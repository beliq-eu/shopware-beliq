<?php declare(strict_types=1);

namespace Beliq\Shopware\Tests;

use Beliq\Shopware\Invoice\Address;
use Beliq\Shopware\Invoice\Party;
use Beliq\Shopware\Invoice\PaymentMeans;
use Beliq\Shopware\Invoice\SourceLine;
use Beliq\Shopware\Invoice\SourceOrder;
use Beliq\Shopware\Service\BeliqClient;
use Beliq\Shopware\Service\InvoiceMapper;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end smoke against a running beliq API: maps a realistic B2B order the
 * way the plugin does, generates the document, and validates the returned bytes.
 * This is the honest proof that the field mapping produces documents that pass
 * the business rules, not just that the code runs.
 *
 * Gated on BELIQ_API_KEY so it is skipped in CI (no key) and runs locally
 * against the dev stack. Point BELIQ_BASE_URL at the local API (for example
 * http://localhost:3000); it defaults to the production API.
 */
final class LiveGenerateSmokeTest extends TestCase
{
    private BeliqClient $client;
    private InvoiceMapper $mapper;

    protected function setUp(): void
    {
        $apiKey = getenv('BELIQ_API_KEY');
        if ($apiKey === false || $apiKey === '') {
            self::markTestSkipped('Set BELIQ_API_KEY (and BELIQ_BASE_URL for a local API) to run the live smoke.');
        }

        $baseUrl = getenv('BELIQ_BASE_URL') ?: 'https://api.beliq.eu';
        $this->client = new BeliqClient($apiKey, $baseUrl);
        $this->mapper = new InvoiceMapper();
    }

    public function testXRechnungFromAGermanBusinessOrderValidatesGreen(): void
    {
        $order = $this->germanBusinessOrder();
        $body = $this->mapper->toGenerateBody($order, 'xrechnung', 'xml');

        $generated = $this->client->generate($body);
        $result = $this->client->validate($generated['bytes'], 'application/xml', 'cii');

        self::assertTrue($result['valid'] ?? false, $this->errorSummary($result));
        self::assertSame([], $result['errors'] ?? [], $this->errorSummary($result));
    }

    /**
     * Uses non-German parties so the smoke stays green against whichever engine build
     * the target API runs. A German (DE to DE) Peppol BIS invoice additionally needs
     * the seller contact group (BG-6, rules DE-R-002/005/006/007); the plugin sends it
     * and the engine emits it as cac:Contact (tobias-dev/bq-engine#119), so a German
     * case validates green once that build is live. XRechnung is the other German target.
     */
    public function testPeppolBisFromANonGermanBusinessOrderValidatesGreen(): void
    {
        $order = $this->frenchBusinessOrder();
        $body = $this->mapper->toGenerateBody($order, 'peppol-bis', 'xml');

        $generated = $this->client->generate($body);
        $result = $this->client->validate($generated['bytes'], 'application/xml', 'ubl');

        self::assertTrue($result['valid'] ?? false, $this->errorSummary($result));
        self::assertSame([], $result['errors'] ?? [], $this->errorSummary($result));
    }

    private function germanBusinessOrder(): SourceOrder
    {
        $seller = new Party(
            name: 'Seller GmbH',
            address: new Address('Berlin', '10115', 'DE', 'Hauptstrasse 1'),
            vatId: 'DE123456789',
            email: 'billing@seller.test',
            phone: '+49 30 1234567',
            contactName: 'Rechnungsstelle',
        );
        $buyer = new Party(
            name: 'Buyer AG',
            address: new Address('Muenchen', '80331', 'DE', 'Marktplatz 2'),
            vatId: 'DE987654321',
            email: 'einkauf@buyer.test',
        );

        return new SourceOrder(
            number: 'SW10042',
            issueDate: '2026-07-01',
            currencyCode: 'EUR',
            seller: $seller,
            buyer: $buyer,
            lines: [new SourceLine('Consulting', 10.0, 100.0, 1000.0, 19.0, 'HUR')],
            paymentMeans: (new PaymentMeans('58', 'DE89370400440532013000', 'COBADEFFXXX', 'Commerzbank'))->withReference('SW10042'),
            buyerReference: 'SW10042',
            buyerFlaggedBusiness: true,
        );
    }

    private function frenchBusinessOrder(): SourceOrder
    {
        $seller = new Party(
            name: 'Vendeur SARL',
            address: new Address('Paris', '75002', 'FR', 'Rue A 1'),
            vatId: 'FR12345678901',
            email: 'facture@vendeur.test',
            phone: '+33 1 2345678',
            contactName: 'Service Facturation',
        );
        $buyer = new Party(
            name: 'Acheteur SA',
            address: new Address('Lyon', '69001', 'FR', 'Rue B 2'),
            vatId: 'FR98765432109',
            email: 'achat@acheteur.test',
        );

        return new SourceOrder(
            number: 'FR10042',
            issueDate: '2026-07-01',
            currencyCode: 'EUR',
            seller: $seller,
            buyer: $buyer,
            lines: [new SourceLine('Conseil', 10.0, 100.0, 1000.0, 20.0, 'HUR')],
            paymentMeans: (new PaymentMeans('58', 'FR7630006000011234567890189'))->withReference('FR10042'),
            buyerReference: 'FR10042',
            buyerFlaggedBusiness: true,
        );
    }

    /**
     * @param array<string, mixed> $result
     */
    private function errorSummary(array $result): string
    {
        $errors = $result['errors'] ?? [];
        if (!is_array($errors) || $errors === []) {
            return 'Expected a green document but validation reported no error list.';
        }

        $lines = array_map(
            static fn (array $e): string => ($e['ruleId'] ?? '?') . ': ' . ($e['message'] ?? ''),
            $errors,
        );

        return "Document failed validation:\n" . implode("\n", $lines);
    }
}
