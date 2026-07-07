<?php

declare(strict_types=1);

namespace MageWatch\Agent\Setup;

use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;

/**
 * Drops the module's own operational table on `bin/magento module:uninstall`.
 *
 * This never touches catalog/sales/customer data - only the internal
 * magewatch_log_offset bookkeeping table created by this module.
 */
class Uninstall implements UninstallInterface
{
    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context): void
    {
        $setup->startSetup();
        $setup->getConnection()->dropTable($setup->getTable('magewatch_log_offset'));
        $setup->endSetup();
    }
}
