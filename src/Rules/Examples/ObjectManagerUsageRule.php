<?php

declare(strict_types=1);

namespace ACQC\Rules\Examples;

use ACQC\Core\Rules\Rule;
use ACQC\Core\Rules\Violation;

/**
 * Magento-specific rule that detects ObjectManager usage
 */
class ObjectManagerUsageRule extends Rule
{
    protected string $id = 'magento-object-manager-usage';
    protected string $name = 'ObjectManager Usage';
    protected string $description = 'Detects direct ObjectManager usage which should be avoided';
    protected string $severity = Violation::SEVERITY_ERROR;
    protected string $type = Violation::TYPE_LOGIC;
    protected ?string $category = 'magento';
    protected int $priority = 20;
    protected array $tags = ['magento', 'dependency-injection', 'best-practices'];
    protected array $fileExtensions = ['php'];
    protected array $excludePatterns = ['*/Test/*', '*/tests/*'];
    protected ?string $documentationUrl = 'https://developer.adobe.com/commerce/php/development/components/dependency-injection/';

    protected array $configuration = [
        'allow_in_factories' => true,
        'allow_in_tests' => true,
    ];

    public function execute(string $filePath, string $content): array
    {
        $violations = [];
        
        // Skip if this is a test file and tests are allowed
        if ($this->getConfigValue('allow_in_tests', true) && $this->isTestFile($filePath)) {
            return [];
        }

        // Skip if this is a factory and factories are allowed
        if ($this->getConfigValue('allow_in_factories', true) && $this->isFactoryFile($filePath)) {
            return [];
        }

        // Find ObjectManager usage patterns
        $patterns = [
            '/ObjectManager::getInstance\(\)/' => 'Direct ObjectManager::getInstance() call',
            '/\$.*?->getInstance\(\).*?ObjectManager/' => 'ObjectManager getInstance() call',
            '/use\s+Magento\\\\Framework\\\\App\\\\ObjectManager/' => 'ObjectManager import',
            '/new\s+ObjectManager\s*\(/' => 'Direct ObjectManager instantiation',
        ];

        foreach ($patterns as $pattern => $description) {
            $matches = $this->findPattern($content, $pattern);
            
            foreach ($matches as $match) {
                $violation = $this->createViolation(
                    $description . ' - Use dependency injection instead',
                    $filePath,
                    $match['line'],
                    $match['column'],
                    $this->extractSourceLine($content, $match['line'])
                );

                $violation->addContext('pattern', $pattern);
                $violation->addContext('matched_text', $match['match']);
                $violation->setSuggestion($this->getSuggestionForPattern($pattern));
                $violation->addMetadata('magento_version', '2.x');
                $violation->addMetadata('impact', 'high');

                $violations[] = $violation;
            }
        }

        return $violations;
    }

    protected function customAppliesTo(string $filePath): bool
    {
        // Only apply to PHP files in Magento modules
        if (!str_contains($filePath, '/app/code/') && !str_contains($filePath, '/vendor/')) {
            return false;
        }

        // Skip generated files
        if (str_contains($filePath, '/generated/')) {
            return false;
        }

        return true;
    }

    /**
     * Check if file is a test file
     */
    private function isTestFile(string $filePath): bool
    {
        return str_contains($filePath, '/Test/') || 
               str_contains($filePath, '/tests/') ||
               str_ends_with($filePath, 'Test.php');
    }

    /**
     * Check if file is a factory
     */
    private function isFactoryFile(string $filePath): bool
    {
        return str_ends_with($filePath, 'Factory.php') || 
               str_contains($filePath, '/Factory/');
    }

    /**
     * Get suggestion for specific pattern
     */
    private function getSuggestionForPattern(string $pattern): string
    {
        return match ($pattern) {
            '/ObjectManager::getInstance\(\)/' => 
                'Inject dependencies through constructor or use factory pattern',
            '/\$.*?->getInstance\(\).*?ObjectManager/' => 
                'Use dependency injection container instead of ObjectManager',
            '/use\s+Magento\\\\Framework\\\\App\\\\ObjectManager/' => 
                'Remove ObjectManager import and use proper dependency injection',
            '/new\s+ObjectManager\s*\(/' => 
                'Use the existing ObjectManager instance through dependency injection',
            default => 'Use dependency injection instead of ObjectManager'
        };
    }
} 