<?php

namespace App\Http\Controllers;

use App\Support\Impersonation\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Ending a view as a customer, from the bar at the top of every page of it (see docs/impersonation.md).
 *
 * `ApplyImpersonation` lets this one request through a read-only view, since it is the way out of it. With no view in progress it
 * does nothing but send the person home.
 */
class ImpersonationStopController extends Controller
{
    public function __invoke(Request $request, Impersonation $impersonation): RedirectResponse
    {
        $target = $impersonation->stop($request, 'you');

        if ($target === null) {
            return redirect()->route('dashboard');
        }

        return redirect()->route('acp.users.edit', $target)->with('info', "You have stopped viewing as {$target->nickname}.");
    }
}
