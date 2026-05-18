<?php

declare(strict_types=1);

namespace Vendor\Module\Model;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\FilterBuilder;

// Good: bulk load orders in a single query using repository getList
class OrderProcessor
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly FilterBuilder $filterBuilder,
    ) {
    }

    public function processOrders(array $orderIds): void
    {
        $filter = $this->filterBuilder
            ->setField('entity_id')
            ->setConditionType('in')
            ->setValue($orderIds)
            ->create();
        $criteria = $this->searchCriteriaBuilder->addFilters([$filter])->create();
        $orders   = $this->orderRepository->getList($criteria)->getItems();
        foreach ($orders as $order) {
            $this->process($order);
        }
    }
}
