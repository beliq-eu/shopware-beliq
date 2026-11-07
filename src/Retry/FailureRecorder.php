<?php declare(strict_types=1);

namespace Beliq\Shopware\Retry;

use Shopware\Core\Framework\Context;

/** Keeps the last failure of an order's automatic invoice visible on the order. */
interface FailureRecorder
{
    /** @param int|null $nextAttemptAt when the next attempt is due, or null when none follows */
    public function recordFailure(string $orderId, string $description, int $at, ?int $nextAttemptAt, Context $context): void;

    /** The invoice was made: nothing is left to show. */
    public function clear(string $orderId, Context $context): void;

    /** The failure stays, but no attempt follows it any more. */
    public function stopRetrying(string $orderId, Context $context): void;
}
