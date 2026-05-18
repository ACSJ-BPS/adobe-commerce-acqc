<?php

declare(strict_types=1);

namespace Vendor\Module\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;

class FileReader
{
    public function __construct(private readonly Filesystem $filesystem)
    {
    }

    public function readTemplate(string $templateName): string
    {
        // Good: use filesystem abstraction with validated base directory
        $dir = $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR);
        $safeName = basename($templateName);
        return $dir->readFile('templates/' . $safeName);
    }

    public function saveUpload(string $filename, string $data): void
    {
        $dir = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $dir->writeFile('uploads/' . basename($filename), $data);
    }
}
