<?php declare(strict_types=1);

namespace Beliq\Shopware\Tests;

use Beliq\Shopware\Exception\BeliqApiException;
use Beliq\Shopware\Migration\Migration1794009600BeliqInvoiceRetry;
use Beliq\Shopware\Retry\InvoiceAttempts;
use Beliq\Shopware\Retry\InvoiceRetryTaskHandler;
use Beliq\Shopware\Retry\OrderCustomFieldRecorder;
use Beliq\Shopware\Retry\OrderFailureFields;
use Beliq\Shopware\Retry\RetrySchedule;
use Beliq\Shopware\Retry\RetryStore;
use Beliq\Shopware\Tests\Support\RecordingRecorder;
use Beliq\Shopware\Tests\Support\RetryDatabase;
use Beliq\Shopware\Tests\Support\ScriptedGeneration;
use Beliq\Shopware\Tests\Support\TestClock;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * What the plugin writes outside its retry logic: the custom field set at
 * install, the order's failure fields, the retry table's schema, and the task
 * handler. The Dockware run exercises them in a real shop; these hold the
 * payloads and the schema in the repo.
 */
final class OrderFailureFieldsTest extends TestCase
{
    public function testInstallCreatesTheSetOnTheOrderHiddenFromTheStoreApi(): void
    {
        $created = null;
        $sets = $this->createMock(EntityRepository::class);
        $sets->method('searchIds')->willReturnCallback(
            static fn (Criteria $c, Context $ctx): IdSearchResult => new IdSearchResult(0, [], $c, $ctx),
        );
        $sets->method('create')->willReturnCallback(static function (array $payload, Context $context) use (&$created): EntityWrittenContainerEvent {
            $created = $payload;

            return EntityWrittenContainerEvent::createWithWrittenEvents([], $context, []);
        });

        OrderFailureFields::install($sets, Context::createDefaultContext());

        self::assertIsArray($created);
        self::assertSame(OrderFailureFields::SET_NAME, $created[0]['name']);
        self::assertSame([['entityName' => 'order']], $created[0]['relations']);
        $fields = $created[0]['customFields'];
        self::assertSame(
            [OrderFailureFields::FAILED_AT, OrderFailureFields::FAILURE, OrderFailureFields::NEXT_ATTEMPT_AT],
            array_column($fields, 'name'),
        );
        foreach ($fields as $field) {
            self::assertFalse($field['storeApiAware'], $field['name'] . ' must stay out of the Store API');
        }
    }

    public function testInstallLeavesAnExistingSetAlone(): void
    {
        $sets = $this->createMock(EntityRepository::class);
        $sets->method('searchIds')->willReturnCallback(
            static fn (Criteria $c, Context $ctx): IdSearchResult => new IdSearchResult(1, [], $c, $ctx),
        );
        $sets->expects(self::never())->method('create');

        OrderFailureFields::install($sets, Context::createDefaultContext());
    }

    public function testTheRecorderWritesTheThreeFieldsAndClearsThem(): void
    {
        $writes = [];
        $orders = $this->createMock(EntityRepository::class);
        $orders->method('update')->willReturnCallback(static function (array $payload, Context $context) use (&$writes): EntityWrittenContainerEvent {
            $writes[] = ['payload' => $payload, 'scope' => $context->getScope()];

            return EntityWrittenContainerEvent::createWithWrittenEvents([], $context, []);
        });
        $recorder = new OrderCustomFieldRecorder($orders);
        $orderId = Uuid::randomHex();
        $context = Context::createDefaultContext();

        $recorder->recordFailure($orderId, 'DATABASE_UNAVAILABLE (HTTP 503)', 1_800_000_000, 1_800_000_120, $context);
        $recorder->recordFailure($orderId, 'INVALID_INVOICE (HTTP 422)', 1_800_000_000, null, $context);
        $recorder->stopRetrying($orderId, $context);
        $recorder->clear($orderId, $context);

        self::assertSame([['id' => $orderId, 'customFields' => [
            OrderFailureFields::FAILED_AT => '2027-01-15T08:00:00+00:00',
            OrderFailureFields::FAILURE => 'DATABASE_UNAVAILABLE (HTTP 503)',
            OrderFailureFields::NEXT_ATTEMPT_AT => '2027-01-15T08:02:00+00:00',
        ]]], $writes[0]['payload']);
        self::assertNull($writes[1]['payload'][0]['customFields'][OrderFailureFields::NEXT_ATTEMPT_AT]);
        self::assertSame([OrderFailureFields::NEXT_ATTEMPT_AT => null], $writes[2]['payload'][0]['customFields']);
        self::assertSame(
            [OrderFailureFields::FAILED_AT => null, OrderFailureFields::FAILURE => null, OrderFailureFields::NEXT_ATTEMPT_AT => null],
            $writes[3]['payload'][0]['customFields'],
        );
        // The first attempt runs in a storefront or webhook request.
        self::assertSame([Context::SYSTEM_SCOPE], array_values(array_unique(array_column($writes, 'scope'))));
    }

    /** The SQLite table the retry tests use must have the migration's columns, no more and no fewer. */
    public function testTheTestDatabaseHasTheMigrationsColumns(): void
    {
        $sql = '';
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(static function (string $statement) use (&$sql): int {
            $sql = $statement;

            return 0;
        });
        (new Migration1794009600BeliqInvoiceRetry())->update($connection);

        preg_match_all('/^\s+`(\w+)`\s+[A-Z]/m', $sql, $match);
        $file = RetryDatabase::create();
        try {
            $columns = array_column(
                RetryDatabase::connect($file)->fetchAllAssociative('PRAGMA table_info(' . RetryStore::TABLE . ')'),
                'name',
            );
        } finally {
            RetryDatabase::remove($file);
        }

        self::assertSame($match[1], $columns);
        self::assertStringContainsString('REFERENCES `order` (`id`, `version_id`) ON DELETE CASCADE', $sql);
        self::assertStringContainsString('REFERENCES `sales_channel` (`id`) ON DELETE CASCADE', $sql);
        self::assertStringContainsString('KEY `idx.beliq_invoice_retry.next_attempt_at` (`next_attempt_at`)', $sql);
    }

    public function testTheTaskHandlerWorksThroughTheDueAttempts(): void
    {
        $file = RetryDatabase::create();
        try {
            $clock = new TestClock(1_800_000_000);
            $generation = new ScriptedGeneration($clock);
            $generation->script = [new BeliqApiException('down', 'DATABASE_UNAVAILABLE', 503), true];
            $attempts = new InvoiceAttempts(new RetryStore(RetryDatabase::connect($file)), $generation, new RecordingRecorder(), new NullLogger(), $clock);
            $attempts->first(Uuid::randomHex(), Uuid::randomHex(), Context::createDefaultContext());
            $clock->set(1_800_000_000 + RetrySchedule::LATER_ATTEMPT_OFFSETS[0]);

            (new InvoiceRetryTaskHandler($this->createMock(EntityRepository::class), new NullLogger(), $attempts))->run();

            self::assertSame([false, true], array_column($generation->calls, 'laterAttempt'));
            self::assertSame(1, $generation->successes);
        } finally {
            RetryDatabase::remove($file);
        }
    }
}
