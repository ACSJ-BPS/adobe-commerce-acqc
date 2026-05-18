<?php

declare(strict_types=1);

namespace Vendor\Module\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

// Bad: $args[] accessed directly without type checking or validation
class SearchResolver implements ResolverInterface
{
    public function resolve(Field $field, $context, ResolveInfo $info, array $value = null, array $args = null): array
    {
        $query    = $args['search'];
        $page     = $args['currentPage'];
        $pageSize = $args['pageSize'];
        return $this->searchService->search($query, $page, $pageSize);
    }
}
