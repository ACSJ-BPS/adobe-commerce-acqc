<?php

declare(strict_types=1);

namespace ACQC\Core\Analysis;

use ACQC\Analyzers\Magento\SecurityAnalyzer as MagentoSecurityAnalyzer;
use ACQC\Analyzers\Magento\PerformanceAnalyzer as MagentoPerformanceAnalyzer;
use ACQC\Analyzers\Magento\DIAnalyzer;
use ACQC\Analyzers\Magento\DatabaseAnalyzer;
use ACQC\Analyzers\Magento\PluginAnalyzer;
use ACQC\Analyzers\Magento\ObserverAnalyzer;
use ACQC\Analyzers\Magento\ModuleStructureAnalyzer;
use ACQC\Analyzers\Magento\UpgradeCompatibilityAnalyzer;
use ACQC\Analyzers\Magento\ApiServiceAnalyzer;
use ACQC\Analyzers\Magento\GraphQLAnalyzer;
use ACQC\Analyzers\Performance\LayoutCacheAnalyzer;
use ACQC\Analyzers\Performance\OpCacheAnalyzer;
use ACQC\Analyzers\Performance\VarnishPerformanceAnalyzer;
use ACQC\Analyzers\Performance\RedisEnhancedAnalyzer;
use ACQC\Analyzers\Performance\APIRateLimitingAnalyzer;
use ACQC\Analyzers\Performance\ServerUptimeAnalyzer;
use ACQC\Analyzers\Performance\IndexersAnalyzer;
use ACQC\Analyzers\Performance\HttpProtocolAnalyzer;
use ACQC\Analyzers\Infrastructure\CDNAnalyzer;
use ACQC\Analyzers\Frontend\SecurityAnalyzer as FrontendSecurityAnalyzer;
use ACQC\Analyzers\Frontend\PerformanceAnalyzer as FrontendPerformanceAnalyzer;
use ACQC\Analyzers\Frontend\AccessibilityAnalyzer;
use ACQC\Analyzers\Frontend\SEOAnalyzer;
use ACQC\Analyzers\Frontend\PWAAnalyzer;
use ACQC\Analyzers\Frontend\ReactAnalyzer;
use ACQC\Analyzers\PHP\DeadCodeAnalyzer;
use ACQC\Analyzers\PHP\CodeHealthAnalyzer;
use ACQC\Analyzers\PHP\PHPCSAnalyzer;
use ACQC\Analyzers\PHP\PHPMDAnalyzer;
use ACQC\Analyzers\PHP\PHPStanAnalyzer;
use ACQC\Analyzers\PHP\PHPCPDAnalyzer;
use ACQC\Analyzers\PHP\SonarAnalyzer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Orchestrates multiple analyzers for comprehensive code analysis
 */
class AnalyzerOrchestrator
{
    private LoggerInterface $logger;
    private array $analyzers = [];
    private array $configuration = [];
    private array $severityMapping = [];

    public function __construct(array $configuration = [], ?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
        $this->configuration = $this->getDefaultConfiguration($configuration);
        $this->severityMapping = $this->configuration['severity_mapping'] ?? $this->getDefaultSeverityMapping();
        $this->initializeAnalyzers();
    }

    /**
     * Analyze files using all enabled analyzers
     */
    public function analyze(array $files, array $options = []): array
    {
        $this->logger->info('Starting comprehensive analysis with ' . count($this->analyzers) . ' analyzers');
        
        $allViolations = [];
        $analyzerResults = [];
        
        foreach ($this->analyzers as $name => $analyzer) {
            if (!$analyzer['enabled']) {
                continue;
            }
            
            try {
                $this->logger->info("Running analyzer: {$name}");
                
                $startTime = microtime(true);
                $violations = $analyzer['instance']->analyze($files, array_merge($options, [
                    'severity_mapping' => $this->severityMapping
                ]));
                $duration = microtime(true) - $startTime;
                
                $analyzerResults[$name] = [
                    'violations' => count($violations),
                    'duration' => round($duration, 2),
                    'status' => 'success'
                ];
                
                // Apply severity overrides
                $violations = $this->applySeverityOverrides($violations, $name);
                
                $allViolations = array_merge($allViolations, $violations);
                
                $this->logger->info("Analyzer {$name} found " . count($violations) . " violations in {$duration}s");
            } catch (\Exception $e) {
                $this->logger->error("Analyzer {$name} failed: " . $e->getMessage());
                $analyzerResults[$name] = [
                    'violations' => 0,
                    'duration' => 0,
                    'status' => 'failed',
                    'error' => $e->getMessage()
                ];
            }
        }
        
        $this->logger->info('Analysis complete: ' . count($allViolations) . ' total violations');
        
        return [
            'violations' => $allViolations,
            'analyzer_results' => $analyzerResults,
            'summary' => $this->generateSummary($allViolations, $analyzerResults)
        ];
    }

    /**
     * Initialize all available analyzers
     */
    private function initializeAnalyzers(): void
    {
        $config = $this->configuration;
        
        // Magento Analyzers
        if ($config['analyzers']['magento_security'] ?? true) {
            $this->addAnalyzer('magento_security', new MagentoSecurityAnalyzer($config, $this->logger));
        }
        
        if ($config['analyzers']['magento_performance'] ?? true) {
            $this->addAnalyzer('magento_performance', new MagentoPerformanceAnalyzer($config, $this->logger));
        }
        
        if ($config['analyzers']['magento_di'] ?? true) {
            $this->addAnalyzer('magento_di', new DIAnalyzer($config, $this->logger));
        }
        
        if ($config['analyzers']['magento_database'] ?? true) {
            $this->addAnalyzer('magento_database', new DatabaseAnalyzer($config, $this->logger));
        }
        
        if ($config['analyzers']['magento_plugin'] ?? true) {
            $this->addAnalyzer('magento_plugin', new PluginAnalyzer($config, $this->logger));
        }
        
        if ($config['analyzers']['magento_observer'] ?? true) {
            $this->addAnalyzer('magento_observer', new ObserverAnalyzer($config, $this->logger));
        }
        
        if ($config['analyzers']['magento_structure'] ?? true) {
            $this->addAnalyzer('magento_structure', new ModuleStructureAnalyzer($config, $this->logger));
        }
        
        if ($config['analyzers']['magento_upgrade'] ?? true) {
            $this->addAnalyzer('magento_upgrade', new UpgradeCompatibilityAnalyzer($config, $this->logger));
        }
        
        if ($config['analyzers']['magento_api_service'] ?? true) {
            $this->addAnalyzer('magento_api_service', new ApiServiceAnalyzer($config, $this->logger));
        }
        
        if ($config['analyzers']['magento_graphql'] ?? true) {
            $this->addAnalyzer('magento_graphql', new GraphQLAnalyzer($config, $this->logger));
        }
        
        // Performance Analyzers (Infrastructure)
        if ($config['analyzers']['performance_layout_cache'] ?? true) {
            $this->addAnalyzer('performance_layout_cache', new LayoutCacheAnalyzer($config, $this->logger));
        }
        
        if ($config['analyzers']['performance_opcache'] ?? true) {
            $this->addAnalyzer('performance_opcache', new OpCacheAnalyzer($config, $this->logger));
        }
        
        if ($config['analyzers']['performance_varnish'] ?? true) {
            $this->addAnalyzer('performance_varnish', new VarnishPerformanceAnalyzer($config, $this->logger));
        }
        
        if ($config['analyzers']['performance_redis'] ?? true) {
            $this->addAnalyzer('performance_redis', new RedisEnhancedAnalyzer($config, $this->logger));
        }
        
        if ($config['analyzers']['performance_api_rate_limiting'] ?? true) {
            $this->addAnalyzer('performance_api_rate_limiting', new APIRateLimitingAnalyzer($config, $this->logger));
        }
        
        if ($config['analyzers']['performance_server_uptime'] ?? true) {
            $this->addAnalyzer('performance_server_uptime', new ServerUptimeAnalyzer($config, $this->logger));
        }
        
        if ($config['analyzers']['performance_indexers'] ?? true) {
            $this->addAnalyzer('performance_indexers', new IndexersAnalyzer($config, $this->logger));
        }
        
        if ($config['analyzers']['performance_http_protocol'] ?? true) {
            $this->addAnalyzer('performance_http_protocol', new HttpProtocolAnalyzer($config, $this->logger));
        }
        
        // Infrastructure Analyzers
        if ($config['analyzers']['infrastructure_cdn'] ?? true) {
            $this->addAnalyzer('infrastructure_cdn', new CDNAnalyzer($config, $this->logger));
        }
        
        // Frontend Analyzers
        if ($config['analyzers']['frontend_security'] ?? true) {
            $this->addAnalyzer('frontend_security', new FrontendSecurityAnalyzer($config, $this->logger));
        }
        
        if ($config['analyzers']['frontend_performance'] ?? true) {
            $this->addAnalyzer('frontend_performance', new FrontendPerformanceAnalyzer($config, $this->logger));
        }
        
        if ($config['analyzers']['frontend_accessibility'] ?? true) {
            $this->addAnalyzer('frontend_accessibility', new AccessibilityAnalyzer($config, $this->logger));
        }
        
        if ($config['analyzers']['frontend_seo'] ?? true) {
            $this->addAnalyzer('frontend_seo', new SEOAnalyzer($config, $this->logger));
        }
        
        if ($config['analyzers']['frontend_pwa'] ?? true) {
            $this->addAnalyzer('frontend_pwa', new PWAAnalyzer($config, $this->logger));
        }
        
        if ($config['analyzers']['frontend_react'] ?? true) {
            $this->addAnalyzer('frontend_react', new ReactAnalyzer($config, $this->logger));
        }
        
        // PHP Analyzers
        if ($config['analyzers']['php_dead_code'] ?? true) {
            $this->addAnalyzer('php_dead_code', new DeadCodeAnalyzer($config, $this->logger));
        }
        
        if ($config['analyzers']['php_code_health'] ?? true) {
            $this->addAnalyzer('php_code_health', new CodeHealthAnalyzer($config, $this->logger));
        }
        
        // PHP Code Quality Tools
        if ($config['analyzers']['php_phpcs'] ?? true) {
            $this->addAnalyzer('php_phpcs', new PHPCSAnalyzer($config, $this->logger));
        }
        
        if ($config['analyzers']['php_phpmd'] ?? true) {
            $this->addAnalyzer('php_phpmd', new PHPMDAnalyzer($config, $this->logger));
        }
        
        if ($config['analyzers']['php_phpstan'] ?? true) {
            $this->addAnalyzer('php_phpstan', new PHPStanAnalyzer($config, $this->logger));
        }
        
        if ($config['analyzers']['php_phpcpd'] ?? true) {
            $this->addAnalyzer('php_phpcpd', new PHPCPDAnalyzer($config, $this->logger));
        }
        
        if ($config['analyzers']['php_sonar'] ?? false) {
            // Optional - requires SonarQube setup
            $this->addAnalyzer('php_sonar', new SonarAnalyzer($config, $this->logger));
        }
        
        $this->logger->info('Initialized ' . count($this->analyzers) . ' analyzers');
    }

    /**
     * Add an analyzer to the orchestrator
     */
    private function addAnalyzer(string $name, $analyzer): void
    {
        $this->analyzers[$name] = [
            'instance' => $analyzer,
            'enabled' => $analyzer->isAvailable(),
            'name' => $name
        ];
    }

    /**
     * Apply severity overrides based on configuration
     */
    private function applySeverityOverrides(array $violations, string $analyzerName): array
    {
        $overrides = $this->configuration['rule_severity_overrides'] ?? [];
        
        if (empty($overrides)) {
            return $violations;
        }
        
        foreach ($violations as $key => $violation) {
            $ruleId = $violation->getRuleId();
            
            if (isset($overrides[$ruleId])) {
                $violations[$key]->setSeverity($overrides[$ruleId]);
            }
            
            // Apply category-based promotions
            if ($this->configuration['promote_security_to_critical'] ?? false) {
                if ($violation->getType() === 'security') {
                    $violations[$key]->setSeverity('critical');
                }
            }
        }
        
        return $violations;
    }

    /**
     * Generate analysis summary
     */
    private function generateSummary(array $violations, array $analyzerResults): array
    {
        $summary = [
            'total_violations' => count($violations),
            'analyzers_run' => count($analyzerResults),
            'total_duration' => array_sum(array_column($analyzerResults, 'duration')),
            'by_severity' => [],
            'by_type' => [],
            'by_analyzer' => []
        ];
        
        // Group by severity
        foreach ($violations as $violation) {
            $severity = $violation->getSeverity();
            $type = $violation->getType();
            
            $summary['by_severity'][$severity] = ($summary['by_severity'][$severity] ?? 0) + 1;
            $summary['by_type'][$type] = ($summary['by_type'][$type] ?? 0) + 1;
        }
        
        // Group by analyzer
        foreach ($analyzerResults as $name => $result) {
            $summary['by_analyzer'][$name] = $result['violations'];
        }
        
        return $summary;
    }

    /**
     * Get default configuration
     */
    private function getDefaultConfiguration(array $userConfig): array
    {
        $defaults = [
            'analyzers' => [
                'magento_security' => true,
                'magento_performance' => true,
                'magento_di' => true,
                'magento_database' => true,
                'magento_plugin' => true,
                'magento_observer' => true,
                'magento_structure' => true,
                'magento_upgrade' => true,
                'magento_api_service' => true,
                'magento_graphql' => true,
                'frontend_security' => true,
                'frontend_performance' => true,
                'frontend_accessibility' => true,
                'frontend_seo' => true,
                'frontend_pwa' => true,
                'frontend_react' => true,
                'php_dead_code' => true,
                'php_code_health' => true,
                'php_phpcs' => true,
                'php_phpmd' => true,
                'php_phpstan' => true,
                'php_phpcpd' => true,
                'php_sonar' => false, // Optional - requires SonarQube
            ],
            'promote_security_to_critical' => false,
            'rule_severity_overrides' => [],
            'parallel_execution' => false,
            'cache_results' => false
        ];
        
        return array_replace_recursive($defaults, $userConfig);
    }

    /**
     * Get default severity mapping
     */
    private function getDefaultSeverityMapping(): array
    {
        return [
            'blocker' => 6,
            'critical' => 5,
            'error' => 4,
            'warning' => 3,
            'info' => 2,
            'minor' => 1
        ];
    }

    /**
     * Get enabled analyzers
     */
    public function getEnabledAnalyzers(): array
    {
        return array_filter($this->analyzers, fn($a) => $a['enabled']);
    }

    /**
     * Enable/disable specific analyzer
     */
    public function setAnalyzerEnabled(string $name, bool $enabled): void
    {
        if (isset($this->analyzers[$name])) {
            $this->analyzers[$name]['enabled'] = $enabled;
        }
    }

    /**
     * Set severity mapping
     */
    public function setSeverityMapping(array $mapping): void
    {
        $this->severityMapping = $mapping;
    }

    /**
     * Load severity configuration from file
     */
    public function loadSeverityConfiguration(string $filePath): void
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("Severity configuration file not found: {$filePath}");
        }
        
        $config = json_decode(file_get_contents($filePath), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Failed to parse severity configuration: " . json_last_error_msg());
        }
        
        if (isset($config['severity_mapping'])) {
            $this->severityMapping = $config['severity_mapping'];
        }
        
        if (isset($config['rule_severity_overrides'])) {
            $this->configuration['rule_severity_overrides'] = $config['rule_severity_overrides'];
        }
        
        if (isset($config['promote_security_to_critical'])) {
            $this->configuration['promote_security_to_critical'] = $config['promote_security_to_critical'];
        }
        
        $this->logger->info('Loaded severity configuration from ' . $filePath);
    }
}
