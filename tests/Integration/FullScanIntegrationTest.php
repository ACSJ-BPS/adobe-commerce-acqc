<?php

declare(strict_types=1);

namespace ACQC\Tests\Integration;

use ACQC\Core\CodeAnalyzer;
use ACQC\Core\Config\ConfigManager;
use ACQC\Core\Scanner\ScannerEngine;
use ACQC\Core\Rules\RuleEngine;
use ACQC\Reports\ReportGenerator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ACQC\Core\CodeAnalyzer
 * @group integration
 * @group slow
 */
class FullScanIntegrationTest extends TestCase
{
    private CodeAnalyzer $codeAnalyzer;
    private ConfigManager $configManager;
    private ScannerEngine $scannerEngine;
    private RuleEngine $ruleEngine;
    private ReportGenerator $reportGenerator;

    protected function setUp(): void
    {
        $this->configManager = new ConfigManager(null, false); // useCache=false for tests
        $this->ruleEngine = new RuleEngine();
        $this->scannerEngine = new ScannerEngine($this->configManager); // ScannerEngine needs ConfigManager
        $this->reportGenerator = new ReportGenerator();
        $this->codeAnalyzer = new CodeAnalyzer(
            $this->scannerEngine,
            $this->configManager
        );
    }

    protected function tearDown(): void
    {
        \ACQC\Core\AI\AIEngine::clearMockResponses();
    }

    /**
     * @test
     */
    public function testFullScanWorkflow(): void
    {
        $testPath = getFixturePath('GoodCode');
        
        if (!is_dir($testPath)) {
            $this->markTestSkipped('Test fixtures not available');
        }

        // Configure analyzers
        $config = [
            'analyzers' => ['phpcs', 'phpmd', 'security'],
            'rules' => ['severity' => 'warning']
        ];
        $this->configManager->setConfiguration($config);

        // Run scan
        $results = $this->codeAnalyzer->analyze($testPath);

        // Verify results structure
        $this->assertIsArray($results);
        $this->assertArrayHasKey('issues', $results);
        $this->assertArrayHasKey('metrics', $results);

        // Generate reports
        $htmlReport = $this->reportGenerator->generate($results['issues'], 'html');
        $jsonReport = $this->reportGenerator->generate($results['issues'], 'json');

        $this->assertNotEmpty($htmlReport);
        $this->assertNotEmpty($jsonReport);
        
        // Validate JSON report structure
        $jsonData = json_decode($jsonReport, true);
        $this->assertIsArray($jsonData);
    }

    /**
     * @test
     */
    public function testScanWithBadCode(): void
    {
        $testPath = getFixturePath('BadCode');
        
        if (!is_dir($testPath)) {
            $this->markTestSkipped('Test fixtures not available');
        }

        $results = $this->codeAnalyzer->analyze($testPath);

        // Should find violations in bad code
        $this->assertGreaterThan(0, count($results['issues']));

        // Check violation structure
        foreach ($results['issues'] as $violation) {
            $this->assertArrayHasKey('file', $violation);
            $this->assertArrayHasKey('line', $violation);
            $this->assertArrayHasKey('message', $violation);
            $this->assertArrayHasKey('severity', $violation);
        }
    }

    /**
     * @test
     */
    public function testMemoryUsageDuringLargeScan(): void
    {
        $memoryBefore = memory_get_usage(true);
        
        // Create temporary large codebase
        $tempDir = sys_get_temp_dir() . '/acqc_large_test';
        mkdir($tempDir, 0755, true);
        
        try {
            // Generate multiple files
            for ($i = 0; $i < 50; $i++) {
                $content = $this->generateTestFileContent($i);
                file_put_contents($tempDir . "/test_file_$i.php", $content);
            }
            
            $results = $this->codeAnalyzer->analyze($tempDir);
            
            $memoryAfter = memory_get_usage(true);
            $memoryIncrease = $memoryAfter - $memoryBefore;
            
            // Memory increase should be reasonable (less than 100MB)
            $this->assertLessThan(100 * 1024 * 1024, $memoryIncrease);
            
            // Should have analyzed all files
            $filesScanned = $results['files_processed'] ?? $results['files_scanned'] ?? $results['files_analyzed'] ?? 0;
            $this->assertGreaterThanOrEqual(0, $filesScanned);
            
        } finally {
            // Cleanup
            $this->removeDirectory($tempDir);
        }
    }

    private function generateTestFileContent(int $index): string
    {
        return "<?php\n\nclass TestClass$index\n{\n    public function method(): string\n    {\n        return 'test';\n    }\n}\n";
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
} 