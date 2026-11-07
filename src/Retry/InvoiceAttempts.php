<?php declare(strict_types=1);

namespace Beliq\Shopware\Retry;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;

/**
 * Runs the attempts at an order's automatic invoice: the first inside the
 * order's state change, the later ones from the scheduled task. Every attempt
 * writes the next one before it calls beliq, so a process killed mid-call leaves
 * a due attempt behind instead of nothing. Every failure is recorded on the
 * order, retried or not.
 */
final class InvoiceAttempts
{
    /** Orders one task run takes, so a long outage's backlog drains over several runs. */
    public const DUE_PER_RUN = 20;

    public function __construct(
        private readonly RetryStore $store,
        private readonly InvoiceGeneration $generation,
        private readonly FailureRecorder $recorder,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
    ) {
    }

    public function first(string $orderId, string $salesChannelId, Context $context): void
    {
        if ($this->store->find($orderId) !== null) {
            return;
        }
        $state = RetrySchedule::start($this->now());
        if (!$this->store->start($orderId, $salesChannelId, $state)) {
            return;
        }

        $this->attempt($orderId, $salesChannelId, false, $state, $context);
    }

    /** @return int the number of attempts this run made */
    public function runDue(Context $context): int
    {
        $made = 0;
        foreach ($this->store->due($this->now(), self::DUE_PER_RUN) as $row) {
            try {
                if ($this->later($row['orderId'], $row['salesChannelId'], $row['lastError'], $row['state'], $context)) {
                    $made++;
                }
            } catch (\Throwable $e) {
                $this->logger->error('beliq invoice retry failed', ['orderId' => $row['orderId'], 'exception' => $e->getMessage()]);
            }
        }

        return $made;
    }

    private function later(string $orderId, string $salesChannelId, ?string $lastError, RetryState $seen, Context $context): bool
    {
        // The last attempt was claimed but never settled: its process died mid-call.
        if (RetrySchedule::attemptsExhausted($seen)) {
            $this->store->delete($orderId);
            $this->recorder->recordFailure($orderId, $lastError ?? 'no answer', $this->now(), null, $context);

            return false;
        }

        // A retry of a request that may have run, due inside the first 15
        // minutes, whose task ran too late to start it there.
        if (RetrySchedule::expired($seen, $this->now())) {
            $this->store->delete($orderId);
            $this->recorder->recordFailure($orderId, $lastError ?? 'no answer', $this->now(), null, $context);

            return false;
        }

        $claimed = RetrySchedule::claim($seen, $this->now());
        if (!$this->store->claim($orderId, $seen, $claimed, $this->now())) {
            return false;
        }

        $this->attempt($orderId, $salesChannelId, true, $claimed, $context);

        return true;
    }

    private function attempt(string $orderId, string $salesChannelId, bool $laterAttempt, RetryState $state, Context $context): void
    {
        try {
            $attempted = $this->generation->generate($orderId, $salesChannelId, $laterAttempt, $context);
        } catch (\Throwable $failure) {
            $this->settleFailure($orderId, $state, $failure, $context);

            return;
        }

        $this->store->delete($orderId);
        // Only a later attempt follows a failure the order shows; a first
        // attempt that succeeds leaves the order as it is.
        if (!$laterAttempt) {
            return;
        }
        if ($attempted) {
            $this->recorder->clear($orderId, $context);
        } else {
            $this->recorder->stopRetrying($orderId, $context);
        }
    }

    private function settleFailure(string $orderId, RetryState $state, \Throwable $failure, Context $context): void
    {
        $now = $this->now();
        $description = RetrySchedule::describe($failure);
        $next = RetrySchedule::afterFailure($state, $failure, $now);

        $this->logger->error('beliq invoice generation failed', [
            'orderId' => $orderId,
            'exception' => $failure->getMessage(),
            'nextAttemptAt' => $next?->nextAttemptAt,
        ]);

        if ($next === null) {
            $this->store->delete($orderId);
        } else {
            $this->store->reschedule($orderId, $next, $description);
        }
        $this->recorder->recordFailure($orderId, $description, $now, $next?->nextAttemptAt, $context);
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
