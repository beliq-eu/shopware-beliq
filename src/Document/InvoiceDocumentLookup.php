<?php declare(strict_types=1);

namespace Beliq\Shopware\Document;

use Shopware\Core\Checkout\Document\DocumentCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\PrefixFilter;

/**
 * Reads the beliq invoice documents already attached to an order. The generation
 * trigger skips a re-fired transition once a document exists (auto generation
 * never overwrites), and the renderer picks a number no beliq document holds and
 * none was issued under, because Shopware rejects a duplicate document number.
 */
final class InvoiceDocumentLookup
{
    /**
     * @param EntityRepository<DocumentCollection> $documentRepository
     */
    public function __construct(private readonly EntityRepository $documentRepository)
    {
    }

    public function countForOrder(string $orderId, Context $context): int
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));
        $criteria->addFilter(new EqualsFilter('documentType.technicalName', BeliqInvoiceRenderer::TYPE));
        $criteria->setLimit(1);
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);

        return $this->documentRepository->searchIds($criteria, $context)->getTotal();
    }

    /**
     * The first beliq document on an order takes the order number. A later one
     * takes `<order>-<n>`, with n one more than the highest suffix in use, the order
     * number counting as 1. A count would hand out a number still in use once a
     * merchant deletes an earlier document. A number in neither form is never
     * handed out, so it does not count.
     *
     * `$issued` holds the issued numbers in these forms, of any order
     * (IssuedInvoiceNumbers). They count as documents do, so a number stays used
     * after its document is deleted: the buyer may hold that invoice.
     *
     * The numbers are read from the beliq documents of every order, because
     * Shopware's duplicate check spans them all: with dashes in the order number
     * range, another order's own number can have this order's suffix form (order
     * `A-1-2` next to order `A-1`). The numbers compare without regard to case,
     * as that check does under MySQL's default collation.
     *
     * @param list<string> $issued
     */
    public function nextNumber(string $orderNumber, array $issued, Context $context): string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('documentType.technicalName', BeliqInvoiceRenderer::TYPE));
        $criteria->addFilter(new OrFilter([
            new EqualsFilter('documentNumber', $orderNumber),
            new PrefixFilter('documentNumber', $orderNumber . '-'),
        ]));

        $documents = $this->documentRepository->search($criteria, $context)->getEntities();
        $numbers = $issued;
        foreach ($documents as $document) {
            $numbers[] = $document->getDocumentNumber();
        }
        $highest = 0;
        foreach ($numbers as $number) {
            $highest = max($highest, self::suffix($orderNumber, $number) ?? 0);
        }

        return $highest === 0 ? $orderNumber : $orderNumber . '-' . ($highest + 1);
    }

    /**
     * The invoice a new beliq document on the order replaces: the order's latest
     * beliq number, the one with the highest suffix, from the order's beliq
     * documents and the numbers issued for the order (`$issued`). A number whose
     * document was deleted still counts, because the buyer may hold that invoice.
     * Null before the order's first beliq document. A number in neither of the
     * order's forms, issued before the order was renumbered, is not named. A
     * number recorded for a create whose document Shopware then failed to store
     * is named too, though the buyer never got that invoice: the renderer
     * records the number before Shopware stores the document, so the two look
     * the same here.
     *
     * The issue date is the one its document recorded under
     * BeliqInvoiceRenderer::ISSUE_DATE_CONFIG, and null when there is none: a
     * document made by 0.2.13 or earlier recorded none (its BT-2 was the order
     * date), and a deleted document is not read.
     *
     * @param list<string> $issued
     *
     * @return array{number: string, issueDate: ?string}|null
     */
    public function precedingInvoice(string $orderId, string $orderNumber, array $issued, Context $context): ?array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));
        $criteria->addFilter(new EqualsFilter('documentType.technicalName', BeliqInvoiceRenderer::TYPE));

        $documents = $this->documentRepository->search($criteria, $context)->getEntities();
        // Documents first, so that on a number both hold the document's date wins.
        $candidates = [];
        foreach ($documents as $document) {
            $issueDate = $document->getConfig()[BeliqInvoiceRenderer::ISSUE_DATE_CONFIG] ?? null;
            $candidates[] = [$document->getDocumentNumber(), is_string($issueDate) ? $issueDate : null];
        }
        foreach ($issued as $number) {
            $candidates[] = [$number, null];
        }

        $preceding = null;
        $highest = 0;
        foreach ($candidates as [$number, $issueDate]) {
            $suffix = self::suffix($orderNumber, $number);
            if ($number !== null && $suffix !== null && $suffix > $highest) {
                $highest = $suffix;
                $preceding = ['number' => $number, 'issueDate' => $issueDate];
            }
        }

        return $preceding;
    }

    /**
     * The suffix of a number in one of the order's two forms, the order number
     * counting as 1, and null for any other number. The match ignores case, as
     * Shopware's duplicate check does under MySQL's default collation.
     */
    private static function suffix(string $orderNumber, ?string $number): ?int
    {
        if ($number === null || preg_match('/^' . preg_quote($orderNumber, '/') . '(?:-([1-9][0-9]*))?$/iuD', $number, $match) !== 1) {
            return null;
        }

        return isset($match[1]) ? (int) $match[1] : 1;
    }
}
