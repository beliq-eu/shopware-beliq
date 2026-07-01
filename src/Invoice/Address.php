<?php declare(strict_types=1);

namespace Beliq\Shopware\Invoice;

/**
 * A postal address. city, postalCode, and countryCode are required by EN 16931
 * (BR-08 / BR-10); the rest are optional.
 */
final readonly class Address
{
    public function __construct(
        public string $city,
        public string $postalCode,
        public string $countryCode,
        public ?string $street = null,
        public ?string $additionalStreet = null,
        public ?string $state = null,
    ) {
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return array_filter([
            'street' => $this->street,
            'additionalStreet' => $this->additionalStreet,
            'city' => $this->city,
            'postalCode' => $this->postalCode,
            'countryCode' => $this->countryCode,
            'state' => $this->state,
        ], static fn ($v) => $v !== null && $v !== '');
    }
}
