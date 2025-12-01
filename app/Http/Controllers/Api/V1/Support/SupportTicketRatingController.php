<?php

namespace App\Http\Controllers\Api\V1\Support;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePublicSupportTicketRatingRequest;
use App\Models\SupportTicket;
use Illuminate\Http\JsonResponse;

class SupportTicketRatingController extends Controller
{
    public function store(StorePublicSupportTicketRatingRequest $request, SupportTicket $ticket): JsonResponse
    {
        $validated = $request->validated();

        $ticket->update([
            'customer_satisfaction_rating' => (int) $validated['rating'],
        ]);

        return response()->json([
            'status' => $ticket->status,
            'customer_satisfaction_rating' => $ticket->customer_satisfaction_rating,
            'message' => 'Thanks for sharing your feedback.',
        ]);
    }
}
