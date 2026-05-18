<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Version\Frontend;

use Symfony\Component\Yaml\Yaml;

class RequireJSMapAnalyzer
{
    private const DEPRECATED_PATHS = [
        'jquery/validate!',
        'mage/validate',
        'jquery/jquery.cookie',
        'jquery/jquery.hashchange',
        'mage/adminhtml/form',
    ];

    /** @var array version → list of {from, to, reason} */
    private array $jsChanges = [];

    public function __construct(
        private readonly string $projectRoot,
        private readonly string $jsLibChangesConfigPath,
    ) {
        $this->loadConfig();
    }

    public function analyze(string $targetVersion = ''): array
    {
        $findings = [];

        foreach (['app/code', 'app/design'] as $baseDir) {
            $dir = $this->projectRoot . '/' . $baseDir;
            if (!is_dir($dir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getFilename() !== 'requirejs-config.js') {
                    continue;
                }

                $findings = array_merge($findings, $this->analyzeFile($file->getPathname(), $targetVersion));
            }
        }

        return $findings;
    }

    private function analyzeFile(string $filepath, string $targetVersion): array
    {
        $content = file_get_contents($filepath);
        if ($content === false) {
            return [];
        }

        $findings = [];
        $module   = $this->resolveModuleName($filepath);
        $versionNote = $targetVersion !== '' ? " AC {$targetVersion}" : '';

        // Extract all key-value pairs from map/paths blocks
        $pathOverrides = $this->extractPathMappings($content);
        $overrideCount = count($pathOverrides);

        // Inventory finding
        $findings[] = [
            'type'     => 'requirejs_config_detected',
            'severity' => 'info',
            'file'     => $filepath,
            'module'   => $module,
            'count'    => $overrideCount,
            'message'  => "requirejs-config.js in {$module} declares {$overrideCount} path override(s). Verify compatibility with{$versionNote}.",
        ];

        // Deprecated path check
        foreach ($pathOverrides as $key => $value) {
            foreach (self::DEPRECATED_PATHS as $deprecated) {
                if (str_contains($key, $deprecated) || str_contains($value, $deprecated)) {
                    $findings[] = [
                        'type'        => 'requirejs_deprecated_path',
                        'severity'    => 'warning',
                        'file'        => $filepath,
                        'module'      => $module,
                        'path'        => $key,
                        'message'     => "requirejs-config.js in {$module} overrides {$overrideCount} paths. "
                            . "Deprecated path '{$key}' detected. Verify compatibility with{$versionNote}.",
                        'remediation' => "Replace deprecated RequireJS alias '{$key}' with the current module path. "
                            . 'See Adobe Commerce JS migration guide for updated module names.',
                    ];
                }
            }
        }

        return $findings;
    }

    /**
     * Extract key → value pairs from map: {} and paths: {} blocks in requirejs-config.js.
     *
     * @return array<string,string>
     */
    private function extractPathMappings(string $content): array
    {
        $mappings = [];

        // Find map and paths blocks
        if (!preg_match_all(
            '/(?:map|paths)\s*:\s*\{([^}]*(?:\{[^}]*\}[^}]*)*)\}/s',
            $content,
            $blockMatches
        )) {
            return $mappings;
        }

        foreach ($blockMatches[1] as $block) {
            // Extract string key-value pairs within the block
            preg_match_all(
                '/[\'"]([^\'"]+)[\'"]\s*:\s*[\'"]([^\'"]+)[\'"]/',
                $block,
                $pairMatches,
                PREG_SET_ORDER
            );
            foreach ($pairMatches as $pair) {
                $mappings[$pair[1]] = $pair[2];
            }
        }

        return $mappings;
    }

    private function resolveModuleName(string $filepath): string
    {
        // app/code/Vendor/Module/... → Vendor_Module
        if (preg_match('#app/code/([^/]+)/([^/]+)#', str_replace('\\', '/', $filepath), $m)) {
            return $m[1] . '_' . $m[2];
        }
        // app/design/area/Vendor/Theme/... → Vendor/Theme
        if (preg_match('#app/design/[^/]+/([^/]+)/([^/]+)#', str_replace('\\', '/', $filepath), $m)) {
            return $m[1] . '/' . $m[2];
        }
        return basename(dirname($filepath));
    }

    private function loadConfig(): void
    {
        if (!file_exists($this->jsLibChangesConfigPath)) {
            return;
        }

        $data = Yaml::parseFile($this->jsLibChangesConfigPath);
        $this->jsChanges = $data['version_changes'] ?? [];
    }
}
