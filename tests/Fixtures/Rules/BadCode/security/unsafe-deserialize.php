<?php

declare(strict_types=1);

namespace Vendor\Module\Model;

class DataCache
{
    public function restoreFromCache(string $cached): array
    {
        // Bad: unserialize() with untrusted data can execute arbitrary code via __wakeup
        $data = unserialize($cached);
        return is_array($data) ? $data : [];
    }
}
