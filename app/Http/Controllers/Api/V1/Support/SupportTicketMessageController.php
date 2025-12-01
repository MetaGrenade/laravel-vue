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
use App\Support\SupportTicketNotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class SupportTicketMessageController extends Controller
{
    public function __construct(private readonly SupportTicketNotificationDispatcher $ticketNotifier)
    {
    }

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

            $attachments = $request->file('attachments', []);

            if ($attachments instanceof UploadedFile) {
                $attachments = [$attachments];
            } elseif (! is_array($attachments)) {
                $attachments = [];
            }

            $disk = 'public';

            foreach ($attachments as $file) {
                if (! $file) {
                    continue;
                }

                $path = $file->store("support-attachments/{$ticket->id}", $disk);

                $message->attachments()->create([
                    'disk' => $disk,
                    'path' => $path,
                    'name' => $file->getClientOriginalName() ?: $file->hashName(),
                    'mime_type' => $file->getClientMimeType(),
                    'size' => $file->getSize() ?: 0,
                ]);
            }

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
                    'download_url' => Storage::disk($attachment->disk)->url($attachment->path),
                ])
                ->values()
                ->all(),
        ];
    }
}
