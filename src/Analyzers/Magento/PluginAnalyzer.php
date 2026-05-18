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

class PluginAnalyzer extends AbstractAnalyzer
{
    private Parser $parser;
    private array $pluginMethods = [];
    private array $declaredPlugins = [];
    
    public function __construct(array $configuration = [], ?\Psr\Log\LoggerInterface $logger = null)
    {
        parent::__construct($configuration, $logger);
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    public function getName(): string
    {
        return 'magento-plugin-analyzer';
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
            'check_plugin_methods' => true,
            'check_method_signatures' => true,
            'check_sort_order_conflicts' => true,
            'check_plugin_naming' => true,
            'check_return_types' => true,
            'check_parameter_types' => true,
            'check_plugin_scope' => true,
            'validate_intercepted_methods' => true,
            'check_plugin_performance' => true,
            'severity_method_signature' => Violation::SEVERITY_ERROR,
            'severity_naming_convention' => Violation::SEVERITY_WARNING,
            'severity_performance' => Violation::SEVERITY_WARNING,
            'severity_sort_order' => Violation::SEVERITY_INFO,
            'valid_plugin_prefixes' => ['before', 'after', 'around'],
            'performance_sensitive_methods' => [
                'save', 'load', 'delete', 'execute', 'dispatch',
                'getCollection', 'toArray', 'getData'
            ],
            'exclude_paths' => [
                '*/Test/*',
                '*/tests/*',
                '*/dev/*',
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
        $this->logger->info('Starting Magento plugin analysis');
        
        $violations = [];
        
        // First pass: collect all di.xml plugin declarations
        foreach ($files as $file) {
            if (str_ends_with($file, 'di.xml')) {
                $this->collectPluginDeclarations($file);
            }
        }
        
        // Second pass: analyze plugin classes
        foreach ($files as $file) {
            if (str_ends_with($file, '.php') && $this->shouldAnalyzeFile($file)) {
                $violations = array_merge($violations, $this->analyzePluginFile($file));
            }
        }
        
        // Third pass: check for conflicts and issues
        $violations = array_merge($violations, $this->checkPluginConflicts());
        
        $this->logger->info('Found ' . count($violations) . ' plugin violations');
        
        return $violations;
    }

    protected function parseOutput(string $output, array $files): array
    {
        return [];
    }

    private function collectPluginDeclarations(string $filePath): void
    {
        $content = file_get_contents($filePath);
        if (!$content) {
            return;
        }
        
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($content)) {
            return;
        }
        
        $xpath = new \DOMXPath($dom);
        $types = $xpath->query('//type');
        
        foreach ($types as $type) {
            $typeName = $type->getAttribute('name');
            $plugins = $xpath->query('.//plugin', $type);
            
            foreach ($plugins as $plugin) {
                $pluginName = $plugin->getAttribute('name');
                $pluginType = $plugin->getAttribute('type');
                $sortOrder = $plugin->getAttribute('sortOrder') ?: '0';
                $disabled = $plugin->getAttribute('disabled') === 'true';
                
                $this->declaredPlugins[] = [
                    'name' => $pluginName,
                    'type' => $pluginType,
                    'intercepted_type' => $typeName,
                    'sort_order' => (int)$sortOrder,
                    'disabled' => $disabled,
                    'file' => $filePath,
                ];
            }
        }
    }

    private function analyzePluginFile(string $filePath): array
    {
        $violations = [];
        $content = file_get_contents($filePath);
        
        if (!$content) {
            return $violations;
        }
        
        // Check if this file contains plugin methods
        if (!$this->containsPluginMethods($content)) {
            return $violations;
        }
        
        try {
            $ast = $this->parser->parse($content);
            if (!$ast) {
                return $violations;
            }
            
            $visitor = new PluginAnalysisVisitor($filePath, $this->configuration, $this->declaredPlugins);
            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);
            
            $violations = array_merge($violations, $visitor->getViolations());
            
        } catch (\Exception $e) {
            $this->logger->error("Failed to parse plugin file: {$filePath}", ['error' => $e->getMessage()]);
        }
        
        return $violations;
    }

    private function containsPluginMethods(string $content): bool
    {
        foreach ($this->configuration['valid_plugin_prefixes'] as $prefix) {
            if (preg_match('/function\s+' . $prefix . '[A-Z]/', $content)) {
                return true;
            }
        }
        return false;
    }

    private function checkPluginConflicts(): array
    {
        $violations = [];
        $pluginsByInterceptedType = [];
        
        // Group plugins by intercepted type
        foreach ($this->declaredPlugins as $plugin) {
            if ($plugin['disabled']) {
                continue;
            }
            
            $interceptedType = $plugin['intercepted_type'];
            if (!isset($pluginsByInterceptedType[$interceptedType])) {
                $pluginsByInterceptedType[$interceptedType] = [];
            }
            $pluginsByInterceptedType[$interceptedType][] = $plugin;
        }
        
        // Check for sort order conflicts
        foreach ($pluginsByInterceptedType as $interceptedType => $plugins) {
            if (count($plugins) < 2) {
                continue;
            }
            
            $sortOrders = array_column($plugins, 'sort_order');
            $duplicateSortOrders = array_diff_assoc($sortOrders, array_unique($sortOrders));
            
            if (!empty($duplicateSortOrders)) {
                foreach ($plugins as $plugin) {
                    if (in_array($plugin['sort_order'], $duplicateSortOrders)) {
                        $violations[] = new Violation(
                            'magento-plugin-sort-order-conflict',
                            "Plugin '{$plugin['name']}' has conflicting sort order {$plugin['sort_order']} for type '{$interceptedType}'",
                            $plugin['file'],
                            0,
                            0,
                            $this->configuration['severity_sort_order'],
                            Violation::TYPE_LOGIC
                        );
                    }
                }
            }
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
}

class PluginAnalysisVisitor extends NodeVisitorAbstract
{
    private string $filePath;
    private array $configuration;
    private array $declaredPlugins;
    private array $violations = [];
    private string $currentClass = '';
    private array $pluginMethods = [];

    public function __construct(string $filePath, array $configuration, array $declaredPlugins)
    {
        $this->filePath = $filePath;
        $this->configuration = $configuration;
        $this->declaredPlugins = $declaredPlugins;
    }

    public function enterNode(Node $node): void
    {
        if ($node instanceof Node\Stmt\Class_) {
            $this->currentClass = $node->name->name ?? '';
        }
        
        if ($node instanceof Node\Stmt\ClassMethod) {
            $this->analyzeMethod($node);
        }
    }

    public function getViolations(): array
    {
        return $this->violations;
    }

    private function analyzeMethod(Node\Stmt\ClassMethod $method): void
    {
        $methodName = $method->name->name;
        
        // Check if this is a plugin method
        $pluginType = $this->getPluginMethodType($methodName);
        if (!$pluginType) {
            return;
        }
        
        $originalMethodName = $this->getOriginalMethodName($methodName, $pluginType);
        
        // Store plugin method for further analysis
        $this->pluginMethods[] = [
            'name' => $methodName,
            'type' => $pluginType,
            'original_method' => $originalMethodName,
            'line' => $method->getStartLine(),
        ];
        
        // Check method signature
        $this->validateMethodSignature($method, $pluginType, $originalMethodName);
        
        // Check naming conventions
        $this->validateNamingConventions($method, $pluginType, $originalMethodName);
        
        // Check return types
        $this->validateReturnTypes($method, $pluginType);
        
        // Check performance considerations
        $this->checkPerformanceConsiderations($method, $originalMethodName);
    }

    private function getPluginMethodType(string $methodName): ?string
    {
        foreach ($this->configuration['valid_plugin_prefixes'] as $prefix) {
            if (str_starts_with($methodName, $prefix) && 
                strlen($methodName) > strlen($prefix) &&
                ctype_upper($methodName[strlen($prefix)])) {
                return $prefix;
            }
        }
        return null;
    }

    private function getOriginalMethodName(string $pluginMethodName, string $pluginType): string
    {
        $prefixLength = strlen($pluginType);
        $originalMethod = substr($pluginMethodName, $prefixLength);
        return lcfirst($originalMethod);
    }

    private function validateMethodSignature(Node\Stmt\ClassMethod $method, string $pluginType, string $originalMethod): void
    {
        $methodName = $method->name->name;
        $params = $method->params;
        
        // Before and after plugins should have specific parameter patterns
        if ($pluginType === 'before') {
            // Before plugins should have the intercepted object as first parameter
            if (empty($params)) {
                $this->violations[] = new Violation(
                    'magento-plugin-before-missing-subject',
                    "Before plugin method '{$methodName}' must have subject as first parameter",
                    $this->filePath,
                    $method->getStartLine(),
                    0,
                    $this->configuration['severity_method_signature'],
                    Violation::TYPE_LOGIC
                );
            }
        } elseif ($pluginType === 'after') {
            // After plugins should have subject and result parameters
            if (count($params) < 2) {
                $this->violations[] = new Violation(
                    'magento-plugin-after-missing-params',
                    "After plugin method '{$methodName}' must have subject and result parameters",
                    $this->filePath,
                    $method->getStartLine(),
                    0,
                    $this->configuration['severity_method_signature'],
                    Violation::TYPE_LOGIC
                );
            }
        } elseif ($pluginType === 'around') {
            // Around plugins should have subject and proceed parameters
            if (count($params) < 2) {
                $this->violations[] = new Violation(
                    'magento-plugin-around-missing-params',
                    "Around plugin method '{$methodName}' must have subject and proceed parameters",
                    $this->filePath,
                    $method->getStartLine(),
                    0,
                    $this->configuration['severity_method_signature'],
                    Violation::TYPE_LOGIC
                );
            } else {
                // Check if second parameter is callable
                $proceedParam = $params[1];
                if ($proceedParam->type && 
                    $proceedParam->type instanceof Node\Name && 
                    $proceedParam->type->toString() !== 'callable') {
                    $this->violations[] = new Violation(
                        'magento-plugin-around-proceed-not-callable',
                        "Around plugin proceed parameter should be callable",
                        $this->filePath,
                        $method->getStartLine(),
                        0,
                        $this->configuration['severity_method_signature'],
                        Violation::TYPE_LOGIC
                    );
                }
            }
        }
        
        // Check parameter naming
        if (!empty($params)) {
            $subjectParam = $params[0];
            if ($subjectParam->var instanceof Node\Expr\Variable && 
                $subjectParam->var->name !== 'subject') {
                $this->violations[] = new Violation(
                    'magento-plugin-subject-naming',
                    "First parameter should be named 'subject' in plugin methods",
                    $this->filePath,
                    $method->getStartLine(),
                    0,
                    $this->configuration['severity_naming_convention'],
                    Violation::TYPE_STYLE
                );
            }
        }
    }

    private function validateNamingConventions(Node\Stmt\ClassMethod $method, string $pluginType, string $originalMethod): void
    {
        $methodName = $method->name->name;
        
        // Check if method name follows camelCase after prefix
        $expectedName = $pluginType . ucfirst($originalMethod);
        if ($methodName !== $expectedName) {
            $this->violations[] = new Violation(
                'magento-plugin-naming-convention',
                "Plugin method should be named '{$expectedName}' instead of '{$methodName}'",
                $this->filePath,
                $method->getStartLine(),
                0,
                $this->configuration['severity_naming_convention'],
                Violation::TYPE_STYLE
            );
        }
    }

    private function validateReturnTypes(Node\Stmt\ClassMethod $method, string $pluginType): void
    {
        $methodName = $method->name->name;
        $returnType = $method->returnType;
        
        // Before plugins should return void or array
        if ($pluginType === 'before') {
            if ($returnType && 
                $returnType instanceof Node\Name && 
                !in_array($returnType->toString(), ['void', 'array'])) {
                $this->violations[] = new Violation(
                    'magento-plugin-before-return-type',
                    "Before plugin method '{$methodName}' should return void or array",
                    $this->filePath,
                    $method->getStartLine(),
                    0,
                    $this->configuration['severity_method_signature'],
                    Violation::TYPE_LOGIC
                );
            }
        }
        
        // After plugins should return the same type as intercepted method
        if ($pluginType === 'after') {
            // This would require analyzing the intercepted method, which is complex
            // For now, we just check that it has a return statement
            $hasReturn = $this->hasReturnStatement($method);
            if (!$hasReturn && (!$returnType || $returnType->toString() !== 'void')) {
                $this->violations[] = new Violation(
                    'magento-plugin-after-missing-return',
                    "After plugin method '{$methodName}' should return the result",
                    $this->filePath,
                    $method->getStartLine(),
                    0,
                    $this->configuration['severity_method_signature'],
                    Violation::TYPE_LOGIC
                );
            }
        }
        
        // Around plugins should return the same type as intercepted method
        if ($pluginType === 'around') {
            $hasReturn = $this->hasReturnStatement($method);
            if (!$hasReturn) {
                $this->violations[] = new Violation(
                    'magento-plugin-around-missing-return',
                    "Around plugin method '{$methodName}' must return result from proceed call",
                    $this->filePath,
                    $method->getStartLine(),
                    0,
                    $this->configuration['severity_method_signature'],
                    Violation::TYPE_LOGIC
                );
            }
        }
    }

    private function checkPerformanceConsiderations(Node\Stmt\ClassMethod $method, string $originalMethod): void
    {
        $methodName = $method->name->name;
        
        // Check if plugin is applied to performance-sensitive methods
        if (in_array($originalMethod, $this->configuration['performance_sensitive_methods'])) {
            $this->violations[] = new Violation(
                'magento-plugin-performance-sensitive',
                "Plugin '{$methodName}' is applied to performance-sensitive method '{$originalMethod}'. Consider optimization.",
                $this->filePath,
                $method->getStartLine(),
                0,
                $this->configuration['severity_performance'],
                Violation::TYPE_PERFORMANCE
            );
        }
        
        // Check for complex logic in plugins
        $complexity = $this->calculateMethodComplexity($method);
        if ($complexity > 10) {
            $this->violations[] = new Violation(
                'magento-plugin-high-complexity',
                "Plugin method '{$methodName}' has high complexity ({$complexity}). Consider refactoring.",
                $this->filePath,
                $method->getStartLine(),
                0,
                $this->configuration['severity_performance'],
                Violation::TYPE_MAINTAINABILITY
            );
        }
    }

    private function hasReturnStatement(Node\Stmt\ClassMethod $method): bool
    {
        foreach ($method->stmts ?? [] as $stmt) {
            if ($stmt instanceof Node\Stmt\Return_) {
                return true;
            }
            
            // Check nested statements (simplified)
            if (property_exists($stmt, 'stmts') && is_array($stmt->stmts)) {
                foreach ($stmt->stmts as $nestedStmt) {
                    if ($nestedStmt instanceof Node\Stmt\Return_) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    private function calculateMethodComplexity(Node\Stmt\ClassMethod $method): int
    {
        $complexity = 1; // Base complexity
        
        foreach ($method->stmts ?? [] as $stmt) {
            if ($stmt instanceof Node\Stmt\If_ ||
                $stmt instanceof Node\Stmt\While_ ||
                $stmt instanceof Node\Stmt\For_ ||
                $stmt instanceof Node\Stmt\Foreach_ ||
                $stmt instanceof Node\Stmt\Switch_ ||
                $stmt instanceof Node\Stmt\TryCatch) {
                $complexity++;
            }
        }
        
        return $complexity;
    }
} 