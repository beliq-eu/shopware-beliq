<?php declare(strict_types=1);

namespace Beliq\Shopware\Subscriber;

use Beliq\Shopware\Config\PluginConfigProvider;
use Beliq\Shopware\Document\InvoiceDocumentLookup;
use Beliq\Shopware\Retry\InvoiceAttempts;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Generates a beliq invoice when an order reaches the configured state.
 * The two subscribed events are the sensible triggers (payment paid, order
 * completed); the merchant picks which one fires and the handler skips the other.
 *
 * The first attempt runs here, inside the state change. When beliq cannot be
 * reached or is unavailable, InvoiceAttempts leaves a later attempt for the
 * scheduled task, and every failure shows on the order. A generation failure
 * must never break the transition that triggered it, so the handler swallows
 * and logs every error rather than letting it propagate.
 */
final class OrderStateSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly PluginConfigProvider $configProvider,
        private readonly InvoiceDocumentLookup $documents,
        private readonly InvoiceAttempts $attempts,
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
        $orderId = $event->getOrder()->getId();

        try {
            $config = $this->configProvider->get($event->getSalesChannelId());
            if (!$config->enabled || $config->apiKey === '') {
                return;
            }
            if ($event->getName() !== $config->triggerEvent) {
                return;
            }

            // Automatic generation never overwrites an existing beliq invoice, so a
            // re-fired transition is a no-op. An explicit regenerate from the order's
            // Documents card still works and gets its own document number.
            if ($this->documents->countForOrder($orderId, $event->getContext()) > 0) {
                return;
            }

            $this->attempts->first($orderId, $event->getSalesChannelId(), $event->getContext());
        } catch (\Throwable $e) {
            $this->logger->error('beliq invoice generation failed', [
                'orderId' => $orderId,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
