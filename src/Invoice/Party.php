<?php declare(strict_types=1);

namespace Beliq\Shopware\Invoice;

/** A seller or buyer. name and address are required; identifiers are optional. */
final readonly class Party
{
    public function __construct(
        public string $name,
        public Address $address,
        public ?string $vatId = null,
        public ?string $taxId = null,
        public ?string $registrationId = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $contactName = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'vatId' => $this->vatId,
            'taxId' => $this->taxId,
            'registrationId' => $this->registrationId,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address->toArray(),
            'contactName' => $this->contactName,
        ], static fn ($v) => $v !== null && $v !== '');
    }
}
