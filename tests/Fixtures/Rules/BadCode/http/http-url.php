<?php

declare(strict_types=1);

namespace Vendor\Module\Model;

// Bad: hardcoded http:// URL — data transmitted unencrypted
class StockService
{
    private const STOCK_API = 'http://erp.example.com/api/stock';

    public function getStock(int $productId): int
    {
        $url      = 'http://pricing.example.com/price/' . $productId;
        $response = file_get_contents($url);
        return (int) json_decode((string) $response, true)['qty'];
    }
}
