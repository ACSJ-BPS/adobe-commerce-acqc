<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Core\Upgrade;

use ACQC\Core\Upgrade\SprintPlanner;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ACQC\Core\Upgrade\SprintPlanner
 * @group unit
 * @group fast
 */
class SprintPlannerTest extends TestCase
{
    /** @test */
    public function testBlockersInSprint1(): void
    {
        $findings = [
            [
                'type'             => 'class_removed',
                'severity'         => 'error',
                'message'          => 'ClassName was removed',
                'effort_hours_min' => 8,
                'effort_hours_max' => 24,
            ],
            [
                'type'             => 'legacy_setup_script_detected',
                'severity'         => 'error',
                'message'          => 'InstallSchema found',
                'effort_hours_min' => 8,
                'effort_hours_max' => 16,
            ],
        ];

        $planner = new SprintPlanner($findings);
        $sprints = $planner->plan();

        $sprint1 = $this->findSprint($sprints, 1);
        $this->assertNotNull($sprint1, 'Sprint 1 must exist when blockers are present');

        $types = array_column($sprint1['findings'], 'type');
        $this->assertContains('class_removed', $types, 'class_removed must be in Sprint 1');
        $this->assertContains('legacy_setup_script_detected', $types, 'legacy_setup_script_detected must be in Sprint 1');
    }

    /** @test */
    public function testHighEffortErrorsInSprint2(): void
    {
        $findings = [
            [
                'type'             => 'method_removed',
                'severity'         => 'error',
                'message'          => 'High-effort refactor needed',
                'effort_hours_min' => 20,
                'effort_hours_max' => 40,
            ],
        ];

        $planner = new SprintPlanner($findings);
        $sprints = $planner->plan();

        $sprint2 = $this->findSprint($sprints, 2);
        $this->assertNotNull($sprint2, 'Sprint 2 must contain high-effort errors (>= 16h)');
        $this->assertNotEmpty($sprint2['findings']);
    }

    /** @test */
    public function testWarningsInSprint3NotSprint1(): void
    {
        $findings = [
            [
                'type'             => 'platform_extension_missing',
                'severity'         => 'warning',
                'message'          => 'ext-xsl not declared',
                'effort_hours_min' => 1,
                'effort_hours_max' => 2,
            ],
            [
                'type'             => 'composer_package_abandoned',
                'severity'         => 'warning',
                'message'          => 'Package abandoned',
                'effort_hours_min' => 2,
                'effort_hours_max' => 8,
            ],
        ];

        $planner = new SprintPlanner($findings);
        $sprints = $planner->plan();

        // Sprint 1 should NOT exist (no blockers/errors)
        $sprint1 = $this->findSprint($sprints, 1);
        if ($sprint1 !== null) {
            $this->assertEmpty(
                $sprint1['findings'],
                'Sprint 1 must not contain warnings-only findings'
            );
        }

        // Warnings should appear in sprint 3 or the first non-blocker sprint
        $warningFound = false;
        foreach ($sprints as $sprint) {
            foreach ($sprint['findings'] as $f) {
                if ($f['severity'] === 'warning') {
                    $warningFound = true;
                    $this->assertGreaterThanOrEqual(
                        3,
                        $sprint['sprint'],
                        'Warnings must not appear before sprint 3'
                    );
                }
            }
        }
        $this->assertTrue($warningFound, 'Warnings must appear in at least one sprint');
    }

    /** @test */
    public function testQaSprint4AlwaysAddedWhenFindingsExist(): void
    {
        $findings = [
            ['type' => 'platform_extension_missing', 'severity' => 'warning', 'message' => 'ext'],
        ];

        $planner = new SprintPlanner($findings);
        $sprints = $planner->plan();

        $hasQaSprint = false;
        foreach ($sprints as $sprint) {
            if (str_contains($sprint['name'], 'QA') || str_contains($sprint['name'], 'Hardening')) {
                $hasQaSprint = true;
            }
        }

        $this->assertTrue($hasQaSprint, 'A QA/hardening sprint must always be added when findings exist');
    }

    /** @test */
    public function testSprint5AddedForLargeIssueSets(): void
    {
        $findings = [];
        for ($i = 0; $i < 25; $i++) {
            $findings[] = [
                'type'             => 'method_removed',
                'severity'         => 'error',
                'message'          => "Issue #{$i}",
                'effort_hours_min' => 2,
                'effort_hours_max' => 4,
            ];
        }

        $planner = new SprintPlanner($findings);
        $sprints = $planner->plan();

        $this->assertGreaterThanOrEqual(5, count($sprints), 'Sprint 5 must be added for > 20 findings');
    }

    /** @test */
    public function testEmptyFindingsProduceNoSprints(): void
    {
        $planner = new SprintPlanner([]);
        $sprints = $planner->plan();

        $this->assertEmpty($sprints, 'No findings should produce no sprints');
    }

    /** @test */
    public function testSprintNumbersAreSequential(): void
    {
        $findings = [];
        for ($i = 0; $i < 5; $i++) {
            $findings[] = [
                'type'             => 'class_removed',
                'severity'         => 'error',
                'message'          => "Blocker #{$i}",
                'effort_hours_min' => 8,
                'effort_hours_max' => 16,
            ];
        }
        for ($i = 0; $i < 3; $i++) {
            $findings[] = [
                'type'             => 'platform_extension_missing',
                'severity'         => 'warning',
                'message'          => "Warning #{$i}",
                'effort_hours_min' => 1,
                'effort_hours_max' => 2,
            ];
        }

        $planner = new SprintPlanner($findings);
        $sprints = $planner->plan();

        $sprintNumbers = array_column($sprints, 'sprint');
        $expected      = range(1, count($sprints));
        $this->assertSame($expected, $sprintNumbers, 'Sprint numbers must be sequential starting from 1');
    }

    private function findSprint(array $sprints, int $number): ?array
    {
        foreach ($sprints as $sprint) {
            if ($sprint['sprint'] === $number) {
                return $sprint;
            }
        }
        return null;
    }
}
