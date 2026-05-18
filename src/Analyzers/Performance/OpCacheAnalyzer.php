<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Performance;

use ACQC\Analyzers\AbstractAnalyzer;
use ACQC\Core\Rules\Violation;

/**
 * Analyzes PHP OPcache configuration for optimal Magento performance
 * 
 * OPcache is critical for PHP performance. This analyzer checks OPcache
 * settings and provides recommendations for Magento-specific optimization.
 */
class OpCacheAnalyzer extends AbstractAnalyzer
{
    private const RULE_OPCACHE_DISABLED = 'opcache-disabled';
    private const RULE_OPCACHE_MEMORY_LOW = 'opcache-memory-too-low';
    private const RULE_OPCACHE_MAX_FILES_LOW = 'opcache-max-files-too-low';
    private const RULE_OPCACHE_VALIDATE_TIMESTAMPS = 'opcache-validate-timestamps-enabled';
    private const RULE_OPCACHE_REVALIDATE_FREQ_LOW = 'opcache-revalidate-freq-too-low';
    private const RULE_OPCACHE_INTERNED_STRINGS_LOW = 'opcache-interned-strings-too-low';
    
    // Recommended values for Magento
    private const RECOMMENDED_MEMORY_MB = 512;
    private const RECOMMENDED_MAX_FILES = 65536;
    private const RECOMMENDED_INTERNED_STRINGS_MB = 16;
    private const RECOMMENDED_REVALIDATE_FREQ = 2; // seconds
    
    public function getName(): string
    {
        return 'opcache';
    }
    
    public function getVersion(): string
    {
        return '1.0.0';
    }
    
    public function isAvailable(): bool
    {
        return function_exists('opcache_get_configuration');
    }
    
    protected function getDefaultConfiguration(): array
    {
        return [
            'check_enabled' => true,
            'check_memory' => true,
            'check_max_files' => true,
            'check_validate_timestamps' => true,
            'check_revalidate_freq' => true,
            'check_interned_strings' => true,
            'production_mode_strict' => true, // Stricter checks for production
        ];
    }
    
    protected function executeAnalysis(array $files, array $options = []): array
    {
        // OPcache analysis is runtime-based, not file-based
        return [];
    }
    
    protected function parseOutput(string $output, array $files): array
    {
        return [];
    }
    
    /**
     * Main analysis method
     */
    public function analyze(array $files, array $options = []): array
    {
        $violations = [];
        
        if (!$this->isAvailable()) {
            $violations[] = $this->createDisabledViolation();
            return $violations;
        }
        
        $config = opcache_get_configuration();
        $status = opcache_get_status(false);
        
        if (!$config || !isset($config['directives'])) {
            return $violations;
        }
        
        $directives = $config['directives'];
        
        // Check if OPcache is enabled
        if ($this->configuration['check_enabled'] && !$directives['opcache.enable']) {
            $violations[] = $this->createDisabledViolation();
            return $violations; // No point checking other settings if disabled
        }
        
        // Check memory
        if ($this->configuration['check_memory']) {
            $memoryViolation = $this->checkMemoryConfiguration($directives);
            if ($memoryViolation) {
                $violations[] = $memoryViolation;
            }
        }
        
        // Check max accelerated files
        if ($this->configuration['check_max_files']) {
            $maxFilesViolation = $this->checkMaxFilesConfiguration($directives, $status);
            if ($maxFilesViolation) {
                $violations[] = $maxFilesViolation;
            }
        }
        
        // Check validate timestamps (production)
        if ($this->configuration['check_validate_timestamps']) {
            $timestampViolation = $this->checkValidateTimestamps($directives, $options);
            if ($timestampViolation) {
                $violations[] = $timestampViolation;
            }
        }
        
        // Check revalidate frequency
        if ($this->configuration['check_revalidate_freq']) {
            $freqViolation = $this->checkRevalidateFrequency($directives);
            if ($freqViolation) {
                $violations[] = $freqViolation;
            }
        }
        
        // Check interned strings buffer
        if ($this->configuration['check_interned_strings']) {
            $internedViolation = $this->checkInternedStringsBuffer($directives);
            if ($internedViolation) {
                $violations[] = $internedViolation;
            }
        }
        
        return $violations;
    }
    
    private function createDisabledViolation(): Violation
    {
        $violation = new Violation(
            self::RULE_OPCACHE_DISABLED,
            'PHP OPcache is disabled. OPcache is CRITICAL for PHP performance, ' .
            'caching compiled bytecode and reducing CPU usage by 70-90%. ' .
            'Magento performance will be severely impacted without OPcache.',
            'php.ini',
            0,
            0,
            Violation::SEVERITY_CRITICAL,
            Violation::TYPE_PERFORMANCE
        );
        
        $violation->setCategory('performance');
        $violation->setMetadata([
            'impact' => 'critical',
            'performance_degradation' => '70-90%',
            'fix_priority' => 'immediate',
        ]);
        
        $violation->setSuggestion(
            "Enable OPcache immediately in php.ini:\n\n" .
            "[opcache]\n" .
            "opcache.enable=1\n" .
            "opcache.enable_cli=1\n" .
            "opcache.memory_consumption=" . self::RECOMMENDED_MEMORY_MB . "\n" .
            "opcache.interned_strings_buffer=" . self::RECOMMENDED_INTERNED_STRINGS_MB . "\n" .
            "opcache.max_accelerated_files=" . self::RECOMMENDED_MAX_FILES . "\n" .
            "opcache.validate_timestamps=0  ; Production only\n" .
            "opcache.revalidate_freq=" . self::RECOMMENDED_REVALIDATE_FREQ . "\n" .
            "opcache.save_comments=1\n" .
            "opcache.fast_shutdown=1\n\n" .
            "After enabling, restart PHP-FPM:\n" .
            "  sudo systemctl restart php-fpm\n" .
            "  # or\n" .
            "  sudo service php8.1-fpm restart"
        );
        
        return $violation;
    }
    
    private function checkMemoryConfiguration(array $directives): ?Violation
    {
        $memoryMB = (int)$directives['opcache.memory_consumption'];
        
        if ($memoryMB < self::RECOMMENDED_MEMORY_MB) {
            $violation = new Violation(
                self::RULE_OPCACHE_MEMORY_LOW,
                sprintf(
                    'OPcache memory is set to %dMB but Magento recommends at least %dMB. ' .
                    'Low memory causes cache evictions, forcing recompilation and hurting performance.',
                    $memoryMB,
                    self::RECOMMENDED_MEMORY_MB
                ),
                'php.ini',
                0,
                0,
                Violation::SEVERITY_WARNING,
                Violation::TYPE_PERFORMANCE
            );
            
            $violation->setCategory('performance');
            $violation->setMetadata([
                'current_value' => $memoryMB,
                'recommended_value' => self::RECOMMENDED_MEMORY_MB,
                'impact' => 'medium',
            ]);
            
            $violation->setSuggestion(
                "Increase OPcache memory in php.ini:\n\n" .
                "opcache.memory_consumption=" . self::RECOMMENDED_MEMORY_MB . "\n\n" .
                "Restart PHP-FPM after change:\n" .
                "  sudo systemctl restart php-fpm\n\n" .
                "Monitor OPcache usage:\n" .
                "  php -r \"print_r(opcache_get_status());\""
            );
            
            return $violation;
        }
        
        return null;
    }
    
    private function checkMaxFilesConfiguration(array $directives, $status): ?Violation
    {
        $maxFiles = (int)$directives['opcache.max_accelerated_files'];
        
        if ($maxFiles < self::RECOMMENDED_MAX_FILES) {
            $currentFiles = $status ? ($status['opcache_statistics']['num_cached_scripts'] ?? 0) : 0;
            $usagePercent = $maxFiles > 0 ? round(($currentFiles / $maxFiles) * 100, 1) : 0;
            
            $violation = new Violation(
                self::RULE_OPCACHE_MAX_FILES_LOW,
                sprintf(
                    'OPcache max_accelerated_files is %d but Magento has 30,000+ PHP files. ' .
                    'Current usage: %d files (%.1f%%). Low limit causes cache misses and recompilation.',
                    $maxFiles,
                    $currentFiles,
                    $usagePercent
                ),
                'php.ini',
                0,
                0,
                $usagePercent > 80 ? Violation::SEVERITY_ERROR : Violation::SEVERITY_WARNING,
                Violation::TYPE_PERFORMANCE
            );
            
            $violation->setCategory('performance');
            $violation->setMetadata([
                'current_max' => $maxFiles,
                'recommended_max' => self::RECOMMENDED_MAX_FILES,
                'current_usage' => $currentFiles,
                'usage_percent' => $usagePercent,
                'impact' => $usagePercent > 80 ? 'high' : 'medium',
            ]);
            
            $violation->setSuggestion(
                "Increase max_accelerated_files in php.ini:\n\n" .
                "opcache.max_accelerated_files=" . self::RECOMMENDED_MAX_FILES . "\n\n" .
                "This value should be a prime number. Common options:\n" .
                "- 65536 (recommended for Magento)\n" .
                "- 32768 (minimum for Magento)\n" .
                "- 131072 (large installations)\n\n" .
                "Restart PHP-FPM after change:\n" .
                "  sudo systemctl restart php-fpm"
            );
            
            return $violation;
        }
        
        return null;
    }
    
    private function checkValidateTimestamps(array $directives, array $options): ?Violation
    {
        $validateTimestamps = (int)$directives['opcache.validate_timestamps'];
        $isProduction = $this->isProductionEnvironment($options);
        
        if ($validateTimestamps && $isProduction && $this->configuration['production_mode_strict']) {
            $violation = new Violation(
                self::RULE_OPCACHE_VALIDATE_TIMESTAMPS,
                'OPcache validate_timestamps is enabled in production. ' .
                'For maximum performance, disable timestamp validation in production. ' .
                'This eliminates filesystem checks on every request, improving response time by 5-10%.',
                'php.ini',
                0,
                0,
                Violation::SEVERITY_WARNING,
                Violation::TYPE_PERFORMANCE
            );
            
            $violation->setCategory('performance');
            $violation->setMetadata([
                'environment' => 'production',
                'current_value' => $validateTimestamps,
                'recommended_value' => 0,
                'performance_gain' => '5-10%',
            ]);
            
            $violation->setSuggestion(
                "Disable timestamp validation in production php.ini:\n\n" .
                "opcache.validate_timestamps=0\n\n" .
                "IMPORTANT: After disabling, you must clear OPcache after code deploys:\n" .
                "  Method 1: Restart PHP-FPM\n" .
                "    sudo systemctl restart php-fpm\n\n" .
                "  Method 2: Use opcache_reset() in deployment script\n" .
                "    php -r \"opcache_reset();\"\n\n" .
                "  Method 3: Use Magento command\n" .
                "    bin/magento cache:flush\n\n" .
                "For development/staging, keep validate_timestamps=1"
            );
            
            return $violation;
        }
        
        return null;
    }
    
    private function checkRevalidateFrequency(array $directives): ?Violation
    {
        $revalidateFreq = (int)$directives['opcache.revalidate_freq'];
        $validateTimestamps = (int)$directives['opcache.validate_timestamps'];
        
        if ($validateTimestamps && $revalidateFreq < self::RECOMMENDED_REVALIDATE_FREQ) {
            $violation = new Violation(
                self::RULE_OPCACHE_REVALIDATE_FREQ_LOW,
                sprintf(
                    'OPcache revalidate_freq is %d seconds. For better performance with timestamp validation enabled, ' .
                    'increase to at least %d seconds to reduce filesystem checks.',
                    $revalidateFreq,
                    self::RECOMMENDED_REVALIDATE_FREQ
                ),
                'php.ini',
                0,
                0,
                Violation::SEVERITY_INFO,
                Violation::TYPE_PERFORMANCE
            );
            
            $violation->setCategory('performance');
            $violation->setSuggestion(
                "Increase revalidate frequency in php.ini:\n\n" .
                "opcache.revalidate_freq=" . self::RECOMMENDED_REVALIDATE_FREQ . "\n\n" .
                "This controls how often (in seconds) OPcache checks for file changes.\n" .
                "Higher values = better performance, lower values = faster code updates.\n\n" .
                "Recommended values by environment:\n" .
                "- Development: 0-2 seconds\n" .
                "- Staging: 60 seconds\n" .
                "- Production: Disable validate_timestamps entirely"
            );
            
            return $violation;
        }
        
        return null;
    }
    
    private function checkInternedStringsBuffer(array $directives): ?Violation
    {
        $internedStringsMB = (int)$directives['opcache.interned_strings_buffer'];
        
        if ($internedStringsMB < self::RECOMMENDED_INTERNED_STRINGS_MB) {
            $violation = new Violation(
                self::RULE_OPCACHE_INTERNED_STRINGS_LOW,
                sprintf(
                    'OPcache interned_strings_buffer is %dMB but Magento recommends %dMB. ' .
                    'This buffer stores common strings (class names, function names) to reduce memory usage.',
                    $internedStringsMB,
                    self::RECOMMENDED_INTERNED_STRINGS_MB
                ),
                'php.ini',
                0,
                0,
                Violation::SEVERITY_INFO,
                Violation::TYPE_PERFORMANCE
            );
            
            $violation->setCategory('performance');
            $violation->setSuggestion(
                "Increase interned strings buffer in php.ini:\n\n" .
                "opcache.interned_strings_buffer=" . self::RECOMMENDED_INTERNED_STRINGS_MB . "\n\n" .
                "This reduces memory usage by storing common strings once.\n" .
                "Restart PHP-FPM after change."
            );
            
            return $violation;
        }
        
        return null;
    }
    
    private function isProductionEnvironment(array $options): bool
    {
        // Check if we can determine environment from Magento
        $magentoRoot = $options['magento_root'] ?? getcwd();
        $envFile = $magentoRoot . '/app/etc/env.php';
        
        if (file_exists($envFile)) {
            $env = include $envFile;
            $mode = $env['MAGE_MODE'] ?? ($env['mode'] ?? null);
            
            if ($mode === 'production') {
                return true;
            }
        }
        
        // Fallback: Check APPLICATION_ENV or MAGE_MODE env variables
        $appEnv = getenv('APPLICATION_ENV') ?: getenv('MAGE_MODE');
        return in_array($appEnv, ['production', 'prod'], true);
    }
    
    /**
     * Get OPcache statistics for reporting
     * 
     * @return array OPcache statistics
     */
    public function getStatistics(): array
    {
        if (!$this->isAvailable()) {
            return ['enabled' => false];
        }
        
        $config = opcache_get_configuration();
        $status = opcache_get_status(false);
        
        if (!$config || !$status) {
            return ['enabled' => false];
        }
        
        $directives = $config['directives'];
        $stats = $status['opcache_statistics'];
        
        return [
            'enabled' => true,
            'memory_mb' => (int)$directives['opcache.memory_consumption'],
            'max_files' => (int)$directives['opcache.max_accelerated_files'],
            'cached_scripts' => $stats['num_cached_scripts'] ?? 0,
            'cache_hits' => $stats['hits'] ?? 0,
            'cache_misses' => $stats['misses'] ?? 0,
            'hit_rate' => $stats['opcache_hit_rate'] ?? 0,
            'memory_usage' => $status['memory_usage'] ?? [],
            'interned_strings' => $status['interned_strings_usage'] ?? [],
        ];
    }
}
