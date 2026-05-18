<?php

declare(strict_types=1);

namespace ACQC\Analyzers;

use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;

class MagentoSpecificAnalyzer implements AnalyzerInterface
{
    private Parser $parser;
    private NodeFinder $nodeFinder;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->nodeFinder = new NodeFinder();
    }

    public function analyze(array $files, array $config = []): array
    {
        $issues = [];
        $metrics = [];

        foreach ($files as $file) {
            if (!$this->isMagentoRelevant($file)) {
                continue;
            }

            $content = file_get_contents($file);
            
            try {
                $ast = $this->parser->parse($content);
                if ($ast) {
                    $fileIssues = $this->analyzeFile($file, $ast, $content);
                    $issues = array_merge($issues, $fileIssues);
                }
            } catch (\Exception $e) {
                $issues[] = [
                    'file' => $file,
                    'line' => 1,
                    'severity' => 'error',
                    'type' => 'parse_error',
                    'message' => 'Failed to parse file: ' . $e->getMessage(),
                ];
            }
        }

        // Calculate Magento-specific metrics
        $metrics['magento_compliance_score'] = $this->calculateComplianceScore($issues);
        $metrics['dependency_injection_usage'] = $this->calculateDIUsage($files);

        return [
            'issues' => $issues,
            'metrics' => $metrics,
            'recommendations' => $this->generateRecommendations($issues),
        ];
    }

    public function getName(): string
    {
        return 'Magento Specific Analyzer';
    }

    public function getDescription(): string
    {
        return 'Analyzes code for Magento/Adobe Commerce specific best practices and conventions';
    }

    public function getSupportedSeverities(): array
    {
        return ['error', 'warning', 'info'];
    }

    private function isMagentoRelevant(string $file): bool
    {
        // Check if file is in typical Magento directories
        $magentoPatterns = [
            '/app/code/',
            '/Model/',
            '/Block/',
            '/Controller/',
            '/Helper/',
            '/Observer/',
            '/Plugin/',
        ];

        foreach ($magentoPatterns as $pattern) {
            if (strpos($file, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    private function analyzeFile(string $file, array $ast, string $content): array
    {
        $issues = [];

        // Check for deprecated Magento patterns
        $issues = array_merge($issues, $this->checkDeprecatedPatterns($file, $content));

        // Check for proper dependency injection
        $issues = array_merge($issues, $this->checkDependencyInjection($file, $ast));

        // Check for ObjectManager usage
        $issues = array_merge($issues, $this->checkObjectManagerUsage($file, $content));

        // Check for proper plugin implementation
        $issues = array_merge($issues, $this->checkPluginImplementation($file, $ast));

        return $issues;
    }

    private function checkDeprecatedPatterns(string $file, string $content): array
    {
        $issues = [];
        $deprecatedPatterns = [
            'Mage::' => 'Direct Mage class usage is deprecated in Magento 2',
            '->getModel(' => 'Use dependency injection instead of getModel()',
            '->getSingleton(' => 'Use dependency injection instead of getSingleton()',
        ];

        foreach ($deprecatedPatterns as $pattern => $message) {
            $lines = explode("\n", $content);
            foreach ($lines as $lineNum => $line) {
                if (strpos($line, $pattern) !== false) {
                    $issues[] = [
                        'file' => $file,
                        'line' => $lineNum + 1,
                        'severity' => 'error',
                        'type' => 'deprecated_pattern',
                        'message' => $message,
                    ];
                }
            }
        }

        return $issues;
    }

    private function checkDependencyInjection(string $file, array $ast): array
    {
        $issues = [];
        
        // Check if class has proper constructor injection
        $classes = $this->nodeFinder->findInstanceOf($ast, \PhpParser\Node\Stmt\Class_::class);
        
        foreach ($classes as $class) {
            $constructor = $this->nodeFinder->findFirst($class->stmts, function($node) {
                return $node instanceof \PhpParser\Node\Stmt\ClassMethod && $node->name->toString() === '__construct';
            });

            if ($constructor && empty($constructor->params)) {
                $issues[] = [
                    'file' => $file,
                    'line' => $class->getLine(),
                    'severity' => 'warning',
                    'type' => 'dependency_injection',
                    'message' => 'Consider using dependency injection in constructor',
                ];
            }
        }

        return $issues;
    }

    private function checkObjectManagerUsage(string $file, string $content): array
    {
        $issues = [];
        
        if (strpos($content, 'ObjectManager::getInstance()') !== false) {
            $lines = explode("\n", $content);
            foreach ($lines as $lineNum => $line) {
                if (strpos($line, 'ObjectManager::getInstance()') !== false) {
                    $issues[] = [
                        'file' => $file,
                        'line' => $lineNum + 1,
                        'severity' => 'error',
                        'type' => 'object_manager_usage',
                        'message' => 'Direct ObjectManager usage is discouraged. Use dependency injection instead.',
                    ];
                }
            }
        }

        return $issues;
    }

    private function checkPluginImplementation(string $file, array $ast): array
    {
        $issues = [];
        
        // Check if plugin methods follow proper naming conventions
        $methods = $this->nodeFinder->findInstanceOf($ast, \PhpParser\Node\Stmt\ClassMethod::class);
        
        foreach ($methods as $method) {
            $methodName = $method->name->toString();
            if (preg_match('/^(before|after|around)([A-Z])/', $methodName)) {
                // Check if method has proper parameters for plugin type
                $paramCount = count($method->params);
                
                if (strpos($methodName, 'before') === 0 && $paramCount < 1) {
                    $issues[] = [
                        'file' => $file,
                        'line' => $method->getLine(),
                        'severity' => 'error',
                        'type' => 'plugin_implementation',
                        'message' => 'Before plugin method must have at least one parameter (subject)',
                    ];
                }
            }
        }

        return $issues;
    }

    private function calculateComplianceScore(array $issues): int
    {
        $errorCount = count(array_filter($issues, fn($issue) => $issue['severity'] === 'error'));
        $warningCount = count(array_filter($issues, fn($issue) => $issue['severity'] === 'warning'));
        
        $baseScore = 100;
        $penalty = ($errorCount * 10) + ($warningCount * 5);
        
        return max(0, $baseScore - $penalty);
    }

    private function calculateDIUsage(array $files): float
    {
        // Simplified calculation of dependency injection usage
        $totalClasses = 0;
        $classesWithDI = 0;
        
        foreach ($files as $file) {
            if ($this->isMagentoRelevant($file)) {
                $content = file_get_contents($file);
                if (preg_match_all('/class\s+\w+/', $content, $matches)) {
                    $totalClasses += count($matches[0]);
                    if (strpos($content, '__construct') !== false) {
                        $classesWithDI++;
                    }
                }
            }
        }
        
        return $totalClasses > 0 ? ($classesWithDI / $totalClasses) * 100 : 0;
    }

    private function generateRecommendations(array $issues): array
    {
        $recommendations = [];
        
        $issueTypes = array_count_values(array_column($issues, 'type'));
        
        if (($issueTypes['deprecated_pattern'] ?? 0) > 0) {
            $recommendations[] = 'Migrate from deprecated Magento 1.x patterns to Magento 2 best practices';
        }
        
        if (($issueTypes['object_manager_usage'] ?? 0) > 0) {
            $recommendations[] = 'Replace ObjectManager usage with proper dependency injection';
        }
        
        if (($issueTypes['dependency_injection'] ?? 0) > 0) {
            $recommendations[] = 'Implement constructor-based dependency injection for better testability';
        }
        
        return $recommendations;
    }
} 