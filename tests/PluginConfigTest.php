<?php declare(strict_types=1);

namespace Beliq\Shopware\Tests;

use Beliq\Shopware\Config\PluginConfig;
use Beliq\Shopware\Config\PluginConfigProvider;
use Beliq\Shopware\Invoice\Address;
use Beliq\Shopware\Invoice\Party;
use Beliq\Shopware\Invoice\SourceLine;
use Beliq\Shopware\Invoice\SourceOrder;
use PHPUnit\Framework\TestCase;

final class PluginConfigTest extends TestCase
{
    public function testEmptyValuesYieldSafeDefaults(): void
    {
        $config = PluginConfigProvider::fromValues([]);

        self::assertFalse($config->enabled);
        self::assertSame('', $config->apiKey);
        self::assertSame('https://api.beliq.eu', $config->baseUrl);
        self::assertSame('zugferd', $config->standard);
        self::assertSame('en16931', $config->profile);
        self::assertSame('pdf', $config->output);
        self::assertTrue($config->businessOnly);
        self::assertSame('state_enter.order_transaction.state.paid', $config->triggerEvent);
        self::assertSame('Z', $config->zeroRateCategory);
        self::assertSame('', $config->seller->name);
    }

    public function testFullValuesAreMapped(): void
    {
        $config = PluginConfigProvider::fromValues([
            'enabled' => true,
            'apiKey' => '  key-123  ',
            'baseUrl' => 'https://api.example.test',
            'standard' => 'xrechnung',
            'profile' => 'extended',
            'output' => 'xml',
            'businessOnly' => false,
            'triggerEvent' => 'state_enter.order.state.completed',
            'zeroRateCategory' => 'E',
            'sellerName' => 'Seller GmbH',
            'sellerVatId' => 'DE123456789',
            'sellerTaxId' => '151/815/08150',
            'sellerRegistrationId' => 'HRB 1234',
            'sellerEmail' => 'billing@seller.test',
            'sellerContactName' => 'Rechnungsstelle',
            'sellerPhone' => '+49 30 1234567',
            'sellerStreet' => 'Marktplatz 1',
            'sellerPostalCode' => '80331',
            'sellerCity' => 'Muenchen',
            'sellerCountryCode' => 'de',
            'paymentMeansCode' => '58',
            'sellerIban' => 'DE89370400440532013000',
            'sellerBic' => 'COBADEFFXXX',
            'sellerBankName' => 'Commerzbank',
        ]);

        self::assertTrue($config->enabled);
        self::assertSame('key-123', $config->apiKey);
        self::assertSame('https://api.example.test', $config->baseUrl);
        self::assertSame('xrechnung', $config->standard);
        self::assertSame('extended', $config->profile);
        self::assertSame('xml', $config->output);
        self::assertFalse($config->businessOnly);
        self::assertSame('state_enter.order.state.completed', $config->triggerEvent);
        self::assertSame('E', $config->zeroRateCategory);

        $seller = $config->seller;
        self::assertSame('Seller GmbH', $seller->name);
        self::assertSame('DE123456789', $seller->vatId);
        self::assertSame('151/815/08150', $seller->taxId);
        self::assertSame('HRB 1234', $seller->registrationId);
        self::assertSame('billing@seller.test', $seller->email);
        self::assertSame('Rechnungsstelle', $seller->contactName);
        self::assertSame('+49 30 1234567', $seller->phone);
        self::assertSame('Marktplatz 1', $seller->address->street);
        self::assertSame('80331', $seller->address->postalCode);
        self::assertSame('Muenchen', $seller->address->city);
        self::assertSame('DE', $seller->address->countryCode);

        $payment = $config->paymentMeans;
        self::assertNotNull($payment);
        self::assertSame('58', $payment->typeCode);
        self::assertSame('DE89370400440532013000', $payment->iban);
        self::assertSame('COBADEFFXXX', $payment->bic);
        self::assertSame('Commerzbank', $payment->bankName);
    }

    public function testPaymentMeansIsNullWithoutIban(): void
    {
        // A bank name but no IBAN: nothing payable, so no payment means.
        $config = PluginConfigProvider::fromValues([
            'paymentMeansCode' => '58',
            'sellerBankName' => 'Commerzbank',
        ]);

        self::assertNull($config->paymentMeans);
    }

    public function testUnknownPaymentMeansCodeFallsBackToSepaCreditTransfer(): void
    {
        $config = PluginConfigProvider::fromValues([
            'paymentMeansCode' => '48',
            'sellerIban' => 'DE89370400440532013000',
        ]);

        self::assertNotNull($config->paymentMeans);
        self::assertSame('58', $config->paymentMeans->typeCode);
    }

    public function testDefaultsHaveNoPaymentMeans(): void
    {
        self::assertNull(PluginConfigProvider::fromValues([])->paymentMeans);
    }

    public function testUnknownOutputFallsBackToPdf(): void
    {
        self::assertSame('pdf', PluginConfigProvider::fromValues(['output' => 'docx'])->output);
        self::assertSame('xml', PluginConfigProvider::fromValues(['output' => 'xml'])->output);
        self::assertSame('pdf', PluginConfigProvider::fromValues(['output' => 'pdf'])->output);
    }

    public function testUnknownTriggerFallsBackToPaid(): void
    {
        self::assertSame(
            'state_enter.order_transaction.state.paid',
            PluginConfigProvider::fromValues(['triggerEvent' => 'state_enter.order.state.cancelled'])->triggerEvent,
        );
    }

    public function testBlankProfileFallsBackToDefault(): void
    {
        self::assertSame('en16931', PluginConfigProvider::fromValues(['profile' => '   '])->profile);
    }

    public function testAllowsOrderRespectsBusinessOnlyScope(): void
    {
        $businessOnly = PluginConfigProvider::fromValues(['businessOnly' => true]);
        $everyOrder = PluginConfigProvider::fromValues(['businessOnly' => false]);

        self::assertTrue($businessOnly->allowsOrder($this->orderFor(business: true)));
        self::assertFalse($businessOnly->allowsOrder($this->orderFor(business: false)));
        self::assertTrue($everyOrder->allowsOrder($this->orderFor(business: false)));
    }

    public function testPluginConfigAllowsOrderDirectly(): void
    {
        $config = new PluginConfig(
            enabled: true,
            apiKey: 'k',
            baseUrl: 'https://api.beliq.eu',
            standard: 'zugferd',
            profile: 'en16931',
            output: 'pdf',
            businessOnly: true,
            triggerEvent: 'state_enter.order_transaction.state.paid',
            zeroRateCategory: 'Z',
            seller: new Party('S', new Address('C', '1', 'DE')),
        );

        self::assertTrue($config->allowsOrder($this->orderFor(business: true)));
        self::assertFalse($config->allowsOrder($this->orderFor(business: false)));
    }

    public function testProfileIsOmittedForStandardsThatPinTheirOwn(): void
    {
        self::assertNull($this->configWith('xrechnung', 'en16931', 'xml')->effectiveProfile());
        self::assertNull($this->configWith('peppol-bis', 'en16931', 'xml')->effectiveProfile());
        self::assertSame('en16931', $this->configWith('zugferd', 'en16931', 'pdf')->effectiveProfile());
        self::assertSame('extended', $this->configWith('facturx', 'extended', 'pdf')->effectiveProfile());
    }

    public function testExpectedFileTypeFollowsStandardThenOutput(): void
    {
        // XRechnung and Peppol BIS are always XML, whatever the output setting.
        self::assertSame('xml', $this->configWith('xrechnung', 'en16931', 'pdf')->expectedFileType());
        self::assertSame('xml', $this->configWith('peppol-bis', 'en16931', 'pdf')->expectedFileType());
        // The ZUGFeRD / Factur-X family follows the output setting.
        self::assertSame('pdf', $this->configWith('zugferd', 'en16931', 'pdf')->expectedFileType());
        self::assertSame('xml', $this->configWith('zugferd', 'en16931', 'xml')->expectedFileType());
        self::assertSame('pdf', $this->configWith('facturx', 'en16931', 'pdf')->expectedFileType());
    }

    private function configWith(string $standard, string $profile, string $output): PluginConfig
    {
        return new PluginConfig(
            enabled: true,
            apiKey: 'k',
            baseUrl: 'https://api.beliq.eu',
            standard: $standard,
            profile: $profile,
            output: $output,
            businessOnly: true,
            triggerEvent: 'state_enter.order_transaction.state.paid',
            zeroRateCategory: 'Z',
            seller: new Party('S', new Address('C', '1', 'DE')),
        );
    }

    private function orderFor(bool $business): SourceOrder
    {
        return new SourceOrder(
            number: 'X1',
            issueDate: '2026-06-30',
            currencyCode: 'EUR',
            seller: new Party('Seller', new Address('Berlin', '10115', 'DE')),
            buyer: new Party(
                'Buyer',
                new Address('Berlin', '10115', 'DE'),
                vatId: $business ? 'DE123456789' : null,
            ),
            lines: [new SourceLine('Item', 1.0, 100.0, 100.0, 19.0)],
            buyerFlaggedBusiness: $business,
        );
    }
}
