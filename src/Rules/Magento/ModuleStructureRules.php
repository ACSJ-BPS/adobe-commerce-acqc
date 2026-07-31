<?php

declare(strict_types=1);

namespace ACQC\Rules\Magento;

use ACQC\Core\Rules\Rule;
use ACQC\Core\Rules\Violation;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeFinder;

/**
 * Magento 2 Module Structure & Registration Rules
 */
class ModuleStructureRules
{
    /**
     * Rule: Missing or incorrect registration.php files
     */
    public static function detectRegistrationIssues(string $filePath, Node|array $ast): ?Violation
    {
        if (basename($filePath) === 'registration.php') {
            if (!self::hasValidRegistrationStructure($ast)) {
                return new Violation(
                    'invalid-registration-structure',
                    'Invalid registration.php file structure.',
                    $filePath,
                    1,
                    0,
                    'error'
                );
            }
        }

        return null;
    }

    /**
     * Rule: Invalid module.xml structure
     */
    public static function detectModuleXmlIssues(string $filePath, string $content): ?Violation
    {
        if (basename($filePath) === 'module.xml') {
            $issues = self::validateModuleXml($content);
            if (!empty($issues)) {
                $violation = new Violation(
                    'invalid-module-xml',
                    'Invalid module.xml structure: ' . implode(', ', $issues),
                    $filePath,
                    1,
                    0,
                    'error'
                );
                $violation->setSuggestion('module.xml should have proper Magento 2 structure.');
                $violation->addMetadata('suggestions', [
                    'Include proper <module> tag with name attribute',
                    'Include <sequence> tag for dependencies',
                    'Use proper XML namespace declarations'
                ]);

                return $violation;
            }
        }

        return null;
    }

    /**
     * Rule: Incorrect module sequence dependencies
     */
    public static function detectSequenceIssues(string $filePath, string $content): ?Violation
    {
        if (basename($filePath) === 'module.xml') {
            $sequenceIssues = self::validateModuleSequence($content);
            if (!empty($sequenceIssues)) {
                $violation = new Violation(
                    'invalid-module-sequence',
                    'Module sequence issues: ' . implode(', ', $sequenceIssues),
                    $filePath,
                    1,
                    0,
                    'warning'
                );
                $violation->setSuggestion('Check module dependencies and sequence order.');
                $violation->addMetadata('suggestions', [
                    'Ensure all dependencies are declared in <sequence>',
                    'Check for circular dependencies',
                    'Verify module names follow Vendor_Module format'
                ]);

                return $violation;
            }
        }

        return null;
    }

    /**
     * Rule: Missing composer.json or invalid module metadata
     */
    public static function detectComposerIssues(string $filePath, string $content): ?Violation
    {
        if (basename($filePath) === 'composer.json') {
            $composerIssues = self::validateComposerJson($content);
            if (!empty($composerIssues)) {
                $violation = new Violation(
                    'invalid-composer-json',
                    'Composer.json issues: ' . implode(', ', $composerIssues),
                    $filePath,
                    1,
                    0,
                    'error'
                );
                $violation->setSuggestion('composer.json should have proper Magento 2 module metadata.');
                $violation->addMetadata('suggestions', [
                    'Include proper name in format: vendor/module-name',
                    'Include magento2-module type',
                    'Include proper autoload configuration',
                    'Include proper version constraints'
                ]);

                return $violation;
            }
        }

        return null;
    }

    /**
     * Rule: Module namespace violations
     */
    public static function detectNamespaceViolations(Node $node, string $filePath): ?Violation
    {
        if ($node instanceof Namespace_) {
            $namespace = $node->name->toString() ?? '';

            if (!self::isValidModuleNamespace($namespace)) {
                $violation = new Violation(
                    'invalid-module-namespace',
                    'Invalid module namespace: ' . $namespace,
                    $filePath,
                    $node->getLine(),
                    0,
                    'error'
                );
                $violation->setSuggestion('Module namespaces should follow Magento 2 conventions.');
                $violation->addMetadata('suggestions', [
                    'Use format: Vendor\Module',
                    'Match namespace with module name',
                    'Follow PSR-4 autoloading standards'
                ]);

                return $violation;
            }
        }

        return null;
    }

    /**
     * Rule: Module directory structure violations
     */
    public static function detectDirectoryStructureIssues(string $filePath): ?Violation
    {
        $moduleDir = self::findModuleRoot(dirname($filePath));
        if ($moduleDir === null) {
            // No registration.php found within a reasonable number of parent
            // directories — this file isn't inside a recognizable module, so
            // there's no module root to validate against.
            return null;
        }

        $expectedStructure = [
            'registration.php',
            'etc/module.xml',
            'composer.json'
        ];

        $missingFiles = [];
        foreach ($expectedStructure as $file) {
            if (!file_exists($moduleDir . '/' . $file)) {
                $missingFiles[] = $file;
            }
        }

        if (!empty($missingFiles)) {
            return new Violation(
                'missing-module-files',
                'Missing required module files: ' . implode(', ', $missingFiles),
                $filePath,
                1,
                0,
                'error'
            );
        }

        return null;
    }

    /**
     * Walk up from a file's directory to find the module root (identified by
     * registration.php), since a file may live several levels below it
     * (e.g. Vendor/Module/etc/di.xml).
     */
    private static function findModuleRoot(string $dir): ?string
    {
        for ($i = 0; $i < 6; $i++) {
            if (file_exists($dir . '/registration.php')) {
                return $dir;
            }

            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }

            $dir = $parent;
        }

        return null;
    }

    /**
     * Check if registration.php has valid structure
     */
    private static function hasValidRegistrationStructure(Node|array $ast): bool
    {
        $nodeFinder = new NodeFinder();
        $staticCalls = $nodeFinder->findInstanceOf($ast, Node\Expr\StaticCall::class);

        foreach ($staticCalls as $call) {
            if ($call->class instanceof Node\Name) {
                $className = $call->class->toString();
                if (strpos($className, 'ComponentRegistrar') !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Validate module.xml content
     */
    private static function validateModuleXml(string $content): array
    {
        $issues = [];

        // Check for required tags
        if (strpos($content, '<module') === false) {
            $issues[] = 'missing module tag';
        }

        if (strpos($content, 'name=') === false) {
            $issues[] = 'missing name attribute';
        }

        if (strpos($content, 'xmlns:xsi=') === false) {
            $issues[] = 'missing XML namespace';
        }

        return $issues;
    }

    /**
     * Validate module sequence
     */
    private static function validateModuleSequence(string $content): array
    {
        $issues = [];

        // Check for sequence tag
        if (strpos($content, '<sequence>') === false) {
            $issues[] = 'missing sequence tag';
        }

        // Check for module dependencies
        if (strpos($content, '<module') === false) {
            $issues[] = 'no module dependencies declared';
        }

        return $issues;
    }

    /**
     * Validate composer.json content
     */
    private static function validateComposerJson(string $content): array
    {
        $issues = [];
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $issues[] = 'invalid JSON format';
            return $issues;
        }

        if (!isset($data['name'])) {
            $issues[] = 'missing name field';
        }

        if (!isset($data['type']) || $data['type'] !== 'magento2-module') {
            $issues[] = 'missing or invalid type field';
        }

        if (!isset($data['autoload'])) {
            $issues[] = 'missing autoload configuration';
        }

        return $issues;
    }

    /**
     * Check if namespace is valid for Magento 2 module
     */
    private static function isValidModuleNamespace(string $namespace): bool
    {
        // Check for proper format: Vendor\Module
        $parts = explode('\\', $namespace);
        
        if (count($parts) < 2) {
            return false;
        }

        // Check for valid vendor and module names
        foreach ($parts as $part) {
            if (!preg_match('/^[A-Z][a-zA-Z0-9]*$/', $part)) {
                return false;
            }
        }

        return true;
    }
} 