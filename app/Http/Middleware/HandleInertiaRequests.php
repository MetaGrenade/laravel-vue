<?php

namespace App\Http\Middleware;

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
                'user' => $request->user() ? $request->user()->load('roles') : null,
                'permissions' => $request->user()
                    ? $request->user()->getAllPermissions()->pluck('name')
                    : [],
            ],
            'notifications' => $request->user() ? (function () use ($request) {
                $user = $request->user();

                $unreadQuery = $user->unreadNotifications()->latest();

                $unreadCount = (clone $unreadQuery)->count();

                $items = $unreadQuery
                    ->limit(10)
                    ->get()
                    ->map(static function ($notification) {
                        $data = $notification->data ?? [];

                        return [
                            'id' => $notification->id,
                            'type' => $notification->type,
                            'title' => $data['title'] ?? $data['thread_title'] ?? 'Notification',
                            'excerpt' => $data['excerpt'] ?? null,
                            'url' => $data['url'] ?? null,
                            'data' => $data,
                            'created_at' => optional($notification->created_at)?->toIso8601String(),
                            'created_at_for_humans' => optional($notification->created_at)?->diffForHumans(),
                            'read_at' => optional($notification->read_at)?->toIso8601String(),
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
