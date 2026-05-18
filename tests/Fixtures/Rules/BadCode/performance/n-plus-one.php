<?php

declare(strict_types=1);

namespace Vendor\Module\Model;

// Bad: calling ->load($id) inside a loop — N+1 database queries
class OrderProcessor
{
    public function processOrders(array $orderIds): void
    {
        $orderModel = $this->orderFactory->create();
        foreach ($orderIds as $id) {
            $order = $orderModel->load($id); // N separate SELECT queries
            $this->process($order);
        }
    }
}
