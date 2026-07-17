<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Core\Coverage;

use ACQC\Core\Coverage\CloverXmlGenerator;
use PHPUnit\Framework\TestCase;

class CloverXmlGeneratorTest extends TestCase
{
    private CloverXmlGenerator $generator;
    private string $targetDir;
    private string $outputFile;

    protected function setUp(): void
    {
        $this->generator = new CloverXmlGenerator();
        $this->targetDir = sys_get_temp_dir() . '/acqc_clover_gen_' . uniqid();
        mkdir($this->targetDir . '/Vendor/ModuleA', 0777, true);

        file_put_contents($this->targetDir . '/Vendor/ModuleA/Foo.php', <<<PHP
<?php
class Foo
{
    public function bar()
    {
        return 1;
    }
}
PHP);

        $this->outputFile = sys_get_temp_dir() . '/acqc_clover_out_' . uniqid() . '/clover.xml';
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->targetDir);
        $this->removeDir(dirname($this->outputFile));
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    public function testGeneratesBaselineCloverWithMarkerAndZeroCoverage(): void
    {
        $result = $this->generator->generateToFile($this->targetDir, $this->outputFile, 'Test Project', []);

        $this->assertSame($this->outputFile, $result);
        $this->assertFileExists($this->outputFile);

        $content = file_get_contents($this->outputFile);
        $firstBytes = substr($content, 0, 512);

        $this->assertStringContainsString('acqc-static-analysis', $firstBytes);
        $this->assertStringContainsString('coveredstatements="0"', $content);
        $this->assertStringContainsString('coveredmethods="0"', $content);
    }

    public function testExcludeListSkipsMatchingPaths(): void
    {
        mkdir($this->targetDir . '/Vendor/ModuleA/tests', 0777, true);
        file_put_contents($this->targetDir . '/Vendor/ModuleA/tests/FooTest.php', '<?php class FooTest {}');

        $this->generator->generateToFile($this->targetDir, $this->outputFile, 'Test Project', ['/tests/']);

        $content = file_get_contents($this->outputFile);
        $this->assertStringNotContainsString('FooTest.php', $content);
        $this->assertStringContainsString('Foo.php', $content);
    }

    public function testValidateAcceptsItsOwnOutput(): void
    {
        $this->generator->generateToFile($this->targetDir, $this->outputFile, 'Test Project', []);
        $content = file_get_contents($this->outputFile);

        $validation = $this->generator->validate($content);

        $this->assertTrue($validation['valid']);
        $this->assertSame([], $validation['errors']);
    }

    public function testValidateRejectsGarbageXml(): void
    {
        $validation = $this->generator->validate('<not-even-close-to-valid');

        $this->assertFalse($validation['valid']);
        $this->assertNotEmpty($validation['errors']);
    }

    public function testValidateRejectsWrongRootElement(): void
    {
        $validation = $this->generator->validate('<?xml version="1.0"?><notcoverage></notcoverage>');

        $this->assertFalse($validation['valid']);
        $this->assertNotEmpty($validation['errors']);
    }
}
