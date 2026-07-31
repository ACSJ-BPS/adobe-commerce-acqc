<?php

declare(strict_types=1);

namespace ACQC\Rules\Magento;

use ACQC\Core\Rules\Rule;
use ACQC\Core\Rules\Violation;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;

/**
 * Magento 2 Security Rules
 */
class SecurityRules
{
    private const CSRF_ACTIONS = [
        'save',
        'delete',
        'update',
        'create',
        'modify',
        'remove',
        'add'
    ];

    private const ADMIN_ROUTES = [
        'admin',
        'adminhtml',
        'backend'
    ];

    /**
     * Rule: Missing CSRF protection in forms
     */
    public static function detectMissingCsrfProtection(Node $node, string $filePath): ?Violation
    {
        if ($node instanceof ClassMethod) {
            $methodName = $node->name->toString();

            if (self::isCsrfAction($methodName)) {
                if (!self::hasCsrfProtection($node)) {
                    $violation = new Violation(
                        'missing-csrf-protection',
                        'Missing CSRF protection in action: ' . $methodName,
                        $filePath,
                        $node->getLine(),
                        0,
                        'critical'
                    );
                    $violation->setSuggestion('Implement CSRF protection for form submissions.');
                    $violation->addMetadata('suggestions', [
                        'Use: `$this->_isAllowed()`',
                        'Use: `$this->getRequest()->isPost()`',
                        'Use: `$this->validateFormKey()`',
                        'Include form key in templates: `<?= $block->getFormKey() ?>`'
                    ]);

                    return $violation;
                }
            }
        }

        return null;
    }

    /**
     * Rule: Improper admin route definitions
     */
    public static function detectAdminRouteIssues(string $filePath, string $content): ?Violation
    {
        if (strpos($filePath, 'routes.xml') !== false) {
            $issues = self::validateAdminRoutes($content);
            if (!empty($issues)) {
                $violation = new Violation(
                    'improper-admin-routes',
                    'Admin route issues: ' . implode(', ', $issues),
                    $filePath,
                    1,
                    0,
                    'error'
                );
                $violation->setSuggestion('Admin routes should have proper security configuration.');
                $violation->addMetadata('suggestions', [
                    'Include proper <admin> route configuration',
                    'Use proper ACL resources',
                    'Include proper security settings',
                    'Follow Magento 2 admin route conventions'
                ]);

                return $violation;
            }
        }

        return null;
    }

    /**
     * Rule: Missing authorization checks in controllers
     */
    public static function detectMissingAuthorization(Node $node, string $filePath): ?Violation
    {
        if ($node instanceof ClassMethod) {
            $methodName = $node->name->toString();

            if (self::isAdminAction($methodName)) {
                if (!self::hasAuthorizationCheck($node)) {
                    $violation = new Violation(
                        'missing-authorization',
                        'Missing authorization check in admin action: ' . $methodName,
                        $filePath,
                        $node->getLine(),
                        0,
                        'critical'
                    );
                    $violation->setSuggestion('Implement proper authorization checks for admin actions.');
                    $violation->addMetadata('suggestions', [
                        'Use: `$this->_isAllowed()`',
                        'Use: `$this->authorization->isAllowed()`',
                        'Check specific ACL resources',
                        'Implement proper role-based access control'
                    ]);

                    return $violation;
                }
            }
        }

        return null;
    }

    /**
     * Rule: Unsafe data output without escaping functions
     */
    public static function detectUnsafeOutput(Node $node, string $filePath): ?Violation
    {
        if ($node instanceof FuncCall) {
            if ($node->name instanceof \PhpParser\Node\Name) {
                $functionName = $node->name->toString();
                
                if (in_array($functionName, ['echo', 'print', 'printf'])) {
                    $args = $node->args;
                    if (!empty($args)) {
                        $firstArg = $args[0]->value;
                        
                        // Skip if it's a safe type (integer, float, boolean, null)
                        if (self::isSafeType($firstArg)) {
                            return null;
                        }
                        
                        // Skip if it's a literal string (not a variable)
                        if ($firstArg instanceof \PhpParser\Node\Scalar\String_) {
                            return null;
                        }
                        
                        // Check if escaped
                        if (!self::isEscaped($firstArg)) {
                            $violation = new Violation(
                                'unsafe-output',
                                'Unsafe output detected without escaping.',
                                $filePath,
                                $node->getLine(),
                                0,
                                'error'
                            );
                            $violation->setSuggestion('Use proper escaping functions for output.');
                            $violation->addMetadata('suggestions', [
                                'Use: `escapeHtml()` for HTML content',
                                'Use: `escapeHtmlAttr()` for HTML attributes',
                                'Use: `escapeUrl()` for URLs',
                                'Use: `escapeCss()` for CSS values'
                            ]);

                            return $violation;
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Rule: Missing input validation
     */
    public static function detectMissingInputValidation(Node $node, string $filePath): ?Violation
    {
        if ($node instanceof MethodCall) {
            $methodName = $node->name->toString();

            if (in_array($methodName, ['getParam', 'getPost', 'getQuery'])) {
                if (!self::hasInputValidation($node)) {
                    $violation = new Violation(
                        'missing-input-validation',
                        'Missing input validation for: ' . $methodName,
                        $filePath,
                        $node->getLine(),
                        0,
                        'error'
                    );
                    $violation->setSuggestion('Validate all user inputs before processing.');
                    $violation->addMetadata('suggestions', [
                        'Use: `$this->getRequest()->getParam()` with validation',
                        'Use: `filter_var()` for data sanitization',
                        'Use: `preg_match()` for pattern validation',
                        'Implement proper input validation rules'
                    ]);

                    return $violation;
                }
            }
        }

        return null;
    }

    /**
     * Rule: Improper session management
     */
    public static function detectSessionIssues(Node $node, string $filePath): ?Violation
    {
        if ($node instanceof MethodCall) {
            $methodName = $node->name->toString();

            if (in_array($methodName, ['setData', 'getData', 'unsetData'])) {
                $var = $node->var;
                if ($var instanceof \PhpParser\Node\Expr\PropertyFetch) {
                    $propertyName = $var->name->toString();
                    if ($propertyName === 'session') {
                        if (!self::hasProperSessionHandling($node)) {
                            $violation = new Violation(
                                'improper-session-handling',
                                'Improper session data handling.',
                                $filePath,
                                $node->getLine(),
                                0,
                                'warning'
                            );
                            $violation->setSuggestion('Use proper session management practices.');
                            $violation->addMetadata('suggestions', [
                                'Use: `$this->session->setData()` with proper scope',
                                'Use: `$this->session->getData()` with validation',
                                'Implement session timeout',
                                'Use proper session security settings'
                            ]);

                            return $violation;
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Rule: Missing security headers
     */
    public static function detectMissingSecurityHeaders(string $filePath, string $content): ?Violation
    {
        if (strpos($filePath, 'web.xml') !== false || strpos($filePath, 'htaccess') !== false) {
            if (!self::hasSecurityHeaders($content)) {
                $violation = new Violation(
                    'missing-security-headers',
                    'Missing security headers configuration.',
                    $filePath,
                    1,
                    0,
                    'warning'
                );
                $violation->setSuggestion('Implement security headers for enhanced protection.');
                $violation->addMetadata('suggestions', [
                    'Add X-Frame-Options header',
                    'Add X-Content-Type-Options header',
                    'Add X-XSS-Protection header',
                    'Add Content-Security-Policy header'
                ]);

                return $violation;
            }
        }

        return null;
    }

    /**
     * Rule: Improper file upload handling
     */
    public static function detectFileUploadIssues(Node $node, string $filePath): ?Violation
    {
        if ($node instanceof MethodCall) {
            $methodName = $node->name->toString();

            if (in_array($methodName, ['upload', 'save', 'move'])) {
                if (!self::hasFileUploadSecurity($node)) {
                    $violation = new Violation(
                        'improper-file-upload',
                        'Improper file upload handling.',
                        $filePath,
                        $node->getLine(),
                        0,
                        'critical'
                    );
                    $violation->setSuggestion('Implement proper file upload security.');
                    $violation->addMetadata('suggestions', [
                        'Validate file types and extensions',
                        'Check file size limits',
                        'Scan for malware',
                        'Use secure file storage location'
                    ]);

                    return $violation;
                }
            }
        }

        return null;
    }

    /**
     * Check if method is CSRF action
     */
    private static function isCsrfAction(string $methodName): bool
    {
        foreach (self::CSRF_ACTIONS as $action) {
            if (strpos(strtolower($methodName), $action) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if method has CSRF protection
     */
    private static function hasCsrfProtection(ClassMethod $method): bool
    {
        $nodeFinder = new NodeFinder();
        $calls = $nodeFinder->find($method, function(Node $node) {
            if ($node instanceof MethodCall) {
                $methodName = $node->name->toString();
                return in_array($methodName, ['_isAllowed', 'validateFormKey', 'isPost']);
            }
            return false;
        });

        return !empty($calls);
    }

    /**
     * Validate admin routes
     */
    private static function validateAdminRoutes(string $content): array
    {
        $issues = [];

        // Check for admin route configuration
        if (preg_match('/<admin[^>]*>/', $content)) {
            if (!preg_match('/<security[^>]*>/', $content)) {
                $issues[] = 'Missing security configuration';
            }
            
            if (!preg_match('/<acl[^>]*>/', $content)) {
                $issues[] = 'Missing ACL configuration';
            }
        }

        return $issues;
    }

    /**
     * Check if method is admin action
     */
    private static function isAdminAction(string $methodName): bool
    {
        return strpos($methodName, 'Action') !== false;
    }

    /**
     * Check if method has authorization
     */
    private static function hasAuthorizationCheck(ClassMethod $method): bool
    {
        $nodeFinder = new NodeFinder();
        $calls = $nodeFinder->find($method, function(Node $node) {
            if ($node instanceof MethodCall) {
                $methodName = $node->name->toString();
                return in_array($methodName, ['_isAllowed', 'isAllowed']);
            }
            return false;
        });

        return !empty($calls);
    }

    /**
     * Check if node is escaped
     */
    private static function isEscaped(Node $node): bool
    {
        if ($node instanceof FuncCall) {
            if ($node->name instanceof \PhpParser\Node\Name) {
                $functionName = $node->name->toString();
                return in_array($functionName, ['escapeHtml', 'escapeHtmlAttr', 'escapeUrl', 'escapeCss']);
            }
        }

        return false;
    }

    /**
     * Check if node is a safe type that cannot contain XSS
     */
    private static function isSafeType(Node $node): bool
    {
        // Literal numbers, booleans, null
        if ($node instanceof \PhpParser\Node\Scalar\Int_ || 
            $node instanceof \PhpParser\Node\Scalar\Float_ ||
            $node instanceof \PhpParser\Node\Scalar\DNumber ||
            $node instanceof \PhpParser\Node\Scalar\LNumber) {
            return true;
        }
        
        // Boolean values and null
        if ($node instanceof \PhpParser\Node\Expr\ConstFetch) {
            $name = $node->name->toString();
            if (in_array(strtolower($name), ['true', 'false', 'null'])) {
                return true;
            }
        }
        
        // Type casts to int, float, bool
        if ($node instanceof \PhpParser\Node\Expr\Cast\Int_ ||
            $node instanceof \PhpParser\Node\Expr\Cast\Double ||
            $node instanceof \PhpParser\Node\Expr\Cast\Bool_) {
            return true;
        }
        
        // Check for safe method calls (IDs, counts, etc.)
        if ($node instanceof \PhpParser\Node\Expr\MethodCall) {
            if ($node->name instanceof \PhpParser\Node\Identifier) {
                $methodName = $node->name->toString();
                $safeMethods = [
                    'getId', 'getEntityId', 'getStoreId', 'getCount', 
                    'getSize', 'getTotal', 'getIncrementId'
                ];
                if (in_array($methodName, $safeMethods)) {
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * Check if method has input validation
     */
    private static function hasInputValidation(MethodCall $node): bool
    {
        $nodeFinder = new NodeFinder();
        $validations = $nodeFinder->find($node, function(Node $node) {
            if ($node instanceof FuncCall) {
                if ($node->name instanceof \PhpParser\Node\Name) {
                    $functionName = $node->name->toString();
                    return in_array($functionName, ['filter_var', 'preg_match', 'is_numeric', 'is_string']);
                }
            }
            return false;
        });

        return !empty($validations);
    }

    /**
     * Check if method has proper session handling
     */
    private static function hasProperSessionHandling(MethodCall $node): bool
    {
        $nodeFinder = new NodeFinder();
        $securityChecks = $nodeFinder->find($node, function(Node $node) {
            if ($node instanceof MethodCall) {
                $methodName = $node->name->toString();
                return in_array($methodName, ['isValid', 'validate', 'check']);
            }
            return false;
        });

        return !empty($securityChecks);
    }

    /**
     * Check for security headers
     */
    private static function hasSecurityHeaders(string $content): bool
    {
        return preg_match('/X-Frame-Options/', $content) ||
               preg_match('/X-Content-Type-Options/', $content) ||
               preg_match('/X-XSS-Protection/', $content) ||
               preg_match('/Content-Security-Policy/', $content);
    }

    /**
     * Check if method has file upload security
     */
    private static function hasFileUploadSecurity(MethodCall $node): bool
    {
        $nodeFinder = new NodeFinder();
        $securityChecks = $nodeFinder->find($node, function(Node $node) {
            if ($node instanceof MethodCall) {
                $methodName = $node->name->toString();
                return in_array($methodName, ['validate', 'checkType', 'scan']);
            }
            return false;
        });

        return !empty($securityChecks);
    }
} 