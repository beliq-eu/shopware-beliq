<?php declare(strict_types=1);

namespace Beliq\Shopware\Document;

use Beliq\Shopware\Config\PluginConfigProvider;
use Beliq\Shopware\Service\BeliqClient;
use Beliq\Shopware\Service\CurlHttpClient;
use Beliq\Shopware\Service\HttpClient;
use Beliq\Shopware\Service\InvoiceMapper;
use Beliq\Shopware\Service\OrderAdapter;
use Shopware\Core\Checkout\Document\Renderer\AbstractDocumentRenderer;
use Shopware\Core\Checkout\Document\Renderer\DocumentRendererConfig;
use Shopware\Core\Checkout\Document\Renderer\RenderedDocument;
use Shopware\Core\Checkout\Document\Renderer\RendererResult;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;

/**
 * Produces a beliq invoice as a first-class Shopware order document. This renderer
 * is the single place that turns an order into a compliant document: it reloads
 * the order with the associations the adapter needs, applies the business-only
 * gate, maps the order onto a beliq generate body, and calls the beliq API. The
 * returned bytes (XRechnung / ZUGFeRD / Factur-X / Peppol BIS, XML or hybrid PDF)
 * become the document's media file.
 *
 * Generating through DocumentGenerator (rather than writing media directly) is
 * what makes the file persist when the trigger fires inside the paid-transition
 * request: DocumentGenerator saves the media in Context::SYSTEM_SCOPE, which the
 * media subsystem requires for a write that happens inside that request.
 */
final class BeliqInvoiceRenderer extends AbstractDocumentRenderer
{
    /** Technical name of the beliq document type (see the registering migration). */
    public const TYPE = 'beliq_invoice';

    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly PluginConfigProvider $configProvider,
        private readonly OrderAdapter $adapter,
        private readonly InvoiceMapper $mapper,
        private readonly InvoiceDocumentLookup $documents,
        private readonly HttpClient $http = new CurlHttpClient(),
    ) {
    }

    public function supports(): string
    {
        return self::TYPE;
    }

    public function render(array $operations, Context $context, DocumentRendererConfig $rendererConfig): RendererResult
    {
        $result = new RendererResult();

        foreach ($operations as $orderId => $operation) {
            try {
                $order = $this->loadOrder($operation->getOrderId(), $context);
                if (!$order instanceof OrderEntity) {
                    throw new \RuntimeException('Order not found for beliq document generation.');
                }

                $config = $this->configProvider->get($order->getSalesChannelId());
                $source = $this->adapter->toSourceOrder($order, $config);

                // Business-only gate: skipping is a deliberate no-op, not an error.
                if (!$config->allowsOrder($source)) {
                    continue;
                }

                $body = $this->mapper->toGenerateBody(
                    $source,
                    $config->standard,
                    $config->output,
                    $config->effectiveProfile(),
                );

                $client = new BeliqClient($config->apiKey, $config->baseUrl, $this->http);
                $generated = $client->generate($body);

                // The first beliq document for an order takes the order number; a
                // regenerate from the order's Documents card gets a distinct suffix,
                // because Shopware rejects a duplicate document number.
                $existing = $this->documents->countForOrder($operation->getOrderId(), $context);
                $documentNumber = $existing === 0 ? $source->number : $source->number . '-' . ($existing + 1);

                $isPdf = str_contains($generated['contentType'], 'pdf');
                $result->addSuccess($orderId, new RenderedDocument(
                    $documentNumber,
                    'invoice-' . $documentNumber,
                    $isPdf ? 'pdf' : 'xml',
                    ['documentNumber' => $documentNumber],
                    $isPdf ? 'application/pdf' : 'application/xml',
                    $generated['bytes'],
                ));
            } catch (\Throwable $exception) {
                $result->addError($orderId, $exception);
            }
        }

        return $result;
    }

    public function getDecorated(): AbstractDocumentRenderer
    {
        throw new DecorationPatternException(self::class);
    }

    /**
     * The order carried on the state-change event has only a shallow association
     * set. The adapter reads the billing address (with its country), the line
     * items, the customer account type, and the currency, so the order is reloaded
     * with those associations before mapping.
     */
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
