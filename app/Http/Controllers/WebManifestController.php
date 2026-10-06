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
                ['src' => '/favicon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'],
            ],
            'start_url' => '/',
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => '#09090b',
        ], 200, ['Content-Type' => 'application/manifest+json']);
    }
}
