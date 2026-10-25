<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StartImpersonationRequest;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Impersonation\Impersonation;
use Illuminate\Http\RedirectResponse;

/**
 * Starting a read-only view as a customer (see docs/impersonation.md). Who may is the route's `can:users.acp.impersonate`.
 */
class ImpersonationController extends Controller
{
    public function store(StartImpersonationRequest $request, User $user, Impersonation $impersonation, Audit $audit): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $refusal = $impersonation->refusal($admin, $user, $request);

        if ($refusal !== null) {
            return back()->withErrors(['impersonate' => $refusal]);
        }

        $reason = (string) $request->validated('reason');

        $impersonation->start($request, $admin, $user, $reason);

        // The audit log writes this request's entry as it ends (it is a change under `acp/`); say what it was and why.
        $audit->event('impersonation.started');
        $audit->subject($user);
        $audit->note('reason', $reason);
        $audit->note('minutes', (int) config('impersonation.minutes', 30));

        return redirect()->route('dashboard');
    }
}
