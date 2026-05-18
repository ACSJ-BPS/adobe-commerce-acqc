<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Version;

readonly class FrontendAnalysisResult
{
    public function __construct(
        public array $findings,
        public int   $templateOverrideCount,
        public int   $requireJSMapCount,
        public int   $lessVariableCount,
        public int   $estimatedHoursMin,
        public int   $estimatedHoursMax,
    ) {}

    public function hasThemeCustomizations(): bool
    {
        return $this->templateOverrideCount > 0
            || $this->requireJSMapCount > 0
            || $this->lessVariableCount > 0;
    }

    public function totalFindings(): int
    {
        return count($this->findings);
    }
}
