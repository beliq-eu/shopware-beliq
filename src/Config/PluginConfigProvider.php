<?php declare(strict_types=1);

namespace Beliq\Shopware\Config;

use Beliq\Shopware\Invoice\Address;
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
    /** System-config domain for this plugin's settings. */
    public const DOMAIN = 'BeliqShopware.config.';

    private const DEFAULT_BASE_URL = 'https://api.beliq.eu';
    private const DEFAULT_STANDARD = 'zugferd';
    private const DEFAULT_PROFILE = 'en16931';
    private const DEFAULT_OUTPUT = 'pdf';
    private const DEFAULT_ZERO_RATE_CATEGORY = 'Z';
    private const TRIGGER_PAID = 'state_enter.order_transaction.state.paid';
    private const TRIGGER_COMPLETED = 'state_enter.order.state.completed';

    /** UNTDID 4461 credit-transfer payment means. 58 is SEPA credit transfer. */
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
        // IBAN there is nothing to emit, and XRechnung's BR-DE-23-a rejects a
        // credit transfer (code 58) that carries no IBAN. So it is assembled only
        // when the merchant has configured a bank account.
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
        );
    }
}
