<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Version\Frontend;

use Symfony\Component\Yaml\Yaml;

class LESSVariableAnalyzer
{
    private const MAGENTO_VAR_PATTERN = '/@[a-z][a-z0-9-_]*__[a-z][a-z0-9-_]*/i';

    private const TARGET_FILENAMES = [
        '_module.less',
        '_extend.less',
        '_variables.less',
        '_theme.less',
    ];

    /** @var array version → list of {variable, action, replacement} */
    private array $lessChanges = [];

    public function __construct(
        private readonly string $projectRoot,
        private readonly string $lessChangesConfigPath,
    ) {
        $this->loadConfig();
    }

    public function analyze(string $targetVersion = ''): array
    {
        $appDesignDir = $this->projectRoot . '/app/design';
        if (!is_dir($appDesignDir)) {
            return [];
        }

        $findings     = [];
        $allVariables = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($appDesignDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'less') {
                continue;
            }

            if (!in_array($file->getFilename(), self::TARGET_FILENAMES, true)) {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            if ($content === false) {
                continue;
            }

            $variables = $this->extractVariables($content);
            $allVariables = array_merge($allVariables, $variables);

            if (count($variables) > 0) {
                $theme    = $this->resolveThemeName($file->getPathname(), $appDesignDir);
                $fileFinding = $this->buildFileFinding($file->getPathname(), $file->getFilename(), $theme, $variables, $targetVersion);
                $findings[] = $fileFinding;
            }
        }

        $unique = array_unique($allVariables);
        $total  = count($unique);

        if ($total > 0) {
            $versionNote = $targetVersion !== '' ? " AC {$targetVersion}" : '';
            $severity    = $total >= 50 ? 'warning' : 'info';

            $findings[] = [
                'type'     => 'less_variable_summary',
                'severity' => $severity,
                'message'  => "Custom theme defines/uses {$total} unique LESS variable(s). "
                    . "Verify against{$versionNote} variable changes.",
            ];
        }

        return $findings;
    }

    /**
     * @return string[]
     */
    private function extractVariables(string $content): array
    {
        preg_match_all(self::MAGENTO_VAR_PATTERN, $content, $matches);
        return $matches[0] ?? [];
    }

    private function buildFileFinding(
        string $filepath,
        string $filename,
        string $theme,
        array $variables,
        string $targetVersion,
    ): array {
        $count       = count($variables);
        $severity    = $count >= 50 ? 'warning' : 'info';
        $versionNote = $targetVersion !== '' ? " AC {$targetVersion}" : '';

        return [
            'type'          => 'less_variables_detected',
            'severity'      => $severity,
            'file'          => $filepath,
            'theme'         => $theme,
            'filename'      => $filename,
            'variable_count' => $count,
            'message'       => "Custom theme {$theme}/{$filename} defines/uses {$count} LESS variable(s). "
                . "Verify against{$versionNote} variable changes.",
        ];
    }

    private function resolveThemeName(string $filepath, string $appDesignDir): string
    {
        $relative = ltrim(str_replace($appDesignDir, '', $filepath), DIRECTORY_SEPARATOR);
        // {area}/{vendor}/{theme}/...
        $parts = explode(DIRECTORY_SEPARATOR, $relative);
        if (count($parts) >= 3) {
            return $parts[1] . '/' . $parts[2];
        }
        return basename(dirname($filepath));
    }

    private function loadConfig(): void
    {
        if (!file_exists($this->lessChangesConfigPath)) {
            return;
        }

        $data = Yaml::parseFile($this->lessChangesConfigPath);
        $this->lessChanges = $data['version_changes'] ?? [];
    }
}
