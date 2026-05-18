<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Core\Upgrade;

use ACQC\Core\Upgrade\UpgradeRiskIndex;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ACQC\Core\Upgrade\UpgradeRiskIndex
 * @group unit
 * @group fast
 */
class UpgradeRiskIndexTest extends TestCase
{
    private UpgradeRiskIndex $uri;

    protected function setUp(): void
    {
        $this->uri = new UpgradeRiskIndex();
    }

    /** @test */
    public function testGradeAForLowScore(): void
    {
        $findings = [
            ['type' => 'platform_extension_missing', 'severity' => 'warning', 'message' => 'Missing ext-intl'],
            ['type' => 'platform_extension_missing', 'severity' => 'warning', 'message' => 'Missing ext-xsl'],
        ];

        $score = $this->uri->calculate($findings, 10000, 5000);

        $this->assertSame('A', $score->grade, 'Two warnings on a 5-KLOC project should grade A');
        $this->assertLessThan(20.0, $score->score);
    }

    /** @test */
    public function testGradeDOrFForHighScore(): void
    {
        $findings = [];

        // 5 blockers
        for ($i = 0; $i < 5; $i++) {
            $findings[] = [
                'type'             => 'class_removed',
                'severity'         => 'error',
                'message'          => "Removed class #{$i}",
                'effort_hours_min' => 8,
                'effort_hours_max' => 24,
            ];
        }

        // 20 errors
        for ($i = 0; $i < 20; $i++) {
            $findings[] = [
                'type'             => 'method_removed',
                'severity'         => 'error',
                'message'          => "Removed method #{$i}",
                'effort_hours_min' => 2,
                'effort_hours_max' => 8,
            ];
        }

        // Small codebase so findings density is high
        $score = $this->uri->calculate($findings, 2000, 1000);

        $this->assertContains($score->grade, ['D', 'F'], 'Many blockers + errors should produce grade D or F');
        $this->assertGreaterThanOrEqual(60.0, $score->score);
    }

    /** @test */
    public function testDbRiskMultiplierApplied(): void
    {
        $baseFindings = [
            ['type' => 'platform_extension_missing', 'severity' => 'warning', 'message' => 'Missing ext'],
            ['type' => 'platform_extension_missing', 'severity' => 'warning', 'message' => 'Missing ext2'],
            ['type' => 'platform_extension_missing', 'severity' => 'warning', 'message' => 'Missing ext3'],
        ];

        $withDbRisk = array_merge($baseFindings, [
            [
                'type'             => 'legacy_setup_script_detected',
                'severity'         => 'error',
                'message'          => 'InstallSchema.php detected',
                'effort_hours_min' => 8,
                'effort_hours_max' => 16,
            ],
        ]);

        $scoreWithout = $this->uri->calculate($baseFindings, 1000, 1000);
        $scoreWith    = $this->uri->calculate($withDbRisk, 1000, 1000);

        $this->assertGreaterThan(
            $scoreWithout->score,
            $scoreWith->score,
            'DB risk factor (1.5×) must raise the URI score when legacy_setup_script is present'
        );
    }

    /** @test */
    public function testComposerRiskMultiplierApplied(): void
    {
        $base = [
            ['type' => 'platform_extension_missing', 'severity' => 'warning', 'message' => 'ext'],
        ];

        $withComposerRisk = array_merge($base, [
            [
                'type'     => 'composer_security_advisory',
                'severity' => 'error',
                'message'  => 'CVE found',
            ],
        ]);

        $scoreWithout = $this->uri->calculate($base, 1000, 1000);
        $scoreWith    = $this->uri->calculate($withComposerRisk, 1000, 1000);

        $this->assertGreaterThan($scoreWithout->score, $scoreWith->score);
    }

    /** @test */
    public function testEffortCalculationIncludesContingency(): void
    {
        $findings = [];
        for ($i = 0; $i < 10; $i++) {
            $findings[] = [
                'type'             => 'method_removed',
                'severity'         => 'error',
                'message'          => "Issue #{$i}",
                'effort_hours_min' => 4,
                'effort_hours_max' => 12,
            ];
        }

        $score = $this->uri->calculate($findings);

        $rawSubtotal = $score->engineeringHours + $score->qaHours + $score->infraHours;
        $this->assertGreaterThan(
            $rawSubtotal,
            $score->totalHoursWithContingency,
            'totalHoursWithContingency must exceed eng+qa+infra sum (contingency not applied)'
        );

        $this->assertGreaterThan(0, $score->engineeringHours);
        $this->assertGreaterThan(0, $score->qaHours);
        $this->assertGreaterThanOrEqual(24, $score->infraHours);
    }

    /** @test */
    public function testTimelineIsReasonable(): void
    {
        $findings = [];
        for ($i = 0; $i < 5; $i++) {
            $findings[] = [
                'type'             => 'class_removed',
                'severity'         => 'error',
                'message'          => "Removed #{$i}",
                'effort_hours_min' => 8,
                'effort_hours_max' => 24,
            ];
        }

        $score = $this->uri->calculate($findings);

        $this->assertMatchesRegularExpression('/^\d+–\d+ weeks$/', $score->recommendedTimeline);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $score->goLiveRecommendation);
    }

    /** @test */
    public function testScoreCapAt100(): void
    {
        $findings = [];
        for ($i = 0; $i < 100; $i++) {
            $findings[] = [
                'type'             => 'class_removed',
                'severity'         => 'error',
                'message'          => "Class #{$i}",
                'effort_hours_min' => 16,
                'effort_hours_max' => 40,
            ];
        }

        $score = $this->uri->calculate($findings, 500, 100);

        $this->assertLessThanOrEqual(100.0, $score->score, 'URI score must never exceed 100');
        $this->assertSame('F', $score->grade);
    }

    /** @test */
    public function testEmptyFindingsGiveGradeA(): void
    {
        $score = $this->uri->calculate([]);

        $this->assertSame('A', $score->grade);
        $this->assertSame(0.0, $score->score);
    }

    /** @test */
    public function testSprintBreakdownIsPopulated(): void
    {
        $findings = [
            ['type' => 'class_removed',   'severity' => 'error',   'message' => 'Blocker',  'effort_hours_min' => 8,  'effort_hours_max' => 16],
            ['type' => 'method_removed',  'severity' => 'error',   'message' => 'Error',    'effort_hours_min' => 2,  'effort_hours_max' => 4],
            ['type' => 'platform_extension_missing', 'severity' => 'warning', 'message' => 'Warn', 'effort_hours_min' => 1, 'effort_hours_max' => 2],
        ];

        $score = $this->uri->calculate($findings);

        $this->assertNotEmpty($score->sprintBreakdown);
        $sprintNumbers = array_column($score->sprintBreakdown, 'sprint');
        $this->assertContains(1, $sprintNumbers, 'Sprint 1 must exist when blockers are present');
    }
}
