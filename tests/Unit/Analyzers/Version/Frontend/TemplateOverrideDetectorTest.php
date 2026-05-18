<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Analyzers\Version\Frontend;

use ACQC\Analyzers\Version\Frontend\TemplateOverrideDetector;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ACQC\Analyzers\Version\Frontend\TemplateOverrideDetector
 * @group unit
 * @group fast
 */
class TemplateOverrideDetectorTest extends TestCase
{
    private string $fixtureRoot;
    private string $configPath;

    protected function setUp(): void
    {
        $projectRoot      = dirname(dirname(dirname(dirname(dirname(__DIR__)))));
        $this->fixtureRoot = $projectRoot . '/tests/Fixtures/UpgradeReadiness/TemplateOverrideProject';
        $this->configPath  = $projectRoot . '/config/upgrade/template-changes.yaml';
    }

    /** @test */
    public function testDetectsTemplateOverrides(): void
    {
        $detector = new TemplateOverrideDetector($this->fixtureRoot, $this->configPath);
        $findings = $detector->detect('2.4.8');

        $overrides = array_filter($findings, fn($f) => $f['type'] === 'template_override_detected');

        $this->assertCount(2, $overrides, 'Expected exactly 2 template_override_detected findings');
    }

    /** @test */
    public function testOverrideFindingsHaveCorrectStructure(): void
    {
        $detector = new TemplateOverrideDetector($this->fixtureRoot, $this->configPath);
        $findings = $detector->detect('2.4.8');

        foreach (array_filter($findings, fn($f) => $f['type'] === 'template_override_detected') as $finding) {
            $this->assertSame('info', $finding['severity']);
            $this->assertNotEmpty($finding['module']);
            $this->assertNotEmpty($finding['template_path']);
            $this->assertStringContainsString('::', $finding['message']);
            $this->assertStringContainsString('2.4.8', $finding['remediation']);
            $this->assertSame(1, $finding['effort_hours_min']);
            $this->assertSame(4, $finding['effort_hours_max']);
        }
    }

    /** @test */
    public function testDetectsCheckoutCartOverride(): void
    {
        $detector = new TemplateOverrideDetector($this->fixtureRoot, $this->configPath);
        $findings = $detector->detect();

        $messages = array_column(
            array_filter($findings, fn($f) => $f['type'] === 'template_override_detected'),
            'message'
        );

        $found = false;
        foreach ($messages as $msg) {
            if (str_contains($msg, 'Magento_Checkout') && str_contains($msg, 'cart/item/default.phtml')) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'Expected finding for Magento_Checkout cart/item/default.phtml override');
    }

    /** @test */
    public function testDetectsCatalogProductOverride(): void
    {
        $detector = new TemplateOverrideDetector($this->fixtureRoot, $this->configPath);
        $findings = $detector->detect();

        $messages = array_column(
            array_filter($findings, fn($f) => $f['type'] === 'template_override_detected'),
            'message'
        );

        $found = false;
        foreach ($messages as $msg) {
            if (str_contains($msg, 'Magento_Catalog') && str_contains($msg, 'product/view.phtml')) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'Expected finding for Magento_Catalog product/view.phtml override');
    }

    /** @test */
    public function testSummaryFindingIsEmittedForNonZeroCount(): void
    {
        $detector = new TemplateOverrideDetector($this->fixtureRoot, $this->configPath);
        $findings = $detector->detect();

        $summaries = array_filter($findings, fn($f) => $f['type'] === 'template_override_summary');
        $this->assertNotEmpty($summaries, 'Expected a template_override_summary finding');
    }

    /** @test */
    public function testEmptyCodebaseReturnsNoFindings(): void
    {
        $detector = new TemplateOverrideDetector('/nonexistent/path/does/not/exist', $this->configPath);
        $findings = $detector->detect();

        $this->assertSame([], $findings);
    }

    /** @test */
    public function testProjectWithNoDesignDirReturnsEmpty(): void
    {
        // ModernProject has no app/design dir
        $projectRoot  = dirname(dirname(dirname(dirname(dirname(dirname(__DIR__))))));
        $modernRoot   = $projectRoot . '/tests/Fixtures/UpgradeReadiness/ModernProject';
        $detector     = new TemplateOverrideDetector($modernRoot, $this->configPath);
        $findings     = $detector->detect();

        $overrides = array_filter($findings, fn($f) => $f['type'] === 'template_override_detected');
        $this->assertEmpty($overrides, 'ModernProject has no app/design dir so no overrides expected');
    }
}
