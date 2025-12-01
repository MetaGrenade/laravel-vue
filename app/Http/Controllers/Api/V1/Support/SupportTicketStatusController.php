<?php

namespace App\Http\Controllers\Api\V1\Support;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupportTicketStatusController extends Controller
{
    public function update(Request $request, SupportTicket $ticket): JsonResponse
    {
        $user = $request->user();

        abort_unless($user && (int) $ticket->user_id === (int) $user->id, 403);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['closed'])],
        ]);

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

    public function reopen(Request $request, SupportTicket $ticket): JsonResponse
    {
        $user = $request->user();

        abort_unless($user && (int) $ticket->user_id === (int) $user->id, 403);

        if ($ticket->status !== 'closed') {
            return response()->json([
                'status' => $ticket->status,
                'message' => 'This ticket is already open.',
            ]);
        }

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
