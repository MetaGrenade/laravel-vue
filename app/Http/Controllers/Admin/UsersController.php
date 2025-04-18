<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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

        $users = User::with('roles')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage)
            ->withQueryString();

        $userStats = [
            'total'      => User::count(),
            'unverified' => User::whereNull('email_verified_at')->count(),
            'banned'     => User::where('is_banned', true)->count(),
            'online'     => User::where('last_activity_at', '>=', now()->subMinutes(5))->count(),
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
     * Update the specified user.
     */
    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name'  => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,'.$user->id,
            'roles' => 'array',               // e.g. ['admin','editor']
        ]);

        $user->update($data);
        $user->syncRoles($data['roles'] ?? []);

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
    public function verify(Request $request, User $user)
    {
        $user->update(['email_verified_at' => now()]);

        return redirect()->route('acp.users.index')
            ->with('success','User verified.');
    }
}
