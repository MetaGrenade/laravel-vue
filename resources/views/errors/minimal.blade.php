@php
    $appearance = request()->cookie('appearance', 'system');
    $appearance = in_array($appearance, ['light', 'dark', 'system'], true) ? $appearance : 'system';
    $code = trim($__env->yieldContent('code'));
    $message = trim($__env->yieldContent('message'));
    $hintKey = \Illuminate\Support\Facades\Lang::has("errors.hints.{$code}") ? "errors.hints.{$code}" : 'errors.hints.default';
    $hint = __($hintKey);
    $generic = [
        '401' => 'Unauthorized',
        '402' => 'Payment Required',
        '403' => 'Forbidden',
        '404' => 'Not Found',
        '419' => 'Page Expired',
        '429' => 'Too Many Requests',
        '500' => 'Server Error',
        '503' => 'Service Unavailable',
    ];
    $detail = $message !== '' && (! isset($generic[$code]) || strcasecmp($message, $generic[$code]) !== 0) ? $message : null;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ $appearance === 'dark' ? 'dark' : ($appearance === 'light' ? 'light' : '') }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">
        <title>@yield('title')</title>
        <style>
            :root {
                color-scheme: light;
                --canvas: #f4f6fb;
                --ink: #141824;
                --muted: #5c6478;
                --card: #ffffff;
                --line: #d9dfec;
                --primary: #5346e8;
                --violet: #d5cffc;
                --gold: #f6d59a;
            }

            html.dark {
                color-scheme: dark;
                --canvas: #070b16;
                --ink: #f3f5fb;
                --muted: #b4bccb;
                --card: #141b2e;
                --line: #2a3348;
            }

            @media (prefers-color-scheme: dark) {
                html:not(.light) {
                    color-scheme: dark;
                    --canvas: #070b16;
                    --ink: #f3f5fb;
                    --muted: #b4bccb;
                    --card: #141b2e;
                    --line: #2a3348;
                }
            }

            * { box-sizing: border-box; }

            html, body {
                margin: 0;
                min-height: 100%;
                background: var(--canvas);
                color: var(--ink);
            }

            body {
                font-family: "Source Sans 3", ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            }

            .mf-error {
                position: relative;
                display: flex;
                min-height: 100vh;
                align-items: center;
                justify-content: center;
                overflow: hidden;
                padding: 2rem 1.25rem 8rem;
            }

            .mf-error__horizon {
                position: absolute;
                right: 0;
                bottom: 0;
                left: 0;
                height: 11rem;
                pointer-events: none;
            }

            .mf-error__horizon svg {
                display: block;
                width: 100%;
                height: 100%;
            }

            .mf-error__violet { fill: var(--violet); }
            .mf-error__gold { fill: var(--gold); }
            .mf-error__stroke,
            .mf-error__stroke-soft,
            .mf-error__stroke-gold,
            .mf-error__stroke-gold-soft {
                fill: none;
                stroke: transparent;
            }

            html.dark .mf-error__violet,
            html.dark .mf-error__gold {
                fill: transparent;
            }

            @media (prefers-color-scheme: dark) {
                html:not(.light) .mf-error__violet,
                html:not(.light) .mf-error__gold {
                    fill: transparent;
                }

                html:not(.light) .mf-error__stroke { stroke: rgb(168 198 255 / 0.95); stroke-width: 2; }
                html:not(.light) .mf-error__stroke-soft { stroke: rgb(168 198 255 / 0.28); stroke-width: 8; }
                html:not(.light) .mf-error__stroke-gold { stroke: rgb(245 176 41 / 0.9); stroke-width: 1.75; }
                html:not(.light) .mf-error__stroke-gold-soft { stroke: rgb(245 176 41 / 0.22); stroke-width: 8; }
            }

            html.dark .mf-error__stroke { stroke: rgb(168 198 255 / 0.95); stroke-width: 2; }
            html.dark .mf-error__stroke-soft { stroke: rgb(168 198 255 / 0.28); stroke-width: 8; }
            html.dark .mf-error__stroke-gold { stroke: rgb(245 176 41 / 0.9); stroke-width: 1.75; }
            html.dark .mf-error__stroke-gold-soft { stroke: rgb(245 176 41 / 0.22); stroke-width: 8; }

            .mf-error__card {
                position: relative;
                width: min(100%, 28rem);
                border: 1px solid var(--line);
                border-radius: 1.15rem;
                background: color-mix(in srgb, var(--card) 94%, transparent);
                padding: 1.75rem 1.5rem 1.5rem;
                text-align: left;
                box-shadow: 0 18px 50px rgb(20 24 36 / 0.08);
            }

            html.dark .mf-error__card {
                box-shadow: none;
            }

            @media (prefers-color-scheme: dark) {
                html:not(.light) .mf-error__card { box-shadow: none; }
            }

            .mf-error__brand {
                display: flex;
                align-items: center;
                gap: 0.7rem;
                color: inherit;
                text-decoration: none;
                width: fit-content;
                border-radius: 0.6rem;
            }

            .mf-error__brand:focus-visible {
                outline: 2px solid var(--primary);
                outline-offset: 3px;
            }

            .mf-error__brand img {
                width: 2rem;
                height: 2rem;
            }

            .mf-error__name {
                font-family: Outfit, "Source Sans 3", ui-sans-serif, system-ui, sans-serif;
                font-size: 1.05rem;
                font-weight: 650;
                letter-spacing: -0.03em;
            }

            .mf-error__code {
                display: flex;
                align-items: center;
                gap: 0.75rem;
                margin: 1.4rem 0 0;
                font-family: Outfit, "Source Sans 3", ui-sans-serif, system-ui, sans-serif;
                font-size: 2.4rem;
                font-weight: 650;
                letter-spacing: -0.04em;
                line-height: 1;
            }

            .mf-error__rule {
                width: 0.15rem;
                height: 1.6rem;
                border-radius: 999px;
                background: #f5b029;
            }

            h1 {
                margin: 0.85rem 0 0;
                font-family: Outfit, "Source Sans 3", ui-sans-serif, system-ui, sans-serif;
                font-size: 1.35rem;
                font-weight: 600;
                letter-spacing: -0.03em;
                line-height: 1.25;
            }

            p {
                margin: 0.55rem 0 0;
                color: var(--muted);
                font-size: 1rem;
                line-height: 1.5;
            }

            .mf-error__home {
                display: inline-flex;
                margin-top: 1.35rem;
                align-items: center;
                border-radius: 0.8rem;
                background: var(--primary);
                color: #fff;
                padding: 0.65rem 1rem;
                font-size: 0.95rem;
                font-weight: 600;
                text-decoration: none;
            }

            .mf-error__home:hover { background: #4639d6; }

            .mf-error__home:focus-visible {
                outline: 2px solid var(--primary);
                outline-offset: 3px;
            }
        </style>
    </head>
    <body>
        <main class="mf-error">
            <div class="mf-error__horizon" aria-hidden="true">
                <svg viewBox="0 0 1440 280" preserveAspectRatio="none">
                    <path class="mf-error__violet" d="M0 148 C 170 108, 300 196, 480 152 C 690 100, 830 186, 1050 142 C 1230 108, 1340 154, 1440 118 L 1440 280 L 0 280 Z" />
                    <path class="mf-error__gold" d="M0 206 C 210 164, 360 236, 560 194 C 780 148, 920 226, 1140 184 C 1290 154, 1365 196, 1440 172 L 1440 280 L 0 280 Z" />
                    <path class="mf-error__stroke-soft" d="M0 118 C 220 72, 390 146, 610 98 C 850 46, 990 128, 1210 82 C 1325 56, 1385 92, 1440 68" />
                    <path class="mf-error__stroke" d="M0 118 C 220 72, 390 146, 610 98 C 850 46, 990 128, 1210 82 C 1325 56, 1385 92, 1440 68" />
                    <path class="mf-error__stroke-gold-soft" d="M0 188 C 230 150, 380 214, 590 176 C 820 134, 960 208, 1180 168 C 1310 142, 1375 176, 1440 158" />
                    <path class="mf-error__stroke-gold" d="M0 188 C 230 150, 380 214, 590 176 C 820 134, 960 208, 1180 168 C 1310 142, 1375 176, 1440 158" />
                </svg>
            </div>

            <section class="mf-error__card">
                <a class="mf-error__brand" href="{{ url('/') }}">
                    <img src="/brand/mark.png" alt="" width="32" height="32">
                    <span class="mf-error__name">{{ config('app.name', 'MetaForge') }}</span>
                </a>
                <p class="mf-error__code"><span class="mf-error__rule" aria-hidden="true"></span>{{ $code }}</p>
                <h1>{{ $hint }}</h1>
                @if ($detail)
                    <p>{{ $detail }}</p>
                @endif
                <a class="mf-error__home" href="{{ url('/') }}">{{ __('errors.home') }}</a>
            </section>
        </main>
    </body>
</html>
