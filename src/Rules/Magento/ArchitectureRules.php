<?php

declare(strict_types=1);

namespace ACQC\Rules\Magento;

use ACQC\Core\Rules\Rule;
use ACQC\Core\Rules\Violation;
use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeFinder;

/**
 * Magento 2 Architecture & Framework Rules
 */
class ArchitectureRules
{
    private const OBJECT_MANAGER_CLASS = 'Magento\Framework\ObjectManager\ObjectManager';
    private const FORBIDDEN_CLASSES = [
        'Magento\Framework\ObjectManager\ObjectManager',
        'Magento\Framework\App\ObjectManager'
    ];

    /**
     * Rule: Direct ObjectManager usage detection
     */
    public static function detectDirectObjectManagerUsage(Node $node, string $filePath = ''): ?Violation
    {
        if ($node instanceof StaticCall) {
            if ($node->class instanceof Name) {
                $className = $node->class->toString();
                if (in_array($className, self::FORBIDDEN_CLASSES)) {
                    return new Violation(
                        'direct-objectmanager-usage',
                        'Direct ObjectManager usage detected. Use dependency injection instead.',
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
     * Rule: Factory class instantiation without proper DI
     */
    public static function detectFactoryWithoutDI(Node $node, string $filePath = ''): ?Violation
    {
        if ($node instanceof New_) {
            if ($node->class instanceof Name) {
                $className = $node->class->toString();
                if (strpos($className, 'Factory') !== false && !self::isInjected($node)) {
                    return new Violation(
                        'factory-without-di',
                        'Factory class instantiated without dependency injection.',
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
     * Rule: Plugin/Interceptor implementation violations
     */
    public static function detectPluginViolations(Node $node, string $filePath = ''): ?Violation
    {
        if ($node instanceof Class_) {
            $className = $node->name?->toString() ?? '';
            
            // Check for plugin naming convention
            if (strpos($className, 'Plugin') !== false) {
                if (!self::hasPluginMethods($node)) {
                    return new Violation(
                        'plugin-missing-methods',
                        'Plugin class missing required plugin methods.',
                        $filePath,
                        $node->getLine(),
                        0,
                        'error',
                        'style'
                    );
                }
            }

            // Check for interceptor naming convention
            if (strpos($className, 'Interceptor') !== false) {
                if (!self::isGeneratedClass($node)) {
                    return new Violation(
                        'manual-interceptor',
                        'Manual interceptor class detected. Interceptors should be generated automatically.',
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
     * Rule: Preference configuration conflicts
     */
    public static function detectPreferenceConflicts(Node $node, string $filePath = ''): ?Violation
    {
        if ($node instanceof Class_) {
            $className = $node->name?->toString() ?? '';
            
            // Check for preference naming conflicts
            if (self::hasPreferenceNaming($className)) {
                if (!self::hasProperPreferenceStructure($node)) {
                    return new Violation(
                        'preference-structure-violation',
                        'Preference class missing proper structure or inheritance.',
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
     * Check if a node is injected through DI
     */
    private static function isInjected(Node $node): bool
    {
        // Check if the node is in a constructor parameter or method parameter
        $parent = $node->getAttribute('parent');
        if ($parent instanceof Node\Param) {
            return true;
        }

        // Check if it's assigned to a property that's injected
        if ($parent instanceof Node\Expr\Assign) {
            if ($parent->var instanceof Node\Expr\PropertyFetch) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if class has plugin methods
     */
    private static function hasPluginMethods(Class_ $class): bool
    {
        $nodeFinder = new NodeFinder();
        $methods = $nodeFinder->findInstanceOf($class, Node\Stmt\ClassMethod::class);

        foreach ($methods as $method) {
            $methodName = $method->name->toString();
            if (strpos($methodName, 'before') === 0 || 
                strpos($methodName, 'around') === 0 || 
                strpos($methodName, 'after') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if class is generated
     */
    private static function isGeneratedClass(Class_ $class): bool
    {
        // Check for generated class indicators
        $comments = $class->getAttribute('comments');
        if ($comments) {
            foreach ($comments as $comment) {
                if (strpos($comment->getText(), '@generated') !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if class has preference naming
     */
    private static function hasPreferenceNaming(string $className): bool
    {
        return strpos($className, 'Preference') !== false || 
               strpos($className, 'Override') !== false;
    }

    /**
     * Check if preference has proper structure
     */
    private static function hasProperPreferenceStructure(Class_ $class): bool
    {
        // Check if class extends or implements something
        if ($class->extends || !empty($class->implements)) {
            return true;
        }

        return false;
    }
} 