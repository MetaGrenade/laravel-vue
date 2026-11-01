<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class WebManifestController extends Controller
{
    /**
     * The PWA web manifest, named after the configured site.
     */
    public function __invoke(): JsonResponse
    {
        $name = (string) config('seo.site_name');

        return response()->json([
            'name' => $name,
            'short_name' => mb_substr($name, 0, 12),
            'icons' => [
                ['src' => '/android-chrome-192x192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/android-chrome-512x512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/brand/mark.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ],
            'start_url' => '/',
            'display' => 'standalone',
            'background_color' => '#f4f6fb',
            'theme_color' => '#5346e8',
        ], 200, ['Content-Type' => 'application/manifest+json']);
    }
}
