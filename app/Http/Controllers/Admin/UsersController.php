<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UsersController extends Controller
{
    use InteractsWithInertiaPagination;

    /**
     * Display a listing of users, plus some stats.
     */
    public function index(Request $request): Response
    {
        $perPage = (int) $request->get('per_page', 15);
        $search = trim((string) $request->query('search', ''));

        $baseQuery = User::query();

        $filteredQuery = (clone $baseQuery);

        if ($search !== '') {
            $filteredQuery->where(function ($query) use ($search) {
                $like = "%{$search}%";

                $query
                    ->where('nickname', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhereHas('roles', function ($roleQuery) use ($like) {
                        $roleQuery->where('name', 'like', $like);
                    });
            });
        }

        $users = (clone $filteredQuery)
            ->with(['roles:id,name', 'bannedBy:id,nickname'])
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();

        $userItems = $users->getCollection()
            ->map(function (User $user) {
                return [
                    'id' => $user->id,
                    'nickname' => $user->nickname,
                    'email' => $user->email,
                    'email_verified_at' => optional($user->email_verified_at)->toIso8601String(),
                    'last_activity_at' => optional($user->last_activity_at)->toIso8601String(),
                    'is_banned' => $user->is_banned,
                    'banned_at' => optional($user->banned_at)->toIso8601String(),
                    'banned_by' => $user->bannedBy?->only(['id', 'nickname']),
                    'created_at' => optional($user->created_at)->toIso8601String(),
                    'roles' => $user->roles
                        ->map(fn ($role) => ['name' => $role->name])
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();

        $userStats = [
            'total' => (clone $baseQuery)->count(),
            'unverified' => (clone $baseQuery)->whereNull('email_verified_at')->count(),
            'banned' => (clone $baseQuery)->where('is_banned', true)->count(),
            'online' => (clone $baseQuery)->where('last_activity_at', '>=', now()->subMinutes(5))->count(),
        ];

        return inertia('acp/Users', [
            'users' => array_merge([
                'data' => $userItems,
            ], $this->inertiaPagination($users)),
            'userStats' => $userStats,
            'filters' => [
                'search' => $search !== '' ? $search : null,
            ],
        ]);
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

    public function ban(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('users.acp.ban');

        if (! $user->is_banned) {
            $user->forceFill([
                'is_banned' => true,
                'banned_at' => now(),
                'banned_by_id' => $request->user()->id,
            ])->save();
        }

        return redirect()->route('acp.users.index')
            ->with('success', 'User banned.');
    }

    public function unban(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('users.acp.ban');

        if ($user->is_banned) {
            $user->forceFill([
                'is_banned' => false,
                'banned_at' => null,
                'banned_by_id' => null,
            ])->save();
        }

        return redirect()->route('acp.users.index')
            ->with('success', 'User unbanned.');
    }
}
