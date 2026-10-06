<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Security Headers
    |--------------------------------------------------------------------------
    |
    | Sent with every web response by App\Http\Middleware\SecurityHeaders.
    | Set a header to null to omit it.
    |
    */

    'headers' => [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), browsing-topics=()',
        'Cross-Origin-Opener-Policy' => 'same-origin-allow-popups',
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Strict Transport Security
    |--------------------------------------------------------------------------
    |
    | Only sent over HTTPS. Enable once the whole site (and, when
    | include_subdomains is true, every subdomain) is served over HTTPS.
    |
    */

    'hsts' => [
        'enabled' => (bool) env('SECURITY_HSTS', env('APP_ENV') === 'production'),
        'max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),
        'include_subdomains' => (bool) env('SECURITY_HSTS_SUBDOMAINS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy
    |--------------------------------------------------------------------------
    |
    | A nonce-based policy: Vite's script/style tags and the app's inline
    | scripts receive a per-request nonce. Use report-only mode to trial
    | changes (violations are logged in the browser console). Add sources for
    | any third-party services you integrate (analytics, CDNs, embeds).
    |
    */

    'csp' => [
        'enabled' => (bool) env('CSP_ENABLED', true),
        'report_only' => (bool) env('CSP_REPORT_ONLY', false),
        'report_uri' => env('CSP_REPORT_URI'),

        'directives' => [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", '{nonce}', "'strict-dynamic'", 'https:', 'https://js.stripe.com', 'https://*.js.stripe.com'],
            // Vue style bindings and some libraries (charts, toasts) set inline styles.
            'style-src' => ["'self'", "'unsafe-inline'"],
            'img-src' => ["'self'", 'data:', 'blob:', 'https:'],
            'font-src' => ["'self'", 'data:'],
            'connect-src' => ["'self'", 'https://api.stripe.com', 'https://*.pusher.com', 'wss://*.pusher.com'],
            'frame-src' => ['https://js.stripe.com', 'https://*.js.stripe.com', 'https://hooks.stripe.com'],
            'frame-ancestors' => ["'self'"],
            'form-action' => ["'self'"],
            'base-uri' => ["'self'"],
            'object-src' => ["'none'"],
        ],
    ],

];
