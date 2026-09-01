<?php declare(strict_types=1);

namespace Beliq\Shopware\Service;

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
            CURLOPT_POSTFIELDS => $body,
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
            curl_close($handle);
            throw new \RuntimeException('beliq request failed: ' . $error);
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return [
            'status' => $status,
            'body' => (string) $responseBody,
            'headers' => $responseHeaders,
        ];
    }
}
