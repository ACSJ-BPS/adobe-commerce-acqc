<?php

declare(strict_types=1);

namespace Vendor\Module\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

// Good: constructor with 3 focused dependencies — single responsibility
class FocusedService
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LoggerInterface $logger,
    ) {
    }
}
