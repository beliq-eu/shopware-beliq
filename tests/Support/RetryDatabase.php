<?php declare(strict_types=1);

namespace Beliq\Shopware\Tests\Support;

use Beliq\Shopware\Retry\RetryStore;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * A real database for the retry store: SQLite in a file, so two connections see
 * the same rows the way two task runners see one shop's database. The table has
 * the columns of the plugin's migration, in SQLite's types, without its foreign
 * keys (there is no order table here); the migration itself ran on Dockware.
 */
final class RetryDatabase
{
    public static function create(): string
    {
        $path = sys_get_temp_dir() . '/beliq-retry-' . bin2hex(random_bytes(6)) . '.sqlite';
        self::connect($path)->executeStatement(
            'CREATE TABLE ' . RetryStore::TABLE . ' (
                order_id BLOB PRIMARY KEY NOT NULL,
                order_version_id BLOB NOT NULL,
                sales_channel_id BLOB NOT NULL,
                first_attempt_at INTEGER NOT NULL,
                later_attempts INTEGER NOT NULL,
                count_retries INTEGER NOT NULL,
                next_attempt_at INTEGER NOT NULL,
                not_after INTEGER NULL,
                last_error TEXT NULL
            )',
        );

        return $path;
    }

    public static function connect(string $path): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
    }

    public static function remove(string $path): void
    {
        foreach (glob($path . '*') ?: [] as $file) {
            unlink($file);
        }
    }
}
