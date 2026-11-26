<?php declare(strict_types=1);

namespace Beliq\Shopware\Tests;

use Beliq\Shopware\Document\BeliqInvoiceRenderer;
use Beliq\Shopware\Document\InvoiceDocumentLookup;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Document\DocumentCollection;
use Shopware\Core\Checkout\Document\DocumentEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\PrefixFilter;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The number a new beliq document takes, from the numbers of the beliq documents
 * already stored. Shopware rejects a number that a document of the type already
 * holds on any order, so a collision is a failed create.
 */
final class InvoiceDocumentLookupTest extends TestCase
{
    public function testTheFirstDocumentTakesTheOrderNumber(): void
    {
        self::assertSame('10000', $this->nextNumber('10000', []));
    }

    public function testTheSecondDocumentTakesSuffixTwo(): void
    {
        self::assertSame('10000-2', $this->nextNumber('10000', ['10000']));
    }

    public function testTheNextNumberIsAboveTheHighestSuffixNotTheCount(): void
    {
        // 10000-2 was deleted: two documents remain, and 10000-3 is taken.
        self::assertSame('10000-4', $this->nextNumber('10000', ['10000', '10000-3']));
        // The first document was deleted.
        self::assertSame('10000-3', $this->nextNumber('10000', ['10000-2']));
    }

    public function testTheOrderInWhichDocumentsAreReadDoesNotMatter(): void
    {
        self::assertSame('10000-11', $this->nextNumber('10000', ['10000-10', '10000-9', '10000']));
    }

    public function testANumberInNeitherFormDoesNotCount(): void
    {
        // A number given by hand, another order's suffix form, and suffixes that
        // the plugin never hands out.
        self::assertSame('100', $this->nextNumber('100', ['R-2026-1', '1000-5', '100-0', '100-02', '100-2a']));
    }

    public function testAnOrderNumberWithRegexCharactersIsMatchedLiterally(): void
    {
        self::assertSame('SW.1+-2', $this->nextNumber('SW.1+', ['SW.1+']));
        self::assertSame('SW.1+', $this->nextNumber('SW.1+', ['SWx1-7']));
    }

    public function testAnotherOrdersOwnNumberInTheSuffixFormIsSkipped(): void
    {
        // Order A-1-2 took its own number for its first document. A regenerate of
        // order A-1 would give A-1-2 too, which Shopware rejects.
        self::assertSame('A-1-3', $this->nextNumber('A-1', ['A-1'], ['A-1-2']));
    }

    public function testAnOrderWhoseNumberAnotherOrdersRegenerateHoldsDoesNotTakeIt(): void
    {
        // Order A-1 was created again as A-1-2 before order A-1-2 was placed.
        self::assertSame('A-1-2-2', $this->nextNumber('A-1-2', [], ['A-1', 'A-1-2']));
    }

    public function testNumbersCompareWithoutRegardToCase(): void
    {
        // Shopware's duplicate check runs under MySQL's case-insensitive collation,
        // so sw-1-2 blocks SW-1-2.
        self::assertSame('SW-1-3', $this->nextNumber('SW-1', ['SW-1'], ['sw-1-2']));
        self::assertSame('SW-1-2', $this->nextNumber('SW-1', [], ['sw-1']));
    }

    public function testAnIssuedNumberCountsAfterItsDocumentIsDeleted(): void
    {
        // Every beliq document on the order was deleted. The buyer may still hold
        // 10000, so it is not issued again.
        self::assertSame('10000-2', $this->nextNumber('10000', [], issued: ['10000']));
        // 10000-2 was deleted, 10000 remains.
        self::assertSame('10000-3', $this->nextNumber('10000', ['10000'], issued: ['10000', '10000-2']));
    }

    public function testAnIssuedNumberInNeitherFormDoesNotCount(): void
    {
        self::assertSame('10000', $this->nextNumber('10000', [], issued: ['1000-5', '10000-0']));
    }

    public function testOnlyBeliqDocumentsCount(): void
    {
        // Shopware's own invoice can hold the same number: the duplicate check is
        // per document type.
        self::assertSame('10000', $this->nextNumber('10000', [], [], ['10000', '10000-2']));
    }

    public function testThePrecedingInvoiceIsTheOrdersHighestNumber(): void
    {
        self::assertNull($this->precedingInvoice('10000', []));
        self::assertSame(['number' => '10000', 'issueDate' => null], $this->precedingInvoice('10000', ['10000']));
        self::assertSame(
            ['number' => '10000-10', 'issueDate' => '2026-11-24'],
            $this->precedingInvoice('10000', ['10000-10', '10000-9', '10000'], ['10000-10' => '2026-11-24', '10000-9' => '2026-11-23']),
        );
    }

    public function testThePrecedingInvoiceIsAnIssuedNumberAboveTheDocuments(): void
    {
        // 10000-2 was deleted: its number counts, its date is gone.
        self::assertSame(
            ['number' => '10000-2', 'issueDate' => null],
            $this->precedingInvoice('10000', ['10000'], ['10000' => '2026-11-20'], issued: ['10000', '10000-2']),
        );
        // A number both hold takes the document's date.
        self::assertSame(
            ['number' => '10000', 'issueDate' => '2026-11-20'],
            $this->precedingInvoice('10000', ['10000'], ['10000' => '2026-11-20'], issued: ['10000']),
        );
    }

    public function testThePrecedingInvoiceIsOnlyTheOrdersOwnBeliqDocument(): void
    {
        // Order A-1's A-1-2, Shopware's own invoice, and numbers the plugin never
        // hands out are not this order's beliq invoices.
        self::assertNull($this->precedingInvoice('A-1-2', [], otherOrders: ['A-1-2', 'A-1-2-2'], otherTypes: ['A-1-2']));
        self::assertNull($this->precedingInvoice('100', ['R-2026-1', '1000-5', '100-0', '100-02', '100-2a']));
        // The match ignores case, as the number does.
        self::assertSame(['number' => 'sw-1-2', 'issueDate' => null], $this->precedingInvoice('SW-1', ['SW-1', 'sw-1-2']));
    }

    public function testANumericOrderNumberIsMatchedAsAString(): void
    {
        // A numeric string used as an array key turns into an int.
        self::assertSame(['number' => '10000-2', 'issueDate' => '2026-11-24'], $this->precedingInvoice('10000', ['10000-2'], ['10000-2' => '2026-11-24']));
        self::assertSame(['number' => '10000', 'issueDate' => '2026-11-24'], $this->precedingInvoice('10000', ['10000'], ['10000' => '2026-11-24']));
    }

    /**
     * @param list<string> $numbers beliq documents on the order
     * @param array<string, string> $issueDates by number, the issue date a beliq document on the order recorded
     * @param list<string> $otherOrders beliq documents on other orders
     * @param list<string> $otherTypes documents of another type on the order
     * @param list<string> $issued numbers issued for the order
     *
     * @return array{number: string, issueDate: ?string}|null
     */
    private function precedingInvoice(string $orderNumber, array $numbers, array $issueDates = [], array $otherOrders = [], array $otherTypes = [], array $issued = []): ?array
    {
        $orderId = Uuid::randomHex();

        return $this->lookup($orderId, $numbers, $otherOrders, $otherTypes, $issueDates)
            ->precedingInvoice($orderId, $orderNumber, $issued, Context::createDefaultContext());
    }

    /**
     * @param list<string> $numbers beliq documents on the order
     * @param list<string> $otherOrders beliq documents on other orders
     * @param list<string> $otherTypes documents of another type on the order
     * @param list<string> $issued numbers recorded on the order
     */
    private function nextNumber(string $orderNumber, array $numbers, array $otherOrders = [], array $otherTypes = [], array $issued = []): string
    {
        return $this->lookup(Uuid::randomHex(), $numbers, $otherOrders, $otherTypes)
            ->nextNumber($orderNumber, $issued, Context::createDefaultContext());
    }

    /**
     * @param list<string> $numbers
     * @param list<string> $otherOrders
     * @param list<string> $otherTypes
     * @param array<string, string> $issueDates
     */
    private function lookup(string $orderId, array $numbers, array $otherOrders, array $otherTypes, array $issueDates = []): InvoiceDocumentLookup
    {
        $documents = [];
        foreach ([[$numbers, $orderId, 'beliq_invoice'], [$otherOrders, Uuid::randomHex(), 'beliq_invoice'], [$otherTypes, $orderId, 'invoice']] as [$list, $documentOrderId, $type]) {
            foreach ($list as $number) {
                $documents[] = ['orderId' => $documentOrderId, 'documentNumber' => $number, 'documentType.technicalName' => $type];
            }
        }

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturnCallback(
            static function (Criteria $criteria, Context $context) use ($documents, $orderId, $issueDates): EntitySearchResult {
                $entities = [];
                foreach ($documents as $fields) {
                    foreach ($criteria->getFilters() as $filter) {
                        if (!self::passes($filter, $fields)) {
                            continue 2;
                        }
                    }
                    $document = new DocumentEntity();
                    $document->setId(Uuid::randomHex());
                    $document->setUniqueIdentifier($document->getId());
                    $document->setOrderId($fields['orderId']);
                    $document->setDocumentNumber($fields['documentNumber']);
                    $config = ['documentNumber' => $fields['documentNumber']];
                    if ($fields['documentType.technicalName'] === BeliqInvoiceRenderer::TYPE && $fields['orderId'] === $orderId && isset($issueDates[$fields['documentNumber']])) {
                        $config[BeliqInvoiceRenderer::ISSUE_DATE_CONFIG] = $issueDates[$fields['documentNumber']];
                    }
                    $document->setConfig($config);
                    $entities[] = $document;
                }

                return new EntitySearchResult('document', count($entities), new DocumentCollection($entities), null, $criteria, $context);
            },
        );

        return new InvoiceDocumentLookup($repository);
    }

    /**
     * What the DAL's SQL does with the filters the lookup can send, under
     * MySQL's case-insensitive default collation. A filter of another kind fails
     * the test rather than matching everything.
     *
     * @param array<string, string> $fields
     */
    private static function passes(Filter $filter, array $fields): bool
    {
        if ($filter instanceof MultiFilter) {
            $results = array_map(static fn (Filter $query): bool => self::passes($query, $fields), $filter->getQueries());

            return $filter->getOperator() === MultiFilter::CONNECTION_OR ? in_array(true, $results, true) : !in_array(false, $results, true);
        }
        if ($filter instanceof EqualsFilter) {
            return mb_strtolower($fields[$filter->getField()] ?? '') === mb_strtolower((string) $filter->getValue());
        }
        if ($filter instanceof PrefixFilter) {
            return str_starts_with(mb_strtolower($fields[$filter->getField()] ?? ''), mb_strtolower($filter->getValue()));
        }
        self::fail('the lookup sent a filter the test does not evaluate: ' . $filter::class);
    }
}
