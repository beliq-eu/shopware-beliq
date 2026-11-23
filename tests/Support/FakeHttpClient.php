<?php declare(strict_types=1);

namespace Beliq\Shopware\Tests\Support;

use Beliq\Shopware\Service\HttpClient;

/** Records requests and replays a canned response, so client tests stay offline. */
final class FakeHttpClient implements HttpClient
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $calls = [];

    /**
     * @param array{status: int, body: string, headers: array<string, string>} $response
     * @param ?\Closure(): void $during runs inside each request, before it answers
     */
    public function __construct(private readonly array $response, private readonly ?\Closure $during = null)
    {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        if ($this->during !== null) {
            ($this->during)();
        }

        return $this->response;
    }

    /** @return array{method: string, url: string, headers: array<string, string>, body: ?string} */
    public function lastCall(): array
    {
        return $this->calls[array_key_last($this->calls)];
    }
}
