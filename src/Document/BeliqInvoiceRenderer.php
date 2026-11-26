<?php declare(strict_types=1);

namespace Beliq\Shopware\Document;

use Beliq\Shopware\Config\PluginConfigProvider;
use Beliq\Shopware\Service\BeliqClient;
use Beliq\Shopware\Service\CurlHttpClient;
use Beliq\Shopware\Service\HttpClient;
use Beliq\Shopware\Service\InvoiceMapper;
use Beliq\Shopware\Service\OrderAdapter;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Document\Renderer\AbstractDocumentRenderer;
use Shopware\Core\Checkout\Document\Renderer\DocumentRendererConfig;
use Shopware\Core\Checkout\Document\Renderer\RenderedDocument;
use Shopware\Core\Checkout\Document\Renderer\RendererResult;
use Shopware\Core\Checkout\Document\Struct\DocumentGenerateOperation;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Symfony\Component\Lock\LockFactory;

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
    /**
     * Technical name of the beliq document type (see the registering migration).
     */
    public const TYPE = 'beliq_invoice';

    public const PREVIEW_NAME = 'beliq-invoice-preview.txt';

    /**
     * The key of the lock an order's number is picked under, followed by the order id.
     */
    public const NUMBER_LOCK = 'beliq-invoice-number-';

    /**
     * The key in a beliq document's config that holds its issue date (BT-2), so a
     * later document can name it as the preceding invoice's date (BT-26).
     */
    public const ISSUE_DATE_CONFIG = 'beliqIssueDate';

    public const PREVIEW_NOTICE = "beliq invoice: no preview\n\n"
        . "The plugin does not generate a preview: each document beliq generates counts\n"
        . "toward the documents per month of your beliq plan. Create the document to see it.\n\n"
        . "beliq-Rechnung: keine Vorschau\n\n"
        . "Das Plugin erstellt keine Vorschau: Jedes von beliq erstellte Dokument wird auf\n"
        . "die monatliche Dokumentenanzahl Ihres beliq-Tarifs angerechnet. Erstellen Sie das\n"
        . "Dokument, um es anzuzeigen.\n";

    /**
     * How long the number lock lives in a store that expires locks (Redis, a
     * database). It spans one generate call, and the longest one gives up after
     * RetrySchedule::LATER_ATTEMPT_TIMEOUT_SECONDS (90 s).
     */
    private const NUMBER_LOCK_TTL_SECONDS = 300.0;

    /**
     * @param EntityRepository<OrderCollection> $orderRepository
     */
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly PluginConfigProvider $configProvider,
        private readonly OrderAdapter $adapter,
        private readonly InvoiceMapper $mapper,
        private readonly InvoiceDocumentLookup $documents,
        private readonly DeliveryShippedDates $shippedDates,
        private readonly IssuedInvoiceNumbers $issuedNumbers,
        private readonly LockFactory $locks,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
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

                $source = $this->adapter->toSourceOrder(
                    $order,
                    $config,
                    fn (array $deliveryIds): array => $this->shippedDates->forDeliveries($deliveryIds, $context),
                );

                $body = $this->mapper->toGenerateBody(
                    $source,
                    $config->standard,
                    $config->effectiveOutput(),
                    $config->effectiveProfile(),
                );
                $issueDate = $this->issueDate($operation);

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

                // Two creates for one order at once would read the same numbers
                // and send the same invoice number; the second would then fail
                // after a billed generate. The lock is held until the number is
                // recorded, because the document itself is stored only after
                // this method returns.
                // No release on destruction: after a failed release below, the
                // destructor would try again and throw outside this method's catch.
                $lock = $this->locks->createLock(self::NUMBER_LOCK . $order->getId(), self::NUMBER_LOCK_TTL_SECONDS, false);
                if (!$lock->acquire(true)) {
                    throw new \RuntimeException('The beliq invoice number lock for the order was not acquired.');
                }
                try {
                    $issued = $this->issuedNumbers->inOrderNumberForm($source->number);
                    $documentNumber = $this->documents->nextNumber($source->number, $issued, $context);
                    $preceding = $this->documents->precedingInvoice(
                        $order->getId(),
                        $source->number,
                        $this->issuedNumbers->ofOrder($order->getId(), $source->number),
                        $context,
                    );

                    $client = new BeliqClient($config->apiKey, $config->baseUrl, $this->http);
                    $generated = $client->generate(self::withDocumentFields($body, $documentNumber, $issueDate, $preceding));

                    // The generate has counted toward the plan by now, so a failed
                    // record keeps the document. While the document exists, its
                    // number is not issued again.
                    try {
                        $this->issuedNumbers->record($order->getId(), $documentNumber);
                    } catch (\Throwable $e) {
                        $this->logger->error('beliq invoice number not recorded', [
                            'orderId' => $order->getId(),
                            'number' => $documentNumber,
                            'exception' => $e->getMessage(),
                        ]);
                    }
                } finally {
                    // A lock store that expires locks frees it after the TTL, so a
                    // failed release does not cost the merchant the document.
                    try {
                        $lock->release();
                    } catch (\Throwable $e) {
                        $this->logger->error('beliq invoice number lock not released', [
                            'orderId' => $order->getId(),
                            'exception' => $e->getMessage(),
                        ]);
                    }
                }

                $isPdf = str_contains($generated['contentType'], 'pdf');
                // The 6.7 signature. 6.6 has a leading `string $html` parameter, so
                // these positional arguments are a TypeError there; composer.json
                // pins ~6.7.0 for that reason.
                $result->addSuccess($orderId, new RenderedDocument(
                    $documentNumber,
                    'invoice-' . $documentNumber,
                    $isPdf ? 'pdf' : 'xml',
                    ['documentNumber' => $documentNumber, self::ISSUE_DATE_CONFIG => $issueDate],
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
     * The issue date (BT-2) is the day the document is made, as on Shopware's own
     * invoice: the date the create dialog sends, else the clock's now. The day is
     * taken in UTC, as Shopware stores its dates; the dialog's date picker
     * (mt-datepicker, whose timeZone defaults to UTC) shows and returns UTC days.
     */
    private function issueDate(DocumentGenerateOperation $operation): string
    {
        $date = $operation->getConfig()['documentDate'] ?? null;
        $at = $date === null ? $this->clock->now() : self::documentDate($date);

        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
    }

    /**
     * A documentDate in the shapes Shopware writes, which start with the day:
     * the date picker's ISO 8601 string and the storage format. A day that does
     * not exist (2026-11-31) and a relative phrase (`tomorrow`) are refused,
     * which \DateTimeImmutable alone would roll over or work out.
     */
    private static function documentDate(mixed $date): \DateTimeImmutable
    {
        if (is_string($date) && preg_match('/^(\d{4}-\d{2}-\d{2})(?:[T ][0-9:.]+(?:Z|[+-]\d{2}:?\d{2})?)?$/D', $date, $match) === 1) {
            try {
                $at = new \DateTimeImmutable($date);
            } catch (\Exception) {
                $at = null;
            }
            if ($at !== null && $at->format('Y-m-d') === $match[1]) {
                return $at;
            }
        }

        throw new \InvalidArgumentException('The documentDate of the beliq document is not a date: ' . json_encode($date));
    }

    /**
     * A document created again is a new invoice, not a copy: the order can change
     * between two creates. So the invoice number (BT-1) is the document's own
     * number, which is the order number for the first document and
     * `<order>-<n>` for a later one, and the issue date (BT-2) is the document's
     * own. A later document carries the whole order again, so it corrects the
     * earlier invoice, which is the first use EN 16931-1 names for BG-3, the
     * preceding invoice reference: its number (BT-25), and its issue date
     * (BT-26) when that is known. The order number stays the payment reference
     * and the fallback buyer reference.
     *
     * @param array<string, mixed> $body
     * @param array{number: string, issueDate: ?string}|null $preceding
     *
     * @return array<string, mixed>
     */
    private static function withDocumentFields(array $body, string $number, string $issueDate, ?array $preceding): array
    {
        if (!is_array($body['invoice'] ?? null)) {
            throw new \LogicException('The generate body has no invoice.');
        }
        $body['invoice']['number'] = $number;
        $body['invoice']['issueDate'] = $issueDate;
        if ($preceding !== null) {
            $reference = ['id' => $preceding['number']];
            if ($preceding['issueDate'] !== null) {
                $reference['issueDate'] = $preceding['issueDate'];
            }
            $body['invoice']['precedingInvoiceReference'] = $reference;
        }

        return $body;
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
