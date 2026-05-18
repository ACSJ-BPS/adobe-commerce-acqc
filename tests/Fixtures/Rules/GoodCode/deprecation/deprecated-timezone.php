<?php

declare(strict_types=1);

namespace Vendor\Module\Model;

class DateHelper
{
    public function getOrderTimestamp(\DateTime $date): int
    {
        // Good: use native getTimestamp() method directly
        return $date->getTimestamp();
    }
}
