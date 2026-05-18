<?php

declare(strict_types=1);

namespace Vendor\LegacyModule\Setup;

use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\UpgradeDataInterface;

class UpgradeData implements UpgradeDataInterface
{
    public function upgrade(ModuleDataSetupInterface $setup, ModuleContextInterface $context): void
    {
        $setup->startSetup();

        if (version_compare($context->getVersion(), '1.0.1', '<')) {
            $setup->getConnection()->query(
                "UPDATE {$setup->getTable('vendor_legacy_custom_entity')} SET status = 1 WHERE status IS NULL"
            );
        }

        if (version_compare($context->getVersion(), '1.0.2', '<')) {
            $setup->getConnection()->insertMultiple(
                $setup->getTable('vendor_legacy_custom_entity'),
                [
                    ['name' => 'Default Entity', 'status' => 1],
                    ['name' => 'Secondary Entity', 'status' => 0],
                ]
            );
        }

        $setup->endSetup();
    }
}
