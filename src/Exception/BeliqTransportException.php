<?php declare(strict_types=1);

namespace Beliq\Shopware\Exception;

/**
 * Raised when no HTTP answer came back from beliq. $kind says where the request
 * stopped, which decides whether a later attempt can help: before the request
 * left the shop nothing ran or was charged, after it beliq may have run it.
 *
 * @phpstan-type Kind self::CONNECT_FAILED|self::CONNECT_TIMEOUT|self::TIMEOUT|self::CONNECTION_LOST|self::TLS|self::CONFIGURATION
 */
final class BeliqTransportException extends \RuntimeException
{
    /** The host was not found or refused the connection: nothing was sent. */
    public const CONNECT_FAILED = 'connect_failed';

    /** The connection (TLS included) was not up before the connect timeout: nothing was sent. */
    public const CONNECT_TIMEOUT = 'connect_timeout';

    /** The request was sent and no answer came before this client's own timeout. */
    public const TIMEOUT = 'timeout';

    /** The connection broke after it was open, so the request may have reached beliq. */
    public const CONNECTION_LOST = 'connection_lost';

    /** The TLS handshake or the certificate failed: waiting does not fix it. */
    public const TLS = 'tls';

    /** The base URL or its scheme is wrong: waiting does not fix it. */
    public const CONFIGURATION = 'configuration';

    // libcurl's error numbers (curl/curl.h), written out so the mapping works
    // without ext-curl's CURLE_* constants, which differ between PHP builds.
    private const CURLE_UNSUPPORTED_PROTOCOL = 1;
    private const CURLE_URL_MALFORMAT = 3;
    private const CURLE_COULDNT_RESOLVE_PROXY = 5;
    private const CURLE_COULDNT_RESOLVE_HOST = 6;
    private const CURLE_COULDNT_CONNECT = 7;
    private const CURLE_OPERATION_TIMEDOUT = 28;
    private const CURLE_SSL_CONNECT_ERROR = 35;
    private const CURLE_SSL_ENGINE_NOTFOUND = 53;
    private const CURLE_SSL_ENGINE_SETFAILED = 54;
    private const CURLE_SSL_CERTPROBLEM = 58;
    private const CURLE_SSL_CIPHER = 59;
    private const CURLE_PEER_FAILED_VERIFICATION = 60;
    private const CURLE_USE_SSL_FAILED = 64;
    private const CURLE_SSL_CACERT_BADFILE = 77;
    private const CURLE_SSL_PINNEDPUBKEYNOTMATCH = 90;

    /** @param Kind $kind */
    public function __construct(public readonly string $kind, string $message)
    {
        parent::__construct($message);
    }

    /**
     * Where a request stopped, from libcurl's error number. A timeout (28) is the
     * connect timeout while the request was not yet sent, and the request's own
     * timeout after. Any other number may have come after the send.
     *
     * @return Kind
     */
    public static function kindForCurlError(int $errno, bool $requestSent): string
    {
        return match ($errno) {
            self::CURLE_COULDNT_RESOLVE_PROXY, self::CURLE_COULDNT_RESOLVE_HOST, self::CURLE_COULDNT_CONNECT => self::CONNECT_FAILED,
            self::CURLE_OPERATION_TIMEDOUT => $requestSent ? self::TIMEOUT : self::CONNECT_TIMEOUT,
            self::CURLE_UNSUPPORTED_PROTOCOL, self::CURLE_URL_MALFORMAT => self::CONFIGURATION,
            self::CURLE_SSL_CONNECT_ERROR, self::CURLE_SSL_ENGINE_NOTFOUND, self::CURLE_SSL_ENGINE_SETFAILED,
            self::CURLE_SSL_CERTPROBLEM, self::CURLE_SSL_CIPHER, self::CURLE_PEER_FAILED_VERIFICATION,
            self::CURLE_USE_SSL_FAILED, self::CURLE_SSL_CACERT_BADFILE, self::CURLE_SSL_PINNEDPUBKEYNOTMATCH => self::TLS,
            default => self::CONNECTION_LOST,
        };
    }
}
