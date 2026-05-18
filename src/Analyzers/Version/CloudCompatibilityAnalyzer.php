<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Version;

use Symfony\Component\Yaml\Yaml;

class CloudCompatibilityAnalyzer
{
    private const CLOUD_INDICATORS = [
        '.magento.app.yaml',
        '.magento.env.yaml',
        '.magento/',
    ];

    private string $eceConfigPath;

    public function __construct(
        private readonly string $acqcRoot,
    ) {
        $this->eceConfigPath = $acqcRoot . '/config/versions/ece-tools';
    }

    public static function isCloudProject(string $projectRoot): bool
    {
        foreach (self::CLOUD_INDICATORS as $indicator) {
            $path = rtrim($projectRoot, '/') . '/' . ltrim($indicator, '/');
            if (file_exists($path)) {
                return true;
            }
        }

        $composerJson = $projectRoot . '/composer.json';
        if (file_exists($composerJson)) {
            $data = json_decode(@file_get_contents($composerJson) ?: '{}', true);
            if (is_array($data)) {
                $require = array_merge($data['require'] ?? [], $data['require-dev'] ?? []);
                if (isset($require['magento/ece-tools']) || isset($require['magento/magento-cloud-metapackage'])) {
                    return true;
                }
            }
        }

        return false;
    }

    public function analyze(string $projectRoot, string $targetAcVersion): CloudCompatibilityResult
    {
        if (!self::isCloudProject($projectRoot)) {
            return CloudCompatibilityResult::notCloud();
        }

        $findings        = [];
        $eceManifest     = $this->loadEceManifest();
        $phpFindings     = $this->analyzePhpVersion($projectRoot, $targetAcVersion, $eceManifest);
        $searchFindings  = $this->analyzeSearchEngine($projectRoot, $targetAcVersion, $eceManifest);
        $eceFindings     = $this->analyzeEceToolsVersion($projectRoot, $targetAcVersion, $eceManifest);

        $findings = array_merge($findings, $phpFindings, $searchFindings, $eceFindings);

        $detectedPhp    = $this->readPhpVersionFromAppYaml($projectRoot);
        $eceInstalled   = $this->detectEceToolsVersion($projectRoot);
        $searchEngine   = $this->readSearchEngineFromEnvYaml($projectRoot);

        return new CloudCompatibilityResult(
            isCloud: true,
            detectedPhpVersion: $detectedPhp,
            eceToolsVersion: $eceInstalled,
            configuredSearchEngine: $searchEngine,
            findings: $findings,
        );
    }

    private function analyzePhpVersion(string $projectRoot, string $targetAcVersion, ?array $eceManifest): array
    {
        $phpVersion = $this->readPhpVersionFromAppYaml($projectRoot);
        if ($phpVersion === null || $eceManifest === null) {
            return [];
        }

        $phpSupport = $eceManifest['php_support_by_ac'][$targetAcVersion] ?? null;
        if ($phpSupport === null) {
            return [];
        }

        $minPhp = $phpSupport['minimum'] ?? null;
        if ($minPhp === null) {
            return [];
        }

        if (version_compare($phpVersion, $minPhp, '<')) {
            return [[
                'type'             => 'cloud_php_version_incompatible',
                'severity'         => 'error',
                'message'          => ".magento.app.yaml declares PHP {$phpVersion}, but AC {$targetAcVersion} "
                                     . "requires PHP >= {$minPhp} in Cloud environments.",
                'remediation'      => "Update the 'type' field in .magento.app.yaml to php:{$phpSupport['recommended']}.",
                'effort_hours_min' => 2,
                'effort_hours_max' => 8,
            ]];
        }

        return [];
    }

    private function analyzeSearchEngine(string $projectRoot, string $targetAcVersion, ?array $eceManifest): array
    {
        $engine = $this->readSearchEngineFromEnvYaml($projectRoot);
        if ($engine === null) {
            return [];
        }

        $droppedEngines = $eceManifest['search_engine_by_ac'][$targetAcVersion]['dropped'] ?? [];

        foreach ($droppedEngines as $dropped) {
            if (str_contains(strtolower($engine), strtolower($dropped))) {
                return [[
                    'type'             => 'cloud_search_engine_incompatible',
                    'severity'         => 'error',
                    'message'          => ".magento.env.yaml still configures {$engine} engine but "
                                         . "AC {$targetAcVersion} requires OpenSearch.",
                    'remediation'      => "Update SEARCH_CONFIGURATION in .magento.env.yaml to use opensearch engine.",
                    'effort_hours_min' => 1,
                    'effort_hours_max' => 4,
                ]];
            }
        }

        return [];
    }

    private function analyzeEceToolsVersion(string $projectRoot, string $targetAcVersion, ?array $eceManifest): array
    {
        $installed = $this->detectEceToolsVersion($projectRoot);
        if ($installed === null || $eceManifest === null) {
            return [];
        }

        $minimum = $eceManifest['minimum_ece_tools_version_by_ac'][$targetAcVersion] ?? null;
        if ($minimum === null) {
            return [];
        }

        if (version_compare($installed, $minimum, '<')) {
            return [[
                'type'             => 'cloud_ece_tools_outdated',
                'severity'         => 'error',
                'message'          => "ECE-Tools {$installed} is below the minimum required version "
                                     . "{$minimum} for AC {$targetAcVersion}.",
                'remediation'      => "Run: composer require magento/ece-tools:^{$minimum}",
                'effort_hours_min' => 1,
                'effort_hours_max' => 2,
            ]];
        }

        return [];
    }

    private function readPhpVersionFromAppYaml(string $projectRoot): ?string
    {
        $yamlFile = $projectRoot . '/.magento.app.yaml';
        if (!file_exists($yamlFile)) {
            return null;
        }

        try {
            $data = Yaml::parseFile($yamlFile);
        } catch (\Throwable) {
            return null;
        }

        $type = $data['type'] ?? null;
        if ($type === null) {
            return null;
        }

        // Format: "php:8.2"
        if (preg_match('/^php:(\d+\.\d+)/', (string) $type, $m)) {
            return $m[1];
        }

        return null;
    }

    private function readSearchEngineFromEnvYaml(string $projectRoot): ?string
    {
        $yamlFile = $projectRoot . '/.magento.env.yaml';
        if (!file_exists($yamlFile)) {
            return null;
        }

        try {
            $data = Yaml::parseFile($yamlFile);
        } catch (\Throwable) {
            return null;
        }

        // SEARCH_CONFIGURATION lives under STAGE > deploy or global
        foreach (['deploy', 'global', 'build'] as $stage) {
            $engine = $data['stage'][$stage]['SEARCH_CONFIGURATION']['engine']
                ?? $data['SEARCH_CONFIGURATION']['engine']
                ?? null;
            if ($engine !== null) {
                return (string) $engine;
            }
        }

        return null;
    }

    private function detectEceToolsVersion(string $projectRoot): ?string
    {
        // Check composer.lock for the resolved ECE-Tools version
        $lockFile = $projectRoot . '/composer.lock';
        if (file_exists($lockFile)) {
            $lock = json_decode(@file_get_contents($lockFile) ?: '{}', true);
            foreach ($lock['packages'] ?? [] as $pkg) {
                if ($pkg['name'] === 'magento/ece-tools') {
                    $v = $pkg['version'] ?? null;
                    if ($v !== null) {
                        return ltrim((string) $v, 'v');
                    }
                }
            }
        }

        // Fall back to composer.json constraint
        $composerJson = $projectRoot . '/composer.json';
        if (file_exists($composerJson)) {
            $data = json_decode(@file_get_contents($composerJson) ?: '{}', true);
            $v = $data['require']['magento/ece-tools'] ?? null;
            if ($v !== null) {
                return preg_replace('/^[~^>=<*\s]+/', '', (string) $v) ?: null;
            }
        }

        return null;
    }

    private function loadEceManifest(): ?array
    {
        $yamlFile = $this->eceConfigPath . '/2002.1.19.yaml';
        if (!file_exists($yamlFile)) {
            return null;
        }

        try {
            return Yaml::parseFile($yamlFile);
        } catch (\Throwable) {
            return null;
        }
    }
}
