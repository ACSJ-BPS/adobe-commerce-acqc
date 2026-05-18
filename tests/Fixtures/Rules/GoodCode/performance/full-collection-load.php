<?php

declare(strict_types=1);

namespace Vendor\Module\Model;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;

// Good: use getSize() to get count without loading all records
class ProductReport
{
    public function __construct(private readonly CollectionFactory $collectionFactory)
    {
    }

    public function getProductCount(): int
    {
        $collection = $this->collectionFactory->create();
        return $collection->getSize(); // SELECT COUNT(*) — no data loaded
    }
}
