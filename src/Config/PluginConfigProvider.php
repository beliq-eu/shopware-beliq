<?php declare(strict_types=1);

namespace Beliq\Shopware\Config;

use Beliq\Shopware\Invoice\Address;
use Beliq\Shopware\Invoice\ExemptionReason;
use Beliq\Shopware\Invoice\Party;
use Beliq\Shopware\Invoice\PaymentMeans;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Reads the plugin settings from Shopware's system config into a typed
 * PluginConfig. The value-to-config mapping lives in fromValues() so it can be
 * asserted without a running Shopware; get() only pulls the raw values.
 */
final class PluginConfigProvider
{
    /**
     * System-config domain for this plugin's settings.
     */
    public const DOMAIN = 'BeliqShopware.config.';

    private const DEFAULT_BASE_URL = 'https://api.beliq.eu';
    private const DEFAULT_STANDARD = 'zugferd';
    private const DEFAULT_PROFILE = 'en16931';
    private const DEFAULT_OUTPUT = 'pdf';
    private const DEFAULT_ZERO_RATE_CATEGORY = 'Z';
    private const TRIGGER_PAID = 'state_enter.order_transaction.state.paid';
    private const TRIGGER_COMPLETED = 'state_enter.order.state.completed';

    /**
     * The settings that hold the exemption reason (BT-121 code, BT-120 text) of
     * each VAT category the plugin offers that needs one: E (BR-E-10) and G
     * (BR-G-10) from the zero-rate setting, AE (BR-AE-10), K (BR-IC-10) and O
     * (BR-O-10) from the tax-free settings, where G is offered too. Z must not
     * carry one (BR-Z-10).
     */
    private const EXEMPTION_REASON_KEYS = [
        'AE' => ['reverseChargeReasonCode', 'reverseChargeReasonText'],
        'E' => ['exemptReasonCode', 'exemptReasonText'],
        'G' => ['exportReasonCode', 'exportReasonText'],
        'K' => ['intraCommunityReasonCode', 'intraCommunityReasonText'],
        'O' => ['outOfScopeReasonCode', 'outOfScopeReasonText'],
    ];

    /**
     * The categories each tax-free setting offers. Its other option, and any
     * value not listed, means no invoice for such an order.
     */
    private const TAX_FREE_EU_BUSINESS_CATEGORIES = ['AE', 'K'];
    private const TAX_FREE_NON_EU_CATEGORIES = ['G', 'O'];

    /**
     * UNTDID 4461 credit-transfer payment means. 58 is SEPA credit transfer.
     */
    private const PAYMENT_MEANS_CODES = ['58', '30'];
    private const DEFAULT_PAYMENT_MEANS_CODE = '58';

    public function __construct(private readonly SystemConfigService $systemConfig)
    {
    }

    public function get(?string $salesChannelId = null): PluginConfig
    {
        $keys = [
            'enabled', 'apiKey', 'baseUrl', 'standard', 'profile', 'output',
            'businessOnly', 'triggerEvent', 'zeroRateCategory',
            'sellerName', 'sellerVatId', 'sellerTaxId', 'sellerRegistrationId',
            'sellerEmail', 'sellerContactName', 'sellerPhone',
            'sellerStreet', 'sellerPostalCode', 'sellerCity', 'sellerCountryCode',
            'paymentMeansCode', 'sellerIban', 'sellerBic', 'sellerBankName',
            'exemptReasonCode', 'exemptReasonText', 'exportReasonCode', 'exportReasonText',
            'taxFreeEuBusiness', 'taxFreeNonEu',
            'reverseChargeReasonCode', 'reverseChargeReasonText',
            'intraCommunityReasonCode', 'intraCommunityReasonText',
            'outOfScopeReasonCode', 'outOfScopeReasonText',
        ];

        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->systemConfig->get(self::DOMAIN . $key, $salesChannelId);
        }

        return self::fromValues($values);
    }

    /**
     * Map raw config values (short keys, without the domain prefix) to a typed
     * PluginConfig, applying defaults and coercion. Static and free of instance
     * state so it can be asserted without a running Shopware.
     *
     * @param array<string, mixed> $values
     */
    public static function fromValues(array $values): PluginConfig
    {
        $str = static function (string $key, string $default = '') use ($values): string {
            $value = $values[$key] ?? null;

            return is_string($value) && trim($value) !== '' ? trim($value) : $default;
        };
        $nullable = static function (string $key) use ($str): ?string {
            $value = $str($key);

            return $value === '' ? null : $value;
        };
        $bool = static function (string $key, bool $default) use ($values): bool {
            return array_key_exists($key, $values) && $values[$key] !== null
                ? (bool) $values[$key]
                : $default;
        };

        $output = $str('output', self::DEFAULT_OUTPUT);
        $output = $output === 'xml' ? 'xml' : self::DEFAULT_OUTPUT;

        $trigger = $str('triggerEvent', self::TRIGGER_PAID);
        if (!in_array($trigger, [self::TRIGGER_PAID, self::TRIGGER_COMPLETED], true)) {
            $trigger = self::TRIGGER_PAID;
        }

        $seller = new Party(
            name: $str('sellerName'),
            address: new Address(
                city: $str('sellerCity'),
                postalCode: $str('sellerPostalCode'),
                countryCode: strtoupper($str('sellerCountryCode')),
                street: $nullable('sellerStreet'),
            ),
            vatId: $nullable('sellerVatId'),
            taxId: $nullable('sellerTaxId'),
            registrationId: $nullable('sellerRegistrationId'),
            email: $nullable('sellerEmail'),
            phone: $nullable('sellerPhone'),
            contactName: $nullable('sellerContactName'),
        );

        // The payment means (BG-16) needs an account to be payable: without an
        // IBAN there is nothing to emit, and XRechnung's BR-DE-23-a requires the
        // credit transfer details (BG-17) for both offered codes, 58 and 30. So it
        // is assembled only when the merchant has configured a bank account.
        $iban = $nullable('sellerIban');
        $paymentMeansCode = $str('paymentMeansCode', self::DEFAULT_PAYMENT_MEANS_CODE);
        if (!in_array($paymentMeansCode, self::PAYMENT_MEANS_CODES, true)) {
            $paymentMeansCode = self::DEFAULT_PAYMENT_MEANS_CODE;
        }
        $paymentMeans = $iban === null ? null : new PaymentMeans(
            typeCode: $paymentMeansCode,
            iban: $iban,
            bic: $nullable('sellerBic'),
            bankName: $nullable('sellerBankName'),
        );

        $exemptionReasons = [];
        foreach (self::EXEMPTION_REASON_KEYS as $category => [$codeKey, $textKey]) {
            $code = $nullable($codeKey);
            $text = $nullable($textKey);
            if ($code !== null || $text !== null) {
                $exemptionReasons[$category] = new ExemptionReason($code, $text);
            }
        }

        $taxFreeEuBusiness = $str('taxFreeEuBusiness');
        $taxFreeNonEu = $str('taxFreeNonEu');

        return new PluginConfig(
            enabled: $bool('enabled', false),
            apiKey: $str('apiKey'),
            baseUrl: $str('baseUrl', self::DEFAULT_BASE_URL),
            standard: $str('standard', self::DEFAULT_STANDARD),
            profile: $nullable('profile') ?? self::DEFAULT_PROFILE,
            output: $output,
            businessOnly: $bool('businessOnly', true),
            triggerEvent: $trigger,
            zeroRateCategory: $str('zeroRateCategory', self::DEFAULT_ZERO_RATE_CATEGORY),
            seller: $seller,
            paymentMeans: $paymentMeans,
            exemptionReasons: $exemptionReasons,
            taxFreeEuBusinessCategory: in_array($taxFreeEuBusiness, self::TAX_FREE_EU_BUSINESS_CATEGORIES, true) ? $taxFreeEuBusiness : null,
            taxFreeNonEuCategory: in_array($taxFreeNonEu, self::TAX_FREE_NON_EU_CATEGORIES, true) ? $taxFreeNonEu : null,
        );
    }
}
