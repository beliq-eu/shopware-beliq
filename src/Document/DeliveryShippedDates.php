<?php declare(strict_types=1);

namespace Beliq\Shopware\Document;

use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineHistory\StateMachineHistoryCollection;

/**
 * Reads, from Shopware's state machine history, the days on which each delivery
 * of an order was set to shipped. Shopware records no delivery date of its own:
 * the "shipped" state is the one record that the goods left.
 *
 * Shopware's delivery state machine has a transition from shipped to shipped,
 * which does not ship the goods again, so it does not count. A day is a UTC
 * date, the basis the invoice's issue date is read on.
 */
final class DeliveryShippedDates
{
    /**
     * @param EntityRepository<StateMachineHistoryCollection> $historyRepository
     */
    public function __construct(private readonly EntityRepository $historyRepository)
    {
    }

    /**
     * @param list<string> $deliveryIds
     * @return array<string, list<string>> per delivery id, the Y-m-d dates it was set to shipped
     */
    public function forDeliveries(array $deliveryIds, Context $context): array
    {
        if ($deliveryIds === []) {
            return [];
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('entityName', OrderDeliveryDefinition::ENTITY_NAME));
        $criteria->addFilter(new EqualsAnyFilter('referencedId', $deliveryIds));
        $criteria->addAssociation('fromStateMachineState');
        $criteria->addAssociation('toStateMachineState');

        $dates = [];
        foreach ($this->historyRepository->search($criteria, $context)->getEntities() as $entry) {
            if ($entry->getToStateMachineState()?->getTechnicalName() !== OrderDeliveryStates::STATE_SHIPPED
                || $entry->getFromStateMachineState()?->getTechnicalName() === OrderDeliveryStates::STATE_SHIPPED
            ) {
                continue;
            }
            $createdAt = $entry->getCreatedAt();
            if ($createdAt === null) {
                continue;
            }
            $date = \DateTimeImmutable::createFromInterface($createdAt)
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d');
            $dates[$entry->getReferencedId()][$date] = true;
        }

        return array_map(static fn (array $days): array => array_map('strval', array_keys($days)), $dates);
    }
}
