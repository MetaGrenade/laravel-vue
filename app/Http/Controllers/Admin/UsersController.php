<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Billing\SubscriptionCancellationFailed;
use App\Support\Billing\SubscriptionManager;
use App\Support\Impersonation\Impersonation;
use App\Support\Localization\DateFormatter;
use App\Support\OAuth\OAuthProviders;
use Carbon\CarbonInterface;
use Illuminate\Auth\Events\Verified;
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
                    ->whereLike('nickname', $like)
                    ->orWhereLike('email', $like)
                    ->orWhereHas('roles', function ($roleQuery) use ($like) {
                        $roleQuery->whereLike('name', $like);
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
            // `created_at` is to the second, so accounts made together tie: the id settles them, or a page could repeat or skip one.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
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
    public function edit(Request $request, User $user, Impersonation $impersonation)
    {
        $formatter = DateFormatter::for($request->user());
        $admin = $request->user();

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

        $providers = collect(OAuthProviders::enabledOptions())
            ->map(fn ($meta) => [
                'key' => $meta['key'],
                'label' => $meta['label'] ?? ucfirst($meta['key']),
                'description' => $meta['description'] ?? null,
            ])
            ->values()
            ->all();

        return Inertia::render('acp/UsersEdit', [
            'user' => $userData,
            'allRoles' => Role::all(),
            'availableSocialProviders' => $providers,
            // The "view as this user" card (docs/impersonation.md): whether the staff member may, and if this person cannot be viewed
            // as, why. Not called `impersonation`: that is the shared prop the bar at the top of a view reads, which a page prop of the
            // same name replaces.
            'viewAs' => [
                'can' => $admin instanceof User && $admin->can('users.acp.impersonate'),
                'refusal' => $admin instanceof User ? $impersonation->refusal($admin, $user) : null,
                'minutes' => (int) config('impersonation.minutes', 30),
            ],
        ]);
    }

    /**
     * Store a new user record.
     */
    public function store(StoreUserRequest $request)
    {
        $user = User::create($request->validated());
        app(Audit::class)->tracking($user, 'roles', fn () => $user->syncRoles($request->roles ?? []));

        return redirect()->route('acp.users.index')
            ->with('success', 'User created.');
    }

    /**
     * Update the specified user.
     */
    public function update(UpdateUserRequest $request, User $user)
    {
        $user->update($request->validated());
        app(Audit::class)->tracking($user, 'roles', fn () => $user->syncRoles($request->roles ?? []));

        return redirect()->route('acp.users.index')
            ->with('success', 'User updated.');
    }

    /**
     * Delete a user, after ending any subscription they have so that it cannot go on charging them.
     */
    public function destroy(User $user, SubscriptionManager $subscriptions)
    {
        Gate::authorize('users.acp.delete');

        if (! $this->deleteEndingSubscriptions($user, $subscriptions)) {
            return back()->with('error', "{$user->nickname} was not deleted: their subscription could not be cancelled at Stripe, so it may still be charging them. Nothing was changed; try again in a few minutes.");
        }

        return redirect()->route('acp.users.index')
            ->with('success', 'User deleted.');
    }

    /**
     * Manually mark a user’s email as verified.
     */
    public function verify(User $user)
    {
        $this->markVerified($user, now());

        return redirect()->route('acp.users.index')
            ->with('success', 'User verified.');
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

    public function bulkUpdate(Request $request, SubscriptionManager $subscriptions): RedirectResponse
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
        $notDeleted = [];

        foreach ($users as $user) {
            $changed = false;

            switch ($action) {
                case 'verify':
                    if ($user->email_verified_at === null) {
                        $this->markVerified($user, $now);
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
                    // One user whose subscription cannot be cancelled is left alone; it does not stop the others.
                    if ($this->deleteEndingSubscriptions($user, $subscriptions)) {
                        $changed = true;
                    } else {
                        $notDeleted[] = $user->nickname;
                    }

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

        $response = back()->with('success', $message);

        if ($notDeleted !== []) {
            $names = collect($notDeleted)->take(5)->implode(', ');
            $more = count($notDeleted) > 5 ? ' and '.(count($notDeleted) - 5).' more' : '';

            $response->with('error', 'Not deleted, because their subscription could not be cancelled at Stripe and may still be charging them: '.$names.$more.'. Nothing was changed for them; try again in a few minutes.');
        }

        return $response;
    }

    /**
     * Delete a user once any subscription they have is ended at Stripe. False means it could not be ended, and then
     * the user is left exactly as they were: deleting them would leave the subscription renewing with nobody to ask.
     */
    private function deleteEndingSubscriptions(User $user, SubscriptionManager $subscriptions): bool
    {
        try {
            $subscriptions->deleteUser($user);
        } catch (SubscriptionCancellationFailed) {
            return false;
        }

        return true;
    }

    /**
     * Mark a user's email as verified, announcing it the way the verification link does, so that whatever
     * waits on a verified address (a guest's earlier orders being linked to the account) is not skipped.
     */
    private function markVerified(User $user, CarbonInterface $at): void
    {
        $wasVerified = $user->hasVerifiedEmail();

        $user->forceFill(['email_verified_at' => $at])->save();

        if (! $wasVerified) {
            event(new Verified($user));
        }
    }
}
