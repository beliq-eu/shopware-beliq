<?php declare(strict_types=1);

// Live smoke, run inside the Dockware container by run.sh (`php /smoke/smoke.php`).
//
// It drives the store over HTTP the way a shop does: a buyer registers and checks
// out through the Store API, and the merchant sets the payment to paid through the
// Admin API. That transition fires the plugin's subscriber inside a real Shopware
// request, where the media write fails unless it runs in SYSTEM_SCOPE. Each case then downloads the stored `beliq_invoice` document
// through the Admin API and posts its bytes to /v1/validate.
//
// BELIQ_API_KEY and BELIQ_BASE_URL come from the environment. BELIQ_SMOKE_STUB=1
// marks a run against stub-router.php, whose documents are placeholders.

const SHOP = 'http://localhost';
const CURRENCY_EUR = 'b7d2554b0ce847cd82f3ac9bd1c0dfca';
const SALES_CHANNEL_TYPE_STOREFRONT = '8a243080f92e4c719546314b577cf82b';
const DOCUMENT_TYPE = 'beliq_invoice';
const FAILURE_FIELD = 'beliq_invoice_failure';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Beliq\\Shopware\\';
    if (str_starts_with($class, $prefix)) {
        $file = '/var/www/html/custom/plugins/BeliqShopware/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

use Beliq\Shopware\Service\BeliqClient;
use Beliq\Shopware\Service\CurlHttpClient;

$failures = [];
$passes = 0;

function check(bool $cond, string $label): void
{
    global $failures, $passes;
    if ($cond) {
        $passes++;
        echo '  PASS  ' . $label . "\n";
    } else {
        $failures[] = $label;
        echo '  FAIL  ' . $label . "\n";
    }
}

/** @return array{status: int, type: string, headers: array<string, string>, body: string} */
function http(string $method, string $url, array $headers, ?string $body = null): array
{
    $responseHeaders = [];
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => static function ($_h, string $line) use (&$responseHeaders): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }

            return strlen($line);
        },
    ]);
    if ($body !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
    }
    $out = curl_exec($handle);
    if ($out === false) {
        throw new RuntimeException($method . ' ' . $url . ': ' . curl_error($handle));
    }

    return [
        'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
        'type' => $responseHeaders['content-type'] ?? '',
        'headers' => $responseHeaders,
        'body' => (string) $out,
    ];
}

// Shopware's admin access token lasts 10 minutes, and a slow run can outlast one.
const TOKEN_REUSE_SECONDS = 300;

function adminToken(): string
{
    static $token = null;
    static $fetchedAt = 0;
    if ($token === null || time() - $fetchedAt > TOKEN_REUSE_SECONDS) {
        $fetchedAt = time();
        $res = http('POST', SHOP . '/api/oauth/token', ['Content-Type: application/json'], json_encode([
            'grant_type' => 'password',
            'client_id' => 'administration',
            'username' => 'admin',
            'password' => 'shopware',
            'scopes' => 'write',
        ]));
        $token = json_decode($res['body'], true)['access_token'] ?? throw new RuntimeException('Admin API login failed: ' . $res['status']);
    }

    return $token;
}

/** Admin API call; returns the decoded JSON (or null for an empty body), throws on a non-2xx. */
function admin(string $method, string $path, ?array $body = null): ?array
{
    $res = http($method, SHOP . '/api' . $path, [
        'Authorization: Bearer ' . adminToken(),
        'Accept: application/json',
        'Content-Type: application/json',
    ], $body === null ? null : json_encode($body));
    if ($res['status'] < 200 || $res['status'] >= 300) {
        throw new RuntimeException($method . ' /api' . $path . ' -> ' . $res['status'] . ': ' . substr($res['body'], 0, 500));
    }

    return $res['body'] === '' ? null : json_decode($res['body'], true);
}

/** @return list<array<string, mixed>> */
function search(string $entity, array $criteria): array
{
    return admin('POST', '/search/' . $entity, $criteria)['data'] ?? [];
}

/** Store API call as the buyer; returns [decoded body, context token]. */
function store(string $method, string $path, string $accessKey, ?string $contextToken, array $body = []): array
{
    $headers = ['sw-access-key: ' . $accessKey, 'Accept: application/json', 'Content-Type: application/json'];
    if ($contextToken !== null) {
        $headers[] = 'sw-context-token: ' . $contextToken;
    }
    $res = http($method, SHOP . '/store-api' . $path, $headers, json_encode((object) $body));
    if ($res['status'] < 200 || $res['status'] >= 300) {
        throw new RuntimeException($method . ' /store-api' . $path . ' -> ' . $res['status'] . ': ' . substr($res['body'], 0, 500));
    }

    return [json_decode($res['body'], true), $res['headers']['sw-context-token'] ?? $contextToken];
}

/** A stable id from a name, so a re-run upserts the same rows instead of adding more. */
function sid(string $name): string
{
    return md5('beliq-smoke:' . $name);
}

function configure(array $values): void
{
    $prefixed = [];
    foreach ($values as $key => $value) {
        $prefixed['BeliqShopware.config.' . $key] = $value;
    }
    admin('POST', '/_action/system-config/batch', ['null' => $prefixed]);
}

/** @return list<array<string, mixed>> the order's beliq documents, oldest first */
function beliqDocuments(string $orderId): array
{
    return search('document', [
        'filter' => [
            ['type' => 'equals', 'field' => 'orderId', 'value' => $orderId],
            ['type' => 'equals', 'field' => 'documentType.technicalName', 'value' => DOCUMENT_TYPE],
        ],
        'sort' => [['field' => 'createdAt', 'order' => 'ASC']],
    ]);
}

/**
 * A buyer checks out one product as a guest; returns the order id and number.
 *
 * @param array{country: string, city: string, zipcode: string, company: ?string, vatId: ?string} $buyer
 */
function placeOrder(array $shop, string $productId, int $quantity, array $buyer): array
{
    $business = $buyer['company'] !== null;
    $billing = [
        'firstName' => 'Smoke',
        'lastName' => 'Buyer',
        'street' => 'Teststrasse 1',
        'zipcode' => $buyer['zipcode'],
        'city' => $buyer['city'],
        'countryId' => $shop['countries'][$buyer['country']],
    ];
    $register = [
        'guest' => true,
        'accountType' => $business ? 'business' : 'private',
        'email' => 'smoke-' . bin2hex(random_bytes(4)) . '@example.' . strtolower($buyer['country']),
        'firstName' => 'Smoke',
        'lastName' => 'Buyer',
        'acceptedDataProtection' => true,
        'storefrontUrl' => $shop['domain'],
        'billingAddress' => $business ? $billing + ['company' => $buyer['company']] : $billing,
    ];
    if ($buyer['vatId'] !== null) {
        $register['vatIds'] = [$buyer['vatId']];
    }

    [, $token] = store('POST', '/account/register', $shop['accessKey'], null, $register);
    [, $token] = store('POST', '/checkout/cart/line-item', $shop['accessKey'], $token, [
        'items' => [['type' => 'product', 'referencedId' => $productId, 'id' => $productId, 'quantity' => $quantity]],
    ]);
    [$order] = store('POST', '/checkout/order', $shop['accessKey'], $token);

    return ['id' => $order['id'], 'number' => $order['orderNumber']];
}

/** Moves the order's transaction to $transition, the merchant's action in the admin. */
function transition(string $orderId, string $transition): void
{
    $transactions = search('order-transaction', [
        'filter' => [['type' => 'equals', 'field' => 'orderId', 'value' => $orderId]],
    ]);
    admin('POST', '/_action/order_transaction/' . $transactions[0]['id'] . '/state/' . $transition);
}

/** Sets every delivery of the order to shipped, the merchant's action in the admin. */
function shipDeliveries(string $orderId): void
{
    foreach (search('order-delivery', ['filter' => [['type' => 'equals', 'field' => 'orderId', 'value' => $orderId]]]) as $delivery) {
        admin('POST', '/_action/order_delivery/' . $delivery['id'] . '/state/ship');
    }
}

/** How many generate calls stub-router.php has answered. */
function stubGenerateCount(string $baseUrl): int
{
    $res = http('GET', $baseUrl . '/__smoke/generate-count', []);
    if ($res['status'] !== 200) {
        throw new RuntimeException('stub generate count -> ' . $res['status']);
    }

    return (int) $res['body'];
}

/** The body of the last generate call stub-router.php answered. */
function stubLastGenerate(string $baseUrl): array
{
    $res = http('GET', $baseUrl . '/__smoke/last-generate', []);
    $body = json_decode($res['body'], true);
    if ($res['status'] !== 200 || !is_array($body)) {
        throw new RuntimeException('stub last generate -> ' . $res['status']);
    }

    return $body;
}

function orderCustomFields(string $orderId): array
{
    return search('order', ['ids' => [$orderId]])[0]['customFields'] ?? [];
}

/**
 * Runs $case with the country's company tax setting on, which makes a company
 * buyer's order to it tax free, and turns the setting off again after.
 */
function withCompanyTax(string $countryId, string $iso, callable $case): void
{
    try {
        admin('PATCH', '/country/' . $countryId, ['companyTax' => ['enabled' => true, 'currencyId' => CURRENCY_EUR, 'amount' => 0]]);
        $case();
    } finally {
        try {
            admin('PATCH', '/country/' . $countryId, ['companyTax' => ['enabled' => false, 'currencyId' => CURRENCY_EUR, 'amount' => 0]]);
        } catch (Throwable $e) {
            check(false, 'restoring the company tax setting of ' . $iso . ' raised ' . $e->getMessage());
        }
    }
}

/**
 * Pays a tax-free order and checks that it got one XRechnung document that
 * validates. Against the stub the stored bytes are a placeholder, so the
 * generate request is what shows the category reached beliq: $checkRequest
 * reads it.
 *
 * @param callable(array<string, mixed>): void $checkRequest
 */
function checkTaxFreeInvoice(array $order, bool $stub, string $baseUrl, BeliqClient $client, callable $checkRequest): void
{
    $taxStatus = search('order', ['ids' => [$order['id']]])[0]['taxStatus'] ?? null;
    check($taxStatus === 'tax-free', 'Shopware recorded the order as tax free (got ' . json_encode($taxStatus) . ')');
    $generatesBefore = $stub ? stubGenerateCount($baseUrl) : null;
    transition($order['id'], 'paid');

    $documents = beliqDocuments($order['id']);
    $failure = orderCustomFields($order['id'])[FAILURE_FIELD] ?? null;
    check(count($documents) === 1, 'the paid transition stored exactly one beliq_invoice document');
    check($failure === null, 'no failure recorded on the order (' . json_encode($failure) . ')');
    if (count($documents) !== 1) {
        return;
    }
    if ($stub) {
        check(stubGenerateCount($baseUrl) === $generatesBefore + 1, 'the paid transition made one generate call');
        $checkRequest(stubLastGenerate($baseUrl)['invoice'] ?? []);
    }

    checkStoredDocumentValidates($documents[0], $client);
}

/**
 * Downloads a stored beliq document as XML and checks that beliq validates it.
 *
 * @param array<string, mixed> $document
 */
function checkStoredDocumentValidates(array $document, BeliqClient $client): void
{
    $download = http(
        'GET',
        SHOP . '/api/_action/document/' . $document['id'] . '/' . $document['deepLinkCode'] . '?fileType=xml',
        ['Authorization: Bearer ' . adminToken()],
    );
    check($download['status'] === 200, 'the document downloads through the Admin API');
    $result = $client->validate($download['body'], 'application/xml', 'cii');
    $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];
    check(($result['valid'] ?? false) === true && $errors === [], 'the stored document validates green ('
        . ($result['format'] ?? '?') . '/' . ($result['profileDetected'] ?? '?') . ')');
    foreach (array_slice($errors, 0, 5) as $error) {
        echo '        ERR ' . ($error['ruleId'] ?? '?') . ': ' . ($error['message'] ?? '') . "\n";
    }
}

/**
 * @param array<string, mixed> $invoice a generate request's invoice
 * @return array{0: list<array{0: mixed, 1: float}>, 1: list<array{0: mixed, 1: mixed, 2: mixed}>}
 *     each line's category and rate, and each breakdown's category and reason
 */
function sentCategories(array $invoice): array
{
    return [
        array_map(static fn (array $line): array => [$line['vatCategoryCode'] ?? null, (float) ($line['vatRate'] ?? -1)], $invoice['lines'] ?? []),
        array_map(
            static fn (array $row): array => [$row['vatCategoryCode'] ?? null, $row['exemptionReasonCode'] ?? null, $row['exemptionReasonText'] ?? null],
            $invoice['taxSummary'] ?? [],
        ),
    ];
}

$apiKey = getenv('BELIQ_API_KEY') ?: '';
$baseUrl = getenv('BELIQ_BASE_URL') ?: '';
$stub = getenv('BELIQ_SMOKE_STUB') === '1';
if ($apiKey === '' || $baseUrl === '') {
    fwrite(STDERR, "BELIQ_API_KEY and BELIQ_BASE_URL must be set.\n");
    exit(2);
}

echo "== Preconditions ==\n";
$plugin = search('plugin', ['filter' => [['type' => 'equals', 'field' => 'name', 'value' => 'BeliqShopware']]])[0] ?? null;
check(($plugin['active'] ?? false) === true, 'BeliqShopware is installed and active (' . ($plugin['version'] ?? '?') . ')');
$documentType = search('document-type', ['filter' => [['type' => 'equals', 'field' => 'technicalName', 'value' => DOCUMENT_TYPE]]]);
check(count($documentType) === 1, 'the beliq_invoice document type is registered');

$channel = search('sales-channel', [
    'filter' => [['type' => 'equals', 'field' => 'typeId', 'value' => SALES_CHANNEL_TYPE_STOREFRONT]],
    'associations' => ['domains' => new stdClass()],
])[0];
$countries = [];
$isEu = [];
foreach (search('country', ['filter' => [['type' => 'equalsAny', 'field' => 'iso', 'value' => ['DE', 'FR', 'AT', 'CH']]]]) as $country) {
    $countries[$country['iso']] = $country['id'];
    $isEu[$country['iso']] = $country['isEu'] ?? null;
}
check(($isEu['AT'] ?? null) === true && ($isEu['CH'] ?? null) === false, 'Shopware marks AT as an EU member and CH as not (' . json_encode($isEu) . ')');
$shop = [
    'accessKey' => $channel['accessKey'],
    'domain' => $channel['domains'][0]['url'],
    'countries' => $countries,
];

// One product per VAT rate, 50.00 net, upserted by fixed ids so a re-run reuses them.
$standardTax = search('tax', ['filter' => [['type' => 'equals', 'field' => 'taxRate', 'value' => 19]]])[0]['id'];
admin('POST', '/_action/sync', [
    'smoke-tax' => ['entity' => 'tax', 'action' => 'upsert', 'payload' => [
        ['id' => sid('tax-fr-20'), 'name' => 'beliq smoke FR 20', 'taxRate' => 20],
        ['id' => sid('tax-0'), 'name' => 'beliq smoke 0', 'taxRate' => 0],
    ]],
    'smoke-products' => ['entity' => 'product', 'action' => 'upsert', 'payload' => array_map(
        static fn (array $p) => [
            'id' => sid('product-' . $p['number']),
            'productNumber' => $p['number'],
            'name' => $p['name'],
            'taxId' => $p['taxId'],
            'stock' => 100000,
            'active' => true,
            'price' => [['currencyId' => CURRENCY_EUR, 'net' => 50.0, 'gross' => $p['gross'], 'linked' => false]],
            'visibilities' => [['id' => sid('visibility-' . $p['number']), 'salesChannelId' => $channel['id'], 'visibility' => 30]],
        ],
        [
            ['number' => 'BELIQ-SMOKE-19', 'name' => 'Smoke widget 19%', 'taxId' => $standardTax, 'gross' => 59.5],
            ['number' => 'BELIQ-SMOKE-20', 'name' => 'Smoke widget 20%', 'taxId' => sid('tax-fr-20'), 'gross' => 60.0],
            ['number' => 'BELIQ-SMOKE-0', 'name' => 'Smoke course 0%', 'taxId' => sid('tax-0'), 'gross' => 50.0],
        ],
    )],
]);
$product19 = sid('product-BELIQ-SMOKE-19');
$product20 = sid('product-BELIQ-SMOKE-20');
$product0 = sid('product-BELIQ-SMOKE-0');

$germanSeller = [
    'sellerName' => 'Muster Handel GmbH',
    'sellerVatId' => 'DE123456789',
    'sellerEmail' => 'buchhaltung@muster-handel.example',
    'sellerPhone' => '+49 30 1234567',
    'sellerContactName' => 'Buchhaltung',
    'sellerStreet' => 'Hauptstrasse 1',
    'sellerPostalCode' => '10115',
    'sellerCity' => 'Berlin',
    'sellerCountryCode' => 'DE',
    'sellerIban' => 'DE89370400440532013000',
    'sellerBic' => 'COBADEFFXXX',
    'sellerBankName' => 'Commerzbank',
];
$germanBuyer = ['country' => 'DE', 'city' => 'Hamburg', 'zipcode' => '20095', 'company' => 'Kaeufer AG', 'vatId' => 'DE987654321'];

$cases = [
    'German XRechnung (xml)' => [
        'config' => ['standard' => 'xrechnung', 'output' => 'xml'] + $germanSeller,
        'product' => $product19,
        'buyer' => $germanBuyer,
        'magic' => '<?xml',
        'mediaType' => 'application/xml',
        'validateFormat' => 'cii',
    ],
    'French Peppol BIS (xml)' => [
        'config' => ['standard' => 'peppol-bis', 'output' => 'xml'] + [
            'sellerName' => 'Vendeur SARL',
            'sellerVatId' => 'FR12345678901',
            'sellerEmail' => 'facture@vendeur.example',
            'sellerPhone' => '+33 1 2345678',
            'sellerContactName' => 'Service Facturation',
            'sellerStreet' => 'Rue A 1',
            'sellerPostalCode' => '75002',
            'sellerCity' => 'Paris',
            'sellerCountryCode' => 'FR',
            'sellerIban' => 'FR7630006000011234567890189',
            'sellerBic' => '',
            'sellerBankName' => '',
        ],
        'product' => $product20,
        'buyer' => ['country' => 'FR', 'city' => 'Lyon', 'zipcode' => '69001', 'company' => 'Acheteur SA', 'vatId' => 'FR98765432109'],
        'magic' => '<?xml',
        'mediaType' => 'application/xml',
        'validateFormat' => 'ubl',
    ],
    'German ZUGFeRD (hybrid pdf)' => [
        'config' => ['standard' => 'zugferd', 'output' => 'pdf'] + $germanSeller,
        'product' => $product19,
        'buyer' => $germanBuyer,
        'magic' => '%PDF',
        'mediaType' => 'application/pdf',
        'validateFormat' => 'auto',
    ],
    'German XRechnung, VAT exempt (xml)' => [
        'config' => [
            'standard' => 'xrechnung',
            'output' => 'xml',
            'zeroRateCategory' => 'E',
            'exemptReasonCode' => 'VATEX-EU-132',
            'exemptReasonText' => 'Exempt under Article 132 of Directive 2006/112/EC',
        ] + $germanSeller,
        'product' => $product0,
        'buyer' => $germanBuyer,
        'magic' => '<?xml',
        'mediaType' => 'application/xml',
        'validateFormat' => 'cii',
        'exemptionReason' => ['VATEX-EU-132', 'Exempt under Article 132 of Directive 2006/112/EC'],
    ],
];

$base = [
    'enabled' => true,
    'apiKey' => $apiKey,
    'baseUrl' => $baseUrl,
    'profile' => 'en16931',
    'businessOnly' => true,
    'triggerEvent' => 'paid',
    'zeroRateCategory' => 'Z',
    'exemptReasonCode' => '',
    'exemptReasonText' => '',
    'exportReasonCode' => '',
    'exportReasonText' => '',
    'taxFreeEuBusiness' => 'stop',
    'taxFreeNonEu' => 'stop',
    'reverseChargeReasonCode' => '',
    'reverseChargeReasonText' => '',
    'intraCommunityReasonCode' => '',
    'intraCommunityReasonText' => '',
    'outOfScopeReasonCode' => '',
    'outOfScopeReasonText' => '',
    'sellerRegistrationId' => '',
    'paymentMeansCode' => '58',
];
$client = new BeliqClient($apiKey, $baseUrl, new CurlHttpClient(120));
$firstOrder = null;

foreach ($cases as $label => $case) {
    echo "\n== Case: {$label} ==\n";
    try {
        configure(array_merge($base, $case['config']));
        $order = placeOrder($shop, $case['product'], 2, $case['buyer']);
        $firstOrder ??= $order;
        check(beliqDocuments($order['id']) === [], 'no beliq document before the payment (order ' . $order['number'] . ')');

        $generatesBefore = $stub ? stubGenerateCount($baseUrl) : null;
        transition($order['id'], 'paid');

        $documents = beliqDocuments($order['id']);
        check(count($documents) === 1, 'the paid transition stored exactly one beliq_invoice document');
        check(!array_key_exists(FAILURE_FIELD, orderCustomFields($order['id'])), 'no failure recorded on the order');
        if (count($documents) !== 1) {
            continue;
        }
        $document = $documents[0];
        check(($document['config']['documentNumber'] ?? null) === $order['number'], 'the document number is the order number');

        // The stub stores a placeholder, so in a stub run the request is what
        // shows the reason reached beliq. Against the API, validate checks BR-E-10.
        if ($stub) {
            // The last body is this case's only if the paid transition made one call.
            check(stubGenerateCount($baseUrl) === $generatesBefore + 1, 'the paid transition made one generate call');
            $summary = stubLastGenerate($baseUrl)['invoice']['taxSummary'] ?? [];
            $sent = array_map(
                static fn (array $row): array => [$row['vatCategoryCode'] ?? null, $row['exemptionReasonCode'] ?? null, $row['exemptionReasonText'] ?? null],
                $summary,
            );
            $expected = isset($case['exemptionReason'])
                ? [['E', ...$case['exemptionReason']]]
                : array_map(static fn (array $row): array => [$row[0], null, null], $sent);
            check($sent !== [] && $sent === $expected, 'the generate request\'s VAT breakdown carries '
                . (isset($case['exemptionReason']) ? 'the configured exemption reason' : 'no exemption reason') . ' (' . json_encode($sent) . ')');
        }

        // The admin's Documents card asks for the stored file's own type.
        $documentUrl = SHOP . '/api/_action/document/' . $document['id'] . '/' . $document['deepLinkCode'];
        $extension = $case['mediaType'] === 'application/pdf' ? 'pdf' : 'xml';
        $download = http('GET', $documentUrl . '?fileType=' . $extension, ['Authorization: Bearer ' . adminToken()]);
        check($download['status'] === 200, 'the document downloads through the Admin API');
        check(str_starts_with($download['type'], $case['mediaType']), 'the download is ' . $case['mediaType'] . ' (got ' . $download['type'] . ')');
        check(str_starts_with(ltrim($download['body']), $case['magic']), 'the stored bytes start with ' . $case['magic']);
        check(!str_contains(substr($download['body'], 0, 200), '"success"'), 'the stored bytes are the document, not the API\'s JSON envelope');

        $result = $client->validate($download['body'], $case['mediaType'], $case['validateFormat']);
        $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];
        check(($result['valid'] ?? false) === true && $errors === [], 'the stored document validates green ('
            . ($result['format'] ?? '?') . '/' . ($result['profileDetected'] ?? '?') . ')');
        foreach (array_slice($errors, 0, 5) as $error) {
            echo '        ERR ' . ($error['ruleId'] ?? '?') . ': ' . ($error['message'] ?? '') . "\n";
        }

        // pdf is the Admin API's default file type and an XML document has no pdf
        // media, so Shopware asks the renderer for one. Up to 0.2.2 that called
        // beliq again and renumbered the stored document to the regenerate form.
        if ($extension === 'xml') {
            $pdfRead = http('GET', $documentUrl, ['Authorization: Bearer ' . adminToken()]);
            $again = beliqDocuments($order['id']);
            check(
                $pdfRead['status'] === 204
                    && count($again) === 1
                    && ($again[0]['config']['documentNumber'] ?? null) === $order['number']
                    && ($again[0]['updatedAt'] ?? null) === ($document['updatedAt'] ?? null),
                'a read as pdf, the Admin API default, answers 204 and leaves the document as it was',
            );
        }
    } catch (Throwable $e) {
        check(false, $label . ' raised ' . $e->getMessage());
    }
}

echo "\n== Case: business-only scope skips a consumer order ==\n";
try {
    configure($base + $cases['German XRechnung (xml)']['config']);
    $consumer = placeOrder($shop, $product19, 1, ['country' => 'DE', 'city' => 'Hamburg', 'zipcode' => '20095', 'company' => null, 'vatId' => null]);
    transition($consumer['id'], 'paid');
    check(beliqDocuments($consumer['id']) === [], 'no beliq document for a consumer order');
    check(!array_key_exists(FAILURE_FIELD, orderCustomFields($consumer['id'])), 'the skip is not recorded as a failure');
} catch (Throwable $e) {
    check(false, 'the consumer case raised ' . $e->getMessage());
}

echo "\n== Case: a VAT exempt order with no exemption reason gets no invoice ==\n";
try {
    configure(array_merge($base, $cases['German XRechnung (xml)']['config'], ['zeroRateCategory' => 'E']));
    $generatesBefore = $stub ? stubGenerateCount($baseUrl) : null;
    $exempt = placeOrder($shop, $product0, 1, $germanBuyer);
    transition($exempt['id'], 'paid');
    check(beliqDocuments($exempt['id']) === [], 'no beliq document for the order');
    $failure = (string) (orderCustomFields($exempt['id'])[FAILURE_FIELD] ?? '');
    check(str_contains($failure, 'VAT category E') && str_contains($failure, 'BR-E-10'), 'the failure on the order names category E and BR-E-10 (' . $failure . ')');
    if ($stub) {
        check(stubGenerateCount($baseUrl) === $generatesBefore, 'the stub saw no generate call');
    }
} catch (Throwable $e) {
    check(false, 'the missing-reason case raised ' . $e->getMessage());
}

// Austria's company tax setting makes a company buyer with a VAT ID tax free, as
// a shop selling to intra-EU businesses sets it up. Shopware then records the
// order as tax free without a VAT ground, so the merchant states the category.
$austrianCompany = ['country' => 'AT', 'city' => 'Wien', 'zipcode' => '1010', 'company' => 'Kaeufer GmbH', 'vatId' => 'ATU12345678'];

echo "\n== Case: a tax-free order to an EU business gets no invoice until the merchant states its category ==\n";
// Category E with its reason is set: up to 0.2.6 this order validated as exempt.
try {
    configure(array_merge($base, $cases['German XRechnung, VAT exempt (xml)']['config']));
    withCompanyTax($shop['countries']['AT'], 'AT', static function () use ($shop, $product19, $austrianCompany, $stub, $baseUrl): void {
        $generatesBefore = $stub ? stubGenerateCount($baseUrl) : null;
        $taxFree = placeOrder($shop, $product19, 1, $austrianCompany);
        $taxStatus = search('order', ['ids' => [$taxFree['id']]])[0]['taxStatus'] ?? null;
        check($taxStatus === 'tax-free', 'Shopware recorded the order as tax free (got ' . json_encode($taxStatus) . ')');
        transition($taxFree['id'], 'paid');
        check(beliqDocuments($taxFree['id']) === [], 'no beliq document for the order');
        $failure = (string) (orderCustomFields($taxFree['id'])[FAILURE_FIELD] ?? '');
        // The field keeps 255 characters, so the end of the message shows it fits.
        check(
            str_contains($failure, 'as tax free') && str_contains($failure, '"Tax-free orders to businesses in other EU countries"')
                && str_ends_with($failure, 'generate the invoice again.'),
            'the failure on the order names the setting, in full (' . $failure . ')',
        );
        if ($stub) {
            check(stubGenerateCount($baseUrl) === $generatesBefore, 'the stub saw no generate call');
        }
    });
} catch (Throwable $e) {
    check(false, 'the tax-free case raised ' . $e->getMessage());
}

echo "\n== Case: a tax-free order to an Austrian company, reverse charge stated ==\n";
try {
    configure(array_merge($base, $cases['German XRechnung (xml)']['config'], [
        'taxFreeEuBusiness' => 'AE',
        'reverseChargeReasonCode' => 'VATEX-EU-AE',
        'reverseChargeReasonText' => 'Reverse charge',
    ]));
    withCompanyTax($shop['countries']['AT'], 'AT', static function () use ($shop, $product19, $austrianCompany, $stub, $baseUrl, $client): void {
        checkTaxFreeInvoice(placeOrder($shop, $product19, 2, $austrianCompany), $stub, $baseUrl, $client, static function (array $invoice): void {
            [$lines, $summary] = sentCategories($invoice);
            check($lines !== [] && array_unique($lines, SORT_REGULAR) === [['AE', 0.0]], 'every line of the request is AE at 0% (' . json_encode($lines) . ')');
            check($summary === [['AE', 'VATEX-EU-AE', 'Reverse charge']], 'the request has one AE breakdown with the configured reason (' . json_encode($summary) . ')');
            check(($invoice['buyer']['vatId'] ?? null) === 'ATU12345678', 'the request carries the buyer\'s VAT ID');
        });
    });
} catch (Throwable $e) {
    check(false, 'the reverse charge case raised ' . $e->getMessage());
}

echo "\n== Case: a tax-free order to an Austrian company, intra-community supply stated ==\n";
// K needs the actual delivery date (BR-IC-11), the day the delivery was set to
// shipped. Paid before shipping, the order gets no invoice; created by hand
// after shipping, it does.
try {
    configure(array_merge($base, $cases['German XRechnung (xml)']['config'], [
        'taxFreeEuBusiness' => 'K',
        'intraCommunityReasonCode' => 'VATEX-EU-IC',
        'intraCommunityReasonText' => 'Intra-community supply',
    ]));
    withCompanyTax($shop['countries']['AT'], 'AT', static function () use ($shop, $product19, $austrianCompany, $stub, $baseUrl, $client): void {
        $order = placeOrder($shop, $product19, 2, $austrianCompany);
        $taxStatus = search('order', ['ids' => [$order['id']]])[0]['taxStatus'] ?? null;
        check($taxStatus === 'tax-free', 'Shopware recorded the order as tax free (got ' . json_encode($taxStatus) . ')');
        $generatesBefore = $stub ? stubGenerateCount($baseUrl) : null;
        transition($order['id'], 'paid');
        check(beliqDocuments($order['id']) === [], 'paid before shipping, the order has no beliq document');
        $failure = (string) (orderCustomFields($order['id'])[FAILURE_FIELD] ?? '');
        check(
            str_contains($failure, 'category K') && str_contains($failure, '(BT-72, BR-IC-11)') && str_ends_with($failure, 'generate the invoice again.'),
            'the failure on the order names the missing delivery date, in full (' . $failure . ')',
        );
        if ($stub) {
            check(stubGenerateCount($baseUrl) === $generatesBefore, 'the stub saw no generate call');
        }

        // The day is read before and after the ship, so a run across midnight UTC
        // accepts either.
        $shipDays = [gmdate('Y-m-d')];
        shipDeliveries($order['id']);
        $shipDays[] = gmdate('Y-m-d');
        $create = admin('POST', '/_action/order/document/' . DOCUMENT_TYPE . '/create', [['orderId' => $order['id']]]);
        check(($create['errors'] ?? []) === [], 'after shipping, creating the document succeeds (' . json_encode($create['errors'] ?? []) . ')');
        $documents = beliqDocuments($order['id']);
        check(count($documents) === 1, 'the order holds one beliq document');
        if ($stub) {
            $invoice = stubLastGenerate($baseUrl)['invoice'] ?? [];
            [$lines, $summary] = sentCategories($invoice);
            check($lines !== [] && array_unique($lines, SORT_REGULAR) === [['K', 0.0]], 'every line of the request is K at 0% (' . json_encode($lines) . ')');
            check($summary === [['K', 'VATEX-EU-IC', 'Intra-community supply']], 'the request has one K breakdown with the configured reason (' . json_encode($summary) . ')');
            $delivery = $invoice['delivery'] ?? null;
            check(
                is_array($delivery) && in_array($delivery['date'] ?? null, $shipDays, true) && ($delivery['address'] ?? null) === ['countryCode' => 'AT'],
                'the request carries the shipping day and AT as the delivery (BT-72, BT-80) (' . json_encode($invoice['delivery'] ?? null) . ')',
            );
        }
        if (count($documents) === 1) {
            checkStoredDocumentValidates($documents[0], $client);
        }
    });
} catch (Throwable $e) {
    check(false, 'the intra-community supply case raised ' . $e->getMessage());
}

echo "\n== Case: a tax-free order to a Swiss company, not subject to VAT stated ==\n";
// Switzerland is no EU member, so its company tax setting makes any company
// buyer's order tax free, with or without a VAT ID.
try {
    configure(array_merge($base, $cases['German XRechnung (xml)']['config'], [
        'sellerRegistrationId' => 'HRB 12345 B',
        'taxFreeNonEu' => 'O',
        'outOfScopeReasonCode' => 'VATEX-EU-O',
        'outOfScopeReasonText' => 'Not subject to VAT',
    ]));
    $channelCountry = ['salesChannelId' => $channel['id'], 'countryId' => $shop['countries']['CH']];
    admin('POST', '/_action/sync', ['smoke-channel-ch' => ['entity' => 'sales_channel_country', 'action' => 'upsert', 'payload' => [$channelCountry]]]);
    withCompanyTax($shop['countries']['CH'], 'CH', static function () use ($shop, $product19, $stub, $baseUrl, $client): void {
        $swiss = ['country' => 'CH', 'city' => 'Zuerich', 'zipcode' => '8001', 'company' => 'Kunde AG', 'vatId' => 'CHE123456789'];
        checkTaxFreeInvoice(placeOrder($shop, $product19, 2, $swiss), $stub, $baseUrl, $client, static function (array $invoice): void {
            [$lines, $summary] = sentCategories($invoice);
            check($lines !== [] && array_unique($lines, SORT_REGULAR) === [['O', 0.0]], 'every line of the request is O (' . json_encode($lines) . ')');
            check($summary === [['O', 'VATEX-EU-O', 'Not subject to VAT']], 'the request has one O breakdown with the configured reason (' . json_encode($summary) . ')');
            check(
                !isset($invoice['seller']['vatId']) && !isset($invoice['buyer']['vatId'])
                    && ($invoice['seller']['registrationId'] ?? null) === 'HRB 12345 B',
                'the request carries no VAT ID and the seller\'s registration number (BR-O-02, BR-CO-26)',
            );
        });
    });
} catch (Throwable $e) {
    check(false, 'the not subject to VAT case raised ' . $e->getMessage());
} finally {
    if (isset($channelCountry)) {
        try {
            admin('POST', '/_action/sync', ['smoke-channel-ch' => ['entity' => 'sales_channel_country', 'action' => 'delete', 'payload' => [$channelCountry]]]);
        } catch (Throwable $e) {
            check(false, 'removing CH from the sales channel raised ' . $e->getMessage());
        }
    }
}

echo "\n== Case: a second paid transition keeps the document, a manual one adds -2 ==\n";
try {
    if ($firstOrder === null) {
        throw new RuntimeException('no order from the first case');
    }
    configure($base + $cases['German XRechnung (xml)']['config']);
    $before = beliqDocuments($firstOrder['id']);
    transition($firstOrder['id'], 'reopen');
    transition($firstOrder['id'], 'paid');
    $after = beliqDocuments($firstOrder['id']);
    check(count($before) === 1 && array_column($after, 'id') === array_column($before, 'id'), 'a re-fired paid transition stores no second document');

    admin('POST', '/_action/order/document/' . DOCUMENT_TYPE . '/create', [['orderId' => $firstOrder['id']]]);
    $manual = beliqDocuments($firstOrder['id']);
    check(count($manual) === 2, 'creating the document by hand adds a second one');
    check(($manual[1]['config']['documentNumber'] ?? null) === $firstOrder['number'] . '-2', 'the second document is numbered ' . $firstOrder['number'] . '-2');
} catch (Throwable $e) {
    check(false, 'the idempotency case raised ' . $e->getMessage());
}

echo "\n== Case: after a deleted document, a manual create takes a number no document holds ==\n";
try {
    if ($firstOrder === null) {
        throw new RuntimeException('no order from the first case');
    }
    // The order holds <order> and <order>-2. Add <order>-3, delete <order>-2, and
    // create again: counting the two remaining documents gives <order>-3, which
    // Shopware rejects with DOCUMENT__NUMBER_ALREADY_EXISTS.
    admin('POST', '/_action/order/document/' . DOCUMENT_TYPE . '/create', [['orderId' => $firstOrder['id']]]);
    $second = array_values(array_filter(
        beliqDocuments($firstOrder['id']),
        static fn (array $d): bool => ($d['documentNumber'] ?? null) === $firstOrder['number'] . '-2',
    ));
    check(count($second) === 1, 'the order holds ' . $firstOrder['number'] . '-2 before the delete');
    admin('DELETE', '/document/' . $second[0]['id']);
    $create = admin('POST', '/_action/order/document/' . DOCUMENT_TYPE . '/create', [['orderId' => $firstOrder['id']]]);
    check(($create['errors'] ?? []) === [], 'the create succeeds (' . json_encode($create['errors'] ?? []) . ')');
    $numbers = array_column(beliqDocuments($firstOrder['id']), 'documentNumber');
    $n = $firstOrder['number'];
    check($numbers === [$n, $n . '-3', $n . '-4'], 'the new document is numbered ' . $n . '-4 (numbers: ' . implode(', ', $numbers) . ')');
} catch (Throwable $e) {
    check(false, 'the deleted-document case raised ' . $e->getMessage());
}

echo "\n== Case: the admin's Preview makes no beliq call ==\n";
try {
    if ($firstOrder === null) {
        throw new RuntimeException('no order from the first case');
    }
    $before = beliqDocuments($firstOrder['id']);
    $generatesBefore = $stub ? stubGenerateCount($baseUrl) : null;
    $deepLinkCode = search('order', ['ids' => [$firstOrder['id']]])[0]['deepLinkCode'];
    // What the create-document dialog's Preview button requests.
    $config = rawurlencode(json_encode(['documentNumber' => $firstOrder['number'] . '-9', 'documentDate' => date('Y-m-d'), 'custom' => new stdClass()]));
    $preview = http(
        'GET',
        SHOP . '/api/_action/order/' . $firstOrder['id'] . '/' . $deepLinkCode . '/document/' . DOCUMENT_TYPE . '/preview?fileType=pdf&config=' . $config,
        ['Authorization: Bearer ' . adminToken()],
    );
    check($preview['status'] === 200, 'the preview answers 200 (got ' . $preview['status'] . ')');
    check(str_starts_with($preview['type'], 'text/plain') && str_starts_with($preview['body'], 'beliq invoice: no preview'), 'the preview is the plugin\'s local notice');
    check(array_column(beliqDocuments($firstOrder['id']), 'id') === array_column($before, 'id'), 'the preview stores no document');
    if ($stub) {
        check(stubGenerateCount($baseUrl) === $generatesBefore, 'the stub saw no generate call for the preview');
    }
} catch (Throwable $e) {
    check(false, 'the preview case raised ' . $e->getMessage());
}

echo "\n";
if ($failures === []) {
    echo "All {$passes} checks passed" . ($stub ? ' against the STUB: the store side works, and no stored document was checked by a validator' : ' against ' . $baseUrl) . ".\n";
    exit(0);
}
echo count($failures) . ' of ' . ($passes + count($failures)) . " checks failed:\n  - " . implode("\n  - ", $failures) . "\n";
exit(1);
