<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Version\Composer;

use Symfony\Component\Yaml\Yaml;

class SecurityAdvisoryChecker
{
    public function __construct(private readonly string $advisoriesPath) {}

    public function check(array $packages): array
    {
        $advisories = $this->loadAdvisories();
        $findings   = [];

        foreach ($packages as $packageName => $info) {
            $version = $info['version'] ?? '0.0.0';

            foreach ($advisories as $advisory) {
                if (($advisory['package'] ?? '') !== $packageName) {
                    continue;
                }

                foreach ($advisory['affected_versions'] ?? [] as $range) {
                    if (!$this->versionInRange($version, (string)$range)) {
                        continue;
                    }

                    $severity = match (strtolower($advisory['severity'] ?? 'medium')) {
                        'critical', 'high' => 'error',
                        default            => 'warning',
                    };

                    $findings[] = [
                        'type'     => 'composer_security_advisory',
                        'severity' => $severity,
                        'package'  => $packageName,
                        'message'  => sprintf(
                            'Package %s v%s is affected by %s (%s): %s Fixed in: %s',
                            $packageName,
                            $version,
                            $advisory['id'] ?? 'UNKNOWN',
                            $advisory['cve'] ?? 'no CVE',
                            rtrim($advisory['description'] ?? '', '.') . '.',
                            $advisory['fixed_in'] ?? 'unknown',
                        ),
                        'cve'     => $advisory['cve'] ?? null,
                        'advisory_id' => $advisory['id'] ?? null,
                    ];
                    break; // One finding per advisory per package is enough
                }
            }
        }

        return $findings;
    }

    private function loadAdvisories(): array
    {
        if (!is_dir($this->advisoriesPath)) {
            return [];
        }

        $advisories = [];
        $files = glob(rtrim($this->advisoriesPath, '/') . '/*.yaml') ?: [];

        foreach ($files as $file) {
            $data = Yaml::parseFile($file);
            foreach ($data['advisories'] ?? [] as $advisory) {
                $advisories[] = $advisory;
            }
        }

        return $advisories;
    }

    private function versionInRange(string $version, string $range): bool
    {
        // Handle OR — split on " || "
        if (str_contains($range, '||')) {
            foreach (array_map('trim', explode('||', $range)) as $part) {
                if ($this->versionInRange($version, $part)) {
                    return true;
                }
            }
            return false;
        }

        // Split compound constraint on whitespace boundaries between tokens
        $parts = preg_split('/\s+(?=[<>=])/', trim($range)) ?: [trim($range)];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (!$this->satisfiesSingle($version, $part)) {
                return false;
            }
        }

        return true;
    }

    private function satisfiesSingle(string $version, string $constraint): bool
    {
        // Normalise version strings like "2.4.3-p2" for comparison
        $normalise = static function (string $v): string {
            if (preg_match('/^(\d+\.\d+\.\d+)-p(\d+)$/', $v, $m)) {
                return $m[1] . '.' . $m[2];
            }
            return $v;
        };

        $vn = $normalise($version);

        if (str_starts_with($constraint, '>=')) {
            return version_compare($vn, $normalise(substr($constraint, 2)), '>=');
        }
        if (str_starts_with($constraint, '>')) {
            return version_compare($vn, $normalise(substr($constraint, 1)), '>');
        }
        if (str_starts_with($constraint, '<=')) {
            return version_compare($vn, $normalise(substr($constraint, 2)), '<=');
        }
        if (str_starts_with($constraint, '<')) {
            return version_compare($vn, $normalise(substr($constraint, 1)), '<');
        }
        if (str_starts_with($constraint, '!=')) {
            return version_compare($vn, $normalise(substr($constraint, 2)), '!=');
        }
        if (str_starts_with($constraint, '^')) {
            $min   = $normalise(ltrim($constraint, '^'));
            $parts = explode('.', $min);
            $next  = ((int)($parts[0] ?? 0) + 1) . '.0.0';
            return version_compare($vn, $min, '>=') && version_compare($vn, $next, '<');
        }
        if (str_starts_with($constraint, '~')) {
            $min   = $normalise(ltrim($constraint, '~'));
            $parts = explode('.', $min);
            $next  = count($parts) >= 2
                ? $parts[0] . '.' . ((int)$parts[1] + 1) . '.0'
                : ((int)($parts[0] ?? 0) + 1) . '.0.0';
            return version_compare($vn, $min, '>=') && version_compare($vn, $next, '<');
        }

        return version_compare($vn, $normalise($constraint), '==');
    }
}
