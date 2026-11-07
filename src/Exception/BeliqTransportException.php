<?php declare(strict_types=1);

namespace Beliq\Shopware\Exception;

/**
 * Raised when no HTTP answer came back from beliq. $kind says where the request
 * stopped, which decides whether a later attempt can help: before the request
 * left the shop nothing ran or was charged, after it beliq may have run it.
 */
final class BeliqTransportException extends \RuntimeException
{
    /** The host was not found or refused the connection: nothing was sent. */
    public const CONNECT_FAILED = 'connect_failed';

    /** The connection was not open before the connect timeout: nothing was sent. */
    public const CONNECT_TIMEOUT = 'connect_timeout';

    /** The request was sent and no answer came before this client's own timeout. */
    public const TIMEOUT = 'timeout';

    /** The connection broke after it was open, so the request may have reached beliq. */
    public const CONNECTION_LOST = 'connection_lost';

    /** The TLS handshake or the certificate failed: waiting does not fix it. */
    public const TLS = 'tls';

    /** @param self::* $kind */
    public function __construct(public readonly string $kind, string $message)
    {
        parent::__construct($message);
    }
}
