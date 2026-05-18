<?php

declare(strict_types=1);

namespace Vendor\Module\Model;

// Good: use curl_multi to execute requests in parallel
class ProductEnricher
{
    public function enrich(array $productIds): array
    {
        $multiHandle = curl_multi_init();
        $handles     = [];

        foreach ($productIds as $id) {
            $ch = curl_init("https://api.example.com/product/{$id}");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_multi_add_handle($multiHandle, $ch);
            $handles[$id] = $ch;
        }

        do {
            curl_multi_exec($multiHandle, $running);
            curl_multi_select($multiHandle);
        } while ($running > 0);

        $enriched = [];
        foreach ($handles as $id => $ch) {
            $enriched[$id] = json_decode(curl_multi_getcontent($ch), true);
            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }
        curl_multi_close($multiHandle);
        return $enriched;
    }
}
