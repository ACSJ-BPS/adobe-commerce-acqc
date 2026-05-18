<?php

declare(strict_types=1);

namespace ACQC\Core\Version;

use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Cache\CacheInterface;

class VersionRegistry
{
    public function __construct(
        private readonly string $registryPath,
        private readonly CacheInterface $cache,
    ) {}

    public function getManifest(string $version): VersionManifest
    {
        $yamlFile = $this->resolveYamlPath($version);
        $mtime = file_exists($yamlFile) ? (filemtime($yamlFile) ?: 0) : 0;
        $cacheKey = 'vm_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($version)) . '_' . $mtime;

        return $this->cache->get($cacheKey, function () use ($yamlFile, $version): VersionManifest {
            if (!file_exists($yamlFile)) {
                throw new \RuntimeException("No manifest found for version '{$version}' at {$yamlFile}");
            }

            $data = Yaml::parseFile($yamlFile);
            return VersionManifest::fromArray($data);
        });
    }

    public function getBreakingChanges(string $source, string $target): array
    {
        $changes = [];
        foreach ($this->getVersionsInRange($source, $target) as $v) {
            $manifest = $this->getManifest($v);
            foreach ($manifest->breakingChanges as $bc) {
                $changes[] = $bc;
            }
        }

        return $changes;
    }

    public function getDeprecatedSymbols(string $fromVersion, string $toVersion): array
    {
        $symbols = [];
        foreach ($this->getVersionsInRange($fromVersion, $toVersion) as $v) {
            $manifest = $this->getManifest($v);
            $ds = $manifest->deprecatedSymbols;
            foreach (['classes', 'methods', 'events'] as $type) {
                foreach ($ds[$type] ?? [] as $entry) {
                    $symbols[] = array_merge($entry, ['kind' => $type]);
                }
            }
        }

        return $symbols;
    }

    public function getRemovedSymbols(string $fromVersion, string $toVersion): array
    {
        return array_values(array_filter(
            $this->getBreakingChanges($fromVersion, $toVersion),
            static fn(BreakingChange $bc) => $bc->type === 'class_removed'
        ));
    }

    public function getDbSchemaChanges(string $fromVersion, string $toVersion): array
    {
        $changes = [];
        foreach ($this->getVersionsInRange($fromVersion, $toVersion) as $v) {
            $manifest = $this->getManifest($v);
            foreach ($manifest->dbSchemaChanges as $dsc) {
                $changes[] = $dsc;
            }
        }

        return $changes;
    }

    public function getComposerRequirements(string $version): array
    {
        return $this->getManifest($version)->composerRequirements;
    }

    public function getPhpSupportMatrix(string $version): array
    {
        return $this->getManifest($version)->phpSupport;
    }

    public function getEolDate(string $version): ?\DateTimeImmutable
    {
        return $this->getManifest($version)->eolDate;
    }

    public function isEndOfLife(string $version, ?\DateTimeImmutable $asOf = null): bool
    {
        return $this->getManifest($version)->isEndOfLife($asOf);
    }

    public function getSecurityAdvisories(string $version): array
    {
        return $this->getManifest($version)->securityAdvisories;
    }

    public function getLatestPatchFor(string $minorVersion): string
    {
        $registry = $this->loadRegistryIndex();
        $patches = $registry['latest_patch_by_minor'] ?? [];

        return $patches[$minorVersion] ?? $minorVersion;
    }

    public function getKnownVersions(): array
    {
        $versions = $this->loadRegistryIndex()['known_versions'] ?? [];
        usort($versions, fn(string $a, string $b) => $this->compareVersions($a, $b));

        return $versions;
    }

    public function getVersionsSince(string $from): array
    {
        return array_values(array_filter(
            $this->getKnownVersions(),
            fn(string $v) => $this->compareVersions($v, $from) > 0
        ));
    }

    private function getVersionsInRange(string $from, string $to): array
    {
        return array_values(array_filter(
            $this->getKnownVersions(),
            fn(string $v) => $this->compareVersions($v, $from) > 0
                && $this->compareVersions($v, $to) <= 0
        ));
    }

    private function loadRegistryIndex(): array
    {
        $registryFile = dirname($this->registryPath) . '/registry.yaml';

        $mtime = @filemtime($registryFile) ?: 0;
        $cacheKey = 'registry_index_' . $mtime;

        return $this->cache->get($cacheKey, static function () use ($registryFile): array {
            if (!file_exists($registryFile)) {
                return [];
            }

            return Yaml::parseFile($registryFile) ?? [];
        });
    }

    private function resolveYamlPath(string $version): string
    {
        return rtrim($this->registryPath, '/') . '/' . $version . '.yaml';
    }

    private function normalizeForCompare(string $version): string
    {
        if (preg_match('/^(\d+\.\d+\.\d+)-p(\d+)$/', $version, $m)) {
            return $m[1] . '.' . $m[2];
        }

        return $version . '.0';
    }

    public function compareVersions(string $a, string $b): int
    {
        return version_compare(
            $this->normalizeForCompare($a),
            $this->normalizeForCompare($b)
        );
    }
}
