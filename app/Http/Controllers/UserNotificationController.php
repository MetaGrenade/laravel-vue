<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UserNotificationController extends Controller
{
    // An id that is not one of the member's stored notifications is not an error. The bell can show an alert that was only pushed to
    // the browser (the member turned the in-site notice off), one dismissed in another tab, or one somebody else owns. There is
    // nothing to change, the page that comes back lists what is stored, and answering 404 to a click would open an error dialog.

    public function markAsRead(Request $request, string $notification): RedirectResponse
    {
        $user = $request->user();

        abort_if($user === null, 403);

        $user->notifications()->whereKey($notification)->first()?->markAsRead();

        return back(status: 303);
    }

    public function markAllAsRead(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_if($user === null, 403);

        $user->unreadNotifications()->update(['read_at' => now()]);

        return back(status: 303);
    }

    public function destroy(Request $request, string $notification): RedirectResponse
    {
        $user = $request->user();

        abort_if($user === null, 403);

        $user->notifications()->whereKey($notification)->first()?->delete();

        return back(status: 303);
    }
}
