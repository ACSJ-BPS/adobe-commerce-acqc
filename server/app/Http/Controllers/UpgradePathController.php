<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\Yaml\Yaml;

class UpgradePathController extends Controller
{
    private const REGISTRY_PATH = '/../config/versions/adobe-commerce';

    public function show(Request $request, string $from, string $to)
    {
        if (!$this->isValidVersion($from) || !$this->isValidVersion($to)) {
            return response()->json([
                'error' => 'Invalid version format. Expected x.y.z or x.y.z-pN (e.g. 2.4.6, 2.4.7-p3).',
            ], 422);
        }

        if (version_compare($from, $to, '>=')) {
            return response()->json([
                'error' => "Source version ({$from}) must be less than target version ({$to}).",
            ], 422);
        }

        $hops = $this->computeHops($from, $to);

        return response()->json([
            'from'         => $from,
            'to'           => $to,
            'hops'         => $hops,
            'total_hops'   => count($hops),
            'direct_upgrade_possible' => count($hops) === 1,
        ]);
    }

    private function computeHops(string $from, string $to): array
    {
        $registryDir = base_path(self::REGISTRY_PATH);
        $intermediates = $this->resolveIntermediates($from, $to);

        $hops = [];
        $prev = $from;

        foreach ($intermediates as $version) {
            $hops[] = $this->buildHop($prev, $version, $registryDir);
            $prev = $version;
        }

        // Final hop to $to
        $hops[] = $this->buildHop($prev, $to, $registryDir);

        return $hops;
    }

    private function resolveIntermediates(string $from, string $to): array
    {
        [$fromMajor, $fromMinor] = $this->parseMinor($from);
        [$toMajor, $toMinor]    = $this->parseMinor($to);

        if ($fromMajor !== $toMajor) {
            return [];
        }

        $intermediates = [];

        // Rule: if skipping more than one minor version, stop at each intermediate minor
        if ($toMinor - $fromMinor > 1) {
            for ($m = $fromMinor + 1; $m < $toMinor; $m++) {
                $intermediates[] = "{$fromMajor}.{$m}";
            }
        }

        // Rule: source < 2.4.4 must pass through 2.4.4 before going to 2.4.5+
        if ($fromMajor === 2 && $fromMinor < 4 && $toMinor >= 5) {
            array_unshift($intermediates, '2.4.4');
        }

        return array_unique($intermediates);
    }

    private function buildHop(string $from, string $to, string $registryDir): array
    {
        $manifest = $this->loadManifest($to, $registryDir);

        $breakingCount   = count($manifest['breaking_changes'] ?? []);
        $deprecatedCount = $this->countDeprecated($manifest['deprecated_symbols'] ?? []);
        $securityCount   = count($manifest['security_advisories'] ?? []);

        $risk = match (true) {
            $breakingCount >= 10                             => 'high',
            $breakingCount >= 5 || $securityCount > 0       => 'medium',
            default                                          => 'low',
        };

        return [
            'from'              => $from,
            'to'                => $to,
            'risk'              => $risk,
            'breaking_changes'  => $breakingCount,
            'deprecated_symbols'=> $deprecatedCount,
            'security_advisories' => $securityCount,
            'release_date'      => $manifest['release_date'] ?? null,
            'eol_date'          => $manifest['eol_date'] ?? null,
            'php_minimum'       => $manifest['php_support']['minimum'] ?? null,
            'rationale'         => $this->buildRationale($from, $to, $risk, $breakingCount),
        ];
    }

    private function loadManifest(string $version, string $registryDir): array
    {
        $yamlFile = $registryDir . '/' . $version . '.yaml';
        if (!file_exists($yamlFile)) {
            return [];
        }

        try {
            return Yaml::parseFile($yamlFile) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function countDeprecated(array $deprecated): int
    {
        $count = 0;
        foreach (['classes', 'methods', 'events'] as $key) {
            $count += count($deprecated[$key] ?? []);
        }
        return $count;
    }

    private function buildRationale(string $from, string $to, string $risk, int $breaking): string
    {
        return match ($risk) {
            'high'   => "{$from} → {$to}: {$breaking} breaking changes — plan a dedicated sprint for remediation before upgrade.",
            'medium' => "{$from} → {$to}: {$breaking} breaking changes — review deprecated symbols and test thoroughly.",
            default  => "{$from} → {$to}: Low risk upgrade — review release notes and run regression suite.",
        };
    }

    private function parseMinor(string $version): array
    {
        preg_match('/^(\d+)\.(\d+)/', $version, $m);
        return [(int)($m[1] ?? 0), (int)($m[2] ?? 0)];
    }

    private function isValidVersion(string $version): bool
    {
        return (bool) preg_match('/^\d+\.\d+\.\d+(?:-p\d+)?$/', $version);
    }
}
