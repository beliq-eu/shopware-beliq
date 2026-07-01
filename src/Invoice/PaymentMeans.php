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

    /**
     * The seller's bank details are configured once; the payment reference (BT-83)
     * is per-order. This returns a copy carrying that reference so the buyer can
     * reconcile the payment.
     */
    public function withReference(string $paymentReference): self
    {
        return new self($this->typeCode, $this->iban, $this->bic, $this->bankName, $paymentReference);
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
