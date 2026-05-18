<?php

declare(strict_types=1);

namespace ACQC\Utils;

class ConfigLoader
{
    private array $defaultConfig = [
        'include_patterns' => ['*.php'],
        'exclude_paths' => ['vendor', 'var', 'generated'],
        'analyzers' => [
            'magento' => ['enabled' => true],
            'security' => ['enabled' => true],
            'performance' => ['enabled' => true],
            'quality' => ['enabled' => true],
        ],
        'severity_levels' => ['critical', 'error', 'warning', 'info'],
        'rules' => [
            'max_method_length' => 50,
            'max_complexity' => 10,
            'require_documentation' => true,
        ],
    ];

    public function load(?string $configPath = null): array
    {
        $config = $this->defaultConfig;

        if ($configPath && file_exists($configPath)) {
            $userConfig = $this->loadConfigFile($configPath);
            $config = array_merge_recursive($config, $userConfig);
        }

        // Look for default config files
        $defaultPaths = [
            getcwd() . '/acqc.json',
            getcwd() . '/acqc.php',
            getcwd() . '/.acqc.json',
        ];

        foreach ($defaultPaths as $path) {
            if (file_exists($path)) {
                $userConfig = $this->loadConfigFile($path);
                $config = array_merge_recursive($config, $userConfig);
                break;
            }
        }

        return $config;
    }

    private function loadConfigFile(string $configPath): array
    {
        $extension = pathinfo($configPath, PATHINFO_EXTENSION);

        switch ($extension) {
            case 'json':
                return $this->loadJsonConfig($configPath);
            case 'php':
                return $this->loadPhpConfig($configPath);
            default:
                throw new \InvalidArgumentException("Unsupported config file format: {$extension}");
        }
    }

    private function loadJsonConfig(string $configPath): array
    {
        $content = file_get_contents($configPath);
        $config = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException("Invalid JSON in config file: " . json_last_error_msg());
        }

        return $config;
    }

    private function loadPhpConfig(string $configPath): array
    {
        $config = include $configPath;

        if (!is_array($config)) {
            throw new \InvalidArgumentException("PHP config file must return an array");
        }

        return $config;
    }

    public function getDefaultConfig(): array
    {
        return $this->defaultConfig;
    }

    public function validateConfig(array $config): bool
    {
        $requiredKeys = ['include_patterns', 'analyzers', 'severity_levels'];

        foreach ($requiredKeys as $key) {
            if (!isset($config[$key])) {
                throw new \InvalidArgumentException("Missing required config key: {$key}");
            }
        }

        return true;
    }
} 