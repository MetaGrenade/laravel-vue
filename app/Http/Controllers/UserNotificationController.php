<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class UserNotificationController extends Controller
{
    public function markAsRead(Request $request, DatabaseNotification $notification): RedirectResponse
    {
        $user = $request->user();

        abort_if($user === null, 403);
        abort_if(
            $notification->notifiable_id !== $user->id ||
            $notification->notifiable_type !== $user::class,
            404,
        );

        if ($notification->read_at === null) {
            $notification->markAsRead();
        }

        return back(status: 303);
    }

    public function markAllAsRead(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_if($user === null, 403);

        $user->unreadNotifications()->update(['read_at' => now()]);

        return back(status: 303);
    }

    public function destroy(Request $request, DatabaseNotification $notification): RedirectResponse
    {
        $user = $request->user();

        abort_if($user === null, 403);
        abort_if(
            $notification->notifiable_id !== $user->id ||
            $notification->notifiable_type !== $user::class,
            404,
        );

        $notification->delete();

        return back(status: 303);
    }
}
