<?php

declare(strict_types=1);

namespace Vendor\Module\Model;

use Magento\Framework\Indexer\IndexerRegistry;

// Good: use executeRow() to reindex only the changed product
class PriceUpdater
{
    public function __construct(private readonly IndexerRegistry $indexerRegistry)
    {
    }

    public function updateProductPrice(int $productId, float $newPrice): void
    {
        $this->savePriceToDatabase($productId, $newPrice);

        $indexer = $this->indexerRegistry->get(\Magento\Catalog\Model\Indexer\Product\Price::INDEXER_ID);
        if (!$indexer->isScheduled()) {
            $indexer->executeRow($productId);
        }
    }
}
