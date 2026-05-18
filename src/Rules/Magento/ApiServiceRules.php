<?php

declare(strict_types=1);

namespace ACQC\Rules\Magento;

use ACQC\Core\Rules\Rule;
use ACQC\Core\Rules\Violation;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeFinder;

/**
 * Magento 2 API & Service Contract Rules
 */
class ApiServiceRules
{
    /**
     * Rule: Missing @api annotations for public interfaces
     */
    public static function detectMissingApiAnnotations(Node $node, string $filePath = ''): ?Violation
    {
        if ($node instanceof Interface_) {
            if (!self::hasApiAnnotation($node)) {
                return new Violation(
                    'missing-api-annotation',
                    'Public interface missing @api annotation.',
                    $filePath,
                    $node->getLine(),
                    0,
                    'error',
                    'style'
                );
            }
        }

        if ($node instanceof ClassMethod) {
            if ($node->isPublic() && !self::hasApiAnnotation($node)) {
                $parent = $node->getAttribute('parent');
                if ($parent instanceof Interface_) {
                    return new Violation(
                        'missing-api-annotation-method',
                        'Public interface method missing @api annotation.',
                        $filePath,
                        $node->getLine(),
                        0,
                        'warning',
                        'style'
                    );
                }
            }
        }

        return null;
    }

    /**
     * Rule: Service contract violations (data interfaces, repository patterns)
     */
    public static function detectServiceContractViolations(Node $node, string $filePath = ''): ?Violation
    {
        if ($node instanceof Class_) {
            $className = $node->name?->toString() ?? '';
            
            if (strpos($className, 'Repository') !== false) {
                $issues = self::validateRepositoryPattern($node);
                if (!empty($issues)) {
                    return new Violation(
                        'repository-pattern-violations',
                        'Repository pattern issues: ' . implode(', ', $issues),
                        $filePath,
                        $node->getLine(),
                        0,
                        'error',
                        'style'
                    );
                }
            }

            if (strpos($className, 'Data') !== false) {
                $issues = self::validateDataInterface($node);
                if (!empty($issues)) {
                    return new Violation(
                        'data-interface-violations',
                        'Data interface issues: ' . implode(', ', $issues),
                        $filePath,
                        $node->getLine(),
                        0,
                        'error',
                        'style'
                    );
                }
            }
        }

        return null;
    }

    /**
     * Rule: Improper extension attributes implementation
     */
    public static function detectExtensionAttributesIssues(Node $node, string $filePath = ''): ?Violation
    {
        if ($node instanceof Interface_) {
            $interfaceName = $node->name->toString() ?? '';
            
            if (strpos($interfaceName, 'ExtensionInterface') !== false) {
                $issues = self::validateExtensionAttributes($node);
                if (!empty($issues)) {
                    return new Violation(
                        'extension-attributes-violations',
                        'Extension attributes issues: ' . implode(', ', $issues),
                        $filePath,
                        $node->getLine(),
                        0,
                        'warning',
                        'style'
                    );
                }
            }
        }

        return null;
    }

    /**
     * Rule: Missing or incorrect WebAPI configurations
     */
    public static function detectWebApiIssues(string $filePath, string $content): ?Violation
    {
        if (strpos($filePath, 'webapi.xml') !== false) {
            $issues = self::validateWebApiXml($content);
            if (!empty($issues)) {
                return new Violation(
                    'webapi-configuration-issues',
                    'WebAPI configuration issues: ' . implode(', ', $issues),
                    $filePath,
                    1,
                    0,
                    'error',
                    'style'
                );
            }
        }

        return null;
    }

    /**
     * Rule: Hardcoded frontend paths/URLs
     */
    public static function detectHardcodedPaths(Node $node): ?Violation
    {
        if ($node instanceof Node\Scalar\String_) {
            $value = $node->value;
            
            // Check for hardcoded frontend paths
            if (preg_match('/^(frontend|adminhtml)\/[a-zA-Z0-9\/_-]+$/', $value)) {
                $violation = new Violation(
                    'hardcoded-frontend-path',
                    'Hardcoded frontend path detected: ' . $value,
                    '', // filePath will be set by analyzer
                    $node->getLine(),
                    0, // column
                    'warning',
                    'style'
                );
                $violation->setSuggestion('Use proper URL generation instead of hardcoded paths.');
                return $violation;
            }

            // Check for hardcoded URLs
            if (preg_match('/^https?:\/\/[^\s]+$/', $value)) {
                $violation = new Violation(
                    'hardcoded-url',
                    'Hardcoded URL detected: ' . $value,
                    '', // filePath will be set by analyzer
                    $node->getLine(),
                    0, // column
                    'warning',
                    'style'
                );
                $violation->setSuggestion('Use proper URL generation instead of hardcoded URLs.');
                return $violation;
            }
        }

        return null;
    }

    /**
     * Rule: Improper API response handling
     */
    public static function detectApiResponseIssues(Node $node): ?Violation
    {
        if ($node instanceof ClassMethod) {
            $methodName = $node->name->toString();
            
            if (strpos($methodName, 'get') === 0 || strpos($methodName, 'list') !== false) {
                $issues = self::validateApiResponse($node);
                if (!empty($issues)) {
                    return new Violation(
                        'api-response-issues',
                        'API response issues: ' . implode(', ', $issues),
                        '', // filePath will be set by analyzer
                        $node->getLine(),
                        0, // column
                        'warning'
                    );
                }
            }
        }

        return null;
    }

    /**
     * Check if node has @api annotation
     */
    private static function hasApiAnnotation(Node $node): bool
    {
        $comments = $node->getAttribute('comments');
        if ($comments) {
            foreach ($comments as $comment) {
                if (strpos($comment->getText(), '@api') !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Validate repository pattern structure
     */
    private static function validateRepositoryPattern(Class_ $class): array
    {
        $issues = [];

        // Check if implements repository interface
        if (empty($class->implements)) {
            $issues[] = 'missing repository interface implementation';
        }

        // Check for required methods
        $nodeFinder = new NodeFinder();
        $methods = $nodeFinder->findInstanceOf($class, ClassMethod::class);
        
        $methodNames = array_map(function($method) {
            return $method->name->toString();
        }, $methods);

        $requiredMethods = ['get', 'getList', 'save', 'delete'];
        foreach ($requiredMethods as $method) {
            if (!in_array($method, $methodNames)) {
                $issues[] = "missing required method: $method";
            }
        }

        // Check for proper exception handling
        foreach ($methods as $method) {
            if (!self::hasProperExceptionHandling($method)) {
                $issues[] = "improper exception handling in {$method->name}";
            }
        }

        return $issues;
    }

    /**
     * Validate data interface structure
     */
    private static function validateDataInterface(Class_ $class): array
    {
        $issues = [];

        // Check if implements data interface
        if (empty($class->implements)) {
            $issues[] = 'missing data interface implementation';
        }

        // Check for proper getter/setter methods
        $nodeFinder = new NodeFinder();
        $methods = $nodeFinder->findInstanceOf($class, ClassMethod::class);
        
        $hasGetters = false;
        $hasSetters = false;

        foreach ($methods as $method) {
            $methodName = $method->name->toString();
            if (strpos($methodName, 'get') === 0) {
                $hasGetters = true;
            }
            if (strpos($methodName, 'set') === 0) {
                $hasSetters = true;
            }
        }

        if (!$hasGetters) {
            $issues[] = 'missing getter methods';
        }
        if (!$hasSetters) {
            $issues[] = 'missing setter methods';
        }

        return $issues;
    }

    /**
     * Validate extension attributes structure
     */
    private static function validateExtensionAttributes(Interface_ $interface): array
    {
        $issues = [];

        // Check if extends proper interface
        if (empty($interface->extends)) {
            $issues[] = 'missing extension interface extension';
        }

        // Check for proper method naming
        $nodeFinder = new NodeFinder();
        $methods = $nodeFinder->findInstanceOf($interface, ClassMethod::class);
        
        foreach ($methods as $method) {
            $methodName = $method->name->toString();
            if (!preg_match('/^(get|set)[A-Z][a-zA-Z0-9]*$/', $methodName)) {
                $issues[] = "improper method naming: $methodName";
            }
        }

        return $issues;
    }

    /**
     * Validate WebAPI XML configuration
     */
    private static function validateWebApiXml(string $content): array
    {
        $issues = [];

        // Check for required tags
        if (strpos($content, '<route') === false) {
            $issues[] = 'missing route definitions';
        }

        if (strpos($content, 'url=') === false) {
            $issues[] = 'missing URL definitions';
        }

        if (strpos($content, 'method=') === false) {
            $issues[] = 'missing HTTP method definitions';
        }

        if (strpos($content, 'service=') === false) {
            $issues[] = 'missing service class references';
        }

        return $issues;
    }

    /**
     * Validate API response structure
     */
    private static function validateApiResponse(ClassMethod $method): array
    {
        $issues = [];

        // Check return type
        if (!$method->returnType) {
            $issues[] = 'missing return type declaration';
        }

        // Check for proper exception handling
        if (!self::hasProperExceptionHandling($method)) {
            $issues[] = 'missing proper exception handling';
        }

        return $issues;
    }

    /**
     * Check if method has proper exception handling
     */
    private static function hasProperExceptionHandling(ClassMethod $method): bool
    {
        $nodeFinder = new NodeFinder();
        $tryCatch = $nodeFinder->findInstanceOf($method, Node\Stmt\TryCatch::class);
        
        return !empty($tryCatch);
    }
} 