<?php

declare(strict_types=1);

namespace Vendor\Module\Model;

// Bad: file_get_contents with HTTP URL — no timeout, no error handling, blocks request
class RemoteConfig
{
    public function loadConfig(): array
    {
        $json = file_get_contents('https://config.example.com/settings.json');
        return json_decode((string) $json, true) ?? [];
    }
}
