<?php

declare(strict_types=1);

namespace Vendor\Module\Model;

// Bad: writing files relative to BP — fails in cloud read-only filesystem
class ConfigCache
{
    public function save(array $config): void
    {
        $content = json_encode($config);
        file_put_contents(BP . '/app/etc/vendor_config_cache.json', $content);
        file_put_contents(__DIR__ . '/../data/cache.json', $content);
        file_put_contents(dirname(__FILE__) . '/runtime.json', $content);
    }
}
