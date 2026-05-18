<?php

declare(strict_types=1);

namespace Vendor\Module\Model;

use Magento\Framework\Indexer\IndexerRegistry;

// Bad: calling ->reindex() triggers a full reindex of all entities
class PriceUpdater
{
    public function __construct(private readonly IndexerRegistry $indexerRegistry)
    {
    }

    public function updateProductPrice(int $productId, float $newPrice): void
    {
        $this->savePriceToDatabase($productId, $newPrice);

        // Bad: full reindex for a single product price change
        $indexer = $this->indexerRegistry->get(\Magento\Catalog\Model\Indexer\Product\Price::INDEXER_ID);
        $indexer->reindex();
    }
}
