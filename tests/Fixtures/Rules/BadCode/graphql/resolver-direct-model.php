<?php

declare(strict_types=1);

namespace Vendor\Module\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

// Bad: direct model access in resolver — bypasses service contracts
class ProductPriceResolver implements ResolverInterface
{
    public function __construct(
        private readonly \Magento\Catalog\Model\ProductFactory $productModelFactory,
    ) {
    }

    public function resolve(Field $field, $context, ResolveInfo $info, array $value = null, array $args = null): array
    {
        $product = $this->productModelFactory->create()->load($args['id']);
        return ['price' => $this->productModel->getFinalPrice()];
    }
}
