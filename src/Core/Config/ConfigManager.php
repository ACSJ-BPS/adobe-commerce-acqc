<?php

declare(strict_types=1);

namespace ACQC\Core\Config;

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Contracts\Cache\ItemInterface;

class ConfigManager
{
    private const DEFAULT_CONFIG_FILE = 'config/default.yaml';
    private const CONFIG_CACHE_KEY = 'acqc_config';
    private const CONFIG_CACHE_TTL = 3600; // 1 hour

    private ?FilesystemAdapter $cache = null;
    private array $loadedConfig = [];
    private bool $useCache;
    private string $projectRoot;

    public function __construct(?string $projectRoot = null, bool $useCache = true)
    {
        $this->projectRoot = $projectRoot ?? getcwd();
        $this->useCache = $useCache;
        // Cache is initialized lazily — avoids unnecessary disk I/O when useCache=false
    }

    private function getCache(): FilesystemAdapter
    {
        if ($this->cache === null) {
            $this->cache = new FilesystemAdapter('acqc', 0, $this->projectRoot . '/var/cache');
        }
        return $this->cache;
    }

    /**
     * Load configuration from multiple sources with caching
     */
    public function loadConfig(array $sources = []): array
    {
        if (!empty($this->loadedConfig)) {
            return $this->loadedConfig;
        }

        $cacheKey = $this->generateCacheKey($sources);

        if ($this->useCache) {
            $config = $this->getCache()->get($cacheKey, function (ItemInterface $item) use ($sources) {
                $item->expiresAfter(self::CONFIG_CACHE_TTL);
                return $this->buildConfiguration($sources);
            });
        } else {
            $config = $this->buildConfiguration($sources);
        }

        $this->loadedConfig = $config;
        return $config;
    }

    /**
     * Load configuration for a specific role
     */
    public function loadRoleConfig(string $role, array $additionalSources = []): array
    {
        $sources = array_merge([
            $this->getDefaultConfigPath(),
            $this->getRoleConfigPath($role),
        ], $additionalSources);

        $config = $this->loadConfig($sources);
        
        // Apply role-specific filtering
        $roleConfig = new RoleConfig($role, $config);
        return $roleConfig->getFilteredConfig();
    }

    /**
     * Get configuration value with dot notation support
     */
    public function get(string $key, $default = null)
    {
        if (empty($this->loadedConfig)) {
            $this->loadConfig();
        }

        return $this->getNestedValue($this->loadedConfig, $key, $default);
    }

    /**
     * Set configuration value with dot notation support
     */
    public function set(string $key, $value): void
    {
        if (empty($this->loadedConfig)) {
            $this->loadConfig();
        }

        $this->setNestedValue($this->loadedConfig, $key, $value);
        
        // Clear cache when config is modified
        if ($this->useCache) {
            $this->getCache()->clear();
        }
    }

    /**
     * Validate current configuration
     */
    public function validate(bool $strict = false): array
    {
        if (empty($this->loadedConfig)) {
            $this->loadConfig();
        }

        $errors = $this->validateConfig($this->loadedConfig);
        $warnings = [];

        // Additional strict validation
        if ($strict) {
            // Check for deprecated options
            if (isset($this->loadedConfig['deprecated_option'])) {
                $warnings[] = "Configuration contains deprecated options";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }

    /**
     * Validate configuration structure and values
     */
    public function validateConfig(array $config): array
    {
        $errors = [];

        // Required top-level keys
        $requiredKeys = ['analyzers', 'rules', 'output', 'paths'];
        foreach ($requiredKeys as $key) {
            if (!isset($config[$key])) {
                $errors[] = "Missing required configuration section: {$key}";
            }
        }

        // Validate analyzers section
        if (isset($config['analyzers'])) {
            $errors = array_merge($errors, $this->validateAnalyzersConfig($config['analyzers']));
        }

        // Validate rules section
        if (isset($config['rules'])) {
            $errors = array_merge($errors, $this->validateRulesConfig($config['rules']));
        }

        // Validate output section
        if (isset($config['output'])) {
            $errors = array_merge($errors, $this->validateOutputConfig($config['output']));
        }

        return $errors;
    }

    /**
     * Load configuration — alias for loadConfig() for test/API compatibility
     */
    public function loadConfiguration(?string $path = null): array
    {
        return $this->loadConfig($path ? [$path] : []);
    }

    /**
     * Validate that a config array contains only known top-level keys.
     * Returns false if any unrecognised key is present.
     */
    public function validateConfiguration(array $config): bool
    {
        $knownKeys = ['analyzers', 'rules', 'output', 'paths', 'quality_gates', 'baselines', 'ai', 'reporting', 'severity_levels'];
        foreach (array_keys($config) as $key) {
            if (!in_array($key, $knownKeys, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Replace the entire loaded config — used in tests and integration scenarios
     */
    public function setConfiguration(array $config): void
    {
        $this->loadedConfig = $config;
        if ($this->useCache) {
            $this->getCache()->clear();
        }
    }

    /**
     * Clear configuration cache
     */
    public function clearCache(): void
    {
        $this->getCache()->clear();
        $this->loadedConfig = [];
    }

    /**
     * Get available configuration roles
     */
    public function getAvailableRoles(): array
    {
        $rolesPath = $this->projectRoot . '/config/roles';
        if (!is_dir($rolesPath)) {
            return [];
        }

        $roles = [];
        $files = glob($rolesPath . '/*.yaml');
        foreach ($files as $file) {
            $roles[] = basename($file, '.yaml');
        }

        return $roles;
    }

    /**
     * Get enabled rules from configuration
     */
    public function getEnabledRules(): array
    {
        if (empty($this->loadedConfig)) {
            $this->loadConfig();
        }

        $enabledRules = [];
        
        // Get rules from analyzers section
        if (isset($this->loadedConfig['analyzers'])) {
            foreach ($this->loadedConfig['analyzers'] as $analyzer => $analyzerConfig) {
                if (is_array($analyzerConfig) && isset($analyzerConfig['rules'])) {
                    foreach ($analyzerConfig['rules'] as $ruleId => $ruleConfig) {
                        if (is_array($ruleConfig) && ($ruleConfig['enabled'] ?? true)) {
                            $enabledRules[] = [
                                'id' => $ruleId,
                                'analyzer' => $analyzer,
                                'severity' => $ruleConfig['severity'] ?? 'warning',
                                'config' => $ruleConfig
                            ];
                        }
                    }
                }
            }
        }
        
        return $enabledRules;
    }

    /**
     * Get quality gate thresholds
     */
    public function getQualityGates(): array
    {
        if (empty($this->loadedConfig)) {
            $this->loadConfig();
        }

        return $this->loadedConfig['quality_gates'] ?? [
            'min_score' => 70,
            'max_critical' => 0,
            'max_errors' => 10,
            'max_warnings' => 50
        ];
    }

    /**
     * Get quality thresholds (alias for getQualityGates)
     */
    public function getQualityThresholds(): array
    {
        return $this->getQualityGates();
    }

    /**
     * Get baseline configuration
     */
    public function getBaselineConfig(): array
    {
        if (empty($this->loadedConfig)) {
            $this->loadConfig();
        }

        return $this->loadedConfig['baselines'] ?? [
            'storage_path' => 'var/baselines',
            'format' => 'json',
            'retention_days' => 90
        ];
    }

    /**
     * Build configuration from multiple sources
     */
    private function buildConfiguration(array $sources): array
    {
        $config = [];

        // Start with default configuration
        $defaultConfig = $this->loadConfigFile($this->getDefaultConfigPath());
        $config = array_merge($config, $defaultConfig);

        // Load and merge additional sources
        foreach ($sources as $source) {
            if (is_string($source)) {
                // loadConfigFile() returns [] for non-existent files — no pre-check needed
                $sourceConfig = $this->loadConfigFile($source);
                $config = $this->mergeConfigurations($config, $sourceConfig);
            } elseif (is_array($source)) {
                $config = $this->mergeConfigurations($config, $source);
            }
        }

        // Apply environment variable overrides
        $config = $this->applyEnvironmentOverrides($config);

        // Validate final configuration
        $errors = $this->validateConfig($config);
        if (!empty($errors)) {
            throw new \InvalidArgumentException('Configuration validation failed: ' . implode(', ', $errors));
        }

        return $config;
    }

    /**
     * Load configuration from YAML file
     */
    private function loadConfigFile(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return []; // Return empty array if file doesn't exist
        }

        try {
            $content = file_get_contents($filePath);
            $config = Yaml::parse($content);
            
            if (!is_array($config)) {
                throw new \InvalidArgumentException("Invalid YAML configuration in: {$filePath}");
            }

            return $config;
        } catch (\Exception $e) {
            throw new \InvalidArgumentException("Failed to parse YAML configuration {$filePath}: " . $e->getMessage());
        }
    }

    private function mergeConfigurations(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = $this->mergeConfigurations($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    private function applyEnvironmentOverrides(array $config): array
    {
        $envPrefix = 'ACQC_';
        
        foreach ($_ENV as $envKey => $envValue) {
            if (strpos($envKey, $envPrefix) === 0) {
                $configKey = strtolower(str_replace($envPrefix, '', $envKey));
                $configKey = str_replace('_', '.', $configKey);
                
                $value = $this->convertEnvValue($envValue);
                $this->setNestedValue($config, $configKey, $value);
            }
        }
        return $config;
    }

    private function convertEnvValue(string $value)
    {
        if (in_array(strtolower($value), ['true', 'false'])) {
            return strtolower($value) === 'true';
        }
        if (is_numeric($value)) {
            return strpos($value, '.') !== false ? (float) $value : (int) $value;
        }
        if (in_array($value[0] ?? '', ['{', '['])) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }
        return $value;
    }

    private function getNestedValue(array $config, string $key, $default = null)
    {
        $keys = explode('.', $key);
        $current = $config;

        foreach ($keys as $k) {
            if (!is_array($current) || !isset($current[$k])) {
                return $default;
            }
            $current = $current[$k];
        }
        return $current;
    }

    private function setNestedValue(array &$config, string $key, $value): void
    {
        $keys = explode('.', $key);
        $current = &$config;

        foreach ($keys as $i => $k) {
            if ($i === count($keys) - 1) {
                $current[$k] = $value;
            } else {
                if (!isset($current[$k]) || !is_array($current[$k])) {
                    $current[$k] = [];
                }
                $current = &$current[$k];
            }
        }
    }

    private function generateCacheKey(array $sources): string
    {
        $sourceHash = md5(serialize($sources));
        return self::CONFIG_CACHE_KEY . '_' . $sourceHash;
    }

    private function getDefaultConfigPath(): string
    {
        return $this->projectRoot . '/' . self::DEFAULT_CONFIG_FILE;
    }

    private function getRoleConfigPath(string $role): string
    {
        return $this->projectRoot . "/config/roles/{$role}.yaml";
    }

    private function validateAnalyzersConfig(array $analyzers): array
    {
        $errors = [];
        $validAnalyzers = ['magento', 'security', 'performance', 'quality', 'phpcs', 'phpmd', 'phpstan'];

        foreach ($analyzers as $analyzer => $config) {
            // Accept simple list format: ['phpcs', 'phpmd']
            if (is_int($analyzer) && is_string($config)) {
                if (!in_array($config, $validAnalyzers)) {
                    $errors[] = "Unknown analyzer: {$config}";
                }
                continue;
            }
            // Associative format: ['phpcs' => [...config...]]
            if (!in_array($analyzer, $validAnalyzers)) {
                $errors[] = "Unknown analyzer: {$analyzer}";
            }
            if (!is_array($config)) {
                $errors[] = "Analyzer configuration for {$analyzer} must be an array";
            }
        }
        return $errors;
    }

    private function validateRulesConfig(array $rules): array
    {
        $errors = [];
        $validSeverities = ['critical', 'error', 'warning', 'info'];
        foreach ($rules as $ruleName => $ruleConfig) {
            // Accept flat severity: ['severity' => 'error']
            if (is_string($ruleName) && is_string($ruleConfig)) {
                if ($ruleName === 'severity' && !in_array($ruleConfig, $validSeverities)) {
                    $errors[] = "Invalid severity level: {$ruleConfig}";
                }
                continue;
            }
            if (!is_array($ruleConfig)) {
                // Skip non-array rule configs silently — they may be simple key-value settings
                continue;
            }
            if (isset($ruleConfig['severity'])) {
                if (!in_array($ruleConfig['severity'], $validSeverities)) {
                    $errors[] = "Invalid severity level for rule {$ruleName}: {$ruleConfig['severity']}";
                }
            }
        }
        return $errors;
    }

    private function validateOutputConfig(array $output): array
    {
        $errors = [];
        if (isset($output['format'])) {
            $validFormats = ['console', 'json', 'html', 'markdown'];
            if (!in_array($output['format'], $validFormats)) {
                $errors[] = "Invalid output format: {$output['format']}";
            }
        }
        return $errors;
    }
} 