<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Magento;

use ACQC\Analyzers\AbstractAnalyzer;
use ACQC\Analyzers\AnalyzerException;
use ACQC\Core\Rules\Violation;
use ACQC\Core\Upgrade\ApiIndexBridgeInterface;
use ACQC\Core\Upgrade\MrayApiIndexBridge;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

class UpgradeCompatibilityAnalyzer extends AbstractAnalyzer
{
    private Parser $parser;
    private array $deprecatedApis = [];
    private array $removedApis = [];
    private array $changedApis = [];
    private ApiIndexBridgeInterface $bridge;

    public function __construct(
        array $configuration = [],
        ?\Psr\Log\LoggerInterface $logger = null,
        ?ApiIndexBridgeInterface $bridge = null
    ) {
        parent::__construct($configuration, $logger);
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->initializeDeprecatedApis();

        // Build the bridge: uses mray phar index when available, YAML data otherwise
        $registryPath = dirname(dirname(dirname(__DIR__))) . '/config/versions';
        $this->bridge = $bridge ?? new MrayApiIndexBridge($registryPath);
    }

    public function getName(): string
    {
        return 'magento-upgrade-compatibility';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function isAvailable(): bool
    {
        // PhpParser\Parser is an interface in v5 — check the factory class instead
        return class_exists('PhpParser\ParserFactory');
    }

    protected function getDefaultConfiguration(): array
    {
        return [
            'target_magento_version' => '2.4.6',
            'check_deprecated_apis' => true,
            'check_removed_apis' => true,
            'check_changed_apis' => true,
            'check_version_constraints' => true,
            'check_composer_compatibility' => true,
            'check_php_compatibility' => true,
            'check_database_schema_changes' => true,
            'severity_removed_api' => Violation::SEVERITY_CRITICAL,
            'severity_deprecated_api' => Violation::SEVERITY_WARNING,
            'severity_changed_api' => Violation::SEVERITY_ERROR,
            'severity_version_constraint' => Violation::SEVERITY_WARNING,
            'minimum_php_version' => '8.1',
            'maximum_php_version' => '8.3',
            'deprecated_modules' => [],
            'removed_classes' => [],
            'deprecated_methods' => [
                'Magento\\Framework\\Registry::register',
                'Magento\\Framework\\Registry::unregister',
                'Magento\\Framework\\Registry::registry',
            ],
            'changed_interfaces' => [],
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
        if ($this->bridge->isAvailable()) {
            $this->logger->info('Starting Magento upgrade compatibility analysis (mray index: full UCT-level coverage)');
        } else {
            $this->logger->info('Starting Magento upgrade compatibility analysis (mray index: not installed — using YAML fallback; install magento/magento-api-index + magento/mray for full coverage)');
        }
        
        $violations = [];
        
        // Check composer.json for version constraints
        foreach ($files as $file) {
            if (basename($file) === 'composer.json') {
                $violations = array_merge($violations, $this->analyzeComposerFile($file));
            }
        }
        
        // Check PHP files for deprecated/removed APIs
        foreach ($files as $file) {
            if (str_ends_with($file, '.php') && $this->shouldAnalyzeFile($file)) {
                $violations = array_merge($violations, $this->analyzePhpFile($file));
            }
        }
        
        // Check XML configuration files
        foreach ($files as $file) {
            if (str_ends_with($file, '.xml') && $this->shouldAnalyzeFile($file)) {
                $violations = array_merge($violations, $this->analyzeXmlFile($file));
            }
        }
        
        $this->logger->info('Found ' . count($violations) . ' upgrade compatibility violations');
        
        return $violations;
    }

    protected function parseOutput(string $output, array $files): array
    {
        return [];
    }

    private function initializeDeprecatedApis(): void
    {
        // Initialize with common deprecated APIs across Magento versions
        $this->deprecatedApis = [
            'Magento\\Framework\\Registry' => '2.3.0',
            'Magento\\Catalog\\Model\\Product\\Type\\AbstractType::getChildrenIds' => '2.2.0',
        ];
        
        $this->removedApis = [];
        
        $this->changedApis = [
            // APIs that changed signatures or behavior
            'Magento\\Framework\\Data\\Collection\\AbstractDb::__construct' => [
                'version' => '2.3.0',
                'change' => 'Constructor signature changed',
            ],
        ];
    }

    private function analyzeComposerFile(string $filePath): array
    {
        $violations = [];
        $content = file_get_contents($filePath);
        
        if (!$content) {
            return $violations;
        }
        
        $composer = json_decode($content, true);
        if (!$composer) {
            return $violations;
        }
        
        // Check PHP version constraint
        if (isset($composer['require']['php'])) {
            $phpConstraint = $composer['require']['php'];
            $violations = array_merge($violations, $this->checkPhpVersionConstraint($phpConstraint, $filePath));
        }
        
        // Check Magento version constraints
        foreach ($composer['require'] ?? [] as $package => $constraint) {
            if (str_starts_with($package, 'magento/')) {
                $violations = array_merge($violations, $this->checkMagentoVersionConstraint($package, $constraint, $filePath));
            }
        }
        
        // Check for deprecated packages
        $deprecatedPackages = [
            'magento/module-weee',
            'magento/module-msrp',
            'zendframework/zend-*',
        ];
        
        foreach ($composer['require'] ?? [] as $package => $constraint) {
            foreach ($deprecatedPackages as $deprecated) {
                if (fnmatch($deprecated, $package)) {
                    $violations[] = new Violation(
                        'magento-upgrade-deprecated-package',
                        "Package '{$package}' is deprecated and may be removed in future versions",
                        $filePath,
                        0,
                        0,
                        $this->configuration['severity_deprecated_api'],
                        Violation::TYPE_LOGIC
                    );
                }
            }
        }
        
        return $violations;
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
            
            $visitor = new UpgradeCompatibilityVisitor($filePath, $this->configuration, $this->deprecatedApis, $this->removedApis, $this->changedApis, $this->bridge);
            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);
            
            $violations = array_merge($violations, $visitor->getViolations());
            
        } catch (\Exception $e) {
            $this->logger->error("Failed to parse file: {$filePath}", ['error' => $e->getMessage()]);
        }
        
        return $violations;
    }

    private function analyzeXmlFile(string $filePath): array
    {
        $violations = [];
        $content = file_get_contents($filePath);
        
        if (!$content) {
            return $violations;
        }
        
        // Check for deprecated XML configurations
        if (str_contains($filePath, 'config.xml')) {
            $violations = array_merge($violations, $this->checkDeprecatedConfigXml($content, $filePath));
        }
        
        if (str_contains($filePath, 'system.xml')) {
            $violations = array_merge($violations, $this->checkDeprecatedSystemXml($content, $filePath));
        }
        
        if (str_contains($filePath, 'layout')) {
            $violations = array_merge($violations, $this->checkDeprecatedLayoutXml($content, $filePath));
        }
        
        return $violations;
    }

    private function checkPhpVersionConstraint(string $constraint, string $filePath): array
    {
        $violations = [];
        
        // Parse version constraint
        if (preg_match('/(\d+\.\d+)/', $constraint, $matches)) {
            $requiredVersion = $matches[1];
            
            if (version_compare($requiredVersion, $this->configuration['minimum_php_version'], '<')) {
                $violations[] = new Violation(
                    'magento-upgrade-php-version-too-low',
                    "PHP version constraint '{$constraint}' is below minimum required version {$this->configuration['minimum_php_version']}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_version_constraint'],
                    Violation::TYPE_LOGIC
                );
            }
            
            if (version_compare($requiredVersion, $this->configuration['maximum_php_version'], '>')) {
                $violations[] = new Violation(
                    'magento-upgrade-php-version-too-high',
                    "PHP version constraint '{$constraint}' exceeds maximum supported version {$this->configuration['maximum_php_version']}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_version_constraint'],
                    Violation::TYPE_LOGIC
                );
            }
        }
        
        return $violations;
    }

    private function checkMagentoVersionConstraint(string $package, string $constraint, string $filePath): array
    {
        $violations = [];
        
        // Check for overly restrictive version constraints
        if (str_contains($constraint, '=')) {
            $violations[] = new Violation(
                'magento-upgrade-restrictive-version',
                "Package '{$package}' uses restrictive version constraint '{$constraint}'. Consider using more flexible constraints.",
                $filePath,
                0,
                0,
                $this->configuration['severity_version_constraint'],
                Violation::TYPE_MAINTAINABILITY
            );
        }
        
        return $violations;
    }

    private function checkDeprecatedConfigXml(string $content, string $filePath): array
    {
        $violations = [];
        
        $deprecatedElements = [
            'global/models',
            'global/blocks',
            'global/helpers',
            'adminhtml/events',
            'frontend/events',
        ];
        
        foreach ($deprecatedElements as $element) {
            if (str_contains($content, $element)) {
                $violations[] = new Violation(
                    'magento-upgrade-deprecated-config-xml',
                    "Deprecated XML element '{$element}' found in config.xml",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_deprecated_api'],
                    Violation::TYPE_LOGIC
                );
            }
        }
        
        return $violations;
    }

    private function checkDeprecatedSystemXml(string $content, string $filePath): array
    {
        $violations = [];
        
        // Check for deprecated field types
        $deprecatedTypes = ['obscure', 'image', 'file'];
        
        foreach ($deprecatedTypes as $type) {
            if (preg_match('/<field[^>]+type="' . $type . '"/', $content)) {
                $violations[] = new Violation(
                    'magento-upgrade-deprecated-field-type',
                    "Deprecated field type '{$type}' found in system.xml",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_deprecated_api'],
                    Violation::TYPE_LOGIC
                );
            }
        }
        
        return $violations;
    }

    private function checkDeprecatedLayoutXml(string $content, string $filePath): array
    {
        $violations = [];
        
        // Check for deprecated layout instructions
        $deprecatedInstructions = [
            'action method',
            'reference name="head"',
            'block type="core/template"',
        ];
        
        foreach ($deprecatedInstructions as $instruction) {
            if (str_contains($content, $instruction)) {
                $violations[] = new Violation(
                    'magento-upgrade-deprecated-layout-instruction',
                    "Deprecated layout instruction '{$instruction}' found",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_deprecated_api'],
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

class UpgradeCompatibilityVisitor extends NodeVisitorAbstract
{
    private string $filePath;
    private array $configuration;
    private array $deprecatedApis;
    private array $removedApis;
    private array $changedApis;
    private array $violations = [];
    private array $usedClasses = [];
    private ?ApiIndexBridgeInterface $bridge;

    public function __construct(
        string $filePath,
        array $configuration,
        array $deprecatedApis,
        array $removedApis,
        array $changedApis,
        ?ApiIndexBridgeInterface $bridge = null
    ) {
        $this->filePath       = $filePath;
        $this->configuration  = $configuration;
        $this->deprecatedApis = $deprecatedApis;
        $this->removedApis    = $removedApis;
        $this->changedApis    = $changedApis;
        $this->bridge         = $bridge;
    }

    private function targetVersion(): string
    {
        return (string)($this->configuration['target_magento_version'] ?? '2.4.8');
    }

    /**
     * Query the bridge for a class FQSEN and emit violations if removed or deprecated.
     * Runs with both mray (full index) and YAML fallback (hand-curated data).
     */
    private function checkFqsenViaBridge(string $fqsen, int $line): void
    {
        if ($this->bridge === null) {
            return;
        }

        // Skip FQSENs already caught by the hardcoded arrays
        if (isset($this->deprecatedApis[$fqsen]) || isset($this->removedApis[$fqsen])) {
            return;
        }

        $target = $this->targetVersion();

        if (!$this->bridge->isPresent($fqsen, $target)) {
            $this->violations[] = new Violation(
                'magento-upgrade-removed-api',
                "'{$fqsen}' does not exist in Adobe Commerce {$target} — it was removed or renamed.",
                $this->filePath,
                $line,
                0,
                $this->configuration['severity_removed_api'] ?? Violation::SEVERITY_CRITICAL,
                Violation::TYPE_LOGIC
            );
            return;
        }

        if ($this->bridge->isDeprecated($fqsen, $target)) {
            $info        = $this->bridge->getDeprecationInfo($fqsen, $target);
            $since       = $info['since'] ?? '';
            $see         = $info['see'] ?? ($info['comment'] ?? '');
            $sinceNote   = $since ? " (since {$since})" : '';
            $seeNote     = $see  ? " — see {$see}" : '';

            $this->violations[] = new Violation(
                'magento-upgrade-deprecated-api',
                "'{$fqsen}' is deprecated in Adobe Commerce {$target}{$sinceNote}{$seeNote}.",
                $this->filePath,
                $line,
                0,
                $this->configuration['severity_deprecated_api'] ?? Violation::SEVERITY_WARNING,
                Violation::TYPE_LOGIC
            );
            return;
        }

        $isApi = $this->bridge->isPublicApi($fqsen, $target);
        if ($isApi === false) {
            $this->violations[] = new Violation(
                'magento-upgrade-non-api-usage',
                "'{$fqsen}' is not marked @api in Adobe Commerce {$target} — it may change without notice.",
                $this->filePath,
                $line,
                0,
                Violation::SEVERITY_WARNING,
                Violation::TYPE_LOGIC
            );
        }
    }

    public function enterNode(Node $node): void
    {
        if ($node instanceof Node\Stmt\Use_) {
            $this->analyzeUseStatement($node);
        }
        
        if ($node instanceof Node\Expr\New_) {
            $this->analyzeNewInstance($node);
        }
        
        if ($node instanceof Node\Expr\StaticCall) {
            $this->analyzeStaticCall($node);
        }
        
        if ($node instanceof Node\Expr\MethodCall) {
            $this->analyzeMethodCall($node);
        }
        
        if ($node instanceof Node\Stmt\Class_) {
            $this->analyzeClass($node);
        }
        
        if ($node instanceof Node\Stmt\Interface_) {
            $this->analyzeInterface($node);
        }
    }

    public function getViolations(): array
    {
        return $this->violations;
    }

    private function analyzeUseStatement(Node\Stmt\Use_ $node): void
    {
        foreach ($node->uses as $use) {
            $className = $use->name->toString();
            $this->usedClasses[] = $className;

            // Live bridge query (mray phar index when available)
            if (str_starts_with($className, 'Magento\\')) {
                $this->checkFqsenViaBridge($className, $node->getStartLine());
            }

            // Check if class is deprecated or removed
            if (isset($this->deprecatedApis[$className])) {
                $this->violations[] = new Violation(
                    'magento-upgrade-deprecated-class-usage',
                    "Deprecated class '{$className}' used. Deprecated since version {$this->deprecatedApis[$className]}",
                    $this->filePath,
                    $node->getStartLine(),
                    0,
                    $this->configuration['severity_deprecated_api'],
                    Violation::TYPE_LOGIC
                );
            }
            
            if (isset($this->removedApis[$className])) {
                $this->violations[] = new Violation(
                    'magento-upgrade-removed-class-usage',
                    "Removed class '{$className}' used. Removed in version {$this->removedApis[$className]}",
                    $this->filePath,
                    $node->getStartLine(),
                    0,
                    $this->configuration['severity_removed_api'],
                    Violation::TYPE_LOGIC
                );
            }
            
            // Check for deprecated modules
            foreach ($this->configuration['deprecated_modules'] as $deprecatedModule) {
                if (str_starts_with($className, $deprecatedModule)) {
                    $this->violations[] = new Violation(
                        'magento-upgrade-deprecated-module-usage',
                        "Class '{$className}' belongs to deprecated module '{$deprecatedModule}'",
                        $this->filePath,
                        $node->getStartLine(),
                        0,
                        $this->configuration['severity_deprecated_api'],
                        Violation::TYPE_LOGIC
                    );
                }
            }
        }
    }

    private function analyzeNewInstance(Node\Expr\New_ $node): void
    {
        if ($node->class instanceof Node\Name) {
            $className = $node->class->toString();

            if (str_starts_with($className, 'Magento\\')) {
                $this->checkFqsenViaBridge($className, $node->getStartLine());
            }

            // Check for deprecated/removed classes
            if (isset($this->deprecatedApis[$className])) {
                $this->violations[] = new Violation(
                    'magento-upgrade-deprecated-class-instantiation',
                    "Instantiating deprecated class '{$className}'",
                    $this->filePath,
                    $node->getStartLine(),
                    0,
                    $this->configuration['severity_deprecated_api'],
                    Violation::TYPE_LOGIC
                );
            }
            
            if (isset($this->removedApis[$className])) {
                $this->violations[] = new Violation(
                    'magento-upgrade-removed-class-instantiation',
                    "Instantiating removed class '{$className}'",
                    $this->filePath,
                    $node->getStartLine(),
                    0,
                    $this->configuration['severity_removed_api'],
                    Violation::TYPE_LOGIC
                );
            }
        }
    }

    private function analyzeStaticCall(Node\Expr\StaticCall $node): void
    {
        if ($node->class instanceof Node\Name && $node->name instanceof Node\Identifier) {
            $className  = $node->class->toString();
            $methodName = $node->name->name;
            $fullMethod = $className . '::' . $methodName;

            if (str_starts_with($className, 'Magento\\')) {
                $this->checkFqsenViaBridge($fullMethod, $node->getStartLine());
            }

            // Check for deprecated/removed static methods
            if (isset($this->deprecatedApis[$fullMethod])) {
                $this->violations[] = new Violation(
                    'magento-upgrade-deprecated-static-method',
                    "Deprecated static method '{$fullMethod}' called",
                    $this->filePath,
                    $node->getStartLine(),
                    0,
                    $this->configuration['severity_deprecated_api'],
                    Violation::TYPE_LOGIC
                );
            }
            
            if (isset($this->removedApis[$fullMethod])) {
                $this->violations[] = new Violation(
                    'magento-upgrade-removed-static-method',
                    "Removed static method '{$fullMethod}' called",
                    $this->filePath,
                    $node->getStartLine(),
                    0,
                    $this->configuration['severity_removed_api'],
                    Violation::TYPE_LOGIC
                );
            }
            
            // Check for specific deprecated patterns
            if ($className === 'ObjectManager' && $methodName === 'getInstance') {
                $this->violations[] = new Violation(
                    'magento-upgrade-objectmanager-usage',
                    'ObjectManager::getInstance() is deprecated. Use dependency injection instead.',
                    $this->filePath,
                    $node->getStartLine(),
                    0,
                    $this->configuration['severity_deprecated_api'],
                    Violation::TYPE_LOGIC
                );
            }
        }
    }

    private function analyzeMethodCall(Node\Expr\MethodCall $node): void
    {
        if ($node->name instanceof Node\Identifier) {
            $methodName = $node->name->name;
            
            // Check for deprecated methods in current scope
            foreach ($this->configuration['deprecated_methods'] as $deprecatedMethod) {
                if (str_ends_with($deprecatedMethod, '::' . $methodName)) {
                    $this->violations[] = new Violation(
                        'magento-upgrade-deprecated-method-call',
                        "Deprecated method '{$methodName}' called",
                        $this->filePath,
                        $node->getStartLine(),
                        0,
                        $this->configuration['severity_deprecated_api'],
                        Violation::TYPE_LOGIC
                    );
                }
            }
            
            // Check for common deprecated method patterns
            $deprecatedMethods = [
                'getChildrenIds' => 'Use proper collection methods',
            ];
            
            if (isset($deprecatedMethods[$methodName])) {
                $this->violations[] = new Violation(
                    'magento-upgrade-deprecated-method-pattern',
                    "Deprecated method '{$methodName}' called. {$deprecatedMethods[$methodName]}",
                    $this->filePath,
                    $node->getStartLine(),
                    0,
                    $this->configuration['severity_deprecated_api'],
                    Violation::TYPE_LOGIC
                );
            }
        }
    }

    private function analyzeClass(Node\Stmt\Class_ $node): void
    {
        if ($node->extends instanceof Node\Name) {
            $parentClass = $node->extends->toString();
            
            // Check if extending deprecated/removed class
            if (isset($this->deprecatedApis[$parentClass])) {
                $this->violations[] = new Violation(
                    'magento-upgrade-deprecated-parent-class',
                    "Extending deprecated class '{$parentClass}'",
                    $this->filePath,
                    $node->getStartLine(),
                    0,
                    $this->configuration['severity_deprecated_api'],
                    Violation::TYPE_LOGIC
                );
            }
            
            if (isset($this->removedApis[$parentClass])) {
                $this->violations[] = new Violation(
                    'magento-upgrade-removed-parent-class',
                    "Extending removed class '{$parentClass}'",
                    $this->filePath,
                    $node->getStartLine(),
                    0,
                    $this->configuration['severity_removed_api'],
                    Violation::TYPE_LOGIC
                );
            }
        }
        
        // Check implemented interfaces
        if ($node->implements) {
            foreach ($node->implements as $interface) {
                $interfaceName = $interface->toString();
                
                if (in_array($interfaceName, $this->configuration['changed_interfaces'])) {
                    $this->violations[] = new Violation(
                        'magento-upgrade-changed-interface',
                        "Interface '{$interfaceName}' has changed in newer versions. Check compatibility.",
                        $this->filePath,
                        $node->getStartLine(),
                        0,
                        $this->configuration['severity_changed_api'],
                        Violation::TYPE_LOGIC
                    );
                }
            }
        }
    }

    private function analyzeInterface(Node\Stmt\Interface_ $node): void
    {
        if ($node->extends) {
            foreach ($node->extends as $interface) {
                $interfaceName = $interface->toString();
                
                if (in_array($interfaceName, $this->configuration['changed_interfaces'])) {
                    $this->violations[] = new Violation(
                        'magento-upgrade-extending-changed-interface',
                        "Extending changed interface '{$interfaceName}'. Check for breaking changes.",
                        $this->filePath,
                        $node->getStartLine(),
                        0,
                        $this->configuration['severity_changed_api'],
                        Violation::TYPE_LOGIC
                    );
                }
            }
        }
    }
} 