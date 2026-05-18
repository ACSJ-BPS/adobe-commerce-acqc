<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Version;

readonly class DBSchemaAnalysisResult
{
    public function __construct(
        public array $findings,
        public array $summaryByType,
        public int   $estimatedHoursMin,
        public int   $estimatedHoursMax,
    ) {}

    public function hasBlockers(): bool
    {
        foreach ($this->findings as $f) {
            if (($f['severity'] ?? '') === 'error') {
                return true;
            }
        }
        return false;
    }

    public function totalFindings(): int
    {
        return count($this->findings);
    }
}
