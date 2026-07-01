<?php declare(strict_types=1);

namespace Beliq\Shopware\Service;

use Beliq\Shopware\Exception\BeliqApiException;

/**
 * A thin beliq API client. Auth is the X-API-Key header. generate returns the
 * document bytes (XML or PDF) plus header metadata; validate returns the parsed
 * ValidationResult. A non-2xx response is turned into a BeliqApiException.
 */
final class BeliqClient
{
    private readonly string $baseUrl;

    public function __construct(
        private readonly string $apiKey,
        string $baseUrl = 'https://api.beliq.eu',
        private readonly HttpClient $http = new CurlHttpClient(),
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * GET /v1/me. Account, plan, and quota context. Does not consume quota.
     *
     * @return array<string, mixed>
     */
    public function me(): array
    {
        $res = $this->http->request('GET', $this->baseUrl . '/v1/me', $this->authHeaders());

        return $this->decodeJson($res, 'me');
    }

    /**
     * POST /v1/generate. Returns the produced document.
     *
     * @param array<string, mixed> $body
     * @return array{contentType: string, bytes: string, meta: array<string, string>}
     */
    public function generate(array $body): array
    {
        $res = $this->http->request(
            'POST',
            $this->baseUrl . '/v1/generate',
            $this->authHeaders(['Content-Type' => 'application/json']),
            json_encode($body, JSON_THROW_ON_ERROR),
        );
        $this->throwIfError($res, 'generate');

        return [
            'contentType' => $res['headers']['content-type'] ?? 'application/octet-stream',
            'bytes' => $res['body'],
            'meta' => array_filter([
                'schematronVersion' => $res['headers']['x-schematron-version'] ?? null,
                'pdfKind' => $res['headers']['x-pdf-kind'] ?? null,
                'outputEnvelope' => $res['headers']['x-output-envelope'] ?? null,
            ], static fn ($v) => $v !== null),
        ];
    }

    /**
     * POST /v1/validate with a raw document. format is one of auto, cii, ubl.
     *
     * @return array<string, mixed> the ValidationResult
     */
    public function validate(string $document, string $contentType = 'application/xml', string $format = 'auto'): array
    {
        $res = $this->http->request(
            'POST',
            $this->baseUrl . '/v1/validate?format=' . rawurlencode($format),
            $this->authHeaders(['Content-Type' => $contentType]),
            $document,
        );

        return $this->decodeJson($res, 'validate');
    }

    /**
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    private function authHeaders(array $extra = []): array
    {
        return ['X-API-Key' => $this->apiKey, 'Accept' => 'application/json', ...$extra];
    }

    /**
     * @param array{status: int, body: string, headers: array<string, string>} $res
     * @return array<string, mixed>
     */
    private function decodeJson(array $res, string $operation): array
    {
        $this->throwIfError($res, $operation);

        $decoded = json_decode($res['body'], true);
        if (!is_array($decoded)) {
            throw new BeliqApiException(
                'beliq ' . $operation . ' returned a response that is not JSON.',
                'INVALID_RESPONSE',
                $res['status'],
            );
        }

        if (isset($decoded['data']) && is_array($decoded['data'])) {
            return $decoded['data'];
        }

        return $decoded;
    }

    /**
     * @param array{status: int, body: string, headers: array<string, string>} $res
     */
    private function throwIfError(array $res, string $operation): void
    {
        if ($res['status'] >= 200 && $res['status'] < 300) {
            return;
        }

        $code = 'HTTP_' . $res['status'];
        $message = 'beliq ' . $operation . ' failed with status ' . $res['status'] . '.';
        $details = null;

        $decoded = json_decode($res['body'], true);
        if (is_array($decoded) && isset($decoded['error']) && is_array($decoded['error'])) {
            $code = (string) ($decoded['error']['code'] ?? $code);
            $message = (string) ($decoded['error']['message'] ?? $message);
            $details = $decoded['error']['details'] ?? null;
        }

        throw new BeliqApiException($message, $code, $res['status'], $details);
    }
}
