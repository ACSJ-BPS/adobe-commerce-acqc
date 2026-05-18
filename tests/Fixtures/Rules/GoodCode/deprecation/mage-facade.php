<?php

declare(strict_types=1);

namespace Vendor\Module\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;

// Good: proper Magento 2 DI — no Mage facade usage
class ModernProductLoader
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    public function getProduct(int $id): array
    {
        $product = $this->productRepository->getById($id);
        $storeName = $this->scopeConfig->getValue('general/store_information/name');
        return ['sku' => $product->getSku(), 'store' => $storeName];
    }
}
