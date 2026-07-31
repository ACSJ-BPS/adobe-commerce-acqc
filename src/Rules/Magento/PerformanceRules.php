<?php

declare(strict_types=1);

namespace ACQC\Rules\Magento;

use ACQC\Core\Rules\Rule;
use ACQC\Core\Rules\Violation;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\While_;
use PhpParser\Node\Stmt\Do_;
use PhpParser\NodeFinder;

/**
 * Magento 2 Performance Rules
 */
class PerformanceRules
{
    private const COLLECTION_METHODS = [
        'load',
        'getItems',
        'getFirstItem',
        'getLastItem',
        'getItemById'
    ];

    /**
     * Rule: Collection loading in loops
     */
    public static function detectCollectionInLoops(Node $node, string $filePath): ?Violation
    {
        if ($node instanceof MethodCall) {
            $methodName = $node->name->toString();

            if (in_array($methodName, self::COLLECTION_METHODS)) {
                $parent = $node->getAttribute('parent');
                if (self::isInLoop($parent)) {
                    $violation = new Violation(
                        'collection-in-loop',
                        'Collection loading detected in loop.',
                        $filePath,
                        $node->getLine(),
                        0,
                        'error'
                    );
                    $violation->setSuggestion('Avoid loading collections inside loops to prevent N+1 queries.');
                    $violation->addMetadata('suggestions', [
                        'Load collection before the loop',
                        'Use: `$collection->load()` once before loop',
                        'Use: `$collection->getItems()` to get all items',
                        'Consider using batch processing'
                    ]);

                    return $violation;
                }
            }
        }

        return null;
    }

    /**
     * Rule: Missing proper indexing usage
     */
    public static function detectMissingIndexing(Node $node, string $filePath): ?Violation
    {
        if ($node instanceof MethodCall) {
            $methodName = $node->name->toString();

            if (in_array($methodName, ['addFieldToFilter', 'addAttributeToFilter'])) {
                if (!self::hasProperIndexing($node)) {
                    $violation = new Violation(
                        'missing-indexing',
                        'Missing proper indexing for filter: ' . $methodName,
                        $filePath,
                        $node->getLine(),
                        0,
                        'warning'
                    );
                    $violation->setSuggestion('Ensure proper database indexing for filtered fields.');
                    $violation->addMetadata('suggestions', [
                        'Add database indexes for filtered fields',
                        'Use: `db_schema.xml` to define indexes',
                        'Consider composite indexes for multiple filters',
                        'Monitor query performance with EXPLAIN'
                    ]);

                    return $violation;
                }
            }
        }

        return null;
    }

    /**
     * Rule: Inefficient repository pattern usage
     */
    public static function detectInefficientRepositoryUsage(Node $node, string $filePath): ?Violation
    {
        if ($node instanceof MethodCall) {
            $methodName = $node->name->toString();

            if (in_array($methodName, ['getById', 'getList'])) {
                if (self::isInefficientRepositoryCall($node)) {
                    $violation = new Violation(
                        'inefficient-repository-usage',
                        'Inefficient repository usage detected.',
                        $filePath,
                        $node->getLine(),
                        0,
                        'warning'
                    );
                    $violation->setSuggestion('Optimize repository calls for better performance.');
                    $violation->addMetadata('suggestions', [
                        'Use: `getList()` with filters instead of multiple `getById()`',
                        'Implement proper caching for repository calls',
                        'Use batch operations when possible',
                        'Consider using collections for complex queries'
                    ]);

                    return $violation;
                }
            }
        }

        return null;
    }

    /**
     * Rule: Missing caching implementation where required
     */
    public static function detectMissingCaching(Node $node, string $filePath): ?Violation
    {
        if ($node instanceof MethodCall) {
            $methodName = $node->name->toString();

            if (in_array($methodName, ['getData', 'load', 'getItems'])) {
                if (!self::hasCaching($node)) {
                    $violation = new Violation(
                        'missing-caching',
                        'Missing caching for expensive operation: ' . $methodName,
                        $filePath,
                        $node->getLine(),
                        0,
                        'warning'
                    );
                    $violation->setSuggestion('Implement caching for expensive operations.');
                    $violation->addMetadata('suggestions', [
                        'Use: `$this->cache->load()` and `$this->cache->save()`',
                        'Use: `$this->cacheManager->get()`',
                        'Implement proper cache tags',
                        'Set appropriate cache lifetime'
                    ]);

                    return $violation;
                }
            }
        }

        return null;
    }

    /**
     * Rule: Inefficient database queries
     */
    public static function detectInefficientQueries(Node $node, string $filePath): ?Violation
    {
        if ($node instanceof MethodCall) {
            $methodName = $node->name->toString();

            if (in_array($methodName, ['query', 'fetchAll', 'fetchOne'])) {
                if (!self::isOptimizedQuery($node)) {
                    $violation = new Violation(
                        'inefficient-query',
                        'Inefficient database query detected.',
                        $filePath,
                        $node->getLine(),
                        0,
                        'error'
                    );
                    $violation->setSuggestion('Optimize database queries for better performance.');
                    $violation->addMetadata('suggestions', [
                        'Use proper WHERE clauses',
                        'Add appropriate indexes',
                        'Limit result sets',
                        'Use prepared statements'
                    ]);

                    return $violation;
                }
            }
        }

        return null;
    }

    /**
     * Rule: Memory usage optimization
     */
    public static function detectMemoryIssues(Node $node, string $filePath): ?Violation
    {
        if ($node instanceof MethodCall) {
            $methodName = $node->name->toString();

            if (in_array($methodName, ['getAllIds', 'getSize'])) {
                if (!self::hasMemoryOptimization($node)) {
                    $violation = new Violation(
                        'memory-optimization',
                        'Missing memory optimization for large datasets.',
                        $filePath,
                        $node->getLine(),
                        0,
                        'warning'
                    );
                    $violation->setSuggestion('Implement memory optimization for large operations.');
                    $violation->addMetadata('suggestions', [
                        'Use pagination for large datasets',
                        'Use: `setPageSize()` and `setCurPage()`',
                        'Implement batch processing',
                        'Use generators for large data processing'
                    ]);

                    return $violation;
                }
            }
        }

        return null;
    }

    /**
     * Rule: Missing lazy loading
     */
    public static function detectMissingLazyLoading(Node $node, string $filePath): ?Violation
    {
        if ($node instanceof MethodCall) {
            $methodName = $node->name->toString();

            if (in_array($methodName, ['load', 'getData'])) {
                if (!self::hasLazyLoading($node)) {
                    $violation = new Violation(
                        'missing-lazy-loading',
                        'Missing lazy loading implementation.',
                        $filePath,
                        $node->getLine(),
                        0,
                        'info'
                    );
                    $violation->setSuggestion('Implement lazy loading for better performance.');
                    $violation->addMetadata('suggestions', [
                        'Use: `$this->getData()` instead of loading all data',
                        'Implement lazy loading in getters',
                        'Use proxy objects for expensive operations',
                        'Consider using virtual types'
                    ]);

                    return $violation;
                }
            }
        }

        return null;
    }

    /**
     * Rule: Inefficient template loops
     */
    public static function detectTemplateLoopIssues(string $filePath, string $content): ?Violation
    {
        if (strpos($filePath, '.phtml') !== false) {
            $issues = self::validateTemplateLoops($content);
            if (!empty($issues)) {
                $violation = new Violation(
                    'template-loop-issues',
                    'Template loop issues: ' . implode(', ', $issues),
                    $filePath,
                    1,
                    0,
                    'warning'
                );
                $violation->setSuggestion('Optimize template loops for better performance.');
                $violation->addMetadata('suggestions', [
                    'Use: `$block->getItems()` instead of loading in loop',
                    'Implement proper pagination',
                    'Use lazy loading for large datasets',
                    'Consider using AJAX for dynamic content'
                ]);

                return $violation;
            }
        }

        return null;
    }

    /**
     * Check if node is in a loop
     */
    private static function isInLoop(?Node $node): bool
    {
        if (!$node) {
            return false;
        }

        if ($node instanceof For_ || 
            $node instanceof Foreach_ || 
            $node instanceof While_ || 
            $node instanceof Do_) {
            return true;
        }

        return self::isInLoop($node->getAttribute('parent'));
    }

    /**
     * Check if method has proper indexing
     */
    private static function hasProperIndexing(MethodCall $node): bool
    {
        // This would check if the filtered field has proper database indexes
        // For now, we'll assume it's missing if we can't determine
        return false;
    }

    /**
     * Check if repository call is inefficient
     */
    private static function isInefficientRepositoryCall(MethodCall $node): bool
    {
        $nodeFinder = new NodeFinder();
        $parent = $node->getAttribute('parent');
        
        // Check if this is inside a loop
        if (self::isInLoop($parent)) {
            return true;
        }

        // Check if multiple getById calls are made
        $calls = $nodeFinder->find($parent, function(Node $node) {
            if ($node instanceof MethodCall) {
                $methodName = $node->name->toString();
                return $methodName === 'getById';
            }
            return false;
        });

        return count($calls) > 1;
    }

    /**
     * Check if method has caching
     */
    private static function hasCaching(MethodCall $node): bool
    {
        $nodeFinder = new NodeFinder();
        $parent = $node->getAttribute('parent');
        
        $cacheCalls = $nodeFinder->find($parent, function(Node $node) {
            if ($node instanceof MethodCall) {
                $methodName = $node->name->toString();
                return in_array($methodName, ['load', 'save', 'get', 'set']);
            }
            return false;
        });

        return !empty($cacheCalls);
    }

    /**
     * Check if query is optimized
     */
    private static function isOptimizedQuery(MethodCall $node): bool
    {
        // Check if query has proper WHERE clauses and limits
        $args = $node->args;
        if (!empty($args)) {
            $query = $args[0]->value;
            if ($query instanceof \PhpParser\Node\Scalar\String_) {
                $sql = $query->value;
                
                // Check for basic optimization indicators
                if (strpos($sql, 'LIMIT') !== false && 
                    strpos($sql, 'WHERE') !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if method has memory optimization
     */
    private static function hasMemoryOptimization(MethodCall $node): bool
    {
        $nodeFinder = new NodeFinder();
        $parent = $node->getAttribute('parent');
        
        $optimizationCalls = $nodeFinder->find($parent, function(Node $node) {
            if ($node instanceof MethodCall) {
                $methodName = $node->name->toString();
                return in_array($methodName, ['setPageSize', 'setCurPage', 'setLimit']);
            }
            return false;
        });

        return !empty($optimizationCalls);
    }

    /**
     * Check if method has lazy loading
     */
    private static function hasLazyLoading(MethodCall $node): bool
    {
        $nodeFinder = new NodeFinder();
        $parent = $node->getAttribute('parent');
        
        $lazyCalls = $nodeFinder->find($parent, function(Node $node) {
            if ($node instanceof MethodCall) {
                $methodName = $node->name->toString();
                return in_array($methodName, ['getData', 'hasData']);
            }
            return false;
        });

        return !empty($lazyCalls);
    }

    /**
     * Validate template loops
     */
    private static function validateTemplateLoops(string $content): array
    {
        $issues = [];

        // Check for collection loading in loops
        if (preg_match('/foreach.*getItems\(\)/', $content)) {
            $issues[] = 'Collection loading in template loop';
        }

        // Check for database queries in loops
        if (preg_match('/foreach.*query\(/', $content)) {
            $issues[] = 'Database query in template loop';
        }

        // Check for missing pagination
        if (preg_match('/foreach.*getItems\(\)/', $content) && !preg_match('/setPageSize/', $content)) {
            $issues[] = 'Missing pagination for large datasets';
        }

        return $issues;
    }
} 