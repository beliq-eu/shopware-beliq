<?php declare(strict_types=1);

namespace Beliq\Shopware\Tests\Support;

use Beliq\Shopware\Document\IssuedInvoiceNumbers;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * A real database for the issued invoice numbers: SQLite in memory, with the
 * columns of the plugin's migration in SQLite's types. NOCASE stands in for the
 * migration's case-insensitive MySQL collation; the migration itself ran on
 * Dockware.
 */
final class NumberDatabase
{
    public const CREATE = 'CREATE TABLE ' . IssuedInvoiceNumbers::TABLE . ' (
        number TEXT COLLATE NOCASE PRIMARY KEY NOT NULL,
        order_id BLOB NOT NULL
    )';

    public static function connect(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(self::CREATE);

        return $connection;
    }
}
