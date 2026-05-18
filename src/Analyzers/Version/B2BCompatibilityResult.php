<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Version;

readonly class B2BCompatibilityResult
{
    public function __construct(
        public bool   $installed,
        public string $installedVersion,
        public array  $findings,
    ) {}

    public static function notInstalled(): self
    {
        return new self(installed: false, installedVersion: '', findings: []);
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
