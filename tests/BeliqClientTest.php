<?php declare(strict_types=1);

namespace Beliq\Shopware\Tests;

use Beliq\Shopware\Exception\BeliqApiException;
use Beliq\Shopware\Service\BeliqClient;
use Beliq\Shopware\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class BeliqClientTest extends TestCase
{
    /**
     * /v1/generate returns the raw file by default but switches to a JSON
     * envelope carrying the file base64-encoded as soon as the caller ranks
     * application/json above the document's own media type. The other endpoints
     * do want JSON, so the header cannot be shared: asking for JSON here made
     * every stored document the envelope instead of the document.
     */
    public function testGenerateAsksForTheDocumentAndNeverTheJsonEnvelope(): void
    {
        foreach ([['xml', 'application/xml'], ['pdf', 'application/pdf']] as [$output, $expected]) {
            $http = new FakeHttpClient([
                'status' => 200,
                'body' => 'bytes',
                'headers' => ['content-type' => $expected],
            ]);
            $client = new BeliqClient('key-123', 'https://api.beliq.eu', $http);

            $client->generate(['standard' => 'zugferd', 'output' => $output, 'invoice' => []]);

            $accept = $http->lastCall()['headers']['Accept'] ?? '';
            self::assertSame($expected, $accept, "output=$output must Accept the document");
            self::assertStringNotContainsString('application/json', $accept, "output=$output must not opt into the JSON envelope");
        }
    }

    /** The JSON-returning endpoints still ask for JSON. */
    public function testJsonEndpointsStillAcceptJson(): void
    {
        $http = new FakeHttpClient([
            'status' => 200,
            'body' => '{"success":true,"data":{"valid":true}}',
            'headers' => ['content-type' => 'application/json'],
        ]);
        $client = new BeliqClient('key-123', 'https://api.beliq.eu', $http);

        $client->validate('<Invoice/>');
        self::assertSame('application/json', $http->lastCall()['headers']['Accept']);

        $client->me();
        self::assertSame('application/json', $http->lastCall()['headers']['Accept']);
    }

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

    /** RFC 9110, section 10.2.3: Retry-After is delay-seconds or an HTTP-date. */
    public function testRetryAfterIsReadInBothOfItsForms(): void
    {
        $at = static function (string $header): ?int {
            $http = new FakeHttpClient([
                'status' => 503,
                'body' => '{"success":false,"error":{"code":"DATABASE_UNAVAILABLE","message":"down"}}',
                'headers' => ['content-type' => 'application/json', 'retry-after' => $header],
            ]);
            try {
                (new BeliqClient('k', 'https://api.beliq.eu', $http))->generate(['invoice' => []]);
            } catch (BeliqApiException $e) {
                return $e->retryAfterSeconds;
            }
            self::fail('Expected a BeliqApiException.');
        };

        self::assertSame(30, $at('30'));
        // The date is in GMT whatever the shop's time zone.
        $zone = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');
        try {
            $date = $at(gmdate('D, d M Y H:i:s', time() + 120) . ' GMT');
        } finally {
            date_default_timezone_set($zone);
        }
        self::assertNotNull($date);
        self::assertGreaterThanOrEqual(118, $date);
        self::assertLessThanOrEqual(120, $date);
        self::assertNull($at('soon'));
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

    /**
     * A host may run with `serialize_precision` 17, at which json_encode writes
     * 19.99 as 19.989999999999998. The body carries 19.99 as written, and the
     * host's setting is left as it was.
     */
    public function testGenerateWritesAnAmountAsItsShortestDecimalWhateverTheHostPrecision(): void
    {
        $previous = ini_set('serialize_precision', '17');
        try {
            $http = new FakeHttpClient([
                'status' => 200,
                'body' => '<Invoice/>',
                'headers' => ['content-type' => 'application/xml'],
            ]);
            $client = new BeliqClient('key-123', 'https://api.beliq.eu', $http);

            $client->generate(['standard' => 'xrechnung', 'output' => 'xml', 'invoice' => ['lines' => [['unitPrice' => 19.99]]]]);

            self::assertStringContainsString('"unitPrice":19.99}', (string) $http->lastCall()['body']);
            self::assertSame('17', ini_get('serialize_precision'));
        } finally {
            ini_set('serialize_precision', $previous === false ? '-1' : $previous);
        }
    }

    /**
     * Below 1e15, where json_encode at `serialize_precision` -1 writes no
     * exponent, the body is the text it writes: keys, strings, lists and
     * amounts alike.
     */
    public function testGenerateWritesTheBodyJsonEncodeWritesAtShortestPrecision(): void
    {
        mt_srand(4931);
        $amounts = [];
        for ($i = 0; $i < 2000; $i++) {
            $amounts[] = mt_rand(-99999999999, 99999999999) / 100;
        }
        $body = [
            'standard' => 'xrechnung',
            'output' => 'xml',
            'invoice' => [
                'seller' => ['name' => "M\u{00FC}ller & S\u{00F6}hne / \"GmbH\"", 'vatId' => null],
                'lines' => [['quantity' => 3, 'unitPrice' => 0.1, 'taxRate' => 19.0, 'note' => '']],
                'tiny' => [1.0e-7, 5.0e-324, -0.0],
                'flags' => [true, false],
                'empty' => [],
                'amounts' => $amounts,
            ],
        ];
        $http = new FakeHttpClient(['status' => 200, 'body' => '<Invoice/>', 'headers' => ['content-type' => 'application/xml']]);

        (new BeliqClient('key-123', 'https://api.beliq.eu', $http))->generate($body);

        $previous = ini_set('serialize_precision', '-1');
        try {
            $expected = json_encode($body, JSON_THROW_ON_ERROR);
        } finally {
            ini_set('serialize_precision', $previous === false ? '-1' : $previous);
        }
        self::assertSame($expected, $http->lastCall()['body']);
    }

    /** Every finite double, at any magnitude, reads back from the body as the same double. */
    public function testGenerateWritesEveryFloatSoItReadsBackUnchanged(): void
    {
        mt_srand(5813);
        $values = [];
        while (count($values) < 20000) {
            $value = unpack('E', pack('J', (mt_rand() << 32) ^ mt_rand()))[1];
            if (is_finite($value)) {
                $values[] = $value;
            }
        }
        $http = new FakeHttpClient(['status' => 200, 'body' => '<Invoice/>', 'headers' => ['content-type' => 'application/xml']]);

        (new BeliqClient('key-123', 'https://api.beliq.eu', $http))->generate(['invoice' => ['values' => $values]]);

        $sent = json_decode((string) $http->lastCall()['body'], true, 512, JSON_THROW_ON_ERROR)['invoice']['values'];
        self::assertSame($values, array_map(floatval(...), $sent));
    }

    public function testGenerateRefusesAnInfiniteAmountAsJsonEncodeDoes(): void
    {
        $http = new FakeHttpClient(['status' => 200, 'body' => '<Invoice/>', 'headers' => ['content-type' => 'application/xml']]);

        $this->expectException(\JsonException::class);
        (new BeliqClient('key-123', 'https://api.beliq.eu', $http))->generate(['invoice' => ['lines' => [['unitPrice' => INF]]]]);
    }
}
