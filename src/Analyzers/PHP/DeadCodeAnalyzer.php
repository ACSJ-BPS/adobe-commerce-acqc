<?php

declare(strict_types=1);

namespace ACQC\Analyzers\PHP;

use ACQC\Analyzers\AbstractAnalyzer;
use ACQC\Core\Rules\Violation;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Proper dead code detection using AST analysis
 * 
 * Detects:
 * - Unreachable code after return/throw/break/continue
 * - Unused private methods
 * - Always-false conditions
 * - Code in unreachable branches
 */
class DeadCodeAnalyzer extends AbstractAnalyzer
{
    private Parser $parser;
    
    public function __construct(array $configuration = [], ?\Psr\Log\LoggerInterface $logger = null)
    {
        parent::__construct($configuration, $logger);
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    public function getName(): string
    {
        return 'php-dead-code-analyzer';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function isAvailable(): bool
    {
        return class_exists('PhpParser\Parser');
    }

    protected function getDefaultConfiguration(): array
    {
        return [
            'check_unreachable_code' => true,
            'check_unused_private_methods' => true,
            'check_always_false_conditions' => true,
            'check_code_after_return' => true,
            'check_code_after_throw' => true,
            'severity_unreachable' => Violation::SEVERITY_WARNING,
            'severity_unused_method' => Violation::SEVERITY_INFO,
            'severity_always_false' => Violation::SEVERITY_WARNING,
        ];
    }

    public function analyze(array $files, array $options = []): array
    {
        $violations = [];
        
        foreach ($files as $file) {
            if (!str_ends_with($file, '.php')) {
                continue;
            }
            
            $violations = array_merge($violations, $this->analyzeFile($file));
        }
        
        $this->logger->info('Dead code analysis found ' . count($violations) . ' issues');
        
        return $violations;
    }

    public function analyzeFile(string $filePath, array $options = []): array
    {
        $violations = [];
        $content = file_get_contents($filePath);
        
        if (!$content) {
            return $violations;
        }
        
        try {
            $ast = $this->parser->parse($content);
            
            if ($ast === null) {
                return $violations;
            }
            
            // Create visitor to detect dead code
            $visitor = new DeadCodeVisitor($filePath, $this->configuration);
            
            // Traverse AST
            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);
            
            $violations = $visitor->getViolations();
            
        } catch (\Exception $e) {
            $this->logger->error("Failed to analyze {$filePath}: " . $e->getMessage());
        }
        
        return $violations;
    }

    protected function executeAnalysis(array $files, array $options = []): array
    {
        return $this->analyze($files, $options);
    }

    protected function parseOutput(string $output, array $files): array
    {
        return [];
    }
}

/**
 * Visitor to detect dead code patterns using control flow analysis
 */
class DeadCodeVisitor extends NodeVisitorAbstract
{
    private string $filePath;
    private array $configuration;
    private array $violations = [];
    private array $declaredPrivateMethods = [];
    private array $calledMethods = [];
    private array $methodDeclarationLines = [];

    public function __construct(string $filePath, array $configuration)
    {
        $this->filePath = $filePath;
        $this->configuration = $configuration;
    }

    public function enterNode(Node $node): ?int
    {
        // Track private method declarations
        if ($node instanceof Node\Stmt\ClassMethod) {
            if ($node->isPrivate()) {
                $methodName = $node->name->toString();
                $this->declaredPrivateMethods[] = $methodName;
                $this->methodDeclarationLines[$methodName] = $node->getStartLine();
            }
            
            // Check for unreachable code within this method
            if ($this->configuration['check_code_after_return'] ?? true) {
                $this->checkUnreachableCodeInMethod($node);
            }
        }
        
        // Track method calls
        if ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall) {
            if ($node->name instanceof Node\Identifier) {
                $this->calledMethods[] = $node->name->toString();
            }
        }
        
        // Check for always-false conditions
        if ($this->configuration['check_always_false_conditions'] ?? true) {
            if ($node instanceof Node\Stmt\If_) {
                if ($this->isAlwaysFalse($node->cond)) {
                    $this->violations[] = new Violation(
                        'unreachable-if-block',
                        'If-block is unreachable (condition is always false)',
                        $this->filePath,
                        $node->getStartLine(),
                        0,
                        $this->configuration['severity_always_false'] ?? Violation::SEVERITY_WARNING,
                        Violation::TYPE_LOGIC
                    );
                }
            }
        }
        
        return null;
    }

    /**
     * Check for unreachable code after return/throw in a method
     */
    private function checkUnreachableCodeInMethod(Node\Stmt\ClassMethod $method): void
    {
        $foundTerminator = false;
        $terminatorLine = 0;
        
        if (!$method->stmts) {
            return;
        }
        
        foreach ($method->stmts as $stmt) {
            // Check if we found a return/throw and then more code
            if ($foundTerminator && $this->isRealStatement($stmt)) {
                $this->violations[] = new Violation(
                    'unreachable-code-after-return',
                    'Unreachable code detected after return/throw statement at line ' . $terminatorLine,
                    $this->filePath,
                    $stmt->getStartLine(),
                    0,
                    $this->configuration['severity_unreachable'] ?? Violation::SEVERITY_WARNING,
                    Violation::TYPE_LOGIC
                );
                // Only report first unreachable statement to avoid spam
                break;
            }
            
            // Check if this is a terminator (return/throw)
            if ($this->isTerminator($stmt)) {
                $foundTerminator = true;
                $terminatorLine = $stmt->getStartLine();
            }
            
            // Reset if we hit a control structure (nested blocks can have their own flow)
            if ($this->isControlStructure($stmt)) {
                $foundTerminator = false;
            }
        }
    }

    /**
     * Check if node is a statement that terminates execution
     */
    private function isTerminator(Node $node): bool
    {
        return $node instanceof Node\Stmt\Return_ || 
               $node instanceof Node\Stmt\Throw_ ||
               $node instanceof Node\Stmt\Break_ ||
               $node instanceof Node\Stmt\Continue_;
    }

    /**
     * Check if node is a control structure
     */
    private function isControlStructure(Node $node): bool
    {
        return $node instanceof Node\Stmt\If_ ||
               $node instanceof Node\Stmt\For_ ||
               $node instanceof Node\Stmt\Foreach_ ||
               $node instanceof Node\Stmt\While_ ||
               $node instanceof Node\Stmt\Do_ ||
               $node instanceof Node\Stmt\Switch_ ||
               $node instanceof Node\Stmt\TryCatch;
    }

    /**
     * Check if node is a real statement (not comment/empty)
     */
    private function isRealStatement(Node $node): bool
    {
        // Exclude empty statements, inline HTML, and namespace statements
        return !($node instanceof Node\Stmt\Nop ||
                 $node instanceof Node\Stmt\InlineHTML ||
                 $node instanceof Node\Stmt\Namespace_);
    }

    /**
     * Check if condition is always false
     */
    private function isAlwaysFalse($condition): bool
    {
        // Literal false
        if ($condition instanceof Node\Expr\ConstFetch) {
            $name = $condition->name->toString();
            return strtolower($name) === 'false';
        }
        
        // 0, 0.0, empty string
        if ($condition instanceof Node\Scalar\LNumber) {
            return $condition->value === 0;
        }
        
        if ($condition instanceof Node\Scalar\DNumber) {
            return $condition->value === 0.0;
        }
        
        if ($condition instanceof Node\Scalar\String_) {
            return $condition->value === '';
        }
        
        // Could add more sophisticated constant folding here
        return false;
    }

    /**
     * After traversal, check for unused private methods
     */
    public function afterTraverse(array $nodes)
    {
        if ($this->configuration['check_unused_private_methods'] ?? true) {
            $unusedMethods = array_diff($this->declaredPrivateMethods, $this->calledMethods);
            
            foreach ($unusedMethods as $methodName) {
                $line = $this->methodDeclarationLines[$methodName] ?? 0;
                
                $this->violations[] = new Violation(
                    'unused-private-method',
                    "Private method '{$methodName}' is never called (potential dead code)",
                    $this->filePath,
                    $line,
                    0,
                    $this->configuration['severity_unused_method'] ?? Violation::SEVERITY_INFO,
                    Violation::TYPE_MAINTAINABILITY
                );
            }
        }
        
        return null;
    }

    public function getViolations(): array
    {
        return $this->violations;
    }
}
