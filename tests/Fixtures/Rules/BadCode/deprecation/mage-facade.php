<?php

declare(strict_types=1);

namespace Vendor\Module\Model;

// Bad: using Mage facade from Magento 1 — not available in Magento 2
class LegacyProductLoader
{
    public function getProduct(int $id): array
    {
        $product = Mage::getModel('catalog/product')->load($id);
        $store   = Mage::app()->getStore();
        $config  = Mage::getStoreConfig('general/store_information/name');
        return ['sku' => $product->getSku(), 'store' => $config];
    }
}
