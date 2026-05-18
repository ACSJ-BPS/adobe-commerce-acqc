<?php

declare(strict_types=1);

namespace ACQC\Core\Export;

readonly class ExportResult
{
    /**
     * @param string[] $writtenFiles Absolute paths of every file that was written.
     */
    public function __construct(
        public string $target,
        public array  $writtenFiles,
        public bool   $isUpToDate,
    ) {}
}
