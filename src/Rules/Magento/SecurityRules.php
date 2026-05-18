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
    public static function detectMissingCsrfProtection(Node $node): ?Violation
    {
        if ($node instanceof ClassMethod) {
            $methodName = $node->name->toString();
            
            if (self::isCsrfAction($methodName)) {
                if (!self::hasCsrfProtection($node)) {
                    return new Violation(
                        'missing-csrf-protection',
                        'critical',
                        'Missing CSRF protection in action: ' . $methodName,
                        $node->getLine(),
                        'Implement CSRF protection for form submissions.',
                        [
                            'Use: `$this->_isAllowed()`',
                            'Use: `$this->getRequest()->isPost()`',
                            'Use: `$this->validateFormKey()`',
                            'Include form key in templates: `<?= $block->getFormKey() ?>`'
                        ]
                    );
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
                return new Violation(
                    'improper-admin-routes',
                    'error',
                    'Admin route issues: ' . implode(', ', $issues),
                    1,
                    'Admin routes should have proper security configuration.',
                    [
                        'Include proper <admin> route configuration',
                        'Use proper ACL resources',
                        'Include proper security settings',
                        'Follow Magento 2 admin route conventions'
                    ]
                );
            }
        }

        return null;
    }

    /**
     * Rule: Missing authorization checks in controllers
     */
    public static function detectMissingAuthorization(Node $node): ?Violation
    {
        if ($node instanceof ClassMethod) {
            $methodName = $node->name->toString();
            
            if (self::isAdminAction($methodName)) {
                if (!self::hasAuthorizationCheck($node)) {
                    return new Violation(
                        'missing-authorization',
                        'critical',
                        'Missing authorization check in admin action: ' . $methodName,
                        $node->getLine(),
                        'Implement proper authorization checks for admin actions.',
                        [
                            'Use: `$this->_isAllowed()`',
                            'Use: `$this->authorization->isAllowed()`',
                            'Check specific ACL resources',
                            'Implement proper role-based access control'
                        ]
                    );
                }
            }
        }

        return null;
    }

    /**
     * Rule: Unsafe data output without escaping functions
     */
    public static function detectUnsafeOutput(Node $node): ?Violation
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
                            return new Violation(
                                'unsafe-output',
                                'error',
                                'Unsafe output detected without escaping.',
                                $node->getLine(),
                                'Use proper escaping functions for output.',
                                [
                                    'Use: `escapeHtml()` for HTML content',
                                    'Use: `escapeHtmlAttr()` for HTML attributes',
                                    'Use: `escapeUrl()` for URLs',
                                    'Use: `escapeCss()` for CSS values'
                                ]
                            );
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
    public static function detectMissingInputValidation(Node $node): ?Violation
    {
        if ($node instanceof MethodCall) {
            $methodName = $node->name->toString();
            
            if (in_array($methodName, ['getParam', 'getPost', 'getQuery'])) {
                if (!self::hasInputValidation($node)) {
                    return new Violation(
                        'missing-input-validation',
                        'error',
                        'Missing input validation for: ' . $methodName,
                        $node->getLine(),
                        'Validate all user inputs before processing.',
                        [
                            'Use: `$this->getRequest()->getParam()` with validation',
                            'Use: `filter_var()` for data sanitization',
                            'Use: `preg_match()` for pattern validation',
                            'Implement proper input validation rules'
                        ]
                    );
                }
            }
        }

        return null;
    }

    /**
     * Rule: Improper session management
     */
    public static function detectSessionIssues(Node $node): ?Violation
    {
        if ($node instanceof MethodCall) {
            $methodName = $node->name->toString();
            
            if (in_array($methodName, ['setData', 'getData', 'unsetData'])) {
                $var = $node->var;
                if ($var instanceof \PhpParser\Node\Expr\PropertyFetch) {
                    $propertyName = $var->name->toString();
                    if ($propertyName === 'session') {
                        if (!self::hasProperSessionHandling($node)) {
                            return new Violation(
                                'improper-session-handling',
                                'warning',
                                'Improper session data handling.',
                                $node->getLine(),
                                'Use proper session management practices.',
                                [
                                    'Use: `$this->session->setData()` with proper scope',
                                    'Use: `$this->session->getData()` with validation',
                                    'Implement session timeout',
                                    'Use proper session security settings'
                                ]
                            );
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
                return new Violation(
                    'missing-security-headers',
                    'warning',
                    'Missing security headers configuration.',
                    1,
                    'Implement security headers for enhanced protection.',
                    [
                        'Add X-Frame-Options header',
                        'Add X-Content-Type-Options header',
                        'Add X-XSS-Protection header',
                        'Add Content-Security-Policy header'
                    ]
                );
            }
        }

        return null;
    }

    /**
     * Rule: Improper file upload handling
     */
    public static function detectFileUploadIssues(Node $node): ?Violation
    {
        if ($node instanceof MethodCall) {
            $methodName = $node->name->toString();
            
            if (in_array($methodName, ['upload', 'save', 'move'])) {
                if (!self::hasFileUploadSecurity($node)) {
                    return new Violation(
                        'improper-file-upload',
                        'critical',
                        'Improper file upload handling.',
                        $node->getLine(),
                        'Implement proper file upload security.',
                        [
                            'Validate file types and extensions',
                            'Check file size limits',
                            'Scan for malware',
                            'Use secure file storage location'
                        ]
                    );
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