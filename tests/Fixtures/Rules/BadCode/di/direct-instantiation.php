<?php

declare(strict_types=1);

namespace Vendor\Module\Model;

class OrderProcessor
{
    public function process(int $orderId): void
    {
        // Bad: instantiating Magento classes directly bypasses DI and plugin interception
        $order = new \Magento\Sales\Model\Order();
        $order->load($orderId);

        $logger = new \Magento\Framework\Logger\Monolog('vendor');
        $logger->info('Processing order ' . $orderId);
    }
}
