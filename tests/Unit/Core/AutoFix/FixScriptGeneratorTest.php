<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Core\AutoFix;

use ACQC\Core\AutoFix\FixScriptGenerator;
use ACQC\Core\AutoFix\Generators\MagentoConfigFixGenerator;
use ACQC\Core\Rules\Violation;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class FixScriptGeneratorTest extends TestCase
{
    private FixScriptGenerator $generator;
    private string $tempDir;
    
    protected function setUp(): void
    {
        $this->generator = new FixScriptGenerator(new NullLogger());
        $this->tempDir = sys_get_temp_dir() . '/acqc_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }
    
    protected function tearDown(): void
    {
        // Clean up temp files
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->tempDir);
        }
    }
    
    public function testRegisterGenerator(): void
    {
        $magentoGenerator = new MagentoConfigFixGenerator();
        
        $result = $this->generator->registerGenerator('magento_config', $magentoGenerator);
        
        $this->assertInstanceOf(FixScriptGenerator::class, $result);
    }
    
    public function testGenerateFixScriptForMagentoConfigViolations(): void
    {
        $this->generator->registerGenerator('magento_config', new MagentoConfigFixGenerator());
        
        $violations = [
            new Violation(
                'config-flat-catalog-disabled',  // $ruleId
                'Flat catalog is disabled for products',  // $message
                'test.php',  // $filePath
                1,  // $line
                0,  // $column
                Violation::SEVERITY_WARNING,  // $severity
                Violation::TYPE_PERFORMANCE  // $type
            ),
            new Violation(
                'config-cache-disabled',
                'Magento caches are disabled',
                'test.php',
                1,
                0,
                Violation::SEVERITY_CRITICAL,
                Violation::TYPE_PERFORMANCE
            ),
        ];
        
        $scriptPath = $this->tempDir . '/test_fix.sh';
        $result = $this->generator->generateFixScript($violations, $scriptPath);
        
        $this->assertFileExists($scriptPath);
        $this->assertTrue(is_executable($scriptPath));
        $this->assertEquals($scriptPath, $result['script_path']);
        $this->assertEquals(2, $result['fixable_count']);
        $this->assertEquals(0, $result['unfixable_count']);
        $this->assertCount(2, $result['commands']);
    }
    
    public function testGeneratedScriptContainsProperStructure(): void
    {
        $this->generator->registerGenerator('magento_config', new MagentoConfigFixGenerator());
        
        $violations = [
            new Violation(
                'config-merge-css-disabled',
                'CSS merging is disabled',
                'test.php',
                1,
                0,
                Violation::SEVERITY_WARNING,
                Violation::TYPE_PERFORMANCE
            ),
        ];
        
        $scriptPath = $this->tempDir . '/test_fix.sh';
        $this->generator->generateFixScript($violations, $scriptPath);
        
        $content = file_get_contents($scriptPath);
        
        // Check script header
        $this->assertStringContainsString('#!/bin/bash', $content);
        $this->assertStringContainsString('ACQC Auto-generated Fix Script', $content);
        $this->assertStringContainsString('Generated:', $content);
        
        // Check safety features
        $this->assertStringContainsString('set -e', $content);
        $this->assertStringContainsString('set -u', $content);
        $this->assertStringContainsString('set -o pipefail', $content);
        
        // Check Magento root setup
        $this->assertStringContainsString('MAGENTO_ROOT', $content);
        
        // Check color codes
        $this->assertStringContainsString('RED=', $content);
        $this->assertStringContainsString('GREEN=', $content);
        
        // Check helper functions
        $this->assertStringContainsString('check_magento_root()', $content);
        $this->assertStringContainsString('check_magento_cli()', $content);
        
        // Check backup creation
        $this->assertStringContainsString('Creating backup', $content);
        $this->assertStringContainsString('var/backups', $content);
        
        // Check fix command
        $this->assertStringContainsString('bin/magento config:set dev/css/merge_css_files 1', $content);
        
        // Check completion message
        $this->assertStringContainsString('All fixes applied successfully', $content);
    }
    
    public function testGetFixableStatistics(): void
    {
        $this->generator->registerGenerator('magento_config', new MagentoConfigFixGenerator());
        
        $violations = [
            (new Violation(
                'config-flat-catalog-disabled',
                'Flat catalog disabled',
                'test.php',
                1,
                0,
                Violation::SEVERITY_WARNING,
                Violation::TYPE_PERFORMANCE
            ))->setCategory('performance'),
            (new Violation(
                'unfixable-rule',
                'This cannot be fixed',
                'test.php',
                1,
                0,
                Violation::SEVERITY_ERROR,
                Violation::TYPE_LOGIC
            ))->setCategory('quality'),
            (new Violation(
                'config-cache-disabled',
                'Cache disabled',
                'test.php',
                1,
                0,
                Violation::SEVERITY_CRITICAL,
                Violation::TYPE_PERFORMANCE
            ))->setCategory('performance'),
        ];
        
        $stats = $this->generator->getFixableStatistics($violations);
        
        $this->assertEquals(3, $stats['total']);
        $this->assertEquals(2, $stats['fixable']);
        $this->assertEquals(1, $stats['unfixable']);
        $this->assertEquals(2, $stats['by_category']['performance']);
        $this->assertEquals(1, $stats['by_category']['quality']);  // Back to quality
        $this->assertEquals(2, $stats['by_generator']['magento_config']);
    }
    
    public function testGenerateFixScriptWithNoFixableViolations(): void
    {
        $this->generator->registerGenerator('magento_config', new MagentoConfigFixGenerator());
        
        $violations = [
            new Violation(
                'unfixable-rule-1',
                'Cannot fix this',
                'test.php',
                1,
                0,
                Violation::SEVERITY_ERROR,
                Violation::TYPE_LOGIC
            ),
            new Violation(
                'unfixable-rule-2',
                'Cannot fix this either',
                'test.php',
                1,
                0,
                Violation::SEVERITY_WARNING,
                Violation::TYPE_MAINTAINABILITY
            ),
        ];
        
        $scriptPath = $this->tempDir . '/test_fix.sh';
        $result = $this->generator->generateFixScript($violations, $scriptPath);
        
        $this->assertFileExists($scriptPath);
        $this->assertEquals(0, $result['fixable_count']);
        $this->assertEquals(2, $result['unfixable_count']);
        $this->assertEmpty($result['commands']);
    }
    
    public function testCommandsSortedByPriority(): void
    {
        $this->generator->registerGenerator('magento_config', new MagentoConfigFixGenerator());
        
        $violations = [
            new Violation(
                'config-merge-css-disabled',  // Priority 6
                'CSS merging disabled',
                'test.php',
                1,
                0,
                Violation::SEVERITY_WARNING,
                Violation::TYPE_PERFORMANCE
            ),
            new Violation(
                'config-cache-disabled',  // Priority 10 (highest)
                'Cache disabled',
                'test.php',
                1,
                0,
                Violation::SEVERITY_CRITICAL,
                Violation::TYPE_PERFORMANCE
            ),
            new Violation(
                'config-flat-catalog-disabled',  // Priority 8
                'Flat catalog disabled',
                'test.php',
                1,
                0,
                Violation::SEVERITY_WARNING,
                Violation::TYPE_PERFORMANCE
            ),
        ];
        
        $scriptPath = $this->tempDir . '/test_fix.sh';
        $result = $this->generator->generateFixScript($violations, $scriptPath);
        
        // Commands should be sorted by priority (highest first)
        $this->assertEquals(10, $result['commands'][0]->priority);  // Cache (priority 10)
        $this->assertEquals(8, $result['commands'][1]->priority);   // Flat catalog (priority 8)
        $this->assertEquals(6, $result['commands'][2]->priority);   // CSS merge (priority 6)
    }
}
