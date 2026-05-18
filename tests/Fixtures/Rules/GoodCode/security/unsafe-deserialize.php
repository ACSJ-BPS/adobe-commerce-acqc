<?php

declare(strict_types=1);

namespace Vendor\Module\Model;

// Good: use json_decode — JSON cannot hydrate arbitrary PHP objects, no code execution risk
class DataCache
{
    public function restoreFromCache(string $cached): array
    {
        $data = json_decode($cached, true);
        return is_array($data) ? $data : [];
    }
}
