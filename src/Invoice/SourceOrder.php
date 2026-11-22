<?php declare(strict_types=1);

namespace Beliq\Shopware\Invoice;

/**
 * A store order normalized to what EN 16931 needs, independent of any platform.
 * The Shopware adapter fills this from an OrderEntity; the mapper turns it into
 * a beliq generate body. dueDate, issueDate are ISO dates (YYYY-MM-DD).
 *
 * zeroRateCategory is the VAT category used for lines taxed at 0%. It defaults to
 * Z (zero-rated). The merchant owns this choice. Reverse charge is not
 * auto-detected: a wrong guess in a compliance tool is worse than none.
 *
 * exemptionReasons holds the merchant's VAT exemption reason per VAT category
 * code.
 *
 * deliveryDate is the actual delivery date (BT-72) and deliverToCountryCode the
 * deliver-to country (BT-80), both null when the order does not send them.
 */
final readonly class SourceOrder
{
    /**
     * @param list<SourceLine> $lines
     * @param array<string, ExemptionReason> $exemptionReasons
     */
    public function __construct(
        public string $number,
        public string $issueDate,
        public string $currencyCode,
        public Party $seller,
        public Party $buyer,
        public array $lines,
        public ?string $dueDate = null,
        public ?PaymentMeans $paymentMeans = null,
        public ?string $paymentTerms = null,
        public ?string $buyerReference = null,
        public ?string $orderReference = null,
        public ?string $note = null,
        public bool $buyerFlaggedBusiness = false,
        public string $zeroRateCategory = 'Z',
        public array $exemptionReasons = [],
        public ?string $deliveryDate = null,
        public ?string $deliverToCountryCode = null,
    ) {
    }

    /**
     * A buyer counts as a business when it carries a VAT ID or the order was
     * flagged business at checkout. Drives whether the plugin generates at all.
     */
    public function buyerIsBusiness(): bool
    {
        return $this->buyerFlaggedBusiness
            || ($this->buyer->vatId !== null && $this->buyer->vatId !== '');
    }
}
