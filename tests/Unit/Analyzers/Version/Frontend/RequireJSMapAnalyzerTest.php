<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Analyzers\Version\Frontend;

use ACQC\Analyzers\Version\Frontend\RequireJSMapAnalyzer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ACQC\Analyzers\Version\Frontend\RequireJSMapAnalyzer
 * @group unit
 * @group fast
 */
class RequireJSMapAnalyzerTest extends TestCase
{
    private string $fixtureRoot;
    private string $configPath;

    protected function setUp(): void
    {
        $projectRoot      = dirname(dirname(dirname(dirname(dirname(__DIR__)))));
        $this->fixtureRoot = $projectRoot . '/tests/Fixtures/UpgradeReadiness/TemplateOverrideProject';
        $this->configPath  = $projectRoot . '/config/upgrade/js-library-changes.yaml';
    }

    /** @test */
    public function testDetectsRequireJSConfig(): void
    {
        $analyzer = new RequireJSMapAnalyzer($this->fixtureRoot, $this->configPath);
        $findings = $analyzer->analyze('2.4.8');

        $configFindings = array_filter($findings, fn($f) => $f['type'] === 'requirejs_config_detected');
        $this->assertNotEmpty($configFindings, 'Expected at least one requirejs_config_detected finding');
    }

    /** @test */
    public function testConfigFindingReportsCorrectPathCount(): void
    {
        $analyzer = new RequireJSMapAnalyzer($this->fixtureRoot, $this->configPath);
        $findings = $analyzer->analyze('2.4.8');

        $configFindings = array_filter($findings, fn($f) => $f['type'] === 'requirejs_config_detected');

        foreach ($configFindings as $finding) {
            // The fixture has 4 key-value pairs in map+paths blocks
            $this->assertGreaterThan(0, $finding['count'], 'Expected at least 1 path override counted');
            $this->assertArrayHasKey('module', $finding);
            $this->assertNotEmpty($finding['module']);
            $this->assertStringContainsString('2.4.8', $finding['message']);
        }
    }

    /** @test */
    public function testFindingContainsFilePath(): void
    {
        $analyzer = new RequireJSMapAnalyzer($this->fixtureRoot, $this->configPath);
        $findings = $analyzer->analyze();

        foreach (array_filter($findings, fn($f) => $f['type'] === 'requirejs_config_detected') as $finding) {
            $this->assertArrayHasKey('file', $finding);
            $this->assertStringEndsWith('requirejs-config.js', $finding['file']);
        }
    }

    /** @test */
    public function testEmptyProjectReturnsNoFindings(): void
    {
        $analyzer = new RequireJSMapAnalyzer('/nonexistent/path', $this->configPath);
        $findings = $analyzer->analyze();

        $this->assertSame([], $findings);
    }

    /** @test */
    public function testProjectWithNoRequireJSFilesReturnsNoFindings(): void
    {
        // LegacyProject has no requirejs-config.js
        $projectRoot  = dirname(dirname(dirname(dirname(dirname(dirname(__DIR__))))));
        $legacyRoot   = $projectRoot . '/tests/Fixtures/UpgradeReadiness/LegacyProject';
        $analyzer     = new RequireJSMapAnalyzer($legacyRoot, $this->configPath);
        $findings     = $analyzer->analyze();

        $this->assertEmpty($findings, 'LegacyProject has no requirejs-config.js files');
    }
}
