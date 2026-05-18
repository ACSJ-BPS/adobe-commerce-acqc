<?php

declare(strict_types=1);

namespace ACQC\Core\Version;

class UpgradePathPlanner
{
    public function __construct(private readonly VersionRegistry $registry) {}

    public function plan(string $source, string $target): UpgradePath
    {
        $hops = $this->computeHops($source, $target);
        $isDirect = count($hops) === 1; // only target itself

        $breakingChanges = $this->countBreakingChanges($source, $hops);
        $duration = $this->estimateDuration($breakingChanges);

        return new UpgradePath(
            source: $source,
            target: $target,
            hops: $hops,
            isDirect: $isDirect,
            rationale: $this->buildRationale($source, $target, $hops, $isDirect),
            prerequisites: [],
            totalBreakingChanges: $breakingChanges,
            estimatedDuration: $duration,
        );
    }

    private function computeHops(string $source, string $target): array
    {
        $hops = [];
        $distance = $this->minorVersionDistance($source, $target);

        if ($distance <= 2) {
            // Check if Elasticsearch stop is required even on short path
            if ($this->elasticsearchStopRequired($source, $target)) {
                $stop246 = $this->registry->getLatestPatchFor('2.4.6');
                if ($this->registry->compareVersions($stop246, $source) > 0
                    && $this->registry->compareVersions($stop246, $target) < 0
                ) {
                    $hops[] = $stop246;
                }
            }
            $hops[] = $target;
            return $hops;
        }

        // Multi-hop: determine intermediate minor versions to stop at
        $intermediates = $this->determineIntermediateMinors($source, $target);

        foreach ($intermediates as $minor) {
            $latestPatch = $this->registry->getLatestPatchFor($minor);
            $hops[] = $latestPatch;
        }

        $hops[] = $target;

        return $hops;
    }

    private function determineIntermediateMinors(string $source, string $target): array
    {
        $sourceMinor = $this->extractMinorVersion($source);
        $targetMinor = $this->extractMinorVersion($target);
        $intermediates = [];

        // Rule 3: if source < 2.4.4 and target >= 2.4.5, must include 2.4.5
        if ($sourceMinor < 4 && $targetMinor >= 5) {
            $intermediates[] = '2.4.5';
        }

        // Rule 4: Elasticsearch → OpenSearch transition stop at 2.4.6
        if ($this->elasticsearchStopRequired($source, $target)) {
            $intermediates[] = '2.4.6';
        }

        // Rule 1/2: insert stops at even minor versions when distance > 2
        $distance = abs($targetMinor - $sourceMinor);
        if ($distance > 2) {
            for ($m = $sourceMinor + 2; $m < $targetMinor; $m += 2) {
                $candidateMinor = '2.4.' . $m;
                if (!in_array($candidateMinor, $intermediates, true)) {
                    $intermediates[] = $candidateMinor;
                }
            }
        }

        // Deduplicate and sort by minor version number ascending
        $intermediates = array_unique($intermediates);
        usort($intermediates, fn(string $a, string $b) => version_compare($a, $b));

        return $intermediates;
    }

    private function elasticsearchStopRequired(string $source, string $target): bool
    {
        // Source must be BELOW 2.4.6 (i.e., still using Elasticsearch without OpenSearch configured)
        // AND target must be >= 2.4.7 (where Elasticsearch was dropped).
        // If source IS 2.4.6, it is already the transition point — no extra stop needed.
        return $this->extractMinorVersion($source) < 6
            && $this->extractMinorVersion($target) >= 7;
    }

    private function countBreakingChanges(string $source, array $hops): int
    {
        $total = 0;
        $from = $source;

        foreach ($hops as $hop) {
            try {
                $changes = $this->registry->getBreakingChanges($from, $hop);
                $total += count($changes);
            } catch (\Throwable) {
                // If manifest missing (e.g., 2.4.4 not in our registry), skip
            }
            $from = $hop;
        }

        return $total;
    }

    private function estimateDuration(int $breakingChanges): string
    {
        return match (true) {
            $breakingChanges < 5 => '4–6 weeks',
            $breakingChanges < 16 => '8–14 weeks',
            $breakingChanges < 31 => '14–22 weeks',
            default => '22–36 weeks',
        };
    }

    private function buildRationale(string $source, string $target, array $hops, bool $isDirect): string
    {
        if ($isDirect) {
            return "Direct upgrade from {$source} to {$target} is recommended (within 2 minor versions).";
        }

        $lines = ["Direct {$source}→{$target} spans multiple minor versions. Intermediate stops required."];
        $intermediates = array_slice($hops, 0, -1); // everything except target
        foreach ($intermediates as $i => $hop) {
            $stopNum = $i + 1;
            $minor = $this->extractMinorVersion($hop);

            if ($minor === 5) {
                $lines[] = "Stop {$stopNum} at {$hop}: complete declarative schema migration.";
            } elseif ($minor === 6) {
                $lines[] = "Stop {$stopNum} at {$hop}: set up OpenSearch alongside Elasticsearch before ES is dropped in 2.4.7.";
            } else {
                $lines[] = "Stop {$stopNum} at {$hop}: stabilise and test at intermediate version.";
            }
        }

        return implode("\n", $lines);
    }

    public function minorVersionDistance(string $a, string $b): int
    {
        return abs($this->extractMinorVersion($a) - $this->extractMinorVersion($b));
    }

    private function extractMinorVersion(string $version): int
    {
        // Handles "2.4.7", "2.4.7-p1", "2.4.6-p2"
        if (preg_match('/^2\.4\.(\d+)/', $version, $m)) {
            return (int)$m[1];
        }

        return 0;
    }
}
