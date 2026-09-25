<?php

declare(strict_types=1);

namespace ACQC\Core\Platform;

/**
 * Detects which platform(s) a project directory belongs to, using the
 * data-driven `detect` blocks from the platform manifests.
 *
 * A project can match more than one platform (an App Builder app also matches
 * `nodejs`; a Commerce project with a `web-src/` SPA can match both). Callers
 * union the resulting rule bundles / include patterns.
 */
class PlatformDetector
{
    public function __construct(private readonly PlatformRegistry $registry)
    {
    }

    /**
     * @return PlatformDefinition[] matched platforms (may be empty)
     */
    public function detect(string $projectPath): array
    {
        $projectPath = rtrim($projectPath, '/');
        $matched     = [];

        foreach ($this->registry->all() as $platform) {
            if ($this->matchesDetect($platform->detect, $projectPath)) {
                $matched[] = $platform;
            }
        }

        return $matched;
    }

    /** @return string[] union of rule bundle names across detected platforms */
    public function ruleBundlesFor(string $projectPath): array
    {
        $bundles = [];
        foreach ($this->detect($projectPath) as $platform) {
            foreach ($platform->ruleBundles as $bundle) {
                $bundles[$bundle] = true;
            }
        }

        return array_keys($bundles);
    }

    /** @return string[] union of include patterns across detected platforms */
    public function includePatternsFor(string $projectPath): array
    {
        $patterns = [];
        foreach ($this->detect($projectPath) as $platform) {
            foreach ($platform->includePatterns as $pattern) {
                $patterns[$pattern] = true;
            }
        }

        return array_keys($patterns);
    }

    // ── detection internals ──────────────────────────────────────────────────

    /** @param array<string,mixed> $detect */
    private function matchesDetect(array $detect, string $projectPath): bool
    {
        // `all_of` requires every present condition type to be satisfied (AND).
        if (isset($detect['all_of']) && is_array($detect['all_of'])) {
            return $this->matchesGroupAll($detect['all_of'], $projectPath);
        }

        // Otherwise any present condition type satisfies the match (OR).
        return $this->matchesGroupAny($detect, $projectPath);
    }

    /** @param array<string,mixed> $group */
    private function matchesGroupAny(array $group, string $projectPath): bool
    {
        if ($this->anyFileExists($group['files'] ?? [], $projectPath)) {
            return true;
        }
        if ($this->anyDependencyMatches($group['package_json_requires'] ?? [], $this->packageDependencies($projectPath))) {
            return true;
        }
        if ($this->anyDependencyMatches($group['composer_json_requires'] ?? [], $this->composerRequires($projectPath))) {
            return true;
        }

        return false;
    }

    /** @param array<string,mixed> $group */
    private function matchesGroupAll(array $group, string $projectPath): bool
    {
        $checks = [];

        if (isset($group['files'])) {
            $checks[] = $this->anyFileExists($group['files'], $projectPath);
        }
        if (isset($group['package_json_requires'])) {
            $checks[] = $this->anyDependencyMatches($group['package_json_requires'], $this->packageDependencies($projectPath));
        }
        if (isset($group['composer_json_requires'])) {
            $checks[] = $this->anyDependencyMatches($group['composer_json_requires'], $this->composerRequires($projectPath));
        }

        if ($checks === []) {
            return false;
        }

        return !in_array(false, $checks, true);
    }

    /** @param string[] $files */
    private function anyFileExists(array $files, string $projectPath): bool
    {
        foreach ($files as $file) {
            if (is_string($file) && file_exists($projectPath . '/' . ltrim($file, '/'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $patterns  dependency-name globs (e.g. "@adobe/aio-lib-*")
     * @param string[] $installed installed dependency names
     */
    private function anyDependencyMatches(array $patterns, array $installed): bool
    {
        foreach ($patterns as $pattern) {
            if (!is_string($pattern)) {
                continue;
            }
            foreach ($installed as $name) {
                if (fnmatch($pattern, $name)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return string[] dependency names from package.json (deps + devDeps) */
    private function packageDependencies(string $projectPath): array
    {
        $data = $this->readJson($projectPath . '/package.json');
        if ($data === null) {
            return [];
        }

        return array_merge(
            array_keys((array) ($data['dependencies'] ?? [])),
            array_keys((array) ($data['devDependencies'] ?? [])),
        );
    }

    /** @return string[] package names from composer.json (require + require-dev) */
    private function composerRequires(string $projectPath): array
    {
        $data = $this->readJson($projectPath . '/composer.json');
        if ($data === null) {
            return [];
        }

        return array_merge(
            array_keys((array) ($data['require'] ?? [])),
            array_keys((array) ($data['require-dev'] ?? [])),
        );
    }

    /** @return array<string,mixed>|null */
    private function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
