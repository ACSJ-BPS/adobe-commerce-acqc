<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Version;

use ACQC\Analyzers\Version\Composer\AbandonedPackageDetector;
use ACQC\Analyzers\Version\Composer\PlatformConstraintValidator;
use ACQC\Analyzers\Version\Composer\SecurityAdvisoryChecker;
use ACQC\Analyzers\Version\Composer\TransitiveDependencyResolver;

class ComposerDeepAnalyzer
{
    public function __construct(
        private readonly TransitiveDependencyResolver $transitiveDeps,
        private readonly AbandonedPackageDetector     $abandonedDetector,
        private readonly SecurityAdvisoryChecker      $securityChecker,
        private readonly PlatformConstraintValidator  $platformValidator,
    ) {}

    public function analyze(string $projectRoot, string $targetVersion): ComposerAnalysisResult
    {
        $packages  = $this->transitiveDeps->resolve();
        $findings  = [];

        $compatFindings = $this->transitiveDeps->checkCompatibility($packages, $targetVersion);
        $findings       = array_merge($findings, $compatFindings);

        $abandonedFindings = $this->abandonedDetector->detectAbandoned($packages);
        $findings          = array_merge($findings, $abandonedFindings);

        $securityFindings = $this->securityChecker->check($packages);
        $findings         = array_merge($findings, $securityFindings);

        $platformFindings = $this->platformValidator->validate($projectRoot, $targetVersion);
        $findings         = array_merge($findings, $platformFindings);

        $directCount     = count(array_filter($packages, fn(array $p) => $p['direct']));
        $transitiveCount = count($packages) - $directCount;
        $abandonedCount  = count($abandonedFindings);
        $secCount        = count($securityFindings);

        [$hoursMin, $hoursMax] = $this->estimateEffort($findings);

        return new ComposerAnalysisResult(
            findings: $findings,
            packageCount: $directCount,
            transitivePackageCount: $transitiveCount,
            abandonedCount: $abandonedCount,
            securityAdvisoryCount: $secCount,
            estimatedHoursMin: $hoursMin,
            estimatedHoursMax: $hoursMax,
        );
    }

    private function estimateEffort(array $findings): array
    {
        $min = 0;
        $max = 0;
        foreach ($findings as $f) {
            $min += (int)($f['effort_hours_min'] ?? 1);
            $max += (int)($f['effort_hours_max'] ?? 4);
        }
        return [$min, $max];
    }
}
