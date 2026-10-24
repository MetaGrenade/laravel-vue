<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Impersonation\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * While a staff member is viewing as a customer (see docs/impersonation.md), makes the request the customer's and keeps it read only.
 *
 * - **Whose request it is.** The signed-in user is swapped for the customer for this request only. The staff member's own sign-in is
 *   left alone and put back before the session is saved, so nothing is logged in or out (no sign-in event, no "new device" email),
 *   the session keeps the staff member's id, and signing out of the staff session ends the view.
 * - **Nothing is changed.** Anything but a GET is refused. A GET runs in a transaction that is rolled back, so what a page does as it
 *   is looked at (a cart made, a counter bumped, `last_activity_at`) is not kept.
 * - **Only the pages that are safe.** A GET is served only for a route in `config/impersonation.php`; the rest, and the API, are refused.
 * - **Everything is written down,** before it is served.
 *
 * It runs before authentication (it is in the priority list for that), so the checks, route bindings and the pages see the customer.
 */
class ApplyImpersonation
{
    public function __construct(private readonly Impersonation $impersonation) {}

    public function handle(Request $request, Closure $next): Response
    {
        $state = $this->impersonation->state($request);

        if ($state === null) {
            return $next($request);
        }

        $guard = Auth::guard('web');
        $admin = $guard->user();

        // A view belongs to the sign-in that began it. If the session now belongs to somebody else, it is not theirs to carry on.
        if (! $admin instanceof User || $admin->getKey() !== $state['admin_id']) {
            $this->impersonation->forget($request);

            return $next($request);
        }

        $target = User::query()->find($state['user_id']);

        if ($target === null) {
            return $this->ended($request, $this->impersonation->stop($request, 'account'), 'account');
        }

        $ended = $this->impersonation->endedBy($state, $admin, $target);

        if ($ended !== null) {
            return $this->ended($request, $this->impersonation->stop($request, $ended), $ended);
        }

        $guard->setUser($target);

        try {
            return $this->serve($request, $next, $admin, $target);
        } finally {
            // Before the session is saved, which records who it belongs to.
            $guard->setUser($admin);
        }
    }

    private function serve(Request $request, Closure $next, User $admin, User $target): Response
    {
        $route = $request->route();

        if ($route === null) {
            return $next($request);
        }

        $name = $route->getName();

        // The two ways out. Stopping is the one thing a view may do (it is a POST), and signing out of a view is stopping it.
        if ($name === 'impersonation.stop') {
            return $next($request);
        }

        if ($name === 'logout') {
            return $this->ended($request, $this->impersonation->stop($request, 'logout'), 'logout');
        }

        // What a page asks for by itself as it opens: turned away, but not written down, since the person did not do it.
        if (in_array(Impersonation::identify($route), (array) config('impersonation.background', []), true)) {
            return response()->json(['message' => 'Live updates are not available while you are viewing as somebody.'], 403);
        }

        if ($this->impersonation->isApi($route)) {
            return $this->refuse($request, $admin, $target, 403, 'The API is not available while you are viewing as somebody.');
        }

        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return $this->refuse($request, $admin, $target, 403, "This is a read-only view: nothing can be changed while you are viewing as {$target->nickname}.");
        }

        if (! $this->impersonation->isViewable($route)) {
            return $this->refuse($request, $admin, $target, 403, 'That page is not available while you are viewing as somebody else.');
        }

        // Written down first: a page that cannot be recorded is not shown.
        $this->impersonation->recordView($request, $admin, $target);

        $level = DB::transactionLevel();
        DB::beginTransaction();

        try {
            return $next($request);
        } finally {
            // Whatever the page did, however many transactions it opened and left open, goes back to where it was.
            while (DB::transactionLevel() > $level) {
                DB::rollBack();
            }
        }
    }

    private function refuse(Request $request, User $admin, User $target, int $status, string $message): Response
    {
        $this->impersonation->recordRefusal($request, $admin, $target, $status);

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], $status);
        }

        // A page that is refused goes to the dashboard, which can always be opened (so this cannot loop). An attempt to change something
        // goes back to the page it was made from, which is usually the very address it was sent to (a form posts to its own page): that is
        // no loop, since what it goes back with is a GET. It is a 303 so the browser does not repeat the request at the new address (the
        // Inertia middleware that normally makes it one has not run).
        if ($request->isMethodSafe()) {
            return redirect()->route('dashboard')->with('error', $message);
        }

        // As an error too, so a form on the page it goes back to is told it failed and does not report "Saved.".
        return redirect()->to(url()->previous(), 303)->with('error', $message)->withErrors(['impersonation' => $message]);
    }

    /**
     * Where somebody goes when their view has ended: back to the account they were looking at, in the admin area.
     */
    private function ended(Request $request, ?User $target, string $how): Response
    {
        $minutes = (int) config('impersonation.minutes', 30);

        $message = match ($how) {
            'expired' => "Your view ended after {$minutes} minutes.",
            'account' => 'Your view ended because that account can no longer be viewed as.',
            'permission' => 'Your view ended because you can no longer view as somebody.',
            default => 'You have stopped viewing as '.($target->nickname ?? 'that account').'.',
        };

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 403);
        }

        $to = match (true) {
            $how === 'permission' => route('dashboard'),
            $target !== null => route('acp.users.edit', $target),
            default => route('acp.users.index'),
        };

        return redirect()->to($to, $request->isMethodSafe() ? 302 : 303)->with('info', $message);
    }
}
