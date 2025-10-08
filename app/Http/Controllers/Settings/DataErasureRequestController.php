<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\DataErasureRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DataErasureRequestController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        $hasActiveRequest = $user->dataErasureRequests()
            ->whereIn('status', [DataErasureRequest::STATUS_PENDING, DataErasureRequest::STATUS_PROCESSING])
            ->exists();

        if ($hasActiveRequest) {
            return to_route('privacy.index')
                ->withErrors([
                    'erasure' => 'You already have a pending erasure request. We will be in touch soon.',
                ])
                ->with('status', 'erasure-pending');
        }

        $user->dataErasureRequests()->create([
            'status' => DataErasureRequest::STATUS_PENDING,
        ]);

        return to_route('privacy.index')->with('status', 'erasure-requested');
    }
}
