<?php declare(strict_types=1);

namespace Beliq\Shopware\Service;

use Beliq\Shopware\Retry\RetrySchedule;

/**
 * The HttpClient the renderer calls through. The automatic invoice's first
 * attempt runs inside the order's state change, which a payment provider's
 * webhook or a customer waits on, so it keeps the short timeout. A later attempt
 * runs in the scheduled task, where nobody waits, and gets the longer timeout of
 * the beliq SDKs.
 */
final class AttemptHttpClient implements HttpClient
{
    private bool $laterAttempt = false;

    public function __construct(
        private readonly HttpClient $orderRequest = new CurlHttpClient(),
        private readonly HttpClient $later = new CurlHttpClient(RetrySchedule::LATER_ATTEMPT_TIMEOUT_SECONDS),
    ) {
    }

    /**
     * @template T
     * @param callable(): T $attempt
     * @return T
     */
    public function runAsLaterAttempt(callable $attempt): mixed
    {
        $this->laterAttempt = true;
        try {
            return $attempt();
        } finally {
            $this->laterAttempt = false;
        }
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        return ($this->laterAttempt ? $this->later : $this->orderRequest)->request($method, $url, $headers, $body);
    }
}
