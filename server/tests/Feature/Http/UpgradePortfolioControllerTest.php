<?php

namespace Tests\Feature\Http;

use App\Models\Project;
use App\Models\UpgradeScan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpgradePortfolioControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function member(): User
    {
        return User::factory()->create(['role' => 'member']);
    }

    private function project(User $owner): Project
    {
        return Project::factory()->create(['created_by' => $owner->id]);
    }

    private function upgradeScan(Project $project, array $overrides = []): UpgradeScan
    {
        return UpgradeScan::create(array_merge([
            'project_id'                   => $project->id,
            'source_version'               => '2.4.5',
            'target_version'               => '2.4.8',
            'uri_score'                    => 35.0,
            'uri_grade'                    => 'B',
            'engineering_hours'            => 180,
            'qa_hours'                     => 60,
            'infra_hours'                  => 30,
            'total_hours_with_contingency' => 297,
            'recommended_timeline'         => '8–10 weeks',
            'blocker_count'                => 0,
            'critical_count'               => 2,
            'error_count'                  => 6,
            'warning_count'                => 14,
        ], $overrides));
    }

    public function testNonAdminCannotAccessPortfolioMatrix(): void
    {
        $member = $this->member();

        $this->actingAs($member)
             ->getJson('/api/v1/upgrade/portfolio-matrix')
             ->assertStatus(403);
    }

    public function testUnauthenticatedCannotAccessPortfolioMatrix(): void
    {
        $this->getJson('/api/v1/upgrade/portfolio-matrix')
             ->assertStatus(401);
    }

    public function testMatrixReturnsAllProjectsWithLatestScan(): void
    {
        $admin    = $this->admin();
        $projectA = $this->project($admin);
        $projectB = $this->project($admin);

        $this->upgradeScan($projectA, ['uri_score' => 20.0, 'uri_grade' => 'A', 'engineering_hours' => 100]);
        $this->upgradeScan($projectB, ['uri_score' => 70.0, 'uri_grade' => 'D', 'engineering_hours' => 500]);

        $response = $this->actingAs($admin)
                         ->getJson('/api/v1/upgrade/portfolio-matrix')
                         ->assertStatus(200);

        $data = $response->json();

        $this->assertArrayHasKey('projects', $data);
        $this->assertArrayHasKey('portfolio_total_hours', $data);
        $this->assertArrayHasKey('project_count', $data);

        $this->assertGreaterThanOrEqual(2, $data['project_count']);
        $this->assertGreaterThanOrEqual(600, $data['portfolio_total_hours']);

        $projectIds = array_column($data['projects'], 'project_id');
        $this->assertContains($projectA->id, $projectIds);
        $this->assertContains($projectB->id, $projectIds);

        $aEntry = collect($data['projects'])->firstWhere('project_id', $projectA->id);
        $this->assertNotEmpty($aEntry['scans']);
        $this->assertEquals('2.4.8', $aEntry['scans'][0]['target_version']);
        $this->assertEquals('A', $aEntry['scans'][0]['uri_grade']);
    }

    public function testMatrixLatestScanPerTargetVersion(): void
    {
        $admin   = $this->admin();
        $project = $this->project($admin);

        // Two scans against the same target — only the latest should appear
        $this->upgradeScan($project, ['uri_score' => 60.0, 'uri_grade' => 'C', 'target_version' => '2.4.8']);
        $this->upgradeScan($project, ['uri_score' => 25.0, 'uri_grade' => 'B', 'target_version' => '2.4.8']);

        // Scan against a different target
        $this->upgradeScan($project, ['uri_score' => 10.0, 'uri_grade' => 'A', 'target_version' => '2.4.7']);

        $response = $this->actingAs($admin)
                         ->getJson('/api/v1/upgrade/portfolio-matrix')
                         ->assertStatus(200);

        $entry = collect($response->json('projects'))->firstWhere('project_id', $project->id);

        // Should have 2 target versions (2.4.7 and 2.4.8), not 3
        $this->assertCount(2, $entry['scans']);
        $targets = array_column($entry['scans'], 'target_version');
        $this->assertContains('2.4.8', $targets);
        $this->assertContains('2.4.7', $targets);
    }
}
