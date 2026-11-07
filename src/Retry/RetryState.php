<?php declare(strict_types=1);

namespace Beliq\Shopware\Retry;

/** Where an order's automatic invoice stands in its later attempts. Times are Unix seconds. */
final class RetryState
{
    public function __construct(
        public readonly int $firstAttemptAt,
        /** Later attempts started so far, the one in flight included. */
        public readonly int $laterAttempts,
        /** Retries spent on failures of the count class. */
        public readonly int $countRetries,
        public readonly int $nextAttemptAt,
    ) {
    }
}
