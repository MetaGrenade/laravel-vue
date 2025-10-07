<?php

namespace App\Http\Middleware;

use App\Support\Localization\DateFormatter;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        $user = $request->user();
        $formatter = DateFormatter::for($user);

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => [
                'message' => trim($message),
                'author' => trim($author)
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'plain_text_token' => $request->session()->get('plain_text_token'),
            ],
            'auth' => [
                'user' => $user ? $user->load('roles') : null,
                'permissions' => $user
                    ? $user->getAllPermissions()->pluck('name')
                    : [],
            ],
            'notifications' => $user ? (function () use ($user, $formatter) {
                $unreadQuery = $user->unreadNotifications()->latest();

                $unreadCount = (clone $unreadQuery)->count();

                $items = $unreadQuery
                    ->limit(10)
                    ->get()
                    ->map(function ($notification) use ($formatter) {
                        $data = $notification->data ?? [];

                        return [
                            'id' => $notification->id,
                            'type' => $notification->type,
                            'title' => $data['title'] ?? $data['thread_title'] ?? 'Notification',
                            'excerpt' => $data['excerpt'] ?? null,
                            'url' => $data['url'] ?? null,
                            'data' => $data,
                            'created_at' => $formatter->iso($notification->created_at),
                            'created_at_for_humans' => $formatter->human($notification->created_at),
                            'read_at' => $formatter->iso($notification->read_at),
                        ];
                    })
                    ->values()
                    ->all();

                return [
                    'items' => $items,
                    'unread_count' => $unreadCount,
                    'has_more' => $unreadCount > count($items),
                ];
            })() : [
                'items' => [],
                'unread_count' => 0,
                'has_more' => false,
            ],
            'ziggy' => [
                ...(new Ziggy)->toArray(),
                'location' => $request->url(),
            ],
        ];
    }
}
