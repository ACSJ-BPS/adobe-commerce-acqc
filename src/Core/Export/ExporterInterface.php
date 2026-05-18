<?php

declare(strict_types=1);

namespace ACQC\Core\Export;

interface ExporterInterface
{
    public function export(array $rules, string $outputPath, string $profile): ExportResult;

    public function supports(string $target): bool;

    /** Returns true when all output files are already up to date. */
    public function check(array $rules, string $outputPath, string $profile): bool;
}
