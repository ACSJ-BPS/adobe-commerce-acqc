<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Version;

readonly class CloudCompatibilityResult
{
    public function __construct(
        public bool    $isCloud,
        public ?string $detectedPhpVersion,
        public ?string $eceToolsVersion,
        public ?string $configuredSearchEngine,
        public array   $findings,
    ) {}

    public static function notCloud(): self
    {
        return new self(
            isCloud: false,
            detectedPhpVersion: null,
            eceToolsVersion: null,
            configuredSearchEngine: null,
            findings: [],
        );
    }

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
