<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Magento;

use ACQC\Analyzers\AbstractAnalyzer;
use ACQC\Analyzers\AnalyzerException;
use ACQC\Core\Rules\Violation;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

class PerformanceAnalyzer extends AbstractAnalyzer
{
    private Parser $parser;
    
    public function __construct(array $configuration = [], ?\Psr\Log\LoggerInterface $logger = null)
    {
        parent::__construct($configuration, $logger);
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    public function getName(): string
    {
        return 'magento-performance-analyzer';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function isAvailable(): bool
    {
        return class_exists('PhpParser\\ParserFactory');
    }

    protected function getDefaultConfiguration(): array
    {
        return [
            'check_n_plus_one_queries' => true,
            'check_inefficient_loops' => true,
            'check_memory_usage' => true,
            'check_cache_usage' => true,
            'check_session_usage' => true,
            'check_full_page_cache' => true,
            'check_database_operations' => true,
            'check_file_operations' => true,
            'check_external_calls' => true,
            'severity_critical_performance' => Violation::SEVERITY_CRITICAL,
            'severity_performance_warning' => Violation::SEVERITY_WARNING,
            'severity_performance_info' => Violation::SEVERITY_INFO,
            'max_loop_iterations' => 1000,
            'max_nested_loops' => 3,
            'max_database_calls_per_method' => 5,
            'expensive_operations' => [
                'file_get_contents',
                'curl_exec',
                'unserialize',
                'json_decode',
                'simplexml_load_string',
                'DOMDocument::load',
                'imageCreateFromJpeg',
                'imageCreateFromPng',
                'mail',
                'exec',
                'shell_exec',
                'system',
            ],
            'cache_patterns' => [
                'getCache',
                'cache->load',
                'cache->save',
                'cacheManager',
                'Cache\\',
                'isCacheEnabled',
            ],
            'session_patterns' => [
                'getSession',
                'session->',
                'sessionManager',
                'Session\\',
                'getCustomerSession',
                'getCheckoutSession',
            ],
            'database_patterns' => [
                '->save(',
                '->load(',
                '->delete(',
                '->getCollection(',
                'getConnection(',
                'query(',
                'rawQuery(',
                'fetchAll(',
                'fetchRow(',
                'fetchOne(',
                'fetchCol(',
            ],
            'performance_critical_paths' => [
                '/Controller/',
                '/Block/',
                '/Plugin/',
                '/Observer/',
                '/Model/',
            ],
            'exclude_paths' => [
                '*/Test/*',
                '*/tests/*',
                '*/dev/*',
                '*/Setup/*',
            ],
        ];
    }
    public function analyze(array $files, array $options = []): array
    {
        $filteredFiles = $this->filterSupportedFiles($files);
        
        if (empty($filteredFiles)) {
            return [];
        }
        
        $violations = $this->executeAnalysis($filteredFiles, $options);
        $this->lastAnalysisResults = $violations;
        
        return $violations;
    }


    protected function executeAnalysis(array $files, array $options = []): array
    {
        $this->logger->info('Starting Magento performance analysis');
        
        $violations = [];
        
        foreach ($files as $file) {
            if (str_ends_with($file, '.php') && $this->shouldAnalyzeFile($file)) {
                $violations = array_merge($violations, $this->analyzePhpFile($file));
            }
        }
        
        $this->logger->info('Found ' . count($violations) . ' performance violations');
        
        return $violations;
    }

    protected function parseOutput(string $output, array $files): array
    {
        return [];
    }

    private function analyzePhpFile(string $filePath): array
    {
        $violations = [];
        $content = file_get_contents($filePath);
        
        if (!$content) {
            return $violations;
        }
        
        try {
            $ast = $this->parser->parse($content);
            if (!$ast) {
                return $violations;
            }
            
            $visitor = new PerformanceAnalysisVisitor($filePath, $this->configuration);
            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);
            
            $violations = array_merge($violations, $visitor->getViolations());
            
        } catch (\Exception $e) {
            $this->logger->error("Failed to parse file: {$filePath}", ['error' => $e->getMessage()]);
        }
        
        return $violations;
    }

    private function shouldAnalyzeFile(string $filePath): bool
    {
        foreach ($this->configuration['exclude_paths'] as $excludePath) {
            if (fnmatch($excludePath, $filePath)) {
                return false;
            }
        }
        
        return true;
    }

    private function isPerformanceCriticalPath(string $filePath): bool
    {
        foreach ($this->configuration['performance_critical_paths'] as $criticalPath) {
            if (str_contains($filePath, $criticalPath)) {
                return true;
            }
        }
        return false;
    }
}

class PerformanceAnalysisVisitor extends NodeVisitorAbstract
{
    private string $filePath;
    private array $configuration;
    private array $violations = [];
    private array $currentLoopDepth = [];
    private array $databaseCallsInMethod = [];
    private string $currentMethod = '';
    private int $nestedLoopDepth = 0;

    public function __construct(string $filePath, array $configuration)
    {
        $this->filePath = $filePath;
        $this->configuration = $configuration;
    }

    public function enterNode(Node $node): void
    {
        if ($node instanceof Node\Stmt\ClassMethod) {
            $this->currentMethod = $node->name->name;
            $this->databaseCallsInMethod[$this->currentMethod] = 0;
        }
        
        if ($node instanceof Node\Stmt\For_ || 
            $node instanceof Node\Stmt\Foreach_ || 
            $node instanceof Node\Stmt\While_ ||
            $node instanceof Node\Stmt\Do_) {
            $this->enterLoop($node);
        }
        
        if ($node instanceof Node\Expr\MethodCall) {
            $this->analyzeMethodCall($node);
        }
        
        if ($node instanceof Node\Expr\FuncCall) {
            $this->analyzeFunctionCall($node);
        }
        
        if ($node instanceof Node\Expr\StaticCall) {
            $this->analyzeStaticCall($node);
        }
        
        if ($node instanceof Node\Expr\New_) {
            $this->analyzeNewInstance($node);
        }
    }

    public function leaveNode(Node $node): void
    {
        if ($node instanceof Node\Stmt\For_ || 
            $node instanceof Node\Stmt\Foreach_ || 
            $node instanceof Node\Stmt\While_ ||
            $node instanceof Node\Stmt\Do_) {
            $this->leaveLoop($node);
        }
        
        if ($node instanceof Node\Stmt\ClassMethod) {
            $this->analyzeMethodPerformance();
            $this->currentMethod = '';
        }
    }

    public function getViolations(): array
    {
        return $this->violations;
    }

    private function enterLoop(Node $node): void
    {
        $this->nestedLoopDepth++;
        
        if ($this->nestedLoopDepth > $this->configuration['max_nested_loops']) {
            $this->violations[] = new Violation(
                'magento-performance-nested-loops',
                "Nested loops depth ({$this->nestedLoopDepth}) exceeds recommended maximum of {$this->configuration['max_nested_loops']}",
                $this->filePath,
                $node->getStartLine(),
                0,
                $this->configuration['severity_performance_warning'],
                Violation::TYPE_PERFORMANCE
            );
        }
        
        // Check for potential infinite loops
        if ($node instanceof Node\Stmt\While_ || $node instanceof Node\Stmt\Do_) {
            if (!$this->hasLoopBreakCondition($node)) {
                $this->violations[] = new Violation(
                    'magento-performance-potential-infinite-loop',
                    'While/Do-While loop may not have proper break condition',
                    $this->filePath,
                    $node->getStartLine(),
                    0,
                    $this->configuration['severity_performance_warning'],
                    Violation::TYPE_LOGIC
                );
            }
        }
        
        // Check foreach with potential large datasets
        if ($node instanceof Node\Stmt\Foreach_) {
            if ($this->isPotentiallyLargeDataset($node->expr)) {
                $this->violations[] = new Violation(
                    'magento-performance-large-dataset-loop',
                    'Foreach loop over potentially large dataset. Consider pagination or chunking.',
                    $this->filePath,
                    $node->getStartLine(),
                    0,
                    $this->configuration['severity_performance_warning'],
                    Violation::TYPE_PERFORMANCE
                );
            }
        }
    }

    private function leaveLoop(Node $node): void
    {
        $this->nestedLoopDepth--;
    }

    private function analyzeMethodCall(Node\Expr\MethodCall $node): void
    {
        if ($node->name instanceof Node\Identifier) {
            $methodName = $node->name->name;
            
            // Check for database operations
            if ($this->isDatabaseOperation($methodName)) {
                if ($this->currentMethod === '') {
                    return;
                }
                $this->databaseCallsInMethod[$this->currentMethod]++;
                
                // Check for database operations in loops
                if ($this->nestedLoopDepth > 0) {
                    $this->violations[] = new Violation(
                        'magento-performance-db-in-loop',
                        "Database operation '{$methodName}' inside loop can cause N+1 query problem",
                        $this->filePath,
                        $node->getStartLine(),
                        0,
                        $this->configuration['severity_critical_performance'],
                        Violation::TYPE_PERFORMANCE
                    );
                }
            }
            
            // Check for collection loading patterns
            if ($methodName === 'load' && $this->isCollectionLoad($node)) {
                if ($this->nestedLoopDepth > 0) {
                    $this->violations[] = new Violation(
                        'magento-performance-collection-load-in-loop',
                        'Loading collection in loop causes N+1 queries. Use joinField or batch loading.',
                        $this->filePath,
                        $node->getStartLine(),
                        0,
                        $this->configuration['severity_critical_performance'],
                        Violation::TYPE_PERFORMANCE
                    );
                }
            }
            
            // Check for inefficient collection methods
            if (in_array($methodName, ['getFirstItem', 'getLastItem']) && $this->isLargeCollection($node)) {
                $this->violations[] = new Violation(
                    'magento-performance-inefficient-collection-method',
                    "Method '{$methodName}' on large collection. Consider using proper filtering or limiting.",
                    $this->filePath,
                    $node->getStartLine(),
                    0,
                    $this->configuration['severity_performance_warning'],
                    Violation::TYPE_PERFORMANCE
                );
            }
            
            // Check for session usage patterns
            if ($this->isSessionOperation($methodName)) {
                if ($this->nestedLoopDepth > 0) {
                    $this->violations[] = new Violation(
                        'magento-performance-session-in-loop',
                        'Session operations in loops can cause performance issues',
                        $this->filePath,
                        $node->getStartLine(),
                        0,
                        $this->configuration['severity_performance_warning'],
                        Violation::TYPE_PERFORMANCE
                    );
                }
            }
            
            // Check for cache misuse
            if ($methodName === 'load' && $this->isCacheOperation($node)) {
                if (!$this->hasCacheCheck($node)) {
                    $this->violations[] = new Violation(
                        'magento-performance-cache-without-check',
                        'Cache load without proper existence check',
                        $this->filePath,
                        $node->getStartLine(),
                        0,
                        $this->configuration['severity_performance_info'],
                        Violation::TYPE_PERFORMANCE
                    );
                }
            }
            
            // Check for expensive operations
            if (in_array($methodName, $this->configuration['expensive_operations'])) {
                if ($this->nestedLoopDepth > 0) {
                    $this->violations[] = new Violation(
                        'magento-performance-expensive-operation-in-loop',
                        "Expensive operation '{$methodName}' in loop",
                        $this->filePath,
                        $node->getStartLine(),
                        0,
                        $this->configuration['severity_critical_performance'],
                        Violation::TYPE_PERFORMANCE
                    );
                }
            }
        }
    }

    private function analyzeFunctionCall(Node\Expr\FuncCall $node): void
    {
        if ($node->name instanceof Node\Name) {
            $functionName = $node->name->toString();
            
            // Check for expensive functions
            if (in_array($functionName, $this->configuration['expensive_operations'])) {
                if ($this->nestedLoopDepth > 0) {
                    $this->violations[] = new Violation(
                        'magento-performance-expensive-function-in-loop',
                        "Expensive function '{$functionName}' called in loop",
                        $this->filePath,
                        $node->getStartLine(),
                        0,
                        $this->configuration['severity_critical_performance'],
                        Violation::TYPE_PERFORMANCE
                    );
                }
            }
            
            // Check for file operations
            if (in_array($functionName, ['file_get_contents', 'file_put_contents', 'fopen', 'fread', 'fwrite'])) {
                if ($this->nestedLoopDepth > 0) {
                    $this->violations[] = new Violation(
                        'magento-performance-file-operation-in-loop',
                        "File operation '{$functionName}' in loop",
                        $this->filePath,
                        $node->getStartLine(),
                        0,
                        $this->configuration['severity_performance_warning'],
                        Violation::TYPE_PERFORMANCE
                    );
                }
            }
            
            // Check for serialization operations
            if (in_array($functionName, ['serialize', 'unserialize', 'json_encode', 'json_decode'])) {
                if ($this->nestedLoopDepth > 0) {
                    $this->violations[] = new Violation(
                        'magento-performance-serialization-in-loop',
                        "Serialization operation '{$functionName}' in loop",
                        $this->filePath,
                        $node->getStartLine(),
                        0,
                        $this->configuration['severity_performance_warning'],
                        Violation::TYPE_PERFORMANCE
                    );
                }
            }
            
            // Check for regular expression operations
            if (in_array($functionName, ['preg_match', 'preg_replace', 'preg_match_all'])) {
                if ($this->nestedLoopDepth > 0) {
                    $this->violations[] = new Violation(
                        'magento-performance-regex-in-loop',
                        "Regular expression '{$functionName}' in loop",
                        $this->filePath,
                        $node->getStartLine(),
                        0,
                        $this->configuration['severity_performance_info'],
                        Violation::TYPE_PERFORMANCE
                    );
                }
            }
        }
    }

    private function analyzeStaticCall(Node\Expr\StaticCall $node): void
    {
        if ($node->class instanceof Node\Name && $node->name instanceof Node\Identifier) {
            $className = $node->class->toString();
            $methodName = $node->name->name;
            
            // Check for ObjectManager usage
            if (str_contains($className, 'ObjectManager') && $methodName === 'getInstance') {
                $this->violations[] = new Violation(
                    'magento-performance-object-manager-usage',
                    'ObjectManager usage can impact performance. Use dependency injection.',
                    $this->filePath,
                    $node->getStartLine(),
                    0,
                    $this->configuration['severity_performance_warning'],
                    Violation::TYPE_PERFORMANCE
                );
            }
            
            // Check for static factory calls in loops
            if (str_ends_with($className, 'Factory') && $this->nestedLoopDepth > 0) {
                $this->violations[] = new Violation(
                    'magento-performance-factory-in-loop',
                    "Factory call '{$className}::{$methodName}' in loop",
                    $this->filePath,
                    $node->getStartLine(),
                    0,
                    $this->configuration['severity_performance_warning'],
                    Violation::TYPE_PERFORMANCE
                );
            }
        }
    }

    private function analyzeNewInstance(Node\Expr\New_ $node): void
    {
        if ($node->class instanceof Node\Name) {
            $className = $node->class->toString();
            
            // Check for object instantiation in loops
            if ($this->nestedLoopDepth > 0) {
                $this->violations[] = new Violation(
                    'magento-performance-object-creation-in-loop',
                    "Object instantiation '{$className}' in loop. Consider object pooling or factory pattern.",
                    $this->filePath,
                    $node->getStartLine(),
                    0,
                    $this->configuration['severity_performance_info'],
                    Violation::TYPE_PERFORMANCE
                );
            }
            
            // Check for expensive object creation
            $expensiveClasses = [
                'DOMDocument',
                'SimpleXMLElement',
                'XMLReader',
                'XMLWriter',
                'ZipArchive',
                'Imagick',
            ];
            
            foreach ($expensiveClasses as $expensiveClass) {
                if (str_contains($className, $expensiveClass)) {
                    if ($this->nestedLoopDepth > 0) {
                        $this->violations[] = new Violation(
                            'magento-performance-expensive-object-in-loop',
                            "Expensive object '{$className}' created in loop",
                            $this->filePath,
                            $node->getStartLine(),
                            0,
                            $this->configuration['severity_performance_warning'],
                            Violation::TYPE_PERFORMANCE
                        );
                    }
                }
            }
        }
    }

    private function analyzeMethodPerformance(): void
    {
        if (!empty($this->currentMethod)) {
            $dbCalls = $this->databaseCallsInMethod[$this->currentMethod] ?? 0;
            
            if ($dbCalls > $this->configuration['max_database_calls_per_method']) {
                $this->violations[] = new Violation(
                    'magento-performance-too-many-db-calls',
                    "Method '{$this->currentMethod}' has {$dbCalls} database calls, exceeds maximum of {$this->configuration['max_database_calls_per_method']}",
                    $this->filePath,
                    0,
                    0,
                    $this->configuration['severity_performance_warning'],
                    Violation::TYPE_PERFORMANCE
                );
            }
        }
    }

    private function isDatabaseOperation(string $methodName): bool
    {
        foreach ($this->configuration['database_patterns'] as $pattern) {
            if (str_contains($pattern, $methodName)) {
                return true;
            }
        }
        return false;
    }

    private function isCollectionLoad(Node\Expr\MethodCall $node): bool
    {
        // Check if this is a collection load by examining the variable/object
        if ($node->var instanceof Node\Expr\Variable) {
            // This is a simplified check - in practice you'd need more sophisticated analysis
            return true;
        }
        return false;
    }

    private function isLargeCollection(Node\Expr\MethodCall $node): bool
    {
        // This would require more sophisticated analysis to determine collection size
        return false; // Placeholder
    }

    private function isSessionOperation(string $methodName): bool
    {
        foreach ($this->configuration['session_patterns'] as $pattern) {
            if (str_contains($pattern, $methodName)) {
                return true;
            }
        }
        return false;
    }

    private function isCacheOperation(Node\Expr\MethodCall $node): bool
    {
        foreach ($this->configuration['cache_patterns'] as $pattern) {
            if (str_contains($this->nodeToString($node), $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function hasCacheCheck(Node\Expr\MethodCall $node): bool
    {
        // This would require more sophisticated analysis of surrounding code
        return false; // Placeholder
    }

    private function hasLoopBreakCondition(Node $node): bool
    {
        // This would require analyzing the loop body for break/return statements
        return true; // Placeholder - assume most loops are safe
    }

    private function isPotentiallyLargeDataset(Node $expr): bool
    {
        // Check if the expression represents a potentially large dataset
        if ($expr instanceof Node\Expr\MethodCall) {
            if ($expr->name instanceof Node\Identifier) {
                $methodName = $expr->name->name;
                return in_array($methodName, ['getCollection', 'getAllItems', 'getItems']);
            }
        }
        return false;
    }

    private function nodeToString(Node $node): string
    {
        // Simplified node to string conversion
        return serialize($node);
    }
} 