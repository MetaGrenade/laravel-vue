<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use App\Support\Localization\DateFormatter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
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
        $role = trim((string) $request->query('role', '')) ?: null;
        $verification = $request->query('verification');
        $banned = $request->query('banned');
        $activityWindow = $request->query('activity_window');

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

        if ($role !== null) {
            $filteredQuery->whereHas('roles', function ($roleQuery) use ($role) {
                $roleQuery->where('name', $role);
            });
        }

        if (in_array($verification, ['verified', 'unverified'], true)) {
            $filteredQuery->when($verification === 'verified', function ($query) {
                $query->whereNotNull('email_verified_at');
            }, function ($query) {
                $query->whereNull('email_verified_at');
            });
        } else {
            $verification = null;
        }

        if (in_array($banned, ['banned', 'not_banned'], true)) {
            $filteredQuery->where('is_banned', $banned === 'banned');
        } else {
            $banned = null;
        }

        if ($activityWindow !== null) {
            $activityWindow = (int) $activityWindow;

            if ($activityWindow > 0) {
                $filteredQuery->where('last_activity_at', '>=', now()->subMinutes($activityWindow));
            } else {
                $activityWindow = null;
            }
        }

        $formatter = DateFormatter::for($request->user());

        $users = (clone $filteredQuery)
            ->with(['roles:id,name', 'bannedBy:id,nickname'])
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();

        $userItems = $users->getCollection()
            ->map(function (User $user) use ($formatter) {
                return [
                    'id' => $user->id,
                    'nickname' => $user->nickname,
                    'email' => $user->email,
                    'email_verified_at' => $formatter->iso($user->email_verified_at),
                    'last_activity_at' => $formatter->iso($user->last_activity_at),
                    'is_banned' => $user->is_banned,
                    'banned_at' => $formatter->iso($user->banned_at),
                    'banned_by' => $user->bannedBy?->only(['id', 'nickname']),
                    'created_at' => $formatter->iso($user->created_at),
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
                'role' => $role,
                'verification' => $verification,
                'banned' => $banned,
                'activity_window' => $activityWindow,
            ],
            'availableRoles' => Role::query()->orderBy('name')->pluck('name')->values()->all(),
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

    public function bulkUpdate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['verify', 'ban', 'unban', 'delete'])],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct', 'exists:users,id'],
        ]);

        $action = $validated['action'];

        match ($action) {
            'verify' => Gate::authorize('users.acp.verify'),
            'ban', 'unban' => Gate::authorize('users.acp.ban'),
            'delete' => Gate::authorize('users.acp.delete'),
        };

        $ids = array_values(array_unique(array_map('intval', $validated['ids'])));

        $users = User::query()
            ->whereIn('id', $ids)
            ->get();

        if ($users->isEmpty()) {
            return back()->with('success', 'No users required updates.');
        }

        $actorId = (int) $request->user()->id;
        $now = now();
        $updatedCount = 0;

        foreach ($users as $user) {
            $changed = false;

            switch ($action) {
                case 'verify':
                    if ($user->email_verified_at === null) {
                        $user->forceFill(['email_verified_at' => $now])->save();
                        $changed = true;
                    }

                    break;

                case 'ban':
                    if (! $user->is_banned) {
                        $user->forceFill([
                            'is_banned' => true,
                            'banned_at' => $now,
                            'banned_by_id' => $actorId,
                        ])->save();
                        $changed = true;
                    }

                    break;

                case 'unban':
                    if ($user->is_banned) {
                        $user->forceFill([
                            'is_banned' => false,
                            'banned_at' => null,
                            'banned_by_id' => null,
                        ])->save();
                        $changed = true;
                    }

                    break;

                case 'delete':
                    $user->delete();
                    $changed = true;
                    break;
            }

            if ($changed) {
                $updatedCount++;
            }
        }

        $message = match ($action) {
            'verify' => match ($updatedCount) {
                0 => 'No users required verification updates.',
                1 => 'Verified 1 user.',
                default => "Verified {$updatedCount} users.",
            },
            'ban' => match ($updatedCount) {
                0 => 'No users required ban updates.',
                1 => 'Banned 1 user.',
                default => "Banned {$updatedCount} users.",
            },
            'unban' => match ($updatedCount) {
                0 => 'No users required unban updates.',
                1 => 'Unbanned 1 user.',
                default => "Unbanned {$updatedCount} users.",
            },
            'delete' => match ($updatedCount) {
                0 => 'No users were deleted.',
                1 => 'Deleted 1 user.',
                default => "Deleted {$updatedCount} users.",
            },
        };

        return back()->with('success', $message);
    }
}
