<?php declare(strict_types=1);

namespace Beliq\Shopware\Document;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

/**
 * Counts the beliq invoice documents already attached to an order. Both the
 * generation trigger and the renderer need this: the trigger skips a re-fired
 * transition once a document exists (auto generation never overwrites), and the
 * renderer disambiguates the document number when a merchant regenerates from the
 * order's Documents card (Shopware rejects a duplicate document number).
 */
final class InvoiceDocumentLookup
{
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
}
