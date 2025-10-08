<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateUserDataExport;
use App\Models\DataExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DataExportController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        $hasPending = $user->dataExports()
            ->whereIn('status', [DataExport::STATUS_PENDING, DataExport::STATUS_PROCESSING])
            ->exists();

        if ($hasPending) {
            return to_route('privacy.index')
                ->withErrors([
                    'export' => 'An export request is already in progress. Please wait for it to finish before requesting another.',
                ])
                ->with('status', 'export-pending');
        }

        $export = $user->dataExports()->create([
            'status' => DataExport::STATUS_PENDING,
        ]);

        GenerateUserDataExport::dispatch($export->id);

        return to_route('privacy.index')->with('status', 'export-requested');
    }

    public function download(Request $request, DataExport $export): StreamedResponse
    {
        if ($request->user()->id !== $export->user_id) {
            abort(403);
        }

        if ($export->status !== DataExport::STATUS_COMPLETED || ! $export->file_path) {
            abort(404);
        }

        if (! Storage::disk('local')->exists($export->file_path)) {
            abort(404);
        }

        return Storage::disk('local')->download(
            $export->file_path,
            sprintf('user-%d-data-export.zip', $export->user_id)
        );
    }
}
