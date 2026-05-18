<?php

namespace Tests\Feature\Http;

use App\Models\Project;
use App\Models\UpgradeScan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpgradeScanControllerTest extends TestCase
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

    private function project(?User $owner = null): Project
    {
        $owner ??= $this->admin();
        return Project::factory()->create(['created_by' => $owner->id]);
    }

    private function scanPayload(): array
    {
        return [
            'source_version'               => '2.4.5',
            'target_version'               => '2.4.8',
            'uri_score'                    => 42.5,
            'uri_grade'                    => 'C',
            'engineering_hours'            => 240,
            'qa_hours'                     => 80,
            'infra_hours'                  => 40,
            'total_hours_with_contingency' => 396,
            'recommended_timeline'         => '10–14 weeks',
            'blocker_count'                => 2,
            'critical_count'               => 5,
            'error_count'                  => 12,
            'warning_count'                => 30,
        ];
    }

    public function testUnauthenticatedRequestReturns401(): void
    {
        $project = $this->project();

        $this->getJson("/api/v1/projects/{$project->id}/upgrade-scans")
             ->assertStatus(401);
    }

    public function testNonMemberCannotAccessProjectScans(): void
    {
        $admin   = $this->admin();
        $project = $this->project($admin);
        $stranger = $this->member();

        UpgradeScan::create(array_merge($this->scanPayload(), ['project_id' => $project->id]));

        $this->actingAs($stranger)
             ->getJson("/api/v1/projects/{$project->id}/upgrade-scans")
             ->assertStatus(403);
    }

    public function testAdminCanAccessAnyProjectScans(): void
    {
        $admin   = $this->admin();
        $project = $this->project($admin);

        UpgradeScan::create(array_merge($this->scanPayload(), ['project_id' => $project->id]));

        $other = $this->admin();
        $this->actingAs($other)
             ->getJson("/api/v1/projects/{$project->id}/upgrade-scans")
             ->assertStatus(200)
             ->assertJsonPath('total', 1);
    }

    public function testProjectMemberCanAccessProjectScans(): void
    {
        $owner   = $this->admin();
        $project = $this->project($owner);
        $member  = $this->member();

        $project->members()->attach($member->id, ['role' => 'member']);
        UpgradeScan::create(array_merge($this->scanPayload(), ['project_id' => $project->id]));

        $this->actingAs($member)
             ->getJson("/api/v1/projects/{$project->id}/upgrade-scans")
             ->assertStatus(200);
    }

    public function testStoreCreatesNewUpgradeScan(): void
    {
        $admin   = $this->admin();
        $project = $this->project($admin);

        $response = $this->actingAs($admin)
                         ->postJson("/api/v1/projects/{$project->id}/upgrade-scans", $this->scanPayload());

        $response->assertStatus(201)
                 ->assertJsonPath('source_version', '2.4.5')
                 ->assertJsonPath('target_version', '2.4.8')
                 ->assertJsonPath('uri_grade', 'C');

        $this->assertDatabaseHas('upgrade_scans', [
            'project_id'     => $project->id,
            'source_version' => '2.4.5',
            'target_version' => '2.4.8',
        ]);
    }

    public function testNonAdminCannotCreateScan(): void
    {
        $owner   = $this->admin();
        $project = $this->project($owner);
        $member  = $this->member();
        $project->members()->attach($member->id, ['role' => 'member']);

        $this->actingAs($member)
             ->postJson("/api/v1/projects/{$project->id}/upgrade-scans", $this->scanPayload())
             ->assertStatus(403);
    }

    public function testShowReturnsScanForCorrectProject(): void
    {
        $admin   = $this->admin();
        $project = $this->project($admin);
        $scan    = UpgradeScan::create(array_merge($this->scanPayload(), ['project_id' => $project->id]));

        $this->actingAs($admin)
             ->getJson("/api/v1/projects/{$project->id}/upgrade-scans/{$scan->id}")
             ->assertStatus(200)
             ->assertJsonPath('id', $scan->id);
    }

    public function testShowReturns404ForWrongProject(): void
    {
        $admin    = $this->admin();
        $projectA = $this->project($admin);
        $projectB = $this->project($admin);
        $scan     = UpgradeScan::create(array_merge($this->scanPayload(), ['project_id' => $projectA->id]));

        $this->actingAs($admin)
             ->getJson("/api/v1/projects/{$projectB->id}/upgrade-scans/{$scan->id}")
             ->assertStatus(404);
    }
}
