<?php declare(strict_types=1);

namespace Beliq\Shopware\Retry;

use Beliq\Shopware\Exception\BeliqApiException;
use Beliq\Shopware\Exception\BeliqTransportException;

/**
 * Which failures of an automatic invoice are tried again later, and when.
 *
 * The classes follow the beliq SDKs. An outage a wait can outlast (a 503
 * DATABASE_UNAVAILABLE, a 502 or 503 with no beliq error code, a connection that
 * could not be opened) is tried at every slot of the window. A failure that may
 * have run on beliq's side (ENGINE_UNAVAILABLE and any other coded 502 or 503, a
 * 429 other than QUOTA_EXCEEDED, a connection lost after the send) is tried at
 * most COUNT_CLASS_RETRIES times, all inside the first COUNT_CLASS_WINDOW_SECONDS,
 * because beliq does not charge a repeat of the same request inside 15 minutes.
 * Everything else would send the same body to the same answer again, so it is
 * never retried. One departure from the SDKs: the order request's own timeout is
 * shorter than beliq's deadlines, so it starts the later attempts; a later
 * attempt's own timeout is final.
 *
 * The slots and the timeouts are beliq's choice, sized from its own outages; no
 * standard sets them.
 */
final class RetrySchedule
{
    public const WINDOW = 'window';
    public const COUNT = 'count';
    public const NONE = 'none';

    /** Seconds after the first attempt at which each later attempt is due. */
    public const LATER_ATTEMPT_OFFSETS = [120, 300, 600, 840, 1800, 3600];

    public const COUNT_CLASS_RETRIES = 3;

    public const COUNT_CLASS_WINDOW_SECONDS = 900;

    /** The per-attempt timeout of the beliq SDKs, which a later attempt uses. */
    public const LATER_ATTEMPT_TIMEOUT_SECONDS = 90;

    /**
     * How long a claimed attempt keeps its order from being claimed again. Longer
     * than an attempt can take, so two task runs never call beliq for one order
     * at once.
     */
    public const ATTEMPT_LEASE_SECONDS = 120;

    private const QUOTA_EXHAUSTED_CODE = 'QUOTA_EXCEEDED';
    private const DATABASE_UNAVAILABLE_CODE = 'DATABASE_UNAVAILABLE';

    /** @return self::WINDOW|self::COUNT|self::NONE */
    public static function classify(\Throwable $failure, bool $firstAttempt): string
    {
        if ($failure instanceof BeliqApiException) {
            return self::classifyStatus($failure->status, $failure->apiCode);
        }
        if ($failure instanceof BeliqTransportException) {
            return match ($failure->kind) {
                BeliqTransportException::CONNECT_FAILED, BeliqTransportException::CONNECT_TIMEOUT => self::WINDOW,
                BeliqTransportException::TIMEOUT => $firstAttempt ? self::WINDOW : self::NONE,
                BeliqTransportException::CONNECTION_LOST => self::COUNT,
                BeliqTransportException::TLS => self::NONE,
            };
        }

        return self::NONE;
    }

    public static function start(int $now): RetryState
    {
        return new RetryState($now, 0, 0, $now + self::LATER_ATTEMPT_OFFSETS[0]);
    }

    /** The state to write before a later attempt's call, so a process killed mid-call leaves the next attempt in place. */
    public static function claim(RetryState $state, int $now): RetryState
    {
        $laterAttempts = $state->laterAttempts + 1;

        return new RetryState(
            $state->firstAttemptAt,
            $laterAttempts,
            $state->countRetries,
            max(self::slot($state->firstAttemptAt, $laterAttempts) ?? 0, $now + self::ATTEMPT_LEASE_SECONDS),
        );
    }

    /** The state after a failed attempt, or null when no attempt follows. */
    public static function afterFailure(RetryState $state, \Throwable $failure, int $now): ?RetryState
    {
        $class = self::classify($failure, $state->laterAttempts === 0);
        if ($class === self::NONE) {
            return null;
        }

        $countRetries = $state->countRetries;
        if ($class === self::COUNT) {
            if ($countRetries >= self::COUNT_CLASS_RETRIES) {
                return null;
            }
            $countRetries++;
        }

        $next = self::slot($state->firstAttemptAt, $state->laterAttempts);
        if ($next === null) {
            return null;
        }
        if ($failure instanceof BeliqApiException && $failure->retryAfterSeconds !== null) {
            $next = max($next, $now + $failure->retryAfterSeconds);
        }
        $next = max($next, $now);

        if ($class === self::COUNT && $next > $state->firstAttemptAt + self::COUNT_CLASS_WINDOW_SECONDS) {
            return null;
        }

        return new RetryState($state->firstAttemptAt, $state->laterAttempts, $countRetries, $next);
    }

    public static function attemptsExhausted(RetryState $state): bool
    {
        return $state->laterAttempts >= count(self::LATER_ATTEMPT_OFFSETS);
    }

    /** What the order shows: the beliq error code, the transport class, or the plugin's own message. */
    public static function describe(\Throwable $failure): string
    {
        if ($failure instanceof BeliqApiException) {
            return $failure->apiCode . ' (HTTP ' . $failure->status . ')';
        }
        if ($failure instanceof BeliqTransportException) {
            return 'connection: ' . $failure->kind;
        }

        return mb_substr($failure->getMessage(), 0, 255);
    }

    /** @return self::WINDOW|self::COUNT|self::NONE */
    private static function classifyStatus(int $status, string $code): string
    {
        if ($status === 429) {
            return $code === self::QUOTA_EXHAUSTED_CODE ? self::NONE : self::COUNT;
        }
        if ($status !== 502 && $status !== 503) {
            return self::NONE;
        }
        // BeliqClient names an answer with no beliq error code HTTP_<status>.
        if ($code === 'HTTP_' . $status) {
            return self::WINDOW;
        }

        return $status === 503 && $code === self::DATABASE_UNAVAILABLE_CODE ? self::WINDOW : self::COUNT;
    }

    /** When the later attempt after $laterAttempts is due, or null when none is left. */
    private static function slot(int $firstAttemptAt, int $laterAttempts): ?int
    {
        $offset = self::LATER_ATTEMPT_OFFSETS[$laterAttempts] ?? null;

        return $offset === null ? null : $firstAttemptAt + $offset;
    }
}
