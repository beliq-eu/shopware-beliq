<?php declare(strict_types=1);

namespace Beliq\Shopware\Tests\Support;

use Beliq\Shopware\Retry\InvoiceGeneration;
use Shopware\Core\Framework\Context;

/** Answers each call from a script: true is a stored invoice, false the plugin off, a Throwable a failure. The last entry repeats. */
final class ScriptedGeneration implements InvoiceGeneration
{
    /** @var list<\Throwable|bool> */
    public array $script = [true];

    /** @var (\Closure(int): void)|null */
    public ?\Closure $during = null;

    /** @var list<array{orderId: string, at: int, laterAttempt: bool}> */
    public array $calls = [];

    public int $successes = 0;

    public function __construct(private readonly TestClock $clock)
    {
    }

    public function generate(string $orderId, string $salesChannelId, bool $laterAttempt, Context $context): bool
    {
        $call = count($this->calls);
        $this->calls[] = ['orderId' => $orderId, 'at' => $this->clock->now()->getTimestamp(), 'laterAttempt' => $laterAttempt];
        if ($this->during !== null) {
            ($this->during)($call);
        }
        $outcome = $this->script[min($call, count($this->script) - 1)];
        if ($outcome instanceof \Throwable) {
            throw $outcome;
        }
        if ($outcome) {
            $this->successes++;
        }

        return $outcome;
    }

    /** @return list<int> */
    public function callOffsets(int $t0): array
    {
        return array_map(static fn (array $call): int => $call['at'] - $t0, $this->calls);
    }
}
