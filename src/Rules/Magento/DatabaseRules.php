<?php

declare(strict_types=1);

namespace ACQC\Rules\Magento;

use ACQC\Core\Rules\Rule;
use ACQC\Core\Rules\Violation;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeFinder;

/**
 * Magento 2 Database & Schema Rules
 */
class DatabaseRules
{
    private const FORBIDDEN_DB_METHODS = [
        'query',
        'fetchAll',
        'fetchOne',
        'fetchRow',
        'fetchCol',
        'fetchPairs'
    ];

    private const ALLOWED_DB_CLASSES = [
        'Magento\Framework\DB\Adapter\AdapterInterface',
        'Magento\Framework\App\ResourceConnection'
    ];

    /**
     * Rule: Direct SQL queries instead of using repositories/collections
     */
    public static function detectDirectSqlQueries(Node $node): ?Violation
    {
        if ($node instanceof MethodCall) {
            // Check if method name is a string or identifier
            if ($node->name instanceof Node\Identifier) {
                $methodName = $node->name->toString();
            } elseif (is_string($node->name)) {
                $methodName = $node->name;
            } else {
                return null; // Skip variable method calls
            }
            
            if (in_array($methodName, self::FORBIDDEN_DB_METHODS)) {
                return new Violation(
                    'direct-sql-query',
                    'Direct SQL query detected. Use repositories or collections instead.',
                    '', // filePath will be set by analyzer
                    $node->getLine(),
                    0, // column
                    'error',
                    'performance'
                );
            }
        }

        return null;
    }

    /**
     * Rule: Missing database schema versioning
     */
    public static function detectMissingSchemaVersioning(string $filePath, Node $node): ?Violation
    {
        if (strpos($filePath, 'db_schema.xml') !== false) {
            if (!self::hasSchemaVersioning($node)) {
                return new Violation(
                    'missing-schema-versioning',
                    'Missing database schema versioning.',
                    $filePath,
                    $node->getLine(),
                    0, // column
                    'warning',
                    'maintainability'
                );
            }
        }

        return null;
    }

    /**
     * Rule: Improper use of setup scripts vs declarative schema
     */
    public static function detectSetupScriptIssues(string $filePath, Node $node): ?Violation
    {
        if (strpos($filePath, 'InstallSchema.php') !== false || strpos($filePath, 'UpgradeSchema.php') !== false) {
            if (self::hasDbSchemaXml($filePath)) {
                return new Violation(
                    'mixed-schema-approaches',
                    'Using both setup scripts and declarative schema.',
                    $filePath,
                    $node->getLine(),
                    0, // column
                    'warning',
                    'maintainability'
                );
            }
        }

        return null;
    }

    /**
     * Rule: Resource model implementation violations
     */
    public static function detectResourceModelViolations(Node $node): ?Violation
    {
        if ($node instanceof Class_) {
            $className = $node->name?->toString() ?? '';

            if (strpos($className, 'Resource') !== false) {
                $issues = self::validateResourceModel($node);
                if (!empty($issues)) {
                    return new Violation(
                        'resource-model-violations',
                        'Resource model issues: ' . implode(', ', $issues),
                        '', // filePath will be set by analyzer
                        $node->getLine(),
                        0, // column
                        'error',
                        'maintainability'
                    );
                }
            }
        }

        return null;
    }

    /**
     * Rule: Collection implementation violations
     */
    public static function detectCollectionViolations(Node $node): ?Violation
    {
        if ($node instanceof Class_) {
            $className = $node->name?->toString() ?? '';

            if (strpos($className, 'Collection') !== false) {
                $issues = self::validateCollection($node);
                if (!empty($issues)) {
                    return new Violation(
                        'collection-violations',
                        'Collection issues: ' . implode(', ', $issues),
                        '', // filePath will be set by analyzer
                        $node->getLine(),
                        0, // column
                        'error',
                        'performance'
                    );
                }
            }
        }

        return null;
    }

    /**
     * Rule: Repository implementation violations
     */
    public static function detectRepositoryViolations(Node $node): ?Violation
    {
        if ($node instanceof Class_) {
            $className = $node->name?->toString() ?? '';

            if (strpos($className, 'Repository') !== false) {
                $issues = self::validateRepository($node);
                if (!empty($issues)) {
                    return new Violation(
                        'repository-violations',
                        'Repository issues: ' . implode(', ', $issues),
                        '', // filePath will be set by analyzer
                        $node->getLine(),
                        0, // column
                        'error',
                        'maintainability'
                    );
                }
            }
        }

        return null;
    }

    /**
     * Check if schema has proper versioning
     */
    private static function hasSchemaVersioning(Node $node): bool
    {
        $nodeFinder = new NodeFinder();
        $attributes = $nodeFinder->findInstanceOf($node, Node\Attribute::class);

        foreach ($attributes as $attribute) {
            if ($attribute->name->toString() === 'version') {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if module has db_schema.xml
     */
    private static function hasDbSchemaXml(string $filePath): bool
    {
        $moduleDir = dirname(dirname($filePath));
        return file_exists($moduleDir . '/etc/db_schema.xml');
    }

    /**
     * Validate resource model structure
     */
    private static function validateResourceModel(Class_ $class): array
    {
        $issues = [];

        // Check if extends proper base class
        if (!$class->extends) {
            $issues[] = 'missing base class extension';
        } else {
            $baseClass = $class->extends->toString();
            if (strpos($baseClass, 'ResourceModel') === false) {
                $issues[] = 'should extend ResourceModel base class';
            }
        }

        // Check for required methods
        $nodeFinder = new NodeFinder();
        $methods = $nodeFinder->findInstanceOf($class, Node\Stmt\ClassMethod::class);
        
        $methodNames = array_map(function($method) {
            return $method->name->toString();
        }, $methods);

        if (!in_array('_construct', $methodNames)) {
            $issues[] = 'missing _construct method';
        }

        return $issues;
    }

    /**
     * Validate collection structure
     */
    private static function validateCollection(Class_ $class): array
    {
        $issues = [];

        // Check if extends proper base class
        if (!$class->extends) {
            $issues[] = 'missing base class extension';
        } else {
            $baseClass = $class->extends->toString();
            if (strpos($baseClass, 'Collection') === false) {
                $issues[] = 'should extend Collection base class';
            }
        }

        // Check for required methods
        $nodeFinder = new NodeFinder();
        $methods = $nodeFinder->findInstanceOf($class, Node\Stmt\ClassMethod::class);
        
        $methodNames = array_map(function($method) {
            return $method->name->toString();
        }, $methods);

        if (!in_array('_construct', $methodNames)) {
            $issues[] = 'missing _construct method';
        }

        return $issues;
    }

    /**
     * Validate repository structure
     */
    private static function validateRepository(Class_ $class): array
    {
        $issues = [];

        // Check if implements repository interface
        if (empty($class->implements)) {
            $issues[] = 'missing repository interface implementation';
        }

        // Check for required methods
        $nodeFinder = new NodeFinder();
        $methods = $nodeFinder->findInstanceOf($class, Node\Stmt\ClassMethod::class);
        
        $methodNames = array_map(function($method) {
            return $method->name->toString();
        }, $methods);

        $requiredMethods = ['get', 'getList', 'save', 'delete'];
        foreach ($requiredMethods as $method) {
            if (!in_array($method, $methodNames)) {
                $issues[] = "missing required method: $method";
            }
        }

        return $issues;
    }
} 