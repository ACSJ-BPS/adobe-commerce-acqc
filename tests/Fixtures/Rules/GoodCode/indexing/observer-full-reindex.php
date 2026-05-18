<?php

declare(strict_types=1);

namespace Vendor\Module\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Indexer\IndexerRegistry;

// Good: reindex only the specific product that changed
class ProductSaveObserver implements ObserverInterface
{
    public function __construct(private readonly IndexerRegistry $indexerRegistry)
    {
    }

    public function execute(Observer $observer): void
    {
        $product = $observer->getEvent()->getProduct();
        $indexer = $this->indexerRegistry->get('catalog_product_price');
        if (!$indexer->isScheduled()) {
            $indexer->executeRow($product->getId());
        }
    }
}
