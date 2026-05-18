<?php

declare(strict_types=1);

namespace Vendor\Module\Model;

// Good: HTTPS endpoint — encrypted in transit
class StockService
{
    private const STOCK_API = 'https://erp.example.com/api/stock';

    public function getStock(int $productId): int
    {
        $url      = 'https://pricing.example.com/price/' . $productId;
        $response = file_get_contents($url);
        return (int) json_decode((string) $response, true)['qty'];
    }
}
