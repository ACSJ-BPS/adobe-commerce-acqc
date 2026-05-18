<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Analyzers\Version\DB;

use ACQC\Analyzers\Version\DB\LegacyScriptDetector;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ACQC\Analyzers\Version\DB\LegacyScriptDetector
 * @group unit
 * @group fast
 */
class LegacyScriptDetectorTest extends TestCase
{
    private string $legacyRoot;
    private string $modernRoot;

    protected function setUp(): void
    {
        $fixturesBase    = dirname(dirname(dirname(dirname(dirname(__DIR__))))) . '/tests/Fixtures/UpgradeReadiness';
        $this->legacyRoot = $fixturesBase . '/LegacyProject';
        $this->modernRoot = $fixturesBase . '/ModernProject';
    }

    /** @test */
    public function testDetectsInstallSchemaFile(): void
    {
        $detector = new LegacyScriptDetector($this->legacyRoot);
        $findings = $detector->detect();

        $this->assertNotEmpty($findings, 'Expected at least one legacy script finding for LegacyProject');

        $types = array_column($findings, 'type');
        $this->assertContains('legacy_setup_script_detected', $types);

        $severities = array_column($findings, 'severity');
        foreach ($severities as $sev) {
            $this->assertSame('error', $sev);
        }

        // Verify effort range is populated
        foreach ($findings as $finding) {
            $this->assertArrayHasKey('effort_hours_min', $finding);
            $this->assertArrayHasKey('effort_hours_max', $finding);
            $this->assertGreaterThan(0, $finding['effort_hours_min']);
            $this->assertGreaterThan($finding['effort_hours_min'], $finding['effort_hours_max']);
        }
    }

    /** @test */
    public function testDetectsUpgradeDataFile(): void
    {
        $detector = new LegacyScriptDetector($this->legacyRoot);
        $findings = $detector->detect();

        $files = array_column($findings, 'file');
        $found = false;
        foreach ($files as $file) {
            if (str_contains($file, 'UpgradeData.php')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected UpgradeData.php to be detected as a legacy script');
    }

    /** @test */
    public function testDoesNotFlagModernDataPatch(): void
    {
        $detector = new LegacyScriptDetector($this->modernRoot);
        $findings = $detector->detect();

        $this->assertEmpty($findings, 'Modern DataPatch should not be flagged as a legacy script');
    }

    /** @test */
    public function testReturnsEmptyForNonExistentDirectory(): void
    {
        $detector = new LegacyScriptDetector('/nonexistent/path/that/does/not/exist');
        $findings = $detector->detect();

        $this->assertSame([], $findings);
    }

    /** @test */
    public function testFindingHasRemediationGuidance(): void
    {
        $detector = new LegacyScriptDetector($this->legacyRoot);
        $findings = $detector->detect();

        foreach ($findings as $finding) {
            $this->assertArrayHasKey('remediation', $finding);
            $this->assertNotEmpty($finding['remediation']);
            $this->assertStringContainsString('db_schema.xml', $finding['remediation']);
        }
    }
}
