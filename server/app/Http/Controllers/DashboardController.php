<?php

namespace App\Http\Controllers;

use App\Models\Finding;
use App\Models\Project;
use App\Models\Scan;
use App\Models\Suppression;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user       = $request->user();
        $projects   = Project::with('latestScan')->visibleTo($user)->get();
        $projectIds = $projects->pluck('id');

        // Projects that have a completed latest scan with a score
        $withScore = $projects->filter(fn($p) => $p->latestScan !== null);

        $avgScore = $withScore->count()
            ? (int) round($withScore->avg(fn($p) => $p->latestScan->score))
            : null;

        // Batch-fetch the previous complete scan score per project (one query)
        $latestIds      = $withScore->pluck('latestScan.id')->filter();
        $avgScoreChange = null;

        if ($latestIds->isNotEmpty()) {
            $prevScores = Scan::whereIn('project_id', $projectIds)
                ->where('status', 'complete')
                ->whereNotNull('score')
                ->whereNotIn('id', $latestIds)
                ->whereIn('id', function ($q) use ($projectIds, $latestIds) {
                    $q->selectRaw('MAX(id)')->from('scans')
                      ->whereIn('project_id', $projectIds)
                      ->where('status', 'complete')
                      ->whereNotNull('score')
                      ->whereNotIn('id', $latestIds)
                      ->groupBy('project_id');
                })
                ->pluck('score', 'project_id');

            $deltas = $withScore
                ->filter(fn($p) => $prevScores->has($p->id))
                ->map(fn($p) => $p->latestScan->score - $prevScores[$p->id]);

            if ($deltas->isNotEmpty()) {
                $avgScoreChange = (int) round($deltas->avg());
            }
        }

        $totalSuppressions   = $projectIds->isEmpty() ? 0
            : Suppression::whereIn('project_id', $projectIds)->count();
        $expiredSuppressions = $projectIds->isEmpty() ? 0
            : Suppression::whereIn('project_id', $projectIds)->expired()->count();

        $stats = [
            'total_projects'       => $projects->count(),
            'healthy'              => $withScore->filter(fn($p) => $p->latestScan->score >= 80)->count(),
            'at_risk'              => $withScore->filter(fn($p) => $p->latestScan->score < 50)->count(),
            'no_scan'              => $projects->filter(fn($p) => !$p->latestScan)->count(),
            'avg_score'            => $avgScore,
            'avg_score_change'     => $avgScoreChange,
            'total_open_critical'  => $withScore->sum(fn($p) => $p->latestScan->critical_count),
            'total_open_error'     => $withScore->sum(fn($p) => $p->latestScan->error_count),
            'resolved_this_month'  => $projectIds->isEmpty() ? 0
                : Finding::whereHas('scan', fn($q) => $q->whereIn('project_id', $projectIds))
                    ->whereNotNull('resolved_at')
                    ->whereYear('resolved_at', now()->year)
                    ->whereMonth('resolved_at', now()->month)
                    ->count(),
            'scans_this_week'      => $projectIds->isEmpty() ? 0
                : Scan::whereIn('project_id', $projectIds)
                    ->where('status', 'complete')
                    ->where('completed_at', '>=', now()->startOfWeek())
                    ->count(),
            'total_suppressions'   => $totalSuppressions,
            'expired_suppressions' => $expiredSuppressions,
        ];

        // Weekly avg score for sparkline (last 8 weeks)
        $score_history = $projectIds->isEmpty() ? [] : Scan::whereIn('project_id', $projectIds)
            ->where('status', 'complete')
            ->whereNotNull('score')
            ->where('completed_at', '>=', now()->subWeeks(8))
            ->selectRaw('YEARWEEK(completed_at, 1) as yw, DATE(MIN(completed_at)) as week_start, ROUND(AVG(score)) as avg_score, COUNT(*) as scan_count')
            ->groupBy(DB::raw('YEARWEEK(completed_at, 1)'))
            ->orderBy('yw')
            ->get();

        $recent_scans = $projectIds->isEmpty() ? [] : Scan::whereIn('project_id', $projectIds)
            ->where('status', 'complete')
            ->with('project')
            ->latest('completed_at')
            ->limit(10)
            ->get();

        // Attach rule_coverage to each project (from its latest scan)
        $projects->each(function ($project) {
            $scan = $project->latestScan;
            if ($scan && $scan->total_rules_evaluated > 0) {
                $passed = $scan->total_rules_evaluated - $scan->violated_rules_count;
                $project->rule_coverage = (int) round(($passed / $scan->total_rules_evaluated) * 100);
            } else {
                $project->rule_coverage = null;
            }
        });

        return response()->json(compact('stats', 'projects', 'recent_scans', 'score_history'));
    }
}
