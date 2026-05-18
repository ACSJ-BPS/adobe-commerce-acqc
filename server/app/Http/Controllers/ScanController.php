<?php

namespace App\Http\Controllers;

use App\Jobs\ScanProject;
use App\Models\Project;
use App\Models\Scan;
use Illuminate\Http\Request;

class ScanController extends Controller
{
    public function index(Request $request, Project $project)
    {
        abort_unless(
            $request->user()->isAdmin() ||
            $project->members()->where('users.id', $request->user()->id)->exists() ||
            $project->created_by === $request->user()->id,
            403
        );
        return response()->json($project->scans()->latest()->paginate(20));
    }

    private function authorizeAccess(Request $request, Project $project): void
    {
        abort_unless(
            $request->user()->isAdmin() ||
            $project->members()->where('users.id', $request->user()->id)->exists() ||
            $project->created_by === $request->user()->id,
            403
        );
    }

    public function store(Request $request, Project $project)
    {
        $this->authorizeAccess($request, $project);

        if (empty($project->local_path)) {
            return response()->json([
                'message' => 'Cannot run scan: project has no local path configured. Edit the project to set the local path to the checked-out repository.',
            ], 422);
        }

        $alreadyActive = $project->scans()
            ->whereIn('status', ['queued', 'running'])
            ->exists();

        if ($alreadyActive) {
            return response()->json([
                'message' => 'A scan is already queued or running for this project.',
            ], 409);
        }

        $scan = $project->scans()->create([
            'triggered_by' => $request->user()->id,
            'status'       => 'queued',
        ]);

        ScanProject::dispatch($scan)->onQueue('scans');

        return response()->json($scan, 202);
    }

    public function show(Request $request, Project $project, Scan $scan)
    {
        abort_unless($scan->project_id === $project->id, 404);
        $this->authorizeAccess($request, $project);

        return response()->json($scan->load('findings'));
    }
}
