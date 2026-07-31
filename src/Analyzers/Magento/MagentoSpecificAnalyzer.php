<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Magento;

use ACQC\Analyzers\AbstractAnalyzer;
use ACQC\Analyzers\AnalyzerInterface;
use ACQC\Core\Rules\Violation;
use ACQC\Rules\Magento\ArchitectureRules;
use ACQC\Rules\Magento\ModuleStructureRules;
use ACQC\Rules\Magento\DatabaseRules;
use ACQC\Rules\Magento\CodingStandardsRules;
use ACQC\Rules\Magento\ApiServiceRules;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * Comprehensive Magento 2 Specific Analyzer
 * 
 * Analyzes Magento 2 code for:
 * - Architecture & Framework violations
 * - Module Structure & Registration issues
 * - Database & Schema problems
 * - Coding Standards violations
 * - API & Service Contract issues
 */
class MagentoSpecificAnalyzer extends AbstractAnalyzer implements AnalyzerInterface
{
    private const ANALYZER_ID = 'magento-specific';
    private const ANALYZER_NAME = 'Magento 2 Specific Rules';
    private const ANALYZER_VERSION = '2.0.0';

    private NodeFinder $nodeFinder;
    private ParserFactory $parserFactory;

    public function __construct()
    {
        parent::__construct();
        $this->nodeFinder = new NodeFinder();
        $this->parserFactory = new ParserFactory();
    }

    public function getId(): string
    {
        return self::ANALYZER_ID;
    }

    public function getName(): string
    {
        return self::ANALYZER_NAME;
    }

    public function getVersion(): string
    {
        return self::ANALYZER_VERSION;
    }

    public function getDescription(): string
    {
        return 'Comprehensive analysis of Magento 2 specific patterns, architecture, and best practices';
    }

    public function getSupportedExtensions(): array
    {
        return ['php', 'xml'];
    }

    public function analyze(array $files, array $options = []): array
    {
        $violations = [];

        foreach ($files as $filePath) {
            try {
                $fileViolations = $this->analyzeFile($filePath, $options);
                $violations = array_merge($violations, $fileViolations);

            } catch (\Exception $e) {
                $this->logger->error("Error analyzing file {$filePath}: " . $e->getMessage());
            }
        }

        return $violations;
    }

    public function analyzeFile(string $filePath, array $options = []): array
    {
        if (!file_exists($filePath)) {
            $this->logger->warning("File not found: {$filePath}");
            return [];
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            $this->logger->warning("Could not read file: {$filePath}");
            return [];
        }

        return $this->analyzeFileContent($filePath, $content);
    }

    private function analyzeFileContent(string $filePath, string $content): array
    {
        $violations = [];
        
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        
        switch ($extension) {
            case 'php':
                $violations = $this->analyzePhpFile($filePath, $content);
                break;
            case 'xml':
                $violations = $this->analyzeXmlFile($filePath, $content);
                break;
            case 'phtml':
                $violations = $this->analyzeTemplateFile($filePath, $content);
                break;
            case 'js':
            case 'jsx':
            case 'ts':
            case 'tsx':
                $violations = $this->analyzeFrontendFile($filePath, $content);
                break;
            case 'css':
            case 'less':
                $violations = $this->analyzeStyleFile($filePath, $content);
                break;
        }
        
        return $violations;
    }

    private function analyzePhpFile(string $filePath, string $content, array $options = []): array
    {
        $violations = [];
        
        try {
            $parser = (new ParserFactory())->createForNewestSupportedVersion();
            $ast = $parser->parse($content);
            
            if (!$ast) {
                return $violations;
            }

            $nodeFinder = new NodeFinder();

            // Architecture Rules
            $violations = array_merge($violations, $this->applyArchitectureRules($ast, $filePath));
            
            // Module Structure Rules
            $violations = array_merge($violations, $this->applyModuleStructureRules($ast, $filePath, $content));
            
            // Database Rules
            $violations = array_merge($violations, $this->applyDatabaseRules($ast, $filePath));
            
            // Coding Standards Rules
            $violations = array_merge($violations, $this->applyCodingStandardsRules($ast, $filePath));
            
            // API Service Rules
            $violations = array_merge($violations, $this->applyApiServiceRules($ast, $filePath));
            
            // Frontend Rules - TEMPORARILY DISABLED (missing helper classes)
            // $violations = array_merge($violations, $this->applyFrontendRules($ast, $filePath));
            
            // Configuration Rules - TEMPORARILY DISABLED (missing helper classes)
            // $violations = array_merge($violations, $this->applyConfigurationRules($ast, $filePath, $content));
            
            // Security Rules - TEMPORARILY DISABLED (missing helper classes)
            // $violations = array_merge($violations, $this->applySecurityRules($ast, $filePath));
            
            // Performance Rules - TEMPORARILY DISABLED (missing helper classes)
            // $violations = array_merge($violations, $this->applyPerformanceRules($ast, $filePath));
            
            // Business Logic Rules - TEMPORARILY DISABLED (missing helper classes)
            // $violations = array_merge($violations, $this->applyBusinessLogicRules($ast, $filePath));

        } catch (\Exception $e) {
            error_log("Error parsing PHP file {$filePath}: " . $e->getMessage());
        }

        return $violations;
    }

    private function analyzeXmlFile(string $filePath, string $content, array $options = []): array
    {
        $violations = [];
        
        // Module Structure Rules for XML
        $violations = array_merge($violations, $this->applyModuleStructureRules(null, $filePath, $content));
        
        // Configuration Rules for XML - TEMPORARILY DISABLED (missing helper classes)
        // $violations = array_merge($violations, $this->applyConfigurationRules(null, $filePath, $content));
        
        // Security Rules for XML - TEMPORARILY DISABLED (missing helper classes)
        // $violations = array_merge($violations, $this->applySecurityRules(null, $filePath, $content));

        return $violations;
    }

    private function analyzeTemplateFile(string $filePath, string $content, array $options = []): array
    {
        $violations = [];
        
        // Frontend Rules for templates - TEMPORARILY DISABLED (missing helper classes)
        // $violations = array_merge($violations, $this->applyFrontendRules(null, $filePath, $content));
        
        // Performance Rules for templates - TEMPORARILY DISABLED (missing helper classes)
        // $violations = array_merge($violations, $this->applyPerformanceRules(null, $filePath, $content));

        return $violations;
    }

    private function analyzeFrontendFile(string $filePath, string $content, array $options = []): array
    {
        $violations = [];
        
        // Frontend Rules for JavaScript/TypeScript - TEMPORARILY DISABLED (missing FrontendRules class)
        // $violations = array_merge($violations, $this->applyFrontendRules(null, $filePath, $content));

        return $violations;
    }

    private function analyzeStyleFile(string $filePath, string $content, array $options = []): array
    {
        $violations = [];
        
        // Frontend Rules for CSS/LESS - TEMPORARILY DISABLED (missing FrontendRules class)
        // $violations = array_merge($violations, $this->applyFrontendRules(null, $filePath, $content));

        return $violations;
    }

    private function analyzeArchitectureRules(string $filePath, array $ast): array
    {
        $violations = [];

        // Analyze each node in the AST
        foreach ($ast as $node) {
            $this->traverseNode($node, function($node) use (&$violations, $filePath) {
                // ObjectManager usage detection
                $objectManagerViolation = ArchitectureRules::detectDirectObjectManagerUsage($node, $filePath);
                if ($objectManagerViolation) {
                    $violations[] = $objectManagerViolation;
                }

                // Factory without DI detection
                $factoryViolation = ArchitectureRules::detectFactoryWithoutDI($node, $filePath);
                if ($factoryViolation) {
                    $violations[] = $factoryViolation;
                }

                // Plugin violations detection
                $pluginViolation = ArchitectureRules::detectPluginViolations($node, $filePath);
                if ($pluginViolation) {
                    $violations[] = $pluginViolation;
                }

                // Preference conflicts detection
                $preferenceViolation = ArchitectureRules::detectPreferenceConflicts($node, $filePath);
                if ($preferenceViolation) {
                    $violations[] = $preferenceViolation;
                }
            });
        }

        return $violations;
    }

    private function analyzeModuleStructureRules(string $filePath, array $ast): array
    {
        $violations = [];

        foreach ($ast as $node) {
            $this->traverseNode($node, function($node) use (&$violations, $filePath) {
                // Registration issues detection
                $registrationViolation = ModuleStructureRules::detectRegistrationIssues($filePath, $node);
                if ($registrationViolation) {
                    $violations[] = $registrationViolation;
                }

                // Namespace violations detection
                $namespaceViolation = ModuleStructureRules::detectNamespaceViolations($node, $filePath);
                if ($namespaceViolation) {
                    $violations[] = $namespaceViolation;
                }
            });
        }

        // Directory structure issues
        $directoryViolation = ModuleStructureRules::detectDirectoryStructureIssues($filePath);
        if ($directoryViolation) {
            $violations[] = $directoryViolation;
        }

        return $violations;
    }

    private function analyzeDatabaseRules(string $filePath, array $ast): array
    {
        $violations = [];

        foreach ($ast as $node) {
            $this->traverseNode($node, function($node) use (&$violations, $filePath) {
                // Direct SQL queries detection
                $sqlViolation = DatabaseRules::detectDirectSqlQueries($node);
                if ($sqlViolation) {
                    $violations[] = $sqlViolation;
                }

                // Resource model violations detection
                $resourceViolation = DatabaseRules::detectResourceModelViolations($node);
                if ($resourceViolation) {
                    $violations[] = $resourceViolation;
                }

                // Collection violations detection
                $collectionViolation = DatabaseRules::detectCollectionViolations($node);
                if ($collectionViolation) {
                    $violations[] = $collectionViolation;
                }

                // Repository violations detection
                $repositoryViolation = DatabaseRules::detectRepositoryViolations($node);
                if ($repositoryViolation) {
                    $violations[] = $repositoryViolation;
                }
            });
        }

        return $violations;
    }

    private function analyzeCodingStandardsRules(string $filePath, array $ast): array
    {
        $violations = [];

        foreach ($ast as $node) {
            $this->traverseNode($node, function($node) use (&$violations, $filePath) {
                // Missing API annotations detection
                $apiViolation = CodingStandardsRules::detectMissingApiAnnotations($node, $filePath);
                if ($apiViolation) {
                    $violations[] = $apiViolation;
                }

                // Hardcoded paths detection
                $pathViolation = CodingStandardsRules::detectHardcodedPaths($node, $filePath);
                if ($pathViolation) {
                    $violations[] = $pathViolation;
                }

                // Constant issues detection
                $constantViolation = CodingStandardsRules::detectConstantIssues($node, $filePath);
                if ($constantViolation) {
                    $violations[] = $constantViolation;
                }

                // Exception handling issues detection
                $exceptionViolation = CodingStandardsRules::detectExceptionIssues($node, $filePath);
                if ($exceptionViolation) {
                    $violations[] = $exceptionViolation;
                }
            });
        }

        return $violations;
    }

    private function analyzeApiServiceRules(string $filePath, array $ast): array
    {
        $violations = [];

        foreach ($ast as $node) {
            $this->traverseNode($node, function($node) use (&$violations) {
                // Missing API annotations detection
                $apiViolation = ApiServiceRules::detectMissingApiAnnotations($node);
                if ($apiViolation) {
                    $violations[] = $apiViolation;
                }

                // Service contract violations detection
                $serviceViolation = ApiServiceRules::detectServiceContractViolations($node);
                if ($serviceViolation) {
                    $violations[] = $serviceViolation;
                }

                // Extension attributes issues detection
                $extensionViolation = ApiServiceRules::detectExtensionAttributesIssues($node);
                if ($extensionViolation) {
                    $violations[] = $extensionViolation;
                }

                // Hardcoded paths detection
                $pathViolation = ApiServiceRules::detectHardcodedPaths($node);
                if ($pathViolation) {
                    $violations[] = $pathViolation;
                }

                // API response issues detection
                $responseViolation = ApiServiceRules::detectApiResponseIssues($node);
                if ($responseViolation) {
                    $violations[] = $responseViolation;
                }
            });
        }

        return $violations;
    }

    private function traverseNode(Node $node, callable $callback): void
    {
        $callback($node);

        foreach ($node->getSubNodeNames() as $name) {
            $subNode = $node->$name;
            if ($subNode instanceof Node) {
                $this->traverseNode($subNode, $callback);
            } elseif (is_array($subNode)) {
                foreach ($subNode as $childNode) {
                    if ($childNode instanceof Node) {
                        $this->traverseNode($childNode, $callback);
                    }
                }
            }
        }
    }

    public function getRules(): array
    {
        return [
            // Architecture & Framework Rules
            'direct-objectmanager-usage' => [
                'name' => 'Direct ObjectManager Usage',
                'description' => 'Direct ObjectManager usage detected. Use dependency injection instead.',
                'severity' => 'error',
                'category' => 'architecture'
            ],
            'factory-without-di' => [
                'name' => 'Factory Without DI',
                'description' => 'Factory class instantiated without dependency injection.',
                'severity' => 'warning',
                'category' => 'architecture'
            ],
            'plugin-missing-methods' => [
                'name' => 'Plugin Missing Methods',
                'description' => 'Plugin class missing required plugin methods.',
                'severity' => 'error',
                'category' => 'architecture'
            ],
            'manual-interceptor' => [
                'name' => 'Manual Interceptor',
                'description' => 'Manual interceptor class detected. Interceptors should be generated automatically.',
                'severity' => 'error',
                'category' => 'architecture'
            ],
            'preference-structure-violation' => [
                'name' => 'Preference Structure Violation',
                'description' => 'Preference class missing proper structure or inheritance.',
                'severity' => 'warning',
                'category' => 'architecture'
            ],

            // Module Structure Rules
            'invalid-registration-structure' => [
                'name' => 'Invalid Registration Structure',
                'description' => 'Invalid registration.php file structure.',
                'severity' => 'error',
                'category' => 'module-structure'
            ],
            'invalid-module-xml' => [
                'name' => 'Invalid Module XML',
                'description' => 'Invalid module.xml structure.',
                'severity' => 'error',
                'category' => 'module-structure'
            ],
            'invalid-module-sequence' => [
                'name' => 'Invalid Module Sequence',
                'description' => 'Module sequence issues.',
                'severity' => 'warning',
                'category' => 'module-structure'
            ],
            'invalid-composer-json' => [
                'name' => 'Invalid Composer JSON',
                'description' => 'Composer.json issues.',
                'severity' => 'error',
                'category' => 'module-structure'
            ],
            'invalid-module-namespace' => [
                'name' => 'Invalid Module Namespace',
                'description' => 'Invalid module namespace.',
                'severity' => 'error',
                'category' => 'module-structure'
            ],
            'missing-module-files' => [
                'name' => 'Missing Module Files',
                'description' => 'Missing required module files.',
                'severity' => 'error',
                'category' => 'module-structure'
            ],

            // Database Rules
            'direct-sql-query' => [
                'name' => 'Direct SQL Query',
                'description' => 'Direct SQL query detected. Use repositories or collections instead.',
                'severity' => 'error',
                'category' => 'database'
            ],
            'missing-schema-versioning' => [
                'name' => 'Missing Schema Versioning',
                'description' => 'Missing database schema versioning.',
                'severity' => 'warning',
                'category' => 'database'
            ],
            'mixed-schema-approaches' => [
                'name' => 'Mixed Schema Approaches',
                'description' => 'Using both setup scripts and declarative schema.',
                'severity' => 'warning',
                'category' => 'database'
            ],
            'resource-model-violations' => [
                'name' => 'Resource Model Violations',
                'description' => 'Resource model implementation violations.',
                'severity' => 'error',
                'category' => 'database'
            ],
            'collection-violations' => [
                'name' => 'Collection Violations',
                'description' => 'Collection implementation violations.',
                'severity' => 'warning',
                'category' => 'database'
            ],
            'repository-violations' => [
                'name' => 'Repository Violations',
                'description' => 'Repository implementation violations.',
                'severity' => 'error',
                'category' => 'database'
            ],

            // Coding Standards Rules
            'missing-api-annotation' => [
                'name' => 'Missing API Annotation',
                'description' => 'Public interface missing @api annotation.',
                'severity' => 'error',
                'category' => 'coding-standards'
            ],
            'missing-api-annotation-method' => [
                'name' => 'Missing API Annotation Method',
                'description' => 'Public interface method missing @api annotation.',
                'severity' => 'warning',
                'category' => 'coding-standards'
            ],
            'service-contract-violations' => [
                'name' => 'Service Contract Violations',
                'description' => 'Service contract violations.',
                'severity' => 'error',
                'category' => 'coding-standards'
            ],
            'extension-attributes-violations' => [
                'name' => 'Extension Attributes Violations',
                'description' => 'Extension attributes implementation violations.',
                'severity' => 'warning',
                'category' => 'coding-standards'
            ],
            'webapi-configuration-issues' => [
                'name' => 'WebAPI Configuration Issues',
                'description' => 'WebAPI configuration issues.',
                'severity' => 'error',
                'category' => 'coding-standards'
            ],
            'hardcoded-frontend-path' => [
                'name' => 'Hardcoded Frontend Path',
                'description' => 'Hardcoded frontend path detected.',
                'severity' => 'warning',
                'category' => 'coding-standards'
            ],
            'hardcoded-url' => [
                'name' => 'Hardcoded URL',
                'description' => 'Hardcoded URL detected.',
                'severity' => 'warning',
                'category' => 'coding-standards'
            ],
            'improper-constant-naming' => [
                'name' => 'Improper Constant Naming',
                'description' => 'Improper constant naming.',
                'severity' => 'warning',
                'category' => 'coding-standards'
            ],
            'exception-handling-issues' => [
                'name' => 'Exception Handling Issues',
                'description' => 'Exception handling issues.',
                'severity' => 'warning',
                'category' => 'coding-standards'
            ],

            // API & Service Contract Rules
            'repository-pattern-violations' => [
                'name' => 'Repository Pattern Violations',
                'description' => 'Repository pattern violations.',
                'severity' => 'error',
                'category' => 'api-service'
            ],
            'data-interface-violations' => [
                'name' => 'Data Interface Violations',
                'description' => 'Data interface violations.',
                'severity' => 'error',
                'category' => 'api-service'
            ],
            'api-response-issues' => [
                'name' => 'API Response Issues',
                'description' => 'API response issues.',
                'severity' => 'warning',
                'category' => 'api-service'
            ]
        ];
    }

    // Required abstract method implementations
    public function isAvailable(): bool
    {
        return true; // This analyzer doesn't require external tools
    }

    protected function getDefaultConfiguration(): array
    {
        return [
            'enabled_rules' => [
                'architecture' => true,
                'module_structure' => true,
                'database' => true,
                'coding_standards' => true,
                'api_service' => true
            ],
            'severity_levels' => [
                'error' => true,
                'warning' => true,
                'info' => false
            ]
        ];
    }

    protected function executeAnalysis(array $files, array $options = []): array
    {
        $violations = [];
        
        foreach ($files as $filePath) {
            if (!file_exists($filePath)) {
                continue;
            }

            $content = file_get_contents($filePath);
            $extension = pathinfo($filePath, PATHINFO_EXTENSION);

            try {
                switch ($extension) {
                    case 'php':
                        $violations = array_merge($violations, $this->analyzePhpFile($filePath, $content, $options));
                        break;
                    case 'xml':
                        $violations = array_merge($violations, $this->analyzeXmlFile($filePath, $content, $options));
                        break;
                    case 'phtml':
                        $violations = array_merge($violations, $this->analyzeTemplateFile($filePath, $content, $options));
                        break;
                    case 'js':
                    case 'jsx':
                    case 'ts':
                    case 'tsx':
                        $violations = array_merge($violations, $this->analyzeFrontendFile($filePath, $content, $options));
                        break;
                    case 'css':
                    case 'less':
                        $violations = array_merge($violations, $this->analyzeStyleFile($filePath, $content, $options));
                        break;
                }
            } catch (\Exception $e) {
                // Log error and continue with other files
                error_log("Error analyzing file {$filePath}: " . $e->getMessage());
            }
        }

        return $violations;
    }

    private function applyArchitectureRules($ast, string $filePath): array
    {
        $violations = [];
        $nodeFinder = new NodeFinder();

        // Direct ObjectManager usage
        $objectManagerCalls = $nodeFinder->find($ast, function(Node $node) {
            return ArchitectureRules::detectDirectObjectManagerUsage($node);
        });
        $violations = array_merge($violations, array_filter($objectManagerCalls));

        // Factory without DI
        $factoryCalls = $nodeFinder->find($ast, function(Node $node) {
            return ArchitectureRules::detectFactoryWithoutDI($node);
        });
        $violations = array_merge($violations, array_filter($factoryCalls));

        // Plugin violations
        $pluginViolations = $nodeFinder->find($ast, function(Node $node) {
            return ArchitectureRules::detectPluginViolations($node);
        });
        $violations = array_merge($violations, array_filter($pluginViolations));

        // Preference conflicts
        $preferenceViolations = $nodeFinder->find($ast, function(Node $node) {
            return ArchitectureRules::detectPreferenceConflicts($node);
        });
        $violations = array_merge($violations, array_filter($preferenceViolations));

        return $violations;
    }

    private function applyModuleStructureRules($ast, string $filePath, string $content = ''): array
    {
        $violations = [];

        if ($ast) {
            $nodeFinder = new NodeFinder();

            // Registration issues - a single file-level check, since a valid
            // ComponentRegistrar::register() call is one node among many and
            // checking node-by-node would flag every other node as "invalid"
            $registrationViolation = ModuleStructureRules::detectRegistrationIssues($filePath, $ast);
            if ($registrationViolation) {
                $violations[] = $registrationViolation;
            }

            $allNodes = $nodeFinder->findInstanceOf($ast, Node::class);

            // Namespace violations - check all nodes
            foreach ($allNodes as $node) {
                $violation = ModuleStructureRules::detectNamespaceViolations($node, $filePath);
                if ($violation) {
                    $violations[] = $violation;
                }
            }
        }

        // XML-specific rules
        if ($content) {
            $moduleXmlIssues = ModuleStructureRules::detectModuleXmlIssues($filePath, $content);
            if ($moduleXmlIssues) $violations[] = $moduleXmlIssues;

            $sequenceIssues = ModuleStructureRules::detectSequenceIssues($filePath, $content);
            if ($sequenceIssues) $violations[] = $sequenceIssues;

            $composerIssues = ModuleStructureRules::detectComposerIssues($filePath, $content);
            if ($composerIssues) $violations[] = $composerIssues;

            $directoryIssues = ModuleStructureRules::detectDirectoryStructureIssues($filePath);
            if ($directoryIssues) $violations[] = $directoryIssues;
        }

        return $violations;
    }

    private function applyDatabaseRules($ast, string $filePath): array
    {
        $violations = [];
        $nodeFinder = new NodeFinder();

        // Direct SQL queries
        $sqlViolations = $nodeFinder->find($ast, function(Node $node) {
            return DatabaseRules::detectDirectSqlQueries($node);
        });
        $violations = array_merge($violations, array_filter($sqlViolations));

        // Schema versioning
        $schemaViolations = $nodeFinder->find($ast, function(Node $node) use ($filePath) {
            return DatabaseRules::detectMissingSchemaVersioning($filePath, $node);
        });
        $violations = array_merge($violations, array_filter($schemaViolations));

        // Setup script issues
        $setupViolations = $nodeFinder->find($ast, function(Node $node) use ($filePath) {
            return DatabaseRules::detectSetupScriptIssues($filePath, $node);
        });
        $violations = array_merge($violations, array_filter($setupViolations));

        // Resource model violations
        $resourceViolations = $nodeFinder->find($ast, function(Node $node) {
            return DatabaseRules::detectResourceModelViolations($node);
        });
        $violations = array_merge($violations, array_filter($resourceViolations));

        // Collection violations
        $collectionViolations = $nodeFinder->find($ast, function(Node $node) {
            return DatabaseRules::detectCollectionViolations($node);
        });
        $violations = array_merge($violations, array_filter($collectionViolations));

        // Repository violations
        $repositoryViolations = $nodeFinder->find($ast, function(Node $node) {
            return DatabaseRules::detectRepositoryViolations($node);
        });
        $violations = array_merge($violations, array_filter($repositoryViolations));

        return $violations;
    }

    private function applyCodingStandardsRules($ast, string $filePath): array
    {
        $violations = [];
        $nodeFinder = new NodeFinder();

        // Missing API annotations
        $apiViolations = $nodeFinder->find($ast, function(Node $node) {
            return CodingStandardsRules::detectMissingApiAnnotations($node);
        });
        $violations = array_merge($violations, array_filter($apiViolations));

        // Service contract violations
        $serviceViolations = $nodeFinder->find($ast, function(Node $node) {
            return CodingStandardsRules::detectServiceContractViolations($node);
        });
        $violations = array_merge($violations, array_filter($serviceViolations));

        // Extension attributes issues
        $extensionViolations = $nodeFinder->find($ast, function(Node $node) {
            return CodingStandardsRules::detectExtensionAttributesIssues($node);
        });
        $violations = array_merge($violations, array_filter($extensionViolations));

        // Hardcoded paths
        $pathViolations = $nodeFinder->find($ast, function(Node $node) {
            return CodingStandardsRules::detectHardcodedPaths($node);
        });
        $violations = array_merge($violations, array_filter($pathViolations));

        // Constant issues
        $constantViolations = $nodeFinder->find($ast, function(Node $node) {
            return CodingStandardsRules::detectConstantIssues($node);
        });
        $violations = array_merge($violations, array_filter($constantViolations));

        // Exception issues
        $exceptionViolations = $nodeFinder->find($ast, function(Node $node) {
            return CodingStandardsRules::detectExceptionIssues($node);
        });
        $violations = array_merge($violations, array_filter($exceptionViolations));

        return $violations;
    }

    private function applyApiServiceRules($ast, string $filePath): array
    {
        $violations = [];
        $nodeFinder = new NodeFinder();

        // Missing API annotations
        $apiViolations = $nodeFinder->find($ast, function(Node $node) {
            return ApiServiceRules::detectMissingApiAnnotations($node);
        });
        $violations = array_merge($violations, array_filter($apiViolations));

        // Service contract violations
        $serviceViolations = $nodeFinder->find($ast, function(Node $node) use ($filePath) {
            return ApiServiceRules::detectServiceContractViolations($node, $filePath);
        });
        $violations = array_merge($violations, array_filter($serviceViolations));

        // Extension attributes issues
        $extensionViolations = $nodeFinder->find($ast, function(Node $node) use ($filePath) {
            return ApiServiceRules::detectExtensionAttributesIssues($node, $filePath);
        });
        $violations = array_merge($violations, array_filter($extensionViolations));

        // Hardcoded paths
        $pathViolations = $nodeFinder->find($ast, function(Node $node) {
            return ApiServiceRules::detectHardcodedPaths($node);
        });
        $violations = array_merge($violations, array_filter($pathViolations));

        // API response issues
        $responseViolations = $nodeFinder->find($ast, function(Node $node) {
            return ApiServiceRules::detectApiResponseIssues($node);
        });
        $violations = array_merge($violations, array_filter($responseViolations));

        return $violations;
    }

    private function applyFrontendRules($ast, string $filePath, string $content = ''): array
    {
        $violations = [];

        if ($ast) {
            $nodeFinder = new NodeFinder();
            
            // Missing translation
            $translationViolations = $nodeFinder->find($ast, function(Node $node) use ($filePath) {
                return FrontendRules::detectMissingTranslation($node, $filePath);
            });
            $violations = array_merge($violations, array_filter($translationViolations));

            // Block inheritance issues
            $inheritanceViolations = $nodeFinder->find($ast, function(Node $node) use ($filePath) {
                return FrontendRules::detectBlockInheritanceIssues($node, $filePath);
            });
            $violations = array_merge($violations, array_filter($inheritanceViolations));

            // Missing escaping
            $escapingViolations = $nodeFinder->find($ast, function(Node $node) use ($filePath) {
                return FrontendRules::detectMissingEscaping($node, $filePath);
            });
            $violations = array_merge($violations, array_filter($escapingViolations));
        }

        if ($content) {
            // Layout XML issues
            $layoutIssues = FrontendRules::detectLayoutXmlIssues($filePath, $content);
            if ($layoutIssues) $violations[] = $layoutIssues;

            // RequireJS issues
            $requireJsIssues = FrontendRules::detectRequireJsIssues($filePath, $content);
            if ($requireJsIssues) $violations[] = $requireJsIssues;

            // Inline JavaScript
            $inlineJsIssues = FrontendRules::detectInlineJavaScript($filePath, $content);
            if ($inlineJsIssues) $violations[] = $inlineJsIssues;

            // Responsive design issues
            $responsiveIssues = FrontendRules::detectResponsiveDesignIssues($filePath, $content);
            if ($responsiveIssues) $violations[] = $responsiveIssues;
        }

        return $violations;
    }

    private function applyConfigurationRules($ast, string $filePath, string $content = ''): array
    {
        $violations = [];

        if ($ast) {
            $nodeFinder = new NodeFinder();
            
            // Hardcoded store IDs
            $storeViolations = $nodeFinder->find($ast, function(Node $node) use ($filePath) {
                return ConfigurationRules::detectHardcodedStoreIds($node, $filePath);
            });
            $violations = array_merge($violations, array_filter($storeViolations));

            // Core config path issues
            $configViolations = $nodeFinder->find($ast, function(Node $node) use ($filePath) {
                return ConfigurationRules::detectCoreConfigPathIssues($node, $filePath);
            });
            $violations = array_merge($violations, array_filter($configViolations));
        }

        if ($content) {
            // ACL resource issues
            $aclIssues = ConfigurationRules::detectMissingAclResources($filePath, $content);
            if ($aclIssues) $violations[] = $aclIssues;

            // System config issues
            $systemIssues = ConfigurationRules::detectSystemConfigIssues($filePath, $content);
            if ($systemIssues) $violations[] = $systemIssues;

            // Environment config issues
            $envIssues = ConfigurationRules::detectEnvironmentConfigIssues($filePath, $content);
            if ($envIssues) $violations[] = $envIssues;

            // Sensitive config exposure
            $sensitiveIssues = ConfigurationRules::detectSensitiveConfigExposure($filePath, $content);
            if ($sensitiveIssues) $violations[] = $sensitiveIssues;

            // Cron config issues
            $cronIssues = ConfigurationRules::detectMissingCronConfig($filePath, $content);
            if ($cronIssues) $violations[] = $cronIssues;

            // Event config issues
            $eventIssues = ConfigurationRules::detectEventConfigIssues($filePath, $content);
            if ($eventIssues) $violations[] = $eventIssues;
        }

        return $violations;
    }

    private function applySecurityRules($ast, string $filePath, string $content = ''): array
    {
        $violations = [];

        if ($ast) {
            $nodeFinder = new NodeFinder();
            
            // Missing CSRF protection
            $csrfViolations = $nodeFinder->find($ast, function(Node $node) use ($filePath) {
                return SecurityRules::detectMissingCsrfProtection($node, $filePath);
            });
            $violations = array_merge($violations, array_filter($csrfViolations));

            // Missing authorization
            $authViolations = $nodeFinder->find($ast, function(Node $node) use ($filePath) {
                return SecurityRules::detectMissingAuthorization($node, $filePath);
            });
            $violations = array_merge($violations, array_filter($authViolations));

            // Unsafe output
            $outputViolations = $nodeFinder->find($ast, function(Node $node) use ($filePath) {
                return SecurityRules::detectUnsafeOutput($node, $filePath);
            });
            $violations = array_merge($violations, array_filter($outputViolations));

            // Missing input validation
            $inputViolations = $nodeFinder->find($ast, function(Node $node) use ($filePath) {
                return SecurityRules::detectMissingInputValidation($node, $filePath);
            });
            $violations = array_merge($violations, array_filter($inputViolations));

            // Session issues
            $sessionViolations = $nodeFinder->find($ast, function(Node $node) use ($filePath) {
                return SecurityRules::detectSessionIssues($node, $filePath);
            });
            $violations = array_merge($violations, array_filter($sessionViolations));

            // File upload issues
            $uploadViolations = $nodeFinder->find($ast, function(Node $node) use ($filePath) {
                return SecurityRules::detectFileUploadIssues($node, $filePath);
            });
            $violations = array_merge($violations, array_filter($uploadViolations));
        }

        if ($content) {
            // Admin route issues
            $routeIssues = SecurityRules::detectAdminRouteIssues($filePath, $content);
            if ($routeIssues) $violations[] = $routeIssues;

            // Security headers
            $headerIssues = SecurityRules::detectMissingSecurityHeaders($filePath, $content);
            if ($headerIssues) $violations[] = $headerIssues;
        }

        return $violations;
    }

    private function applyPerformanceRules($ast, string $filePath, string $content = ''): array
    {
        $violations = [];

        if ($ast) {
            $nodeFinder = new NodeFinder();
            
            // Collection in loops
            $collectionViolations = $nodeFinder->find($ast, function(Node $node) use ($filePath) {
                return PerformanceRules::detectCollectionInLoops($node, $filePath);
            });
            $violations = array_merge($violations, array_filter($collectionViolations));

            // Missing indexing
            $indexingViolations = $nodeFinder->find($ast, function(Node $node) use ($filePath) {
                return PerformanceRules::detectMissingIndexing($node, $filePath);
            });
            $violations = array_merge($violations, array_filter($indexingViolations));

            // Inefficient repository usage
            $repositoryViolations = $nodeFinder->find($ast, function(Node $node) use ($filePath) {
                return PerformanceRules::detectInefficientRepositoryUsage($node, $filePath);
            });
            $violations = array_merge($violations, array_filter($repositoryViolations));

            // Missing caching
            $cachingViolations = $nodeFinder->find($ast, function(Node $node) use ($filePath) {
                return PerformanceRules::detectMissingCaching($node, $filePath);
            });
            $violations = array_merge($violations, array_filter($cachingViolations));

            // Inefficient queries
            $queryViolations = $nodeFinder->find($ast, function(Node $node) use ($filePath) {
                return PerformanceRules::detectInefficientQueries($node, $filePath);
            });
            $violations = array_merge($violations, array_filter($queryViolations));

            // Memory issues
            $memoryViolations = $nodeFinder->find($ast, function(Node $node) use ($filePath) {
                return PerformanceRules::detectMemoryIssues($node, $filePath);
            });
            $violations = array_merge($violations, array_filter($memoryViolations));

            // Missing lazy loading
            $lazyViolations = $nodeFinder->find($ast, function(Node $node) use ($filePath) {
                return PerformanceRules::detectMissingLazyLoading($node, $filePath);
            });
            $violations = array_merge($violations, array_filter($lazyViolations));
        }

        if ($content) {
            // Template loop issues
            $templateIssues = PerformanceRules::detectTemplateLoopIssues($filePath, $content);
            if ($templateIssues) $violations[] = $templateIssues;
        }

        return $violations;
    }

    private function applyBusinessLogicRules($ast, string $filePath, string $content = ''): array
    {
        $violations = [];

        if ($ast) {
            $nodeFinder = new NodeFinder();
            
            // Hardcoded currency codes
            $currencyViolations = $nodeFinder->find($ast, function(Node $node) use ($filePath) {
                return BusinessLogicRules::detectHardcodedCurrencyCodes($node, $filePath);
            });
            $violations = array_merge($violations, array_filter($currencyViolations));

            // Missing multi-store support
            $storeViolations = $nodeFinder->find($ast, function(Node $node) use ($filePath) {
                return BusinessLogicRules::detectMissingMultiStoreSupport($node, $filePath);
            });
            $violations = array_merge($violations, array_filter($storeViolations));

            // Price calculation issues
            $priceViolations = $nodeFinder->find($ast, function(Node $node) use ($filePath) {
                return BusinessLogicRules::detectPriceCalculationIssues($node, $filePath);
            });
            $violations = array_merge($violations, array_filter($priceViolations));

            // Tax calculation issues
            $taxViolations = $nodeFinder->find($ast, function(Node $node) use ($filePath) {
                return BusinessLogicRules::detectTaxCalculationIssues($node, $filePath);
            });
            $violations = array_merge($violations, array_filter($taxViolations));

            // Missing event dispatching
            $eventViolations = $nodeFinder->find($ast, function(Node $node) use ($filePath) {
                return BusinessLogicRules::detectMissingEventDispatching($node, $filePath);
            });
            $violations = array_merge($violations, array_filter($eventViolations));

            // Plugin signature issues
            $pluginViolations = $nodeFinder->find($ast, function(Node $node) use ($filePath) {
                return BusinessLogicRules::detectPluginSignatureIssues($node, $filePath);
            });
            $violations = array_merge($violations, array_filter($pluginViolations));
        }

        if ($content) {
            // Observer issues
            $observerIssues = BusinessLogicRules::detectObserverIssues($filePath, $content);
            if ($observerIssues) $violations[] = $observerIssues;

            // Integration test issues
            $testIssues = BusinessLogicRules::detectMissingIntegrationTests($filePath, $content);
            if ($testIssues) $violations[] = $testIssues;

            // Mock usage issues
            $mockIssues = BusinessLogicRules::detectMockIssues($filePath, $content);
            if ($mockIssues) $violations[] = $mockIssues;
        }

        return $violations;
    }

    protected function parseOutput(string $output, array $files): array
    {
        // This analyzer doesn't parse external tool output
        return [];
    }

    public function getSupportedSeverities(): array
    {
        return ['error', 'warning', 'info'];
    }
} 