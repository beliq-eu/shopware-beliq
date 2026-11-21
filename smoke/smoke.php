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

/** @return array{status: int, type: string, body: string} */
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

function adminToken(): string
{
    static $token = null;
    if ($token === null) {
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

function orderCustomFields(string $orderId): array
{
    return search('order', ['ids' => [$orderId]])[0]['customFields'] ?? [];
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
foreach (search('country', ['filter' => [['type' => 'equalsAny', 'field' => 'iso', 'value' => ['DE', 'FR']]]]) as $country) {
    $countries[$country['iso']] = $country['id'];
}
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
        ],
    )],
]);
$product19 = sid('product-BELIQ-SMOKE-19');
$product20 = sid('product-BELIQ-SMOKE-20');

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
];

$base = [
    'enabled' => true,
    'apiKey' => $apiKey,
    'baseUrl' => $baseUrl,
    'profile' => 'en16931',
    'businessOnly' => true,
    'triggerEvent' => 'paid',
    'zeroRateCategory' => 'Z',
    'paymentMeansCode' => '58',
];
$client = new BeliqClient($apiKey, $baseUrl, new CurlHttpClient(120));
$firstOrder = null;

foreach ($cases as $label => $case) {
    echo "\n== Case: {$label} ==\n";
    try {
        configure($base + $case['config']);
        $order = placeOrder($shop, $case['product'], 2, $case['buyer']);
        $firstOrder ??= $order;
        check(beliqDocuments($order['id']) === [], 'no beliq document before the payment (order ' . $order['number'] . ')');

        transition($order['id'], 'paid');

        $documents = beliqDocuments($order['id']);
        check(count($documents) === 1, 'the paid transition stored exactly one beliq_invoice document');
        check(!array_key_exists(FAILURE_FIELD, orderCustomFields($order['id'])), 'no failure recorded on the order');
        if (count($documents) !== 1) {
            continue;
        }
        $document = $documents[0];
        check(($document['config']['documentNumber'] ?? null) === $order['number'], 'the document number is the order number');

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

echo "\n";
if ($failures === []) {
    echo "All {$passes} checks passed" . ($stub ? ' against the STUB: the store side works, and no stored document was checked by a validator' : ' against ' . $baseUrl) . ".\n";
    exit(0);
}
echo count($failures) . ' of ' . ($passes + count($failures)) . " checks failed:\n  - " . implode("\n  - ", $failures) . "\n";
exit(1);
