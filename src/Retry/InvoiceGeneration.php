<?php declare(strict_types=1);

namespace Beliq\Shopware\Retry;

use Shopware\Core\Framework\Context;

/** One attempt at an order's automatic beliq invoice. */
interface InvoiceGeneration
{
    /**
     * Throws the failure when the attempt failed. Returns false when the plugin
     * is off for the order's sales channel, so no attempt was made, and true
     * otherwise: the invoice is stored, the order already had one, or the
     * business-only setting skips the order.
     */
    public function generate(string $orderId, string $salesChannelId, bool $laterAttempt, Context $context): bool;
}
