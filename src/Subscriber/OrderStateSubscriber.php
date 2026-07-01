<?php declare(strict_types=1);

namespace Beliq\Shopware\Subscriber;

use Beliq\Shopware\Config\PluginConfigProvider;
use Beliq\Shopware\Service\BeliqClient;
use Beliq\Shopware\Service\DocumentStore;
use Beliq\Shopware\Service\InvoiceMapper;
use Beliq\Shopware\Service\OrderAdapter;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
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
}
