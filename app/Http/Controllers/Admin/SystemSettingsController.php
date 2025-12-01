<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Support\EmailVerification;
use App\Support\OAuth\OAuthProviders;
use App\Support\WebsiteSections;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SystemSettingsController extends Controller
{
    /**
     * Display the system settings management screen.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('acp/System', [
            'settings' => [
                'maintenance_mode' => (bool) SystemSetting::get('maintenance_mode', false),
                'email_verification_required' => EmailVerification::isRequired(),
                'website_sections' => WebsiteSections::all(),
                'oauth_providers' => OAuthProviders::all(),
            ],
            'oauthProviders' => OAuthProviders::options(),
            'diagnostics' => $this->diagnosticsPayload(),
        ]);
    }

    /**
     * Persist the incoming system setting updates.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'maintenance_mode' => ['required', 'boolean'],
            'email_verification_required' => ['required', 'boolean'],
            'website_sections' => ['required', 'array'],
            ...collect(WebsiteSections::keys())
                ->mapWithKeys(fn (string $section) => ["website_sections.{$section}" => ['required', 'boolean']])
                ->all(),
            'oauth_providers' => ['required', 'array'],
            ...collect(OAuthProviders::keys())
                ->mapWithKeys(fn (string $provider) => ["oauth_providers.{$provider}" => ['required', 'boolean']])
                ->all(),
        ]);

        SystemSetting::set('maintenance_mode', (bool) $validated['maintenance_mode']);
        SystemSetting::set('email_verification_required', (bool) $validated['email_verification_required']);
        SystemSetting::set('website_sections', WebsiteSections::normalize($validated['website_sections']));
        SystemSetting::set('oauth_providers', OAuthProviders::normalize($validated['oauth_providers']));

        return back()->with('success', 'System settings were updated successfully.');
    }

    /**
     * Build a diagnostics payload with real server information.
     */
    protected function diagnosticsPayload(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'server_environment' => config('app.env'),
            'server_time' => now()->toDateTimeString(),
            'server_timezone' => config('app.timezone'),
            'app_url' => config('app.url'),
            'queue_connection' => config('queue.default'),
            'cache_driver' => config('cache.default'),
            'session_driver' => config('session.driver'),
            'memory_usage' => $this->formatBytes(memory_get_usage(true)),
            'memory_peak' => $this->formatBytes(memory_get_peak_usage(true)),
        ];
    }

    /**
     * Present a human readable representation of a memory size.
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $precision = 2;

        $bytes = max($bytes, 0);
        $pow = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
