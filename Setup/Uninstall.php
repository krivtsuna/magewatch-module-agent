<?php

declare(strict_types=1);

namespace MageWatch\Agent\Setup;

use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;

/**
 * Drops the module's own operational tables on `bin/magento module:uninstall`.
 *
 * This never touches catalog/sales/customer data - only the internal
 * bookkeeping tables created by this module.
 */
class Uninstall implements UninstallInterface
{
    private const TABLES = [
        'magewatch_log_offset',
        'magewatch_order_attribution',
    ];

    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context): void
    {
        $setup->startSetup();

        foreach (self::TABLES as $table) {
            $setup->getConnection()->dropTable($setup->getTable($table));
        }

        $setup->endSetup();
    }
}
