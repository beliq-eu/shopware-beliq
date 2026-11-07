<?php declare(strict_types=1);

namespace Beliq\Shopware\Retry;

use Shopware\Core\Framework\Context;

/** One attempt at an order's automatic beliq invoice. */
interface InvoiceGeneration
{
    /**
     * Throws the failure when the attempt failed. Returns true when the order
     * has a beliq invoice after the attempt (stored now, or before it), and false
     * when it has none and nothing failed: the plugin is off for the order's
     * sales channel, or the business-only setting skips the order.
     */
    public function generate(string $orderId, string $salesChannelId, bool $laterAttempt, Context $context): bool;
}
