<?php

namespace App\Http\Controllers\Api\V1\Support;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReopenPublicSupportTicketRequest;
use App\Http\Requests\UpdatePublicSupportTicketStatusRequest;
use App\Models\SupportTicket;
use Illuminate\Http\JsonResponse;

class SupportTicketStatusController extends Controller
{
    public function update(UpdatePublicSupportTicketStatusRequest $request, SupportTicket $ticket): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        if ($ticket->status === $validated['status']) {
            return response()->json([
                'status' => $ticket->status,
                'message' => 'This ticket is already closed.',
            ]);
        }

        $updates = [
            'status' => $validated['status'],
        ];

        if (! $ticket->resolved_at) {
            $updates['resolved_at'] = now();
        }

        if (! $ticket->resolved_by) {
            $updates['resolved_by'] = $user->id;
        }

        $ticket->update($updates);

        return response()->json([
            'status' => $ticket->status,
            'message' => 'Ticket closed.',
        ]);
    }

    public function reopen(ReopenPublicSupportTicketRequest $request, SupportTicket $ticket): JsonResponse
    {
        $ticket->update([
            'status' => 'open',
            'resolved_at' => null,
            'resolved_by' => null,
            'customer_satisfaction_rating' => null,
        ]);

        return response()->json([
            'status' => $ticket->status,
            'message' => 'Ticket reopened. We will take another look.',
        ]);
    }
}
