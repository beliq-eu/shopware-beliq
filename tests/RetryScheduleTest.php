<?php declare(strict_types=1);

namespace Beliq\Shopware\Tests;

use Beliq\Shopware\Exception\BeliqApiException;
use Beliq\Shopware\Exception\BeliqTransportException;
use Beliq\Shopware\Retry\RetrySchedule;
use Beliq\Shopware\Retry\RetryState;
use PHPUnit\Framework\TestCase;

/**
 * Which failures of an automatic invoice are tried again later, and when. The
 * classes follow the beliq SDKs: an outage a wait can outlast is retried through
 * the whole window, a failure that may repeat work on beliq's side at most three
 * times inside the first 15 minutes, and anything that sends the same wrong body
 * again never.
 */
final class RetryScheduleTest extends TestCase
{
    private const T0 = 1_800_000_000;

    /** @return iterable<string, array{\Throwable, bool, string}> */
    public static function classes(): iterable
    {
        yield 'database outage' => [self::api(503, 'DATABASE_UNAVAILABLE'), true, RetrySchedule::WINDOW];
        yield '502 from the edge, no beliq code' => [self::api(502, 'HTTP_502'), true, RetrySchedule::WINDOW];
        yield '503 from the edge, no beliq code' => [self::api(503, 'HTTP_503'), false, RetrySchedule::WINDOW];
        yield 'engine unavailable' => [self::api(503, 'ENGINE_UNAVAILABLE'), true, RetrySchedule::COUNT];
        yield 'engine unavailable as a 502' => [self::api(502, 'ENGINE_UNAVAILABLE'), true, RetrySchedule::COUNT];
        yield 'throttled' => [self::api(429, 'RATE_LIMITED'), true, RetrySchedule::COUNT];
        yield 'quota gone' => [self::api(429, 'QUOTA_EXCEEDED'), true, RetrySchedule::NONE];
        yield 'the order data is wrong' => [self::api(422, 'INVALID_INVOICE'), true, RetrySchedule::NONE];
        yield 'no key' => [self::api(401, 'AUTHENTICATION_REQUIRED'), true, RetrySchedule::NONE];
        yield 'key revoked' => [self::api(403, 'INVALID_API_KEY'), true, RetrySchedule::NONE];
        yield 'engine timeout' => [self::api(504, 'ENGINE_UNAVAILABLE'), true, RetrySchedule::NONE];
        yield 'connection refused' => [new BeliqTransportException(BeliqTransportException::CONNECT_FAILED, 'x'), false, RetrySchedule::WINDOW];
        yield 'connect timeout' => [new BeliqTransportException(BeliqTransportException::CONNECT_TIMEOUT, 'x'), false, RetrySchedule::WINDOW];
        // The named departure: the order request's own 30 s can be shorter than
        // beliq's own deadlines, so it starts the later attempts once.
        yield "the order request's own timeout" => [new BeliqTransportException(BeliqTransportException::TIMEOUT, 'x'), true, RetrySchedule::WINDOW];
        yield "a later attempt's own timeout" => [new BeliqTransportException(BeliqTransportException::TIMEOUT, 'x'), false, RetrySchedule::NONE];
        yield 'connection lost after the send' => [new BeliqTransportException(BeliqTransportException::CONNECTION_LOST, 'x'), true, RetrySchedule::COUNT];
        yield 'TLS failure' => [new BeliqTransportException(BeliqTransportException::TLS, 'x'), true, RetrySchedule::NONE];
        yield 'a mapping error' => [new \RuntimeException('Order has no billing address.'), true, RetrySchedule::NONE];
    }

    /** @dataProvider classes */
    #[\PHPUnit\Framework\Attributes\DataProvider('classes')]
    public function testClassifiesEachFailure(\Throwable $failure, bool $firstAttempt, string $expected): void
    {
        self::assertSame($expected, RetrySchedule::classify($failure, $firstAttempt));
    }

    public function testAnOutageIsTriedAtTheSixSlotsThenGivenUp(): void
    {
        $state = RetrySchedule::start(self::T0);
        self::assertSame(self::T0 + 120, $state->nextAttemptAt);

        $times = [];
        $now = self::T0;
        while (true) {
            $state = RetrySchedule::afterFailure($state, self::api(503, 'DATABASE_UNAVAILABLE'), $now);
            if ($state === null) {
                break;
            }
            $times[] = $state->nextAttemptAt - self::T0;
            $now = $state->nextAttemptAt;
            $state = RetrySchedule::claim($state, $now);
        }

        self::assertSame([120, 300, 600, 840, 1800, 3600], $times);
    }

    public function testRetryAfterLongerThanTheSlotPushesTheAttempt(): void
    {
        $failure = self::api(503, 'DATABASE_UNAVAILABLE', retryAfter: 400);

        $state = RetrySchedule::afterFailure(RetrySchedule::start(self::T0), $failure, self::T0 + 1);

        self::assertNotNull($state);
        self::assertSame(self::T0 + 401, $state->nextAttemptAt);
    }

    public function testRetryAfterShorterThanTheSlotKeepsTheSlot(): void
    {
        $failure = self::api(503, 'DATABASE_UNAVAILABLE', retryAfter: 30);

        $state = RetrySchedule::afterFailure(RetrySchedule::start(self::T0), $failure, self::T0 + 1);

        self::assertNotNull($state);
        self::assertSame(self::T0 + 120, $state->nextAttemptAt);
    }

    public function testCountClassStopsAfterThreeRetries(): void
    {
        $state = RetrySchedule::start(self::T0);
        $now = self::T0;
        $retries = 0;
        while (($state = RetrySchedule::afterFailure($state, self::api(503, 'ENGINE_UNAVAILABLE'), $now)) !== null) {
            $retries++;
            $now = $state->nextAttemptAt;
            $state = RetrySchedule::claim($state, $now);
        }

        self::assertSame(3, $retries);
    }

    public function testCountClassNeverSchedulesPastTheFirstFifteenMinutes(): void
    {
        // A window-class failure first, then the late slots: a count-class failure
        // at minute 14 would be retried at minute 30, outside the uncharged window.
        $state = new RetryState(self::T0, laterAttempts: 4, countRetries: 0, nextAttemptAt: self::T0 + 1800);

        self::assertNull(RetrySchedule::afterFailure($state, self::api(429, 'RATE_LIMITED'), self::T0 + 845));
    }

    public function testAClaimLeasesTheAttemptPastItsOwnTimeout(): void
    {
        // An attempt run late, at minute 9, would otherwise leave the next slot
        // (minute 10) due while it is still running, and a second runner would
        // start a parallel call for the same order.
        $state = new RetryState(self::T0, laterAttempts: 2, countRetries: 0, nextAttemptAt: self::T0 + 300);

        $claimed = RetrySchedule::claim($state, self::T0 + 540);

        self::assertSame(3, $claimed->laterAttempts);
        self::assertGreaterThanOrEqual(self::T0 + 540 + RetrySchedule::ATTEMPT_LEASE_SECONDS, $claimed->nextAttemptAt);
        self::assertGreaterThan(RetrySchedule::LATER_ATTEMPT_TIMEOUT_SECONDS, RetrySchedule::ATTEMPT_LEASE_SECONDS);
    }

    public function testDescribesTheFailureForTheOrder(): void
    {
        self::assertSame('DATABASE_UNAVAILABLE (HTTP 503)', RetrySchedule::describe(self::api(503, 'DATABASE_UNAVAILABLE')));
        self::assertSame('connection: connect_timeout', RetrySchedule::describe(new BeliqTransportException(BeliqTransportException::CONNECT_TIMEOUT, 'x')));
        self::assertSame('Order has no billing address.', RetrySchedule::describe(new \RuntimeException('Order has no billing address.')));
    }

    private static function api(int $status, string $code, ?int $retryAfter = null): BeliqApiException
    {
        return new BeliqApiException('failed', $code, $status, null, $retryAfter);
    }
}
