<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\DataExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

class PrivacyController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        $exports = $user->dataExports()
            ->latest()
            ->limit(10)
            ->get()
            ->map(function (DataExport $export) {
                return [
                    'id' => $export->id,
                    'status' => $export->status,
                    'format' => $export->format,
                    'failure_reason' => $export->failure_reason,
                    'created_at' => $export->created_at?->toIso8601String(),
                    'completed_at' => $export->completed_at?->toIso8601String(),
                    'download_expires_at' => $export->downloadExpiresAt()?->toIso8601String(),
                    'download_url' => $export->isReady()
                        ? URL::temporarySignedRoute('privacy.exports.download', now()->addMinutes(30), ['export' => $export->id])
                        : null,
                ];
            })
            ->values()
            ->all();

        $latestErasureRequest = $user->dataErasureRequests()->latest()->first();

        return Inertia::render('settings/Privacy', [
            'exports' => $exports,
            'erasureRequest' => $latestErasureRequest ? [
                'id' => $latestErasureRequest->id,
                'status' => $latestErasureRequest->status,
                'processed_at' => $latestErasureRequest->processed_at?->toIso8601String(),
                'created_at' => $latestErasureRequest->created_at?->toIso8601String(),
            ] : null,
            'status' => $request->session()->get('status'),
        ]);
    }
}
