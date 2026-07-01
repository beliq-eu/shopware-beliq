<?php declare(strict_types=1);

namespace Beliq\Shopware\Exception;

/** Raised when the beliq API returns a non-2xx response. */
final class BeliqApiException extends \RuntimeException
{
    /**
     * @param mixed $details the error `details` payload, if any
     */
    public function __construct(
        string $message,
        public readonly string $apiCode,
        public readonly int $status,
        public readonly mixed $details = null,
    ) {
        parent::__construct($message);
    }
}
