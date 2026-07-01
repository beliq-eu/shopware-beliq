<?php declare(strict_types=1);

namespace Beliq\Shopware;

use Shopware\Core\Framework\Plugin;

/**
 * Plugin base class. Shopware loads the runtime wiring (the order-state
 * subscriber and services) from Resources/config/services.xml and the merchant
 * settings from Resources/config/config.xml.
 */
class BeliqShopware extends Plugin
{
}
