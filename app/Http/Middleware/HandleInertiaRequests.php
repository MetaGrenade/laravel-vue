<?php

namespace App\Http\Middleware;

use App\Support\Commerce\CartManager;
use App\Support\Localization\DateFormatter;
use App\Support\OAuth\OAuthProviders;
use App\Support\Routing\ZiggyRouteGroup;
use App\Support\Seo\Seo;
use App\Support\WebsiteSections;
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
        $user = $request->user();
        $formatter = DateFormatter::for($user);
        $websiteSections = WebsiteSections::all();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => function () {
                [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

                return [
                    'message' => trim($message),
                    'author' => trim($author),
                ];
            },
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'warning' => $request->session()->get('warning'),
                'info' => $request->session()->get('info'),
                'plain_text_token' => $request->session()->get('plain_text_token'),
            ],
            'auth' => [
                'user' => $user ? $user->load(['roles', 'badges']) : null,
                'permissions' => $user
                    ? $user->getAllPermissions()->pluck('name')
                    : [],
            ],
            'notifications' => fn () => $user ? (function () use ($user, $formatter) {
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
            'ziggy' => fn () => $this->ziggy($request),
            // Rendered into <head> by Inertia's `serverHead` option (see resources/js/app.ts).
            'seoHead' => fn () => app(Seo::class)->headElements(),
            'billing' => [
                'stripeKey' => config('cashier.key'),
            ],
            'settings' => [
                'website_sections' => $websiteSections,
                'oauth_providers' => OAuthProviders::all(),
            ],
            'cart' => function () use ($request, $websiteSections) {
                if (! $websiteSections['commerce']) {
                    return null;
                }

                $cart = CartManager::forRequest($request);

                return CartManager::summary($cart);
            },
        ];
    }

    /**
     * Ziggy data for the page.
     *
     * The browser receives the route map for its group once, via the @routes
     * Blade directive, and reports that group on every Inertia request (the
     * X-Ziggy-Group header, see resources/js/lib/ziggy.ts). The map is only
     * sent again when it is needed:
     *
     * - on Inertia visits where the user's group has changed, e.g. a staff
     *   member signing in from the guest login page, or signing out;
     * - on initial page loads rendered by the SSR server.
     *
     * @return array<string, mixed>
     */
    protected function ziggy(Request $request): array
    {
        $group = ZiggyRouteGroup::for($request->user());

        $data = [
            'location' => $request->fullUrl(),
            'group' => $group,
        ];

        $needsRoutes = $request->inertia()
            ? $request->header(ZiggyRouteGroup::HEADER) !== $group
            : (bool) config('inertia.ssr.enabled');

        if (! $needsRoutes) {
            return $data;
        }

        return [...(new Ziggy($group))->toArray(), ...$data];
    }
}
