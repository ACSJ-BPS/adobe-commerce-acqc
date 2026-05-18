<?php

declare(strict_types=1);

namespace ACQC\Tests\Performance;

use ACQC\Analyzers\PHP\PHPCSAnalyzer;
use ACQC\Analyzers\SecurityAnalyzer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ACQC\Analyzers\PHP\PHPCSAnalyzer
 * @covers \ACQC\Analyzers\SecurityAnalyzer
 * @group performance
 * @group slow
 */
class AnalyzerPerformanceTest extends TestCase
{
    /**
     * @test
     */
    public function testPHPCSAnalyzerPerformance(): void
    {
        $analyzer = new PHPCSAnalyzer();

        if (!$analyzer->isAvailable()) {
            $this->markTestSkipped('PHPCS is not available');
        }

        $largeFile = $this->generateLargeTestFile(5000); // 5000 lines

        try {
            $startTime = microtime(true);
            $violations = $analyzer->analyzeFile($largeFile);
            $executionTime = microtime(true) - $startTime;

            $this->assertLessThan(30.0, $executionTime, 'PHPCS analysis took too long');
            $this->assertIsArray($violations);
        } finally {
            unlink($largeFile);
        }
    }

    /**
     * @test
     */
    public function testMemoryUsageUnderLoad(): void
    {
        $analyzer = new SecurityAnalyzer();
        $memoryBefore = memory_get_usage(true);

        for ($i = 0; $i < 100; $i++) {
            $testFile = createTempFile($this->getTestCode());
            try {
                $analyzer->analyze([$testFile]);
            } catch (\Exception $e) {
                // Tool may not be available; just measure memory
            }
            unlink($testFile);
        }

        $memoryAfter = memory_get_usage(true);
        $memoryIncrease = $memoryAfter - $memoryBefore;

        // Should not increase by more than 50MB
        $this->assertLessThan(50 * 1024 * 1024, $memoryIncrease, 'Memory usage increased too much');
    }

    private function generateLargeTestFile(int $lines): string
    {
        $content = "<?php\n\nclass LargeTestClass\n{\n";

        for ($i = 1; $i <= $lines; $i++) {
            $content .= "    public function method{$i}(): string { return 'test'; }\n";
        }

        $content .= "}\n";

        return createTempFile($content);
    }

    private function getTestCode(): string
    {
        return '<?php class Test { public function method() { return true; } }';
    }
}
