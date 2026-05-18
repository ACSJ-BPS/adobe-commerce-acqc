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
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeFinder;

/**
 * Magento 2 Coding Standards Rules
 */
class CodingStandardsRules
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
     * Rule: Service contract violations
     */
    public static function detectServiceContractViolations(Node $node, string $filePath = ''): ?Violation
    {
        if ($node instanceof Class_) {
            $className = $node->name?->toString() ?? '';
            
            if (strpos($className, 'Repository') !== false) {
                $issues = self::validateServiceContract($node);
                if (!empty($issues)) {
                    return new Violation(
                        'service-contract-violations',
                        'Service contract issues: ' . implode(', ', $issues),
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
    public static function detectHardcodedPaths(Node $node, string $filePath = ''): ?Violation
    {
        if ($node instanceof Node\Scalar\String_) {
            $value = $node->value;
            
            // Check for hardcoded frontend paths
            if (preg_match('/^(frontend|adminhtml)\/[a-zA-Z0-9\/_-]+$/', $value)) {
                return new Violation(
                    'hardcoded-frontend-path',
                    'Hardcoded frontend path detected: ' . $value,
                    $filePath,
                    $node->getLine(),
                    0,
                    'warning',
                    'style'
                );
            }

            // Check for hardcoded URLs
            if (preg_match('/^https?:\/\/[^\s]+$/', $value)) {
                return new Violation(
                    'hardcoded-url',
                    'Hardcoded URL detected: ' . $value,
                    $filePath,
                    $node->getLine(),
                    0,
                    'warning',
                    'style'
                );
            }
        }

        return null;
    }

    /**
     * Rule: Improper use of constants
     */
    public static function detectConstantIssues(Node $node, string $filePath = ''): ?Violation
    {
        if ($node instanceof Node\Stmt\ClassConst) {
            $constName = $node->consts[0]->name->toString();
            
            // Check for magic numbers or strings
            if (is_numeric($constName) || strlen($constName) < 3) {
                return new Violation(
                    'improper-constant-naming',
                    'Improper constant naming: ' . $constName,
                    $filePath,
                    $node->getLine(),
                    0,
                    'warning',
                    'style'
                );
            }
        }

        return null;
    }

    /**
     * Rule: Improper exception handling
     */
    public static function detectExceptionIssues(Node $node): ?Violation
    {
        if ($node instanceof Node\Stmt\TryCatch) {
            $issues = self::validateExceptionHandling($node);
            if (!empty($issues)) {
                return new Violation(
                    'exception-handling-issues',
                    'Exception handling issues: ' . implode(', ', $issues),
                    '',
                    $node->getLine(),
                    0,
                    'warning',
                    'style'
                );
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
     * Validate service contract structure
     */
    private static function validateServiceContract(Class_ $class): array
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

        return $issues;
    }

    /**
     * Validate exception handling
     */
    private static function validateExceptionHandling(Node\Stmt\TryCatch $node): array
    {
        $issues = [];

        // Check for generic exception catching
        foreach ($node->catches as $catch) {
            foreach ($catch->types as $type) {
                if ($type->toString() === 'Exception') {
                    $issues[] = 'catching generic Exception';
                }
            }
        }

        // Check for empty catch blocks
        foreach ($node->catches as $catch) {
            if (empty($catch->stmts)) {
                $issues[] = 'empty catch block';
            }
        }

        return $issues;
    }
} 