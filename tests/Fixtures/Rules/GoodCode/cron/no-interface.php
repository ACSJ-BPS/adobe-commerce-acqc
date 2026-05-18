<?php

declare(strict_types=1);

namespace Vendor\Module\Cron;

// Good: cron class implements an interface, enabling proper DI and testing
class DailyReport implements \Vendor\Module\Cron\CronInterface
{
    public function execute(): void
    {
        // report generation
    }
}
