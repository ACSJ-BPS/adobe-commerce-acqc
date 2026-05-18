<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Analyzers\Version;

use ACQC\Analyzers\Version\B2BCompatibilityAnalyzer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ACQC\Analyzers\Version\B2BCompatibilityAnalyzer
 * @group unit
 * @group fast
 */
class B2BCompatibilityAnalyzerTest extends TestCase
{
    private string $fixturesBase;
    private string $acqcRoot;

    protected function setUp(): void
    {
        $this->fixturesBase = dirname(dirname(dirname(dirname(__DIR__)))) . '/tests/Fixtures/UpgradeReadiness';
        $this->acqcRoot     = dirname(dirname(dirname(dirname(__DIR__))));
    }

    /** @test */
    public function testReturnsEmptyWhenB2BNotInstalled(): void
    {
        $projectRoot = $this->fixturesBase . '/NonB2BProject';
        $analyzer    = new B2BCompatibilityAnalyzer($this->acqcRoot);

        $result = $analyzer->analyze($projectRoot, '2.4.8');

        $this->assertFalse($result->installed);
        $this->assertEmpty($result->findings);
        $this->assertSame(0, $result->totalFindings());
    }

    /** @test */
    public function testIsInstalledReturnsFalseForNonB2BProject(): void
    {
        $projectRoot = $this->fixturesBase . '/NonB2BProject';

        $this->assertFalse(B2BCompatibilityAnalyzer::isInstalled($projectRoot));
    }

    /** @test */
    public function testIsInstalledReturnsTrueForB2BProject(): void
    {
        $projectRoot = $this->fixturesBase . '/B2BProject';

        $this->assertTrue(B2BCompatibilityAnalyzer::isInstalled($projectRoot));
    }

    /** @test */
    public function testDetectsB2BVersionConflict(): void
    {
        // B2B 1.3.5 is only compatible with AC 2.4.5/2.4.6, not 2.4.8
        $projectRoot = $this->fixturesBase . '/B2BProject';
        $analyzer    = new B2BCompatibilityAnalyzer($this->acqcRoot);

        $result = $analyzer->analyze($projectRoot, '2.4.8');

        $this->assertTrue($result->installed);
        $this->assertGreaterThan(0, $result->totalFindings());

        $types = array_column($result->findings, 'type');
        $this->assertContains('b2b_version_incompatible', $types, 'Expected b2b_version_incompatible finding');
    }

    /** @test */
    public function testNoConflictWhenB2BCompatibleWithTarget(): void
    {
        // B2B 1.3.5 is compatible with AC 2.4.6
        $projectRoot = $this->fixturesBase . '/B2BProject';
        $analyzer    = new B2BCompatibilityAnalyzer($this->acqcRoot);

        $result = $analyzer->analyze($projectRoot, '2.4.6');

        $this->assertTrue($result->installed);
        // No version-incompatible finding for a compatible pairing
        $types = array_column($result->findings, 'type');
        $this->assertNotContains('b2b_version_incompatible', $types);
    }

    /** @test */
    public function testIncompatibleFindingHasRemediationGuidance(): void
    {
        $projectRoot = $this->fixturesBase . '/B2BProject';
        $analyzer    = new B2BCompatibilityAnalyzer($this->acqcRoot);

        $result = $analyzer->analyze($projectRoot, '2.4.8');

        foreach ($result->findings as $finding) {
            if ($finding['type'] === 'b2b_version_incompatible') {
                $this->assertArrayHasKey('remediation', $finding);
                $this->assertNotEmpty($finding['remediation']);
                $this->assertArrayHasKey('effort_hours_min', $finding);
                $this->assertGreaterThan(0, $finding['effort_hours_min']);
                return;
            }
        }

        $this->fail('Expected b2b_version_incompatible finding was not present');
    }

    /** @test */
    public function testReturnsEmptyForNonExistentPath(): void
    {
        $analyzer = new B2BCompatibilityAnalyzer($this->acqcRoot);
        $result   = $analyzer->analyze('/nonexistent/path', '2.4.8');

        $this->assertFalse($result->installed);
        $this->assertSame([], $result->findings);
    }
}
