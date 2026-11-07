<?php declare(strict_types=1);

namespace Beliq\Shopware\Retry;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Works through the automatic invoices that wait for a later attempt. It runs
 * only when the shop runs its scheduled tasks and a message consumer: with
 * neither, Shopware's admin worker runs it only while someone is logged into
 * the Administration.
 */
final class InvoiceRetryTask extends ScheduledTask
{
    /** Every minute, the resolution of the attempt slots. */
    private const INTERVAL_SECONDS = 60;

    public static function getTaskName(): string
    {
        return 'beliq.invoice_retry';
    }

    public static function getDefaultInterval(): int
    {
        return self::INTERVAL_SECONDS;
    }
}
