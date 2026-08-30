<?php declare(strict_types=1);

namespace Beliq\Shopware\Config;

use Beliq\Shopware\Invoice\Party;
use Beliq\Shopware\Invoice\PaymentMeans;
use Beliq\Shopware\Invoice\SourceOrder;

/**
 * The merchant's plugin settings, resolved for one sales channel. The seller is
 * pre-assembled into a Party from the seller-legal fields (name, address, VAT/tax
 * ids, and the BG-6 contact); the seller's bank details become the paymentMeans
 * template. profile is null when the merchant leaves it blank, letting the engine
 * pick its default.
 */
final readonly class PluginConfig
{
    /**
     * Standards whose profile is fixed by the standard itself. Sending a profile
     * for these is a hard 422 from the API, so it must be omitted.
     */
    private const PROFILE_FIXED_STANDARDS = ['xrechnung', 'peppol-bis'];

    /**
     * Standards with no hybrid PDF. The API refuses PDF output for these unless
     * the request names a visual to render, and that visual carries no embedded
     * XML: it is a rendering of the invoice, not the invoice. The document this
     * plugin stores against an order has to be the legal one, so PDF output is
     * resolved back to XML for them rather than requesting a picture of it.
     *
     * Same two members as PROFILE_FIXED_STANDARDS today, for a different reason:
     * one is about which profile the standard pins, this one is about whether a
     * hybrid PDF exists at all.
     */
    private const XML_ONLY_STANDARDS = ['xrechnung', 'peppol-bis'];

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
        public ?PaymentMeans $paymentMeans = null,
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
     * The `output` to send for the configured standard. XRechnung and Peppol BIS
     * have no hybrid PDF, so the Output setting ("PDF (hybrid, where the format
     * supports it)") resolves to XML for them; the ZUGFeRD / Factur-X family
     * follows the setting as chosen.
     */
    public function effectiveOutput(): string
    {
        if (in_array($this->standard, self::XML_ONLY_STANDARDS, true)) {
            return 'xml';
        }

        return $this->output === 'xml' ? 'xml' : 'pdf';
    }

    /**
     * The file extension the generated document will carry. Follows the output
     * actually sent, so the stored file and the request can never disagree.
     */
    public function expectedFileType(): string
    {
        return $this->effectiveOutput();
    }
}
