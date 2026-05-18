<?php

declare(strict_types=1);

namespace Vendor\Module\Model;

class FileReader
{
    public function readTemplate(string $templateName): string
    {
        // Bad: user-controlled path passed directly to filesystem function
        $content = file_get_contents($templateName);
        fopen($templateName, 'r');
        return $content ?: '';
    }

    public function saveUpload(string $filename, string $data): void
    {
        file_put_contents($filename, $data);
    }
}
