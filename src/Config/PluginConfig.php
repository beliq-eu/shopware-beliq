<?php declare(strict_types=1);

namespace Beliq\Shopware\Config;

use Beliq\Shopware\Invoice\Party;
use Beliq\Shopware\Invoice\SourceOrder;

/**
 * The merchant's plugin settings, resolved for one sales channel. The seller is
 * pre-assembled into a Party from the seller-legal fields. profile is null when
 * the merchant leaves it blank, letting the engine pick its default.
 */
final readonly class PluginConfig
{
    public function __construct(
        public bool $enabled,
        public string $apiKey,
        public string $baseUrl,
        public string $standard,
        public ?string $profile,
        public string $output,
        public bool $businessOnly,
        public string $triggerEvent,
        public string $zeroRateCategory,
        public Party $seller,
    ) {
    }

    /**
     * Whether the plugin should generate for this order. With the business-only
     * scope (the default) a private-consumer order is skipped; see ROADMAP.md on
     * why the safe default is narrow.
     */
    public function allowsOrder(SourceOrder $order): bool
    {
        return !$this->businessOnly || $order->buyerIsBusiness();
    }
}
