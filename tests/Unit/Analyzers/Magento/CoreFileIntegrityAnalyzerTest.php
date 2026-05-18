<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Analyzers\Magento;

use ACQC\Analyzers\Magento\CoreFileIntegrityAnalyzer;
use ACQC\Core\Rules\Violation;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ACQC\Analyzers\Magento\CoreFileIntegrityAnalyzer
 * @group unit
 * @group analyzers
 * @group fast
 */
class CoreFileIntegrityAnalyzerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/acqc_core_integrity_test_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    /** @test */
    public function testAnalyzerMetadata(): void
    {
        $analyzer = new CoreFileIntegrityAnalyzer();
        $this->assertSame('magento-core-file-integrity', $analyzer->getName());
        $this->assertTrue($analyzer->isAvailable());
    }

    /** @test */
    public function testReturnsNoViolationsWhenVanillaNotPresent(): void
    {
        $analyzer = new CoreFileIntegrityAnalyzer([
            'target_magento_version' => '2.4.8',
        ]);

        $violations = $analyzer->analyze([]);
        $this->assertEmpty($violations);
    }

    /** @test */
    public function testDetectsModifiedCoreFile(): void
    {
        // Vanilla: vendor/magento/module-catalog/Model/Product.php  (original)
        // Project: vendor/magento/module-catalog/Model/Product.php  (modified)
        $vanillaDir = $this->tmpDir . '/vanilla';
        $projectDir = $this->tmpDir . '/project';

        $this->createFile($vanillaDir . '/vendor/magento/module-catalog/Model/Product.php', '<?php // original');
        $this->createFile($projectDir . '/vendor/magento/module-catalog/Model/Product.php', '<?php // modified by developer');

        $analyzer = new CoreFileIntegrityAnalyzer([
            'target_magento_version' => '2.4.8',
            'vanilla_base_path'      => $vanillaDir,
            'vendor_path'            => $projectDir . '/vendor',
            'vendor_prefixes'        => ['magento/'],
        ]);

        $violations = $analyzer->analyze([]);
        $this->assertNotEmpty($violations);

        $ruleIds = array_map(fn(Violation $v) => $v->getRuleId(), $violations);
        $this->assertContains('magento-core-file-modified', $ruleIds);

        $severities = array_map(fn(Violation $v) => $v->getSeverity(), $violations);
        $this->assertContains(Violation::SEVERITY_CRITICAL, $severities);
    }

    /** @test */
    public function testNoViolationForUnchangedFile(): void
    {
        $vanillaDir = $this->tmpDir . '/vanilla';
        $projectDir = $this->tmpDir . '/project';

        $content = '<?php // identical content';
        $this->createFile($vanillaDir . '/vendor/magento/module-catalog/Model/Product.php', $content);
        $this->createFile($projectDir . '/vendor/magento/module-catalog/Model/Product.php', $content);

        $analyzer = new CoreFileIntegrityAnalyzer([
            'target_magento_version' => '2.4.8',
            'vanilla_base_path'      => $vanillaDir,
            'vendor_path'            => $projectDir . '/vendor',
            'vendor_prefixes'        => ['magento/'],
        ]);

        $violations = $analyzer->analyze([]);
        $this->assertEmpty($violations);
    }

    /** @test */
    public function testDetectsAddedFileInCorePackage(): void
    {
        $vanillaDir = $this->tmpDir . '/vanilla';
        $projectDir = $this->tmpDir . '/project';

        $this->createFile($vanillaDir . '/vendor/magento/module-catalog/Model/Product.php', '<?php // original');
        $this->createFile($projectDir . '/vendor/magento/module-catalog/Model/Product.php', '<?php // original');
        // Extra file not in vanilla
        $this->createFile($projectDir . '/vendor/magento/module-catalog/Model/CustomHack.php', '<?php // added');

        $analyzer = new CoreFileIntegrityAnalyzer([
            'target_magento_version' => '2.4.8',
            'vanilla_base_path'      => $vanillaDir,
            'vendor_path'            => $projectDir . '/vendor',
            'vendor_prefixes'        => ['magento/'],
            'check_added'            => true,
        ]);

        $violations = $analyzer->analyze([]);
        $ruleIds = array_map(fn(Violation $v) => $v->getRuleId(), $violations);
        $this->assertContains('magento-core-file-added', $ruleIds);
    }

    /** @test */
    public function testCheckAddedCanBeDisabled(): void
    {
        $vanillaDir = $this->tmpDir . '/vanilla';
        $projectDir = $this->tmpDir . '/project';

        $this->createFile($vanillaDir . '/vendor/magento/module-catalog/Model/Product.php', '<?php // original');
        $this->createFile($projectDir . '/vendor/magento/module-catalog/Model/Product.php', '<?php // original');
        $this->createFile($projectDir . '/vendor/magento/module-catalog/Model/CustomHack.php', '<?php // added');

        $analyzer = new CoreFileIntegrityAnalyzer([
            'target_magento_version' => '2.4.8',
            'vanilla_base_path'      => $vanillaDir,
            'vendor_path'            => $projectDir . '/vendor',
            'vendor_prefixes'        => ['magento/'],
            'check_added'            => false,
        ]);

        $violations = $analyzer->analyze([]);
        $ruleIds = array_map(fn(Violation $v) => $v->getRuleId(), $violations);
        $this->assertNotContains('magento-core-file-added', $ruleIds);
    }

    /** @test */
    public function testVanillaMissingFileIsNotAViolation(): void
    {
        // Project may prune vendor files (e.g. remove test suites) — not a violation
        $vanillaDir = $this->tmpDir . '/vanilla';
        $projectDir = $this->tmpDir . '/project';

        $this->createFile($vanillaDir . '/vendor/magento/module-catalog/Model/Product.php', '<?php // original');
        $this->createFile($vanillaDir . '/vendor/magento/module-catalog/Test/Unit/Foo.php', '<?php // test');
        // Project only has Product.php, Test file is pruned
        $this->createFile($projectDir . '/vendor/magento/module-catalog/Model/Product.php', '<?php // original');

        $analyzer = new CoreFileIntegrityAnalyzer([
            'target_magento_version' => '2.4.8',
            'vanilla_base_path'      => $vanillaDir,
            'vendor_path'            => $projectDir . '/vendor',
            'vendor_prefixes'        => ['magento/'],
        ]);

        $violations = $analyzer->analyze([]);
        $this->assertEmpty($violations);
    }

    // -------------------------------------------------------------------------

    private function createFile(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($path, $content);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }
        rmdir($dir);
    }
}
