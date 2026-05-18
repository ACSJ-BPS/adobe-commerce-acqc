<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Magento;

use ACQC\Analyzers\AbstractAnalyzer;
use ACQC\Core\Rules\Violation;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Magento GraphQL Analyzer
 * 
 * Analyzes GraphQL implementations including:
 * - schema.graphqls files
 * - GraphQL resolvers
 * - GraphQL models
 * - N+1 query detection
 * - Security and authorization
 * - Performance optimization
 */
class GraphQLAnalyzer extends AbstractAnalyzer
{
    private Parser $parser;
    private array $graphqlTypes = [];
    private array $graphqlResolvers = [];
    
    public function __construct(array $configuration = [], ?\Psr\Log\LoggerInterface $logger = null)
    {
        parent::__construct($configuration, $logger);
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    public function getName(): string
    {
        return 'magento-graphql-analyzer';
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
            'check_schema' => true,
            'check_resolvers' => true,
            'check_performance' => true,
            'check_security' => true,
            'check_documentation' => true,
            'check_n_plus_one' => true,
            'severity_security' => Violation::SEVERITY_CRITICAL,
            'severity_performance' => Violation::SEVERITY_WARNING,
            'severity_documentation' => Violation::SEVERITY_INFO,
            'max_query_depth' => 5,
            'max_query_complexity' => 100,
            'graphql_patterns' => [
                '/GraphQl/',
                '/Resolver/',
                'schema.graphqls',
                'Resolver.php',
            ],
            'exclude_paths' => [
                '*/Test/*',
                '*/tests/*',
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
        $this->logger->info('Starting Magento GraphQL analysis');
        
        $violations = [];
        
        // Phase 1: Parse schema.graphqls files
        foreach ($files as $file) {
            if (str_ends_with($file, 'schema.graphqls')) {
                $violations = array_merge($violations, $this->analyzeGraphQLSchema($file));
            }
        }
        
        // Phase 2: Analyze GraphQL resolver files
        foreach ($files as $file) {
            if ($this->isGraphQLResolver($file) && $this->shouldAnalyzeFile($file)) {
                $violations = array_merge($violations, $this->analyzeGraphQLResolver($file));
            }
        }
        
        // Phase 3: Analyze GraphQL model files
        foreach ($files as $file) {
            if ($this->isGraphQLModel($file) && $this->shouldAnalyzeFile($file)) {
                $violations = array_merge($violations, $this->analyzeGraphQLModel($file));
            }
        }
        
        $this->logger->info('Found ' . count($violations) . ' GraphQL violations');
        
        return $violations;
    }

    protected function parseOutput(string $output, array $files): array
    {
        return [];
    }

    private function analyzeGraphQLSchema(string $filePath): array
    {
        $violations = [];
        $content = file_get_contents($filePath);
        
        if (!$content) {
            return $violations;
        }
        
        // Parse GraphQL schema
        $lines = explode("\n", $content);
        $currentType = null;
        $currentLine = 0;
        
        foreach ($lines as $lineNum => $line) {
            $currentLine = $lineNum + 1;
            $line = trim($line);
            
            // Skip comments and empty lines
            if (empty($line) || strpos($line, '#') === 0) {
                continue;
            }
            
            // Detect type definitions
            if (preg_match('/^type\s+(\w+)/', $line, $matches)) {
                $currentType = $matches[1];
                $this->graphqlTypes[$currentType] = [
                    'file' => $filePath,
                    'line' => $currentLine,
                    'fields' => []
                ];
                
                // Check for proper type naming
                if (!preg_match('/^[A-Z][a-zA-Z0-9]*$/', $currentType)) {
                    $violations[] = new Violation(
                        'magento-graphql-type-naming',
                        "GraphQL type '{$currentType}' doesn't follow PascalCase naming convention",
                        $filePath,
                        $currentLine,
                        0,
                        Violation::SEVERITY_WARNING,
                        'graphql'
                    );
                }
            }
            
            // Detect field definitions
            if ($currentType && preg_match('/^\s*(\w+)\s*:\s*(.+)/', $line, $matches)) {
                $fieldName = $matches[1];
                $fieldType = trim($matches[2]);
                
                $this->graphqlTypes[$currentType]['fields'][] = $fieldName;
                
                // Check for proper field naming (camelCase)
                if (!preg_match('/^[a-z][a-zA-Z0-9]*$/', $fieldName)) {
                    $violations[] = new Violation(
                        'magento-graphql-field-naming',
                        "GraphQL field '{$fieldName}' in type '{$currentType}' doesn't follow camelCase convention",
                        $filePath,
                        $currentLine,
                        0,
                        Violation::SEVERITY_INFO,
                        'graphql'
                    );
                }
                
                // Check for documentation
                $prevLine = $lineNum > 0 ? trim($lines[$lineNum - 1]) : '';
                if (strpos($prevLine, '#') !== 0 && !str_contains($prevLine, '@doc')) {
                    $violations[] = new Violation(
                        'magento-graphql-missing-field-docs',
                        "GraphQL field '{$fieldName}' missing documentation",
                        $filePath,
                        $currentLine,
                        0,
                        $this->configuration['severity_documentation'],
                        'graphql'
                    );
                }
            }
            
            // Detect interface definitions
            if (preg_match('/^interface\s+(\w+)/', $line, $matches)) {
                $interfaceName = $matches[1];
                
                // Check for proper interface naming
                if (!str_ends_with($interfaceName, 'Interface')) {
                    $violations[] = new Violation(
                        'magento-graphql-interface-naming',
                        "GraphQL interface '{$interfaceName}' should end with 'Interface'",
                        $filePath,
                        $currentLine,
                        0,
                        Violation::SEVERITY_WARNING,
                        'graphql'
                    );
                }
            }
            
            // Detect input type definitions
            if (preg_match('/^input\s+(\w+)/', $line, $matches)) {
                $inputName = $matches[1];
                
                // Check for proper input naming
                if (!str_ends_with($inputName, 'Input')) {
                    $violations[] = new Violation(
                        'magento-graphql-input-naming',
                        "GraphQL input type '{$inputName}' should end with 'Input'",
                        $filePath,
                        $currentLine,
                        0,
                        Violation::SEVERITY_INFO,
                        'graphql'
                    );
                }
            }
        }
        
        return $violations;
    }

    private function analyzeGraphQLResolver(string $filePath): array
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
            
            $visitor = new GraphQLResolverVisitor($filePath, $this->configuration);
            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);
            
            $violations = array_merge($violations, $visitor->getViolations());
            
        } catch (\Exception $e) {
            $this->logger->error("Failed to parse GraphQL resolver: {$filePath}", ['error' => $e->getMessage()]);
        }
        
        return $violations;
    }

    private function analyzeGraphQLModel(string $filePath): array
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
            
            $visitor = new GraphQLModelVisitor($filePath, $this->configuration);
            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);
            
            $violations = array_merge($violations, $visitor->getViolations());
            
        } catch (\Exception $e) {
            $this->logger->error("Failed to parse GraphQL model: {$filePath}", ['error' => $e->getMessage()]);
        }
        
        return $violations;
    }

    private function isGraphQLResolver(string $filePath): bool
    {
        return str_contains($filePath, '/GraphQl/') && 
               str_contains($filePath, '/Resolver/') &&
               str_ends_with($filePath, '.php');
    }

    private function isGraphQLModel(string $filePath): bool
    {
        return str_contains($filePath, '/GraphQl/') && 
               str_contains($filePath, '/Model/') &&
               str_ends_with($filePath, '.php');
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
}

class GraphQLResolverVisitor extends NodeVisitorAbstract
{
    private string $filePath;
    private array $configuration;
    private array $violations = [];
    private string $currentMethod = '';
    private int $dbCallsInMethod = 0;
    private bool $inLoop = false;
    private bool $hasAuthorizationCheck = false;

    public function __construct(string $filePath, array $configuration)
    {
        $this->filePath = $filePath;
        $this->configuration = $configuration;
    }

    public function enterNode(Node $node): void
    {
        if ($node instanceof Node\Stmt\Class_) {
            $this->analyzeResolverClass($node);
        }
        
        if ($node instanceof Node\Stmt\ClassMethod) {
            $this->currentMethod = $node->name->toString();
            $this->dbCallsInMethod = 0;
            $this->hasAuthorizationCheck = false;
            
            if ($this->currentMethod === 'resolve') {
                $this->analyzeResolveMethod($node);
            }
        }
        
        if ($node instanceof Node\Stmt\For_ || 
            $node instanceof Node\Stmt\Foreach_ || 
            $node instanceof Node\Stmt\While_) {
            $this->inLoop = true;
        }
        
        if ($node instanceof Node\Expr\MethodCall) {
            $this->analyzeMethodCall($node);
        }
        
        if ($node instanceof Node\Expr\FuncCall) {
            $this->analyzeFunctionCall($node);
        }
    }

    public function leaveNode(Node $node): void
    {
        if ($node instanceof Node\Stmt\For_ || 
            $node instanceof Node\Stmt\Foreach_ || 
            $node instanceof Node\Stmt\While_) {
            $this->inLoop = false;
        }
        
        if ($node instanceof Node\Stmt\ClassMethod && $node->name->toString() === 'resolve') {
            if ($this->dbCallsInMethod > 2) {
                $this->violations[] = new Violation(
                    'magento-graphql-too-many-db-calls',
                    "GraphQL resolver has {$this->dbCallsInMethod} database calls - consider DataLoader pattern",
                    $this->filePath,
                    $node->getStartLine(),
                    0,
                    $this->configuration['severity_performance'],
                    'graphql'
                );
            }
            
            if (!$this->hasAuthorizationCheck) {
                $this->violations[] = new Violation(
                    'magento-graphql-missing-authorization',
                    "GraphQL resolver missing authorization check - verify security requirements",
                    $this->filePath,
                    $node->getStartLine(),
                    0,
                    $this->configuration['severity_security'],
                    'graphql'
                );
            }
            
            $this->currentMethod = '';
        }
    }

    public function getViolations(): array
    {
        return $this->violations;
    }

    private function analyzeResolverClass(Node\Stmt\Class_ $node): void
    {
        $className = $node->name->toString();
        
        // Check if implements ResolverInterface
        $implementsResolverInterface = false;
        if ($node->implements) {
            foreach ($node->implements as $interface) {
                if ($interface->toString() === 'ResolverInterface') {
                    $implementsResolverInterface = true;
                    break;
                }
            }
        }
        
        if (!$implementsResolverInterface) {
            $this->violations[] = new Violation(
                'magento-graphql-missing-interface',
                "GraphQL resolver '{$className}' should implement ResolverInterface",
                $this->filePath,
                $node->getStartLine(),
                0,
                Violation::SEVERITY_ERROR,
                'graphql'
            );
        }
    }

    private function analyzeResolveMethod(Node\Stmt\ClassMethod $node): void
    {
        // Check for proper resolve() signature
        if (count($node->params) < 4) {
            $this->violations[] = new Violation(
                'magento-graphql-invalid-resolve-signature',
                "resolve() method should have 4 parameters: Field, Context, ResolveInfo, array",
                $this->filePath,
                $node->getStartLine(),
                0,
                Violation::SEVERITY_ERROR,
                'graphql'
            );
        }
        
        // Check for try-catch
        $hasTryCatch = false;
        foreach ($node->stmts ?? [] as $stmt) {
            if ($stmt instanceof Node\Stmt\TryCatch) {
                $hasTryCatch = true;
                break;
            }
        }
        
        if (!$hasTryCatch) {
            $this->violations[] = new Violation(
                'magento-graphql-missing-error-handling',
                "GraphQL resolver missing try-catch error handling",
                $this->filePath,
                $node->getStartLine(),
                0,
                Violation::SEVERITY_WARNING,
                'graphql'
            );
        }
    }

    private function analyzeMethodCall(Node\Expr\MethodCall $node): void
    {
        if ($node->name instanceof Node\Identifier) {
            $methodName = $node->name->name;
            
            // Check for database operations
            if (in_array($methodName, ['load', 'save', 'delete', 'getCollection', 'getList'])) {
                $this->dbCallsInMethod++;
                
                if ($this->inLoop) {
                    $this->violations[] = new Violation(
                        'magento-graphql-n-plus-one',
                        "Database operation '{$methodName}' in loop causes N+1 problem - use DataLoader",
                        $this->filePath,
                        $node->getStartLine(),
                        0,
                        Violation::SEVERITY_CRITICAL,
                        'graphql'
                    );
                }
            }
            
            // Check for authorization
            if (in_array($methodName, ['isAllowed', 'authorize', 'checkPermission'])) {
                $this->hasAuthorizationCheck = true;
            }
            
            // Check for session usage (anti-pattern in GraphQL)
            if (str_contains($methodName, 'Session') || str_contains($methodName, 'session')) {
                $this->violations[] = new Violation(
                    'magento-graphql-session-usage',
                    "Session usage in GraphQL resolver - GraphQL should be stateless",
                    $this->filePath,
                    $node->getStartLine(),
                    0,
                    Violation::SEVERITY_WARNING,
                    'graphql'
                );
            }
        }
    }

    private function analyzeFunctionCall(Node\Expr\FuncCall $node): void
    {
        if ($node->name instanceof Node\Name) {
            $functionName = $node->name->toString();
            
            // Check for expensive operations in resolvers
            $expensiveOps = ['file_get_contents', 'curl_exec', 'unserialize', 'sleep'];
            if (in_array($functionName, $expensiveOps)) {
                $this->violations[] = new Violation(
                    'magento-graphql-expensive-operation',
                    "Expensive operation '{$functionName}' in GraphQL resolver - consider async processing",
                    $this->filePath,
                    $node->getStartLine(),
                    0,
                    $this->configuration['severity_performance'],
                    'graphql'
                );
            }
        }
    }
}

class GraphQLModelVisitor extends NodeVisitorAbstract
{
    private string $filePath;
    private array $configuration;
    private array $violations = [];

    public function __construct(string $filePath, array $configuration)
    {
        $this->filePath = $filePath;
        $this->configuration = $configuration;
    }

    public function enterNode(Node $node): void
    {
        if ($node instanceof Node\Stmt\Class_) {
            $this->analyzeModelClass($node);
        }
        
        if ($node instanceof Node\Stmt\ClassMethod) {
            $this->analyzeMethod($node);
        }
    }

    public function getViolations(): array
    {
        return $this->violations;
    }

    private function analyzeModelClass(Node\Stmt\Class_ $node): void
    {
        $className = $node->name->toString();
        
        // Check for proper documentation
        $docComment = $node->getDocComment();
        if (!$docComment) {
            $this->violations[] = new Violation(
                'magento-graphql-model-missing-docs',
                "GraphQL model '{$className}' missing documentation",
                $this->filePath,
                $node->getStartLine(),
                0,
                $this->configuration['severity_documentation'],
                'graphql'
            );
        }
    }

    private function analyzeMethod(Node\Stmt\ClassMethod $node): void
    {
        $methodName = $node->name->toString();
        
        // Check for lazy loading implementation
        if (str_starts_with($methodName, 'get') && !str_contains($methodName, 'Collection')) {
            // This is a getter - check if it might cause N+1
            $usesLoad = false;
            
            foreach ($node->stmts ?? [] as $stmt) {
                if ($this->containsLoadCall($stmt)) {
                    $usesLoad = true;
                    break;
                }
            }
            
            if ($usesLoad) {
                $this->violations[] = new Violation(
                    'magento-graphql-lazy-loading',
                    "Method '{$methodName}' uses lazy loading which can cause N+1 in GraphQL - consider eager loading",
                    $this->filePath,
                    $node->getStartLine(),
                    0,
                    Violation::SEVERITY_WARNING,
                    'graphql'
                );
            }
        }
    }

    private function containsLoadCall(Node $node): bool
    {
        if ($node instanceof Node\Expr\MethodCall) {
            if ($node->name instanceof Node\Identifier && $node->name->name === 'load') {
                return true;
            }
        }
        
        // Recursively check child nodes
        foreach ($node->getSubNodeNames() as $name) {
            $subNode = $node->$name;
            
            if ($subNode instanceof Node) {
                if ($this->containsLoadCall($subNode)) {
                    return true;
                }
            } elseif (is_array($subNode)) {
                foreach ($subNode as $item) {
                    if ($item instanceof Node && $this->containsLoadCall($item)) {
                        return true;
                    }
                }
            }
        }
        
        return false;
    }
}
