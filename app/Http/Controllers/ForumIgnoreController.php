<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Forum\IgnoredMembers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ForumIgnoreController extends Controller
{
    public function store(Request $request, User $user, IgnoredMembers $ignored): RedirectResponse
    {
        $viewer = $request->user();

        abort_if($viewer === null, 403);

        $ignored->ignore($viewer, $user);

        return redirect()->back()->with('success', 'You are ignoring '.$user->nickname.'.');
    }

    public function destroy(Request $request, User $user, IgnoredMembers $ignored): RedirectResponse
    {
        $viewer = $request->user();

        abort_if($viewer === null, 403);

        $ignored->forget($viewer, $user);

        return redirect()->back()->with('success', 'You stopped ignoring '.$user->nickname.'.');
    }
}
