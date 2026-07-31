<?php

declare(strict_types=1);

namespace ACQC\Rules\Magento;

use ACQC\Core\Rules\Rule;
use ACQC\Core\Rules\Violation;
use PhpParser\Node;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;

/**
 * Magento 2 Business Logic Rules
 */
class BusinessLogicRules
{
    private const HARDCODED_CURRENCIES = ['USD', 'EUR', 'GBP', 'CAD', 'AUD'];
    private const HARDCODED_STORE_CODES = ['default', 'admin', 'default_store_view'];

    /**
     * Rule: Hardcoded currency codes
     */
    public static function detectHardcodedCurrencyCodes(Node $node, string $filePath): ?Violation
    {
        if ($node instanceof String_) {
            $value = $node->value;

            if (in_array($value, self::HARDCODED_CURRENCIES)) {
                $violation = new Violation(
                    'hardcoded-currency',
                    'Hardcoded currency code detected: ' . $value,
                    $filePath,
                    $node->getLine(),
                    0,
                    'warning'
                );
                $violation->setSuggestion('Use configuration or context to get currency dynamically.');
                $violation->addMetadata('suggestions', [
                    'Use: `$this->storeManager->getStore()->getCurrentCurrencyCode()`',
                    'Use: `$this->currencyFactory->create()->load($code)`',
                    'Use: `$this->config->getValue(\'currency/options/base\')`',
                    'Avoid hardcoded currency codes for multi-currency support'
                ]);

                return $violation;
            }
        }

        return null;
    }

    /**
     * Rule: Missing multi-store compatibility
     */
    public static function detectMissingMultiStoreSupport(Node $node, string $filePath): ?Violation
    {
        if ($node instanceof ClassMethod) {
            $methodName = $node->name->toString();

            if (self::isStoreSpecificMethod($methodName)) {
                if (!self::hasMultiStoreSupport($node)) {
                    $violation = new Violation(
                        'missing-multi-store-support',
                        'Missing multi-store compatibility in method: ' . $methodName,
                        $filePath,
                        $node->getLine(),
                        0,
                        'warning'
                    );
                    $violation->setSuggestion('Implement multi-store compatibility for business logic.');
                    $violation->addMetadata('suggestions', [
                        'Use: `$this->storeManager->getStore()`',
                        'Use: `$this->storeManager->getStores()`',
                        'Check store context in business logic',
                        'Use store-specific configuration'
                    ]);

                    return $violation;
                }
            }
        }

        return null;
    }

    /**
     * Rule: Improper price calculation methods
     */
    public static function detectPriceCalculationIssues(Node $node, string $filePath): ?Violation
    {
        if ($node instanceof ClassMethod) {
            $methodName = $node->name->toString();

            if (self::isPriceCalculationMethod($methodName)) {
                $issues = self::validatePriceCalculation($node);
                if (!empty($issues)) {
                    $violation = new Violation(
                        'price-calculation-issues',
                        'Price calculation issues: ' . implode(', ', $issues),
                        $filePath,
                        $node->getLine(),
                        0,
                        'error'
                    );
                    $violation->setSuggestion('Implement proper price calculation logic.');
                    $violation->addMetadata('suggestions', [
                        'Use: `$this->priceCurrency->convert()`',
                        'Use: `$this->taxCalculation->calculateTax()`',
                        'Consider tax rules and discounts',
                        'Use proper rounding methods'
                    ]);

                    return $violation;
                }
            }
        }

        return null;
    }

    /**
     * Rule: Tax calculation rule violations
     */
    public static function detectTaxCalculationIssues(Node $node, string $filePath): ?Violation
    {
        if ($node instanceof ClassMethod) {
            $methodName = $node->name->toString();

            if (self::isTaxCalculationMethod($methodName)) {
                if (!self::hasProperTaxCalculation($node)) {
                    $violation = new Violation(
                        'tax-calculation-issues',
                        'Improper tax calculation in method: ' . $methodName,
                        $filePath,
                        $node->getLine(),
                        0,
                        'error'
                    );
                    $violation->setSuggestion('Implement proper tax calculation logic.');
                    $violation->addMetadata('suggestions', [
                        'Use: `$this->taxCalculation->calculateTax()`',
                        'Use: `$this->taxConfig->getTaxClass()`',
                        'Consider tax rules and rates',
                        'Use proper tax rounding'
                    ]);

                    return $violation;
                }
            }
        }

        return null;
    }

    /**
     * Rule: Missing event dispatching for extensibility
     */
    public static function detectMissingEventDispatching(Node $node, string $filePath): ?Violation
    {
        if ($node instanceof ClassMethod) {
            $methodName = $node->name->toString();

            if (self::isBusinessLogicMethod($methodName)) {
                if (!self::hasEventDispatching($node)) {
                    $violation = new Violation(
                        'missing-event-dispatching',
                        'Missing event dispatching in business logic: ' . $methodName,
                        $filePath,
                        $node->getLine(),
                        0,
                        'warning'
                    );
                    $violation->setSuggestion('Dispatch events for extensibility in business logic.');
                    $violation->addMetadata('suggestions', [
                        'Use: `$this->eventManager->dispatch()`',
                        'Dispatch before and after events',
                        'Pass relevant data in event',
                        'Follow Magento 2 event naming conventions'
                    ]);

                    return $violation;
                }
            }
        }

        return null;
    }

    /**
     * Rule: Observer implementation violations
     */
    public static function detectObserverIssues(string $filePath, string $content): ?Violation
    {
        if (strpos($filePath, 'Observer.php') !== false) {
            $issues = self::validateObserverImplementation($content);
            if (!empty($issues)) {
                $violation = new Violation(
                    'observer-implementation-issues',
                    'Observer implementation issues: ' . implode(', ', $issues),
                    $filePath,
                    1,
                    0,
                    'error'
                );
                $violation->setSuggestion('Implement observers following Magento 2 patterns.');
                $violation->addMetadata('suggestions', [
                    'Extend: `Magento\Framework\Event\ObserverInterface`',
                    'Implement: `execute()` method',
                    'Use proper parameter injection',
                    'Follow observer naming conventions'
                ]);

                return $violation;
            }
        }

        return null;
    }

    /**
     * Rule: Plugin method signature mismatches
     */
    public static function detectPluginSignatureIssues(Node $node, string $filePath): ?Violation
    {
        if ($node instanceof ClassMethod) {
            $methodName = $node->name->toString();

            if (self::isPluginMethod($methodName)) {
                if (!self::hasProperPluginSignature($node)) {
                    $violation = new Violation(
                        'plugin-signature-mismatch',
                        'Plugin method signature mismatch: ' . $methodName,
                        $filePath,
                        $node->getLine(),
                        0,
                        'error'
                    );
                    $violation->setSuggestion('Plugin methods should match original method signatures.');
                    $violation->addMetadata('suggestions', [
                        'Use: `beforeMethod($subject, ...$args)`',
                        'Use: `aroundMethod($subject, $proceed, ...$args)`',
                        'Use: `afterMethod($subject, $result, ...$args)`',
                        'Match parameter types and return types'
                    ]);

                    return $violation;
                }
            }
        }

        return null;
    }

    /**
     * Rule: Missing integration test coverage for critical paths
     */
    public static function detectMissingIntegrationTests(string $filePath, string $content): ?Violation
    {
        if (strpos($filePath, 'Test.php') !== false && strpos($filePath, 'Integration') !== false) {
            $issues = self::validateIntegrationTests($content);
            if (!empty($issues)) {
                $violation = new Violation(
                    'missing-integration-tests',
                    'Integration test issues: ' . implode(', ', $issues),
                    $filePath,
                    1,
                    0,
                    'warning'
                );
                $violation->setSuggestion('Implement comprehensive integration tests for critical paths.');
                $violation->addMetadata('suggestions', [
                    'Test complete user workflows',
                    'Test database interactions',
                    'Test external service integrations',
                    'Test error scenarios'
                ]);

                return $violation;
            }
        }

        return null;
    }

    /**
     * Rule: Improper mock usage in unit tests
     */
    public static function detectMockIssues(string $filePath, string $content): ?Violation
    {
        if (strpos($filePath, 'Test.php') !== false) {
            $issues = self::validateMockUsage($content);
            if (!empty($issues)) {
                $violation = new Violation(
                    'improper-mock-usage',
                    'Mock usage issues: ' . implode(', ', $issues),
                    $filePath,
                    1,
                    0,
                    'warning'
                );
                $violation->setSuggestion('Use proper mocking techniques in unit tests.');
                $violation->addMetadata('suggestions', [
                    'Use: `$this->createMock()`',
                    'Use: `$this->getMockBuilder()`',
                    'Mock only external dependencies',
                    'Use proper mock expectations'
                ]);

                return $violation;
            }
        }

        return null;
    }

    /**
     * Check if method is store-specific
     */
    private static function isStoreSpecificMethod(string $methodName): bool
    {
        $storePatterns = [
            '/store/',
            '/website/',
            '/currency/',
            '/locale/',
            '/price/',
            '/tax/'
        ];

        foreach ($storePatterns as $pattern) {
            if (preg_match($pattern, strtolower($methodName))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if method has multi-store support
     */
    private static function hasMultiStoreSupport(ClassMethod $method): bool
    {
        $nodeFinder = new NodeFinder();
        $storeCalls = $nodeFinder->find($method, function(Node $node) {
            if ($node instanceof MethodCall) {
                $methodName = $node->name->toString();
                return in_array($methodName, ['getStore', 'getStores', 'getCurrentStore']);
            }
            return false;
        });

        return !empty($storeCalls);
    }

    /**
     * Check if method is price calculation
     */
    private static function isPriceCalculationMethod(string $methodName): bool
    {
        $pricePatterns = [
            '/price/',
            '/calculate/',
            '/total/',
            '/amount/',
            '/cost/'
        ];

        foreach ($pricePatterns as $pattern) {
            if (preg_match($pattern, strtolower($methodName))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate price calculation
     */
    private static function validatePriceCalculation(ClassMethod $method): array
    {
        $issues = [];
        $nodeFinder = new NodeFinder();
        
        // Check for hardcoded calculations
        $hardcodedValues = $nodeFinder->find($method, function(Node $node) {
            return $node instanceof \PhpParser\Node\Scalar\LNumber;
        });

        if (count($hardcodedValues) > 5) {
            $issues[] = 'Too many hardcoded values';
        }

        // Check for proper currency handling
        $currencyCalls = $nodeFinder->find($method, function(Node $node) {
            if ($node instanceof MethodCall) {
                $methodName = $node->name->toString();
                return in_array($methodName, ['convert', 'format', 'getRate']);
            }
            return false;
        });

        if (empty($currencyCalls)) {
            $issues[] = 'Missing currency conversion';
        }

        return $issues;
    }

    /**
     * Check if method is tax calculation
     */
    private static function isTaxCalculationMethod(string $methodName): bool
    {
        return strpos(strtolower($methodName), 'tax') !== false;
    }

    /**
     * Check if method has proper tax calculation
     */
    private static function hasProperTaxCalculation(ClassMethod $method): bool
    {
        $nodeFinder = new NodeFinder();
        $taxCalls = $nodeFinder->find($method, function(Node $node) {
            if ($node instanceof MethodCall) {
                $methodName = $node->name->toString();
                return in_array($methodName, ['calculateTax', 'getTaxRate', 'getTaxClass']);
            }
            return false;
        });

        return !empty($taxCalls);
    }

    /**
     * Check if method is business logic
     */
    private static function isBusinessLogicMethod(string $methodName): bool
    {
        $businessPatterns = [
            '/save/',
            '/delete/',
            '/create/',
            '/update/',
            '/process/',
            '/calculate/',
            '/validate/'
        ];

        foreach ($businessPatterns as $pattern) {
            if (preg_match($pattern, strtolower($methodName))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if method has event dispatching
     */
    private static function hasEventDispatching(ClassMethod $method): bool
    {
        $nodeFinder = new NodeFinder();
        $eventCalls = $nodeFinder->find($method, function(Node $node) {
            if ($node instanceof MethodCall) {
                $methodName = $node->name->toString();
                return $methodName === 'dispatch';
            }
            return false;
        });

        return !empty($eventCalls);
    }

    /**
     * Validate observer implementation
     */
    private static function validateObserverImplementation(string $content): array
    {
        $issues = [];

        // Check for proper interface implementation
        if (!preg_match('/implements.*ObserverInterface/', $content)) {
            $issues[] = 'Missing ObserverInterface implementation';
        }

        // Check for execute method
        if (!preg_match('/public function execute/', $content)) {
            $issues[] = 'Missing execute() method';
        }

        // Check for proper parameter injection
        if (!preg_match('/Observer \$observer/', $content)) {
            $issues[] = 'Missing Observer parameter';
        }

        return $issues;
    }

    /**
     * Check if method is plugin method
     */
    private static function isPluginMethod(string $methodName): bool
    {
        return strpos($methodName, 'before') === 0 || 
               strpos($methodName, 'around') === 0 || 
               strpos($methodName, 'after') === 0;
    }

    /**
     * Check if method has proper plugin signature
     */
    private static function hasProperPluginSignature(ClassMethod $method): bool
    {
        $params = $method->params;
        
        if (empty($params)) {
            return false;
        }

        $firstParam = $params[0];
        return $firstParam->type && $firstParam->type->toString() === 'object';
    }

    /**
     * Validate integration tests
     */
    private static function validateIntegrationTests(string $content): array
    {
        $issues = [];

        // Check for test methods
        if (!preg_match('/public function test/', $content)) {
            $issues[] = 'Missing test methods';
        }

        // Check for database interactions
        if (!preg_match('/getObjectManager/', $content) && !preg_match('/createMock/', $content)) {
            $issues[] = 'Missing database interaction tests';
        }

        // Check for error scenarios
        if (!preg_match('/expectException/', $content)) {
            $issues[] = 'Missing error scenario tests';
        }

        return $issues;
    }

    /**
     * Validate mock usage
     */
    private static function validateMockUsage(string $content): array
    {
        $issues = [];

        // Check for proper mock creation
        if (!preg_match('/createMock\(/', $content) && !preg_match('/getMockBuilder\(/', $content)) {
            $issues[] = 'Missing proper mock creation';
        }

        // Check for mock expectations
        if (!preg_match('/expects\(/', $content) && !preg_match('/willReturn\(/', $content)) {
            $issues[] = 'Missing mock expectations';
        }

        return $issues;
    }
} 