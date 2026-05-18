<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Version\Composer;

class TransitiveDependencyResolver
{
    private const FRAMEWORK_VERSION_MAP = [
        '2.4.5'    => '102.0.0',
        '2.4.5-p1' => '102.0.0',
        '2.4.5-p2' => '102.0.0',
        '2.4.5-p3' => '102.0.0',
        '2.4.6'    => '102.0.8',
        '2.4.6-p1' => '102.0.8',
        '2.4.6-p2' => '102.0.8',
        '2.4.7'    => '103.0.7',
        '2.4.7-p1' => '103.0.7',
        '2.4.7-p2' => '103.0.7',
        '2.4.8'    => '103.0.8',
        '2.4.8-p1' => '103.0.8',
        '2.4.8-p2' => '103.0.8',
    ];

    public function __construct(private readonly string $projectRoot) {}

    public function resolve(): array
    {
        $lockFile = $this->projectRoot . '/composer.lock';
        $jsonFile = $this->projectRoot . '/composer.json';

        if (!file_exists($lockFile)) {
            return [];
        }

        $lock = json_decode((string)file_get_contents($lockFile), true) ?? [];
        $directRequires = [];

        if (file_exists($jsonFile)) {
            $json = json_decode((string)file_get_contents($jsonFile), true) ?? [];
            $directRequires = array_keys($json['require'] ?? []);
        }

        $packages = [];
        foreach ($lock['packages'] ?? [] as $pkg) {
            $name = $pkg['name'] ?? '';
            if ($name === '') {
                continue;
            }
            $packages[$name] = [
                'version'  => ltrim($pkg['version'] ?? '0.0.0', 'v'),
                'direct'   => in_array($name, $directRequires, true),
                'requires' => array_keys($pkg['require'] ?? []),
            ];
        }

        foreach ($lock['packages-dev'] ?? [] as $pkg) {
            $name = $pkg['name'] ?? '';
            if ($name === '' || isset($packages[$name])) {
                continue;
            }
            $packages[$name] = [
                'version'  => ltrim($pkg['version'] ?? '0.0.0', 'v'),
                'direct'   => in_array($name, $directRequires, true),
                'requires' => array_keys($pkg['require'] ?? []),
            ];
        }

        return $packages;
    }

    public function checkCompatibility(array $packages, string $targetAcVersion): array
    {
        $frameworkVersion = self::FRAMEWORK_VERSION_MAP[$targetAcVersion]
            ?? self::FRAMEWORK_VERSION_MAP[preg_replace('/-p\d+$/', '', $targetAcVersion)]
            ?? null;

        if ($frameworkVersion === null) {
            return [];
        }

        $findings = [];
        $frameworkPackages = ['magento/framework', 'magento/magento2-base'];

        foreach ($packages as $name => $info) {
            $requires = $info['requires'] ?? [];
            foreach ($frameworkPackages as $fwPackage) {
                if (!in_array($fwPackage, $requires, true)) {
                    continue;
                }

                // The constraint is stored in the lock file's require map — we only have keys here.
                // Re-read from lock to get the actual constraint value.
                $constraint = $this->readConstraintFromLock($name, $fwPackage);
                if ($constraint === null) {
                    continue;
                }

                if (!$this->satisfiesConstraint($frameworkVersion, $constraint)) {
                    $severity = $info['direct'] ? 'error' : 'warning';
                    $findings[] = [
                        'type'     => 'composer_transitive_conflict',
                        'severity' => $severity,
                        'package'  => $name,
                        'message'  => sprintf(
                            'Package %s v%s requires %s %s which may not be compatible with AC %s.',
                            $name,
                            $info['version'],
                            $fwPackage,
                            $constraint,
                            $targetAcVersion,
                        ),
                    ];
                }
            }
        }

        return $findings;
    }

    private function readConstraintFromLock(string $packageName, string $requireKey): ?string
    {
        $lockFile = $this->projectRoot . '/composer.lock';
        if (!file_exists($lockFile)) {
            return null;
        }

        $lock = json_decode((string)file_get_contents($lockFile), true) ?? [];
        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $pkg) {
            if (($pkg['name'] ?? '') === $packageName) {
                return $pkg['require'][$requireKey] ?? null;
            }
        }

        return null;
    }

    private function satisfiesConstraint(string $version, string $constraint): bool
    {
        // Handle OR constraints
        if (str_contains($constraint, '||')) {
            foreach (array_map('trim', explode('||', $constraint)) as $part) {
                if ($this->satisfiesConstraint($version, $part)) {
                    return true;
                }
            }
            return false;
        }

        // Handle AND constraints (space or comma separated)
        $parts = preg_split('/\s*,\s*|\s+(?=[<>=~^])/', trim($constraint)) ?: [trim($constraint)];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || $part === '*') {
                continue;
            }
            if (!$this->satisfiesSingleConstraint($version, $part)) {
                return false;
            }
        }

        return true;
    }

    private function satisfiesSingleConstraint(string $version, string $constraint): bool
    {
        if ($constraint === '*' || $constraint === '') {
            return true;
        }

        if (str_starts_with($constraint, '^')) {
            $min   = ltrim($constraint, '^');
            $parts = explode('.', $min);
            $major = (int)($parts[0] ?? 0);
            $next  = ($major + 1) . '.0.0';
            return version_compare($version, $min, '>=') && version_compare($version, $next, '<');
        }

        if (str_starts_with($constraint, '~')) {
            $min   = ltrim($constraint, '~');
            $parts = explode('.', $min);
            if (count($parts) >= 2) {
                $next = $parts[0] . '.' . ((int)$parts[1] + 1) . '.0';
            } else {
                $next = ((int)($parts[0] ?? 0) + 1) . '.0.0';
            }
            return version_compare($version, $min, '>=') && version_compare($version, $next, '<');
        }

        if (str_starts_with($constraint, '>=')) {
            return version_compare($version, substr($constraint, 2), '>=');
        }
        if (str_starts_with($constraint, '>')) {
            return version_compare($version, substr($constraint, 1), '>');
        }
        if (str_starts_with($constraint, '<=')) {
            return version_compare($version, substr($constraint, 2), '<=');
        }
        if (str_starts_with($constraint, '<')) {
            return version_compare($version, substr($constraint, 1), '<');
        }
        if (str_starts_with($constraint, '!=')) {
            return version_compare($version, substr($constraint, 2), '!=');
        }

        if (str_contains($constraint, '*')) {
            $prefix = str_replace('*', '', $constraint);
            return str_starts_with($version . '.', $prefix);
        }

        return version_compare($version, $constraint, '==');
    }
}
