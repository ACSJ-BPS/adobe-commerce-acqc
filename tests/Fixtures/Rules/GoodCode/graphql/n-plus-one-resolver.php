<?php

declare(strict_types=1);

namespace Vendor\Module\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

// Good: use BatchContractInterface or pass data through parent value — no per-item DB calls
class OrderItemsResolver implements ResolverInterface
{
    public function resolve(Field $field, $context, ResolveInfo $info, array $value = null, array $args = null): array
    {
        // items already loaded by parent order resolver via eager loading
        return array_map(
            static fn($item) => ['sku' => $item->getSku(), 'qty' => $item->getQtyOrdered()],
            $value['items'] ?? []
        );
    }
}
