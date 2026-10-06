@php
    $seo = app(\App\Support\Seo\Seo::class);
    $nonce = \Illuminate\Support\Facades\Vite::cspNonce();
    $appearance = in_array($appearance ?? 'system', ['light', 'dark', 'system'], true) ? ($appearance ?? 'system') : 'system';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => $appearance === 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="color-scheme" content="light dark">
        <meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="#0a0a0a" media="(prefers-color-scheme: dark)">

        {{-- Apply the system dark mode preference before first paint to avoid a flash --}}
        <script @if ($nonce) nonce="{{ $nonce }}" @endif>
            (function () {
                if (@js($appearance) === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    document.documentElement.classList.add('dark');
                }
            })();
        </script>

        {{-- Match the page background to the theme before the stylesheet loads --}}
        <style @if ($nonce) nonce="{{ $nonce }}" @endif>
            html { background-color: #ffffff; }
            html.dark { background-color: #0a0a0a; }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="manifest" href="/site.webmanifest">

        @if (\App\Support\WebsiteSections::isEnabled('blog'))
            <link rel="alternate" type="application/rss+xml" title="{{ config('seo.site_name') }} Blog" href="{{ route('blogs.feed') }}">
        @endif

        @fonts

        @routes(\App\Support\Routing\ZiggyRouteGroup::for(auth()->user()), $nonce)
        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])

        <x-inertia::head>
            {{-- Server-rendered metadata for crawlers when SSR is not running.
                 The same elements are managed client-side via the `seoHead` prop. --}}
            <title data-inertia="">{{ $seo->documentTitle() }}</title>
            @foreach ($page['props']['seoHead'] ?? [] as $element)
                @unless (str_starts_with($element, '<title'))
                    {!! $element !!}
                @endunless
            @endforeach
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
