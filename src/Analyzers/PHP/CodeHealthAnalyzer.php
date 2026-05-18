<?php

declare(strict_types=1);

namespace ACQC\Analyzers\PHP;

use ACQC\Analyzers\AbstractAnalyzer;
use ACQC\Core\Rules\Violation;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/**
 * Comprehensive code health analyzer for deep-level audit
 */
class CodeHealthAnalyzer extends AbstractAnalyzer
{
    private array $metrics = [];

    public function getName(): string
    {
        return 'PHP Code Health Analyzer';
    }

    public function getDescription(): string
    {
        return 'Comprehensive code health analysis including complexity, maintainability, and quality metrics';
    }

    public function getVersion(): string
    {
        return '2.0.0';
    }

    public function isAvailable(): bool
    {
        return class_exists('PhpParser\\ParserFactory');
    }

    protected function getDefaultConfiguration(): array
    {
        return [
            'max_cyclomatic_complexity' => 10,
            'max_cognitive_complexity' => 15,
            'min_maintainability_index' => 50,
            'max_method_length' => 50,
            'max_class_length' => 500,
            'max_parameters' => 5,
        ];
    }

    protected function executeAnalysis(array $files, array $options = []): array
    {
        // Implementation not needed - using analyze() method instead
        return [];
    }

    protected function parseOutput(string $output, array $files): array
    {
        // Implementation not needed - direct violation generation
        return [];
    }

    public function analyzeFile(string $filePath, array $options = []): array
    {
        $violations = [];
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return $violations;
        }
        
        $content = file_get_contents($filePath);
        try {
            $ast = $parser->parse($content);
            if ($ast) {
                $violations = $this->analyzeFileInternal($filePath, $ast, $content);
            }
        } catch (\Exception $e) {
            $this->logger->warning("Failed to parse {$filePath}: " . $e->getMessage());
        }
        
        return $violations;
    }
    
    public function analyze(array $files, array $options = []): array
    {
        $this->logger->info('Starting code health analysis on ' . count($files) . ' files');
        
        $violations = [];
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        foreach ($files as $file) {
            if (!$this->isPhpFile($file)) {
                continue;
            }

            try {
                $content = file_get_contents($file);
                $ast = $parser->parse($content);

                if (!$ast) {
                    continue;
                }

                $fileViolations = $this->analyzeFile($file, $ast, $content);
                $violations = array_merge($violations, $fileViolations);

            } catch (\Exception $e) {
                $this->logger->warning("Failed to analyze {$file}: " . $e->getMessage());
            }
        }

        $this->logger->info('Code health analysis complete. Found ' . count($violations) . ' issues');
        
        return $violations;
    }

    /**
     * Analyze a single file (internal method)
     */
    private function analyzeFileInternal(string $file, array $ast, string $content): array
    {
        $violations = [];
        
        // Cyclomatic complexity analysis
        $violations = array_merge($violations, $this->analyzeCyclomaticComplexity($file, $ast));
        
        // Cognitive complexity analysis
        $violations = array_merge($violations, $this->analyzeCognitiveComplexity($file, $ast));
        
        // Maintainability index
        $violations = array_merge($violations, $this->analyzeMaintainabilityIndex($file, $ast, $content));
        
        // Code smells
        $violations = array_merge($violations, $this->detectCodeSmells($file, $ast));
        
        // Coupling and cohesion
        $violations = array_merge($violations, $this->analyzeCouplingAndCohesion($file, $ast));
        
        // Documentation quality
        $violations = array_merge($violations, $this->analyzeDocumentationQuality($file, $ast, $content));
        
        // Code duplication patterns
        $violations = array_merge($violations, $this->detectDuplicationPatterns($file, $ast));
        
        return $violations;
    }

    /**
     * Analyze cyclomatic complexity
     */
    private function analyzeCyclomaticComplexity(string $file, array $ast): array
    {
        $violations = [];
        $visitor = new class extends NodeVisitorAbstract {
            public array $methods = [];

            public function enterNode(Node $node)
            {
                if ($node instanceof Node\Stmt\ClassMethod) {
                    $complexity = $this->calculateComplexity($node);
                    $this->methods[] = [
                        'name' => $node->name->toString(),
                        'line' => $node->getStartLine(),
                        'complexity' => $complexity
                    ];
                }
            }

            private function calculateComplexity(Node $node): int
            {
                $complexity = 1; // Base complexity
                
                $traverser = new NodeTraverser();
                $traverser->addVisitor(new class($complexity) extends NodeVisitorAbstract {
                    private int $complexity;
                    
                    public function __construct(int &$complexity)
                    {
                        $this->complexity = &$complexity;
                    }
                    
                    public function enterNode(Node $node)
                    {
                        if ($node instanceof Node\Stmt\If_ ||
                            $node instanceof Node\Stmt\ElseIf_ ||
                            $node instanceof Node\Stmt\For_ ||
                            $node instanceof Node\Stmt\Foreach_ ||
                            $node instanceof Node\Stmt\While_ ||
                            $node instanceof Node\Stmt\Do_ ||
                            $node instanceof Node\Stmt\Case_ ||
                            $node instanceof Node\Stmt\Catch_ ||
                            $node instanceof Node\Expr\Ternary ||
                            ($node instanceof Node\Expr\BinaryOp && 
                             ($node instanceof Node\Expr\BinaryOp\BooleanAnd || 
                              $node instanceof Node\Expr\BinaryOp\BooleanOr ||
                              $node instanceof Node\Expr\BinaryOp\LogicalAnd ||
                              $node instanceof Node\Expr\BinaryOp\LogicalOr))) {
                            $this->complexity++;
                        }
                    }
                });
                
                $traverser->traverse([$node]);
                return $complexity;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        foreach ($visitor->methods as $method) {
            if ($method['complexity'] > 10) {
                $severity = $method['complexity'] > 20 ? Violation::SEVERITY_CRITICAL : 
                           ($method['complexity'] > 15 ? Violation::SEVERITY_ERROR : Violation::SEVERITY_WARNING);
                
                $violations[] = new Violation(
                    'HighCyclomaticComplexity',
                    "Method '{$method['name']}' has cyclomatic complexity of {$method['complexity']} (threshold: 10)",
                    $file,
                    $method['line'],
                    0,
                    $severity,
                    Violation::TYPE_MAINTAINABILITY
                );
            }
        }

        return $violations;
    }

    /**
     * Analyze cognitive complexity
     */
    private function analyzeCognitiveComplexity(string $file, array $ast): array
    {
        $violations = [];
        $visitor = new class extends NodeVisitorAbstract {
            public array $methods = [];
            private int $nestingLevel = 0;

            public function enterNode(Node $node)
            {
                if ($node instanceof Node\Stmt\ClassMethod) {
                    $this->nestingLevel = 0;
                    $complexity = $this->calculateCognitiveComplexity($node);
                    $this->methods[] = [
                        'name' => $node->name->toString(),
                        'line' => $node->getStartLine(),
                        'complexity' => $complexity
                    ];
                }
            }

            private function calculateCognitiveComplexity(Node $node): int
            {
                $complexity = 0;
                $nestingLevel = 0;
                
                $traverser = new NodeTraverser();
                $traverser->addVisitor(new class($complexity, $nestingLevel) extends NodeVisitorAbstract {
                    private int $complexity;
                    private int $nestingLevel;
                    
                    public function __construct(int &$complexity, int &$nestingLevel)
                    {
                        $this->complexity = &$complexity;
                        $this->nestingLevel = &$nestingLevel;
                    }
                    
                    public function enterNode(Node $node)
                    {
                        // Increment for control structures
                        if ($node instanceof Node\Stmt\If_ ||
                            $node instanceof Node\Stmt\For_ ||
                            $node instanceof Node\Stmt\Foreach_ ||
                            $node instanceof Node\Stmt\While_ ||
                            $node instanceof Node\Stmt\Do_ ||
                            $node instanceof Node\Stmt\Switch_ ||
                            $node instanceof Node\Stmt\Catch_) {
                            
                            $this->complexity += 1 + $this->nestingLevel;
                            $this->nestingLevel++;
                        }
                        
                        // Increment for binary operations
                        if ($node instanceof Node\Expr\BinaryOp\BooleanAnd ||
                            $node instanceof Node\Expr\BinaryOp\BooleanOr ||
                            $node instanceof Node\Expr\BinaryOp\LogicalAnd ||
                            $node instanceof Node\Expr\BinaryOp\LogicalOr) {
                            $this->complexity++;
                        }
                        
                        // Increment for recursion
                        if ($node instanceof Node\Expr\FuncCall) {
                            // Check if it's a recursive call
                            $this->complexity += 0; // TODO: implement recursion detection
                        }
                    }
                    
                    public function leaveNode(Node $node)
                    {
                        if ($node instanceof Node\Stmt\If_ ||
                            $node instanceof Node\Stmt\For_ ||
                            $node instanceof Node\Stmt\Foreach_ ||
                            $node instanceof Node\Stmt\While_ ||
                            $node instanceof Node\Stmt\Do_ ||
                            $node instanceof Node\Stmt\Switch_ ||
                            $node instanceof Node\Stmt\Catch_) {
                            $this->nestingLevel--;
                        }
                    }
                });
                
                $traverser->traverse([$node]);
                return $complexity;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        foreach ($visitor->methods as $method) {
            if ($method['complexity'] > 15) {
                $severity = $method['complexity'] > 30 ? Violation::SEVERITY_CRITICAL : 
                           ($method['complexity'] > 20 ? Violation::SEVERITY_ERROR : Violation::SEVERITY_WARNING);
                
                $violations[] = new Violation(
                    'HighCognitiveComplexity',
                    "Method '{$method['name']}' has cognitive complexity of {$method['complexity']} (threshold: 15)",
                    $file,
                    $method['line'],
                    0,
                    $severity,
                    Violation::TYPE_MAINTAINABILITY
                );
            }
        }

        return $violations;
    }

    /**
     * Calculate maintainability index
     */
    private function analyzeMaintainabilityIndex(string $file, array $ast, string $content): array
    {
        $violations = [];
        
        // Calculate metrics
        $loc = substr_count($content, "\n") + 1;
        $cyclomaticComplexity = $this->getAverageCyclomaticComplexity($ast);
        $halsteadVolume = $this->calculateHalsteadVolume($ast);
        $commentRatio = $this->calculateCommentRatio($content);
        
        // Maintainability Index = MAX(0,(171 - 5.2 * ln(Halstead Volume) - 0.23 * (Cyclomatic Complexity) - 16.2 * ln(LOC)) * 100 / 171)
        $mi = max(0, (171 - 5.2 * log($halsteadVolume + 1) - 0.23 * $cyclomaticComplexity - 16.2 * log($loc)) * 100 / 171);
        
        if ($mi < 20) {
            $violations[] = new Violation(
                'LowMaintainabilityIndex',
                "File has maintainability index of " . round($mi, 2) . " (threshold: 20). Consider refactoring.",
                $file,
                1,
                0,
                $mi < 10 ? Violation::SEVERITY_CRITICAL : Violation::SEVERITY_ERROR,
                Violation::TYPE_MAINTAINABILITY
            );
        }

        return $violations;
    }

    /**
     * Detect code smells
     */
    private function detectCodeSmells(string $file, array $ast): array
    {
        $violations = [];
        
        $visitor = new class extends NodeVisitorAbstract {
            public array $issues = [];

            public function enterNode(Node $node)
            {
                // Long parameter list
                if ($node instanceof Node\Stmt\ClassMethod) {
                    $paramCount = count($node->params);
                    if ($paramCount > 5) {
                        $this->issues[] = [
                            'type' => 'LongParameterList',
                            'message' => "Method '{$node->name}' has {$paramCount} parameters (threshold: 5)",
                            'line' => $node->getStartLine(),
                            'severity' => $paramCount > 8 ? Violation::SEVERITY_ERROR : Violation::SEVERITY_WARNING
                        ];
                    }
                }
                
                // Large class
                if ($node instanceof Node\Stmt\Class_) {
                    $methodCount = count($node->getMethods());
                    if ($methodCount > 20) {
                        $this->issues[] = [
                            'type' => 'LargeClass',
                            'message' => "Class has {$methodCount} methods (threshold: 20)",
                            'line' => $node->getStartLine(),
                            'severity' => $methodCount > 30 ? Violation::SEVERITY_ERROR : Violation::SEVERITY_WARNING
                        ];
                    }
                }
                
                // Long method (by lines)
                if ($node instanceof Node\Stmt\ClassMethod) {
                    $lines = $node->getEndLine() - $node->getStartLine();
                    if ($lines > 50) {
                        $this->issues[] = [
                            'type' => 'LongMethod',
                            'message' => "Method '{$node->name}' has {$lines} lines (threshold: 50)",
                            'line' => $node->getStartLine(),
                            'severity' => $lines > 100 ? Violation::SEVERITY_ERROR : Violation::SEVERITY_WARNING
                        ];
                    }
                }
                
                // God object (too many dependencies)
                if ($node instanceof Node\Stmt\Class_) {
                    $dependencies = $this->countDependencies($node);
                    if ($dependencies > 10) {
                        $this->issues[] = [
                            'type' => 'GodObject',
                            'message' => "Class has {$dependencies} dependencies (threshold: 10)",
                            'line' => $node->getStartLine(),
                            'severity' => $dependencies > 15 ? Violation::SEVERITY_CRITICAL : Violation::SEVERITY_ERROR
                        ];
                    }
                }
                
                // Deeply nested code
                if ($node instanceof Node\Stmt\If_) {
                    $depth = $this->getNestingDepth($node);
                    if ($depth > 3) {
                        $this->issues[] = [
                            'type' => 'DeeplyNestedCode',
                            'message' => "Code has nesting depth of {$depth} (threshold: 3)",
                            'line' => $node->getStartLine(),
                            'severity' => $depth > 5 ? Violation::SEVERITY_ERROR : Violation::SEVERITY_WARNING
                        ];
                    }
                }
            }

            private function countDependencies(Node\Stmt\Class_ $node): int
            {
                $dependencies = 0;
                
                // Count constructor parameters (dependency injection)
                $constructor = $node->getMethod('__construct');
                if ($constructor) {
                    $dependencies = count($constructor->params);
                }
                
                return $dependencies;
            }

            private function getNestingDepth(Node $node, int $currentDepth = 0): int
            {
                $maxDepth = $currentDepth;
                
                foreach ($node->getSubNodeNames() as $subNodeName) {
                    $subNode = $node->$subNodeName;
                    
                    if ($subNode instanceof Node\Stmt\If_ ||
                        $subNode instanceof Node\Stmt\For_ ||
                        $subNode instanceof Node\Stmt\Foreach_ ||
                        $subNode instanceof Node\Stmt\While_) {
                        $depth = $this->getNestingDepth($subNode, $currentDepth + 1);
                        $maxDepth = max($maxDepth, $depth);
                    }
                    
                    if (is_array($subNode)) {
                        foreach ($subNode as $item) {
                            if ($item instanceof Node) {
                                $depth = $this->getNestingDepth($item, $currentDepth + 1);
                                $maxDepth = max($maxDepth, $depth);
                            }
                        }
                    }
                }
                
                return $maxDepth;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        foreach ($visitor->issues as $issue) {
            $violations[] = new Violation(
                $issue['type'],
                $issue['message'],
                $file,
                $issue['line'],
                0,
                $issue['severity'],
                Violation::TYPE_MAINTAINABILITY
            );
        }

        return $violations;
    }

    /**
     * Analyze coupling and cohesion
     */
    private function analyzeCouplingAndCohesion(string $file, array $ast): array
    {
        $violations = [];
        
        // TODO: Implement coupling and cohesion analysis
        // - Efferent coupling (Ce): number of classes this class depends on
        // - Afferent coupling (Ca): number of classes that depend on this class
        // - Instability: Ce / (Ce + Ca)
        // - Lack of Cohesion (LCOM): measure of how well methods are related
        
        return $violations;
    }

    /**
     * Analyze documentation quality
     */
    private function analyzeDocumentationQuality(string $file, array $ast, string $content): array
    {
        $violations = [];
        
        $visitor = new class extends NodeVisitorAbstract {
            public array $issues = [];

            public function enterNode(Node $node)
            {
                if ($node instanceof Node\Stmt\ClassMethod) {
                    if ($node->isPublic() && !$node->getDocComment()) {
                        $this->issues[] = [
                            'type' => 'MissingDocumentation',
                            'message' => "Public method '{$node->name}' is missing documentation",
                            'line' => $node->getStartLine(),
                            'severity' => Violation::SEVERITY_INFO
                        ];
                    }
                }
                
                if ($node instanceof Node\Stmt\Class_) {
                    if (!$node->getDocComment()) {
                        $this->issues[] = [
                            'type' => 'MissingDocumentation',
                            'message' => "Class is missing documentation",
                            'line' => $node->getStartLine(),
                            'severity' => Violation::SEVERITY_INFO
                        ];
                    }
                }
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        foreach ($visitor->issues as $issue) {
            $violations[] = new Violation(
                $issue['type'],
                $issue['message'],
                $file,
                $issue['line'],
                0,
                $issue['severity'],
                Violation::TYPE_DOCUMENTATION
            );
        }

        return $violations;
    }

    /**
     * Detect duplication patterns
     */
    private function detectDuplicationPatterns(string $file, array $ast): array
    {
        $violations = [];
        
        // TODO: Implement duplication detection
        // - Token-based duplication
        // - AST-based duplication
        // - Semantic duplication
        
        return $violations;
    }

    /**
     * Get average cyclomatic complexity
     */
    private function getAverageCyclomaticComplexity(array $ast): float
    {
        // Simplified calculation
        return 5.0; // TODO: Implement actual calculation
    }

    /**
     * Calculate Halstead volume
     */
    private function calculateHalsteadVolume(array $ast): float
    {
        // Simplified calculation
        return 100.0; // TODO: Implement actual Halstead metrics
    }

    /**
     * Calculate comment ratio
     */
    private function calculateCommentRatio(string $content): float
    {
        $totalLines = substr_count($content, "\n") + 1;
        $commentLines = preg_match_all('/^\\s*(\\/\\/|\\*|\\#)/', $content);
        
        return $totalLines > 0 ? $commentLines / $totalLines : 0.0;
    }

    /**
     * Check if file is PHP
     */
    private function isPhpFile(string $file): bool
    {
        return pathinfo($file, PATHINFO_EXTENSION) === 'php';
    }
}
