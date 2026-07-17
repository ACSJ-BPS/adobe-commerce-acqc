<?php

declare(strict_types=1);

namespace ACQC\Core\Coverage;

/**
 * Parses the plain-text summary produced by PHPUnit's --coverage-text option.
 *
 * Only the "Summary:" block is consulted (Lines/Methods percentages); the
 * format has no branch-level data, so branch coverage is estimated from
 * line coverage via CoverageFallbackTrait.
 */
class PHPUnitTextCoverageParser
{
    use CoverageFallbackTrait;

    /**
     * @return array{line_coverage: float, branch_coverage: float, function_coverage: float}
     */
    public function parse(string $path): array
    {
        try {
            $content = @file_get_contents($path);
            if ($content === false || trim($content) === '') {
                return $this->zeroed();
            }

            $lineCoverage = $this->extractPercentage($content, 'Lines');
            $functionCoverage = $this->extractPercentage($content, 'Methods');
            $branchCoverage = $this->estimateBranchCoverage($lineCoverage);

            return [
                'line_coverage' => $lineCoverage,
                'branch_coverage' => $branchCoverage,
                'function_coverage' => $functionCoverage,
            ];
        } catch (\Throwable) {
            return $this->zeroed();
        }
    }

    private function extractPercentage(string $content, string $label): float
    {
        if (preg_match('/' . preg_quote($label, '/') . ':\s*([\d.]+)%/i', $content, $matches) === 1) {
            return ((float) $matches[1]) / 100;
        }

        return 0.0;
    }
}
