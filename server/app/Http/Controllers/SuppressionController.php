<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Suppression;
use Illuminate\Http\Request;

class SuppressionController extends Controller
{
    public function index(Request $request, Project $project)
    {
        $user = $request->user();
        abort_unless(
            $user->isAdmin() ||
            $project->members()->where('users.id', $user->id)->exists() ||
            $project->created_by === $user->id,
            403
        );

        return response()->json(
            $project->suppressions()->with('acknowledgedByUser:id,name,email')->get()
        );
    }

    public function store(Request $request, Project $project)
    {
        $user = $request->user();
        abort_unless(
            $user->isAdmin() ||
            $project->members()->where('users.id', $user->id)->exists() ||
            $project->created_by === $user->id,
            403
        );

        $data = $request->validate([
            'rule_id'   => ['required', 'string', 'max:50', 'regex:/^[A-Z]{2,5}_[A-Z]{2,8}_\d{3}$/'],
            'reason'    => ['required', 'string', 'max:500'],
            'review_by' => ['nullable', 'date_format:Y-m-d', 'after:today'],
        ]);

        $suppression = $project->suppressions()->updateOrCreate(
            ['rule_id' => $data['rule_id']],
            [
                'reason'              => $data['reason'],
                'suppressed_by_email' => $user->email,
                'suppressed_at'       => now()->toDateString(),
                'review_by'           => $data['review_by'] ?? null,
                'dashboard_acked_at'  => null,
            ]
        );

        return response()->json($suppression, 201);
    }

    public function destroy(Request $request, Project $project, Suppression $suppression)
    {
        $user = $request->user();
        abort_unless(
            $user->isAdmin() ||
            $project->members()->where('users.id', $user->id)->exists() ||
            $project->created_by === $user->id,
            403
        );
        abort_if($suppression->project_id !== $project->id, 404);

        $suppression->delete();

        return response()->noContent();
    }
}
