<?php declare(strict_types=1);

namespace Beliq\Shopware\Document;

use Shopware\Core\Checkout\Document\DocumentCollection;
use Shopware\Core\Checkout\Document\DocumentEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

/**
 * Reads the beliq invoice documents already attached to an order. The generation
 * trigger skips a re-fired transition once a document exists (auto generation
 * never overwrites), and the renderer picks a number no document on the order
 * holds, because Shopware rejects a duplicate document number.
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
     */
    public function nextNumber(string $orderId, string $orderNumber, Context $context): string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));
        $criteria->addFilter(new EqualsFilter('documentType.technicalName', BeliqInvoiceRenderer::TYPE));

        $suffix = '/^' . preg_quote($orderNumber, '/') . '-([1-9][0-9]*)$/D';
        $highest = 0;
        foreach ($this->documentRepository->search($criteria, $context)->getEntities() as $document) {
            if (!$document instanceof DocumentEntity) {
                continue;
            }
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
