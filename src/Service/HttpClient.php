<?php declare(strict_types=1);

namespace Beliq\Shopware\Service;

/**
 * The HTTP seam. Pass 1 ships CurlHttpClient; the Shopware wiring can inject an
 * adapter over Symfony's HttpClient, and tests inject a fake sender. The body is
 * raw bytes so binary responses (PDF) survive intact.
 */
interface HttpClient
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array;
}
