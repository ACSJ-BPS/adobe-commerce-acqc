<?php

declare(strict_types=1);

namespace ACQC\Core\Rules;

use Symfony\Component\Finder\Finder;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class RuleLoader
{
    private LoggerInterface $logger;
    private array $searchPaths = [];
    private array $loadedClasses = [];
    private array $ruleFactories = [];
    private array $pluginDirectories = [];
    private bool $enableCaching = true;
    private string $cacheDirectory;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
        $this->cacheDirectory = sys_get_temp_dir() . '/acqc_rules';
        
        // Add default search paths
        $this->addSearchPath(__DIR__ . '/../../../Rules');
        $this->addSearchPath(__DIR__ . '/../../Rules');
    }

    /**
     * Add a search path for rules
     */
    public function addSearchPath(string $path): self
    {
        $realPath = realpath($path);
        if ($realPath && is_dir($realPath) && !in_array($realPath, $this->searchPaths)) {
            $this->searchPaths[] = $realPath;
            $this->logger->debug("Added rule search path: {$realPath}");
        }
        return $this;
    }

    /**
     * Add a plugin directory
     */
    public function addPluginDirectory(string $path): self
    {
        $realPath = realpath($path);
        if ($realPath && is_dir($realPath) && !in_array($realPath, $this->pluginDirectories)) {
            $this->pluginDirectories[] = $realPath;
            $this->logger->debug("Added plugin directory: {$realPath}");
        }
        return $this;
    }

    /**
     * Register a rule factory for custom rule types
     */
    public function registerRuleFactory(string $type, callable $factory): self
    {
        $this->ruleFactories[$type] = $factory;
        $this->logger->debug("Registered rule factory for type: {$type}");
        return $this;
    }

    /**
     * Enable or disable caching
     */
    public function setCaching(bool $enabled, ?string $cacheDirectory = null): self
    {
        $this->enableCaching = $enabled;
        if ($cacheDirectory !== null) {
            $this->cacheDirectory = $cacheDirectory;
        }

        if ($enabled && !is_dir($this->cacheDirectory)) {
            mkdir($this->cacheDirectory, 0755, true);
        }

        return $this;
    }

    /**
     * Load all available rules
     */
    public function loadRules(array $filters = []): RuleSet
    {
        $ruleSet = new RuleSet();
        $cacheKey = $this->generateCacheKey($filters);
        
        // Try to load from cache
        if ($this->enableCaching && $this->isCacheValid($cacheKey)) {
            $cached = $this->loadFromCache($cacheKey);
            if ($cached !== null) {
                $this->logger->debug("Loaded rules from cache");
                return $cached;
            }
        }

        // Load from class files
        $this->loadRulesFromClasses($ruleSet, $filters);
        
        // Load from configuration files
        $this->loadRulesFromConfig($ruleSet, $filters);
        
        // Load from plugins
        $this->loadRulesFromPlugins($ruleSet, $filters);

        // Save to cache
        if ($this->enableCaching) {
            $this->saveToCache($cacheKey, $ruleSet);
        }

        $this->logger->info("Loaded {$ruleSet->count()} rules");
        return $ruleSet;
    }

    /**
     * Load a specific rule by ID
     */
    public function loadRule(string $ruleId, array $config = []): ?Rule
    {
        // Try to find in already loaded classes
        foreach ($this->loadedClasses as $className) {
            if (class_exists($className)) {
                $reflection = new \ReflectionClass($className);
                if ($reflection->isSubclassOf(Rule::class) && !$reflection->isAbstract()) {
                    $tempRule = $reflection->newInstance($config);
                    if ($tempRule->getId() === $ruleId) {
                        return $tempRule;
                    }
                }
            }
        }

        // Search in all available files
        $this->discoverRuleClasses();
        
        foreach ($this->loadedClasses as $className) {
            if (class_exists($className)) {
                $reflection = new \ReflectionClass($className);
                if ($reflection->isSubclassOf(Rule::class) && !$reflection->isAbstract()) {
                    $tempRule = $reflection->newInstance($config);
                    if ($tempRule->getId() === $ruleId) {
                        return $tempRule;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Load rules from a specific file
     */
    public function loadRulesFromFile(string $filePath, array $config = []): RuleSet
    {
        $ruleSet = new RuleSet();

        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("Rule file not found: {$filePath}");
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        switch ($extension) {
            case 'php':
                $this->loadFromPhpFile($filePath, $ruleSet, $config);
                break;
            case 'json':
                $this->loadFromJsonFile($filePath, $ruleSet, $config);
                break;
            case 'yaml':
            case 'yml':
                $this->loadFromYamlFile($filePath, $ruleSet, $config);
                break;
            default:
                throw new \InvalidArgumentException("Unsupported rule file format: {$extension}");
        }

        return $ruleSet;
    }

    /**
     * Create a rule from configuration array
     */
    public function createRuleFromConfig(array $config): ?Rule
    {
        if (!isset($config['type']) || !isset($config['class'])) {
            return null;
        }

        $type = $config['type'];
        $className = $config['class'];

        // Check if we have a factory for this type
        if (isset($this->ruleFactories[$type])) {
            return call_user_func($this->ruleFactories[$type], $config);
        }

        // Try to instantiate the class directly
        if (class_exists($className)) {
            $reflection = new \ReflectionClass($className);
            if ($reflection->isSubclassOf(Rule::class) && !$reflection->isAbstract()) {
                return $reflection->newInstance($config);
            }
        }

        $this->logger->warning("Cannot create rule of type '{$type}' with class '{$className}'");
        return null;
    }

    /**
     * Get available rule classes
     */
    public function getAvailableRuleClasses(): array
    {
        $this->discoverRuleClasses();
        return $this->loadedClasses;
    }

    /**
     * Validate rule class
     */
    public function validateRuleClass(string $className): array
    {
        $errors = [];

        if (!class_exists($className)) {
            $errors[] = "Class '{$className}' does not exist";
            return $errors;
        }

        $reflection = new \ReflectionClass($className);

        if (!$reflection->isSubclassOf(Rule::class)) {
            $errors[] = "Class '{$className}' does not extend Rule class";
        }

        if ($reflection->isAbstract()) {
            $errors[] = "Class '{$className}' is abstract and cannot be instantiated";
        }

        if (!$reflection->hasMethod('execute')) {
            $errors[] = "Class '{$className}' does not implement execute method";
        }

        try {
            $tempRule = $reflection->newInstance();
            if (empty($tempRule->getId())) {
                $errors[] = "Rule '{$className}' has empty ID";
            }
        } catch (\Exception $e) {
            $errors[] = "Cannot instantiate rule '{$className}': " . $e->getMessage();
        }

        return $errors;
    }

    /**
     * Clear cache
     */
    public function clearCache(): self
    {
        if (is_dir($this->cacheDirectory)) {
            $files = glob($this->cacheDirectory . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        $this->logger->debug("Cleared rule cache");
        return $this;
    }

    /**
     * Load rules from PHP class files
     */
    private function loadRulesFromClasses(RuleSet $ruleSet, array $filters): void
    {
        $this->discoverRuleClasses();

        foreach ($this->loadedClasses as $className) {
            try {
                if (class_exists($className)) {
                    $reflection = new \ReflectionClass($className);
                    if ($reflection->isSubclassOf(Rule::class) && !$reflection->isAbstract()) {
                        $rule = $reflection->newInstance();
                        
                        if ($this->matchesFilters($rule, $filters)) {
                            $ruleSet->addRule($rule);
                            $this->logger->debug("Loaded rule: {$rule->getId()}");
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->logger->error("Failed to load rule class '{$className}': " . $e->getMessage());
            }
        }
    }

    /**
     * Load rules from configuration files
     */
    private function loadRulesFromConfig(RuleSet $ruleSet, array $filters): void
    {
        foreach ($this->searchPaths as $path) {
            $configFiles = [
                $path . '/rules.json',
                $path . '/rules.yaml',
                $path . '/rules.yml',
            ];

            foreach ($configFiles as $configFile) {
                if (file_exists($configFile)) {
                    try {
                        $configRules = $this->loadRulesFromFile($configFile);
                        foreach ($configRules as $rule) {
                            if ($this->matchesFilters($rule, $filters)) {
                                $ruleSet->addRule($rule);
                            }
                        }
                    } catch (\Exception $e) {
                        $this->logger->error("Failed to load config file '{$configFile}': " . $e->getMessage());
                    }
                }
            }
        }
    }

    /**
     * Load rules from plugins
     */
    private function loadRulesFromPlugins(RuleSet $ruleSet, array $filters): void
    {
        foreach ($this->pluginDirectories as $pluginDir) {
            $this->loadPluginRules($pluginDir, $ruleSet, $filters);
        }
    }

    /**
     * Discover all rule classes in search paths
     */
    private function discoverRuleClasses(): void
    {
        foreach ($this->searchPaths as $path) {
            if (!is_dir($path)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()->name('*.php')->in($path);

            foreach ($finder as $file) {
                $this->loadClassFromFile($file->getRealPath());
            }
        }
    }

    /**
     * Load class from PHP file
     */
    private function loadClassFromFile(string $filePath): void
    {
        if (in_array($filePath, $this->loadedClasses)) {
            return;
        }

        try {
            // Extract namespace and class name from file
            $className = $this->extractClassNameFromFile($filePath);
            if ($className) {
                require_once $filePath;
                $this->loadedClasses[] = $className;
                $this->logger->debug("Discovered rule class: {$className}");
            }
        } catch (\Exception $e) {
            $this->logger->error("Failed to load class from '{$filePath}': " . $e->getMessage());
        }
    }

    /**
     * Extract class name from PHP file
     */
    private function extractClassNameFromFile(string $filePath): ?string
    {
        $content = file_get_contents($filePath);
        $namespace = '';
        $className = '';

        // Extract namespace
        if (preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
            $namespace = trim($matches[1]);
        }

        // Extract class name
        if (preg_match('/class\s+(\w+)/', $content, $matches)) {
            $className = trim($matches[1]);
        }

        if ($namespace && $className) {
            return $namespace . '\\' . $className;
        }

        return $className ?: null;
    }

    /**
     * Load from PHP file
     */
    private function loadFromPhpFile(string $filePath, RuleSet $ruleSet, array $config): void
    {
        $this->loadClassFromFile($filePath);
        // Additional PHP-specific loading logic can go here
    }

    /**
     * Load from JSON file
     */
    private function loadFromJsonFile(string $filePath, RuleSet $ruleSet, array $config): void
    {
        $content = file_get_contents($filePath);
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException("Invalid JSON in file: {$filePath}");
        }

        $this->processConfigData($data, $ruleSet, $config);
    }

    /**
     * Load from YAML file
     */
    private function loadFromYamlFile(string $filePath, RuleSet $ruleSet, array $config): void
    {
        if (!function_exists('yaml_parse_file')) {
            throw new \RuntimeException("YAML extension not available");
        }

        $data = yaml_parse_file($filePath);
        if ($data === false) {
            throw new \InvalidArgumentException("Invalid YAML in file: {$filePath}");
        }

        $this->processConfigData($data, $ruleSet, $config);
    }

    /**
     * Process configuration data
     */
    private function processConfigData(array $data, RuleSet $ruleSet, array $config): void
    {
        if (isset($data['rules']) && is_array($data['rules'])) {
            foreach ($data['rules'] as $ruleConfig) {
                $mergedConfig = array_merge($config, $ruleConfig);
                $rule = $this->createRuleFromConfig($mergedConfig);
                if ($rule) {
                    $ruleSet->addRule($rule);
                }
            }
        }
    }

    /**
     * Load plugin rules
     */
    private function loadPluginRules(string $pluginDir, RuleSet $ruleSet, array $filters): void
    {
        $pluginFile = $pluginDir . '/plugin.php';
        if (file_exists($pluginFile)) {
            try {
                $plugin = require $pluginFile;
                if (is_array($plugin) && isset($plugin['rules'])) {
                    foreach ($plugin['rules'] as $ruleClass) {
                        if (class_exists($ruleClass)) {
                            $rule = new $ruleClass();
                            if ($rule instanceof Rule && $this->matchesFilters($rule, $filters)) {
                                $ruleSet->addRule($rule);
                                $this->logger->debug("Loaded plugin rule: {$rule->getId()}");
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->logger->error("Failed to load plugin from '{$pluginDir}': " . $e->getMessage());
            }
        }
    }

    /**
     * Check if rule matches filters
     */
    private function matchesFilters(Rule $rule, array $filters): bool
    {
        if (empty($filters)) {
            return true;
        }

        if (isset($filters['categories'])) {
            $categories = (array) $filters['categories'];
            if (!in_array($rule->getCategory(), $categories)) {
                return false;
            }
        }

        if (isset($filters['tags'])) {
            $requiredTags = (array) $filters['tags'];
            $ruleTags = $rule->getTags();
            foreach ($requiredTags as $tag) {
                if (!in_array($tag, $ruleTags)) {
                    return false;
                }
            }
        }

        if (isset($filters['severity'])) {
            $severities = (array) $filters['severity'];
            if (!in_array($rule->getSeverity(), $severities)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Generate cache key
     */
    private function generateCacheKey(array $filters): string
    {
        $key = 'rules_' . md5(serialize([
            'filters' => $filters,
            'search_paths' => $this->searchPaths,
            'plugin_directories' => $this->pluginDirectories,
        ]));
        return $key;
    }

    /**
     * Check if cache is valid
     */
    private function isCacheValid(string $cacheKey): bool
    {
        $cacheFile = $this->cacheDirectory . '/' . $cacheKey . '.cache';
        if (!file_exists($cacheFile)) {
            return false;
        }

        $cacheTime = filemtime($cacheFile);
        $currentTime = time();
        
        // Cache is valid for 1 hour
        return ($currentTime - $cacheTime) < 3600;
    }

    /**
     * Load from cache
     */
    private function loadFromCache(string $cacheKey): ?RuleSet
    {
        $cacheFile = $this->cacheDirectory . '/' . $cacheKey . '.cache';
        if (file_exists($cacheFile)) {
            try {
                $data = unserialize(file_get_contents($cacheFile));
                if ($data instanceof RuleSet) {
                    return $data;
                }
            } catch (\Exception $e) {
                $this->logger->warning("Failed to load from cache: " . $e->getMessage());
            }
        }
        return null;
    }

    /**
     * Save to cache
     */
    private function saveToCache(string $cacheKey, RuleSet $ruleSet): void
    {
        try {
            $cacheFile = $this->cacheDirectory . '/' . $cacheKey . '.cache';
            file_put_contents($cacheFile, serialize($ruleSet));
            $this->logger->debug("Saved rules to cache: {$cacheFile}");
        } catch (\Exception $e) {
            $this->logger->warning("Failed to save to cache: " . $e->getMessage());
        }
    }
} 