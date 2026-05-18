<?php

declare(strict_types=1);

namespace Vendor\Module\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

// Bad: foreach loop inside resolver — N+1 query pattern
class OrderItemsResolver implements ResolverInterface
{
    public function resolve(Field $field, $context, ResolveInfo $info, array $value = null, array $args = null): array
    {
        $results = [];
        foreach ($value['item_ids'] as $itemId) {
            $item = $this->orderItemRepository->get($itemId);
            $results[] = ['sku' => $item->getSku(), 'qty' => $item->getQtyOrdered()];
        }
        return $results;
    }
}
