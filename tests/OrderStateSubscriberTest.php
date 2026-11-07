<?php declare(strict_types=1);

namespace Beliq\Shopware\Tests;

use Beliq\Shopware\Config\PluginConfigProvider;
use Beliq\Shopware\Document\BeliqInvoiceRenderer;
use Beliq\Shopware\Document\InvoiceDocumentLookup;
use Beliq\Shopware\Exception\BeliqApiException;
use Beliq\Shopware\Retry\DocumentInvoiceGeneration;
use Beliq\Shopware\Retry\InvoiceAttempts;
use Beliq\Shopware\Retry\RetryStore;
use Beliq\Shopware\Service\AttemptHttpClient;
use Beliq\Shopware\Subscriber\OrderStateSubscriber;
use Beliq\Shopware\Tests\Support\FakeHttpClient;
use Beliq\Shopware\Tests\Support\RecordingRecorder;
use Beliq\Shopware\Tests\Support\RetryDatabase;
use Beliq\Shopware\Tests\Support\TestClock;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Checkout\Document\DocumentGenerationResult;
use Shopware\Core\Checkout\Document\Service\DocumentGenerator;
use Shopware\Core\Checkout\Document\Struct\DocumentGenerateOperation;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * The subscriber delegates document generation to DocumentGenerator, which runs
 * BeliqInvoiceRenderer and persists the result as a first-class order document.
 * These tests assert the delegation contract: the right document type, an
 * operation carrying the configured file type, the enabled / trigger gates, the
 * never-break-checkout behaviour (a generation error is swallowed), and what a
 * failure leaves behind: a later attempt for an outage, and a note on the order
 * either way. The retry store is a real database (SQLite).
 */
final class OrderStateSubscriberTest extends TestCase
{
    private const TRIGGER = 'state_enter.order_transaction.state.paid';

    private string $dbFile;

    private RecordingRecorder $recorder;

    protected function setUp(): void
    {
        $this->dbFile = RetryDatabase::create();
        $this->recorder = new RecordingRecorder();
    }

    protected function tearDown(): void
    {
        RetryDatabase::remove($this->dbFile);
    }

    public function testGeneratesBeliqDocumentOnTheConfiguredTrigger(): void
    {
        $context = Context::createDefaultContext();
        $order = $this->order();

        $capturedType = null;
        $capturedOperations = null;
        $generator = $this->createMock(DocumentGenerator::class);
        $generator->expects(self::once())
            ->method('generate')
            ->willReturnCallback(
                function (string $type, array $operations, Context $ctx) use (&$capturedType, &$capturedOperations): DocumentGenerationResult {
                    $capturedType = $type;
                    $capturedOperations = $operations;

                    return new DocumentGenerationResult();
                },
            );

        $this->subscriber($generator)->onOrderState(
            new OrderStateMachineStateChangeEvent(self::TRIGGER, $order, $context),
        );

        self::assertSame(BeliqInvoiceRenderer::TYPE, $capturedType);
        self::assertIsArray($capturedOperations);
        self::assertArrayHasKey($order->getId(), $capturedOperations);
        $operation = $capturedOperations[$order->getId()];
        self::assertInstanceOf(DocumentGenerateOperation::class, $operation);
        self::assertSame($order->getId(), $operation->getOrderId());
        // standard=zugferd + output=pdf resolves to a hybrid PDF document.
        self::assertSame('pdf', $operation->getFileType());
    }

    public function testDoesNothingWhenDisabled(): void
    {
        $generator = $this->createMock(DocumentGenerator::class);
        $generator->expects(self::never())->method('generate');

        $this->subscriber($generator, enabled: false)->onOrderState(
            new OrderStateMachineStateChangeEvent(self::TRIGGER, $this->order(), Context::createDefaultContext()),
        );
    }

    public function testDoesNothingWhenApiKeyMissing(): void
    {
        $generator = $this->createMock(DocumentGenerator::class);
        $generator->expects(self::never())->method('generate');

        $this->subscriber($generator, apiKey: '')->onOrderState(
            new OrderStateMachineStateChangeEvent(self::TRIGGER, $this->order(), Context::createDefaultContext()),
        );
    }

    public function testIgnoresTheEventThatIsNotTheConfiguredTrigger(): void
    {
        $generator = $this->createMock(DocumentGenerator::class);
        $generator->expects(self::never())->method('generate');

        // Configured trigger is "paid"; the completed event must be skipped.
        $this->subscriber($generator)->onOrderState(
            new OrderStateMachineStateChangeEvent('state_enter.order.state.completed', $this->order(), Context::createDefaultContext()),
        );
    }

    public function testDoesNotRegenerateWhenABeliqDocumentAlreadyExists(): void
    {
        $generator = $this->createMock(DocumentGenerator::class);
        $generator->expects(self::never())->method('generate');

        // A re-fired paid transition on an order that already has a beliq invoice
        // must be a no-op, not a duplicate document (and not a document-number clash).
        $this->subscriber($generator, existingCount: 1)->onOrderState(
            new OrderStateMachineStateChangeEvent(self::TRIGGER, $this->order(), Context::createDefaultContext()),
        );
    }

    public function testSwallowsGenerationErrorsSoCheckoutIsNeverBroken(): void
    {
        $result = new DocumentGenerationResult();
        $result->addError('order-1', new \RuntimeException('beliq is down'));

        $generator = $this->createMock(DocumentGenerator::class);
        $generator->method('generate')->willReturn($result);

        // The absence of a thrown exception is the assertion: the handler must
        // never let a generation failure propagate out of the transition.
        $this->subscriber($generator)->onOrderState(
            new OrderStateMachineStateChangeEvent(self::TRIGGER, $this->order(), Context::createDefaultContext()),
        );

        $this->addToAssertionCount(1);
    }

    /**
     * The problem check. Before the later invoice, an outage at the moment of
     * payment was logged and nothing else happened: no invoice, ever, and nothing
     * on the order.
     */
    public function testAnOutageAtPaymentLeavesALaterAttemptAndShowsOnTheOrder(): void
    {
        $order = $this->order();
        $result = new DocumentGenerationResult();
        $result->addError($order->getId(), new BeliqApiException('down', 'DATABASE_UNAVAILABLE', 503, null, 30));
        $generator = $this->createMock(DocumentGenerator::class);
        $generator->method('generate')->willReturn($result);

        $this->subscriber($generator)->onOrderState(
            new OrderStateMachineStateChangeEvent(self::TRIGGER, $order, Context::createDefaultContext()),
        );

        $pending = (new RetryStore(RetryDatabase::connect($this->dbFile)))->find($order->getId());
        self::assertNotNull($pending);
        self::assertSame(0, $pending->laterAttempts);
        self::assertCount(1, $this->recorder->failures);
        self::assertSame('DATABASE_UNAVAILABLE (HTTP 503)', $this->recorder->failures[0]['description']);
        self::assertSame($pending->nextAttemptAt, $this->recorder->failures[0]['nextAttemptAt']);
    }

    public function testARejectedInvoiceShowsOnTheOrderWithNoLaterAttempt(): void
    {
        $order = $this->order();
        $result = new DocumentGenerationResult();
        $result->addError($order->getId(), new BeliqApiException('invalid', 'INVALID_INVOICE', 422));
        $generator = $this->createMock(DocumentGenerator::class);
        $generator->method('generate')->willReturn($result);

        $this->subscriber($generator)->onOrderState(
            new OrderStateMachineStateChangeEvent(self::TRIGGER, $order, Context::createDefaultContext()),
        );

        self::assertNull((new RetryStore(RetryDatabase::connect($this->dbFile)))->find($order->getId()));
        self::assertSame('INVALID_INVOICE (HTTP 422)', $this->recorder->failures[0]['description']);
        self::assertNull($this->recorder->failures[0]['nextAttemptAt']);
    }

    private function order(): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId(Uuid::randomHex());
        $order->setUniqueIdentifier(Uuid::randomHex());
        $order->setSalesChannelId(Uuid::randomHex());

        return $order;
    }

    private function subscriber(DocumentGenerator $generator, bool $enabled = true, string $apiKey = 'blq_test', int $existingCount = 0): OrderStateSubscriber
    {
        $config = $this->configProvider($enabled, $apiKey);
        $lookup = $this->lookup($existingCount);
        $unused = new FakeHttpClient(['status' => 500, 'body' => '', 'headers' => []]);

        return new OrderStateSubscriber(
            $config,
            $lookup,
            new InvoiceAttempts(
                new RetryStore(RetryDatabase::connect($this->dbFile)),
                new DocumentInvoiceGeneration($config, $generator, $lookup, new AttemptHttpClient($unused, $unused)),
                $this->recorder,
                new NullLogger(),
                new TestClock(1_800_000_000),
            ),
            new NullLogger(),
        );
    }

    private function lookup(int $count): InvoiceDocumentLookup
    {
        $documentRepository = $this->createMock(EntityRepository::class);
        $documentRepository->method('searchIds')->willReturnCallback(
            static fn (Criteria $criteria, Context $context): IdSearchResult => new IdSearchResult($count, [], $criteria, $context),
        );

        return new InvoiceDocumentLookup($documentRepository);
    }

    private function configProvider(bool $enabled, string $apiKey): PluginConfigProvider
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnCallback(
            static fn (string $key): mixed => match ($key) {
                PluginConfigProvider::DOMAIN . 'enabled' => $enabled,
                PluginConfigProvider::DOMAIN . 'apiKey' => $apiKey,
                PluginConfigProvider::DOMAIN . 'businessOnly' => true,
                PluginConfigProvider::DOMAIN . 'triggerEvent' => self::TRIGGER,
                PluginConfigProvider::DOMAIN . 'standard' => 'zugferd',
                PluginConfigProvider::DOMAIN . 'output' => 'pdf',
                default => null,
            },
        );

        return new PluginConfigProvider($systemConfig);
    }
}
