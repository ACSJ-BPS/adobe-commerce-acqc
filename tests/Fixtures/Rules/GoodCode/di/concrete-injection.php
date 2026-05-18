<?php

declare(strict_types=1);

namespace Vendor\Module\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;

class ProductCustomizer
{
    public function __construct(
        // Good: inject repository interfaces, not concrete model classes
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CustomerRepositoryInterface $customerRepository,
    ) {
    }

    public function customize(int $productId, int $customerId): void
    {
        $product  = $this->productRepository->getById($productId);
        $customer = $this->customerRepository->getById($customerId);
    }
}
