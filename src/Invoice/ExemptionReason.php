<?php declare(strict_types=1);

namespace Beliq\Shopware\Invoice;

/**
 * The VAT exemption reason of one VAT category: a code from the VATEX code list
 * (BT-121), a text (BT-120), or both. The merchant configures it; the plugin
 * passes it on as given.
 */
final readonly class ExemptionReason
{
    public function __construct(
        public ?string $code = null,
        public ?string $text = null,
    ) {
    }

    /**
     * @return array<string, string> the taxSummary fields of the generate body
     */
    public function toArray(): array
    {
        return array_filter([
            'exemptionReasonCode' => $this->code,
            'exemptionReasonText' => $this->text,
        ], static fn ($v) => $v !== null && $v !== '');
    }
}
