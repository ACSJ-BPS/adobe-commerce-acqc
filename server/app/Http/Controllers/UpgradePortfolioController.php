<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\UpgradeScan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UpgradePortfolioController extends Controller
{
    public function matrix(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);

        // For each project, get the latest scan per unique target_version
        $projects = Project::withTrashed(false)
            ->get(['id', 'name']);

        // Fetch latest scan id per (project_id, target_version) pair
        $latestIds = UpgradeScan::select(DB::raw('MAX(id) as id'))
            ->groupBy('project_id', 'target_version')
            ->pluck('id');

        $latestScans = UpgradeScan::whereIn('id', $latestIds)
            ->get(['id', 'project_id', 'target_version', 'uri_score', 'uri_grade', 'engineering_hours', 'created_at']);

        $totalHours = $latestScans->sum('engineering_hours');

        $matrix = $projects->map(function (Project $project) use ($latestScans) {
            $scans = $latestScans
                ->where('project_id', $project->id)
                ->values()
                ->map(fn ($s) => [
                    'target_version'    => $s->target_version,
                    'uri_score'         => $s->uri_score,
                    'uri_grade'         => $s->uri_grade,
                    'engineering_hours' => $s->engineering_hours,
                    'created_at'        => $s->created_at,
                ])
                ->values();

            return [
                'project_id'   => $project->id,
                'project_name' => $project->name,
                'scans'        => $scans,
            ];
        });

        return response()->json([
            'projects'                => $matrix,
            'portfolio_total_hours'   => (int) $totalHours,
            'project_count'           => $projects->count(),
        ]);
    }
}
