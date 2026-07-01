<?php declare(strict_types=1);

namespace Beliq\Shopware\Invoice;

/**
 * How the invoice is to be paid. typeCode follows UNTDID 4461 (for example 58
 * for SEPA credit transfer, 30 for a generic credit transfer).
 */
final readonly class PaymentMeans
{
    public function __construct(
        public string $typeCode,
        public ?string $iban = null,
        public ?string $bic = null,
        public ?string $bankName = null,
        public ?string $paymentReference = null,
    ) {
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return array_filter([
            'typeCode' => $this->typeCode,
            'iban' => $this->iban,
            'bic' => $this->bic,
            'bankName' => $this->bankName,
            'paymentReference' => $this->paymentReference,
        ], static fn ($v) => $v !== null && $v !== '');
    }
}
