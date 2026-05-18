<?php

declare(strict_types=1);

namespace TestFixtures\GoodCode\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Example of well-written Magento 2 observer following best practices
 */
class GoodObserver implements ObserverInterface
{
    private ProductRepositoryInterface $productRepository;
    private LoggerInterface $logger;

    public function __construct(
        ProductRepositoryInterface $productRepository,
        LoggerInterface $logger
    ) {
        $this->productRepository = $productRepository;
        $this->logger = $logger;
    }

    public function execute(Observer $observer): void
    {
        try {
            $event = $observer->getEvent();
            $product = $event->getProduct();

            if (!$product || !$product->getId()) {
                $this->logger->warning('Product observer called without valid product');
                return;
            }

            // Lightweight processing only
            $this->processProduct($product);

        } catch (LocalizedException $e) {
            $this->logger->error('Observer processing failed', [
                'error' => $e->getMessage(),
                'product_id' => $product ? $product->getId() : null
            ]);
        }
    }

    private function processProduct($product): void
    {
        // Simple, focused processing
        $this->logger->info('Product processed', ['id' => $product->getId()]);
    }
} 