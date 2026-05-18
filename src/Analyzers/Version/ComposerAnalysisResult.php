<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Version;

readonly class ComposerAnalysisResult
{
    public function __construct(
        public array $findings,
        public int   $packageCount,
        public int   $transitivePackageCount,
        public int   $abandonedCount,
        public int   $securityAdvisoryCount,
        public int   $estimatedHoursMin,
        public int   $estimatedHoursMax,
    ) {}

    public function totalFindings(): int
    {
        return count($this->findings);
    }

    public function hasBlockers(): bool
    {
        foreach ($this->findings as $f) {
            if (($f['severity'] ?? '') === 'error') {
                return true;
            }
        }
        return false;
    }
}
