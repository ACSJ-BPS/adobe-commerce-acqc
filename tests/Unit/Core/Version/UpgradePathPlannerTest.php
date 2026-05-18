<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Core\Version;

use ACQC\Core\Version\UpgradePath;
use ACQC\Core\Version\UpgradePathPlanner;
use ACQC\Core\Version\VersionRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * @covers \ACQC\Core\Version\UpgradePathPlanner
 * @covers \ACQC\Core\Version\UpgradePath
 * @group unit
 * @group fast
 */
class UpgradePathPlannerTest extends TestCase
{
    private UpgradePathPlanner $planner;
    private VersionRegistry $registry;

    protected function setUp(): void
    {
        $registryPath = dirname(dirname(dirname(dirname(__DIR__)))) . '/config/versions/adobe-commerce';
        $this->registry = new VersionRegistry($registryPath, new ArrayAdapter());
        $this->planner = new UpgradePathPlanner($this->registry);
    }

    /**
     * @test
     */
    public function testDirectPathWhenWithinTwoMinorVersions(): void
    {
        $path = $this->planner->plan('2.4.6', '2.4.8');

        $this->assertInstanceOf(UpgradePath::class, $path);
        $this->assertSame('2.4.6', $path->source);
        $this->assertSame('2.4.8', $path->target);
        $this->assertTrue($path->isDirect, "2.4.6→2.4.8 spans 2 minor versions and should be direct");
        $hops = $path->hops;
        $this->assertSame('2.4.8', end($hops));
    }

    /**
     * @test
     */
    public function testMultiHopPathWhenSpanning3Versions(): void
    {
        // 2.4.4 → 2.4.8 spans 4 minor versions
        $path = $this->planner->plan('2.4.4', '2.4.8');

        $this->assertFalse($path->isDirect);
        $this->assertGreaterThan(1, count($path->hops));

        // Must pass through 2.4.6 or 2.4.7 as intermediate
        $intermediates = array_slice($path->hops, 0, -1);
        $hasIntermediate = false;
        foreach ($intermediates as $hop) {
            if (str_starts_with($hop, '2.4.6') || str_starts_with($hop, '2.4.7')) {
                $hasIntermediate = true;
                break;
            }
        }
        $this->assertTrue($hasIntermediate, "Multi-hop path must have 2.4.6 or 2.4.7 as intermediate");
    }

    /**
     * @test
     */
    public function testElasticsearchHopRequired(): void
    {
        // 2.4.5 uses Elasticsearch; 2.4.8 drops it (dropped in 2.4.7)
        // Must include 2.4.6 as intermediate stop
        $path = $this->planner->plan('2.4.5', '2.4.8');

        $this->assertFalse($path->isDirect);

        // 2.4.6-p2 (latest of 2.4.6) must be in hops
        $hop246Found = false;
        foreach ($path->hops as $hop) {
            if (str_starts_with($hop, '2.4.6')) {
                $hop246Found = true;
                break;
            }
        }
        $this->assertTrue($hop246Found, "Elasticsearch transition stop at 2.4.6 must be present in hops");
    }

    /**
     * @test
     */
    public function testSingleVersionUpgradeIsDirect(): void
    {
        $path = $this->planner->plan('2.4.7', '2.4.8');

        $this->assertTrue($path->isDirect);
        $this->assertSame(['2.4.8'], $path->hops);
    }

    /**
     * @test
     */
    public function testHopsAlwaysEndWithTarget(): void
    {
        $path = $this->planner->plan('2.4.5', '2.4.8');

        $hops = $path->hops;
        $this->assertSame('2.4.8', end($hops));
    }

    /**
     * @test
     */
    public function testMinorVersionDistance(): void
    {
        $this->assertSame(2, $this->planner->minorVersionDistance('2.4.6', '2.4.8'));
        $this->assertSame(3, $this->planner->minorVersionDistance('2.4.5', '2.4.8'));
        $this->assertSame(1, $this->planner->minorVersionDistance('2.4.7', '2.4.8'));
        $this->assertSame(0, $this->planner->minorVersionDistance('2.4.8', '2.4.8'));
    }

    /**
     * @test
     */
    public function testEstimatedDurationIsPopulated(): void
    {
        $path = $this->planner->plan('2.4.5', '2.4.8');

        $this->assertNotEmpty($path->estimatedDuration);
        $this->assertStringContainsString('weeks', $path->estimatedDuration);
    }

    /**
     * @test
     */
    public function testRationaleIsPopulated(): void
    {
        $path = $this->planner->plan('2.4.5', '2.4.8');

        $this->assertNotEmpty($path->rationale);
    }

    /**
     * @test
     */
    public function testTotalBreakingChangesIsNonNegative(): void
    {
        $path = $this->planner->plan('2.4.6', '2.4.8');

        $this->assertGreaterThanOrEqual(0, $path->totalBreakingChanges);
    }

    /**
     * @test
     */
    public function testLatestPatchUsedForIntermediateHops(): void
    {
        $path = $this->planner->plan('2.4.5', '2.4.8');

        // Intermediate hops should use latest patch (e.g., 2.4.6-p2 not 2.4.6)
        $intermediates = array_slice($path->hops, 0, -1);
        foreach ($intermediates as $hop) {
            if (str_starts_with($hop, '2.4.6')) {
                $this->assertSame('2.4.6-p2', $hop, "Should use latest patch 2.4.6-p2");
            }
        }
    }
}
