<?php

declare(strict_types=1);

namespace ACQC\Core\Coverage;

/**
 * Shared fallback helpers for coverage parsers.
 *
 * Neither PHPUnit's --coverage-text output nor Clover XML expose a direct
 * branch-coverage metric, so both parsers derive an estimate from line
 * coverage using the same discount factor ReportCommand already applies
 * to its own heuristic coverage fallback.
 */
trait CoverageFallbackTrait
{
    private function estimateBranchCoverage(float $lineCoverage): float
    {
        return $lineCoverage * 0.85;
    }

    /**
     * @return array{line_coverage: float, branch_coverage: float, function_coverage: float}
     */
    private function zeroed(): array
    {
        return [
            'line_coverage' => 0.0,
            'branch_coverage' => 0.0,
            'function_coverage' => 0.0,
        ];
    }
}
