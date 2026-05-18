<?php

declare(strict_types=1);

namespace Vendor\Module\Model;

use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

class OrderProcessor
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(int $orderId): void
    {
        // Good: use injected repository and logger — fully interceptable via plugins
        $order = $this->orderRepository->get($orderId);
        $this->logger->info('Processing order ' . $orderId);
    }
}
