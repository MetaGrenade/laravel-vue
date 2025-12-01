<?php

namespace App\Http\Controllers\Api\V1\Support;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePublicSupportTicketRequest;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\SupportTicketMessageAttachment;
use App\Notifications\TicketOpened;
use App\Support\Database\Transaction;
use App\Support\Localization\DateFormatter;
use App\Support\SupportTicketAutoAssigner;
use App\Support\SupportTicketNotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class SupportTicketController extends Controller
{
    public function __construct(
        private readonly SupportTicketNotificationDispatcher $ticketNotifier,
        private readonly SupportTicketAutoAssigner $ticketAssigner,
    ) {
    }

    public function show(Request $request, SupportTicket $ticket): JsonResponse
    {
        $user = $request->user();

        abort_unless($user && $ticket->user_id === $user->id, 403);

        $ticket->load([
            'assignee:id,nickname,email',
            'user:id,nickname,email',
            'category:id,name',
            'team:id,name',
            'messages.author:id,nickname,email',
            'messages.attachments',
        ]);

        return response()->json($this->transformTicket($ticket, $request));
    }

    public function store(StorePublicSupportTicketRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $ticket = null;
        $message = null;

        Transaction::run(function () use ($request, $validated, &$ticket, &$message): void {
            $ticket = SupportTicket::create([
                'user_id' => $validated['user_id'],
                'subject' => $validated['subject'],
                'body' => $validated['body'],
                'priority' => $validated['priority'] ?? 'medium',
                'support_ticket_category_id' => $validated['support_ticket_category_id'] ?? null,
            ]);

            $message = $ticket->messages()->create([
                'user_id' => $ticket->user_id,
                'body' => $ticket->body,
            ]);

            $message->setRelation('author', $ticket->user);

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

            $message->touch();

            $this->ticketAssigner->assign($ticket);
        });

        if ($ticket && $message) {
            $ticket->loadMissing(['assignee', 'team']);

            $this->ticketNotifier->dispatch($ticket, function (string $audience) use ($ticket, $message) {
                return (new TicketOpened($ticket, $message))
                    ->forAudience($audience);
            });
        }

        $ticket?->loadMissing(['messages.author', 'messages.attachments']);

        return response()->json($this->transformTicket($ticket, $request), 201);
    }

    private function transformTicket(SupportTicket $ticket, Request $request): array
    {
        $formatter = DateFormatter::for($request->user());

        return [
            'id' => $ticket->id,
            'subject' => $ticket->subject,
            'body' => $ticket->body,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
            'support_ticket_category_id' => $ticket->support_ticket_category_id,
            'support_team_id' => $ticket->support_team_id,
            'created_at' => $formatter->iso($ticket->created_at),
            'updated_at' => $formatter->iso($ticket->updated_at),
            'customer_satisfaction_rating' => $ticket->customer_satisfaction_rating,
            'assignee' => $ticket->assignee ? [
                'id' => $ticket->assignee->id,
                'nickname' => $ticket->assignee->nickname,
                'email' => $ticket->assignee->email,
            ] : null,
            'team' => $ticket->team ? [
                'id' => $ticket->team->id,
                'name' => $ticket->team->name,
            ] : null,
            'category' => $ticket->category ? [
                'id' => $ticket->category->id,
                'name' => $ticket->category->name,
            ] : null,
            'messages' => $ticket->messages
                ->sortBy('created_at')
                ->values()
                ->map(fn (SupportTicketMessage $message) => $this->transformMessage($message, $ticket, $formatter))
                ->all(),
        ];
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
