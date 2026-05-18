<?php

declare(strict_types=1);

namespace Vendor\Module\Block;

use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Framework\View\Element\Template;

class ProductBlock extends Template
{
    public function __construct(
        Template\Context $context,
        private readonly DirectoryHelper $directoryHelper,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    public function getFormattedPrice(float $price): string
    {
        // Good: inject helper via constructor, not via magic _helper accessor
        return $this->directoryHelper->currencySymbol() . number_format($price, 2);
    }
}
