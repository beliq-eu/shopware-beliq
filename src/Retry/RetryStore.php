<?php declare(strict_types=1);

namespace Beliq\Shopware\Retry;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The orders whose automatic invoice waits for a later attempt, one row per
 * order (its live version), in the plugin's own table. The scheduled task reads the due rows through
 * the index on next_attempt_at, and a claim is a conditional update, so of two
 * task runs that read the same row only one gets to call beliq.
 */
final class RetryStore
{
    public const TABLE = 'beliq_invoice_retry';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function find(string $orderId): ?RetryState
    {
        $row = $this->connection->fetchAssociative(
            'SELECT first_attempt_at, later_attempts, count_retries, next_attempt_at, not_after FROM ' . self::TABLE . ' WHERE order_id = :id',
            ['id' => Uuid::fromHexToBytes($orderId)],
        );

        return $row === false ? null : self::state($row);
    }

    /** False when the order already has a row, so another attempt is under way. */
    public function start(string $orderId, string $salesChannelId, RetryState $state): bool
    {
        try {
            $this->connection->insert(self::TABLE, [
                'order_id' => Uuid::fromHexToBytes($orderId),
                'order_version_id' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
                'sales_channel_id' => Uuid::fromHexToBytes($salesChannelId),
                'first_attempt_at' => $state->firstAttemptAt,
                'later_attempts' => $state->laterAttempts,
                'count_retries' => $state->countRetries,
                'next_attempt_at' => $state->nextAttemptAt,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /** True when this caller got the attempt: the row still was as $seen and due. */
    public function claim(string $orderId, RetryState $seen, RetryState $claimed, int $now): bool
    {
        return $this->connection->executeStatement(
            'UPDATE ' . self::TABLE . '
             SET later_attempts = :claimedAttempts, next_attempt_at = :next, not_after = :notAfter
             WHERE order_id = :id AND later_attempts = :seenAttempts AND next_attempt_at <= :now',
            [
                'claimedAttempts' => $claimed->laterAttempts,
                'next' => $claimed->nextAttemptAt,
                'notAfter' => $claimed->notAfter,
                'id' => Uuid::fromHexToBytes($orderId),
                'seenAttempts' => $seen->laterAttempts,
                'now' => $now,
            ],
        ) === 1;
    }

    public function reschedule(string $orderId, RetryState $state, string $lastError): void
    {
        $this->connection->update(self::TABLE, [
            'later_attempts' => $state->laterAttempts,
            'count_retries' => $state->countRetries,
            'next_attempt_at' => $state->nextAttemptAt,
            'not_after' => $state->notAfter,
            'last_error' => $lastError,
        ], ['order_id' => Uuid::fromHexToBytes($orderId)]);
    }

    public function delete(string $orderId): void
    {
        $this->connection->delete(self::TABLE, ['order_id' => Uuid::fromHexToBytes($orderId)]);
    }

    /** @return list<array{orderId: string, salesChannelId: string, lastError: ?string, state: RetryState}> */
    public function due(int $now, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT order_id, sales_channel_id, first_attempt_at, later_attempts, count_retries, next_attempt_at, not_after, last_error
             FROM ' . self::TABLE . ' WHERE next_attempt_at <= :now ORDER BY next_attempt_at LIMIT ' . $limit,
            ['now' => $now],
        );

        return array_map(static fn (array $row): array => [
            'orderId' => Uuid::fromBytesToHex((string) $row['order_id']),
            'salesChannelId' => Uuid::fromBytesToHex((string) $row['sales_channel_id']),
            'lastError' => $row['last_error'] === null ? null : (string) $row['last_error'],
            'state' => self::state($row),
        ], $rows);
    }

    /** @param array<string, mixed> $row */
    private static function state(array $row): RetryState
    {
        return new RetryState(
            (int) $row['first_attempt_at'],
            (int) $row['later_attempts'],
            (int) $row['count_retries'],
            (int) $row['next_attempt_at'],
            $row['not_after'] === null ? null : (int) $row['not_after'],
        );
    }
}
