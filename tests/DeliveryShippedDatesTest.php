<?php declare(strict_types=1);

namespace Beliq\Shopware\Tests;

use Beliq\Shopware\Document\DeliveryShippedDates;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineHistory\StateMachineHistoryCollection;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineHistory\StateMachineHistoryEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;

/**
 * The history a delivery's state machine writes, read back as the days it was
 * set to shipped. The rows here are the transitions Shopware's delivery state
 * machine has (Migration1536233560BasicData).
 */
final class DeliveryShippedDatesTest extends TestCase
{
    public function testTheDaysEachDeliveryWasSetToShipped(): void
    {
        $first = Uuid::randomHex();
        $second = Uuid::randomHex();
        $criteria = null;
        $lookup = $this->lookup([
            [$first, 'open', 'shipped', '2026-07-01T22:30:00+00:00'],
            // "ship" from shipped to shipped does not ship the goods again.
            [$first, 'shipped', 'shipped', '2026-07-05T09:00:00+00:00'],
            [$first, 'shipped', 'returned', '2026-07-08T09:00:00+00:00'],
            [$second, 'open', 'shipped_partially', '2026-07-01T09:00:00+00:00'],
            // Shopware stores UTC; a time in another zone is the same instant.
            [$second, 'shipped_partially', 'shipped', '2026-07-03T00:30:00+02:00'],
        ], $criteria);

        $dates = $lookup->forDeliveries([$first, $second], Context::createDefaultContext());

        self::assertSame([$first => ['2026-07-01'], $second => ['2026-07-02']], $dates);
        self::assertInstanceOf(Criteria::class, $criteria);
        $filters = $criteria->getFilters();
        self::assertEquals(new EqualsFilter('entityName', 'order_delivery'), $filters[0]);
        self::assertEquals(new EqualsAnyFilter('referencedId', [$first, $second]), $filters[1]);
    }

    public function testADeliverySetToShippedAgainAfterACancelKeepsBothDays(): void
    {
        $delivery = Uuid::randomHex();
        $criteria = null;
        $lookup = $this->lookup([
            [$delivery, 'open', 'shipped', '2026-07-01T09:00:00+00:00'],
            [$delivery, 'shipped', 'cancelled', '2026-07-02T09:00:00+00:00'],
            [$delivery, 'cancelled', 'open', '2026-07-03T09:00:00+00:00'],
            [$delivery, 'open', 'shipped', '2026-07-04T09:00:00+00:00'],
        ], $criteria);

        self::assertSame([$delivery => ['2026-07-01', '2026-07-04']], $lookup->forDeliveries([$delivery], Context::createDefaultContext()));
    }

    public function testADeliveryNeverSetToShippedHasNoEntry(): void
    {
        $delivery = Uuid::randomHex();
        $criteria = null;
        $lookup = $this->lookup([[$delivery, 'open', 'cancelled', '2026-07-01T09:00:00+00:00']], $criteria);

        self::assertSame([], $lookup->forDeliveries([$delivery], Context::createDefaultContext()));
    }

    public function testNoDeliveriesReadsNothing(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::never())->method('search');

        self::assertSame([], (new DeliveryShippedDates($repository))->forDeliveries([], Context::createDefaultContext()));
    }

    /**
     * @param list<array{0: string, 1: string, 2: string, 3: string}> $rows delivery id, from state, to state, created at
     */
    private function lookup(array $rows, ?Criteria &$seen): DeliveryShippedDates
    {
        $entries = [];
        foreach ($rows as [$deliveryId, $from, $to, $at]) {
            $entry = new StateMachineHistoryEntity();
            $entry->setId(Uuid::randomHex());
            $entry->setUniqueIdentifier($entry->getId());
            $entry->setEntityName('order_delivery');
            $entry->setReferencedId($deliveryId);
            $entry->setFromStateMachineState($this->state($from));
            $entry->setToStateMachineState($this->state($to));
            $entry->setCreatedAt(new \DateTimeImmutable($at));
            $entries[] = $entry;
        }

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturnCallback(
            static function (Criteria $criteria, Context $context) use ($entries, &$seen): EntitySearchResult {
                $seen = $criteria;

                return new EntitySearchResult('state_machine_history', count($entries), new StateMachineHistoryCollection($entries), null, $criteria, $context);
            },
        );

        return new DeliveryShippedDates($repository);
    }

    private function state(string $technicalName): StateMachineStateEntity
    {
        $state = new StateMachineStateEntity();
        $state->setId(Uuid::randomHex());
        $state->setUniqueIdentifier($state->getId());
        $state->setTechnicalName($technicalName);

        return $state;
    }
}
