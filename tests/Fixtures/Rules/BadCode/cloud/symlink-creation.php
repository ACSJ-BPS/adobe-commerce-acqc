<?php

declare(strict_types=1);

namespace Vendor\Module\Model;

// Bad: creating symlinks at runtime — unreliable in cloud read-only filesystem
class AssetLinker
{
    public function linkAssets(string $source, string $target): void
    {
        symlink($source, $target);
        symlink(BP . '/pub/media/original/' . basename($source), BP . '/pub/media/cache/thumb');
    }
}
