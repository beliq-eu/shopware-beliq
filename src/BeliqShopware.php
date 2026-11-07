<?php declare(strict_types=1);

namespace Beliq\Shopware;

use Beliq\Shopware\Retry\OrderFailureFields;
use Beliq\Shopware\Retry\RetryStore;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;

/**
 * Plugin base class. Shopware loads the runtime wiring (the order-state
 * subscriber and services) from Resources/config/services.xml and the merchant
 * settings from Resources/config/config.xml. The lifecycle adds the order's
 * custom field set for a failed automatic invoice, and an uninstall that does
 * not keep user data removes it and the retry table.
 */
class BeliqShopware extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        OrderFailureFields::install($this->customFieldSets(), $installContext->getContext());
    }

    public function update(UpdateContext $updateContext): void
    {
        OrderFailureFields::install($this->customFieldSets(), $updateContext->getContext());
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        if ($uninstallContext->keepUserData()) {
            return;
        }

        OrderFailureFields::remove($this->customFieldSets(), $uninstallContext->getContext());
        $connection = $this->container?->get(Connection::class);
        if ($connection instanceof Connection) {
            $connection->executeStatement('DROP TABLE IF EXISTS `' . RetryStore::TABLE . '`');
        }
    }

    /** @return EntityRepository<\Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetCollection> */
    private function customFieldSets(): EntityRepository
    {
        $repository = $this->container?->get('custom_field_set.repository');
        if (!$repository instanceof EntityRepository) {
            throw new \RuntimeException('custom_field_set.repository is not available.');
        }

        return $repository;
    }
}
