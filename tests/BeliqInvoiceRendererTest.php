<?php declare(strict_types=1);

namespace Beliq\Shopware\Tests;

use Beliq\Shopware\Config\PluginConfigProvider;
use Beliq\Shopware\Document\BeliqInvoiceRenderer;
use Beliq\Shopware\Document\DeliveryShippedDates;
use Beliq\Shopware\Document\InvoiceDocumentLookup;
use Beliq\Shopware\Document\IssuedInvoiceNumbers;
use Beliq\Shopware\Exception\MissingOrderDataException;
use Beliq\Shopware\Service\InvoiceMapper;
use Beliq\Shopware\Service\OrderAdapter;
use Beliq\Shopware\Tests\Support\FakeHttpClient;
use Beliq\Shopware\Tests\Support\NumberDatabase;
use Beliq\Shopware\Tests\Support\TestClock;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Document\DocumentCollection;
use Shopware\Core\Checkout\Document\DocumentEntity;
use Shopware\Core\Checkout\Document\Renderer\DocumentRendererConfig;
use Shopware\Core\Checkout\Document\Struct\DocumentGenerateOperation;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineHistory\StateMachineHistoryCollection;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineHistory\StateMachineHistoryEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\InMemoryStore;

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
    /** 2026-11-26 23:30 UTC, the clock of every test that sets none. */
    private const NOW = 1795735800;

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
        // The order's first beliq document replaces no invoice.
        self::assertArrayNotHasKey('precedingInvoiceReference', $sent['invoice']);
    }

    public function testTheIssueDateIsTheDayOfTheDocumentDateTheCreateDialogSends(): void
    {
        $order = $this->businessOrder('SW10042');
        $sent = [];
        // The Administration's create dialog sends `new Date().toISOString()`. An
        // offset is converted: 00:30 at +01:00 is still the 26th in UTC.
        foreach (['2026-11-25T08:15:00.000Z', '2026-11-27T00:30:00+01:00'] as $documentDate) {
            $http = new FakeHttpClient(['status' => 200, 'body' => '<x/>', 'headers' => ['content-type' => 'application/xml']]);
            $result = $this->renderer($order, $this->config('zugferd', 'xml'), $http)
                ->render([$order->getId() => $this->operation($order->getId(), 'xml', ['documentDate' => $documentDate])], Context::createDefaultContext(), new DocumentRendererConfig());
            $sent[] = [
                json_decode((string) $http->lastCall()['body'], true)['invoice']['issueDate'],
                $result->getOrderSuccess($order->getId())?->getConfig()[BeliqInvoiceRenderer::ISSUE_DATE_CONFIG] ?? null,
            ];
        }

        // Not the order date (2026-06-30), and not the clock's day.
        self::assertSame([['2026-11-25', '2026-11-25'], ['2026-11-26', '2026-11-26']], $sent);
    }

    public function testWithoutADocumentDateTheIssueDateIsTheUtcDayOfTheCreate(): void
    {
        // The automatic create sends no document date. A clock in another time
        // zone still gives the UTC day.
        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-11-27 00:30:00', new \DateTimeZone('Europe/Berlin'));
            }
        };
        $http = new FakeHttpClient(['status' => 200, 'body' => '<x/>', 'headers' => ['content-type' => 'application/xml']]);
        $order = $this->businessOrder('SW10042');

        $result = $this->renderer($order, $this->config('zugferd', 'xml'), $http, clock: $clock)
            ->render([$order->getId() => $this->operation($order->getId(), 'xml')], Context::createDefaultContext(), new DocumentRendererConfig());

        self::assertSame('2026-11-26', json_decode((string) $http->lastCall()['body'], true)['invoice']['issueDate']);
        self::assertSame('2026-11-26', $result->getOrderSuccess($order->getId())?->getConfig()[BeliqInvoiceRenderer::ISSUE_DATE_CONFIG] ?? null);
    }

    public function testADocumentDateThatIsNoDateFailsBeforeTheGenerate(): void
    {
        $order = $this->businessOrder('SW10042');
        foreach (['31.11.2026 Uhr', 1795735800, ''] as $documentDate) {
            $http = new FakeHttpClient(['status' => 200, 'body' => '<x/>', 'headers' => ['content-type' => 'application/xml']]);
            $result = $this->renderer($order, $this->config('zugferd', 'xml'), $http)
                ->render([$order->getId() => $this->operation($order->getId(), 'xml', ['documentDate' => $documentDate])], Context::createDefaultContext(), new DocumentRendererConfig());

            self::assertNotNull($result->getOrderError($order->getId()), var_export($documentDate, true));
            self::assertSame([], $http->calls, 'no billed generate for ' . var_export($documentDate, true));
        }
    }

    public function testARegenerateNamesTheInvoiceItReplacesWithThatInvoicesIssueDate(): void
    {
        $http = new FakeHttpClient(['status' => 200, 'body' => '<x/>', 'headers' => ['content-type' => 'application/xml']]);
        $order = $this->businessOrder('SW10042');

        $result = $this->renderer(
            $order,
            $this->config('zugferd', 'xml'),
            $http,
            existingNumbers: ['SW10042-2', 'SW10042'],
            issueDates: ['SW10042' => '2026-11-20', 'SW10042-2' => '2026-11-24'],
        )->render([$order->getId() => $this->operation($order->getId(), 'xml')], Context::createDefaultContext(), new DocumentRendererConfig());

        self::assertSame('SW10042-3', $result->getOrderSuccess($order->getId())?->getNumber());
        $sent = json_decode((string) $http->lastCall()['body'], true);
        self::assertSame(['id' => 'SW10042-2', 'issueDate' => '2026-11-24'], $sent['invoice']['precedingInvoiceReference'] ?? null);
        self::assertSame('2026-11-26', $sent['invoice']['issueDate']);
    }

    public function testAnInvoiceThatRecordedNoIssueDateIsNamedWithoutOne(): void
    {
        // A document made by 0.2.13 or earlier recorded no issue date, and its
        // BT-2 was the order date, not the day it was made.
        $http = new FakeHttpClient(['status' => 200, 'body' => '<x/>', 'headers' => ['content-type' => 'application/xml']]);
        $order = $this->businessOrder('SW10042');

        $this->renderer($order, $this->config('zugferd', 'xml'), $http, existingNumbers: ['SW10042'])
            ->render([$order->getId() => $this->operation($order->getId(), 'xml')], Context::createDefaultContext(), new DocumentRendererConfig());

        $sent = json_decode((string) $http->lastCall()['body'], true);
        self::assertSame(['id' => 'SW10042'], $sent['invoice']['precedingInvoiceReference'] ?? null);
    }

    public function testAnInvoiceWhoseDocumentWasDeletedIsNamedWithoutItsIssueDate(): void
    {
        // SW10042-2 was issued and its document deleted: the buyer may hold it, so
        // the next invoice replaces it. Its date went with the document.
        $http = new FakeHttpClient(['status' => 200, 'body' => '<x/>', 'headers' => ['content-type' => 'application/xml']]);
        $order = $this->businessOrder('SW10042');
        $numbers = NumberDatabase::connect();
        $issued = new IssuedInvoiceNumbers($numbers);
        $issued->record($order->getId(), 'SW10042');
        $issued->record($order->getId(), 'SW10042-2');

        $result = $this->renderer($order, $this->config('zugferd', 'xml'), $http, existingNumbers: ['SW10042'], numbers: $numbers, issueDates: ['SW10042' => '2026-11-20'])
            ->render([$order->getId() => $this->operation($order->getId(), 'xml')], Context::createDefaultContext(), new DocumentRendererConfig());

        self::assertSame('SW10042-3', $result->getOrderSuccess($order->getId())?->getNumber());
        $sent = json_decode((string) $http->lastCall()['body'], true);
        self::assertSame(['id' => 'SW10042-2'], $sent['invoice']['precedingInvoiceReference'] ?? null);
    }

    public function testAnotherOrdersNumberInThisOrdersFormIsNotNamedAsTheInvoiceReplaced(): void
    {
        // Order A-1 issued A-1-2 for its second document and holds a document
        // under it. Order A-1-2's first document takes A-1-2-2 and replaces nothing.
        $http = new FakeHttpClient(['status' => 200, 'body' => '<x/>', 'headers' => ['content-type' => 'application/xml']]);
        $order = $this->businessOrder('A-1-2');
        $numbers = NumberDatabase::connect();
        (new IssuedInvoiceNumbers($numbers))->record(Uuid::randomHex(), 'A-1-2');

        $result = $this->renderer($order, $this->config('zugferd', 'xml'), $http, numbers: $numbers, otherOrders: ['A-1', 'A-1-2'], issueDates: ['A-1-2' => '2026-11-20'])
            ->render([$order->getId() => $this->operation($order->getId(), 'xml')], Context::createDefaultContext(), new DocumentRendererConfig());

        self::assertSame('A-1-2-2', $result->getOrderSuccess($order->getId())?->getNumber());
        $sent = json_decode((string) $http->lastCall()['body'], true);
        self::assertArrayNotHasKey('precedingInvoiceReference', $sent['invoice']);
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

    public function testRegenerateUsesADistinctDocumentNumberWhenOneAlreadyExists(): void
    {
        $bytes = '<?xml version="1.0"?><Invoice>beliq</Invoice>';
        $http = new FakeHttpClient(['status' => 200, 'body' => $bytes, 'headers' => ['content-type' => 'application/xml']]);
        $order = $this->businessOrder('SW10042');

        // One beliq invoice already exists for the order. A regenerate must not reuse
        // SW10042 (Shopware rejects a duplicate document number), so it takes the next
        // distinct number instead of failing.
        $config = $this->config('zugferd', 'xml', extra: ['sellerIban' => 'DE02120300000000202051']);
        $result = $this->renderer($order, $config, $http, existingNumbers: ['SW10042'])
            ->render([$order->getId() => $this->operation($order->getId(), 'xml')], Context::createDefaultContext(), new DocumentRendererConfig());

        $doc = $result->getOrderSuccess($order->getId());
        self::assertNotNull($doc);
        self::assertSame('SW10042-2', $doc->getNumber());
        self::assertSame('invoice-SW10042-2', $doc->getName());
        self::assertSame('SW10042-2', $doc->getConfig()['documentNumber'] ?? null);

        // A regenerate is a new invoice, so the e-invoice carries the document's
        // number as its invoice number (BT-1). The order number stays the payment
        // reference (BT-83).
        $sent = json_decode((string) $http->lastCall()['body'], true);
        self::assertSame('SW10042-2', $sent['invoice']['number']);
        self::assertSame('SW10042', $sent['invoice']['paymentMeans']['paymentReference'] ?? null);
    }

    public function testRegenerateAfterADeletedDocumentTakesANumberNoDocumentHolds(): void
    {
        $http = new FakeHttpClient(['status' => 200, 'body' => '<x/>', 'headers' => ['content-type' => 'application/xml']]);
        $order = $this->businessOrder('SW10042');

        // SW10042, SW10042-2 and SW10042-3 were created, then SW10042-2 was
        // deleted. Two documents remain, so counting them gives SW10042-3, which
        // Shopware rejects as a duplicate.
        $result = $this->renderer($order, $this->config('zugferd', 'xml'), $http, existingNumbers: ['SW10042', 'SW10042-3'])
            ->render([$order->getId() => $this->operation($order->getId(), 'xml')], Context::createDefaultContext(), new DocumentRendererConfig());

        self::assertNull($result->getOrderError($order->getId()));
        $doc = $result->getOrderSuccess($order->getId());
        self::assertNotNull($doc);
        self::assertSame('SW10042-4', $doc->getNumber());
        self::assertSame('SW10042-4', $doc->getConfig()['documentNumber'] ?? null);
        $sent = json_decode((string) $http->lastCall()['body'], true);
        self::assertSame('SW10042-4', $sent['invoice']['number']);
    }

    public function testANumberIssuedBeforeEveryDocumentWasDeletedIsNotIssuedAgain(): void
    {
        $http = new FakeHttpClient(['status' => 200, 'body' => '<x/>', 'headers' => ['content-type' => 'application/xml']]);
        $order = $this->businessOrder('SW10042');
        // SW10042 was issued, then its document was deleted. The buyer may hold it.
        $numbers = NumberDatabase::connect();
        (new IssuedInvoiceNumbers($numbers))->record($order->getId(), 'SW10042');

        $result = $this->renderer($order, $this->config('zugferd', 'xml'), $http, numbers: $numbers)
            ->render([$order->getId() => $this->operation($order->getId(), 'xml')], Context::createDefaultContext(), new DocumentRendererConfig());

        self::assertSame('SW10042-2', $result->getOrderSuccess($order->getId())?->getNumber());
        $sent = json_decode((string) $http->lastCall()['body'], true);
        self::assertSame('SW10042-2', $sent['invoice']['number']);
        self::assertSame(
            [['number' => 'SW10042', 'order_id' => $order->getId()], ['number' => 'SW10042-2', 'order_id' => $order->getId()]],
            self::issuedRows($numbers),
        );
    }

    public function testANumberAnotherOrderIssuedIsNotIssuedAgain(): void
    {
        $http = new FakeHttpClient(['status' => 200, 'body' => '<x/>', 'headers' => ['content-type' => 'application/xml']]);
        $order = $this->businessOrder('A-1-2');
        // Order A-1 issued A-1-2 for its second document, which was deleted since.
        $numbers = NumberDatabase::connect();
        (new IssuedInvoiceNumbers($numbers))->record(Uuid::randomHex(), 'A-1-2');

        $result = $this->renderer($order, $this->config('zugferd', 'xml'), $http, numbers: $numbers)
            ->render([$order->getId() => $this->operation($order->getId(), 'xml')], Context::createDefaultContext(), new DocumentRendererConfig());

        self::assertSame('A-1-2-2', $result->getOrderSuccess($order->getId())?->getNumber());
    }

    public function testACreateBeforeTheLastDocumentIsStoredTakesTheNextNumber(): void
    {
        // Shopware stores a document after the renderer returns. A second create
        // that gets the lock in between finds no document yet, only the number
        // the first one recorded.
        $http = new FakeHttpClient(['status' => 200, 'body' => '<x/>', 'headers' => ['content-type' => 'application/xml']]);
        $order = $this->businessOrder('SW10042');
        $numbers = NumberDatabase::connect();
        $renderer = $this->renderer($order, $this->config('zugferd', 'xml'), $http, numbers: $numbers);

        $created = [];
        foreach ([1, 2] as $create) {
            $result = $renderer->render([$order->getId() => $this->operation($order->getId(), 'xml')], Context::createDefaultContext(), new DocumentRendererConfig());
            $created[] = $result->getOrderSuccess($order->getId())?->getNumber();
        }

        self::assertSame(['SW10042', 'SW10042-2'], $created);
        self::assertSame(['SW10042', 'SW10042-2'], array_column(self::issuedRows($numbers), 'number'));
        // The first document is not stored yet, so its issue date is not known.
        $references = array_map(
            static fn (array $call): mixed => json_decode((string) $call['body'], true)['invoice']['precedingInvoiceReference'] ?? null,
            $http->calls,
        );
        self::assertSame([null, ['id' => 'SW10042']], $references);
    }

    public function testTheOrdersNumberLockIsHeldThroughTheGenerateCall(): void
    {
        $order = $this->businessOrder('SW10042');
        $locks = new LockFactory(new InMemoryStore());
        $other = $locks->createLock(BeliqInvoiceRenderer::NUMBER_LOCK . $order->getId());
        $otherOrder = $locks->createLock(BeliqInvoiceRenderer::NUMBER_LOCK . Uuid::randomHex());
        $heldDuringGenerate = null;
        $http = new FakeHttpClient(
            ['status' => 200, 'body' => '<x/>', 'headers' => ['content-type' => 'application/xml']],
            static function () use ($other, $otherOrder, &$heldDuringGenerate): void {
                $heldDuringGenerate = !$other->acquire(false);
                // Another order's create is not held up.
                self::assertTrue($otherOrder->acquire(false));
                $otherOrder->release();
            },
        );

        $result = $this->renderer($order, $this->config('zugferd', 'xml'), $http, locks: $locks)
            ->render([$order->getId() => $this->operation($order->getId(), 'xml')], Context::createDefaultContext(), new DocumentRendererConfig());

        self::assertNotNull($result->getOrderSuccess($order->getId()));
        self::assertTrue($heldDuringGenerate);
        self::assertTrue($other->acquire(false), 'released after the create');
    }

    public function testAFailedGenerateRecordsNoNumberAndReleasesTheLock(): void
    {
        $http = new FakeHttpClient(['status' => 422, 'body' => '{"error":{"code":"VALIDATION","message":"bad"}}', 'headers' => ['content-type' => 'application/json']]);
        $order = $this->businessOrder('SW10042');
        $locks = new LockFactory(new InMemoryStore());
        $numbers = NumberDatabase::connect();

        $result = $this->renderer($order, $this->config('zugferd', 'xml'), $http, locks: $locks, numbers: $numbers)
            ->render([$order->getId() => $this->operation($order->getId(), 'xml')], Context::createDefaultContext(), new DocumentRendererConfig());

        self::assertNotNull($result->getOrderError($order->getId()));
        // No document holds SW10042, so the next create still takes it.
        self::assertSame([], self::issuedRows($numbers));
        self::assertTrue($locks->createLock(BeliqInvoiceRenderer::NUMBER_LOCK . $order->getId())->acquire(false));
    }

    public function testAFailedRecordKeepsTheBilledDocument(): void
    {
        $http = new FakeHttpClient(['status' => 200, 'body' => '<x/>', 'headers' => ['content-type' => 'application/xml']]);
        $order = $this->businessOrder('SW10042');
        $numbers = NumberDatabase::connect();
        $numbers->executeStatement("CREATE TRIGGER refuse BEFORE INSERT ON beliq_invoice_number BEGIN SELECT RAISE(ABORT, 'refused'); END");
        $logger = self::recordingLogger();

        $result = $this->renderer($order, $this->config('zugferd', 'xml'), $http, numbers: $numbers, logger: $logger)
            ->render([$order->getId() => $this->operation($order->getId(), 'xml')], Context::createDefaultContext(), new DocumentRendererConfig());

        self::assertNull($result->getOrderError($order->getId()));
        self::assertSame('SW10042', $result->getOrderSuccess($order->getId())?->getNumber());
        self::assertSame(['beliq invoice number not recorded'], $logger->messages);
    }

    public function testAFailedLockReleaseKeepsTheBilledDocument(): void
    {
        $http = new FakeHttpClient(['status' => 200, 'body' => '<x/>', 'headers' => ['content-type' => 'application/xml']]);
        $order = $this->businessOrder('SW10042');
        $store = new class implements PersistingStoreInterface {
            private InMemoryStore $inner;

            public function __construct()
            {
                $this->inner = new InMemoryStore();
            }

            public function save(Key $key): void
            {
                $this->inner->save($key);
            }

            public function delete(Key $key): void
            {
                throw new \RuntimeException('lock store unreachable');
            }

            public function exists(Key $key): bool
            {
                return $this->inner->exists($key);
            }

            public function putOffExpiration(Key $key, float $ttl): void
            {
                $this->inner->putOffExpiration($key, $ttl);
            }
        };
        $logger = self::recordingLogger();

        $result = $this->renderer($order, $this->config('zugferd', 'xml'), $http, locks: new LockFactory($store), logger: $logger)
            ->render([$order->getId() => $this->operation($order->getId(), 'xml')], Context::createDefaultContext(), new DocumentRendererConfig());

        self::assertNull($result->getOrderError($order->getId()));
        self::assertSame('SW10042', $result->getOrderSuccess($order->getId())?->getNumber());
        self::assertSame(['beliq invoice number lock not released'], $logger->messages);
    }

    public function testThePreviewIsALocalNoticeAndMakesNoBeliqCall(): void
    {
        $http = new FakeHttpClient(['status' => 200, 'body' => '<x/>', 'headers' => ['content-type' => 'application/xml']]);
        $order = $this->businessOrder('SW10051');

        // What DocumentController::previewDocument sends for the Preview button of
        // the admin's create-document dialog.
        $operation = new DocumentGenerateOperation($order->getId(), 'pdf', [], null, false, true);

        $result = $this->renderer($order, $this->config('xrechnung', 'xml'), $http)
            ->render([$order->getId() => $operation], Context::createDefaultContext(), new DocumentRendererConfig());

        self::assertSame([], $http->calls, 'a preview must not bill a beliq generate');
        $doc = $result->getOrderSuccess($order->getId());
        self::assertNotNull($doc, 'without a result Shopware shows the merchant a generation error');
        self::assertSame(BeliqInvoiceRenderer::PREVIEW_NOTICE, $doc->getContent());
        self::assertSame('text/plain; charset=utf-8', $doc->getContentType());
    }

    public function testThePreviewOfAnOrderTheCreateWouldSkipIsNoNotice(): void
    {
        $http = new FakeHttpClient(['status' => 200, 'body' => 'unused', 'headers' => []]);
        $order = $this->privateOrder('SW10052');
        $operation = new DocumentGenerateOperation($order->getId(), 'pdf', [], null, false, true);

        // A notice would ask the merchant to create a document that the
        // business-only setting then skips.
        $result = $this->renderer($order, $this->config('zugferd', 'xml', businessOnly: true), $http)
            ->render([$order->getId() => $operation], Context::createDefaultContext(), new DocumentRendererConfig());

        self::assertNull($result->getOrderSuccess($order->getId()));
        self::assertSame([], $http->calls);
    }

    public function testThePreviewOfAnOrderMissingDataShowsTheMissingValue(): void
    {
        $http = new FakeHttpClient(['status' => 200, 'body' => 'unused', 'headers' => []]);
        $order = $this->businessOrder('SW10053');
        $order->setShippingCosts(new CalculatedPrice(
            4.95,
            4.95,
            new CalculatedTaxCollection([new CalculatedTax(0.79, 19.0, 4.95)]),
            new TaxRuleCollection(),
        ));
        $operation = new DocumentGenerateOperation($order->getId(), 'pdf', [], null, false, true);

        $result = $this->renderer($order, $this->config('zugferd', 'xml'), $http)
            ->render([$order->getId() => $operation], Context::createDefaultContext(), new DocumentRendererConfig());

        self::assertNull($result->getOrderSuccess($order->getId()));
        $error = $result->getOrderError($order->getId());
        self::assertNotNull($error, 'the create would fail, so the preview says why');
        self::assertStringContainsString('no delivery that carries them', $error->getMessage());
        self::assertSame([], $http->calls);
    }

    public function testAStoredDocumentIsNotRenderedAgainForAnotherFileType(): void
    {
        $http = new FakeHttpClient(['status' => 200, 'body' => '<x/>', 'headers' => ['content-type' => 'application/xml']]);
        $order = $this->businessOrder('SW10048');

        // What DocumentGenerator::readDocument sends when an XML beliq invoice is
        // read as pdf, the default of the Admin API download.
        $operation = $this->operation($order->getId(), 'pdf');
        $operation->setDocumentId('0196f4b2c1a07d3e9b5a8c2f4e6d1b02');

        $result = $this->renderer($order, $this->config('xrechnung', 'xml'), $http, existingNumbers: ['SW10048'])
            ->render([$order->getId() => $operation], Context::createDefaultContext(), new DocumentRendererConfig());

        self::assertNull($result->getOrderSuccess($order->getId()), 'a stored document must not be replaced');
        self::assertNull($result->getOrderError($order->getId()), 'declining the re-render is not an error');
        self::assertSame([], $http->calls, 'no second beliq generate for a document that exists');
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

    public function testXrechnungWithThePdfSettingStillRequestsXml(): void
    {
        // The Output setting defaults to PDF and XRechnung has no hybrid PDF, so
        // this pairing is the plugin's most likely misconfiguration. Sending it as
        // asked is a 400 on every order; the setting's own label ("PDF (hybrid,
        // where the format supports it)") says XML is what it means here.
        $http = new FakeHttpClient(['status' => 200, 'body' => '<x/>', 'headers' => ['content-type' => 'application/xml']]);
        $order = $this->businessOrder('SW10046');

        $result = $this->renderer($order, $this->config('xrechnung', 'pdf'), $http)
            ->render([$order->getId() => $this->operation($order->getId(), 'xml')], Context::createDefaultContext(), new DocumentRendererConfig());

        $sent = json_decode($http->lastCall()['body'], true);
        self::assertSame('xrechnung', $sent['standard']);
        self::assertSame('xml', $sent['output'], 'xrechnung has no hybrid PDF, so pdf must resolve to xml');

        $doc = $result->getOrderSuccess($order->getId());
        self::assertNotNull($doc);
        self::assertSame('xml', $doc->getFileExtension());
    }

    public function testZugferdWithThePdfSettingStillRequestsPdf(): void
    {
        $http = new FakeHttpClient(['status' => 200, 'body' => '%PDF-1.3 fake', 'headers' => ['content-type' => 'application/pdf']]);
        $order = $this->businessOrder('SW10047');

        $this->renderer($order, $this->config('zugferd', 'pdf'), $http)
            ->render([$order->getId() => $this->operation($order->getId(), 'pdf')], Context::createDefaultContext(), new DocumentRendererConfig());

        self::assertSame('pdf', json_decode($http->lastCall()['body'], true)['output']);
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

    /**
     * The shipping line takes the name of the order's shipping method in the
     * order's language, as Shopware's own invoice does. The fake repository
     * resolves a translated field along the Context's language chain the way the
     * DAL does, so the name only comes out German when the order is loaded with
     * the order's language first in that chain.
     */
    public function testShippingLineTakesTheMethodNameInTheOrdersLanguage(): void
    {
        $http = new FakeHttpClient(['status' => 200, 'body' => '<x/>', 'headers' => ['content-type' => 'application/xml']]);
        $german = Uuid::randomHex();
        $order = $this->businessOrder('SW10048');
        $order->setLanguageId($german);
        $order->setShippingCosts(new CalculatedPrice(
            4.95,
            4.95,
            new CalculatedTaxCollection([new CalculatedTax(0.79, 19.0, 4.95)]),
            new TaxRuleCollection(),
        ));
        $repository = $this->translatingRepository($order, [
            Defaults::LANGUAGE_SYSTEM => 'Standard shipping',
            $german => 'Standardversand',
        ]);

        $result = $this->renderer($order, $this->config('zugferd', 'xml'), $http, orderRepository: $repository)
            ->render([$order->getId() => $this->operation($order->getId(), 'xml')], Context::createDefaultContext(), new DocumentRendererConfig());

        self::assertNull($result->getOrderError($order->getId()));
        $lines = json_decode($http->lastCall()['body'], true)['invoice']['lines'];
        self::assertSame('Standardversand', $lines[1]['description']);
    }

    /**
     * An order whose shipping method name cannot be read gets no invoice: the
     * line's name (BT-153) has no value the shop holds, and none is made up.
     */
    public function testShippingWithoutAMethodNameIsARenderErrorWithoutCallingTheApi(): void
    {
        $http = new FakeHttpClient(['status' => 200, 'body' => 'unused', 'headers' => []]);
        $order = $this->businessOrder('SW10049');
        $order->setShippingCosts(new CalculatedPrice(
            4.95,
            4.95,
            new CalculatedTaxCollection([new CalculatedTax(0.79, 19.0, 4.95)]),
            new TaxRuleCollection(),
        ));

        $result = $this->renderer($order, $this->config('zugferd', 'xml'), $http)
            ->render([$order->getId() => $this->operation($order->getId(), 'xml')], Context::createDefaultContext(), new DocumentRendererConfig());

        self::assertNull($result->getOrderSuccess($order->getId()));
        $error = $result->getOrderError($order->getId());
        self::assertNotNull($error);
        self::assertStringContainsString('no delivery that carries them', $error->getMessage());
        self::assertSame([], $http->calls);
    }

    /**
     * The business-only setting decides before the conversion, so a consumer
     * order it skips is not failed for a value only its invoice would need.
     */
    public function testPrivateOrderUnderBusinessOnlyIsSkippedBeforeShippingIsChecked(): void
    {
        $http = new FakeHttpClient(['status' => 200, 'body' => 'unused', 'headers' => []]);
        $order = $this->privateOrder('SW10050');
        $order->setShippingCosts(new CalculatedPrice(
            4.95,
            4.95,
            new CalculatedTaxCollection([new CalculatedTax(0.79, 19.0, 4.95)]),
            new TaxRuleCollection(),
        ));

        $result = $this->renderer($order, $this->config('zugferd', 'xml', businessOnly: true), $http)
            ->render([$order->getId() => $this->operation($order->getId(), 'xml')], Context::createDefaultContext(), new DocumentRendererConfig());

        self::assertNull($result->getOrderSuccess($order->getId()));
        self::assertNull($result->getOrderError($order->getId()), 'skipping is a no-op, not an error');
        self::assertSame([], $http->calls);
    }

    /**
     * Shopware records that no VAT was charged, not why, so a tax-free order
     * whose shipping country the plugin cannot read gets no invoice and beliq
     * is not called.
     */
    public function testATaxFreeOrderIsARenderErrorWithoutCallingTheApi(): void
    {
        $http = new FakeHttpClient(['status' => 200, 'body' => 'unused', 'headers' => []]);
        $order = $this->businessOrder('SW10051');
        $order->setTaxStatus(CartPrice::TAX_STATE_FREE);

        $result = $this->renderer($order, $this->config('zugferd', 'xml'), $http)
            ->render([$order->getId() => $this->operation($order->getId(), 'xml')], Context::createDefaultContext(), new DocumentRendererConfig());

        self::assertNull($result->getOrderSuccess($order->getId()));
        $error = $result->getOrderError($order->getId());
        self::assertInstanceOf(MissingOrderDataException::class, $error);
        self::assertStringContainsString('Shopware marked order SW10051 as tax free', $error->getMessage());
        self::assertSame([], $http->calls);
    }

    /**
     * An order in category K sends, as its actual delivery date, the day the
     * state machine history records its delivery was set to shipped.
     */
    public function testACategoryKOrderSendsTheDayItsDeliveryWasSetToShipped(): void
    {
        $http = new FakeHttpClient(['status' => 200, 'body' => '<x/>', 'headers' => ['content-type' => 'application/xml']]);
        $order = $this->businessOrder('SW10053');
        $order->setTaxStatus(CartPrice::TAX_STATE_FREE);
        $order->getOrderCustomer()?->setVatIds(['ATU12345678']);
        $country = new CountryEntity();
        $country->setUniqueIdentifier(Uuid::randomHex());
        $country->setId(Uuid::randomHex());
        $country->setIso('AT');
        $country->setIsEu(true);
        $shipTo = $this->address('Ring 1', '1010', 'Wien', 'AT', 'Kaeufer GmbH');
        $shipTo->setCountry($country);
        $delivery = new OrderDeliveryEntity();
        $delivery->setId(Uuid::randomHex());
        $delivery->setUniqueIdentifier($delivery->getId());
        $delivery->setShippingCosts($order->getShippingCosts());
        $delivery->setShippingOrderAddress($shipTo);
        $order->setDeliveries(new OrderDeliveryCollection([$delivery]));

        $config = $this->config('xrechnung', 'xml', extra: [
            'taxFreeEuBusiness' => 'K',
            'intraCommunityReasonCode' => 'VATEX-EU-IC',
        ]);
        $result = $this->renderer($order, $config, $http, shipped: [[$delivery->getId(), '2026-07-02T08:00:00+00:00']])
            ->render([$order->getId() => $this->operation($order->getId(), 'xml')], Context::createDefaultContext(), new DocumentRendererConfig());

        self::assertNull($result->getOrderError($order->getId()));
        self::assertIsString($http->lastCall()['body']);
        $sent = json_decode($http->lastCall()['body'], true);
        self::assertSame(['date' => '2026-07-02', 'address' => ['countryCode' => 'AT']], $sent['invoice']['delivery']);
        self::assertSame('K', $sent['invoice']['taxSummary'][0]['vatCategoryCode']);
    }

    public function testATaxFreeOrderTheBusinessOnlySettingSkipsRecordsNoError(): void
    {
        $http = new FakeHttpClient(['status' => 200, 'body' => 'unused', 'headers' => []]);
        $order = $this->privateOrder('SW10052');
        $order->setTaxStatus(CartPrice::TAX_STATE_FREE);

        $result = $this->renderer($order, $this->config('zugferd', 'xml', businessOnly: true), $http)
            ->render([$order->getId() => $this->operation($order->getId(), 'xml')], Context::createDefaultContext(), new DocumentRendererConfig());

        self::assertNull($result->getOrderSuccess($order->getId()));
        self::assertNull($result->getOrderError($order->getId()), 'skipping is a no-op, not an error');
        self::assertSame([], $http->calls);
    }

    public function testSupportsIsTheBeliqDocumentType(): void
    {
        $http = new FakeHttpClient(['status' => 200, 'body' => '', 'headers' => []]);
        $order = $this->businessOrder('SW1');

        self::assertSame(BeliqInvoiceRenderer::TYPE, $this->renderer($order, $this->config('zugferd', 'xml'), $http)->supports());
    }

    /**
     * @param list<string> $existingNumbers numbers of the beliq documents already on the order
     * @param EntityRepository<OrderCollection>|null $orderRepository
     * @param list<array{0: string, 1: string}> $shipped delivery id and time of each move from open to shipped
     * @param array<string, string> $issueDates by number, the issue date a beliq document recorded
     * @param list<string> $otherOrders numbers of beliq documents on other orders
     */
    private function renderer(
        OrderEntity $order,
        PluginConfigProvider $configProvider,
        FakeHttpClient $http,
        array $existingNumbers = [],
        ?EntityRepository $orderRepository = null,
        array $shipped = [],
        ?LockFactory $locks = null,
        ?Connection $numbers = null,
        ?LoggerInterface $logger = null,
        array $issueDates = [],
        array $otherOrders = [],
        ?ClockInterface $clock = null,
    ): BeliqInvoiceRenderer {
        if ($orderRepository === null) {
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
        }

        return new BeliqInvoiceRenderer(
            $orderRepository,
            $configProvider,
            new OrderAdapter(),
            new InvoiceMapper(),
            $this->lookup($order->getId(), $existingNumbers, $issueDates, $otherOrders),
            $this->shippedDates($shipped),
            new IssuedInvoiceNumbers($numbers ?? NumberDatabase::connect()),
            $locks ?? new LockFactory(new InMemoryStore()),
            $logger ?? new NullLogger(),
            $clock ?? new TestClock(self::NOW),
            $http,
        );
    }

    /**
     * @param list<array{0: string, 1: string}> $shipped
     */
    private function shippedDates(array $shipped): DeliveryShippedDates
    {
        $state = static function (string $technicalName): StateMachineStateEntity {
            $state = new StateMachineStateEntity();
            $state->setId(Uuid::randomHex());
            $state->setUniqueIdentifier($state->getId());
            $state->setTechnicalName($technicalName);

            return $state;
        };
        $entries = [];
        foreach ($shipped as [$deliveryId, $at]) {
            $entry = new StateMachineHistoryEntity();
            $entry->setId(Uuid::randomHex());
            $entry->setUniqueIdentifier($entry->getId());
            $entry->setReferencedId($deliveryId);
            $entry->setFromStateMachineState($state('open'));
            $entry->setToStateMachineState($state('shipped'));
            $entry->setCreatedAt(new \DateTimeImmutable($at));
            $entries[] = $entry;
        }

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturnCallback(
            static fn (Criteria $criteria, Context $context): EntitySearchResult => new EntitySearchResult(
                'state_machine_history',
                count($entries),
                new StateMachineHistoryCollection($entries),
                null,
                $criteria,
                $context,
            ),
        );

        return new DeliveryShippedDates($repository);
    }

    /**
     * An order repository that loads the order's delivery and its shipping method
     * only when the Criteria asks for `deliveries.shippingMethod`, and fills the
     * method's name the way the DAL's EntityHydrator does: `translated` holds the
     * first value along the Context's language chain, and the plain field holds
     * the value of the chain's first language or null.
     *
     * @param array<string, string> $nameByLanguage
     *
     * @return EntityRepository<OrderCollection>
     */
    private function translatingRepository(OrderEntity $order, array $nameByLanguage): EntityRepository
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturnCallback(
            static function (Criteria $criteria, Context $context) use ($order, $nameByLanguage): EntitySearchResult {
                $loaded = clone $order;
                $deliveries = $criteria->hasAssociation('deliveries')
                    ? $criteria->getAssociation('deliveries')
                    : null;
                if ($deliveries !== null && $deliveries->hasAssociation('shippingMethod')) {
                    $chain = $context->getLanguageIdChain();
                    $translated = null;
                    foreach ($chain as $languageId) {
                        if (isset($nameByLanguage[$languageId])) {
                            $translated = $nameByLanguage[$languageId];
                            break;
                        }
                    }

                    $method = new ShippingMethodEntity();
                    $method->setUniqueIdentifier(Uuid::randomHex());
                    $method->setId(Uuid::randomHex());
                    $method->setName($nameByLanguage[$chain[0]] ?? null);
                    $method->setTranslated(['name' => $translated]);

                    $delivery = new OrderDeliveryEntity();
                    $delivery->setUniqueIdentifier(Uuid::randomHex());
                    $delivery->setId(Uuid::randomHex());
                    $delivery->setShippingMethodId($method->getId());
                    $delivery->setShippingMethod($method);
                    $delivery->setShippingCosts($order->getShippingCosts());
                    $loaded->setDeliveries(new OrderDeliveryCollection([$delivery]));
                }

                return new EntitySearchResult('order', 1, new OrderCollection([$loaded]), null, $criteria, $context);
            },
        );

        return $repository;
    }

    /**
     * beliq documents, read as the DAL reads them for the order filter only. The
     * number filters are left to InvoiceDocumentLookupTest.
     *
     * @param list<string> $numbers beliq documents on the order
     * @param array<string, string> $issueDates by number, the issue date a document recorded
     * @param list<string> $otherOrders beliq documents on another order
     */
    private function lookup(string $orderId, array $numbers, array $issueDates = [], array $otherOrders = []): InvoiceDocumentLookup
    {
        $documents = [];
        foreach ([[$numbers, $orderId], [$otherOrders, Uuid::randomHex()]] as [$list, $documentOrderId]) {
            foreach ($list as $number) {
                $document = new DocumentEntity();
                $document->setId(Uuid::randomHex());
                $document->setUniqueIdentifier($document->getId());
                $document->setOrderId($documentOrderId);
                $document->setDocumentNumber($number);
                $config = ['documentNumber' => $number];
                if (isset($issueDates[$number])) {
                    $config[BeliqInvoiceRenderer::ISSUE_DATE_CONFIG] = $issueDates[$number];
                }
                $document->setConfig($config);
                $documents[] = $document;
            }
        }

        $documentRepository = $this->createMock(EntityRepository::class);
        $documentRepository->method('search')->willReturnCallback(
            static function (Criteria $criteria, Context $context) use ($documents): EntitySearchResult {
                foreach ($criteria->getFilters() as $filter) {
                    if ($filter instanceof EqualsFilter && $filter->getField() === 'orderId') {
                        $documents = array_values(array_filter(
                            $documents,
                            static fn (DocumentEntity $document): bool => $document->getOrderId() === $filter->getValue(),
                        ));
                    }
                }

                return new EntitySearchResult('document', count($documents), new DocumentCollection($documents), null, $criteria, $context);
            },
        );

        return new InvoiceDocumentLookup($documentRepository);
    }

    private static function recordingLogger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };
    }

    /**
     * @return list<array{number: string, order_id: string}>
     */
    private static function issuedRows(Connection $numbers): array
    {
        return array_map(
            static fn (array $row): array => ['number' => $row['number'], 'order_id' => Uuid::fromBytesToHex($row['order_id'])],
            $numbers->fetchAllAssociative('SELECT number, order_id FROM ' . IssuedInvoiceNumbers::TABLE . ' ORDER BY number'),
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function operation(string $orderId, string $fileType, array $config = []): DocumentGenerateOperation
    {
        return new DocumentGenerateOperation($orderId, $fileType, $config);
    }

    /**
     * A real PluginConfigProvider over a mocked SystemConfigService, so the config
     * resolution the renderer depends on is exercised for real rather than doubled.
     */
    /**
     * @param array<string, mixed> $extra
     */
    private function config(string $standard, string $output, bool $businessOnly = true, array $extra = []): PluginConfigProvider
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
        ] + $extra;

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
        $order->setLanguageId(Defaults::LANGUAGE_SYSTEM);
        $order->setOrderDateTime(new \DateTimeImmutable('2026-06-30T10:00:00+00:00'));
        $order->setTaxStatus(CartPrice::TAX_STATE_GROSS);
        $order->setCurrency($currency);
        $order->setLineItems(new OrderLineItemCollection([$item]));
        $order->setOrderCustomer($customer);
        $order->setBillingAddress($billing);
        $order->setShippingCosts(new CalculatedPrice(0.0, 0.0, new CalculatedTaxCollection(), new TaxRuleCollection()));

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
