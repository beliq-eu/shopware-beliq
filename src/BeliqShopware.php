<?php declare(strict_types=1);

namespace Beliq\Shopware;

use Shopware\Core\Framework\Plugin;

/**
 * Plugin base class. The runtime wiring (order subscriber, document storage,
 * system config) lands in Pass 1b; see ROADMAP.md.
 */
class BeliqShopware extends Plugin
{
}
