<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Version\Frontend;

use Symfony\Component\Yaml\Yaml;

class TemplateOverrideDetector
{
    /** @var array<string, list<array<string,mixed>>> version → changed templates */
    private array $templateChanges = [];

    public function __construct(
        private readonly string $projectRoot,
        private readonly string $templateChangesConfigPath,
    ) {
        $this->loadConfig();
    }

    public function detect(string $targetVersion = ''): array
    {
        $appDesignDir = $this->projectRoot . '/app/design';
        if (!is_dir($appDesignDir)) {
            return [];
        }

        $findings   = [];
        $overrides  = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($appDesignDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'phtml') {
                continue;
            }

            $parsed = $this->parseOverridePath($file->getPathname(), $appDesignDir);
            if ($parsed === null) {
                continue;
            }

            ['module' => $module, 'template_path' => $templatePath, 'theme_path' => $themePath] = $parsed;

            $overrides[] = compact('module', 'templatePath', 'themePath', 'file');

            $versionNote = $targetVersion !== '' ? " AC {$targetVersion}" : '';

            $findings[] = [
                'type'             => 'template_override_detected',
                'severity'         => 'info',
                'file'             => $file->getPathname(),
                'module'           => $module,
                'template_path'    => $templatePath,
                'message'          => "Template override found: {$themePath} overrides {$module}::{$templatePath}",
                'remediation'      => "Review parent template in{$versionNote} to check for changes. Manual comparison required.",
                'effort_hours_min' => 1,
                'effort_hours_max' => 4,
            ];
        }

        $count = count($overrides);

        if ($count > 20) {
            $findings[] = [
                'type'     => 'template_override_summary',
                'severity' => 'warning',
                'message'  => "High number of template overrides ({$count}) detected. Upgrade regression risk is elevated.",
            ];
        } elseif ($count > 0) {
            $findings[] = [
                'type'     => 'template_override_summary',
                'severity' => 'info',
                'message'  => "{$count} template override(s) detected. Review each after upgrade.",
            ];
        }

        return $findings;
    }

    /**
     * Parse path like:
     *   app/design/{area}/{vendor}/{theme}/{Module_Name}/templates/{path}.phtml
     *
     * Returns [module, template_path, theme_path] or null if not a module override.
     *
     * @return array{module:string,template_path:string,theme_path:string}|null
     */
    private function parseOverridePath(string $absolute, string $appDesignDir): ?array
    {
        $relative = ltrim(str_replace($appDesignDir, '', $absolute), DIRECTORY_SEPARATOR);
        // Expected: {area}/{vendor}/{theme}/{Module_Name}/templates/{...}.phtml
        $parts = explode(DIRECTORY_SEPARATOR, $relative);

        // Minimum: area / vendor / theme / Module_Name / templates / file.phtml  = 6 parts
        if (count($parts) < 6) {
            return null;
        }

        $templatesIdx = array_search('templates', $parts, true);
        if ($templatesIdx === false || $templatesIdx < 3) {
            return null;
        }

        $moduleName   = $parts[$templatesIdx - 1]; // e.g. Magento_Checkout
        $templatePath = implode('/', array_slice($parts, $templatesIdx + 1)); // e.g. cart/item/default.phtml
        $themeParts   = array_slice($parts, 0, $templatesIdx - 1); // area/vendor/theme
        $themePath    = implode('/', $themeParts) . '/' . $moduleName . '/templates/' . $templatePath;

        return [
            'module'        => $moduleName,
            'template_path' => $templatePath,
            'theme_path'    => $themePath,
        ];
    }

    private function loadConfig(): void
    {
        if (!file_exists($this->templateChangesConfigPath)) {
            return;
        }

        $data = Yaml::parseFile($this->templateChangesConfigPath);
        $this->templateChanges = $data['version_changes'] ?? [];
    }
}
