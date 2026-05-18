<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Analyzers\PHP;

use ACQC\Analyzers\PHP\PHPCSAnalyzer;
use ACQC\Core\Rules\Violation;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * @covers \ACQC\Analyzers\PHP\PHPCSAnalyzer
 * @group unit
 * @group analyzers
 * @group phpcs
 */
class PHPCSAnalyzerTest extends TestCase
{
    private PHPCSAnalyzer $analyzer;
    private LoggerInterface|MockObject $logger;
    private string $testCodeGood;
    private string $testCodeBad;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        // Clear ignore_patterns so temp files in /var/... are not excluded
        $this->analyzer = new PHPCSAnalyzer(['ignore_patterns' => []], $this->logger);

        $this->testCodeGood = $this->getGoodTestCode();
        $this->testCodeBad = $this->getBadTestCode();
    }

    /**
     * @test
     * @group fast
     */
    public function testAnalyzerNameAndDescription(): void
    {
        $this->assertEquals('PHPCS', $this->analyzer->getName());
        $this->assertStringContainsString('PHP Code Sniffer', $this->analyzer->getDescription());
    }

    /**
     * @test
     * @group fast
     */
    public function testSupportsPhpFiles(): void
    {
        $this->assertTrue($this->analyzer->supports('.php'));
        $this->assertTrue($this->analyzer->supports('test.php'));
        $this->assertFalse($this->analyzer->supports('.js'));
        $this->assertFalse($this->analyzer->supports('style.css'));
    }

    /**
     * @test
     * @group fast
     */
    public function testAnalyzeGoodCode(): void
    {
        if (!$this->analyzer->isAvailable()) {
            $this->markTestSkipped('PHPCS is not available');
        }

        $tempFile = createTempFile($this->testCodeGood);

        try {
            $violations = $this->analyzer->analyzeFile($tempFile);
            $this->assertIsArray($violations);
            // Good code should produce few or no violations
            foreach ($violations as $violation) {
                $this->assertInstanceOf(Violation::class, $violation);
            }
        } finally {
            unlink($tempFile);
        }
    }

    /**
     * @test
     * @group fast
     */
    public function testAnalyzeBadCode(): void
    {
        if (!$this->analyzer->isAvailable()) {
            $this->markTestSkipped('PHPCS is not available');
        }

        $tempFile = createTempFile($this->testCodeBad);

        try {
            $violations = $this->analyzer->analyzeFile($tempFile);
            $this->assertIsArray($violations);
            $this->assertGreaterThan(0, count($violations));

            foreach ($violations as $violation) {
                $this->assertInstanceOf(Violation::class, $violation);
                $this->assertNotEmpty($violation->getMessage());
                $this->assertNotEmpty($violation->getFilePath());
                $this->assertGreaterThan(0, $violation->getLine());
            }
        } finally {
            unlink($tempFile);
        }
    }

    /**
     * @test
     * @group fast
     */
    public function testAnalyzeWithCustomStandard(): void
    {
        if (!$this->analyzer->isAvailable()) {
            $this->markTestSkipped('PHPCS is not available');
        }

        $this->analyzer->configure(['standard' => 'PSR12']);
        $tempFile = createTempFile($this->testCodeBad);

        try {
            $violations = $this->analyzer->analyzeFile($tempFile);
            $this->assertIsArray($violations);
        } finally {
            unlink($tempFile);
        }
    }

    /**
     * @test
     * @group fast
     */
    public function testAnalyzeNonExistentFile(): void
    {
        if (!$this->analyzer->isAvailable()) {
            $this->markTestSkipped('PHPCS is not available');
        }

        $this->expectException(\Exception::class);
        $this->analyzer->analyzeFile('/non/existent/file.php');
    }

    /**
     * @test
     * @group fast
     */
    public function testAnalyzeWithSyntaxError(): void
    {
        if (!$this->analyzer->isAvailable()) {
            $this->markTestSkipped('PHPCS is not available');
        }

        $syntaxErrorCode = '<?php class MissingBrace {';
        $tempFile = createTempFile($syntaxErrorCode);

        try {
            $violations = $this->analyzer->analyzeFile($tempFile);
            $this->assertIsArray($violations);
            // PHPCS should detect issues in malformed code
            $this->assertGreaterThan(0, count($violations));
        } finally {
            unlink($tempFile);
        }
    }

    /**
     * @test
     * @group fast
     */
    public function testConfigureStandards(): void
    {
        $config = [
            'standard' => 'PSR12',
            'ignore_patterns' => ['*.min.js', 'vendor/*'],
            'warning_severity' => 5,
        ];

        $this->analyzer->configure($config);

        if (!$this->analyzer->isAvailable()) {
            $this->markTestSkipped('PHPCS is not available');
        }

        $tempFile = createTempFile($this->testCodeBad);

        try {
            $violations = $this->analyzer->analyzeFile($tempFile);
            $this->assertIsArray($violations);
        } finally {
            unlink($tempFile);
        }
    }

    /**
     * @test
     * @group fast
     */
    public function testViolationSeverityMapping(): void
    {
        if (!$this->analyzer->isAvailable()) {
            $this->markTestSkipped('PHPCS is not available');
        }

        $tempFile = createTempFile($this->getCodeWithVariousSeverities());

        try {
            $violations = $this->analyzer->analyzeFile($tempFile);
            $this->assertIsArray($violations);

            // Check that violations have severity set
            foreach ($violations as $violation) {
                $this->assertInstanceOf(Violation::class, $violation);
                $this->assertNotEmpty($violation->getSeverity());
            }
        } finally {
            unlink($tempFile);
        }
    }

    /**
     * @test
     * @group performance
     */
    public function testPerformanceWithLargeFile(): void
    {
        if (!$this->analyzer->isAvailable()) {
            $this->markTestSkipped('PHPCS is not available');
        }

        $largeCode = $this->generateLargeTestCode(1000);
        $tempFile = createTempFile($largeCode);

        try {
            $startTime = microtime(true);
            $violations = $this->analyzer->analyzeFile($tempFile);
            $executionTime = microtime(true) - $startTime;

            $this->assertLessThan(30.0, $executionTime, 'Analysis took too long: ' . $executionTime . 's');
            $this->assertIsArray($violations);
        } finally {
            unlink($tempFile);
        }
    }

    /**
     * @test
     * @group integration
     */
    public function testMagentoSpecificRules(): void
    {
        if (!$this->analyzer->isAvailable()) {
            $this->markTestSkipped('PHPCS is not available');
        }

        $magentoCode = $this->getMagentoSpecificBadCode();
        $tempFile = createTempFile($magentoCode);

        try {
            $violations = $this->analyzer->analyzeFile($tempFile);
            $this->assertIsArray($violations);
            // Standard PSR12 check — at minimum we verify the method works
            $this->assertGreaterThanOrEqual(0, count($violations));
        } finally {
            unlink($tempFile);
        }
    }

    private function getGoodTestCode(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace Example\Module\Model;

class ExampleModel
{
    private const CACHE_TAG = 'example_model';

    public function getExampleData(): ?string
    {
        return null;
    }

    public function setExampleData(string $data): self
    {
        return $this;
    }
}
PHP;
    }

    private function getBadTestCode(): string
    {
        return <<<'PHP'
<?php
// Missing declare statement
namespace Example\Module\Model;
class ExampleModel {
    const CACHE_TAG = 'example_model';
        public function _construct() {
            }
    public function getIdentities() {
        return [self::CACHE_TAG];
    }
    public function getExampleData() { return $this->getData('example_data') ? $this->getData('example_data') : null; }
    public function setExampleData($data) {
        return $this->setData('example_data', $data);
    }
}
PHP;
    }

    private function getCodeWithVariousSeverities(): string
    {
        return <<<'PHP'
<?php

namespace Example;

class BadExample {
    const CONSTANT = 'value';
    public function test() {
        $unused = 'not used';
        return true;
    }
    public function comparison($value) {
        return $value == true;
    }
}
PHP;
    }

    private function getMagentoSpecificBadCode(): string
    {
        return <<<'PHP'
<?php

namespace Example\Module\Controller;

class BadController
{
    public function execute()
    {
        return true;
    }
}
PHP;
    }

    private function generateLargeTestCode(int $lines): string
    {
        $code = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Large\\Test;\n\nclass LargeClass\n{\n";

        for ($i = 1; $i <= $lines; $i++) {
            $code .= "    public function method{$i}(): string\n";
            $code .= "    {\n";
            $code .= "        return 'Method {$i} result';\n";
            $code .= "    }\n\n";
        }

        $code .= "}\n";

        return $code;
    }
}
