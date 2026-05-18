<?php

declare(strict_types=1);

namespace Vendor\Module\Setup\Patch\Data;

use Magento\Customer\Model\Customer;
use Magento\Customer\Setup\CustomerSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;

class AddCustomAttribute implements DataPatchInterface, PatchRevertableInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly CustomerSetupFactory $customerSetupFactory,
    ) {}

    public function apply(): self
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $customerSetup = $this->customerSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $customerSetup->addAttribute(Customer::ENTITY, 'vendor_subscription_preference', [
            'type'         => 'varchar',
            'label'        => 'Subscription Preference',
            'input'        => 'select',
            'source'       => \Magento\Eav\Model\Entity\Attribute\Source\Boolean::class,
            'required'     => false,
            'default'      => '0',
            'sort_order'   => 200,
            'visible'      => true,
            'user_defined' => true,
            'system'       => false,
        ]);

        // Correct usage: table name resolved via getTable(), not hardcoded
        $tableName = $this->moduleDataSetup->getTable('customer_entity');
        $this->moduleDataSetup->getConnection()->update(
            $tableName,
            ['vendor_subscription_preference' => '0'],
            ['vendor_subscription_preference IS NULL']
        );

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    public function revert(): void
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $customerSetup = $this->customerSetupFactory->create(['setup' => $this->moduleDataSetup]);
        $customerSetup->removeAttribute(Customer::ENTITY, 'vendor_subscription_preference');

        $this->moduleDataSetup->getConnection()->endSetup();
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
