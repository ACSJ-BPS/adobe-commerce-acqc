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

class DIAnalyzer extends AbstractAnalyzer
{
    private Parser $parser;
    private array $objectManagerUsage = [];
    private array $constructorIssues = [];
    private array $diViolations = [];
    
    public function __construct(array $configuration = [], ?\Psr\Log\LoggerInterface $logger = null)
    {
        parent::__construct($configuration, $logger);
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    public function getName(): string
    {
        return 'magento-dependency-injection';
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
            'check_object_manager_usage' => true,
            'check_constructor_injection' => true,
            'check_preference_usage' => true,
            'check_virtual_types' => true,
            'check_factory_patterns' => true,
            'check_proxy_usage' => true,
            'check_plugin_preferences' => true,
            'check_di_xml_structure' => true,
            'allow_object_manager_in_factories' => true,
            'allow_object_manager_in_tests' => true,
            'allow_object_manager_in_legacy' => false,
            'max_constructor_parameters' => 10,
            'severity_object_manager' => Violation::SEVERITY_ERROR,
            'severity_constructor_issues' => Violation::SEVERITY_WARNING,
            'severity_di_xml_issues' => Violation::SEVERITY_ERROR,
            'severity_preference_issues' => Violation::SEVERITY_WARNING,
            'exclude_paths' => [
                '*/Test/*',
                '*/tests/*',
                '*/dev/*',
            ],
            'magento_interfaces' => [
                'Magento\\Framework\\ObjectManagerInterface',
                'Magento\\Framework\\App\\ObjectManager',
            ],
            'factory_patterns' => [
                'Factory',
                'Builder',
                'Creator',
            ],
        ];
    }

    public function analyze(array $files, array $options = []): array
    {
        $filteredFiles = $this->filterSupportedFiles($files);
        
        if (empty($filteredFiles)) {
            return [];
        }
        
        $this->logger->info('Starting Magento dependency injection analysis');
        
        $violations = [];
        
        foreach ($filteredFiles as $file) {
            if (!$this->shouldAnalyzeFile($file)) {
                continue;
            }
            
            if (str_ends_with($file, '.php')) {
                $violations = array_merge($violations, $this->analyzePhpFile($file));
            } elseif (str_ends_with($file, 'di.xml')) {
                $violations = array_merge($violations, $this->analyzeDiXml($file));
            }
        }
        
        $this->logger->info('Found ' . count($violations) . ' dependency injection violations');
        $this->lastAnalysisResults = $violations;
        
        return $violations;
    }

    protected function executeAnalysis(array $files, array $options = []): array
    {
        return [];
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
            
            $visitor = new DIAnalysisVisitor($filePath, $this->configuration);
            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);
            
            $violations = array_merge($violations, $visitor->getViolations());
            
        } catch (\Exception $e) {
            $this->logger->error("Failed to parse PHP file: {$filePath}", ['error' => $e->getMessage()]);
            
            $violations[] = new Violation(
                'magento-di-parse-error',
                "Failed to parse file for DI analysis: " . $e->getMessage(),
                $filePath,
                0,
                0,
                Violation::SEVERITY_WARNING,
                Violation::TYPE_SYNTAX
            );
        }
        
        return $violations;
    }

    private function analyzeDiXml(string $filePath): array
    {
        $violations = [];
        $content = file_get_contents($filePath);
        
        if (!$content) {
            return $violations;
        }
        
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        
        if (!@$dom->loadXML($content)) {
            $violations[] = new Violation(
                'magento-di-xml-invalid',
                'Invalid XML in di.xml file',
                $filePath,
                0,
                0,
                $this->configuration['severity_di_xml_issues'],
                Violation::TYPE_SYNTAX
            );
            return $violations;
        }
        
        $xpath = new \DOMXPath($dom);
        
        // Check for common DI XML issues
        $violations = array_merge($violations, $this->checkPreferences($xpath, $filePath));
        $violations = array_merge($violations, $this->checkVirtualTypes($xpath, $filePath));
        $violations = array_merge($violations, $this->checkTypeConfiguration($xpath, $filePath));
        $violations = array_merge($violations, $this->checkPluginConfiguration($xpath, $filePath));
        
        return $violations;
    }

    private function checkPreferences(\DOMXPath $xpath, string $filePath): array
    {
        $violations = [];
        
        // Check for duplicate preferences
        $preferences = $xpath->query('//preference');
        $preferenceMap = [];
        
        foreach ($preferences as $preference) {
            $for = $preference->getAttribute('for');
            $type = $preference->getAttribute('type');
            
            if (empty($for) || empty($type)) {
                $violations[] = new Violation(
                    'magento-di-preference-missing-attributes',
                    'Preference element must have both "for" and "type" attributes',
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_preference_issues'],
                    Violation::TYPE_LOGIC
                );
                continue;
            }
            
            if (isset($preferenceMap[$for])) {
                $violations[] = new Violation(
                    'magento-di-duplicate-preference',
                    "Duplicate preference for '{$for}'. This may cause conflicts.",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_preference_issues'],
                    Violation::TYPE_LOGIC
                );
            }
            
            $preferenceMap[$for] = $type;
            
            // Check if preference is for interface
            if (!str_contains($for, 'Interface') && !str_contains($for, 'Api\\')) {
                $violations[] = new Violation(
                    'magento-di-preference-concrete-class',
                    "Preference for concrete class '{$for}'. Consider using interfaces instead.",
                    $filePath,
                    0,
                    0,
                    Violation::SEVERITY_INFO,
                    Violation::TYPE_MAINTAINABILITY
                );
            }
        }
        
        return $violations;
    }

    private function checkVirtualTypes(\DOMXPath $xpath, string $filePath): array
    {
        $violations = [];
        
        $virtualTypes = $xpath->query('//virtualType');
        
        foreach ($virtualTypes as $virtualType) {
            $name = $virtualType->getAttribute('name');
            $type = $virtualType->getAttribute('type');
            
            if (empty($name)) {
                $violations[] = new Violation(
                    'magento-di-virtual-type-no-name',
                    'Virtual type must have a name attribute',
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_di_xml_issues'],
                    Violation::TYPE_LOGIC
                );
            }
            
            if (empty($type)) {
                $violations[] = new Violation(
                    'magento-di-virtual-type-no-type',
                    'Virtual type must have a type attribute',
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_di_xml_issues'],
                    Violation::TYPE_LOGIC
                );
            }
            
            // Check virtual type naming
            if (!empty($name) && !preg_match('/^[A-Z][a-zA-Z0-9\\\\]*$/', $name)) {
                $violations[] = new Violation(
                    'magento-di-virtual-type-naming',
                    "Virtual type name '{$name}' should follow class naming conventions",
                    $filePath,
                    0,
                    0,
                    Violation::SEVERITY_WARNING,
                    Violation::TYPE_STYLE
                );
            }
        }
        
        return $violations;
    }

    private function checkTypeConfiguration(\DOMXPath $xpath, string $filePath): array
    {
        $violations = [];
        
        $types = $xpath->query('//type');
        
        foreach ($types as $type) {
            $name = $type->getAttribute('name');
            
            if (empty($name)) {
                $violations[] = new Violation(
                    'magento-di-type-no-name',
                    'Type configuration must have a name attribute',
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_di_xml_issues'],
                    Violation::TYPE_LOGIC
                );
                continue;
            }
            
            // Check for excessive constructor arguments
            $arguments = $xpath->query('.//argument', $type);
            if ($arguments->length > $this->configuration['max_constructor_parameters']) {
                $violations[] = new Violation(
                    'magento-di-too-many-arguments',
                    "Type '{$name}' has {$arguments->length} arguments, which exceeds the recommended maximum of {$this->configuration['max_constructor_parameters']}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_constructor_issues'],
                    Violation::TYPE_MAINTAINABILITY
                );
            }
            
            // Check argument configuration
            foreach ($arguments as $argument) {
                $argName = $argument->getAttribute('name');
                $argType = $argument->getAttribute('xsi:type');
                
                if (empty($argName)) {
                    $violations[] = new Violation(
                        'magento-di-argument-no-name',
                        "Argument in type '{$name}' must have a name attribute",
                        $filePath,
                        0,
                        0,
                        $this->configuration['severity_di_xml_issues'],
                        Violation::TYPE_LOGIC
                    );
                }
                
                // Check for array arguments without proper type
                if ($argType === 'array') {
                    $items = $xpath->query('.//item', $argument);
                    if ($items->length === 0) {
                        $violations[] = new Violation(
                            'magento-di-empty-array-argument',
                            "Empty array argument '{$argName}' in type '{$name}'. Consider removing if not needed.",
                            $filePath,
                            0,
                            0,
                            Violation::SEVERITY_INFO,
                            Violation::TYPE_MAINTAINABILITY
                        );
                    }
                }
            }
        }
        
        return $violations;
    }

    private function checkPluginConfiguration(\DOMXPath $xpath, string $filePath): array
    {
        $violations = [];
        
        $plugins = $xpath->query('//plugin');
        
        foreach ($plugins as $plugin) {
            $name = $plugin->getAttribute('name');
            $type = $plugin->getAttribute('type');
            $sortOrder = $plugin->getAttribute('sortOrder');
            $disabled = $plugin->getAttribute('disabled');
            
            if (empty($name)) {
                $violations[] = new Violation(
                    'magento-di-plugin-no-name',
                    'Plugin must have a name attribute',
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_di_xml_issues'],
                    Violation::TYPE_LOGIC
                );
            }
            
            if (empty($type)) {
                $violations[] = new Violation(
                    'magento-di-plugin-no-type',
                    'Plugin must have a type attribute',
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_di_xml_issues'],
                    Violation::TYPE_LOGIC
                );
            }
            
            // Check sort order is numeric
            if (!empty($sortOrder) && !is_numeric($sortOrder)) {
                $violations[] = new Violation(
                    'magento-di-plugin-invalid-sort-order',
                    "Plugin sortOrder must be numeric, got '{$sortOrder}'",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_di_xml_issues'],
                    Violation::TYPE_LOGIC
                );
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

class DIAnalysisVisitor extends NodeVisitorAbstract
{
    private string $filePath;
    private array $configuration;
    private array $violations = [];
    private array $constructorParams = [];
    private string $currentClass = '';
    private bool $isFactory = false;
    private bool $isTest = false;

    public function __construct(string $filePath, array $configuration)
    {
        $this->filePath = $filePath;
        $this->configuration = $configuration;
        $this->isFactory = $this->isFactoryFile($filePath);
        $this->isTest = $this->isTestFile($filePath);
    }

    public function enterNode(Node $node): void
    {
        if ($node instanceof Node\Stmt\Class_) {
            $this->currentClass = $node->name->name ?? '';
        }
        
        if ($node instanceof Node\Stmt\ClassMethod && $node->name->name === '__construct') {
            $this->analyzeConstructor($node);
        }
        
        if ($node instanceof Node\Expr\StaticCall) {
            $this->analyzeStaticCall($node);
        }
        
        if ($node instanceof Node\Expr\MethodCall) {
            $this->analyzeMethodCall($node);
        }
        
        if ($node instanceof Node\Expr\New_) {
            $this->analyzeNewInstance($node);
        }
    }

    public function getViolations(): array
    {
        return $this->violations;
    }

    private function analyzeConstructor(Node\Stmt\ClassMethod $constructor): void
    {
        $paramCount = count($constructor->params);
        
        if ($paramCount > $this->configuration['max_constructor_parameters']) {
            $this->violations[] = new Violation(
                'magento-di-too-many-constructor-params',
                "Constructor has {$paramCount} parameters, exceeds recommended maximum of {$this->configuration['max_constructor_parameters']}",
                $this->filePath,
                $constructor->getStartLine(),
                0,
                $this->configuration['severity_constructor_issues'],
                Violation::TYPE_MAINTAINABILITY
            );
        }
        
        // Check parameter types
        foreach ($constructor->params as $param) {
            if ($param->type instanceof Node\Name) {
                $typeName = $param->type->toString();
                
                // Check for concrete class injection (should prefer interfaces)
                if ($this->isConcreteClass($typeName) && !$this->isAcceptableConcreteClass($typeName)) {
                    $this->violations[] = new Violation(
                        'magento-di-concrete-class-injection',
                        "Injecting concrete class '{$typeName}'. Consider using interface instead.",
                        $this->filePath,
                        $param->getStartLine(),
                        0,
                        Violation::SEVERITY_INFO,
                        Violation::TYPE_MAINTAINABILITY
                    );
                }
                
                // Check for ObjectManager injection
                if (in_array($typeName, $this->configuration['magento_interfaces'])) {
                    if (!$this->isObjectManagerAllowed()) {
                        $this->violations[] = new Violation(
                            'magento-di-object-manager-injection',
                            "ObjectManager injection detected. Use dependency injection instead.",
                            $this->filePath,
                            $param->getStartLine(),
                            0,
                            $this->configuration['severity_object_manager'],
                            Violation::TYPE_LOGIC
                        );
                    }
                }
            }
        }
    }

    private function analyzeStaticCall(Node\Expr\StaticCall $node): void
    {
        if ($node->class instanceof Node\Name) {
            $className = $node->class->toString();
            $methodName = $node->name instanceof Node\Identifier ? $node->name->name : '';
            
            // Check for ObjectManager::getInstance()
            if ($className === 'ObjectManager' || str_contains($className, 'ObjectManager')) {
                if ($methodName === 'getInstance' && !$this->isObjectManagerAllowed()) {
                    $this->violations[] = new Violation(
                        'magento-di-object-manager-usage',
                        'ObjectManager::getInstance() detected. Use dependency injection instead.',
                        $this->filePath,
                        $node->getStartLine(),
                        0,
                        $this->configuration['severity_object_manager'],
                        Violation::TYPE_LOGIC
                    );
                }
            }
            
            // Check for other service locator patterns
            if (str_contains($className, 'Registry') && $methodName === 'getInstance') {
                $this->violations[] = new Violation(
                    'magento-di-registry-usage',
                    'Registry pattern detected. Use dependency injection instead.',
                    $this->filePath,
                    $node->getStartLine(),
                    0,
                    Violation::SEVERITY_WARNING,
                    Violation::TYPE_LOGIC
                );
            }
        }
    }

    private function analyzeMethodCall(Node\Expr\MethodCall $node): void
    {
        if ($node->name instanceof Node\Identifier) {
            $methodName = $node->name->name;
            
            // Check for get() calls on ObjectManager-like objects
            if ($methodName === 'get' || $methodName === 'create') {
                if ($node->var instanceof Node\Expr\Variable && $node->var->name === 'objectManager') {
                    if (!$this->isObjectManagerAllowed()) {
                        $this->violations[] = new Violation(
                            'magento-di-object-manager-get',
                            "ObjectManager->{$methodName}() detected. Use dependency injection instead.",
                            $this->filePath,
                            $node->getStartLine(),
                            0,
                            $this->configuration['severity_object_manager'],
                            Violation::TYPE_LOGIC
                        );
                    }
                }
            }
        }
    }

    private function analyzeNewInstance(Node\Expr\New_ $node): void
    {
        if ($node->class instanceof Node\Name) {
            $className = $node->class->toString();
            
            // Check for direct instantiation of Magento classes
            if (str_starts_with($className, 'Magento\\') && !$this->isAcceptableDirectInstantiation($className)) {
                $this->violations[] = new Violation(
                    'magento-di-direct-instantiation',
                    "Direct instantiation of '{$className}' detected. Use dependency injection or factories instead.",
                    $this->filePath,
                    $node->getStartLine(),
                    0,
                    Violation::SEVERITY_WARNING,
                    Violation::TYPE_LOGIC
                );
            }
        }
    }

    private function isFactoryFile(string $filePath): bool
    {
        foreach ($this->configuration['factory_patterns'] as $pattern) {
            if (str_contains($filePath, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function isTestFile(string $filePath): bool
    {
        return str_contains($filePath, '/Test/') || str_contains($filePath, '/tests/');
    }

    private function isObjectManagerAllowed(): bool
    {
        if ($this->isTest && $this->configuration['allow_object_manager_in_tests']) {
            return true;
        }
        
        if ($this->isFactory && $this->configuration['allow_object_manager_in_factories']) {
            return true;
        }
        
        return $this->configuration['allow_object_manager_in_legacy'];
    }

    private function isConcreteClass(string $typeName): bool
    {
        return !str_contains($typeName, 'Interface') && 
               !str_contains($typeName, 'Api\\') &&
               !str_starts_with($typeName, 'array') &&
               !in_array($typeName, ['string', 'int', 'bool', 'float']);
    }

    private function isAcceptableConcreteClass(string $typeName): bool
    {
        $acceptableClasses = [
            'DateTime',
            'DateTimeInterface',
            'Exception',
            'Psr\\Log\\LoggerInterface',
        ];
        
        return in_array($typeName, $acceptableClasses) || 
               str_ends_with($typeName, 'Factory') ||
               str_ends_with($typeName, 'Collection') ||
               str_contains($typeName, 'ResourceModel');
    }

    private function isAcceptableDirectInstantiation(string $className): bool
    {
        $acceptableClasses = [
            'Magento\\Framework\\DataObject',
            'Magento\\Framework\\Phrase',
            'Magento\\Framework\\Exception\\',
        ];
        
        foreach ($acceptableClasses as $acceptable) {
            if (str_starts_with($className, $acceptable)) {
                return true;
            }
        }
        
        return false;
    }
} 