<?php declare(strict_types=1);

namespace Beliq\Shopware\Tests;

use Beliq\Shopware\Document\IssuedInvoiceNumbers;
use Beliq\Shopware\Migration\Migration1795392000BeliqInvoiceNumber;
use Beliq\Shopware\Tests\Support\NumberDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The issued invoice numbers, against a real (SQLite) table: which numbers a new
 * document on an order has to count, of any order.
 */
final class IssuedInvoiceNumbersTest extends TestCase
{
    public function testNumbersInTheOrderNumbersFormsCountFromEveryOrder(): void
    {
        $numbers = new IssuedInvoiceNumbers(NumberDatabase::connect());
        $numbers->record(Uuid::randomHex(), '10000');
        $numbers->record(Uuid::randomHex(), '10000-2');
        // Order A-1 issued A-1-2; order A-1-2 has to count it for its own first number.
        $numbers->record(Uuid::randomHex(), 'A-1-2');
        $numbers->record(Uuid::randomHex(), '100000');
        $numbers->record(Uuid::randomHex(), '1000-5');

        self::assertSame(['10000', '10000-2'], self::sorted($numbers->inOrderNumberForm('10000')));
        self::assertSame(['A-1-2'], $numbers->inOrderNumberForm('A-1-2'));
        self::assertSame(['1000-5'], $numbers->inOrderNumberForm('1000'));
        self::assertSame([], $numbers->inOrderNumberForm('100'));
    }

    public function testLikeWildcardsInTheOrderNumberMatchLiterally(): void
    {
        $numbers = new IssuedInvoiceNumbers(NumberDatabase::connect());
        $numbers->record(Uuid::randomHex(), 'SWx1-2');
        $numbers->record(Uuid::randomHex(), 'SW%1-2');
        $numbers->record(Uuid::randomHex(), 'SW!1-2');

        self::assertSame([], $numbers->inOrderNumberForm('SW_1'));
        self::assertSame(['SW%1-2'], $numbers->inOrderNumberForm('SW%1'));
        self::assertSame(['SW!1-2'], $numbers->inOrderNumberForm('SW!1'));
    }

    public function testNumbersCompareWithoutRegardToCase(): void
    {
        $numbers = new IssuedInvoiceNumbers(NumberDatabase::connect());
        $numbers->record(Uuid::randomHex(), 'sw-1-2');

        self::assertSame(['sw-1-2'], $numbers->inOrderNumberForm('SW-1'));
        $this->expectException(UniqueConstraintViolationException::class);
        $numbers->record(Uuid::randomHex(), 'SW-1-2');
    }

    /** The SQLite table the tests use must have the migration's columns and key. */
    public function testTheTestDatabaseHasTheMigrationsColumns(): void
    {
        $statements = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(static function (string $statement) use (&$statements): int {
            $statements[] = $statement;

            return 0;
        });
        (new Migration1795392000BeliqInvoiceNumber())->update($connection);
        $sql = $statements[0];

        preg_match_all('/^\s+`(\w+)`\s+[A-Z]/m', $sql, $match);
        $columns = array_column(NumberDatabase::connect()->fetchAllAssociative('PRAGMA table_info(' . IssuedInvoiceNumbers::TABLE . ')'), 'name');

        self::assertSame($match[1], $columns);
        self::assertStringContainsString('PRIMARY KEY (`number`)', $sql);
        self::assertStringContainsString('COLLATE=utf8mb4_unicode_ci', $sql);
        self::assertStringNotContainsString('FOREIGN KEY', $sql);
    }

    /**
     * @param list<string> $numbers
     *
     * @return list<string>
     */
    private static function sorted(array $numbers): array
    {
        sort($numbers);

        return $numbers;
    }
}
