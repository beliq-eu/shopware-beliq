<?php declare(strict_types=1);

namespace Beliq\Shopware\Migration;

use Beliq\Shopware\Document\BeliqInvoiceRenderer;
use Beliq\Shopware\Document\IssuedInvoiceNumbers;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * The table of invoice numbers beliq documents were issued under
 * (IssuedInvoiceNumbers). The number is the key, so the table refuses a number
 * issued twice. The key's collation compares without regard to case, as
 * Shopware's duplicate check on document numbers does. There is no foreign key
 * to the order: a deleted order's numbers stay issued. The numbers of the beliq
 * documents stored at install are copied in, so deleting one of those later does
 * not free its number.
 */
class Migration1795392000BeliqInvoiceNumber extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1795392000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `' . IssuedInvoiceNumbers::TABLE . '` (
                `number` VARCHAR(255) NOT NULL,
                `order_id` BINARY(16) NOT NULL,
                PRIMARY KEY (`number`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $connection->executeStatement('
            INSERT IGNORE INTO `' . IssuedInvoiceNumbers::TABLE . '` (`number`, `order_id`)
            SELECT `document`.`document_number`, `document`.`order_id`
            FROM `document`
            INNER JOIN `document_type` ON `document_type`.`id` = `document`.`document_type_id`
            WHERE `document_type`.`technical_name` = :type AND `document`.`document_number` IS NOT NULL
        ', ['type' => BeliqInvoiceRenderer::TYPE]);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
