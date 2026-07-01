<?php declare(strict_types=1);

namespace Beliq\Shopware\Tests;

use Beliq\Shopware\Exception\BeliqApiException;
use Beliq\Shopware\Service\BeliqClient;
use Beliq\Shopware\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class BeliqClientTest extends TestCase
{
    public function testGenerateBuildsRequestAndReturnsDocument(): void
    {
        $http = new FakeHttpClient([
            'status' => 200,
            'body' => '<Invoice/>',
            'headers' => ['content-type' => 'application/xml', 'x-schematron-version' => '1.5'],
        ]);
        $client = new BeliqClient('key-123', 'https://api.beliq.eu', $http);

        $result = $client->generate(['standard' => 'xrechnung', 'output' => 'xml', 'invoice' => ['number' => 'X']]);

        $call = $http->lastCall();
        self::assertSame('POST', $call['method']);
        self::assertSame('https://api.beliq.eu/v1/generate', $call['url']);
        self::assertSame('key-123', $call['headers']['X-API-Key']);
        self::assertSame('application/json', $call['headers']['Content-Type']);
        self::assertSame('xrechnung', json_decode((string) $call['body'], true)['standard']);

        self::assertSame('application/xml', $result['contentType']);
        self::assertSame('<Invoice/>', $result['bytes']);
        self::assertSame('1.5', $result['meta']['schematronVersion']);
        self::assertArrayNotHasKey('pdfKind', $result['meta']);
    }

    public function testGeneratePdfExposesHeaderMetadata(): void
    {
        $http = new FakeHttpClient([
            'status' => 200,
            'body' => '%PDF-1.7 bytes',
            'headers' => [
                'content-type' => 'application/pdf',
                'x-pdf-kind' => 'facturx',
                'x-output-envelope' => 'pdf',
            ],
        ]);
        $client = new BeliqClient('key-123', 'https://api.beliq.eu', $http);

        $result = $client->generate(['standard' => 'facturx', 'output' => 'pdf', 'invoice' => []]);

        self::assertSame('application/pdf', $result['contentType']);
        self::assertSame('facturx', $result['meta']['pdfKind']);
        self::assertSame('pdf', $result['meta']['outputEnvelope']);
    }

    public function testGenerateErrorThrowsApiException(): void
    {
        $http = new FakeHttpClient([
            'status' => 400,
            'body' => json_encode([
                'success' => false,
                'error' => ['code' => 'INVALID_INVOICE', 'message' => 'Missing due date', 'details' => ['rule' => 'BR-CO-25']],
            ], JSON_THROW_ON_ERROR),
            'headers' => ['content-type' => 'application/json'],
        ]);
        $client = new BeliqClient('key-123', 'https://api.beliq.eu', $http);

        try {
            $client->generate(['standard' => 'xrechnung', 'output' => 'xml', 'invoice' => []]);
            self::fail('Expected BeliqApiException');
        } catch (BeliqApiException $e) {
            self::assertSame('INVALID_INVOICE', $e->apiCode);
            self::assertSame(400, $e->status);
            self::assertSame('Missing due date', $e->getMessage());
            self::assertSame(['rule' => 'BR-CO-25'], $e->details);
        }
    }

    public function testValidateUnwrapsDataAndBuildsQuery(): void
    {
        $http = new FakeHttpClient([
            'status' => 200,
            'body' => json_encode([
                'success' => true,
                'data' => ['valid' => true, 'errors' => [], 'warnings' => []],
            ], JSON_THROW_ON_ERROR),
            'headers' => ['content-type' => 'application/json'],
        ]);
        $client = new BeliqClient('key-123', 'https://api.beliq.eu', $http);

        $result = $client->validate('<Invoice/>');

        $call = $http->lastCall();
        self::assertSame('POST', $call['method']);
        self::assertSame('https://api.beliq.eu/v1/validate?format=auto', $call['url']);
        self::assertSame('application/xml', $call['headers']['Content-Type']);
        self::assertSame('<Invoice/>', $call['body']);
        self::assertTrue($result['valid']);
    }

    public function testValidateHonoursFormatAndContentType(): void
    {
        $http = new FakeHttpClient([
            'status' => 200,
            'body' => json_encode(['success' => true, 'data' => ['valid' => false]], JSON_THROW_ON_ERROR),
            'headers' => ['content-type' => 'application/json'],
        ]);
        $client = new BeliqClient('key-123', 'https://api.beliq.eu', $http);

        $client->validate('%PDF bytes', 'application/pdf', 'cii');

        $call = $http->lastCall();
        self::assertSame('https://api.beliq.eu/v1/validate?format=cii', $call['url']);
        self::assertSame('application/pdf', $call['headers']['Content-Type']);
    }

    public function testMeUnwrapsData(): void
    {
        $http = new FakeHttpClient([
            'status' => 200,
            'body' => json_encode(['success' => true, 'data' => ['plan' => 'business', 'quota' => 1000]], JSON_THROW_ON_ERROR),
            'headers' => ['content-type' => 'application/json'],
        ]);
        $client = new BeliqClient('key-123', 'https://api.beliq.eu', $http);

        $result = $client->me();

        self::assertSame('GET', $http->lastCall()['method']);
        self::assertSame('https://api.beliq.eu/v1/me', $http->lastCall()['url']);
        self::assertSame('key-123', $http->lastCall()['headers']['X-API-Key']);
        self::assertSame('business', $result['plan']);
    }

    public function testInvalidJsonResponseThrows(): void
    {
        $http = new FakeHttpClient([
            'status' => 200,
            'body' => 'not json at all',
            'headers' => ['content-type' => 'text/plain'],
        ]);
        $client = new BeliqClient('key-123', 'https://api.beliq.eu', $http);

        try {
            $client->me();
            self::fail('Expected BeliqApiException');
        } catch (BeliqApiException $e) {
            self::assertSame('INVALID_RESPONSE', $e->apiCode);
            self::assertSame(200, $e->status);
        }
    }

    public function testTrailingSlashInBaseUrlIsNormalized(): void
    {
        $http = new FakeHttpClient([
            'status' => 200,
            'body' => json_encode(['data' => []], JSON_THROW_ON_ERROR),
            'headers' => ['content-type' => 'application/json'],
        ]);
        $client = new BeliqClient('key-123', 'https://api.beliq.eu/', $http);

        $client->me();

        self::assertSame('https://api.beliq.eu/v1/me', $http->lastCall()['url']);
    }
}
