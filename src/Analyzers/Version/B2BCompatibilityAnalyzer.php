<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Version;

use Symfony\Component\Yaml\Yaml;

class B2BCompatibilityAnalyzer
{
    private string $b2bConfigPath;

    public function __construct(
        private readonly string $acqcRoot,
    ) {
        $this->b2bConfigPath = $acqcRoot . '/config/versions/b2b';
    }

    public static function isInstalled(string $projectRoot): bool
    {
        if (self::detectViaComposer($projectRoot) !== null) {
            return true;
        }
        return self::detectViaAppCode($projectRoot);
    }

    public function analyze(string $projectRoot, string $targetAcVersion): B2BCompatibilityResult
    {
        $installedVersion = self::detectViaComposer($projectRoot);
        $appCodeDetected  = self::detectViaAppCode($projectRoot);

        if ($installedVersion === null && !$appCodeDetected) {
            return B2BCompatibilityResult::notInstalled();
        }

        $findings      = [];
        $b2bManifest   = $this->loadB2BManifest($installedVersion);
        $resolvedVersion = $installedVersion ?? 'unknown';

        if ($installedVersion !== null && $b2bManifest !== null) {
            $compatibleAcVersions = $b2bManifest['compatible_ac_versions'] ?? [];
            if (!$this->isCompatibleWithTarget($compatibleAcVersions, $targetAcVersion)) {
                $findings[] = [
                    'type'             => 'b2b_version_incompatible',
                    'severity'         => 'error',
                    'message'          => "B2B {$resolvedVersion} is not compatible with Adobe Commerce {$targetAcVersion}. "
                                         . 'Compatible AC versions: ' . implode(', ', $compatibleAcVersions) . '.',
                    'remediation'      => "Upgrade magento/extension-b2b to the version compatible with AC {$targetAcVersion} "
                                         . 'before proceeding with the Commerce upgrade.',
                    'effort_hours_min' => 4,
                    'effort_hours_max' => 16,
                ];
            }

            foreach ($b2bManifest['breaking_changes'] ?? [] as $bc) {
                $type = $bc['type'] ?? '';

                if ($type === 'rest_endpoint_changed') {
                    $findings[] = [
                        'type'             => 'b2b_api_contract_changed',
                        'severity'         => $bc['severity'] ?? 'warning',
                        'message'          => "B2B REST API change in {$resolvedVersion}: {$bc['symbol']} — {$bc['change_description']}",
                        'remediation'      => $bc['remediation_summary'] ?? '',
                        'effort_hours_min' => 2,
                        'effort_hours_max' => 8,
                    ];
                } elseif (in_array($type, ['method_removed', 'class_removed', 'method_signature_changed'], true)) {
                    $findings[] = [
                        'type'             => 'b2b_module_deprecated',
                        'severity'         => $bc['severity'] ?? 'warning',
                        'message'          => "B2B breaking change in {$resolvedVersion}: "
                                             . ($bc['class'] ?? $bc['symbol'] ?? '') . ' — '
                                             . ($bc['change_description'] ?? $bc['remediation_summary'] ?? ''),
                        'remediation'      => $bc['remediation_summary'] ?? '',
                        'effort_hours_min' => 1,
                        'effort_hours_max' => 4,
                    ];
                }
            }
        } elseif ($appCodeDetected) {
            $findings[] = [
                'type'             => 'b2b_version_incompatible',
                'severity'         => 'warning',
                'message'          => "B2B modules detected in app/code/ but version could not be determined from composer.json. "
                                     . "Manual compatibility review required for target AC {$targetAcVersion}.",
                'remediation'      => 'Ensure all custom B2B extension points are compatible with the target AC version.',
                'effort_hours_min' => 4,
                'effort_hours_max' => 12,
            ];
        }

        return new B2BCompatibilityResult(
            installed: true,
            installedVersion: $resolvedVersion,
            findings: $findings,
        );
    }

    private static function detectViaComposer(string $projectRoot): ?string
    {
        $composerJson = $projectRoot . '/composer.json';
        if (!file_exists($composerJson)) {
            return null;
        }

        $data = json_decode(file_get_contents($composerJson), true);
        if (!is_array($data)) {
            return null;
        }

        $version = $data['require']['magento/extension-b2b']
            ?? $data['require-dev']['magento/extension-b2b']
            ?? null;

        if ($version === null) {
            return null;
        }

        // Normalise version constraint to bare version string if possible
        return preg_replace('/^[~^>=<*\s]+/', '', $version) ?: $version;
    }

    private static function detectViaAppCode(string $projectRoot): bool
    {
        $appCode = $projectRoot . '/app/code';
        if (!is_dir($appCode)) {
            return false;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($appCode, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir() && stripos($item->getFilename(), 'B2B') !== false) {
                return true;
            }
            if ($item->isFile() && $item->getFilename() === 'module.xml') {
                $content = @file_get_contents($item->getPathname()) ?: '';
                if (stripos($content, 'B2B') !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    private function loadB2BManifest(?string $version): ?array
    {
        if ($version === null) {
            return null;
        }

        $yamlFile = $this->b2bConfigPath . '/' . $version . '.yaml';
        if (!file_exists($yamlFile)) {
            // Fall back to closest known manifest
            $yamlFile = $this->findClosestManifest($version);
        }

        if ($yamlFile === null || !file_exists($yamlFile)) {
            return null;
        }

        return Yaml::parseFile($yamlFile);
    }

    private function findClosestManifest(string $requestedVersion): ?string
    {
        if (!is_dir($this->b2bConfigPath)) {
            return null;
        }

        $files = glob($this->b2bConfigPath . '/*.yaml') ?: [];
        if (empty($files)) {
            return null;
        }

        // Return the highest available version that does not exceed the requested version
        $best = null;
        foreach ($files as $file) {
            $v = basename($file, '.yaml');
            if (version_compare($v, $requestedVersion, '<=')) {
                if ($best === null || version_compare($v, basename($best, '.yaml'), '>')) {
                    $best = $file;
                }
            }
        }

        return $best ?? end($files);
    }

    private function isCompatibleWithTarget(array $compatibleVersions, string $target): bool
    {
        foreach ($compatibleVersions as $v) {
            if ($v === $target || str_starts_with($target, $v)) {
                return true;
            }
        }
        return false;
    }
}
