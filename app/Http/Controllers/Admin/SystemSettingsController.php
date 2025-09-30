<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Support\EmailVerification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SystemSettingsController extends Controller
{
    /**
     * Display the system settings management screen.
     */
    public function index(): Response
    {
        return Inertia::render('acp/System', [
            'settings' => [
                'maintenance_mode' => (bool) SystemSetting::get('maintenance_mode', false),
                'email_verification_required' => EmailVerification::isRequired(),
            ],
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
        ]);

        foreach ($validated as $key => $value) {
            SystemSetting::set($key, (bool) $value);
        }

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
