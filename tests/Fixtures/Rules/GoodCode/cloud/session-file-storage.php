<?php

declare(strict_types=1);

namespace Vendor\Module\Setup;

// Good: Redis session storage configured via Magento — shared across all cloud nodes
class SessionSetup
{
    public function configure(): void
    {
        // Session configuration is handled via .magento.env.yaml:
        // stage:
        //   deploy:
        //     SESSION_CONFIGURATION:
        //       save: redis
        //       redis:
        //         host: redis.internal
        //         port: 6379
        //         database: 0
    }
}
