<?php

namespace App\Support\Impersonation;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Routing\ZiggyRouteGroup;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Read-only impersonation: staff seeing the site as one customer sees it (see docs/impersonation.md).
 *
 * A view is a few facts kept in the staff member's own session: who they are, whose account they are looking at, why, and when it
 * ends. Nothing is changed about either account, so there is nothing to undo, and signing out of the staff session ends it.
 * `ApplyImpersonation` does the rest, request by request.
 */
class Impersonation
{
    public const SESSION_KEY = 'impersonation';

    /**
     * The view in progress, or null. Anything in the session that is not a view this class made is no view.
     *
     * @return array{admin_id: int, user_id: int, reason: string, started_at: int, expires_at: int, views: int}|null
     */
    public function state(Request $request): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }

        $state = $request->session()->get(self::SESSION_KEY);

        if (! is_array($state)) {
            return null;
        }

        foreach (['admin_id', 'user_id', 'started_at', 'expires_at'] as $key) {
            if (! is_int($state[$key] ?? null)) {
                return null;
            }
        }

        return [
            'admin_id' => $state['admin_id'],
            'user_id' => $state['user_id'],
            'reason' => is_string($state['reason'] ?? null) ? $state['reason'] : '',
            'started_at' => $state['started_at'],
            'expires_at' => $state['expires_at'],
            'views' => is_int($state['views'] ?? null) ? $state['views'] : 0,
        ];
    }

    /**
     * Why this person may not be viewed as, or null when they may. Staff are never viewed as (a customer's account lends nobody any
     * power, and staff accounts are not for staff to look through), and neither is somebody banned, who cannot sign in.
     */
    public function refusal(User $admin, User $target, ?Request $request = null): ?string
    {
        if ($request !== null && $this->state($request) !== null) {
            return 'You are already viewing as somebody. Stop that first.';
        }

        if ($admin->is($target)) {
            return 'You cannot view as yourself.';
        }

        if ($target->is_banned) {
            return 'This account is banned, so it cannot be viewed as.';
        }

        if ($target->hasAnyRole(ZiggyRouteGroup::STAFF_ROLES)) {
            return 'Staff accounts cannot be viewed as. Only customers can.';
        }

        return null;
    }

    /**
     * Begin a view. The caller has checked `refusal()`.
     */
    public function start(Request $request, User $admin, User $target, string $reason): void
    {
        $now = now();

        $request->session()->put(self::SESSION_KEY, [
            'admin_id' => (int) $admin->getKey(),
            'user_id' => (int) $target->getKey(),
            'reason' => $reason,
            'started_at' => $now->getTimestamp(),
            'expires_at' => $now->copy()->addMinutes(max(1, (int) config('impersonation.minutes', 30)))->getTimestamp(),
            'views' => 0,
        ]);
    }

    /**
     * What ends the view in progress, if anything has: the time is up, the account stopped being one a view can be of, or the staff
     * member lost the right to.
     *
     * @param  array{admin_id: int, user_id: int, reason: string, started_at: int, expires_at: int, views: int}  $state
     * @return 'expired'|'account'|'permission'|null
     */
    public function endedBy(array $state, User $admin, User $target): ?string
    {
        if (now()->getTimestamp() >= $state['expires_at']) {
            return 'expired';
        }

        if ($target->is_banned || $target->hasAnyRole(ZiggyRouteGroup::STAFF_ROLES)) {
            return 'account';
        }

        if ($admin->is_banned || ! $admin->can('users.acp.impersonate')) {
            return 'permission';
        }

        return null;
    }

    /**
     * End the view in progress and record that it ended, and how. Returns the account that was being viewed (null if there is none
     * any more, or no view). Ending is never held up by the log: nobody is kept in a view because an entry could not be written.
     *
     * @param  'you'|'logout'|'expired'|'account'|'permission'  $how
     */
    public function stop(Request $request, string $how): ?User
    {
        $state = $this->state($request);

        $this->forget($request);

        if ($state === null) {
            return null;
        }

        $admin = User::query()->find($state['admin_id']);
        $target = User::query()->find($state['user_id']);

        try {
            app(Audit::class)->record(
                'impersonation.stopped',
                $admin,
                $target,
                meta: [
                    'ended_by' => $how,
                    'reason' => $state['reason'],
                    'seconds' => max(0, now()->getTimestamp() - $state['started_at']),
                    'pages' => $state['views'],
                    'account' => $state['user_id'],
                ],
                request: $request,
            );
        } catch (Throwable $exception) {
            report($exception);
        }

        return $target;
    }

    /**
     * Drop the view without a word (it belonged to a sign-in that is not this one).
     */
    public function forget(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget(self::SESSION_KEY);
        }
    }

    /**
     * Whether a page may be opened while viewing as somebody: only those the configuration lists (`viewable`).
     */
    public function isViewable(Route $route): bool
    {
        return in_array(self::identify($route), (array) config('impersonation.viewable', []), true);
    }

    /**
     * How a route is listed in the configuration: by its name, or by its address when it has none.
     */
    public static function identify(Route $route): string
    {
        return $route->getName() ?? $route->uri();
    }

    /**
     * Whether a route belongs to the API (the `api` middleware group), which is not available during a view.
     */
    public function isApi(Route $route): bool
    {
        return in_array('api', $route->middleware(), true);
    }

    /**
     * Write down that a page is being opened. This comes before the page is served, so a page that cannot be written down is not
     * served: nothing seen through a view is unrecorded. A reload of part of a page and a prefetch are not a page opened.
     */
    public function recordView(Request $request, User $admin, User $target): void
    {
        if ($this->isQuiet($request)) {
            return;
        }

        app(Audit::class)->record('impersonation.viewed', $admin, $target, meta: $this->page($request), request: $request);

        $request->session()->increment(self::SESSION_KEY.'.views');
    }

    /**
     * Write down that something was refused while viewing as somebody (a page kept from view, or an attempt to change something).
     */
    public function recordRefusal(Request $request, User $admin, User $target, int $status): void
    {
        app(Audit::class)->record(
            'impersonation.blocked',
            $admin,
            $target,
            meta: $this->page($request) + ['status' => $status],
            outcome: AuditLog::DENIED,
            request: $request,
        );
    }

    /**
     * What the person is shown at the top of every page while they are viewing as somebody. Null when they are not.
     *
     * @return array{user: array{id: int, nickname: string, email: string}, by: string|null, reason: string, expires_at: string, stop_url: string}|null
     */
    public function banner(Request $request): ?array
    {
        $state = $this->state($request);
        $user = $request->user();

        if ($state === null || ! $user instanceof User || $user->getKey() !== $state['user_id']) {
            return null;
        }

        return [
            'user' => ['id' => (int) $user->getKey(), 'nickname' => (string) $user->nickname, 'email' => (string) $user->email],
            'by' => User::query()->find($state['admin_id'])?->nickname,
            'reason' => $state['reason'],
            'expires_at' => Carbon::createFromTimestamp($state['expires_at'])->toIso8601String(),
            'stop_url' => route('impersonation.stop', absolute: false),
        ];
    }

    /**
     * @return array{route: string|null, method: string, path: string}
     */
    private function page(Request $request): array
    {
        return [
            'route' => $request->route()?->getName(),
            'method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
        ];
    }

    private function isQuiet(Request $request): bool
    {
        $purpose = strtolower((string) ($request->header('Purpose') ?? $request->header('Sec-Purpose') ?? ''));

        return $request->isMethod('HEAD')
            || $request->header('X-Inertia-Partial-Data') !== null
            || str_contains($purpose, 'prefetch');
    }
}
