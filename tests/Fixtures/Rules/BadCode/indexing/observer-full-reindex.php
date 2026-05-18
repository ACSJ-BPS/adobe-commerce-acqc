<?php

declare(strict_types=1);

namespace Vendor\Module\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Indexer\IndexerRegistry;

// Bad: triggering full reindex inside event observer
class ProductSaveObserver implements ObserverInterface
{
    public function __construct(private readonly IndexerRegistry $indexerRegistry)
    {
    }

    public function execute(Observer $observer): void
    {
        $indexer = $this->indexerRegistry->get('catalog_product_price');
        $indexer->reindex(); // full reindex on every product save!
    }
}
