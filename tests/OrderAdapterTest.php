<?php declare(strict_types=1);

namespace Beliq\Shopware\Tests;

use Beliq\Shopware\Config\PluginConfig;
use Beliq\Shopware\Invoice\Address;
use Beliq\Shopware\Invoice\Party;
use Beliq\Shopware\Service\OrderAdapter;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\Framework\Uuid\Uuid;

final class OrderAdapterTest extends TestCase
{
    private OrderAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new OrderAdapter();
    }

    public function testGrossBusinessOrderIsConvertedToNet(): void
    {
        $order = $this->order(
            taxStatus: CartPrice::TAX_STATE_GROSS,
            lines: [
                // 2 x 119 gross at 19% => net 200, unit net 100.
                $this->line('Widget', 2, 238.0, [[38.0, 19.0, 238.0]], 'SW-1'),
            ],
            customer: $this->customer('buyer@acme.test', 'Ada', 'Byte', 'ACME GmbH', ['DE123456789'], CustomerEntity::ACCOUNT_TYPE_BUSINESS),
            billing: $this->address('Hauptstrasse 1', '10115', 'Berlin', 'DE', 'ACME GmbH'),
        );

        $source = $this->adapter->toSourceOrder($order, $this->config());

        self::assertSame('SW10001', $source->number);
        self::assertSame('2026-06-30', $source->issueDate);
        self::assertSame('EUR', $source->currencyCode);
        self::assertSame('Z', $source->zeroRateCategory);

        self::assertSame('ACME GmbH', $source->buyer->name);
        self::assertSame('DE123456789', $source->buyer->vatId);
        self::assertSame('buyer@acme.test', $source->buyer->email);
        self::assertSame('Ada Byte', $source->buyer->contactName);
        self::assertSame('Berlin', $source->buyer->address->city);
        self::assertSame('10115', $source->buyer->address->postalCode);
        self::assertSame('DE', $source->buyer->address->countryCode);
        self::assertSame('Hauptstrasse 1', $source->buyer->address->street);
        self::assertTrue($source->buyerIsBusiness());

        self::assertCount(1, $source->lines);
        self::assertEqualsWithDelta(200.0, $source->lines[0]->lineNetTotal, 0.001);
        self::assertEqualsWithDelta(100.0, $source->lines[0]->unitNetPrice, 0.001);
        self::assertEqualsWithDelta(19.0, $source->lines[0]->vatRate, 0.001);
        self::assertSame('SW-1', $source->lines[0]->itemId);
        self::assertSame('Widget', $source->lines[0]->description);
    }

    public function testNetOrderPassesLineTotalsThrough(): void
    {
        $order = $this->order(
            taxStatus: CartPrice::TAX_STATE_NET,
            lines: [
                $this->line('Service', 1, 500.0, [[95.0, 19.0, 500.0]], 'SV-1'),
            ],
            customer: $this->customer('b@x.test', 'Max', 'Muster', 'X SARL', ['FR12345678901'], CustomerEntity::ACCOUNT_TYPE_BUSINESS),
            billing: $this->address('Rue 2', '75001', 'Paris', 'FR', 'X SARL'),
        );

        $source = $this->adapter->toSourceOrder($order, $this->config());

        self::assertEqualsWithDelta(500.0, $source->lines[0]->lineNetTotal, 0.001);
        self::assertEqualsWithDelta(500.0, $source->lines[0]->unitNetPrice, 0.001);
        self::assertEqualsWithDelta(19.0, $source->lines[0]->vatRate, 0.001);
    }

    public function testTaxFreeOrderYieldsZeroRate(): void
    {
        $order = $this->order(
            taxStatus: CartPrice::TAX_STATE_FREE,
            lines: [
                $this->line('Export good', 3, 300.0, [], 'EX-1'),
            ],
            customer: $this->customer('c@x.test', 'Jo', 'Doe', 'Overseas Inc', ['CHE123456789'], CustomerEntity::ACCOUNT_TYPE_BUSINESS),
            billing: $this->address('Bahnhofstr 3', '8001', 'Zurich', 'CH', 'Overseas Inc'),
        );

        $source = $this->adapter->toSourceOrder($order, $this->config());

        self::assertEqualsWithDelta(300.0, $source->lines[0]->lineNetTotal, 0.001);
        self::assertEqualsWithDelta(100.0, $source->lines[0]->unitNetPrice, 0.001);
        self::assertEqualsWithDelta(0.0, $source->lines[0]->vatRate, 0.001);
    }

    public function testPrivateConsumerIsNotFlaggedBusiness(): void
    {
        $order = $this->order(
            taxStatus: CartPrice::TAX_STATE_GROSS,
            lines: [
                $this->line('Book', 1, 21.4, [[1.4, 7.0, 21.4]], 'BK-1'),
            ],
            customer: $this->customer('jane@home.test', 'Jane', 'Roe', null, [], CustomerEntity::ACCOUNT_TYPE_PRIVATE),
            billing: $this->address('Wohnweg 4', '20095', 'Hamburg', 'DE', null),
        );

        $source = $this->adapter->toSourceOrder($order, $this->config());

        self::assertSame('Jane Roe', $source->buyer->name);
        self::assertNull($source->buyer->vatId);
        self::assertNull($source->buyer->contactName);
        self::assertFalse($source->buyerIsBusiness());
        self::assertEqualsWithDelta(20.0, $source->lines[0]->lineNetTotal, 0.001);
        self::assertEqualsWithDelta(7.0, $source->lines[0]->vatRate, 0.001);
    }

    public function testCompanyWithoutVatIdStillCountsAsBusiness(): void
    {
        $order = $this->order(
            taxStatus: CartPrice::TAX_STATE_GROSS,
            lines: [$this->line('Item', 1, 119.0, [[19.0, 19.0, 119.0]], 'I-1')],
            customer: $this->customer('k@co.test', 'Kim', 'Lee', 'Kim Trading', [], CustomerEntity::ACCOUNT_TYPE_PRIVATE),
            billing: $this->address('Str 5', '50667', 'Koeln', 'DE', 'Kim Trading'),
        );

        $source = $this->adapter->toSourceOrder($order, $this->config());

        self::assertSame('Kim Trading', $source->buyer->name);
        self::assertNull($source->buyer->vatId);
        self::assertTrue($source->buyerIsBusiness());
    }

    public function testContainerLineItemIsSkipped(): void
    {
        $order = $this->order(
            taxStatus: CartPrice::TAX_STATE_GROSS,
            lines: [
                $this->line('Bundle', 1, 119.0, [[19.0, 19.0, 119.0]], 'B-1', LineItem::CONTAINER_LINE_ITEM),
                $this->line('Part', 1, 119.0, [[19.0, 19.0, 119.0]], 'P-1'),
            ],
            customer: $this->customer('d@x.test', 'D', 'E', 'Co', ['DE9'], CustomerEntity::ACCOUNT_TYPE_BUSINESS),
            billing: $this->address('S 6', '1', 'C', 'DE', 'Co'),
        );

        $source = $this->adapter->toSourceOrder($order, $this->config());

        self::assertCount(1, $source->lines);
        self::assertSame('P-1', $source->lines[0]->itemId);
    }

    public function testMixedRateLinesKeepTheirOwnRates(): void
    {
        $order = $this->order(
            taxStatus: CartPrice::TAX_STATE_GROSS,
            lines: [
                $this->line('Standard', 1, 119.0, [[19.0, 19.0, 119.0]], 'S-1'),
                $this->line('Reduced', 1, 107.0, [[7.0, 7.0, 107.0]], 'R-1'),
            ],
            customer: $this->customer('m@x.test', 'M', 'X', 'MX', ['DE1'], CustomerEntity::ACCOUNT_TYPE_BUSINESS),
            billing: $this->address('S 7', '1', 'C', 'DE', 'MX'),
        );

        $source = $this->adapter->toSourceOrder($order, $this->config());

        self::assertCount(2, $source->lines);
        self::assertEqualsWithDelta(100.0, $source->lines[0]->lineNetTotal, 0.001);
        self::assertEqualsWithDelta(19.0, $source->lines[0]->vatRate, 0.001);
        self::assertEqualsWithDelta(100.0, $source->lines[1]->lineNetTotal, 0.001);
        self::assertEqualsWithDelta(7.0, $source->lines[1]->vatRate, 0.001);
    }

    public function testLineWithoutCalculatedPriceFallsBackToEntityTotal(): void
    {
        $item = new OrderLineItemEntity();
        $item->setUniqueIdentifier(Uuid::randomHex());
        $item->setId(Uuid::randomHex());
        $item->setLabel('Manual');
        $item->setQuantity(4);
        $item->setType(LineItem::CUSTOM_LINE_ITEM_TYPE);
        $item->setTotalPrice(40.0);

        $order = $this->order(
            taxStatus: CartPrice::TAX_STATE_NET,
            lineItems: new OrderLineItemCollection([$item]),
            customer: $this->customer('f@x.test', 'F', 'G', 'FG', ['DE2'], CustomerEntity::ACCOUNT_TYPE_BUSINESS),
            billing: $this->address('S 8', '1', 'C', 'DE', 'FG'),
        );

        $source = $this->adapter->toSourceOrder($order, $this->config());

        self::assertEqualsWithDelta(40.0, $source->lines[0]->lineNetTotal, 0.001);
        self::assertEqualsWithDelta(10.0, $source->lines[0]->unitNetPrice, 0.001);
        self::assertEqualsWithDelta(0.0, $source->lines[0]->vatRate, 0.001);
    }

    private function config(): PluginConfig
    {
        return new PluginConfig(
            enabled: true,
            apiKey: 'test-key',
            baseUrl: 'https://api.beliq.eu',
            standard: 'zugferd',
            profile: 'en16931',
            output: 'pdf',
            businessOnly: true,
            triggerEvent: 'state_enter.order_transaction.state.paid',
            zeroRateCategory: 'Z',
            seller: new Party('Seller GmbH', new Address('Berlin', '10115', 'DE'), vatId: 'DE999999999'),
        );
    }

    /**
     * @param array<int, array{0: float, 1: float, 2: float}> $taxes tuples of (tax, rate, price)
     */
    private function line(
        string $label,
        int $quantity,
        float $totalPrice,
        array $taxes,
        string $productNumber,
        string $type = LineItem::PRODUCT_LINE_ITEM_TYPE,
    ): OrderLineItemEntity {
        $collection = new CalculatedTaxCollection();
        foreach ($taxes as $tax) {
            $collection->add(new CalculatedTax($tax[0], $tax[1], $tax[2]));
        }

        $item = new OrderLineItemEntity();
        $item->setUniqueIdentifier(Uuid::randomHex());
        $item->setId(Uuid::randomHex());
        $item->setLabel($label);
        $item->setQuantity($quantity);
        $item->setType($type);
        $item->setUnitPrice($quantity > 0 ? $totalPrice / $quantity : $totalPrice);
        $item->setTotalPrice($totalPrice);
        $item->setPayload(['productNumber' => $productNumber]);
        $item->setPrice(new CalculatedPrice(
            $quantity > 0 ? $totalPrice / $quantity : $totalPrice,
            $totalPrice,
            $collection,
            new TaxRuleCollection(),
            $quantity,
        ));

        return $item;
    }

    /**
     * @param list<string> $vatIds
     */
    private function customer(
        string $email,
        string $firstName,
        string $lastName,
        ?string $company,
        array $vatIds,
        string $accountType,
    ): OrderCustomerEntity {
        $customer = new CustomerEntity();
        $customer->setUniqueIdentifier(Uuid::randomHex());
        $customer->setId(Uuid::randomHex());
        $customer->setAccountType($accountType);

        $orderCustomer = new OrderCustomerEntity();
        $orderCustomer->setUniqueIdentifier(Uuid::randomHex());
        $orderCustomer->setId(Uuid::randomHex());
        $orderCustomer->setEmail($email);
        $orderCustomer->setFirstName($firstName);
        $orderCustomer->setLastName($lastName);
        $orderCustomer->setVatIds($vatIds);
        if ($company !== null) {
            $orderCustomer->setCompany($company);
        }
        $orderCustomer->setCustomer($customer);

        return $orderCustomer;
    }

    private function address(
        string $street,
        string $zipcode,
        string $city,
        string $countryIso,
        ?string $company,
    ): OrderAddressEntity {
        $country = new CountryEntity();
        $country->setUniqueIdentifier(Uuid::randomHex());
        $country->setId(Uuid::randomHex());
        $country->setIso($countryIso);

        $address = new OrderAddressEntity();
        $address->setUniqueIdentifier(Uuid::randomHex());
        $address->setId(Uuid::randomHex());
        $address->setStreet($street);
        $address->setZipcode($zipcode);
        $address->setCity($city);
        $address->setCountry($country);
        if ($company !== null) {
            $address->setCompany($company);
        }

        return $address;
    }

    /**
     * @param list<OrderLineItemEntity> $lines
     */
    private function order(
        string $taxStatus,
        OrderCustomerEntity $customer,
        OrderAddressEntity $billing,
        array $lines = [],
        ?OrderLineItemCollection $lineItems = null,
        string $currency = 'EUR',
    ): OrderEntity {
        $currencyEntity = new CurrencyEntity();
        $currencyEntity->setUniqueIdentifier(Uuid::randomHex());
        $currencyEntity->setId(Uuid::randomHex());
        $currencyEntity->setIsoCode($currency);

        $order = new OrderEntity();
        $order->setUniqueIdentifier(Uuid::randomHex());
        $order->setId(Uuid::randomHex());
        $order->setOrderNumber('SW10001');
        $order->setOrderDateTime(new \DateTimeImmutable('2026-06-30T10:00:00+00:00'));
        $order->setTaxStatus($taxStatus);
        $order->setCurrency($currencyEntity);
        $order->setLineItems($lineItems ?? new OrderLineItemCollection($lines));
        $order->setOrderCustomer($customer);
        $order->setBillingAddress($billing);

        return $order;
    }
}
