<?php

declare(strict_types=1);

namespace ACQC\Rules\Magento;

use ACQC\Core\Rules\Rule;
use ACQC\Core\Rules\Violation;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\NodeFinder;

/**
 * Magento 2 Configuration & System Rules
 */
class ConfigurationRules
{
    private const HARDCODED_STORE_IDS = [1, 2, 3, 4, 5];
    private const HARDCODED_WEBSITE_IDS = [1, 2, 3, 4, 5];

    /**
     * Rule: Hardcoded store/website IDs
     */
    public static function detectHardcodedStoreIds(Node $node): ?Violation
    {
        if ($node instanceof LNumber) {
            $value = $node->value;
            
            if (in_array($value, self::HARDCODED_STORE_IDS)) {
                return new Violation(
                    'hardcoded-store-id',
                    'error',
                    'Hardcoded store ID detected: ' . $value,
                    $node->getLine(),
                    'Use configuration or context to get store ID dynamically.',
                    [
                        'Use: `$this->storeManager->getStore()->getId()`',
                        'Use: `$this->storeManager->getStoreId()`',
                        'Use: `$this->context->getStoreManager()->getStore()->getId()`',
                        'Avoid hardcoded store IDs for multi-store compatibility'
                    ]
                );
            }
        }

        if ($node instanceof String_) {
            $value = $node->value;
            
            if (is_numeric($value) && in_array((int)$value, self::HARDCODED_STORE_IDS)) {
                return new Violation(
                    'hardcoded-store-id-string',
                    'error',
                    'Hardcoded store ID detected: ' . $value,
                    $node->getLine(),
                    'Use configuration or context to get store ID dynamically.',
                    [
                        'Use: `$this->storeManager->getStore()->getId()`',
                        'Use: `$this->storeManager->getStoreId()`',
                        'Avoid hardcoded store IDs for multi-store compatibility'
                    ]
                );
            }
        }

        return null;
    }

    /**
     * Rule: Missing ACL resource definitions
     */
    public static function detectMissingAclResources(string $filePath, string $content): ?Violation
    {
        if (strpos($filePath, 'acl.xml') !== false) {
            $issues = self::validateAclXml($content);
            if (!empty($issues)) {
                return new Violation(
                    'missing-acl-resources',
                    'error',
                    'ACL resource issues: ' . implode(', ', $issues),
                    1,
                    'Define proper ACL resources for admin functionality.',
                    [
                        'Include <resource> tags for all admin actions',
                        'Use proper resource hierarchy',
                        'Define proper permissions for admin users',
                        'Follow Magento 2 ACL conventions'
                    ]
                );
            }
        }

        return null;
    }

    /**
     * Rule: System configuration XML structure violations
     */
    public static function detectSystemConfigIssues(string $filePath, string $content): ?Violation
    {
        if (strpos($filePath, 'system.xml') !== false) {
            $issues = self::validateSystemXml($content);
            if (!empty($issues)) {
                return new Violation(
                    'system-config-violations',
                    'error',
                    'System configuration issues: ' . implode(', ', $issues),
                    1,
                    'System configuration should follow Magento 2 structure.',
                    [
                        'Use proper <system> structure',
                        'Include proper <section> and <group> tags',
                        'Use proper <field> definitions',
                        'Include proper <source_model> and <backend_model>'
                    ]
                );
            }
        }

        return null;
    }

    /**
     * Rule: Improper use of core configuration paths
     */
    public static function detectCoreConfigPathIssues(Node $node): ?Violation
    {
        if ($node instanceof String_) {
            $value = $node->value;
            
            if (self::isCoreConfigPath($value)) {
                $parent = $node->getAttribute('parent');
                if (!self::isProperConfigUsage($parent)) {
                    return new Violation(
                        'improper-core-config-usage',
                        'warning',
                        'Improper use of core configuration path: ' . $value,
                        $node->getLine(),
                        'Use proper configuration scope and avoid direct core path access.',
                        [
                            'Use: `$this->scopeConfig->getValue()`',
                            'Use: `$this->config->getValue()`',
                            'Specify proper scope: ScopeInterface::SCOPE_STORE',
                            'Avoid hardcoded configuration paths'
                        ]
                    );
                }
            }
        }

        return null;
    }

    /**
     * Rule: Missing environment-specific configurations
     */
    public static function detectEnvironmentConfigIssues(string $filePath, string $content): ?Violation
    {
        if (strpos($filePath, 'env.php') !== false || strpos($filePath, 'app/etc/') !== false) {
            if (!self::hasEnvironmentSpecificConfig($content)) {
                return new Violation(
                    'missing-environment-config',
                    'warning',
                    'Missing environment-specific configuration.',
                    1,
                    'Separate configuration for different environments.',
                    [
                        'Use env.php for environment-specific settings',
                        'Separate dev, staging, and production configs',
                        'Use proper configuration inheritance',
                        'Avoid hardcoded environment values'
                    ]
                );
            }
        }

        return null;
    }

    /**
     * Rule: Sensitive config parameter masking
     */
    public static function detectSensitiveConfigExposure(string $filePath, string $content): ?Violation
    {
        $sensitivePatterns = [
            '/password\s*=\s*["\'][^"\']+["\']/',
            '/api_key\s*=\s*["\'][^"\']+["\']/',
            '/secret\s*=\s*["\'][^"\']+["\']/',
            '/token\s*=\s*["\'][^"\']+["\']/'
        ];

        foreach ($sensitivePatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return new Violation(
                    'sensitive-config-exposure',
                    'critical',
                    'Sensitive configuration parameter exposed.',
                    1,
                    'Mask sensitive parameters in configuration files.',
                    [
                        'Use environment variables for sensitive data',
                        'Use configuration encryption',
                        'Mask sensitive values in logs',
                        'Use proper configuration security'
                    ]
                );
            }
        }

        return null;
    }

    /**
     * Rule: Missing cron configuration
     */
    public static function detectMissingCronConfig(string $filePath, string $content): ?Violation
    {
        if (strpos($filePath, 'crontab.xml') !== false) {
            $issues = self::validateCronXml($content);
            if (!empty($issues)) {
                return new Violation(
                    'missing-cron-config',
                    'warning',
                    'Cron configuration issues: ' . implode(', ', $issues),
                    1,
                    'Define proper cron job configurations.',
                    [
                        'Include proper <job> definitions',
                        'Use proper schedule expressions',
                        'Define proper instance and method',
                        'Follow Magento 2 cron conventions'
                    ]
                );
            }
        }

        return null;
    }

    /**
     * Rule: Improper event configuration
     */
    public static function detectEventConfigIssues(string $filePath, string $content): ?Violation
    {
        if (strpos($filePath, 'events.xml') !== false) {
            $issues = self::validateEventsXml($content);
            if (!empty($issues)) {
                return new Violation(
                    'event-config-violations',
                    'error',
                    'Event configuration issues: ' . implode(', ', $issues),
                    1,
                    'Event configuration should follow Magento 2 structure.',
                    [
                        'Use proper <event> structure',
                        'Include proper <observer> definitions',
                        'Use proper instance and method names',
                        'Follow Magento 2 event conventions'
                    ]
                );
            }
        }

        return null;
    }

    /**
     * Validate ACL XML structure
     */
    private static function validateAclXml(string $content): array
    {
        $issues = [];

        // Check for proper ACL structure
        if (!preg_match('/<acl[^>]*>/', $content)) {
            $issues[] = 'Missing <acl> root element';
        }

        // Check for resource definitions
        if (!preg_match('/<resource[^>]*>/', $content)) {
            $issues[] = 'Missing <resource> definitions';
        }

        // Check for proper resource hierarchy
        if (preg_match('/<resource[^>]*>/', $content) && !preg_match('/id="[^"]*"/', $content)) {
            $issues[] = 'Resource missing id attribute';
        }

        return $issues;
    }

    /**
     * Validate system.xml structure
     */
    private static function validateSystemXml(string $content): array
    {
        $issues = [];

        // Check for proper system structure
        if (!preg_match('/<system[^>]*>/', $content)) {
            $issues[] = 'Missing <system> root element';
        }

        // Check for section definitions
        if (!preg_match('/<section[^>]*>/', $content)) {
            $issues[] = 'Missing <section> definitions';
        }

        // Check for group definitions
        if (preg_match('/<section[^>]*>/', $content) && !preg_match('/<group[^>]*>/', $content)) {
            $issues[] = 'Missing <group> definitions';
        }

        // Check for field definitions
        if (preg_match('/<group[^>]*>/', $content) && !preg_match('/<field[^>]*>/', $content)) {
            $issues[] = 'Missing <field> definitions';
        }

        return $issues;
    }

    /**
     * Check if string is core configuration path
     */
    private static function isCoreConfigPath(string $value): bool
    {
        $corePaths = [
            'general/store_information/',
            'general/locale/',
            'currency/',
            'catalog/',
            'customer/',
            'sales/',
            'payment/',
            'shipping/',
            'tax/',
            'system/'
        ];

        foreach ($corePaths as $path) {
            if (strpos($value, $path) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if node is proper config usage
     */
    private static function isProperConfigUsage(?Node $node): bool
    {
        if (!$node) {
            return false;
        }

        if ($node instanceof \PhpParser\Node\Expr\MethodCall) {
            $methodName = $node->name->toString();
            return in_array($methodName, ['getValue', 'isSetFlag']);
        }

        return self::isProperConfigUsage($node->getAttribute('parent'));
    }

    /**
     * Check for environment-specific configuration
     */
    private static function hasEnvironmentSpecificConfig(string $content): bool
    {
        return preg_match('/env\[/', $content) ||
               preg_match('/getenv\(/', $content) ||
               preg_match('/APP_ENV/', $content) ||
               preg_match('/MAGE_MODE/', $content);
    }

    /**
     * Validate cron.xml structure
     */
    private static function validateCronXml(string $content): array
    {
        $issues = [];

        // Check for proper cron structure
        if (!preg_match('/<config[^>]*>/', $content)) {
            $issues[] = 'Missing <config> root element';
        }

        // Check for job definitions
        if (!preg_match('/<job[^>]*>/', $content)) {
            $issues[] = 'Missing <job> definitions';
        }

        // Check for proper job attributes
        if (preg_match('/<job[^>]*>/', $content) && !preg_match('/name="[^"]*"/', $content)) {
            $issues[] = 'Job missing name attribute';
        }

        return $issues;
    }

    /**
     * Validate events.xml structure
     */
    private static function validateEventsXml(string $content): array
    {
        $issues = [];

        // Check for proper events structure
        if (!preg_match('/<config[^>]*>/', $content)) {
            $issues[] = 'Missing <config> root element';
        }

        // Check for event definitions
        if (!preg_match('/<event[^>]*>/', $content)) {
            $issues[] = 'Missing <event> definitions';
        }

        // Check for observer definitions
        if (preg_match('/<event[^>]*>/', $content) && !preg_match('/<observer[^>]*>/', $content)) {
            $issues[] = 'Missing <observer> definitions';
        }

        return $issues;
    }
} 