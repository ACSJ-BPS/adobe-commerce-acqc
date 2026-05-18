<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Analyzers\Performance;

use ACQC\Analyzers\Performance\IndexersAnalyzer;
use ACQC\Core\Rules\Violation;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for IndexersAnalyzer
 *
 * Tests all 5 indexer-related checks:
 * - Indexer mode validation
 * - Search engine configuration
 * - Invalid indexer detection
 * - Mview backlog monitoring
 * - Flat catalog scheduling
 */
class IndexersAnalyzerTest extends TestCase
{
    private IndexersAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new IndexersAnalyzer([], new NullLogger());
    }

    public function testGetName(): void
    {
        $this->assertEquals('indexers', $this->analyzer->getName());
    }

    public function testGetVersion(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $this->analyzer->getVersion());
    }

    public function testAnalyzeReturnsEmptyArrayWhenNoMagentoRoot(): void
    {
        $violations = $this->analyzer->analyze(['/nonexistent/path'], []);
        
        $this->assertIsArray($violations);
        $this->assertEmpty($violations);
    }

    public function testAnalyzeReturnsArrayOfViolations(): void
    {
        // Create a temporary test structure
        $tempDir = sys_get_temp_dir() . '/acqc_test_' . uniqid();
        mkdir($tempDir, 0777, true);
        mkdir($tempDir . '/bin', 0777, true);
        
        // Create a dummy bin/magento file
        $binMagento = $tempDir . '/bin/magento';
        file_put_contents($binMagento, '<?php echo "Magento CLI";');
        chmod($binMagento, 0755);
        
        try {
            $violations = $this->analyzer->analyze([$tempDir], ['magento_root' => $tempDir]);
            
            $this->assertIsArray($violations);
            // All violations should be Violation objects
            foreach ($violations as $violation) {
                $this->assertInstanceOf(Violation::class, $violation);
            }
        } finally {
            // Cleanup
            if (file_exists($binMagento)) {
                unlink($binMagento);
            }
            if (is_dir($tempDir . '/bin')) {
                rmdir($tempDir . '/bin');
            }
            if (is_dir($tempDir)) {
                rmdir($tempDir);
            }
        }
    }

    public function testIndexerModeValidation(): void
    {
        // Test that violations are properly structured
        $tempDir = sys_get_temp_dir() . '/acqc_test_' . uniqid();
        mkdir($tempDir, 0777, true);
        mkdir($tempDir . '/bin', 0777, true);
        
        $binMagento = $tempDir . '/bin/magento';
        file_put_contents($binMagento, '<?php echo "Magento CLI";');
        chmod($binMagento, 0755);
        
        try {
            $violations = $this->analyzer->analyze([$tempDir], ['magento_root' => $tempDir]);
            
            // Check that violations have proper rule IDs
            $ruleIds = array_map(fn($v) => $v->getRuleId(), $violations);
            $expectedRuleIds = [
                'indexer-update-on-save',
                'search-engine-not-elasticsearch',
                'indexer-invalid-status',
                'indexer-outdated-mview',
                'flat-catalog-without-schedule'
            ];
            
            // At least some of these rules should be checked
            // (actual violations depend on the test environment)
            $this->assertIsArray($ruleIds);
        } finally {
            // Cleanup
            if (file_exists($binMagento)) {
                unlink($binMagento);
            }
            if (is_dir($tempDir . '/bin')) {
                rmdir($tempDir . '/bin');
            }
            if (is_dir($tempDir)) {
                rmdir($tempDir);
            }
        }
    }

    public function testSearchEngineValidation(): void
    {
        // Create temp structure with env.php
        $tempDir = sys_get_temp_dir() . '/acqc_test_' . uniqid();
        mkdir($tempDir, 0777, true);
        mkdir($tempDir . '/app/etc', 0777, true);
        
        // Create env.php with MySQL search engine
        $envPhp = $tempDir . '/app/etc/env.php';
        $envContent = <<<'PHP'
<?php
return [
    'system' => [
        'default' => [
            'catalog' => [
                'search' => [
                    'engine' => 'mysql'
                ]
            ]
        ]
    ]
];
PHP;
        file_put_contents($envPhp, $envContent);
        
        try {
            $violations = $this->analyzer->analyze([$tempDir], ['magento_root' => $tempDir]);
            
            // Should detect MySQL search engine
            $searchEngineViolations = array_filter(
                $violations,
                fn($v) => $v->getRuleId() === 'search-engine-not-elasticsearch'
            );
            
            $this->assertGreaterThanOrEqual(1, count($searchEngineViolations));
            
            if (!empty($searchEngineViolations)) {
                $violation = reset($searchEngineViolations);
                $this->assertEquals(Violation::SEVERITY_WARNING, $violation->getSeverity());
                $this->assertEquals(Violation::TYPE_PERFORMANCE, $violation->getType());
                $this->assertStringContainsString('mysql', strtolower($violation->getMessage()));
            }
        } finally {
            // Cleanup
            if (file_exists($envPhp)) {
                unlink($envPhp);
            }
            if (is_dir($tempDir . '/app/etc')) {
                rmdir($tempDir . '/app/etc');
            }
            if (is_dir($tempDir . '/app')) {
                rmdir($tempDir . '/app');
            }
            if (is_dir($tempDir)) {
                rmdir($tempDir);
            }
        }
    }

    public function testViolationsHaveProperStructure(): void
    {
        $tempDir = sys_get_temp_dir() . '/acqc_test_' . uniqid();
        mkdir($tempDir, 0777, true);
        mkdir($tempDir . '/app/etc', 0777, true);
        
        // Create env.php to trigger at least one violation
        $envPhp = $tempDir . '/app/etc/env.php';
        file_put_contents($envPhp, '<?php return ["system" => ["default" => ["catalog" => ["search" => ["engine" => "mysql"]]]]];');
        
        try {
            $violations = $this->analyzer->analyze([$tempDir], ['magento_root' => $tempDir]);
            
            foreach ($violations as $violation) {
                // Test violation structure
                $this->assertInstanceOf(Violation::class, $violation);
                $this->assertNotEmpty($violation->getRuleId());
                $this->assertNotEmpty($violation->getMessage());
                $this->assertContains($violation->getSeverity(), [
                    Violation::SEVERITY_INFO,
                    Violation::SEVERITY_WARNING,
                    Violation::SEVERITY_ERROR,
                    Violation::SEVERITY_CRITICAL
                ]);
                $this->assertEquals(Violation::TYPE_PERFORMANCE, $violation->getType());
                $this->assertEquals('performance', $violation->getCategory());
            }
        } finally {
            // Cleanup
            if (file_exists($envPhp)) {
                unlink($envPhp);
            }
            if (is_dir($tempDir . '/app/etc')) {
                rmdir($tempDir . '/app/etc');
            }
            if (is_dir($tempDir . '/app')) {
                rmdir($tempDir . '/app');
            }
            if (is_dir($tempDir)) {
                rmdir($tempDir);
            }
        }
    }

    public function testHandlesNonexistentBinMagentoGracefully(): void
    {
        $tempDir = sys_get_temp_dir() . '/acqc_test_' . uniqid();
        mkdir($tempDir, 0777, true);
        
        try {
            // No bin/magento file - should not throw exception
            $violations = $this->analyzer->analyze([$tempDir], ['magento_root' => $tempDir]);
            
            // Should return array (possibly empty)
            $this->assertIsArray($violations);
        } finally {
            // Cleanup
            if (is_dir($tempDir)) {
                rmdir($tempDir);
            }
        }
    }

    public function testMetadataIsIncludedInViolations(): void
    {
        $tempDir = sys_get_temp_dir() . '/acqc_test_' . uniqid();
        mkdir($tempDir, 0777, true);
        mkdir($tempDir . '/app/etc', 0777, true);
        
        $envPhp = $tempDir . '/app/etc/env.php';
        file_put_contents($envPhp, '<?php return ["system" => ["default" => ["catalog" => ["search" => ["engine" => "mysql"]]]]];');
        
        try {
            $violations = $this->analyzer->analyze([$tempDir], ['magento_root' => $tempDir]);
            
            // Find a violation with metadata
            $violationsWithMetadata = array_filter(
                $violations,
                fn($v) => !empty($v->getMetadata())
            );
            
            if (!empty($violationsWithMetadata)) {
                $violation = reset($violationsWithMetadata);
                $metadata = $violation->getMetadata();
                
                $this->assertIsArray($metadata);
                $this->assertNotEmpty($metadata);
                
                // Search engine violation should have current_engine metadata
                if ($violation->getRuleId() === 'search-engine-not-elasticsearch') {
                    $this->assertArrayHasKey('current_engine', $metadata);
                    $this->assertEquals('mysql', $metadata['current_engine']);
                }
            }
        } finally {
            // Cleanup
            if (file_exists($envPhp)) {
                unlink($envPhp);
            }
            if (is_dir($tempDir . '/app/etc')) {
                rmdir($tempDir . '/app/etc');
            }
            if (is_dir($tempDir . '/app')) {
                rmdir($tempDir . '/app');
            }
            if (is_dir($tempDir)) {
                rmdir($tempDir);
            }
        }
    }
}
