<?php declare(strict_types=1);

namespace Beliq\Shopware\Service;

use Beliq\Shopware\Exception\BeliqTransportException;

/** A cURL-backed HttpClient with no framework dependency. */
final class CurlHttpClient implements HttpClient
{
    /**
     * $connectTimeoutSeconds bounds the TCP connect alone. It matters because
     * BeliqInvoiceRenderer::render() calls this once per order in a bulk run:
     * against a black-holed host (a mistyped base URL, a DNS-resolvable host
     * that drops SYNs) each order otherwise burns the full $timeoutSeconds, so
     * a 50-order render sits for 25 minutes instead of 8. libcurl's own default
     * is 300s, capped here only by the total timeout.
     */
    public function __construct(
        private readonly int $timeoutSeconds = 30,
        private readonly int $connectTimeoutSeconds = 10,
    ) {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('Failed to initialize cURL.');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $responseHeaders = [];
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            // ext-curl sends a null body as '' too, so a GET carries an empty body either way.
            CURLOPT_POSTFIELDS => $body ?? '',
            CURLOPT_HEADERFUNCTION => static function ($_handle, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        $responseBody = curl_exec($handle);
        if ($responseBody === false) {
            $error = curl_error($handle);
            $kind = self::failureKind(curl_errno($handle), (float) curl_getinfo($handle, CURLINFO_CONNECT_TIME));
            curl_close($handle);
            throw new BeliqTransportException($kind, 'beliq request failed: ' . $error);
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return [
            'status' => $status,
            'body' => (string) $responseBody,
            'headers' => $responseHeaders,
        ];
    }

    /**
     * Where the request stopped, from libcurl's error number. A timeout (28) is
     * the connect timeout when no connection was ever made, which libcurl reports
     * as a connect time of 0, and this client's own total timeout otherwise.
     *
     * @return BeliqTransportException::*
     */
    private static function failureKind(int $errno, float $connectTime): string
    {
        return match ($errno) {
            CURLE_COULDNT_RESOLVE_PROXY, CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT => BeliqTransportException::CONNECT_FAILED,
            CURLE_OPERATION_TIMEDOUT => $connectTime > 0.0 ? BeliqTransportException::TIMEOUT : BeliqTransportException::CONNECT_TIMEOUT,
            CURLE_SSL_CONNECT_ERROR, CURLE_SSL_PEER_CERTIFICATE, CURLE_SSL_CERTPROBLEM,
            CURLE_SSL_CACERT_BADFILE => BeliqTransportException::TLS,
            default => BeliqTransportException::CONNECTION_LOST,
        };
    }
}
