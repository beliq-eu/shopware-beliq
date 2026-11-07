<?php declare(strict_types=1);

namespace Beliq\Shopware\Retry;

/** Where an order's automatic invoice stands in its later attempts. Times are Unix seconds. */
final class RetryState
{
    public function __construct(
        public readonly int $firstAttemptAt,
        /** Slots of RetrySchedule::LATER_ATTEMPT_OFFSETS used so far: started, or skipped because they had passed. */
        public readonly int $laterAttempts,
        /** Retries spent on failures of the count class. */
        public readonly int $countRetries,
        public readonly int $nextAttemptAt,
        /** The latest time the next attempt may start, when the failure before it was of the count class. */
        public readonly ?int $notAfter = null,
    ) {
    }
}
