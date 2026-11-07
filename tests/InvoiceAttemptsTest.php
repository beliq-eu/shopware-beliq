<?php declare(strict_types=1);

namespace Beliq\Shopware\Tests;

use Beliq\Shopware\Exception\BeliqApiException;
use Beliq\Shopware\Exception\BeliqTransportException;
use Beliq\Shopware\Retry\InvoiceAttempts;
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
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The later invoice, end to end below Shopware: a real database (SQLite through
 * DBAL, a file so two connections share it), the real store and schedule, and a
 * generation that answers from a script. What happens to a paid order whose
 * invoice meets an outage, and what the order shows.
 */
final class InvoiceAttemptsTest extends TestCase
{
    private const T0 = 1_800_000_000;

    private string $dbFile;

    private TestClock $clock;

    private ScriptedGeneration $generation;

    private RecordingRecorder $recorder;

    private string $orderId;

    private string $salesChannelId;

    protected function setUp(): void
    {
        $this->dbFile = RetryDatabase::create();
        $this->clock = new TestClock(self::T0);
        $this->generation = new ScriptedGeneration($this->clock);
        $this->recorder = new RecordingRecorder();
        $this->orderId = Uuid::randomHex();
        $this->salesChannelId = Uuid::randomHex();
    }

    protected function tearDown(): void
    {
        RetryDatabase::remove($this->dbFile);
    }

    public function testAnOrderPaidDuringAnOutageGetsItsInvoiceAfterIt(): void
    {
        // Ten minutes of database outage, then beliq answers.
        $down = new BeliqApiException('down', 'DATABASE_UNAVAILABLE', 503, null, 30);
        $this->generation->script = [$down, $down, $down, $down, true];

        $attempts = $this->attempts();
        $attempts->first($this->orderId, $this->salesChannelId, Context::createDefaultContext());
        $this->runTheTaskEveryMinuteFor(70);

        self::assertSame([0, 120, 300, 600, 840], $this->generation->callOffsets(self::T0));
        self::assertSame(1, $this->generation->successes);
        self::assertNull($this->store()->find($this->orderId));
        self::assertTrue($this->recorder->clearedLast($this->orderId));
        // Each failure was on the order while it lasted, with the retry pending.
        self::assertSame('DATABASE_UNAVAILABLE (HTTP 503)', $this->recorder->failures[0]['description']);
        self::assertSame(self::T0 + 120, $this->recorder->failures[0]['nextAttemptAt']);
    }

    public function testTheOrderDataBeingWrongIsNotRetriedAndShowsOnTheOrder(): void
    {
        $this->generation->script = [new BeliqApiException('invalid', 'INVALID_INVOICE', 422)];

        $this->attempts()->first($this->orderId, $this->salesChannelId, Context::createDefaultContext());
        $this->runTheTaskEveryMinuteFor(70);

        self::assertCount(1, $this->generation->calls);
        self::assertNull($this->store()->find($this->orderId));
        self::assertSame([[
            'orderId' => $this->orderId,
            'description' => 'INVALID_INVOICE (HTTP 422)',
            'at' => self::T0,
            'nextAttemptAt' => null,
        ]], $this->recorder->failures);
    }

    public function testAnOutageLongerThanTheWindowEndsOnTheOrderWithNoRetryPending(): void
    {
        $this->generation->script = [new BeliqTransportException(BeliqTransportException::CONNECT_FAILED, 'refused')];

        $this->attempts()->first($this->orderId, $this->salesChannelId, Context::createDefaultContext());
        $this->runTheTaskEveryMinuteFor(90);

        self::assertSame([0, 120, 300, 600, 840, 1800, 3600], $this->generation->callOffsets(self::T0));
        self::assertNull($this->store()->find($this->orderId));
        $last = $this->recorder->failures[array_key_last($this->recorder->failures)];
        self::assertSame('connection: connect_failed', $last['description']);
        self::assertNull($last['nextAttemptAt']);
    }

    public function testTheOrderRequestsOwnTimeoutIsRetriedOnceAndALaterTimeoutIsNot(): void
    {
        $timeout = new BeliqTransportException(BeliqTransportException::TIMEOUT, 'timed out');
        $this->generation->script = [$timeout, $timeout];

        $this->attempts()->first($this->orderId, $this->salesChannelId, Context::createDefaultContext());
        $this->runTheTaskEveryMinuteFor(70);

        self::assertSame([false, true], array_column($this->generation->calls, 'laterAttempt'));
        self::assertNull($this->store()->find($this->orderId));
    }

    public function testARetryOfARequestThatMayHaveRunIsDroppedWhenTheTaskRunsTooLate(): void
    {
        $this->generation->script = [new BeliqApiException('engine', 'ENGINE_UNAVAILABLE', 503), true];

        $this->attempts()->first($this->orderId, $this->salesChannelId, Context::createDefaultContext());
        // No task ran for 20 minutes.
        $this->clock->set(self::T0 + 1200);
        $this->attempts()->runDue(Context::createDefaultContext());

        self::assertCount(1, $this->generation->calls);
        self::assertNull($this->store()->find($this->orderId));
        $last = $this->recorder->failures[array_key_last($this->recorder->failures)];
        self::assertSame('ENGINE_UNAVAILABLE (HTTP 503)', $last['description']);
        self::assertNull($last['nextAttemptAt']);
    }

    public function testAFirstAttemptThatSucceedsLeavesTheOrderAsItIs(): void
    {
        $this->attempts()->first($this->orderId, $this->salesChannelId, Context::createDefaultContext());

        self::assertSame(1, $this->generation->successes);
        self::assertSame([], $this->recorder->failures);
        self::assertSame([], $this->recorder->cleared);
        self::assertNull($this->store()->find($this->orderId));
    }

    public function testEngineUnavailableIsRetriedThreeTimesAtMost(): void
    {
        $this->generation->script = [new BeliqApiException('engine', 'ENGINE_UNAVAILABLE', 503)];

        $this->attempts()->first($this->orderId, $this->salesChannelId, Context::createDefaultContext());
        $this->runTheTaskEveryMinuteFor(70);

        self::assertSame([0, 120, 300, 600], $this->generation->callOffsets(self::T0));
    }

    public function testTheNextAttemptIsWrittenBeforeTheCallSoAKilledHandlerLeavesItInPlace(): void
    {
        $down = new BeliqApiException('down', 'DATABASE_UNAVAILABLE', 503);
        $this->generation->script = [$down, true];
        // During the later attempt, copy the database file: that copy is what a
        // process killed mid-call leaves behind.
        $killedState = $this->dbFile . '.killed';
        $this->generation->during = function (int $call) use ($killedState): void {
            if ($call === 1) {
                copy($this->dbFile, $killedState);
            }
        };

        $this->attempts()->first($this->orderId, $this->salesChannelId, Context::createDefaultContext());
        $this->runTheTaskEveryMinuteFor(3);
        self::assertSame(1, $this->generation->successes);

        // Restart from the killed state: the attempt in flight is leased, then due again.
        $this->dbFile = $killedState;
        $leased = $this->store()->find($this->orderId);
        self::assertNotNull($leased);
        self::assertSame(1, $leased->laterAttempts);
        self::assertGreaterThanOrEqual(self::T0 + 120 + RetrySchedule::ATTEMPT_LEASE_SECONDS, $leased->nextAttemptAt);

        $this->generation->script = [true];
        $this->generation->during = null;
        $this->clock->set($leased->nextAttemptAt);
        self::assertSame(1, $this->attempts()->runDue(Context::createDefaultContext()));
        self::assertSame(2, $this->generation->successes);
        self::assertNull($this->store()->find($this->orderId));
    }

    public function testTwoRunnersAtOnceMakeOneAttempt(): void
    {
        $down = new BeliqApiException('down', 'DATABASE_UNAVAILABLE', 503);
        $this->generation->script = [$down, true];
        $this->attempts()->first($this->orderId, $this->salesChannelId, Context::createDefaultContext());

        // Both runners read the due row before either claims it.
        $this->clock->set(self::T0 + 120);
        $storeA = $this->store();
        $storeB = $this->store();
        $dueA = $storeA->due(self::T0 + 120, 20);
        $dueB = $storeB->due(self::T0 + 120, 20);
        self::assertCount(1, $dueA);
        self::assertCount(1, $dueB);

        $claimed = RetrySchedule::claim($dueA[0]['state'], self::T0 + 120);
        self::assertTrue($storeA->claim($this->orderId, $dueA[0]['state'], $claimed, self::T0 + 120));
        self::assertFalse($storeB->claim($this->orderId, $dueB[0]['state'], $claimed, self::T0 + 120));

        // And a second task run started while the first one's call is in flight
        // finds nothing due, so the generation runs once.
        $nested = 0;
        $this->generation->during = function (int $call) use (&$nested): void {
            if ($call === 1) {
                $nested = $this->attempts()->runDue(Context::createDefaultContext());
            }
        };
        $this->clock->set(self::T0 + 2 * RetrySchedule::ATTEMPT_LEASE_SECONDS + 120);
        $this->attempts()->runDue(Context::createDefaultContext());

        self::assertSame(0, $nested);
        self::assertSame(1, $this->generation->successes);
    }

    public function testAReFiredTriggerWhileARetryIsPendingStartsNoSecondChain(): void
    {
        $this->generation->script = [new BeliqApiException('down', 'DATABASE_UNAVAILABLE', 503), true];

        $attempts = $this->attempts();
        $attempts->first($this->orderId, $this->salesChannelId, Context::createDefaultContext());
        $this->clock->set(self::T0 + 10);
        $attempts->first($this->orderId, $this->salesChannelId, Context::createDefaultContext());

        self::assertCount(1, $this->generation->calls);
    }

    public function testAPluginSwitchedOffDuringTheWindowEndsTheRetry(): void
    {
        $this->generation->script = [new BeliqApiException('down', 'DATABASE_UNAVAILABLE', 503), false];

        $this->attempts()->first($this->orderId, $this->salesChannelId, Context::createDefaultContext());
        $this->runTheTaskEveryMinuteFor(70);

        self::assertCount(2, $this->generation->calls);
        self::assertNull($this->store()->find($this->orderId));
        self::assertSame([$this->orderId], $this->recorder->stopped);
    }

    public function testATaskRunTakesAtMostItsCapOfDueOrders(): void
    {
        $down = new BeliqApiException('down', 'DATABASE_UNAVAILABLE', 503);
        $this->generation->script = [$down];
        for ($i = 0; $i < InvoiceAttempts::DUE_PER_RUN + 5; $i++) {
            $this->attempts()->first(Uuid::randomHex(), $this->salesChannelId, Context::createDefaultContext());
        }

        $this->clock->set(self::T0 + 120);

        self::assertSame(InvoiceAttempts::DUE_PER_RUN, $this->attempts()->runDue(Context::createDefaultContext()));
        self::assertSame(5, $this->attempts()->runDue(Context::createDefaultContext()));
    }

    private function runTheTaskEveryMinuteFor(int $minutes): void
    {
        for ($minute = 1; $minute <= $minutes; $minute++) {
            $this->clock->set(self::T0 + 60 * $minute);
            $this->attempts()->runDue(Context::createDefaultContext());
        }
    }

    private function attempts(): InvoiceAttempts
    {
        return new InvoiceAttempts($this->store(), $this->generation, $this->recorder, new NullLogger(), $this->clock);
    }

    private function store(): RetryStore
    {
        return new RetryStore($this->connection());
    }

    private function connection(): Connection
    {
        return RetryDatabase::connect($this->dbFile);
    }
}
