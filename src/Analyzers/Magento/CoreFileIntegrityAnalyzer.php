<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Magento;

use ACQC\Analyzers\AbstractAnalyzer;
use ACQC\Core\Rules\Violation;
use Symfony\Component\Finder\Finder;

/**
 * Detects core file modifications by comparing project vendor files against
 * a clean vanilla Adobe Commerce installation (cached in var/vanilla/<version>/).
 *
 * Mirrors UCT's core:code:changes command approach: download a vanilla instance
 * with `version:download`, then MD5-compare vendor/magento/* files against the
 * project's own vendor directory.
 *
 * Modified core files are a critical upgrade blocker — they conflict with
 * composer update and break patch installation.
 */
class CoreFileIntegrityAnalyzer extends AbstractAnalyzer
{
    /** In-memory cache so vanilla and project maps are not recomputed within the same run. */
    private array $hashMapCache = [];

    public function getName(): string
    {
        return 'magento-core-file-integrity';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    protected function getDefaultConfiguration(): array
    {
        return [
            'target_magento_version' => null,
            'vanilla_base_path'      => null,
            'vendor_path'            => null,
            'vendor_prefixes'        => ['magento/', 'paypal/'],
            'severity_modified'      => Violation::SEVERITY_CRITICAL,
            'severity_added'         => Violation::SEVERITY_ERROR,
            'check_modified'         => true,
            'check_added'            => true,
            'exclude_paths'          => [
                '*/Test/*',
                '*/tests/*',
                '*/.git/*',
                '*/node_modules/*',
            ],
        ];
    }

    public function analyze(array $files, array $options = []): array
    {
        $violations = $this->executeAnalysis($files, $options);
        $this->lastAnalysisResults = $violations;
        return $violations;
    }

    protected function executeAnalysis(array $files, array $options = []): array
    {
        $version = $this->configuration['target_magento_version']
            ?? ($options['target_magento_version'] ?? null);

        $vendorPath = $this->resolveVendorPath();
        $vanillaPath = $this->resolveVanillaPath($version);

        if ($vanillaPath === null) {
            $this->logger->warning(
                'CoreFileIntegrityAnalyzer: vanilla path not found for version ' . ($version ?? 'unknown') .
                '. Run `acqc version:download <version>` first.'
            );
            return [];
        }

        if (!is_dir($vendorPath)) {
            $this->logger->warning("CoreFileIntegrityAnalyzer: vendor path not found: {$vendorPath}");
            return [];
        }

        $this->logger->info("CoreFileIntegrityAnalyzer: comparing {$vendorPath} against {$vanillaPath}");

        $violations = [];

        foreach ($this->configuration['vendor_prefixes'] as $prefix) {
            $projectDir = $vendorPath . '/' . rtrim($prefix, '/');
            $vanillaDir = $vanillaPath . '/vendor/' . rtrim($prefix, '/');

            if (!is_dir($vanillaDir)) {
                continue;
            }

            $violations = array_merge(
                $violations,
                $this->compareVendorDirectory($projectDir, $vanillaDir, $prefix)
            );
        }

        $this->logger->info('CoreFileIntegrityAnalyzer: found ' . count($violations) . ' violations');
        return $violations;
    }

    protected function parseOutput(string $output, array $files): array
    {
        return [];
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function compareVendorDirectory(string $projectDir, string $vanillaDir, string $prefix): array
    {
        if (!is_dir($projectDir) || !is_dir($vanillaDir)) {
            return [];
        }

        $violations = [];

        // Build MD5 map for vanilla files
        $vanillaHashes = $this->buildHashMap($vanillaDir);

        // Compare each vanilla file against the project
        foreach ($vanillaHashes as $relativePath => $vanillaHash) {
            if ($this->isExcluded($relativePath)) {
                continue;
            }

            $projectFile = $projectDir . '/' . $relativePath;

            if (!file_exists($projectFile)) {
                // File present in vanilla but not in project — vendor pruning, skip (not a violation)
                continue;
            }

            $projectHash = md5_file($projectFile);
            if ($projectHash !== $vanillaHash) {
                if ($this->configuration['check_modified']) {
                    $violations[] = new Violation(
                        'magento-core-file-modified',
                        "Core file modified: vendor/{$prefix}{$relativePath} — hash mismatch against vanilla. Core file modifications block upgrades and patches.",
                        $projectFile,
                        0,
                        0,
                        $this->configuration['severity_modified'],
                        Violation::TYPE_SECURITY
                    );
                }
            }
        }

        // Check for files added to vendor packages that don't exist in vanilla
        if ($this->configuration['check_added']) {
            $projectHashes = $this->buildHashMap($projectDir);
            foreach (array_keys($projectHashes) as $relativePath) {
                if ($this->isExcluded($relativePath)) {
                    continue;
                }
                if (!isset($vanillaHashes[$relativePath])) {
                    $violations[] = new Violation(
                        'magento-core-file-added',
                        "File added to core package: vendor/{$prefix}{$relativePath} — not present in vanilla. Extra files in core packages are overwritten on upgrade.",
                        $projectDir . '/' . $relativePath,
                        0,
                        0,
                        $this->configuration['severity_added'],
                        Violation::TYPE_SECURITY
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * Build a map of [relative_path => md5_hash] for all PHP/XML/JS/phtml/HTML files
     * under $baseDir, walking recursively.
     *
     * Results are cached in sys_get_temp_dir() keyed by the directory path + its mtime,
     * so repeat runs (or project dir vs vanilla dir for the same version) are instant.
     *
     * @return array<string, string>
     */
    private function buildHashMap(string $baseDir): array
    {
        if (!is_dir($baseDir)) {
            return [];
        }

        $realBase = (string)(realpath($baseDir) ?: $baseDir);

        if (isset($this->hashMapCache[$realBase])) {
            return $this->hashMapCache[$realBase];
        }

        $cacheFile = $this->hashCachePath($baseDir);
        if ($cacheFile !== null && file_exists($cacheFile)) {
            $cached = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return $this->hashMapCache[$realBase] = $cached;
            }
        }

        $map = [];

        $finder = (new Finder())
            ->files()
            ->in($baseDir)
            ->name('/\.(php|phtml|xml|js|html|css|less)$/');

        foreach ($finder as $file) {
            $relativePath = $file->getRelativePathname();
            $map[$relativePath] = (string)md5_file($file->getRealPath());
        }

        if ($cacheFile !== null) {
            file_put_contents($cacheFile, json_encode($map));
        }

        return $this->hashMapCache[$realBase] = $map;
    }

    /**
     * Returns a path for the hash-map cache file, or null when caching is not possible.
     * Cache is keyed by canonical path + filemtime of the directory itself (changes on
     * any direct child file creation/deletion, which is sufficient for vendor directories).
     */
    private function hashCachePath(string $baseDir): ?string
    {
        $cacheDir = sys_get_temp_dir() . '/acqc_hash_cache';
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0700, true)) {
            return null;
        }
        $mtime = @filemtime($baseDir);
        $key   = md5(realpath($baseDir) . ':' . ($mtime ?: '0'));
        return $cacheDir . '/' . $key . '.json';
    }

    private function isExcluded(string $relativePath): bool
    {
        foreach ($this->configuration['exclude_paths'] as $pattern) {
            if (fnmatch($pattern, $relativePath) || fnmatch($pattern, '/' . $relativePath)) {
                return true;
            }
        }
        return false;
    }

    private function resolveVendorPath(): string
    {
        if ($this->configuration['vendor_path'] !== null) {
            return rtrim($this->configuration['vendor_path'], '/');
        }

        // Auto-discover: walk up from CWD looking for vendor/
        $dir = $this->workingDirectory;
        for ($i = 0; $i < 5; $i++) {
            if (is_dir($dir . '/vendor')) {
                return $dir . '/vendor';
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return $this->workingDirectory . '/vendor';
    }

    private function resolveVanillaPath(?string $version): ?string
    {
        if ($this->configuration['vanilla_base_path'] !== null) {
            $path = rtrim($this->configuration['vanilla_base_path'], '/');
            return is_dir($path) ? $path : null;
        }

        if ($version === null) {
            return null;
        }

        // Default: var/vanilla/<version>/ relative to CWD (matches version:download output)
        $candidates = [
            $this->workingDirectory . '/var/vanilla/' . $version,
            dirname($this->workingDirectory) . '/var/vanilla/' . $version,
        ];

        foreach ($candidates as $candidate) {
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
