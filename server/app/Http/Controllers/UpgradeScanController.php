<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\UpgradeScan;
use Illuminate\Http\Request;

class UpgradeScanController extends Controller
{
    private function authorizeAccess(Request $request, Project $project): void
    {
        abort_unless(
            $request->user()->isAdmin() ||
            $project->members()->where('users.id', $request->user()->id)->exists() ||
            $project->created_by === $request->user()->id,
            403
        );
    }

    public function index(Request $request, Project $project)
    {
        $this->authorizeAccess($request, $project);

        $scans = $project->upgradeScans()
            ->orderBy('created_at', 'desc')
            ->paginate(20, [
                'id', 'project_id', 'source_version', 'target_version',
                'uri_score', 'uri_grade', 'engineering_hours',
                'total_hours_with_contingency', 'blocker_count',
                'error_count', 'warning_count', 'created_at',
            ]);

        return response()->json($scans);
    }

    public function show(Request $request, Project $project, UpgradeScan $scan)
    {
        abort_unless($scan->project_id === $project->id, 404);
        $this->authorizeAccess($request, $project);

        return response()->json($scan);
    }

    public function store(Request $request, Project $project)
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'source_version'             => 'required|string|max:20',
            'target_version'             => 'required|string|max:20',
            'uri_score'                  => 'nullable|numeric|min:0|max:100',
            'uri_grade'                  => 'nullable|string|in:A,B,C,D,F',
            'engineering_hours'          => 'nullable|integer|min:0',
            'qa_hours'                   => 'nullable|integer|min:0',
            'infra_hours'                => 'nullable|integer|min:0',
            'total_hours_with_contingency' => 'nullable|integer|min:0',
            'recommended_timeline'       => 'nullable|string|max:50',
            'blocker_count'              => 'nullable|integer|min:0',
            'critical_count'             => 'nullable|integer|min:0',
            'error_count'                => 'nullable|integer|min:0',
            'warning_count'              => 'nullable|integer|min:0',
            'full_report_json'           => 'nullable|array',
        ]);

        $scan = $project->upgradeScans()->create($data);

        return response()->json($scan, 201);
    }
}
