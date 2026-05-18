<?php

declare(strict_types=1);

namespace ACQC\Core\Rules;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * Six-layer YAML rule loader.
 *
 * Layers (last writer wins on matching 'id'):
 *   1. Global  — $acqcRoot/config/rules/global/
 *   2. Dept    — $acqcRoot/config/rules/adobe-commerce/  + project.yaml rule_bundles
 *   3. Team    — $projectRoot/.acqc/rules/team/  (if present)
 *   4. Project — suppressions from $projectRoot/.acqc/project.yaml
 *   5. Role    — filterByProfile() post-load
 *   6. Personal— ~/.acqc/rules/personal/  (skipped for ci profile or when disabled)
 */
class YamlRuleLoader
{
    private CacheItemPoolInterface $cache;

    public function __construct(
        private readonly RuleValidator $validator,
        private readonly string $projectRoot,
        private readonly string $acqcRoot,
        ?CacheItemPoolInterface $cache = null,
    ) {
        $this->cache = $cache ?? new FilesystemAdapter(
            'acqc_rules',
            3600,
            sys_get_temp_dir() . '/acqc_cache'
        );
    }

    /**
     * Load and merge all rule layers, returning RuleDefinition[] indexed by rule ID.
     *
     * @return RuleDefinition[]
     */
    public function load(string $profile = 'developer', bool $includePersonal = true): array
    {
        $cacheKey = $this->buildCacheKey($profile);
        $item     = $this->cache->getItem($cacheKey);

        if ($item->isHit()) {
            return $item->get();
        }

        // Raw rule data arrays, keyed by rule ID — later layers overwrite earlier ones
        $raw   = [];
        $paths = [];

        // Layer 1: Global
        $this->scanDir("{$this->acqcRoot}/config/rules/global", $raw, $paths);

        // Layer 2: Department  + optional rule_bundles
        $this->scanDir("{$this->acqcRoot}/config/rules/adobe-commerce", $raw, $paths);
        $projectConfig = $this->loadProjectConfig();
        foreach ($projectConfig['rule_bundles'] ?? [] as $bundle) {
            $this->scanDir("{$this->acqcRoot}/config/rules/{$bundle}", $raw, $paths);
        }

        // Layer 3: Team overrides
        $teamPath = "{$this->projectRoot}/.acqc/rules/team";
        if (is_dir($teamPath)) {
            $this->scanDir($teamPath, $raw, $paths);
        }

        // Layer 4: Project suppressions
        foreach ($projectConfig['suppressions'] ?? [] as $suppression) {
            $ruleId = $suppression['rule_id'] ?? null;
            if ($ruleId && isset($raw[$ruleId])) {
                $raw[$ruleId]['suppression'] = [
                    'reason'        => $suppression['reason']        ?? '',
                    'suppressed_by' => $suppression['suppressed_by'] ?? '',
                    'suppressed_at' => $suppression['suppressed_at'] ?? '',
                ];
            }
        }

        // Layer 6: Personal (skip for ci profile or when disabled)
        if ($includePersonal && $profile !== 'ci') {
            $home         = $_SERVER['HOME'] ?? (getenv('HOME') ?: '');
            $personalPath = $home . '/.acqc/rules/personal';
            if ($home !== '' && is_dir($personalPath)) {
                $this->scanDir($personalPath, $raw, $paths);
            }
        }

        // Convert to RuleDefinition objects (Layer 5 — filter by profile)
        $result = [];
        foreach ($raw as $id => $data) {
            $filePath = $paths[$id] ?? '';
            try {
                $rule = $this->validator->validate($data, $filePath);
                if ($this->profileMatches($rule, $profile)) {
                    $result[$id] = $rule;
                }
            } catch (RuleValidationException) {
                // Silently skip invalid rules during bulk load to avoid crashing on bad files
            }
        }

        $item->set($result);
        $item->expiresAfter(3600);
        $this->cache->save($item);

        return $result;
    }

    // ── private helpers ────────────────────────────────────────────────────────

    /**
     * Recursively scan a directory for YAML files and merge into $raw.
     * Later entries for the same rule ID overwrite earlier ones.
     */
    private function scanDir(string $dir, array &$raw, array &$paths): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $finder = new Finder();
        $finder->files()->name(['*.yaml', '*.yml'])->in($dir)->sortByName();

        foreach ($finder as $file) {
            try {
                $data = Yaml::parseFile($file->getRealPath());
            } catch (\Throwable) {
                continue;
            }

            if (!is_array($data) || !isset($data['id'])) {
                continue;
            }

            $id        = (string) $data['id'];
            $raw[$id]  = $data;
            $paths[$id] = $file->getRealPath();
        }
    }

    private function profileMatches(RuleDefinition $rule, string $profile): bool
    {
        $normalized = str_replace('-', '_', strtolower($profile));
        return in_array($normalized, $rule->roles, true);
    }

    private function loadProjectConfig(): array
    {
        $configPath = "{$this->projectRoot}/.acqc/project.yaml";
        if (!file_exists($configPath)) {
            return [];
        }

        try {
            $data = Yaml::parseFile($configPath);
            return is_array($data) ? $data : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function buildCacheKey(string $profile): string
    {
        $newestMtime = 0;

        $dirs = [
            "{$this->acqcRoot}/config/rules/global",
            "{$this->acqcRoot}/config/rules/adobe-commerce",
            "{$this->projectRoot}/.acqc/rules/team",
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $finder = new Finder();
            foreach ($finder->files()->name(['*.yaml', '*.yml'])->in($dir) as $file) {
                $mtime = $file->getMTime();
                if ($mtime > $newestMtime) {
                    $newestMtime = $mtime;
                }
            }
        }

        return md5($this->projectRoot . $profile . $newestMtime);
    }
}
