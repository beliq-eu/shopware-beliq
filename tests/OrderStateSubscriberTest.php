<?php declare(strict_types=1);

namespace Beliq\Shopware\Tests;

use Beliq\Shopware\Config\PluginConfigProvider;
use Beliq\Shopware\Service\DocumentStore;
use Beliq\Shopware\Service\InvoiceMapper;
use Beliq\Shopware\Service\OrderAdapter;
use Beliq\Shopware\Subscriber\OrderStateSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * The order carried on the state-change event has only a shallow association
 * set. The adapter reads the billing address, the line items, the customer
 * account type, and the currency, so the subscriber must reload the order with
 * those associations before mapping. Without the reload the buyer address is
 * empty and the generate call is rejected. This guards that regression by
 * asserting the reload criteria; the handler swallows the downstream error the
 * shallow test order provokes, which is the intended never-break-checkout
 * behaviour.
 */
final class OrderStateSubscriberTest extends TestCase
{
    private const TRIGGER = 'state_enter.order_transaction.state.paid';

    public function testReloadsOrderWithAssociationsTheAdapterNeeds(): void
    {
        $context = Context::createDefaultContext();

        $shallow = new OrderEntity();
        $shallow->setId(Uuid::randomHex());
        $shallow->setUniqueIdentifier(Uuid::randomHex());
        $shallow->setSalesChannelId(Uuid::randomHex());

        $capturedCriteria = null;
        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository->method('search')->willReturnCallback(
            function (Criteria $criteria) use (&$capturedCriteria, $context): EntitySearchResult {
                $capturedCriteria = $criteria;

                return new EntitySearchResult('order', 0, new EntityCollection([]), null, $criteria, $context);
            },
        );

        $subscriber = new OrderStateSubscriber(
            $this->configProvider(),
            new OrderAdapter(),
            new InvoiceMapper(),
            new DocumentStore($this->createMock(MediaService::class), $orderRepository),
            $orderRepository,
            new NullLogger(),
        );

        $subscriber->onOrderState(new OrderStateMachineStateChangeEvent(self::TRIGGER, $shallow, $context));

        self::assertInstanceOf(Criteria::class, $capturedCriteria, 'the subscriber must reload the order');
        self::assertTrue($capturedCriteria->hasAssociation('billingAddress'), 'billing address must be loaded');
        self::assertTrue($capturedCriteria->hasAssociation('lineItems'));
        self::assertTrue($capturedCriteria->hasAssociation('orderCustomer'));
        self::assertTrue($capturedCriteria->hasAssociation('currency'));
    }

    private function configProvider(): PluginConfigProvider
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnCallback(
            static fn (string $key): mixed => match ($key) {
                PluginConfigProvider::DOMAIN . 'enabled' => true,
                PluginConfigProvider::DOMAIN . 'apiKey' => 'blq_test',
                PluginConfigProvider::DOMAIN . 'businessOnly' => true,
                PluginConfigProvider::DOMAIN . 'triggerEvent' => self::TRIGGER,
                default => null,
            },
        );

        return new PluginConfigProvider($systemConfig);
    }
}
