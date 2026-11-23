<?php declare(strict_types=1);

namespace Beliq\Shopware\Document;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The invoice numbers beliq documents were issued under, in the plugin's own
 * table, one row per number with the order it was issued for. A merchant can
 * delete a document while the buyer still holds the invoice, so the next number
 * is worked out from these as well as from the documents that remain. The table
 * is the plugin's and not an order custom field, because saving an order in the
 * Administration writes the order's custom fields back as they were when it was
 * opened. A deleted order leaves its numbers in the table.
 */
final class IssuedInvoiceNumbers
{
    public const TABLE = 'beliq_invoice_number';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * The issued numbers of any order that equal the order number or start with
     * `<order number>-`: the numbers InvoiceDocumentLookup::nextNumber counts.
     * Read on each call, because the renderer calls this under the order's lock,
     * after another create may have recorded a number. The column's collation
     * compares without regard to case, as Shopware's duplicate check does.
     *
     * @return list<string>
     */
    public function inOrderNumberForm(string $orderNumber): array
    {
        /** @var list<string> */
        return $this->connection->fetchFirstColumn(
            'SELECT `number` FROM `' . self::TABLE . '` WHERE `number` = :number OR `number` LIKE :prefix ESCAPE \'!\'',
            ['number' => $orderNumber, 'prefix' => str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $orderNumber) . '-%'],
        );
    }

    public function record(string $orderId, string $number): void
    {
        $this->connection->insert(self::TABLE, [
            'number' => $number,
            'order_id' => Uuid::fromHexToBytes($orderId),
        ]);
    }
}
