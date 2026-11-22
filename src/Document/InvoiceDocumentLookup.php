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
 * never overwrites), and the renderer picks a number no beliq document holds,
 * because Shopware rejects a duplicate document number.
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
     * The numbers are read from the beliq documents of every order, because
     * Shopware's duplicate check spans them all: with dashes in the order number
     * range, another order's own number can have this order's suffix form (order
     * `A-1-2` next to order `A-1`).
     */
    public function nextNumber(string $orderNumber, Context $context): string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('documentType.technicalName', BeliqInvoiceRenderer::TYPE));
        $criteria->addFilter(new OrFilter([
            new EqualsFilter('documentNumber', $orderNumber),
            new PrefixFilter('documentNumber', $orderNumber . '-'),
        ]));

        $suffix = '/^' . preg_quote($orderNumber, '/') . '-([1-9][0-9]*)$/D';
        $documents = $this->documentRepository->search($criteria, $context)->getEntities();
        $highest = 0;
        foreach ($documents as $document) {
            $number = $document->getDocumentNumber();
            if ($number === $orderNumber) {
                $highest = max($highest, 1);
            } elseif ($number !== null && preg_match($suffix, $number, $match) === 1) {
                $highest = max($highest, (int) $match[1]);
            }
        }

        return $highest === 0 ? $orderNumber : $orderNumber . '-' . ($highest + 1);
    }
}
