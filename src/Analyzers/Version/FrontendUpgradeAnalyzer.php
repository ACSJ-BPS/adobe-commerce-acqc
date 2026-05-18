<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Version;

use ACQC\Analyzers\Version\Frontend\LESSVariableAnalyzer;
use ACQC\Analyzers\Version\Frontend\RequireJSMapAnalyzer;
use ACQC\Analyzers\Version\Frontend\TemplateOverrideDetector;

class FrontendUpgradeAnalyzer
{
    public function __construct(
        private readonly TemplateOverrideDetector $templateDetector,
        private readonly RequireJSMapAnalyzer     $requireJSAnalyzer,
        private readonly LESSVariableAnalyzer     $lessAnalyzer,
    ) {}

    public function analyze(string $projectRoot, string $targetVersion): FrontendAnalysisResult
    {
        $templateFindings  = $this->templateDetector->detect($targetVersion);
        $requireJSFindings = $this->requireJSAnalyzer->analyze($targetVersion);
        $lessFindings      = $this->lessAnalyzer->analyze($targetVersion);

        $allFindings = array_merge($templateFindings, $requireJSFindings, $lessFindings);

        $templateOverrideCount = count(array_filter(
            $templateFindings,
            fn($f) => $f['type'] === 'template_override_detected'
        ));

        $requireJSMapCount = count(array_filter(
            $requireJSFindings,
            fn($f) => $f['type'] === 'requirejs_config_detected'
        ));

        $lessVariableCount = (int)array_sum(array_map(
            fn($f) => $f['variable_count'] ?? 0,
            array_filter($lessFindings, fn($f) => $f['type'] === 'less_variables_detected')
        ));

        [$hoursMin, $hoursMax] = $this->estimateEffort($allFindings);

        return new FrontendAnalysisResult(
            findings:              $allFindings,
            templateOverrideCount: $templateOverrideCount,
            requireJSMapCount:     $requireJSMapCount,
            lessVariableCount:     $lessVariableCount,
            estimatedHoursMin:    $hoursMin,
            estimatedHoursMax:    $hoursMax,
        );
    }

    private function estimateEffort(array $findings): array
    {
        $min = 0;
        $max = 0;
        foreach ($findings as $f) {
            $min += (int)($f['effort_hours_min'] ?? 0);
            $max += (int)($f['effort_hours_max'] ?? 0);
        }
        return [$min, $max];
    }
}
