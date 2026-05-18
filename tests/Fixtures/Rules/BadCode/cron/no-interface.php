<?php

declare(strict_types=1);

namespace Vendor\Module\Cron;

// Bad: cron class without implementing a cron interface — no contract enforced
class DailyReport {
    public function execute(): void
    {
        // report generation
    }
}
