<?php

declare(strict_types=1);

namespace Vendor\Module\Model;

// Bad: multiple sequential curl_exec calls block the request until all complete
class ProductEnricher
{
    public function enrich(array $productIds): array
    {
        $enriched = [];
        foreach ($productIds as $id) {
            $ch = curl_init("https://api.example.com/product/{$id}");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $data = curl_exec($ch);
            curl_close($ch);
            $enriched[$id] = json_decode((string) $data, true);
        }
        return $enriched;
    }
}
