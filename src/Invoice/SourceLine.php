<?php declare(strict_types=1);

namespace Beliq\Shopware\Invoice;

/**
 * One order line, in net figures. vatRate is a percentage (19 means 19%).
 * unitCode follows UN/ECE Recommendation 20 (C62 = one/unit, the neutral default
 * for e-commerce items; HUR = hour). vatCategoryCode, when set, overrides the
 * category the mapper would derive from the rate.
 */
final readonly class SourceLine
{
    public function __construct(
        public string $description,
        public float $quantity,
        public float $unitNetPrice,
        public float $lineNetTotal,
        public float $vatRate,
        public string $unitCode = 'C62',
        public ?string $vatCategoryCode = null,
        public ?string $itemId = null,
    ) {
    }
}
