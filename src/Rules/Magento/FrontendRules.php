<?php

declare(strict_types=1);

namespace ACQC\Rules\Magento;

use ACQC\Core\Rules\Rule;
use ACQC\Core\Rules\Violation;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeFinder;

/**
 * Magento 2 Frontend & UI Rules
 */
class FrontendRules
{
    private const TRANSLATION_FUNCTIONS = [
        '__',
        'translate',
        'escapeHtml',
        'escapeHtmlAttr',
        'escapeUrl'
    ];

    private const BLOCK_BASE_CLASSES = [
        'Magento\Framework\View\Element\Template',
        'Magento\Framework\View\Element\AbstractBlock',
        'Magento\Backend\Block\Template'
    ];

    /**
     * Rule: Missing translation functions (__())
     */
    public static function detectMissingTranslation(Node $node, string $filePath): ?Violation
    {
        if ($node instanceof String_) {
            $value = $node->value;

            // Check for hardcoded strings that should be translated
            if (self::shouldBeTranslated($value)) {
                $parent = $node->getAttribute('parent');
                if (!self::isInTranslationFunction($parent)) {
                    $violation = new Violation(
                        'missing-translation',
                        'Hardcoded string should be translated: "' . substr($value, 0, 50) . '"',
                        $filePath,
                        $node->getLine(),
                        0,
                        'warning'
                    );
                    $violation->setSuggestion('Use translation functions for user-facing strings.');
                    $violation->addMetadata('suggestions', [
                        'Use __() for simple strings: `__("Your text")`',
                        'Use translate() for complex translations',
                        'Use escapeHtml() for HTML output: `escapeHtml(__("Your text"))`',
                        'Use escapeHtmlAttr() for HTML attributes'
                    ]);

                    return $violation;
                }
            }
        }

        return null;
    }

    /**
     * Rule: Improper block class inheritance
     */
    public static function detectBlockInheritanceIssues(Node $node, string $filePath): ?Violation
    {
        if ($node instanceof Class_) {
            $className = $node->name?->toString() ?? '';

            if (strpos($className, 'Block') !== false) {
                if (!self::extendsBlockBaseClass($node)) {
                    $violation = new Violation(
                        'improper-block-inheritance',
                        'Block class does not extend proper base class.',
                        $filePath,
                        $node->getLine(),
                        0,
                        'error'
                    );
                    $violation->setSuggestion('Block classes should extend Magento block base classes.');
                    $violation->addMetadata('suggestions', [
                        'Extend: `Magento\Framework\View\Element\Template`',
                        'Extend: `Magento\Framework\View\Element\AbstractBlock`',
                        'Use proper block inheritance hierarchy'
                    ]);

                    return $violation;
                }
            }
        }

        return null;
    }

    /**
     * Rule: Layout XML structure violations
     */
    public static function detectLayoutXmlIssues(string $filePath, string $content): ?Violation
    {
        if (strpos($filePath, 'layout/') !== false && strpos($filePath, '.xml') !== false) {
            $issues = self::validateLayoutXml($content);
            if (!empty($issues)) {
                $violation = new Violation(
                    'layout-xml-violations',
                    'Layout XML issues: ' . implode(', ', $issues),
                    $filePath,
                    1,
                    0,
                    'error'
                );
                $violation->setSuggestion('Layout XML should follow Magento 2 structure.');
                $violation->addMetadata('suggestions', [
                    'Use proper <page> or <referenceContainer> structure',
                    'Include proper xmlns declarations',
                    'Use proper block and container references',
                    'Follow Magento 2 layout conventions'
                ]);

                return $violation;
            }
        }

        return null;
    }

    /**
     * Rule: RequireJS module definition issues
     */
    public static function detectRequireJsIssues(string $filePath, string $content): ?Violation
    {
        if (strpos($filePath, '.js') !== false) {
            $issues = self::validateRequireJs($content);
            if (!empty($issues)) {
                $violation = new Violation(
                    'requirejs-violations',
                    'RequireJS issues: ' . implode(', ', $issues),
                    $filePath,
                    1,
                    0,
                    'warning'
                );
                $violation->setSuggestion('RequireJS modules should follow Magento 2 patterns.');
                $violation->addMetadata('suggestions', [
                    'Use proper define() structure',
                    'Include proper dependencies',
                    'Use proper module naming conventions',
                    'Follow Magento 2 JavaScript standards'
                ]);

                return $violation;
            }
        }

        return null;
    }

    /**
     * Rule: Missing proper escaping in templates
     */
    public static function detectMissingEscaping(Node $node, string $filePath): ?Violation
    {
        if ($node instanceof String_) {
            $value = $node->value;

            if (self::containsHtml($value)) {
                $parent = $node->getAttribute('parent');
                if (!self::isEscaped($parent)) {
                    $violation = new Violation(
                        'missing-escaping',
                        'HTML content not properly escaped.',
                        $filePath,
                        $node->getLine(),
                        0,
                        'error'
                    );
                    $violation->setSuggestion('Use proper escaping functions for HTML output.');
                    $violation->addMetadata('suggestions', [
                        'Use escapeHtml() for HTML content',
                        'Use escapeHtmlAttr() for HTML attributes',
                        'Use escapeUrl() for URLs',
                        'Use escapeCss() for CSS values'
                    ]);

                    return $violation;
                }
            }
        }

        return null;
    }

    /**
     * Rule: Improper use of inline JavaScript
     */
    public static function detectInlineJavaScript(string $filePath, string $content): ?Violation
    {
        if (strpos($filePath, '.phtml') !== false || strpos($filePath, '.html') !== false) {
            if (self::hasInlineJavaScript($content)) {
                $violation = new Violation(
                    'inline-javascript',
                    'Inline JavaScript detected in template.',
                    $filePath,
                    1,
                    0,
                    'warning'
                );
                $violation->setSuggestion('Avoid inline JavaScript. Use RequireJS modules instead.');
                $violation->addMetadata('suggestions', [
                    'Move JavaScript to separate .js files',
                    'Use RequireJS define() for modules',
                    'Use proper event handling',
                    'Follow Magento 2 JavaScript standards'
                ]);

                return $violation;
            }
        }

        return null;
    }

    /**
     * Rule: Missing responsive design considerations
     */
    public static function detectResponsiveDesignIssues(string $filePath, string $content): ?Violation
    {
        if (strpos($filePath, '.css') !== false || strpos($filePath, '.less') !== false) {
            if (!self::hasResponsiveDesign($content)) {
                $violation = new Violation(
                    'missing-responsive-design',
                    'Missing responsive design considerations.',
                    $filePath,
                    1,
                    0,
                    'warning'
                );
                $violation->setSuggestion('Include responsive design patterns for mobile compatibility.');
                $violation->addMetadata('suggestions', [
                    'Use CSS media queries for responsive design',
                    'Implement mobile-first approach',
                    'Use flexible layouts and units',
                    'Test on multiple screen sizes'
                ]);

                return $violation;
            }
        }

        return null;
    }

    /**
     * Check if string should be translated
     */
    private static function shouldBeTranslated(string $value): bool
    {
        // Check for user-facing strings that should be translated
        $patterns = [
            '/^[A-Z][a-z\s]+$/', // Title case strings
            '/^(Yes|No|Submit|Cancel|Save|Delete|Edit|Add|Remove)$/i',
            '/^(Error|Warning|Success|Info|Notice)$/i',
            '/^(Loading|Please wait|Processing)$/i'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, trim($value))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if node is in translation function
     */
    private static function isInTranslationFunction(?Node $node): bool
    {
        if (!$node) {
            return false;
        }

        if ($node instanceof FuncCall) {
            if ($node->name instanceof \PhpParser\Node\Name) {
                $functionName = $node->name->toString();
                return in_array($functionName, self::TRANSLATION_FUNCTIONS);
            }
        }

        return self::isInTranslationFunction($node->getAttribute('parent'));
    }

    /**
     * Check if class extends block base class
     */
    private static function extendsBlockBaseClass(Class_ $class): bool
    {
        if (!$class->extends) {
            return false;
        }

        $extendsName = $class->extends->toString();
        return in_array($extendsName, self::BLOCK_BASE_CLASSES);
    }

    /**
     * Validate layout XML structure
     */
    private static function validateLayoutXml(string $content): array
    {
        $issues = [];

        // Check for proper XML structure
        if (!preg_match('/<page\s+xmlns[^>]*>/', $content) && 
            !preg_match('/<referenceContainer[^>]*>/', $content) &&
            !preg_match('/<referenceBlock[^>]*>/', $content)) {
            $issues[] = 'Missing proper layout structure';
        }

        // Check for proper xmlns declarations
        if (!preg_match('/xmlns:xsi="[^"]*"/', $content)) {
            $issues[] = 'Missing xsi namespace declaration';
        }

        // Check for proper block references
        if (preg_match('/<block[^>]*>/', $content) && !preg_match('/name="[^"]*"/', $content)) {
            $issues[] = 'Block missing name attribute';
        }

        return $issues;
    }

    /**
     * Validate RequireJS structure
     */
    private static function validateRequireJs(string $content): array
    {
        $issues = [];

        // Check for proper define() structure
        if (!preg_match('/define\s*\(/', $content)) {
            $issues[] = 'Missing define() function';
        }

        // Check for proper dependencies
        if (preg_match('/define\s*\(\s*\[/', $content) && !preg_match('/\]\s*,/', $content)) {
            $issues[] = 'Incomplete dependency array';
        }

        // Check for proper module structure
        if (preg_match('/define\s*\(/', $content) && !preg_match('/function\s*\(/', $content)) {
            $issues[] = 'Missing module function';
        }

        return $issues;
    }

    /**
     * Check if string contains HTML
     */
    private static function containsHtml(string $value): bool
    {
        return preg_match('/<[^>]+>/', $value);
    }

    /**
     * Check if content is escaped
     */
    private static function isEscaped(?Node $node): bool
    {
        if (!$node) {
            return false;
        }

        if ($node instanceof FuncCall) {
            if ($node->name instanceof \PhpParser\Node\Name) {
                $functionName = $node->name->toString();
                return in_array($functionName, ['escapeHtml', 'escapeHtmlAttr', 'escapeUrl', 'escapeCss']);
            }
        }

        return self::isEscaped($node->getAttribute('parent'));
    }

    /**
     * Check for inline JavaScript
     */
    private static function hasInlineJavaScript(string $content): bool
    {
        return preg_match('/<script[^>]*>/', $content) || 
               preg_match('/onclick\s*=/', $content) ||
               preg_match('/onload\s*=/', $content) ||
               preg_match('/onchange\s*=/', $content);
    }

    /**
     * Check for responsive design
     */
    private static function hasResponsiveDesign(string $content): bool
    {
        return preg_match('/@media\s+screen/', $content) ||
               preg_match('/@media\s+\(max-width/', $content) ||
               preg_match('/@media\s+\(min-width/', $content) ||
               preg_match('/flexbox/', $content) ||
               preg_match('/grid/', $content);
    }
} 