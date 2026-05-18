<?php

declare(strict_types=1);

namespace Vendor\Module\Model;

// Bad: loading entire collection into memory — no pagination or filters
class ProductReport
{
    public function getProductCount(): int
    {
        $collection = $this->productCollectionFactory->create();
        $collection->load(); // loads ALL products into PHP memory
        return $collection->count();
    }
}
