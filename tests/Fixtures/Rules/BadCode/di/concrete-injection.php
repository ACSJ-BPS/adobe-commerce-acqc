<?php

declare(strict_types=1);

namespace Vendor\Module\Model;

class ProductCustomizer
{
    public function __construct(
        // Bad: injecting concrete Model classes instead of repository interfaces
        private readonly \Magento\Catalog\Model\Product $productModel,
        private readonly \Magento\Customer\Model\Customer $customerModel,
    ) {
    }

    public function customize(int $productId, int $customerId): void
    {
        $product  = $this->productModel->load($productId);
        $customer = $this->customerModel->load($customerId);
    }
}
