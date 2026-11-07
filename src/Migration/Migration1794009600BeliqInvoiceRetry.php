<?php declare(strict_types=1);

namespace Beliq\Shopware\Migration;

use Beliq\Shopware\Retry\RetryStore;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * The table of orders whose automatic beliq invoice waits for a later attempt
 * (RetryStore). One row per order; the scheduled task reads due rows through the
 * index on next_attempt_at. Times are Unix seconds.
 */
class Migration1794009600BeliqInvoiceRetry extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1794009600;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `' . RetryStore::TABLE . '` (
                `order_id` BINARY(16) NOT NULL,
                `sales_channel_id` BINARY(16) NOT NULL,
                `first_attempt_at` INT UNSIGNED NOT NULL,
                `later_attempts` SMALLINT UNSIGNED NOT NULL,
                `count_retries` SMALLINT UNSIGNED NOT NULL,
                `next_attempt_at` INT UNSIGNED NOT NULL,
                `last_error` VARCHAR(255) NULL,
                PRIMARY KEY (`order_id`),
                KEY `idx.beliq_invoice_retry.next_attempt_at` (`next_attempt_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
