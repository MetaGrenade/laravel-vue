<?php

namespace App\Http\Controllers\Api\V1\Support;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePublicSupportTicketMessageRequest;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\SupportTicketMessageAttachment;
use App\Models\User;
use App\Notifications\TicketReplied;
use App\Support\Database\Transaction;
use App\Support\Localization\DateFormatter;
use App\Support\SupportAttachmentStorage;
use App\Support\SupportTicketNotificationDispatcher;
use Illuminate\Http\JsonResponse;

class SupportTicketMessageController extends Controller
{
    public function __construct(private readonly SupportTicketNotificationDispatcher $ticketNotifier) {}

    public function store(StorePublicSupportTicketMessageRequest $request, SupportTicket $ticket): JsonResponse
    {
        $user = $request->user();

        abort_unless($user && $ticket->user_id === $user->id, 403);

        $validated = $request->validated();

        $message = null;

        Transaction::run(function () use ($request, $ticket, $validated, &$message): void {
            $message = $ticket->messages()->create([
                'user_id' => $request->user()->id,
                'body' => $validated['body'],
            ]);

            $message->setRelation('author', $request->user());

            $attachments = $request->file('attachments');

            app(SupportAttachmentStorage::class)->attach($message, $ticket, $attachments);

            $ticket->touch();
            $message->touch();
        });

        if ($message) {
            $this->ticketNotifier->dispatch(
                $ticket,
                function (string $audience) use ($ticket, $message) {
                    return (new TicketReplied($ticket, $message))
                        ->forAudience($audience);
                },
                function (string $audience, User $recipient) {
                    return ['database', 'mail', 'push'];
                }
            );
        }

        $formatter = DateFormatter::for($request->user());

        return response()->json([
            'message' => $this->transformMessage($message, $ticket, $formatter),
        ], 201);
    }

    private function transformMessage(
        SupportTicketMessage $message,
        SupportTicket $ticket,
        DateFormatter $formatter
    ): array {
        return [
            'id' => $message->id,
            'body' => $message->body,
            'created_at' => $formatter->iso($message->created_at),
            'author' => $message->author ? [
                'id' => $message->author->id,
                'nickname' => $message->author->nickname,
                'email' => $message->author->email,
            ] : null,
            'is_from_support' => $message->author
                ? $message->author->id !== $ticket->user_id
                : false,
            'attachments' => $message->attachments
                ->map(fn (SupportTicketMessageAttachment $attachment) => [
                    'id' => $attachment->id,
                    'name' => $attachment->name,
                    'size' => $attachment->size,
                    'download_url' => app(SupportAttachmentStorage::class)->downloadUrl($attachment, true),
                ])
                ->values()
                ->all(),
        ];
    }
}
