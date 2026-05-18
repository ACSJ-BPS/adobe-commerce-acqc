<?php

declare(strict_types=1);

namespace Vendor\Module\Cron;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;

class ProductExport implements \Vendor\Module\Cron\CronInterface
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
    ) {
    }

    public function execute(): void
    {
        $criteria = $this->searchCriteriaBuilder->create();
        $products = $this->productRepository->getList($criteria)->getItems();

        // Bad: foreach inside cron processes unlimited products synchronously
        foreach ($products as $product) {
            $this->exportProduct($product);
        }
    }

    private function exportProduct(\Magento\Catalog\Api\Data\ProductInterface $product): void
    {
        // heavy processing per product
    }
}
