<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function index(Request $request)
    {
        $projects = Project::with('latestScan')
            ->visibleTo($request->user())
            ->latest()
            ->paginate(20);

        return response()->json($projects);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'dr_no'       => 'required|string|max:50|unique:projects',
            'description' => 'nullable|string',
            'repo_url'    => 'nullable|url',
            'tech_stack'  => 'nullable|array',
            'status'      => 'in:active,in_review,archived',
        ]);

        $project = Project::create([...$data, 'created_by' => $request->user()->id]);

        $project->members()->attach($request->user()->id, ['role' => 'lead']);

        return response()->json($project, 201);
    }

    public function show(Request $request, Project $project)
    {
        $this->authorizeAccess($request->user(), $project);

        return response()->json($project->load('latestScan', 'members'));
    }

    public function update(Request $request, Project $project)
    {
        $this->authorizeAccess($request->user(), $project);

        $data = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'repo_url'    => 'nullable|url',
            'local_path'  => 'nullable|string|max:500',
            'tech_stack'  => 'nullable|array',
            'status'      => 'in:active,in_review,archived',
        ]);

        if (array_key_exists('local_path', $data) && $data['local_path'] !== null) {
            $data['local_path'] = $this->validateLocalPath($data['local_path']);
        }

        $project->update($data);

        return response()->json($project);
    }

    public function destroy(Request $request, Project $project)
    {
        $this->authorizeAccess($request->user(), $project);
        $project->delete();

        return response()->json(null, 204);
    }

    public function addMember(Request $request, Project $project)
    {
        $this->authorizeAccess($request->user(), $project);

        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'role'    => 'required|in:lead,member',
        ]);

        $project->members()->syncWithoutDetaching([$data['user_id'] => ['role' => $data['role']]]);

        return response()->json(['message' => 'Member added.']);
    }

    public function removeMember(Request $request, Project $project, User $user)
    {
        $this->authorizeAccess($request->user(), $project);
        $project->members()->detach($user->id);

        return response()->json(null, 204);
    }

    private function authorizeAccess(User $actor, Project $project): void
    {
        if ($actor->isAdmin()) return;

        $visible = Project::visibleTo(Project::query(), $actor)->where('id', $project->id)->exists();

        abort_unless($visible, 403);
    }

    private function validateLocalPath(string $path): string
    {
        $resolved = realpath($path);

        abort_if($resolved === false || !is_dir($resolved), 422, 'local_path must be an existing directory on the server.');

        $base = config('acqc.scan_base_path');
        if ($base !== null) {
            $base = rtrim((string) realpath($base) ?: $base, '/') . '/';
            abort_unless(str_starts_with($resolved . '/', $base), 422, 'local_path must reside within the permitted scan base directory.');
        }

        return $resolved;
    }
}
