<?php declare(strict_types=1);

namespace Beliq\Shopware\Tests\Support;

use Beliq\Shopware\Retry\FailureRecorder;
use Shopware\Core\Framework\Context;

final class RecordingRecorder implements FailureRecorder
{
    /** @var list<array{orderId: string, description: string, at: int, nextAttemptAt: ?int}> */
    public array $failures = [];

    /** @var list<string> */
    public array $cleared = [];

    /** @var list<string> */
    public array $stopped = [];

    /** @var list<string> */
    private array $events = [];

    public function recordFailure(string $orderId, string $description, int $at, ?int $nextAttemptAt, Context $context): void
    {
        $this->failures[] = ['orderId' => $orderId, 'description' => $description, 'at' => $at, 'nextAttemptAt' => $nextAttemptAt];
        $this->events[] = 'failure';
    }

    public function clear(string $orderId, Context $context): void
    {
        $this->cleared[] = $orderId;
        $this->events[] = 'clear';
    }

    public function stopRetrying(string $orderId, Context $context): void
    {
        $this->stopped[] = $orderId;
        $this->events[] = 'stop';
    }

    public function clearedLast(string $orderId): bool
    {
        return end($this->events) === 'clear' && end($this->cleared) === $orderId;
    }
}
