<?php declare(strict_types=1);

namespace Beliq\Shopware\Subscriber;

use Beliq\Shopware\Config\PluginConfigProvider;
use Beliq\Shopware\Document\BeliqInvoiceRenderer;
use Beliq\Shopware\Document\InvoiceDocumentLookup;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Document\Service\DocumentGenerator;
use Shopware\Core\Checkout\Document\Struct\DocumentGenerateOperation;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Generates a beliq invoice document when an order reaches the configured state.
 * The two subscribed events are the sensible triggers (payment paid, order
 * completed); the merchant picks which one fires and the handler skips the other.
 *
 * The handler delegates to DocumentGenerator, which runs BeliqInvoiceRenderer (the
 * order mapping and beliq API call) and stores the result as a first-class order
 * document. A generation failure must never break the transition that triggered
 * it, so the handler swallows and logs every error rather than letting it
 * propagate.
 */
final class OrderStateSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly PluginConfigProvider $configProvider,
        private readonly DocumentGenerator $documentGenerator,
        private readonly InvoiceDocumentLookup $documents,
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

            $operation = new DocumentGenerateOperation($orderId, $config->expectedFileType());

            $result = $this->documentGenerator->generate(
                BeliqInvoiceRenderer::TYPE,
                [$orderId => $operation],
                $event->getContext(),
            );

            foreach ($result->getErrors() as $error) {
                throw $error;
            }
        } catch (\Throwable $e) {
            $this->logger->error('beliq invoice generation failed', [
                'orderId' => $orderId,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
