<?php

declare(strict_types=1);

namespace Vendor\Module\Model;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

class DateHelper
{
    public function __construct(private readonly TimezoneInterface $timezone)
    {
    }

    public function getOrderTimestamp(\DateTime $date): int
    {
        // Bad: toTimestamp() is deprecated — use getTimestamp() instead
        return $this->timezone->toTimestamp($date);
    }
}
