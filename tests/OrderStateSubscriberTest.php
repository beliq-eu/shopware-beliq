<?php declare(strict_types=1);

namespace Beliq\Shopware\Tests;

use Beliq\Shopware\Config\PluginConfigProvider;
use Beliq\Shopware\Document\BeliqInvoiceRenderer;
use Beliq\Shopware\Subscriber\OrderStateSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Checkout\Document\DocumentGenerationResult;
use Shopware\Core\Checkout\Document\Service\DocumentGenerator;
use Shopware\Core\Checkout\Document\Struct\DocumentGenerateOperation;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * The subscriber delegates document generation to DocumentGenerator, which runs
 * BeliqInvoiceRenderer and persists the result as a first-class order document.
 * These tests assert the delegation contract: the right document type, an
 * operation carrying the configured file type, the enabled / trigger gates, and
 * the never-break-checkout behaviour (a generation error is swallowed and logged).
 */
final class OrderStateSubscriberTest extends TestCase
{
    private const TRIGGER = 'state_enter.order_transaction.state.paid';

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

    private function order(): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId(Uuid::randomHex());
        $order->setUniqueIdentifier(Uuid::randomHex());
        $order->setSalesChannelId(Uuid::randomHex());

        return $order;
    }

    private function subscriber(DocumentGenerator $generator, bool $enabled = true, string $apiKey = 'blq_test'): OrderStateSubscriber
    {
        return new OrderStateSubscriber(
            $this->configProvider($enabled, $apiKey),
            $generator,
            new NullLogger(),
        );
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
