<?php declare(strict_types=1);

namespace Beliq\Shopware\Tests;

use Beliq\Shopware\Config\PluginConfigProvider;
use Beliq\Shopware\Document\BeliqInvoiceRenderer;
use Beliq\Shopware\Service\InvoiceMapper;
use Beliq\Shopware\Service\OrderAdapter;
use Beliq\Shopware\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Document\Renderer\DocumentRendererConfig;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * The renderer is the single place that turns an order into a beliq document: it
 * reloads the order with the associations the adapter needs, applies the
 * business-only gate, maps the order onto a beliq generate body, calls the beliq
 * API, and returns the bytes as a RenderedDocument that DocumentGenerator persists
 * as media. These tests exercise that behaviour against a fake HTTP client, so no
 * network is touched and the assertions are on observable output, not mocks.
 */
final class BeliqInvoiceRendererTest extends TestCase
{
    public function testBusinessOrderProducesAnXmlDocumentFromTheApiBytes(): void
    {
        $bytes = '<?xml version="1.0"?><Invoice>beliq</Invoice>';
        $http = new FakeHttpClient(['status' => 200, 'body' => $bytes, 'headers' => ['content-type' => 'application/xml']]);
        $order = $this->businessOrder('SW10042');

        $result = $this->renderer($order, $this->config('zugferd', 'xml'), $http)
            ->render([$order->getId() => $this->operation($order->getId(), 'xml')], Context::createDefaultContext(), new DocumentRendererConfig());

        $doc = $result->getOrderSuccess($order->getId());
        self::assertNotNull($doc, 'a business order must yield a document');
        self::assertNull($result->getOrderError($order->getId()));
        self::assertSame($bytes, $doc->getContent());
        self::assertSame('xml', $doc->getFileExtension());
        self::assertSame('application/xml', $doc->getContentType());
        self::assertSame('SW10042', $doc->getNumber());
        self::assertSame('invoice-SW10042', $doc->getName());
        self::assertSame('SW10042', $doc->getConfig()['documentNumber'] ?? null);

        // The mapped generate request reached the API.
        $call = $http->lastCall();
        self::assertSame('POST', $call['method']);
        self::assertStringEndsWith('/v1/generate', $call['url']);
        self::assertIsString($call['body']);
        $sent = json_decode($call['body'], true);
        self::assertSame('zugferd', $sent['standard']);
        self::assertSame('SW10042', $sent['invoice']['number']);
    }

    public function testPdfContentTypeYieldsAPdfDocument(): void
    {
        $http = new FakeHttpClient(['status' => 200, 'body' => '%PDF-1.3 fake', 'headers' => ['content-type' => 'application/pdf']]);
        $order = $this->businessOrder('SW10043');

        $result = $this->renderer($order, $this->config('zugferd', 'pdf'), $http)
            ->render([$order->getId() => $this->operation($order->getId(), 'pdf')], Context::createDefaultContext(), new DocumentRendererConfig());

        $doc = $result->getOrderSuccess($order->getId());
        self::assertNotNull($doc);
        self::assertSame('pdf', $doc->getFileExtension());
        self::assertSame('application/pdf', $doc->getContentType());
    }

    public function testXrechnungOmitsTheProfileInTheGenerateBody(): void
    {
        $http = new FakeHttpClient(['status' => 200, 'body' => '<x/>', 'headers' => ['content-type' => 'application/xml']]);
        $order = $this->businessOrder('SW10044');

        $this->renderer($order, $this->config('xrechnung', 'xml'), $http)
            ->render([$order->getId() => $this->operation($order->getId(), 'xml')], Context::createDefaultContext(), new DocumentRendererConfig());

        $sent = json_decode($http->lastCall()['body'], true);
        self::assertSame('xrechnung', $sent['standard']);
        self::assertArrayNotHasKey('profile', $sent, 'profile must be omitted for xrechnung');
    }

    public function testPrivateOrderUnderBusinessOnlyIsSkippedWithoutCallingTheApi(): void
    {
        $http = new FakeHttpClient(['status' => 200, 'body' => 'unused', 'headers' => []]);
        $order = $this->privateOrder('SW10045');

        $result = $this->renderer($order, $this->config('zugferd', 'xml', businessOnly: true), $http)
            ->render([$order->getId() => $this->operation($order->getId(), 'xml')], Context::createDefaultContext(), new DocumentRendererConfig());

        self::assertNull($result->getOrderSuccess($order->getId()), 'a private order must be skipped under business-only');
        self::assertNull($result->getOrderError($order->getId()), 'skipping is a no-op, not an error');
        self::assertSame([], $http->calls, 'no beliq call may be made for a skipped order');
    }

    public function testApiFailureBecomesARenderError(): void
    {
        $http = new FakeHttpClient(['status' => 422, 'body' => '{"error":{"code":"VALIDATION","message":"bad"}}', 'headers' => ['content-type' => 'application/json']]);
        $order = $this->businessOrder('SW10046');

        $result = $this->renderer($order, $this->config('zugferd', 'xml'), $http)
            ->render([$order->getId() => $this->operation($order->getId(), 'xml')], Context::createDefaultContext(), new DocumentRendererConfig());

        self::assertNull($result->getOrderSuccess($order->getId()));
        self::assertNotNull($result->getOrderError($order->getId()));
    }

    public function testSupportsIsTheBeliqDocumentType(): void
    {
        $http = new FakeHttpClient(['status' => 200, 'body' => '', 'headers' => []]);
        $order = $this->businessOrder('SW1');

        self::assertSame(BeliqInvoiceRenderer::TYPE, $this->renderer($order, $this->config('zugferd', 'xml'), $http)->supports());
    }

    private function renderer(OrderEntity $order, PluginConfigProvider $configProvider, FakeHttpClient $http): BeliqInvoiceRenderer
    {
        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository->method('search')->willReturnCallback(
            fn (Criteria $criteria, Context $context): EntitySearchResult => new EntitySearchResult(
                'order',
                1,
                new OrderCollection([$order]),
                null,
                $criteria,
                $context,
            ),
        );

        return new BeliqInvoiceRenderer(
            $orderRepository,
            $configProvider,
            new OrderAdapter(),
            new InvoiceMapper(),
            $http,
        );
    }

    private function operation(string $orderId, string $fileType): \Shopware\Core\Checkout\Document\Struct\DocumentGenerateOperation
    {
        return new \Shopware\Core\Checkout\Document\Struct\DocumentGenerateOperation($orderId, $fileType);
    }

    /**
     * A real PluginConfigProvider over a mocked SystemConfigService, so the config
     * resolution the renderer depends on is exercised for real rather than doubled.
     */
    private function config(string $standard, string $output, bool $businessOnly = true): PluginConfigProvider
    {
        $values = [
            'enabled' => true,
            'apiKey' => 'blq_test',
            'baseUrl' => 'https://api.beliq.eu',
            'standard' => $standard,
            'profile' => 'en16931',
            'output' => $output,
            'businessOnly' => $businessOnly,
            'triggerEvent' => 'state_enter.order_transaction.state.paid',
            'zeroRateCategory' => 'Z',
            'sellerName' => 'Seller GmbH',
            'sellerVatId' => 'DE999999999',
            'sellerCity' => 'Berlin',
            'sellerPostalCode' => '10115',
            'sellerCountryCode' => 'DE',
        ];

        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnCallback(
            static fn (string $key): mixed => $values[substr($key, strlen(PluginConfigProvider::DOMAIN))] ?? null,
        );

        return new PluginConfigProvider($systemConfig);
    }

    private function businessOrder(string $number): OrderEntity
    {
        return $this->order(
            $number,
            $this->customer('buyer@acme.test', 'Ada', 'Byte', 'ACME GmbH', ['DE123456789'], CustomerEntity::ACCOUNT_TYPE_BUSINESS),
            $this->address('Hauptstrasse 1', '10115', 'Berlin', 'DE', 'ACME GmbH'),
        );
    }

    private function privateOrder(string $number): OrderEntity
    {
        return $this->order(
            $number,
            $this->customer('jane@home.test', 'Jane', 'Roe', null, [], CustomerEntity::ACCOUNT_TYPE_PRIVATE),
            $this->address('Wohnweg 4', '20095', 'Hamburg', 'DE', null),
        );
    }

    private function order(string $number, OrderCustomerEntity $customer, OrderAddressEntity $billing): OrderEntity
    {
        $taxes = new CalculatedTaxCollection([new CalculatedTax(38.0, 19.0, 238.0)]);
        $item = new OrderLineItemEntity();
        $item->setUniqueIdentifier(Uuid::randomHex());
        $item->setId(Uuid::randomHex());
        $item->setLabel('Widget');
        $item->setQuantity(2);
        $item->setType(LineItem::PRODUCT_LINE_ITEM_TYPE);
        $item->setUnitPrice(119.0);
        $item->setTotalPrice(238.0);
        $item->setPayload(['productNumber' => 'SW-1']);
        $item->setPrice(new CalculatedPrice(119.0, 238.0, $taxes, new TaxRuleCollection(), 2));

        $currency = new CurrencyEntity();
        $currency->setUniqueIdentifier(Uuid::randomHex());
        $currency->setId(Uuid::randomHex());
        $currency->setIsoCode('EUR');

        $order = new OrderEntity();
        $order->setId(Uuid::randomHex());
        $order->setUniqueIdentifier(Uuid::randomHex());
        $order->setSalesChannelId(Uuid::randomHex());
        $order->setOrderNumber($number);
        $order->setOrderDateTime(new \DateTimeImmutable('2026-06-30T10:00:00+00:00'));
        $order->setTaxStatus(CartPrice::TAX_STATE_GROSS);
        $order->setCurrency($currency);
        $order->setLineItems(new OrderLineItemCollection([$item]));
        $order->setOrderCustomer($customer);
        $order->setBillingAddress($billing);

        return $order;
    }

    /**
     * @param list<string> $vatIds
     */
    private function customer(string $email, string $firstName, string $lastName, ?string $company, array $vatIds, string $accountType): OrderCustomerEntity
    {
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

    private function address(string $street, string $zipcode, string $city, string $countryIso, ?string $company): OrderAddressEntity
    {
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
}
