<?php declare(strict_types=1);

namespace Beliq\Shopware\Tests\Support;

use Psr\Clock\ClockInterface;

final class TestClock implements ClockInterface
{
    public function __construct(private int $now)
    {
    }

    public function set(int $now): void
    {
        $this->now = $now;
    }

    public function now(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->setTimestamp($this->now);
    }
}
