<?php

declare(strict_types=1);

namespace Vendor\Module\Model;

// Bad: constructor with 8 dependencies on one line triggers the pattern
class OverloadedService
{
    public function __construct(private readonly \Magento\Catalog\Api\ProductRepositoryInterface $a, private readonly \Magento\Sales\Api\OrderRepositoryInterface $b, private readonly \Magento\Customer\Api\CustomerRepositoryInterface $c, private readonly \Psr\Log\LoggerInterface $d, private readonly \Magento\Store\Model\StoreManagerInterface $e, private readonly \Magento\Framework\Event\ManagerInterface $f, private readonly \Magento\Framework\App\CacheInterface $g, private readonly \Magento\Framework\Mail\Template\TransportBuilderInterface $h)
    {
    }
}
