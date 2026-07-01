<?php declare(strict_types=1);

namespace Beliq\Shopware\Tests;

use Beliq\Shopware\Invoice\Address;
use Beliq\Shopware\Invoice\Party;
use Beliq\Shopware\Invoice\PaymentMeans;
use Beliq\Shopware\Invoice\SourceLine;
use Beliq\Shopware\Invoice\SourceOrder;
use Beliq\Shopware\Service\InvoiceMapper;
use PHPUnit\Framework\TestCase;

final class InvoiceMapperTest extends TestCase
{
    private InvoiceMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new InvoiceMapper();
    }

    public function testStandardRatedSingleLine(): void
    {
        $order = $this->order([
            new SourceLine('Consulting', 10.0, 100.0, 1000.0, 19.0, 'HUR'),
        ]);

        $body = $this->mapper->toGenerateBody($order, 'xrechnung', 'xml');

        self::assertSame('xrechnung', $body['standard']);
        self::assertSame('xml', $body['output']);
        self::assertArrayNotHasKey('profile', $body);

        $invoice = $body['invoice'];
        self::assertSame('S', $invoice['lines'][0]['vatCategoryCode']);
        self::assertSame('HUR', $invoice['lines'][0]['unitCode']);

        self::assertCount(1, $invoice['taxSummary']);
        self::assertSame('S', $invoice['taxSummary'][0]['vatCategoryCode']);
        self::assertEqualsWithDelta(1000.0, $invoice['taxSummary'][0]['taxableAmount'], 0.001);
        self::assertEqualsWithDelta(190.0, $invoice['taxSummary'][0]['taxAmount'], 0.001);

        self::assertEqualsWithDelta(1000.0, $invoice['totalNetAmount'], 0.001);
        self::assertEqualsWithDelta(190.0, $invoice['totalTaxAmount'], 0.001);
        self::assertEqualsWithDelta(1190.0, $invoice['totalGrossAmount'], 0.001);
    }

    public function testMixedRatesProduceTwoGroupsSortedByRate(): void
    {
        $order = $this->order([
            new SourceLine('Standard item', 1.0, 1000.0, 1000.0, 19.0),
            new SourceLine('Reduced item', 1.0, 100.0, 100.0, 7.0),
        ]);

        $summary = $this->mapper->toGenerateBody($order, 'xrechnung')['invoice']['taxSummary'];

        self::assertCount(2, $summary);
        // Sorted by category then rate ascending: 7% before 19%.
        self::assertEqualsWithDelta(7.0, $summary[0]['vatRate'], 0.001);
        self::assertEqualsWithDelta(7.0, $summary[0]['taxAmount'], 0.001);
        self::assertEqualsWithDelta(19.0, $summary[1]['vatRate'], 0.001);
        self::assertEqualsWithDelta(190.0, $summary[1]['taxAmount'], 0.001);
    }

    public function testTotalsAreConsistentAcrossGroups(): void
    {
        $order = $this->order([
            new SourceLine('Standard item', 1.0, 1000.0, 1000.0, 19.0),
            new SourceLine('Reduced item', 1.0, 100.0, 100.0, 7.0),
        ]);

        $invoice = $this->mapper->toGenerateBody($order, 'xrechnung')['invoice'];

        self::assertEqualsWithDelta(1100.0, $invoice['totalNetAmount'], 0.001);
        self::assertEqualsWithDelta(197.0, $invoice['totalTaxAmount'], 0.001);
        self::assertEqualsWithDelta(1297.0, $invoice['totalGrossAmount'], 0.001);
        // BR-CO-15: gross equals net plus tax.
        self::assertEqualsWithDelta(
            $invoice['totalNetAmount'] + $invoice['totalTaxAmount'],
            $invoice['totalGrossAmount'],
            0.001,
        );
    }

    public function testZeroRateUsesConfiguredCategory(): void
    {
        $order = $this->order(
            [new SourceLine('Zero-rated', 1.0, 500.0, 500.0, 0.0)],
            ['zeroRateCategory' => 'Z'],
        );

        $invoice = $this->mapper->toGenerateBody($order, 'xrechnung')['invoice'];

        self::assertSame('Z', $invoice['lines'][0]['vatCategoryCode']);
        self::assertSame('Z', $invoice['taxSummary'][0]['vatCategoryCode']);
        self::assertEqualsWithDelta(0.0, $invoice['taxSummary'][0]['taxAmount'], 0.001);
        self::assertEqualsWithDelta(500.0, $invoice['totalNetAmount'], 0.001);
        self::assertEqualsWithDelta(500.0, $invoice['totalGrossAmount'], 0.001);
    }

    public function testExplicitLineCategoryWins(): void
    {
        $order = $this->order([
            new SourceLine('Reverse charge', 1.0, 500.0, 500.0, 0.0, 'C62', 'AE'),
        ]);

        $invoice = $this->mapper->toGenerateBody($order, 'xrechnung')['invoice'];

        self::assertSame('AE', $invoice['lines'][0]['vatCategoryCode']);
        self::assertSame('AE', $invoice['taxSummary'][0]['vatCategoryCode']);
    }

    public function testRoundingKeepsTwoDecimalsAndConsistentTotals(): void
    {
        $order = $this->order([
            new SourceLine('Odd price', 3.0, 3.333, 9.999, 19.0),
        ]);

        $invoice = $this->mapper->toGenerateBody($order, 'xrechnung')['invoice'];

        self::assertEqualsWithDelta(3.33, $invoice['lines'][0]['unitPrice'], 0.0001);
        self::assertEqualsWithDelta(10.0, $invoice['lines'][0]['lineTotal'], 0.0001);
        self::assertEqualsWithDelta(10.0, $invoice['totalNetAmount'], 0.0001);
        self::assertEqualsWithDelta(1.9, $invoice['totalTaxAmount'], 0.0001);
        self::assertEqualsWithDelta(11.9, $invoice['totalGrossAmount'], 0.0001);
    }

    public function testOptionalFieldsIncludedWhenSet(): void
    {
        $order = $this->order(
            [new SourceLine('Item', 1.0, 100.0, 100.0, 19.0)],
            [
                'dueDate' => '2026-02-14',
                'buyerReference' => 'BUYER-01',
                'orderReference' => 'ORDER-01',
                'note' => 'Thank you',
                'paymentTerms' => 'Net 30',
                'paymentMeans' => new PaymentMeans('58', 'DE89370400440532013000'),
            ],
        );

        $invoice = $this->mapper->toGenerateBody($order, 'xrechnung')['invoice'];

        self::assertSame('2026-02-14', $invoice['dueDate']);
        self::assertSame('BUYER-01', $invoice['buyerReference']);
        self::assertSame('ORDER-01', $invoice['orderReference']);
        self::assertSame('Thank you', $invoice['note']);
        self::assertSame('Net 30', $invoice['paymentTerms']);
        self::assertSame('58', $invoice['paymentMeans']['typeCode']);
        self::assertSame('DE89370400440532013000', $invoice['paymentMeans']['iban']);
    }

    public function testOptionalFieldsOmittedWhenAbsent(): void
    {
        $invoice = $this->mapper->toGenerateBody(
            $this->order([new SourceLine('Item', 1.0, 100.0, 100.0, 19.0)]),
            'xrechnung',
        )['invoice'];

        self::assertArrayNotHasKey('dueDate', $invoice);
        self::assertArrayNotHasKey('buyerReference', $invoice);
        self::assertArrayNotHasKey('orderReference', $invoice);
        self::assertArrayNotHasKey('note', $invoice);
        self::assertArrayNotHasKey('paymentMeans', $invoice);
        self::assertArrayNotHasKey('paymentTerms', $invoice);
    }

    public function testProfilePassedThroughWhenGiven(): void
    {
        $body = $this->mapper->toGenerateBody(
            $this->order([new SourceLine('Item', 1.0, 100.0, 100.0, 19.0)]),
            'xrechnung',
            'xml',
            'en16931',
        );

        self::assertSame('en16931', $body['profile']);
    }

    public function testBuyerIsBusinessDetection(): void
    {
        $withVat = $this->order([new SourceLine('Item', 1.0, 100.0, 100.0, 19.0)], ['buyerVatId' => 'FR12345678901']);
        self::assertTrue($withVat->buyerIsBusiness());

        $flagged = $this->order([new SourceLine('Item', 1.0, 100.0, 100.0, 19.0)], ['buyerFlaggedBusiness' => true]);
        self::assertTrue($flagged->buyerIsBusiness());

        $consumer = $this->order([new SourceLine('Item', 1.0, 100.0, 100.0, 19.0)]);
        self::assertFalse($consumer->buyerIsBusiness());
    }

    public function testEmptyLinesThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mapper->toGenerateBody($this->order([]), 'xrechnung');
    }

    /**
     * @param list<SourceLine> $lines
     * @param array<string, mixed> $overrides
     */
    private function order(array $lines, array $overrides = []): SourceOrder
    {
        $seller = new Party('Seller GmbH', new Address('Berlin', '10115', 'DE', 'Hauptstrasse 1'), 'DE123456789');
        $buyer = new Party(
            'Buyer SARL',
            new Address('Paris', '75002', 'FR', 'Rue de la Paix 2'),
            $overrides['buyerVatId'] ?? null,
        );

        return new SourceOrder(
            number: 'INV-2026-001',
            issueDate: '2026-01-15',
            currencyCode: 'EUR',
            seller: $seller,
            buyer: $buyer,
            lines: $lines,
            dueDate: $overrides['dueDate'] ?? null,
            paymentMeans: $overrides['paymentMeans'] ?? null,
            paymentTerms: $overrides['paymentTerms'] ?? null,
            buyerReference: $overrides['buyerReference'] ?? null,
            orderReference: $overrides['orderReference'] ?? null,
            note: $overrides['note'] ?? null,
            buyerFlaggedBusiness: $overrides['buyerFlaggedBusiness'] ?? false,
            zeroRateCategory: $overrides['zeroRateCategory'] ?? 'Z',
        );
    }
}
