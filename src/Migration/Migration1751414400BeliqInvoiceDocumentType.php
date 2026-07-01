<?php declare(strict_types=1);

namespace Beliq\Shopware\Migration;

use Beliq\Shopware\Document\BeliqInvoiceRenderer;
use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Registers the "beliq_invoice" document type so a generated beliq invoice is a
 * first-class Shopware order document: it appears on the order's Documents tab,
 * is downloadable, and is created through DocumentGenerator. Idempotent, so it is
 * safe to re-run and does not clash with an existing type of the same name.
 */
class Migration1751414400BeliqInvoiceDocumentType extends MigrationStep
{
    private const TECHNICAL_NAME = BeliqInvoiceRenderer::TYPE;

    public function getCreationTimestamp(): int
    {
        return 1751414400;
    }

    public function update(Connection $connection): void
    {
        $existing = $connection->fetchOne(
            'SELECT id FROM document_type WHERE technical_name = :name',
            ['name' => self::TECHNICAL_NAME],
        );
        if ($existing !== false) {
            return;
        }

        $now = (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT);
        $typeId = Uuid::randomBytes();

        $connection->insert('document_type', [
            'id' => $typeId,
            'technical_name' => self::TECHNICAL_NAME,
            'created_at' => $now,
        ]);

        $systemLanguageId = Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM);
        $connection->insert('document_type_translation', [
            'document_type_id' => $typeId,
            'language_id' => $systemLanguageId,
            'name' => 'beliq e-invoice',
            'created_at' => $now,
        ]);

        $deDeId = $this->deDeLanguageId($connection);
        if ($deDeId !== null && $deDeId !== $systemLanguageId) {
            $connection->insert('document_type_translation', [
                'document_type_id' => $typeId,
                'language_id' => $deDeId,
                'name' => 'beliq E-Rechnung',
                'created_at' => $now,
            ]);
        }

        $configId = Uuid::randomBytes();
        $connection->insert('document_base_config', [
            'id' => $configId,
            'name' => self::TECHNICAL_NAME,
            'global' => 1,
            'filename_prefix' => self::TECHNICAL_NAME . '_',
            'document_type_id' => $typeId,
            'config' => '{}',
            'created_at' => $now,
        ]);

        $connection->insert('document_base_config_sales_channel', [
            'id' => Uuid::randomBytes(),
            'document_base_config_id' => $configId,
            'document_type_id' => $typeId,
            'created_at' => $now,
        ]);
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function deDeLanguageId(Connection $connection): ?string
    {
        $id = $connection->fetchOne(
            'SELECT `language`.id FROM `language`
             INNER JOIN locale ON locale.id = `language`.locale_id
             WHERE locale.code = :code',
            ['code' => 'de-DE'],
        );

        return $id === false ? null : $id;
    }
}
