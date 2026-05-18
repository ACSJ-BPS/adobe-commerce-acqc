<?php

declare(strict_types=1);

namespace Vendor\Module\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;

// Good: use Magento filesystem API to write in writable var/ directory
class ConfigCache
{
    public function __construct(private readonly Filesystem $filesystem)
    {
    }

    public function save(array $config): void
    {
        $content  = json_encode($config);
        $varWrite = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $varWrite->writeFile('vendor_module/config_cache.json', $content);
    }
}
