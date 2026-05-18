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

class ObserverAnalyzer extends AbstractAnalyzer
{
    private Parser $parser;
    private array $declaredObservers = [];
    private array $dispatchedEvents = [];
    
    public function __construct(array $configuration = [], ?\Psr\Log\LoggerInterface $logger = null)
    {
        parent::__construct($configuration, $logger);
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    public function getName(): string
    {
        return 'magento-observer-analyzer';
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
            'check_observer_interface' => true,
            'check_event_naming' => true,
            'check_observer_performance' => true,
            'check_event_dispatching' => true,
            'check_observer_registration' => true,
            'validate_event_data' => true,
            'check_async_observers' => true,
            'severity_interface_violation' => Violation::SEVERITY_ERROR,
            'severity_naming_convention' => Violation::SEVERITY_WARNING,
            'severity_performance' => Violation::SEVERITY_WARNING,
            'severity_missing_registration' => Violation::SEVERITY_ERROR,
            'max_observers_per_event' => 10,
            'performance_sensitive_events' => [
                'controller_action_predispatch',
                'controller_action_postdispatch',
                'sales_order_save_before',
                'sales_order_save_after',
                'catalog_product_save_before',
                'catalog_product_save_after',
                'customer_login',
                'customer_logout',
            ],
            'deprecated_events' => [
                'core_block_abstract_prepare_layout_before',
                'core_block_abstract_prepare_layout_after',
                'adminhtml_block_html_before',
            ],
            'required_event_prefixes' => [
                'controller_action_',
                'adminhtml_',
                'sales_',
                'catalog_',
                'customer_',
                'checkout_',
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
        $this->logger->info('Starting Magento observer analysis');
        
        $violations = [];
        
        // First pass: collect observer registrations from events.xml
        foreach ($files as $file) {
            if (str_ends_with($file, 'events.xml')) {
                $this->collectObserverRegistrations($file);
            }
        }
        
        // Second pass: analyze observer classes and event dispatches
        foreach ($files as $file) {
            if (str_ends_with($file, '.php') && $this->shouldAnalyzeFile($file)) {
                $violations = array_merge($violations, $this->analyzePhpFile($file));
            }
        }
        
        // Third pass: cross-check and validate
        $violations = array_merge($violations, $this->validateObserverConsistency());
        
        $this->logger->info('Found ' . count($violations) . ' observer violations');
        
        return $violations;
    }

    protected function parseOutput(string $output, array $files): array
    {
        return [];
    }

    private function collectObserverRegistrations(string $filePath): void
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
        $events = $xpath->query('//event');
        
        foreach ($events as $event) {
            $eventName = $event->getAttribute('name');
            $observers = $xpath->query('.//observer', $event);
            
            foreach ($observers as $observer) {
                $observerName = $observer->getAttribute('name');
                $observerInstance = $observer->getAttribute('instance');
                $observerMethod = $observer->getAttribute('method') ?: 'execute';
                $disabled = $observer->getAttribute('disabled') === 'true';
                $shared = $observer->getAttribute('shared') !== 'false';
                
                $this->declaredObservers[] = [
                    'event' => $eventName,
                    'name' => $observerName,
                    'instance' => $observerInstance,
                    'method' => $observerMethod,
                    'disabled' => $disabled,
                    'shared' => $shared,
                    'file' => $filePath,
                ];
            }
        }
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
            
            $visitor = new ObserverAnalysisVisitor($filePath, $this->configuration, $this->declaredObservers);
            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);
            
            $violations = array_merge($violations, $visitor->getViolations());
            $this->dispatchedEvents = array_merge($this->dispatchedEvents, $visitor->getDispatchedEvents());
            
        } catch (\Exception $e) {
            $this->logger->error("Failed to parse file: {$filePath}", ['error' => $e->getMessage()]);
        }
        
        return $violations;
    }

    private function validateObserverConsistency(): array
    {
        $violations = [];
        
        // Check for observers without implementations
        foreach ($this->declaredObservers as $observer) {
            if ($observer['disabled']) {
                continue;
            }
            
            $violations = array_merge($violations, $this->validateObserverImplementation($observer));
        }
        
        // Check for events with too many observers
        $eventObserverCount = [];
        foreach ($this->declaredObservers as $observer) {
            if (!$observer['disabled']) {
                $eventObserverCount[$observer['event']] = ($eventObserverCount[$observer['event']] ?? 0) + 1;
            }
        }
        
        foreach ($eventObserverCount as $event => $count) {
            if ($count > $this->configuration['max_observers_per_event']) {
                $violations[] = new Violation(
                    'magento-observer-too-many-observers',
                    "Event '{$event}' has {$count} observers, which may impact performance",
                    '',
                    0,
                    0,
                    $this->configuration['severity_performance'],
                    Violation::TYPE_PERFORMANCE
                );
            }
        }
        
        // Check for deprecated events
        foreach ($this->declaredObservers as $observer) {
            if (in_array($observer['event'], $this->configuration['deprecated_events'])) {
                $violations[] = new Violation(
                    'magento-observer-deprecated-event',
                    "Observer '{$observer['name']}' listens to deprecated event '{$observer['event']}'",
                    $observer['file'],
                    0,
                    0,
                    Violation::SEVERITY_WARNING,
                    Violation::TYPE_LOGIC
                );
            }
        }
        
        return $violations;
    }

    private function validateObserverImplementation(array $observer): array
    {
        $violations = [];
        $className = $observer['instance'];
        
        // Try to find the class file (simplified approach)
        $classFile = $this->findClassFile($className);
        if (!$classFile) {
            $violations[] = new Violation(
                'magento-observer-missing-implementation',
                "Observer class '{$className}' not found",
                $observer['file'],
                0,
                0,
                $this->configuration['severity_missing_registration'],
                Violation::TYPE_LOGIC
            );
            return $violations;
        }
        
        return $violations;
    }

    private function findClassFile(string $className): ?string
    {
        // This is a simplified implementation
        // In a real analyzer, you'd use autoloader or file scanning
        $relativePath = str_replace('\\', '/', $className) . '.php';
        $possiblePaths = [
            'src/' . $relativePath,
            'app/code/' . $relativePath,
            'vendor/' . $relativePath,
        ];
        
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }
        
        return null;
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

class ObserverAnalysisVisitor extends NodeVisitorAbstract
{
    private string $filePath;
    private array $configuration;
    private array $declaredObservers;
    private array $violations = [];
    private array $dispatchedEvents = [];
    private string $currentClass = '';
    private bool $implementsObserverInterface = false;

    public function __construct(string $filePath, array $configuration, array $declaredObservers)
    {
        $this->filePath = $filePath;
        $this->configuration = $configuration;
        $this->declaredObservers = $declaredObservers;
    }

    public function enterNode(Node $node): void
    {
        if ($node instanceof Node\Stmt\Class_) {
            $this->analyzeClass($node);
        }
        
        if ($node instanceof Node\Stmt\ClassMethod) {
            $this->analyzeMethod($node);
        }
        
        if ($node instanceof Node\Expr\MethodCall) {
            $this->analyzeMethodCall($node);
        }
    }

    public function getViolations(): array
    {
        return $this->violations;
    }

    public function getDispatchedEvents(): array
    {
        return $this->dispatchedEvents;
    }

    private function analyzeClass(Node\Stmt\Class_ $class): void
    {
        $this->currentClass = $class->name->name ?? '';
        
        // Check if this class implements ObserverInterface
        $this->implementsObserverInterface = false;
        
        if ($class->implements) {
            foreach ($class->implements as $interface) {
                if ($interface instanceof Node\Name) {
                    $interfaceName = $interface->toString();
                    if (str_contains($interfaceName, 'ObserverInterface')) {
                        $this->implementsObserverInterface = true;
                        break;
                    }
                }
            }
        }
        
        // Check if this class is registered as an observer
        $isRegisteredObserver = $this->isRegisteredObserver($this->currentClass);
        
        if ($isRegisteredObserver && !$this->implementsObserverInterface) {
            $this->violations[] = new Violation(
                'magento-observer-missing-interface',
                "Observer class '{$this->currentClass}' should implement ObserverInterface",
                $this->filePath,
                $class->getStartLine(),
                0,
                $this->configuration['severity_interface_violation'],
                Violation::TYPE_LOGIC
            );
        }
    }

    private function analyzeMethod(Node\Stmt\ClassMethod $method): void
    {
        $methodName = $method->name->name;
        
        // Check observer execute method
        if ($methodName === 'execute' && $this->implementsObserverInterface) {
            $this->validateExecuteMethod($method);
        }
        
        // Check for heavy operations in observers
        if ($this->implementsObserverInterface) {
            $this->checkMethodPerformance($method);
        }
    }

    private function analyzeMethodCall(Node\Expr\MethodCall $node): void
    {
        if ($node->name instanceof Node\Identifier) {
            $methodName = $node->name->name;
            
            // Check for event dispatching
            if ($methodName === 'dispatch') {
                $this->analyzeEventDispatch($node);
            }
            
            // Check for deprecated event manager methods
            if (in_array($methodName, ['dispatchEvent', 'fireEvent'])) {
                $this->violations[] = new Violation(
                    'magento-observer-deprecated-dispatch-method',
                    "Method '{$methodName}' is deprecated. Use 'dispatch' instead.",
                    $this->filePath,
                    $node->getStartLine(),
                    0,
                    Violation::SEVERITY_WARNING,
                    Violation::TYPE_LOGIC
                );
            }
        }
    }

    private function validateExecuteMethod(Node\Stmt\ClassMethod $method): void
    {
        $params = $method->params;
        
        // Execute method should have one parameter of type Observer
        if (count($params) !== 1) {
            $this->violations[] = new Violation(
                'magento-observer-execute-wrong-params',
                'Observer execute method should have exactly one parameter',
                $this->filePath,
                $method->getStartLine(),
                0,
                $this->configuration['severity_interface_violation'],
                Violation::TYPE_LOGIC
            );
            return;
        }
        
        $param = $params[0];
        if ($param->type instanceof Node\Name) {
            $typeName = $param->type->toString();
            if (!str_contains($typeName, 'Observer')) {
                $this->violations[] = new Violation(
                    'magento-observer-execute-wrong-param-type',
                    'Observer execute method parameter should be of type Observer',
                    $this->filePath,
                    $method->getStartLine(),
                    0,
                    $this->configuration['severity_interface_violation'],
                    Violation::TYPE_LOGIC
                );
            }
        }
        
        // Check return type
        if ($method->returnType && 
            $method->returnType instanceof Node\Name && 
            $method->returnType->toString() !== 'void') {
            $this->violations[] = new Violation(
                'magento-observer-execute-return-type',
                'Observer execute method should return void',
                $this->filePath,
                $method->getStartLine(),
                0,
                $this->configuration['severity_interface_violation'],
                Violation::TYPE_LOGIC
            );
        }
    }

    private function checkMethodPerformance(Node\Stmt\ClassMethod $method): void
    {
        $complexity = $this->calculateMethodComplexity($method);
        
        if ($complexity > 10) {
            $this->violations[] = new Violation(
                'magento-observer-high-complexity',
                "Observer method '{$method->name->name}' has high complexity ({$complexity}). Consider refactoring.",
                $this->filePath,
                $method->getStartLine(),
                0,
                $this->configuration['severity_performance'],
                Violation::TYPE_PERFORMANCE
            );
        }
        
        // Check for database operations
        if ($this->containsDatabaseOperations($method)) {
            $this->violations[] = new Violation(
                'magento-observer-database-operations',
                "Observer method '{$method->name->name}' contains database operations. Consider async processing.",
                $this->filePath,
                $method->getStartLine(),
                0,
                $this->configuration['severity_performance'],
                Violation::TYPE_PERFORMANCE
            );
        }
        
        // Check for external API calls
        if ($this->containsExternalApiCalls($method)) {
            $this->violations[] = new Violation(
                'magento-observer-external-api-calls',
                "Observer method '{$method->name->name}' contains external API calls. Consider async processing.",
                $this->filePath,
                $method->getStartLine(),
                0,
                $this->configuration['severity_performance'],
                Violation::TYPE_PERFORMANCE
            );
        }
    }

    private function analyzeEventDispatch(Node\Expr\MethodCall $node): void
    {
        if (empty($node->args)) {
            return;
        }
        
        $eventNameArg = $node->args[0];
        if ($eventNameArg->value instanceof Node\Scalar\String_) {
            $eventName = $eventNameArg->value->value;
            
            $this->dispatchedEvents[] = [
                'name' => $eventName,
                'file' => $this->filePath,
                'line' => $node->getStartLine(),
            ];
            
            // Validate event naming
            $this->validateEventNaming($eventName, $node->getStartLine());
            
            // Check if event is performance sensitive
            if (in_array($eventName, $this->configuration['performance_sensitive_events'])) {
                $this->violations[] = new Violation(
                    'magento-observer-performance-sensitive-event',
                    "Dispatching performance-sensitive event '{$eventName}'. Ensure minimal observer logic.",
                    $this->filePath,
                    $node->getStartLine(),
                    0,
                    $this->configuration['severity_performance'],
                    Violation::TYPE_PERFORMANCE
                );
            }
        }
    }

    private function validateEventNaming(string $eventName, int $line): void
    {
        // Check event naming conventions
        if (!preg_match('/^[a-z][a-z0-9_]*[a-z0-9]$/', $eventName)) {
            $this->violations[] = new Violation(
                'magento-observer-invalid-event-name',
                "Event name '{$eventName}' doesn't follow naming conventions (lowercase with underscores)",
                $this->filePath,
                $line,
                0,
                $this->configuration['severity_naming_convention'],
                Violation::TYPE_STYLE
            );
        }
        
        // Check for required prefixes
        $hasValidPrefix = false;
        foreach ($this->configuration['required_event_prefixes'] as $prefix) {
            if (str_starts_with($eventName, $prefix)) {
                $hasValidPrefix = true;
                break;
            }
        }
        
        if (!$hasValidPrefix && !str_contains($eventName, '_custom_')) {
            $validPrefixes = implode(', ', $this->configuration['required_event_prefixes']);
            $this->violations[] = new Violation(
                'magento-observer-missing-event-prefix',
                "Event '{$eventName}' should start with a standard prefix ({$validPrefixes}) or contain '_custom_'",
                $this->filePath,
                $line,
                0,
                $this->configuration['severity_naming_convention'],
                Violation::TYPE_STYLE
            );
        }
    }

    private function isRegisteredObserver(string $className): bool
    {
        foreach ($this->declaredObservers as $observer) {
            if (str_contains($observer['instance'], $className)) {
                return true;
            }
        }
        return false;
    }

    private function calculateMethodComplexity(Node\Stmt\ClassMethod $method): int
    {
        $complexity = 1;
        
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

    private function containsDatabaseOperations(Node\Stmt\ClassMethod $method): bool
    {
        // Simplified check for database operations
        $code = $this->nodeToString($method);
        
        $dbPatterns = [
            'save()',
            'load(',
            'delete(',
            'getCollection(',
            'addFieldToFilter(',
            'getSelect(',
            'query(',
            '->save(',
            '->load(',
            '->delete(',
        ];
        
        foreach ($dbPatterns as $pattern) {
            if (str_contains($code, $pattern)) {
                return true;
            }
        }
        
        return false;
    }

    private function containsExternalApiCalls(Node\Stmt\ClassMethod $method): bool
    {
        // Simplified check for external API calls
        $code = $this->nodeToString($method);
        
        $apiPatterns = [
            'curl_exec(',
            'file_get_contents(',
            'guzzle',
            'http_build_query(',
            'json_decode(',
            'simplexml_load_string(',
            '->request(',
            '->get(',
            '->post(',
        ];
        
        foreach ($apiPatterns as $pattern) {
            if (str_contains($code, $pattern)) {
                return true;
            }
        }
        
        return false;
    }

    private function nodeToString(Node $node): string
    {
        // This is a simplified implementation
        // In practice, you'd use a proper pretty printer
        return serialize($node);
    }
} 