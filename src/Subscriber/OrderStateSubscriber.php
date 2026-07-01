<?php declare(strict_types=1);

namespace Beliq\Shopware\Subscriber;

use Beliq\Shopware\Config\PluginConfigProvider;
use Beliq\Shopware\Service\BeliqClient;
use Beliq\Shopware\Service\DocumentStore;
use Beliq\Shopware\Service\InvoiceMapper;
use Beliq\Shopware\Service\OrderAdapter;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Generates a beliq invoice when an order reaches the configured state. The two
 * subscribed events are the sensible triggers (payment paid, order completed);
 * the merchant picks which one fires and the handler skips the other.
 *
 * A generation failure must never break the transition that triggered it, so the
 * handler swallows and logs every error rather than letting it propagate.
 */
final class OrderStateSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly PluginConfigProvider $configProvider,
        private readonly OrderAdapter $adapter,
        private readonly InvoiceMapper $mapper,
        private readonly DocumentStore $documentStore,
        private readonly EntityRepository $orderRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'state_enter.order_transaction.state.paid' => 'onOrderState',
            'state_enter.order.state.completed' => 'onOrderState',
        ];
    }

    public function onOrderState(OrderStateMachineStateChangeEvent $event): void
    {
        $order = $event->getOrder();

        try {
            $config = $this->configProvider->get($event->getSalesChannelId());
            if (!$config->enabled || $config->apiKey === '') {
                return;
            }
            if ($event->getName() !== $config->triggerEvent) {
                return;
            }

            // The order on the event carries only a shallow association set. The
            // adapter reads the billing address (with its country), the line
            // items, the customer account type, and the currency, so reload the
            // order with those associations before mapping.
            $order = $this->loadOrder($order->getId(), $event->getContext()) ?? $order;

            $source = $this->adapter->toSourceOrder($order, $config);
            if (!$config->allowsOrder($source)) {
                return;
            }

            $body = $this->mapper->toGenerateBody(
                $source,
                $config->standard,
                $config->output,
                $config->profile,
            );

            $client = new BeliqClient($config->apiKey, $config->baseUrl);
            $result = $client->generate($body);

            $this->documentStore->store(
                $order->getId(),
                $source->number,
                $result['bytes'],
                $result['contentType'],
                $event->getContext(),
            );
        } catch (\Throwable $e) {
            $this->logger->error('beliq invoice generation failed', [
                'orderId' => $order->getId(),
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function loadOrder(string $orderId, Context $context): ?OrderEntity
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('billingAddress.country');
        $criteria->addAssociation('orderCustomer.customer');
        $criteria->addAssociation('currency');

        $order = $this->orderRepository->search($criteria, $context)->first();

        return $order instanceof OrderEntity ? $order : null;
    }
}
