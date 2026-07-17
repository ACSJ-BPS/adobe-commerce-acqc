<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Core\Coverage;

use ACQC\Core\Coverage\PHPUnitTextCoverageParser;
use PHPUnit\Framework\TestCase;

class PHPUnitTextCoverageParserTest extends TestCase
{
    private PHPUnitTextCoverageParser $parser;
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->parser = new PHPUnitTextCoverageParser();
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'acqc_coverage_text_');
    }

    protected function tearDown(): void
    {
        if (is_file($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    public function testParsesValidSummaryBlock(): void
    {
        file_put_contents($this->tmpFile, <<<TXT
Code Coverage Report:

 Summary:
  Classes: 50.00% (5/10)
  Methods: 75.00% (30/40)
  Lines:   80.00% (400/500)
TXT);

        $result = $this->parser->parse($this->tmpFile);

        $this->assertEqualsWithDelta(0.80, $result['line_coverage'], 0.0001);
        $this->assertEqualsWithDelta(0.75, $result['function_coverage'], 0.0001);
        $this->assertEqualsWithDelta(0.80 * 0.85, $result['branch_coverage'], 0.0001);
    }

    public function testReturnsZeroedOnMalformedContent(): void
    {
        file_put_contents($this->tmpFile, 'not a coverage report');

        $result = $this->parser->parse($this->tmpFile);

        $this->assertSame(0.0, $result['line_coverage']);
        $this->assertSame(0.0, $result['branch_coverage']);
        $this->assertSame(0.0, $result['function_coverage']);
    }

    public function testReturnsZeroedOnMissingFile(): void
    {
        $result = $this->parser->parse('/nonexistent/path/coverage.txt');

        $this->assertSame(0.0, $result['line_coverage']);
        $this->assertSame(0.0, $result['branch_coverage']);
        $this->assertSame(0.0, $result['function_coverage']);
    }
}
