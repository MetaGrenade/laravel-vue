<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;

class UsersController extends Controller
{
    /**
     * Display a listing of users, plus some stats.
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 15);

        $userQuery = User::query();

        $users = (clone $userQuery)
            ->with('roles')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage)
            ->withQueryString();

        $userStats = [
            'total'      => $users->total(),
            'unverified' => (clone $userQuery)->whereNull('email_verified_at')->count(),
            'banned'     => (clone $userQuery)->where('is_banned', true)->count(),
            'online'     => (clone $userQuery)->where('last_activity_at', '>=', now()->subMinutes(5))->count(),
        ];

        return inertia('acp/Users', compact('users','userStats'));
    }

    /**
     * Show the form for editing the specified user.
     */
    public function edit(User $user)
    {
        return Inertia::render('acp/UsersEdit', [
            'user'      => $user->load('roles'),
            'allRoles'  => Role::all(),
        ]);
    }

    /**
     * Store a new user record.
     */
    public function store(StoreUserRequest $request)
    {
        $user = User::create($request->validated());
        $user->syncRoles($request->roles ?? []);
        return redirect()->route('acp.users.index')
            ->with('success','User created.');
    }

    /**
     * Update the specified user.
     */
    public function update(UpdateUserRequest $request, User $user)
    {
        $user->update($request->validated());
        $user->syncRoles($request->roles ?? []);
        return redirect()->route('acp.users.index')
            ->with('success','User updated.');
    }

    /**
     * Delete a user.
     */
    public function destroy(User $user)
    {
        $user->delete();
        return redirect()->route('acp.users.index')
            ->with('success','User deleted.');
    }

    /**
     * Manually mark a user’s email as verified.
     */
    public function verify(User $user)
    {
        $user->update(['email_verified_at' => now()]);
        return redirect()->route('acp.users.index')
            ->with('success','User verified.');
    }
}
