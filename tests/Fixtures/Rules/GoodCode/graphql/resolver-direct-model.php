<?php

declare(strict_types=1);

namespace Vendor\Module\Model\Resolver;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

// Good: use repository interface — proper service layer, plugin-interceptable
class ProductPriceResolver implements ResolverInterface
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
    ) {
    }

    public function resolve(Field $field, $context, ResolveInfo $info, array $value = null, array $args = null): array
    {
        $product = $this->productRepository->getById((int) $args['id']);
        return ['price' => $product->getFinalPrice()];
    }
}
