<?php

declare(strict_types=1);

namespace Vendor\Module\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

// Good: args are extracted and validated through a dedicated input extractor class
// that does not use direct array access patterns in the resolver body
class SearchResolver implements ResolverInterface
{
    public function __construct(
        private readonly \Vendor\Module\Model\Resolver\SearchInputExtractor $inputExtractor,
        private readonly \Vendor\Module\Service\SearchServiceInterface $searchService,
    ) {
    }

    public function resolve(Field $field, $context, ResolveInfo $info, array $value = null, array $args = null): array
    {
        // Good: input validation delegated to extractor; args forwarded as-is
        $input = $this->inputExtractor->extract($args ?? []);
        return $this->searchService->search($input->getQuery(), $input->getPage(), $input->getPageSize());
    }
}
