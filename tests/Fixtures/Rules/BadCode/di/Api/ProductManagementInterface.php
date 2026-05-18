<?php

declare(strict_types=1);

namespace Vendor\Module\Api;

// Bad: public API interface in Api/ without @api annotation
// Other modules cannot safely depend on this interface for Magento marketplace compliance
interface ProductManagementInterface
{
    public function updatePrice(int $productId, float $price): bool;

    public function getStock(int $productId): int;
}
