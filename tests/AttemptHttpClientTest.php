<?php declare(strict_types=1);

namespace Beliq\Shopware\Tests;

use Beliq\Shopware\Config\PluginConfigProvider;
use Beliq\Shopware\Document\InvoiceDocumentLookup;
use Beliq\Shopware\Retry\DocumentInvoiceGeneration;
use Beliq\Shopware\Service\AttemptHttpClient;
use Beliq\Shopware\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Document\DocumentGenerationResult;
use Shopware\Core\Checkout\Document\Service\DocumentGenerator;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * The order request waits on the call, so it keeps its short timeout; a later
 * attempt runs in the task runner and gets the beliq SDKs' 90 s, which covers
 * beliq's own deadlines.
 */
final class AttemptHttpClientTest extends TestCase
{
    public function testALaterAttemptGoesThroughTheLaterClientAndOnlyForItsDuration(): void
    {
        [$client, $orderRequest, $later] = $this->clients();

        $client->request('GET', 'https://api.beliq.eu/a');
        $client->runAsLaterAttempt(static fn () => $client->request('GET', 'https://api.beliq.eu/b'));
        try {
            $client->runAsLaterAttempt(static function (): never {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }
        $client->request('GET', 'https://api.beliq.eu/c');

        self::assertSame(['https://api.beliq.eu/a', 'https://api.beliq.eu/c'], array_column($orderRequest->calls, 'url'));
        self::assertSame(['https://api.beliq.eu/b'], array_column($later->calls, 'url'));
    }

    public function testTheGenerationRunsALaterAttemptThroughTheLaterClient(): void
    {
        [$client, $orderRequest, $later] = $this->clients();
        $generator = $this->createMock(DocumentGenerator::class);
        $generator->method('generate')->willReturnCallback(static function () use ($client): DocumentGenerationResult {
            $client->request('POST', 'https://api.beliq.eu/v1/generate');

            return new DocumentGenerationResult();
        });
        $generation = new DocumentInvoiceGeneration($this->config(), $generator, $this->noDocuments(), $client);

        self::assertTrue($generation->generate(Uuid::randomHex(), Uuid::randomHex(), false, Context::createDefaultContext()));
        self::assertTrue($generation->generate(Uuid::randomHex(), Uuid::randomHex(), true, Context::createDefaultContext()));

        self::assertCount(1, $orderRequest->calls);
        self::assertCount(1, $later->calls);
    }

    /** @return array{AttemptHttpClient, FakeHttpClient, FakeHttpClient} */
    private function clients(): array
    {
        $ok = ['status' => 200, 'body' => '', 'headers' => []];
        $orderRequest = new FakeHttpClient($ok);
        $later = new FakeHttpClient($ok);

        return [new AttemptHttpClient($orderRequest, $later), $orderRequest, $later];
    }

    private function noDocuments(): InvoiceDocumentLookup
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('searchIds')->willReturnCallback(
            static fn (Criteria $criteria, Context $context): IdSearchResult => new IdSearchResult(0, [], $criteria, $context),
        );

        return new InvoiceDocumentLookup($repository);
    }

    private function config(): PluginConfigProvider
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnCallback(
            static fn (string $key): mixed => match ($key) {
                PluginConfigProvider::DOMAIN . 'enabled' => true,
                PluginConfigProvider::DOMAIN . 'apiKey' => 'blq_test',
                default => null,
            },
        );

        return new PluginConfigProvider($systemConfig);
    }
}
