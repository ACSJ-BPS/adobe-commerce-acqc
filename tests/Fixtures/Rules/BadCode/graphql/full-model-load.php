<?php

declare(strict_types=1);

namespace Vendor\Module\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

// Bad: loading model by variable ID inside resolver — potential N+1 per field
class ProductResolver implements ResolverInterface
{
    public function resolve(Field $field, $context, ResolveInfo $info, array $value = null, array $args = null): array
    {
        $productId = $args['id'];
        $product = $this->productFactory->create()->load($productId);
        return ['sku' => $product->getSku(), 'name' => $product->getName()];
    }
}
