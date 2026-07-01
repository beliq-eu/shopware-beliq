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
    /**
     * Standards whose profile is fixed by the standard itself. Sending a profile
     * for these is a hard 422 from the API, so it must be omitted.
     */
    private const PROFILE_FIXED_STANDARDS = ['xrechnung', 'peppol-bis'];

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

    /**
     * The profile to send for the configured standard. XRechnung and Peppol BIS
     * pin their own profile, so it is omitted (null) for those; the profile option
     * only applies to the ZUGFeRD / Factur-X family.
     */
    public function effectiveProfile(): ?string
    {
        return in_array($this->standard, self::PROFILE_FIXED_STANDARDS, true)
            ? null
            : $this->profile;
    }

    /**
     * The file extension the generated document will carry. XRechnung and Peppol
     * BIS are always XML; the ZUGFeRD / Factur-X family follows the output setting
     * (hybrid PDF or XML).
     */
    public function expectedFileType(): string
    {
        if (in_array($this->standard, self::PROFILE_FIXED_STANDARDS, true)) {
            return 'xml';
        }

        return $this->output === 'xml' ? 'xml' : 'pdf';
    }
}
