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
 * Magento REST API Service Analyzer
 * 
 * Analyzes REST API implementations including:
 * - webapi.xml configuration
 * - API interfaces and contracts
 * - Service implementations
 * - Security and authorization
 * - Performance and optimization
 */
class ApiServiceAnalyzer extends AbstractAnalyzer
{
    private Parser $parser;
    private array $apiEndpoints = [];
    private array $apiInterfaces = [];
    
    public function __construct(array $configuration = [], ?\Psr\Log\LoggerInterface $logger = null)
    {
        parent::__construct($configuration, $logger);
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    public function getName(): string
    {
        return 'magento-api-service-analyzer';
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
            'check_webapi_xml' => true,
            'check_api_annotations' => true,
            'check_security' => true,
            'check_performance' => true,
            'check_documentation' => true,
            'check_data_contracts' => true,
            'check_error_handling' => true,
            'severity_security' => Violation::SEVERITY_CRITICAL,
            'severity_performance' => Violation::SEVERITY_WARNING,
            'severity_documentation' => Violation::SEVERITY_INFO,
            'required_annotations' => [
                '@api',
                '@param',
                '@return',
                '@throws',
            ],
            'api_patterns' => [
                '/Api/',
                'Interface.php',
                'RepositoryInterface',
                'ManagementInterface',
                'ServiceInterface',
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
        $this->logger->info('Starting Magento REST API analysis');
        
        $violations = [];
        
        // Phase 1: Parse webapi.xml files to map endpoints
        foreach ($files as $file) {
            if (str_ends_with($file, 'webapi.xml')) {
                $violations = array_merge($violations, $this->analyzeWebapiXml($file));
            }
        }
        
        // Phase 2: Analyze API interface files
        foreach ($files as $file) {
            if ($this->isApiInterface($file) && $this->shouldAnalyzeFile($file)) {
                $violations = array_merge($violations, $this->analyzeApiInterface($file));
            }
        }
        
        // Phase 3: Analyze API implementation files
        foreach ($files as $file) {
            if ($this->isApiImplementation($file) && $this->shouldAnalyzeFile($file)) {
                $violations = array_merge($violations, $this->analyzeApiImplementation($file));
            }
        }
        
        $this->logger->info('Found ' . count($violations) . ' REST API violations');
        
        return $violations;
    }

    protected function parseOutput(string $output, array $files): array
    {
        return [];
    }

    private function analyzeWebapiXml(string $filePath): array
    {
        $violations = [];
        $content = file_get_contents($filePath);
        
        if (!$content) {
            return $violations;
        }
        
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($content)) {
            $violations[] = new Violation(
                'magento-api-invalid-webapi-xml',
                'Invalid XML in webapi.xml',
                $filePath,
                0,
                0,
                Violation::SEVERITY_ERROR,
                'api'
            );
            return $violations;
        }
        
        $xpath = new \DOMXPath($dom);
        
        // Analyze routes
        $routes = $xpath->query('//route');
        foreach ($routes as $route) {
            $violations = array_merge($violations, $this->analyzeRoute($route, $filePath, $xpath));
        }
        
        return $violations;
    }

    private function analyzeRoute(\DOMElement $route, string $filePath, \DOMXPath $xpath): array
    {
        $violations = [];
        $url = $route->getAttribute('url');
        $method = $route->getAttribute('method');
        
        // Store endpoint for reference
        $this->apiEndpoints[$url] = [
            'method' => $method,
            'file' => $filePath
        ];
        
        // Check for ACL resource
        $services = $xpath->query('.//service', $route);
        foreach ($services as $service) {
            $class = $service->getAttribute('class');
            $methodName = $service->getAttribute('method');
            
            // Check for resource attribute (ACL)
            $resources = $xpath->query('.//resource', $service);
            if ($resources->length === 0) {
                $violations[] = new Violation(
                    'magento-api-missing-acl',
                    "API endpoint '{$url}' missing ACL resource definition",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_security'],
                    'api'
                );
            } else {
                foreach ($resources as $resource) {
                    $resourceRef = $resource->getAttribute('ref');
                    if (empty($resourceRef) || $resourceRef === 'anonymous') {
                        $violations[] = new Violation(
                            'magento-api-anonymous-endpoint',
                            "API endpoint '{$url}' allows anonymous access - verify security requirements",
                            $filePath,
                            0,
                            0,
                            Violation::SEVERITY_WARNING,
                            'api'
                        );
                    }
                }
            }
            
            // Store interface mapping
            $this->apiInterfaces[$class] = [
                'url' => $url,
                'method' => $method,
                'methodName' => $methodName
            ];
        }
        
        // Check HTTP method consistency
        if (!in_array(strtoupper($method), ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'])) {
            $violations[] = new Violation(
                'magento-api-invalid-http-method',
                "Invalid HTTP method '{$method}' for endpoint '{$url}'",
                $filePath,
                0,
                0,
                Violation::SEVERITY_ERROR,
                'api'
            );
        }
        
        // Check for RESTful naming
        if (strtoupper($method) === 'GET' && strpos($url, '/save') !== false) {
            $violations[] = new Violation(
                'magento-api-inconsistent-naming',
                "GET endpoint '{$url}' contains '/save' - violates REST conventions",
                $filePath,
                0,
                0,
                Violation::SEVERITY_WARNING,
                'api'
            );
        }
        
        return $violations;
    }

    private function analyzeApiInterface(string $filePath): array
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
            
            $visitor = new ApiInterfaceVisitor($filePath, $this->configuration);
            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);
            
            $violations = array_merge($violations, $visitor->getViolations());
            
        } catch (\Exception $e) {
            $this->logger->error("Failed to parse API interface: {$filePath}", ['error' => $e->getMessage()]);
        }
        
        return $violations;
    }

    private function analyzeApiImplementation(string $filePath): array
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
            
            $visitor = new ApiImplementationVisitor($filePath, $this->configuration);
            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);
            
            $violations = array_merge($violations, $visitor->getViolations());
            
        } catch (\Exception $e) {
            $this->logger->error("Failed to parse API implementation: {$filePath}", ['error' => $e->getMessage()]);
        }
        
        return $violations;
    }

    private function isApiInterface(string $filePath): bool
    {
        if (!str_ends_with($filePath, '.php')) {
            return false;
        }
        
        foreach ($this->configuration['api_patterns'] as $pattern) {
            if (str_contains($filePath, $pattern)) {
                return true;
            }
        }
        
        return false;
    }

    private function isApiImplementation(string $filePath): bool
    {
        return str_contains($filePath, '/Model/') && 
               (str_contains($filePath, 'Repository.php') || 
                str_contains($filePath, 'Management.php') ||
                str_contains($filePath, 'Service.php'));
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

class ApiInterfaceVisitor extends NodeVisitorAbstract
{
    private string $filePath;
    private array $configuration;
    private array $violations = [];
    private bool $hasApiAnnotation = false;

    public function __construct(string $filePath, array $configuration)
    {
        $this->filePath = $filePath;
        $this->configuration = $configuration;
    }

    public function enterNode(Node $node): void
    {
        if ($node instanceof Node\Stmt\Interface_) {
            $this->analyzeInterface($node);
        }
        
        if ($node instanceof Node\Stmt\ClassMethod) {
            $this->analyzeMethod($node);
        }
    }

    public function getViolations(): array
    {
        return $this->violations;
    }

    private function analyzeInterface(Node\Stmt\Interface_ $node): void
    {
        $interfaceName = $node->name->toString();
        
        // Check for @api annotation
        $docComment = $node->getDocComment();
        if ($docComment) {
            $comment = $docComment->getText();
            if (strpos($comment, '@api') !== false) {
                $this->hasApiAnnotation = true;
            } else {
                $this->violations[] = new Violation(
                    'magento-api-missing-api-annotation',
                    "Interface '{$interfaceName}' missing @api annotation",
                    $this->filePath,
                    $node->getStartLine(),
                    0,
                    $this->configuration['severity_documentation'],
                    'api'
                );
            }
        } else {
            $this->violations[] = new Violation(
                'magento-api-missing-documentation',
                "Interface '{$interfaceName}' missing documentation block",
                $this->filePath,
                $node->getStartLine(),
                0,
                $this->configuration['severity_documentation'],
                'api'
            );
        }
    }

    private function analyzeMethod(Node\Stmt\ClassMethod $node): void
    {
        $methodName = $node->name->toString();
        
        if (!$node->isPublic()) {
            return; // Only analyze public methods
        }
        
        $docComment = $node->getDocComment();
        if (!$docComment) {
            $this->violations[] = new Violation(
                'magento-api-missing-method-docs',
                "Public method '{$methodName}' missing documentation",
                $this->filePath,
                $node->getStartLine(),
                0,
                $this->configuration['severity_documentation'],
                'api'
            );
            return;
        }
        
        $comment = $docComment->getText();
        
        // Check for required annotations
        if (!empty($node->params) && strpos($comment, '@param') === false) {
            $this->violations[] = new Violation(
                'magento-api-missing-param-docs',
                "Method '{$methodName}' missing @param documentation",
                $this->filePath,
                $node->getStartLine(),
                0,
                $this->configuration['severity_documentation'],
                'api'
            );
        }
        
        if (strpos($comment, '@return') === false) {
            $this->violations[] = new Violation(
                'magento-api-missing-return-docs',
                "Method '{$methodName}' missing @return documentation",
                $this->filePath,
                $node->getStartLine(),
                0,
                $this->configuration['severity_documentation'],
                'api'
            );
        }
        
        // Check for proper return type hint
        if (!$node->returnType) {
            $this->violations[] = new Violation(
                'magento-api-missing-return-type',
                "Method '{$methodName}' missing return type hint",
                $this->filePath,
                $node->getStartLine(),
                0,
                Violation::SEVERITY_WARNING,
                'api'
            );
        }
        
        // Check for proper parameter type hints
        foreach ($node->params as $param) {
            if (!$param->type) {
                $paramName = $param->var->name ?? 'unknown';
                $this->violations[] = new Violation(
                    'magento-api-missing-param-type',
                    "Parameter '\${$paramName}' in method '{$methodName}' missing type hint",
                    $this->filePath,
                    $node->getStartLine(),
                    0,
                    Violation::SEVERITY_WARNING,
                    'api'
                );
            }
        }
    }
}

class ApiImplementationVisitor extends NodeVisitorAbstract
{
    private string $filePath;
    private array $configuration;
    private array $violations = [];
    private string $currentMethod = '';
    private int $dbCallsInMethod = 0;
    private bool $inLoop = false;

    public function __construct(string $filePath, array $configuration)
    {
        $this->filePath = $filePath;
        $this->configuration = $configuration;
    }

    public function enterNode(Node $node): void
    {
        if ($node instanceof Node\Stmt\ClassMethod) {
            $this->currentMethod = $node->name->toString();
            $this->dbCallsInMethod = 0;
            $this->analyzeMethodImplementation($node);
        }
        
        if ($node instanceof Node\Stmt\For_ || 
            $node instanceof Node\Stmt\Foreach_ || 
            $node instanceof Node\Stmt\While_) {
            $this->inLoop = true;
        }
        
        if ($node instanceof Node\Expr\MethodCall) {
            $this->analyzeMethodCall($node);
        }
    }

    public function leaveNode(Node $node): void
    {
        if ($node instanceof Node\Stmt\For_ || 
            $node instanceof Node\Stmt\Foreach_ || 
            $node instanceof Node\Stmt\While_) {
            $this->inLoop = false;
        }
        
        if ($node instanceof Node\Stmt\ClassMethod) {
            if ($this->dbCallsInMethod > 3) {
                $this->violations[] = new Violation(
                    'magento-api-too-many-db-calls',
                    "API method '{$this->currentMethod}' has {$this->dbCallsInMethod} database calls - consider optimization",
                    $this->filePath,
                    $node->getStartLine(),
                    0,
                    $this->configuration['severity_performance'],
                    'api'
                );
            }
            
            $this->currentMethod = '';
        }
    }

    public function getViolations(): array
    {
        return $this->violations;
    }

    private function analyzeMethodImplementation(Node\Stmt\ClassMethod $node): void
    {
        // Check for try-catch blocks in API methods
        $hasTryCatch = false;
        foreach ($node->stmts ?? [] as $stmt) {
            if ($stmt instanceof Node\Stmt\TryCatch) {
                $hasTryCatch = true;
                break;
            }
        }
        
        if (!$hasTryCatch && $node->isPublic()) {
            $this->violations[] = new Violation(
                'magento-api-missing-error-handling',
                "Public API method '{$node->name}' missing try-catch error handling",
                $this->filePath,
                $node->getStartLine(),
                0,
                Violation::SEVERITY_WARNING,
                'api'
            );
        }
    }

    private function analyzeMethodCall(Node\Expr\MethodCall $node): void
    {
        if ($node->name instanceof Node\Identifier) {
            $methodName = $node->name->name;
            
            // Check for database operations
            if (in_array($methodName, ['load', 'save', 'delete', 'getCollection'])) {
                $this->dbCallsInMethod++;
                
                if ($this->inLoop) {
                    $this->violations[] = new Violation(
                        'magento-api-db-in-loop',
                        "Database operation '{$methodName}' in loop can cause N+1 queries in API response",
                        $this->filePath,
                        $node->getStartLine(),
                        0,
                        Violation::SEVERITY_CRITICAL,
                        'api'
                    );
                }
            }
            
            // Check for proper use of SearchCriteria
            if ($methodName === 'getList' && !$this->hasSearchCriteriaParameter($node)) {
                $this->violations[] = new Violation(
                    'magento-api-missing-search-criteria',
                    "getList() method should use SearchCriteriaInterface for filtering",
                    $this->filePath,
                    $node->getStartLine(),
                    0,
                    Violation::SEVERITY_INFO,
                    'api'
                );
            }
        }
    }

    private function hasSearchCriteriaParameter(Node\Expr\MethodCall $node): bool
    {
        // Simplified check - would need more sophisticated analysis
        return !empty($node->args);
    }
}
