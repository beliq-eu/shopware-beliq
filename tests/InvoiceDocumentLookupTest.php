<?php declare(strict_types=1);

namespace Beliq\Shopware\Tests;

use Beliq\Shopware\Document\InvoiceDocumentLookup;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Document\DocumentCollection;
use Shopware\Core\Checkout\Document\DocumentEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The number a new beliq document takes, from the numbers of the beliq documents
 * already on the order. Shopware rejects a number that a document of the type
 * already holds, so a collision is a failed create.
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

    /**
     * @param list<string> $numbers
     */
    private function nextNumber(string $orderNumber, array $numbers): string
    {
        $documents = [];
        foreach ($numbers as $number) {
            $document = new DocumentEntity();
            $document->setId(Uuid::randomHex());
            $document->setUniqueIdentifier($document->getId());
            $document->setDocumentNumber($number);
            $documents[] = $document;
        }

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturnCallback(
            static fn (Criteria $criteria, Context $context): EntitySearchResult => new EntitySearchResult(
                'document',
                count($documents),
                new DocumentCollection($documents),
                null,
                $criteria,
                $context,
            ),
        );

        return (new InvoiceDocumentLookup($repository))->nextNumber(Uuid::randomHex(), $orderNumber, Context::createDefaultContext());
    }
}
