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
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;

/**
 * Produces a beliq invoice as a first-class Shopware order document. This renderer
 * is the single place that turns an order into an e-invoice document: it reloads
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

    public const PREVIEW_NAME = 'beliq-invoice-preview.txt';

    public const PREVIEW_NOTICE = "beliq invoice: no preview\n\n"
        . "The plugin does not generate a preview: each document beliq generates counts\n"
        . "toward the documents per month of your beliq plan. Create the document to see it.\n\n"
        . "beliq-Rechnung: keine Vorschau\n\n"
        . "Das Plugin erstellt keine Vorschau: Jedes von beliq erstellte Dokument wird auf\n"
        . "die monatliche Dokumentenanzahl Ihres beliq-Tarifs angerechnet. Erstellen Sie das\n"
        . "Dokument, um es anzuzeigen.\n";

    /**
     * @param EntityRepository<OrderCollection> $orderRepository
     */
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
            // A document id means Shopware is rendering a stored document again:
            // DocumentGenerator::readDocument does that when a reader asks for a
            // file type the document has no media for, and its default is pdf. The
            // stored document is what beliq returned for the order, so rendering it
            // again would bill a second generate and rewrite its number to the
            // regenerate form. Returning nothing gives that reader a 204. It also
            // means a stored document whose file was lost is never rebuilt under
            // its number: the buyer may hold the original, so a new document,
            // created by hand, takes the next number instead.
            if ($operation->getDocumentId() !== null) {
                continue;
            }

            try {
                $order = $this->loadOrder($operation->getOrderId(), $context);
                if (!$order instanceof OrderEntity) {
                    throw new \RuntimeException('Order not found for beliq document generation.');
                }

                $config = $this->configProvider->get($order->getSalesChannelId());

                // Business-only gate: skipping is a deliberate no-op, not an error.
                // It runs before the conversion, which stops on missing data.
                if (!$config->allowsOrder($this->adapter->toGateOrder($order, $config))) {
                    continue;
                }

                $source = $this->adapter->toSourceOrder($order, $config);

                $body = $this->mapper->toGenerateBody(
                    $source,
                    $config->standard,
                    $config->effectiveOutput(),
                    $config->effectiveProfile(),
                );

                // The admin's create-document dialog asks for a preview before the
                // merchant creates the document. A beliq generate counts toward the
                // plan's documents per month, so the preview stops here, after the
                // same local checks as a create, and gets a notice. Returning
                // nothing would show the merchant an error.
                if ($operation->isPreview()) {
                    $result->addSuccess($orderId, new RenderedDocument(
                        '',
                        self::PREVIEW_NAME,
                        'txt',
                        [],
                        'text/plain; charset=utf-8',
                        self::PREVIEW_NOTICE,
                    ));

                    continue;
                }

                $documentNumber = $this->documents->nextNumber($operation->getOrderId(), $source->number, $context);

                $client = new BeliqClient($config->apiKey, $config->baseUrl, $this->http);
                $generated = $client->generate($body);

                $isPdf = str_contains($generated['contentType'], 'pdf');
                // The 6.7 signature. 6.6 has a leading `string $html` parameter, so
                // these positional arguments are a TypeError there; composer.json
                // pins ~6.7.0 for that reason.
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
     * items, the customer account type, the currency and the deliveries' shipping
     * method, so the order is reloaded with those associations before mapping.
     *
     * The reload puts the order's language first in the Context's language chain,
     * as Shopware's own InvoiceRenderer does, so the shipping method's name comes
     * out in the language the order was placed in. The caller's Context is left
     * as it is.
     */
    private function loadOrder(string $orderId, Context $context): ?OrderEntity
    {
        $order = $this->orderRepository->search(new Criteria([$orderId]), $context)->getEntities()->first();
        if (!$order instanceof OrderEntity) {
            return null;
        }

        $orderContext = clone $context;
        $orderContext->assign([
            'languageIdChain' => array_values(array_unique(array_filter([
                $order->getLanguageId(),
                ...$context->getLanguageIdChain(),
            ]))),
        ]);

        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('billingAddress.country');
        $criteria->addAssociation('orderCustomer.customer');
        $criteria->addAssociation('currency');
        $criteria->addAssociation('deliveries.shippingMethod');
        $criteria->addAssociation('deliveries.shippingOrderAddress.country');

        return $this->orderRepository->search($criteria, $orderContext)->getEntities()->first();
    }
}
