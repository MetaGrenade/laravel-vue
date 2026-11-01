<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

class UserNotificationController extends Controller
{
    // An id that is not one of the member's stored notifications is not an error. The bell can show an alert that was only pushed to
    // the browser (the member turned the in-site notice off), one dismissed in another tab, or one somebody else owns. There is
    // nothing to change, the page that comes back lists what is stored, and answering 404 to a click would open an error dialog.

    public function markAsRead(Request $request, string $notification): RedirectResponse
    {
        $user = $request->user();

        abort_if($user === null, 403);

        $this->stored($user, $notification)?->markAsRead();

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

        $this->stored($user, $notification)?->delete();

        return back(status: 303);
    }

    private function stored(User $user, string $id): ?DatabaseNotification
    {
        // The id column is a uuid, and PostgreSQL refuses to compare it with any other text (a 500), where SQLite and MySQL just find nothing.
        if (! Str::isUuid($id)) {
            return null;
        }

        return $user->notifications()->whereKey($id)->first();
    }
}
