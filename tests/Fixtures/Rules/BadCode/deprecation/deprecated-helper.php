<?php

declare(strict_types=1);

namespace Vendor\Module\Block;

use Magento\Framework\View\Element\Template;

class ProductBlock extends Template
{
    public function getFormattedPrice(float $price): string
    {
        // Bad: _helper magic accessor is deprecated in Magento 2
        return $this->_helper->currency($price, true, true);
    }
}
