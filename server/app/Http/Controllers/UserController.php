<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index()
    {
        return response()->json(User::withCount('projects')->latest()->paginate(50));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users',
            'role'     => 'required|in:admin,project_lead,member',
            'password' => 'required|string|min:8',
        ]);

        $user = User::create([...$data, 'password' => Hash::make($data['password'])]);

        return response()->json($user, 201);
    }

    public function show(User $user)
    {
        return response()->json($user->load('projects'));
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'role' => 'sometimes|in:admin,project_lead,member',
        ]);

        $user->update($data);

        return response()->json($user);
    }

    public function destroy(Request $request, User $user)
    {
        if ($request->user()->id === $user->id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }

        $user->delete();

        return response()->json(null, 204);
    }
}
