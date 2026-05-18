<?php

declare(strict_types=1);

namespace Vendor\Module\Setup;

// Bad: configuring file-based session storage — breaks on multi-node cloud deployments
class SessionSetup
{
    public function configure(): void
    {
        // Builds PHP ini override string that forces file-based sessions
        $iniConfig = "session.save_handler = 'files'\nsession.save_path = '/tmp/sessions'";
        $this->applyIniConfig($iniConfig);
    }

    private function applyIniConfig(string $config): void
    {
        foreach (explode("\n", $config) as $line) {
            [$key, $value] = explode('=', $line, 2);
            ini_set(trim($key), trim($value, " '\"\t"));
        }
    }
}
