<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Localization\DateFormatter;
use App\Support\OAuth\ProviderRegistry;
use DateTimeInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;
use function activity;

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
    public function edit(Request $request, User $user)
    {
        $formatter = DateFormatter::for($request->user());

        $user->loadMissing(['roles', 'socialAccounts']);

        $socialAccounts = $user->socialAccounts
            ->sortBy('provider')
            ->values()
            ->map(function (SocialAccount $account) use ($formatter) {
                return [
                    'id' => $account->id,
                    'provider' => $account->provider,
                    'provider_id' => $account->provider_id,
                    'name' => $account->name,
                    'nickname' => $account->nickname,
                    'email' => $account->email,
                    'avatar' => $account->avatar,
                    'created_at' => $formatter->iso($account->created_at),
                    'updated_at' => $formatter->iso($account->updated_at),
                ];
            })
            ->all();

        $userData = [
            'id' => $user->id,
            'nickname' => $user->nickname,
            'email' => $user->email,
            'email_verified_at' => $formatter->iso($user->email_verified_at),
            'created_at' => $formatter->iso($user->created_at),
            'updated_at' => $formatter->iso($user->updated_at),
            'last_activity_at' => $formatter->iso($user->last_activity_at),
            'avatar_url' => $user->avatar_url,
            'profile_bio' => $user->profile_bio,
            'social_links' => $user->social_links,
            'roles' => $user->roles->map(fn ($role) => $role->only(['id', 'name']))->values()->all(),
            'social_accounts' => $socialAccounts,
        ];

        $providers = collect(ProviderRegistry::all())
            ->map(fn ($meta, $key) => [
                'key' => $key,
                'label' => $meta['label'] ?? ucfirst($key),
                'description' => $meta['description'] ?? null,
            ])
            ->values()
            ->all();

        return Inertia::render('acp/UsersEdit', [
            'user' => $userData,
            'allRoles' => Role::all(),
            'availableSocialProviders' => $providers,
        ]);
    }

    /**
     * Store a new user record.
     */
    public function store(StoreUserRequest $request)
    {
        $user = User::create($request->validated());
        $user->syncRoles($request->roles ?? []);

        activity('users')
            ->event('user.created')
            ->performedOn($user)
            ->causedBy($request->user())
            ->withProperties([
                'attributes' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'roles' => $user->roles->pluck('name')->values()->all(),
                ],
            ])
            ->log(sprintf('User %s created', $user->email ?? $user->id));

        return redirect()->route('acp.users.index')
            ->with('success','User created.');
    }

    /**
     * Update the specified user.
     */
    public function update(UpdateUserRequest $request, User $user)
    {
        $validated = $request->validated();

        $originalValues = Arr::only($user->getAttributes(), array_keys($validated));
        $originalRoles = $user->roles->pluck('name')->sort()->values()->all();

        $user->update($validated);
        $user->syncRoles($request->roles ?? []);
        $user->refresh();

        $newValues = Arr::only($user->getAttributes(), array_keys($validated));

        $oldChanges = [];
        $newChanges = [];

        foreach ($newValues as $key => $value) {
            $before = $originalValues[$key] ?? null;
            $normalisedBefore = $this->normaliseAuditValue($before);
            $normalisedAfter = $this->normaliseAuditValue($value);

            if ($normalisedBefore !== $normalisedAfter) {
                $oldChanges[$key] = $normalisedBefore;
                $newChanges[$key] = $normalisedAfter;
            }
        }

        if ($oldChanges !== []) {
            activity('users')
                ->event('user.updated')
                ->performedOn($user)
                ->causedBy($request->user())
                ->withProperties([
                    'old' => $oldChanges,
                    'attributes' => $newChanges,
                ])
                ->log(sprintf('User %s updated', $user->email ?? $user->id));
        }

        $updatedRoles = $user->roles->pluck('name')->sort()->values()->all();

        if ($originalRoles !== $updatedRoles) {
            activity('users')
                ->event('user.roles.updated')
                ->performedOn($user)
                ->causedBy($request->user())
                ->withProperties([
                    'old' => ['roles' => $originalRoles],
                    'attributes' => ['roles' => $updatedRoles],
                ])
                ->log(sprintf('Roles updated for %s', $user->email ?? $user->id));
        }

        return redirect()->route('acp.users.index')
            ->with('success','User updated.');
    }

    /**
     * Delete a user.
     */
    public function destroy(Request $request, User $user)
    {
        $causer = $request->user();

        $snapshot = [
            'id' => $user->id,
            'email' => $user->email,
            'roles' => $user->roles->pluck('name')->values()->all(),
        ];

        $user->delete();

        activity('users')
            ->event('user.deleted')
            ->performedOn($user)
            ->causedBy($causer)
            ->withProperties([
                'old' => $snapshot,
            ])
            ->log(sprintf('User %s deleted', $snapshot['email'] ?? $user->id));

        return redirect()->route('acp.users.index')
            ->with('success','User deleted.');
    }

    /**
     * Manually mark a user’s email as verified.
     */
    public function verify(Request $request, User $user)
    {
        $previouslyVerifiedAt = $user->email_verified_at;

        $user->update(['email_verified_at' => now()]);

        activity('users')
            ->event('user.verified')
            ->performedOn($user)
            ->causedBy($request->user())
            ->withProperties([
                'old' => ['email_verified_at' => $this->normaliseAuditValue($previouslyVerifiedAt)],
                'attributes' => ['email_verified_at' => $this->normaliseAuditValue($user->email_verified_at)],
            ])
            ->log(sprintf('User %s verified', $user->email ?? $user->id));

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

            activity('users')
                ->event('user.banned')
                ->performedOn($user)
                ->causedBy($request->user())
                ->withProperties([
                    'old' => ['is_banned' => false],
                    'attributes' => [
                        'is_banned' => true,
                        'banned_at' => $this->normaliseAuditValue($user->banned_at),
                    ],
                ])
                ->log(sprintf('User %s banned', $user->email ?? $user->id));
        }

        return redirect()->route('acp.users.index')
            ->with('success', 'User banned.');
    }

    public function unban(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('users.acp.ban');

        if ($user->is_banned) {
            $previousBanAt = $user->banned_at;

            $user->forceFill([
                'is_banned' => false,
                'banned_at' => null,
                'banned_by_id' => null,
            ])->save();

            activity('users')
                ->event('user.unbanned')
                ->performedOn($user)
                ->causedBy($request->user())
                ->withProperties([
                    'old' => [
                        'is_banned' => true,
                        'banned_at' => $this->normaliseAuditValue($previousBanAt),
                    ],
                    'attributes' => [
                        'is_banned' => false,
                        'banned_at' => null,
                    ],
                ])
                ->log(sprintf('User %s unbanned', $user->email ?? $user->id));
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
        $affectedIds = [];

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
                $affectedIds[] = $user->id;
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

        if ($updatedCount > 0) {
            activity('users')
                ->event('user.bulk_action')
                ->causedBy($request->user())
                ->withProperties([
                    'attributes' => [
                        'action' => $action,
                        'updated_user_ids' => $affectedIds,
                        'requested_user_ids' => $ids,
                    ],
                ])
                ->log(sprintf('Bulk user %s applied to %d users', $action, $updatedCount));
        }

        return back()->with('success', $message);
    }

    protected function normaliseAuditValue(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        return $value;
    }
}
