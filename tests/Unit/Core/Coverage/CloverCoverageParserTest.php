<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Core\Coverage;

use ACQC\Core\Coverage\CloverCoverageParser;
use PHPUnit\Framework\TestCase;

class CloverCoverageParserTest extends TestCase
{
    private CloverCoverageParser $parser;
    private string $tmpFile;
    private string $basePath = '/project';

    protected function setUp(): void
    {
        $this->parser = new CloverCoverageParser();
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'acqc_clover_');
    }

    protected function tearDown(): void
    {
        if (is_file($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    private function writeClover(string $xml): void
    {
        file_put_contents($this->tmpFile, $xml);
    }

    public function testParsesProjectLevelMetricsAsFractions(): void
    {
        $this->writeClover(<<<XML
<?xml version="1.0" encoding="UTF-8"?>
<coverage generated="123">
  <project name="test" timestamp="123">
    <metrics statements="500" coveredstatements="400" methods="40" coveredmethods="30" elements="540" coveredelements="430"/>
  </project>
</coverage>
XML);

        $result = $this->parser->parse($this->tmpFile, $this->basePath);

        $this->assertEqualsWithDelta(0.80, $result['line_coverage'], 0.0001);
        $this->assertEqualsWithDelta(0.75, $result['function_coverage'], 0.0001);
        $this->assertEqualsWithDelta(0.80 * 0.85, $result['branch_coverage'], 0.0001);
    }

    public function testReturnsZeroedOnMalformedXml(): void
    {
        $this->writeClover('<not-valid-xml');

        $result = $this->parser->parse($this->tmpFile, $this->basePath);

        $this->assertSame(0.0, $result['line_coverage']);
        $this->assertSame(0.0, $result['branch_coverage']);
        $this->assertSame(0.0, $result['function_coverage']);
    }

    public function testReturnsZeroedOnMissingFile(): void
    {
        $result = $this->parser->parse('/nonexistent/clover.xml', $this->basePath);

        $this->assertSame(0.0, $result['line_coverage']);
        $this->assertSame(0.0, $result['function_coverage']);
    }

    public function testModuleBreakdownGroupsByVendorModuleAndUsesPercentageScale(): void
    {
        $this->writeClover(<<<XML
<?xml version="1.0" encoding="UTF-8"?>
<coverage generated="123">
  <project name="test" timestamp="123">
    <package name="Vendor.ModuleA">
      <file name="{$this->basePath}/Vendor/ModuleA/Foo.php">
        <metrics statements="100" coveredstatements="50" methods="10" coveredmethods="5" elements="110" coveredelements="55"/>
      </file>
      <file name="{$this->basePath}/Vendor/ModuleA/Bar.php">
        <metrics statements="100" coveredstatements="100" methods="10" coveredmethods="10" elements="110" coveredelements="110"/>
      </file>
    </package>
    <file name="{$this->basePath}/Vendor/ModuleB/Baz.php">
      <metrics statements="50" coveredstatements="0" methods="5" coveredmethods="0" elements="55" coveredelements="0"/>
    </file>
  </project>
</coverage>
XML);

        $breakdown = $this->parser->parseWithModuleBreakdown($this->tmpFile, $this->basePath);

        $this->assertArrayHasKey('Vendor_ModuleA', $breakdown['modules']);
        $this->assertArrayHasKey('Vendor_ModuleB', $breakdown['modules']);

        $moduleA = $breakdown['modules']['Vendor_ModuleA'];
        $this->assertSame(150, $moduleA['lines']['covered']);
        $this->assertSame(200, $moduleA['lines']['total']);
        $this->assertEqualsWithDelta(75.0, $moduleA['lines']['pct'], 0.0001);

        $moduleB = $breakdown['modules']['Vendor_ModuleB'];
        $this->assertSame(0, $moduleB['lines']['covered']);
        $this->assertSame(50, $moduleB['lines']['total']);
        $this->assertEqualsWithDelta(0.0, $moduleB['lines']['pct'], 0.0001);
    }

    public function testModuleBreakdownReturnsEmptyModulesOnMissingFile(): void
    {
        $breakdown = $this->parser->parseWithModuleBreakdown('/nonexistent/clover.xml', $this->basePath);

        $this->assertSame(['modules' => []], $breakdown);
    }
}
