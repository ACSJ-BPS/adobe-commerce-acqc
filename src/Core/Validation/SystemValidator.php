<?php

declare(strict_types=1);

namespace ACQC\Core\Validation;

use ACQC\Core\AnalyzerRegistry;
use ACQC\Core\Rules\RuleEngine;
use ACQC\Core\Rules\RuleLoader;
use ACQC\Core\Analysis\AnalyzerOrchestrator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Comprehensive system validator to ensure all analyzers and rules are working
 */
class SystemValidator
{
    private AnalyzerRegistry $analyzerRegistry;
    private RuleEngine $ruleEngine;
    private AnalyzerOrchestrator $orchestrator;
    private LoggerInterface $logger;
    private array $validationResults = [];

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
        $this->analyzerRegistry = new AnalyzerRegistry(null, $this->logger);
        $this->ruleEngine = new RuleEngine(new RuleLoader($this->logger), $this->logger);
        $this->orchestrator = new AnalyzerOrchestrator([], $this->logger);
    }

    /**
     * Run comprehensive validation
     */
    public function validate(): array
    {
        $this->logger->info('Starting comprehensive system validation');
        
        $results = [
            'timestamp' => date('Y-m-d H:i:s'),
            'overall_status' => 'pending',
            'analyzers' => $this->validateAnalyzers(),
            'rules' => $this->validateRules(),
            'orchestrator' => $this->validateOrchestrator(),
            'integration' => $this->validateIntegration(),
            'dependencies' => $this->validateDependencies(),
            'performance' => $this->measurePerformance(),
            'recommendations' => []
        ];

        // Determine overall status
        $results['overall_status'] = $this->determineOverallStatus($results);
        
        // Generate recommendations
        $results['recommendations'] = $this->generateRecommendations($results);
        
        $this->validationResults = $results;
        
        $this->logger->info('System validation complete');
        
        return $results;
    }

    /**
     * Validate all analyzers
     */
    private function validateAnalyzers(): array
    {
        $this->logger->info('Validating analyzers');
        
        $allAnalyzers = $this->analyzerRegistry->getAllAnalyzers();
        $analyzerInfo = $this->analyzerRegistry->getAnalyzerInfo();
        
        $results = [
            'total_count' => count($allAnalyzers),
            'available_count' => 0,
            'unavailable_count' => 0,
            'categories' => [],
            'analyzers' => [],
            'status' => 'unknown'
        ];

        foreach ($allAnalyzers as $id => $analyzer) {
            $info = $analyzerInfo[$id] ?? [];
            $isAvailable = $info['available'] ?? false;
            
            if ($isAvailable) {
                $results['available_count']++;
            } else {
                $results['unavailable_count']++;
            }

            $category = $info['category'] ?? 'unknown';
            if (!isset($results['categories'][$category])) {
                $results['categories'][$category] = [
                    'total' => 0,
                    'available' => 0,
                    'unavailable' => 0
                ];
            }
            
            $results['categories'][$category]['total']++;
            if ($isAvailable) {
                $results['categories'][$category]['available']++;
            } else {
                $results['categories'][$category]['unavailable']++;
            }

            $results['analyzers'][$id] = [
                'name' => $info['name'] ?? $id,
                'available' => $isAvailable,
                'category' => $category,
                'version' => $info['version'] ?? 'unknown',
                'description' => $info['description'] ?? '',
                'dependencies_met' => $this->checkAnalyzerDependencies($id),
                'test_result' => $this->testAnalyzer($id, $analyzer)
            ];
        }

        // Determine status
        if ($results['available_count'] === $results['total_count']) {
            $results['status'] = 'excellent';
        } elseif ($results['available_count'] >= $results['total_count'] * 0.8) {
            $results['status'] = 'good';
        } elseif ($results['available_count'] >= $results['total_count'] * 0.5) {
            $results['status'] = 'warning';
        } else {
            $results['status'] = 'critical';
        }

        return $results;
    }

    /**
     * Validate all rules
     */
    private function validateRules(): array
    {
        $this->logger->info('Validating rules');
        
        $results = [
            'rules_loaded' => 0,
            'rules_by_category' => [],
            'rule_files' => [],
            'status' => 'unknown'
        ];

        // Find all rule files
        $ruleDir = __DIR__ . '/../../Rules/Magento';
        if (is_dir($ruleDir)) {
            $ruleFiles = glob($ruleDir . '/*Rules.php');
            
            foreach ($ruleFiles as $file) {
                $basename = basename($file, '.php');
                $className = "ACQC\\Rules\\Magento\\{$basename}";
                
                $fileResult = [
                    'file' => $basename,
                    'path' => $file,
                    'exists' => file_exists($file),
                    'readable' => is_readable($file),
                    'class_exists' => false,
                    'methods' => [],
                    'rule_count' => 0
                ];

                if (file_exists($file) && is_readable($file)) {
                    require_once $file;
                    
                    if (class_exists($className)) {
                        $fileResult['class_exists'] = true;
                        
                        $reflection = new \ReflectionClass($className);
                        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
                        
                        foreach ($methods as $method) {
                            if (strpos($method->getName(), 'detect') === 0 || 
                                strpos($method->getName(), 'check') === 0) {
                                $fileResult['methods'][] = $method->getName();
                                $fileResult['rule_count']++;
                            }
                        }
                    }
                }

                $results['rule_files'][$basename] = $fileResult;
                $results['rules_loaded'] += $fileResult['rule_count'];
            }
        }

        // Categorize rules
        foreach ($results['rule_files'] as $fileName => $fileData) {
            $category = str_replace('Rules', '', $fileName);
            $results['rules_by_category'][$category] = $fileData['rule_count'];
        }

        $results['status'] = $results['rules_loaded'] > 0 ? 'good' : 'critical';

        return $results;
    }

    /**
     * Validate orchestrator
     */
    private function validateOrchestrator(): array
    {
        $this->logger->info('Validating orchestrator');
        
        $enabledAnalyzers = $this->orchestrator->getEnabledAnalyzers();
        
        $results = [
            'orchestrator_exists' => class_exists('ACQC\\Core\\Analysis\\AnalyzerOrchestrator'),
            'enabled_analyzers' => count($enabledAnalyzers),
            'analyzer_details' => [],
            'status' => 'unknown'
        ];

        foreach ($enabledAnalyzers as $name => $analyzerData) {
            $results['analyzer_details'][$name] = [
                'enabled' => $analyzerData['enabled'],
                'instance_exists' => isset($analyzerData['instance']),
                'instance_type' => isset($analyzerData['instance']) ? get_class($analyzerData['instance']) : null
            ];
        }

        $results['status'] = count($enabledAnalyzers) > 10 ? 'excellent' : 
                            (count($enabledAnalyzers) > 5 ? 'good' : 'warning');

        return $results;
    }

    /**
     * Validate integration between components
     */
    private function validateIntegration(): array
    {
        $this->logger->info('Validating component integration');
        
        $results = [
            'registry_to_analyzers' => 'unknown',
            'orchestrator_to_analyzers' => 'unknown',
            'rules_to_engine' => 'unknown',
            'report_command_integration' => 'unknown',
            'analyze_command_integration' => 'unknown',
            'status' => 'unknown'
        ];

        // Test registry integration
        try {
            $testAnalyzers = $this->analyzerRegistry->getAllAnalyzers();
            $results['registry_to_analyzers'] = count($testAnalyzers) > 0 ? 'working' : 'failed';
        } catch (\Exception $e) {
            $results['registry_to_analyzers'] = 'failed: ' . $e->getMessage();
        }

        // Test orchestrator integration
        try {
            $enabledAnalyzers = $this->orchestrator->getEnabledAnalyzers();
            $results['orchestrator_to_analyzers'] = count($enabledAnalyzers) > 0 ? 'working' : 'failed';
        } catch (\Exception $e) {
            $results['orchestrator_to_analyzers'] = 'failed: ' . $e->getMessage();
        }

        // Test rule engine integration
        try {
            $ruleLoader = new RuleLoader($this->logger);
            $ruleSet = $ruleLoader->loadRules();
            $results['rules_to_engine'] = $ruleSet ? 'working' : 'failed';
        } catch (\Exception $e) {
            $results['rules_to_engine'] = 'failed: ' . $e->getMessage();
        }

        // Check command files exist
        $reportCommand = __DIR__ . '/../../Commands/ReportCommand.php';
        $analyzeCommand = __DIR__ . '/../../Commands/AnalyzeCommand.php';
        
        $results['report_command_integration'] = file_exists($reportCommand) ? 'exists' : 'missing';
        $results['analyze_command_integration'] = file_exists($analyzeCommand) ? 'exists' : 'missing';

        // Determine status
        $workingCount = 0;
        foreach ($results as $key => $value) {
            if ($key !== 'status' && (strpos($value, 'working') !== false || $value === 'exists')) {
                $workingCount++;
            }
        }
        
        $totalChecks = count($results) - 1;
        $results['status'] = $workingCount === $totalChecks ? 'excellent' : 
                            ($workingCount >= $totalChecks * 0.7 ? 'good' : 'warning');

        return $results;
    }

    /**
     * Validate dependencies
     */
    private function validateDependencies(): array
    {
        $this->logger->info('Validating dependencies');
        
        $results = [
            'php_version' => PHP_VERSION,
            'php_version_ok' => version_compare(PHP_VERSION, '8.1.0', '>='),
            'required_extensions' => [],
            'optional_extensions' => [],
            'composer_installed' => file_exists(__DIR__ . '/../../../vendor/autoload.php'),
            'external_tools' => [],
            'status' => 'unknown'
        ];

        // Check required extensions
        $requiredExtensions = ['json', 'mbstring', 'xml', 'dom', 'simplexml'];
        foreach ($requiredExtensions as $ext) {
            $results['required_extensions'][$ext] = extension_loaded($ext);
        }

        // Check optional extensions
        $optionalExtensions = ['pcntl', 'posix', 'zip', 'gd', 'imagick'];
        foreach ($optionalExtensions as $ext) {
            $results['optional_extensions'][$ext] = extension_loaded($ext);
        }

        // Check external tools
        $externalTools = ['phpcs', 'phpmd', 'phpstan', 'phpcpd', 'git'];
        foreach ($externalTools as $tool) {
            exec("which {$tool} 2>/dev/null", $output, $returnCode);
            $results['external_tools'][$tool] = $returnCode === 0 ? 'available' : 'not found';
        }

        // Determine status
        $allRequired = array_reduce($results['required_extensions'], fn($carry, $item) => $carry && $item, true);
        $results['status'] = $allRequired && $results['php_version_ok'] ? 'good' : 'critical';

        return $results;
    }

    /**
     * Measure performance
     */
    private function measurePerformance(): array
    {
        $this->logger->info('Measuring performance');
        
        $results = [
            'startup_time' => 0.0,
            'analyzer_initialization_time' => 0.0,
            'rule_loading_time' => 0.0,
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'status' => 'unknown'
        ];

        // Measure startup time
        $startTime = microtime(true);
        $testRegistry = new AnalyzerRegistry(null, new NullLogger());
        $results['startup_time'] = microtime(true) - $startTime;

        // Measure analyzer initialization
        $startTime = microtime(true);
        $testOrchestrator = new AnalyzerOrchestrator([], new NullLogger());
        $results['analyzer_initialization_time'] = microtime(true) - $startTime;

        // Measure rule loading
        $startTime = microtime(true);
        $testRuleLoader = new RuleLoader(new NullLogger());
        $testRuleLoader->loadRules();
        $results['rule_loading_time'] = microtime(true) - $startTime;

        // Format memory usage
        $results['memory_usage_formatted'] = $this->formatBytes($results['memory_usage']);
        $results['memory_peak_formatted'] = $this->formatBytes($results['memory_peak']);

        // Determine status
        $totalTime = $results['startup_time'] + $results['analyzer_initialization_time'] + $results['rule_loading_time'];
        $results['status'] = $totalTime < 1.0 ? 'excellent' : ($totalTime < 3.0 ? 'good' : 'warning');

        return $results;
    }

    /**
     * Test individual analyzer
     */
    private function testAnalyzer(string $id, $analyzer): array
    {
        try {
            // Create a minimal test file
            $testFile = sys_get_temp_dir() . '/acqc_test_' . uniqid() . '.php';
            file_put_contents($testFile, "<?php\n\nclass TestClass {}\n");
            
            $result = [
                'can_instantiate' => is_object($analyzer),
                'has_analyze_method' => method_exists($analyzer, 'analyze'),
                'can_run' => false,
                'error' => null
            ];

            if ($result['has_analyze_method']) {
                try {
                    $analyzer->analyze([$testFile], []);
                    $result['can_run'] = true;
                } catch (\Exception $e) {
                    $result['error'] = $e->getMessage();
                }
            }

            @unlink($testFile);
            
            return $result;
        } catch (\Exception $e) {
            return [
                'can_instantiate' => false,
                'has_analyze_method' => false,
                'can_run' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Check analyzer dependencies
     */
    private function checkAnalyzerDependencies(string $id): bool
    {
        // Add specific dependency checks based on analyzer type
        if (strpos($id, 'phpcs') !== false) {
            exec('which phpcs 2>/dev/null', $output, $returnCode);
            return $returnCode === 0;
        }
        
        if (strpos($id, 'phpmd') !== false) {
            exec('which phpmd 2>/dev/null', $output, $returnCode);
            return $returnCode === 0;
        }
        
        if (strpos($id, 'phpstan') !== false) {
            exec('which phpstan 2>/dev/null', $output, $returnCode);
            return $returnCode === 0;
        }
        
        return true; // Default to true for internal analyzers
    }

    /**
     * Determine overall status
     */
    private function determineOverallStatus(array $results): string
    {
        $statuses = [
            $results['analyzers']['status'],
            $results['rules']['status'],
            $results['orchestrator']['status'],
            $results['integration']['status'],
            $results['dependencies']['status'],
            $results['performance']['status']
        ];

        if (in_array('critical', $statuses)) {
            return 'critical';
        }
        
        if (in_array('warning', $statuses)) {
            return 'warning';
        }
        
        if (count(array_filter($statuses, fn($s) => $s === 'excellent')) >= 4) {
            return 'excellent';
        }
        
        return 'good';
    }

    /**
     * Generate recommendations
     */
    private function generateRecommendations(array $results): array
    {
        $recommendations = [];

        // Analyzer recommendations
        if ($results['analyzers']['unavailable_count'] > 0) {
            $recommendations[] = [
                'severity' => 'warning',
                'category' => 'analyzers',
                'message' => "{$results['analyzers']['unavailable_count']} analyzers are unavailable. Install missing dependencies.",
                'action' => 'Install: phpcs, phpmd, phpstan, phpcpd'
            ];
        }

        // Rule recommendations
        if ($results['rules']['rules_loaded'] < 50) {
            $recommendations[] = [
                'severity' => 'info',
                'category' => 'rules',
                'message' => "Only {$results['rules']['rules_loaded']} rules loaded. Consider adding more comprehensive rules.",
                'action' => 'Add more rule definitions in src/Rules/Magento'
            ];
        }

        // Dependency recommendations
        if (!$results['dependencies']['php_version_ok']) {
            $recommendations[] = [
                'severity' => 'critical',
                'category' => 'dependencies',
                'message' => "PHP version {$results['dependencies']['php_version']} is too old. Upgrade to PHP 8.1+",
                'action' => 'Upgrade PHP version'
            ];
        }

        $missingExtensions = array_filter($results['dependencies']['required_extensions'], fn($loaded) => !$loaded);
        if (count($missingExtensions) > 0) {
            $recommendations[] = [
                'severity' => 'critical',
                'category' => 'dependencies',
                'message' => "Missing required PHP extensions: " . implode(', ', array_keys($missingExtensions)),
                'action' => 'Install missing PHP extensions'
            ];
        }

        // Performance recommendations
        if ($results['performance']['status'] === 'warning') {
            $recommendations[] = [
                'severity' => 'info',
                'category' => 'performance',
                'message' => "Initialization time is high. Consider implementing caching.",
                'action' => 'Enable caching in configuration'
            ];
        }

        // Integration recommendations
        if ($results['integration']['status'] !== 'excellent') {
            $recommendations[] = [
                'severity' => 'warning',
                'category' => 'integration',
                'message' => "Some integrations are not optimal. Review component connections.",
                'action' => 'Check integration status details'
            ];
        }

        return $recommendations;
    }

    /**
     * Format bytes to human-readable
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;
        
        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }
        
        return round($bytes, 2) . ' ' . $units[$unitIndex];
    }

    /**
     * Get validation results
     */
    public function getValidationResults(): array
    {
        return $this->validationResults;
    }

    /**
     * Export validation results to file
     */
    public function exportToFile(string $filePath, string $format = 'json'): bool
    {
        if (empty($this->validationResults)) {
            return false;
        }

        $content = match ($format) {
            'json' => json_encode($this->validationResults, JSON_PRETTY_PRINT),
            'yaml' => $this->arrayToYaml($this->validationResults),
            'md' => $this->arrayToMarkdown($this->validationResults),
            default => json_encode($this->validationResults, JSON_PRETTY_PRINT)
        };

        return file_put_contents($filePath, $content) !== false;
    }

    /**
     * Convert array to YAML (simple version)
     */
    private function arrayToYaml(array $data, int $indent = 0): string
    {
        $yaml = '';
        $indentStr = str_repeat('  ', $indent);
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $yaml .= "{$indentStr}{$key}:\n";
                $yaml .= $this->arrayToYaml($value, $indent + 1);
            } else {
                $yaml .= "{$indentStr}{$key}: " . var_export($value, true) . "\n";
            }
        }
        
        return $yaml;
    }

    /**
     * Convert array to Markdown
     */
    private function arrayToMarkdown(array $data): string
    {
        $md = "# ACQC System Validation Report\n\n";
        $md .= "**Generated:** {$data['timestamp']}\n\n";
        $md .= "**Overall Status:** " . strtoupper($data['overall_status']) . "\n\n";
        
        $md .= "## Summary\n\n";
        $md .= "| Component | Status |\n";
        $md .= "|-----------|--------|\n";
        $md .= "| Analyzers | {$data['analyzers']['status']} |\n";
        $md .= "| Rules | {$data['rules']['status']} |\n";
        $md .= "| Orchestrator | {$data['orchestrator']['status']} |\n";
        $md .= "| Integration | {$data['integration']['status']} |\n";
        $md .= "| Dependencies | {$data['dependencies']['status']} |\n";
        $md .= "| Performance | {$data['performance']['status']} |\n\n";
        
        if (!empty($data['recommendations'])) {
            $md .= "## Recommendations\n\n";
            foreach ($data['recommendations'] as $rec) {
                $md .= "- **[{$rec['severity']}]** {$rec['message']}\n";
                $md .= "  - Action: {$rec['action']}\n\n";
            }
        }
        
        return $md;
    }
}
