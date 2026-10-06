<?php

/*
|--------------------------------------------------------------------------
| Ziggy Route Groups
|--------------------------------------------------------------------------
|
| Ziggy exposes named routes to the frontend. Only the routes a visitor can
| plausibly use are published: guests and members never receive the admin
| control panel route map, and internal/integration routes are always
| omitted. See App\Support\Routing\ZiggyRouteGroup for group selection.
|
*/

$internal = [
    '!cashier.*',
    '!sanctum.*',
    '!storage.*',
    '!stripe.*',
    '!_inertia.*',
    '!ignition.*',
];

return [
    'groups' => [
        'public' => [...$internal, '!acp.*'],
        'staff' => $internal,
    ],
];
