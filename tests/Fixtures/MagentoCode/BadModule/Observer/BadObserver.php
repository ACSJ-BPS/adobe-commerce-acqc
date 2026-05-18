<?php

namespace TestFixtures\BadCode\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\App\ObjectManager;

/**
 * Example of poorly written Magento 2 observer with violations
 */
class BadObserver implements ObserverInterface
{
    // VIOLATION: Direct ObjectManager usage
    public function execute(Observer $observer)
    {
        $objectManager = ObjectManager::getInstance();
        $productRepository = $objectManager->create('Magento\Catalog\Api\ProductRepositoryInterface');
        
        $event = $observer->getEvent();
        $product = $event->getProduct();
        
        // VIOLATION: No null check
        $productId = $product->getId();
        
        // VIOLATION: Heavy processing in observer
        for ($i = 0; $i < 1000; $i++) {
            $relatedProduct = $productRepository->getById($i);
            // Complex processing that should be in a service class
        }
        
        // VIOLATION: No error handling
        file_put_contents('/tmp/product_log.txt', $productId);
    }
} 