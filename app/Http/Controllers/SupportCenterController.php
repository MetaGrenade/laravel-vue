<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Http\Requests\StorePublicSupportTicketMessageRequest;
use App\Http\Requests\StorePublicSupportTicketRequest;
use App\Models\Faq;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class SupportCenterController extends Controller
{
    use InteractsWithInertiaPagination;

    public function index(Request $request): Response
    {
        $user = $request->user();

        $ticketsPerPage = max(1, (int) $request->query('tickets_per_page', 10));
        $faqsPerPage = max(1, (int) $request->query('faqs_per_page', 10));

        $ticketsPayload = [
            'data' => [],
            'meta' => null,
            'links' => null,
        ];

        if ($user) {
            $tickets = SupportTicket::query()
                ->where('user_id', $user->id)
                ->with(['assignee:id,nickname,email'])
                ->orderByDesc('created_at')
                ->paginate($ticketsPerPage, ['*'], 'tickets_page')
                ->withQueryString();

            $ticketsPayload = array_merge([
                'data' => $tickets->getCollection()
                    ->map(function (SupportTicket $ticket) {
                        return [
                            'id' => $ticket->id,
                            'subject' => $ticket->subject,
                            'status' => $ticket->status,
                            'priority' => $ticket->priority,
                            'created_at' => optional($ticket->created_at)->toIso8601String(),
                            'updated_at' => optional($ticket->updated_at)->toIso8601String(),
                            'assignee' => $ticket->assignee ? [
                                'id' => $ticket->assignee->id,
                                'nickname' => $ticket->assignee->nickname,
                                'email' => $ticket->assignee->email,
                            ] : null,
                        ];
                    })
                    ->values()
                    ->all(),
            ], $this->inertiaPagination($tickets));
        }

        $faqs = Faq::query()
            ->where('published', true)
            ->orderBy('order')
            ->paginate($faqsPerPage, ['*'], 'faqs_page')
            ->withQueryString();

        $faqItems = $faqs->getCollection()
            ->map(function (Faq $faq) {
                return [
                    'id' => $faq->id,
                    'question' => $faq->question,
                    'answer' => $faq->answer,
                ];
            })
            ->values()
            ->all();

        return Inertia::render('Support', [
            'tickets' => $ticketsPayload,
            'faqs' => array_merge([
                'data' => $faqItems,
            ], $this->inertiaPagination($faqs)),
            'canSubmitTicket' => (bool) $user,
        ]);
    }

    public function store(StorePublicSupportTicketRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $ticket = SupportTicket::create([
            'user_id' => $validated['user_id'],
            'subject' => $validated['subject'],
            'body' => $validated['body'],
            'priority' => $validated['priority'] ?? 'medium',
        ]);

        $ticket->messages()->create([
            'user_id' => $ticket->user_id,
            'body' => $ticket->body,
        ]);

        return redirect()
            ->route('support')
            ->with('success', 'Support ticket submitted successfully.');
    }

    public function show(Request $request, SupportTicket $ticket): Response
    {
        $user = $request->user();

        abort_unless($user && $ticket->user_id === $user->id, 403);

        $ticket->load([
            'assignee:id,nickname,email',
            'user:id,nickname,email',
            'messages.author:id,nickname,email',
        ]);

        $messages = $ticket->messages
            ->map(function (SupportTicketMessage $message) use ($ticket) {
                return [
                    'id' => $message->id,
                    'body' => $message->body,
                    'created_at' => optional($message->created_at)->toIso8601String(),
                    'author' => $message->author ? [
                        'id' => $message->author->id,
                        'nickname' => $message->author->nickname,
                        'email' => $message->author->email,
                    ] : null,
                    'is_from_support' => $message->author
                        ? $message->author->id !== $ticket->user_id
                        : false,
                ];
            })
            ->values()
            ->all();

        if (count($messages) === 0) {
            $messages[] = [
                'id' => -$ticket->id,
                'body' => $ticket->body,
                'created_at' => optional($ticket->created_at)->toIso8601String(),
                'author' => $ticket->user ? [
                    'id' => $ticket->user->id,
                    'nickname' => $ticket->user->nickname,
                    'email' => $ticket->user->email,
                ] : null,
                'is_from_support' => false,
            ];
        }

        return Inertia::render('SupportTicketView', [
            'ticket' => [
                'id' => $ticket->id,
                'subject' => $ticket->subject,
                'body' => $ticket->body,
                'status' => $ticket->status,
                'priority' => $ticket->priority,
                'created_at' => optional($ticket->created_at)->toIso8601String(),
                'updated_at' => optional($ticket->updated_at)->toIso8601String(),
                'assignee' => $ticket->assignee ? [
                    'id' => $ticket->assignee->id,
                    'nickname' => $ticket->assignee->nickname,
                    'email' => $ticket->assignee->email,
                ] : null,
                'user' => $ticket->user ? [
                    'id' => $ticket->user->id,
                    'nickname' => $ticket->user->nickname,
                    'email' => $ticket->user->email,
                ] : null,
            ],
            'messages' => $messages,
            'canReply' => $ticket->status !== 'closed',
        ]);
    }

    public function storeMessage(
        StorePublicSupportTicketMessageRequest $request,
        SupportTicket $ticket
    ): RedirectResponse {
        $validated = $request->validated();

        $message = $ticket->messages()->create([
            'user_id' => $request->user()->id,
            'body' => $validated['body'],
        ]);

        $ticket->touch();
        $message->touch();

        return redirect()
            ->route('support.tickets.show', $ticket)
            ->with('success', 'Your message has been sent.');
    }

    public function updateStatus(Request $request, SupportTicket $ticket): HttpResponse
    {
        $user = $request->user();

        abort_unless($user && $ticket->user_id === $user->id, 403);

        $validated = $request->validate([
            'status' => ['required', 'in:closed'],
        ]);

        if ($ticket->status !== $validated['status']) {
            $ticket->update([
                'status' => $validated['status'],
            ]);
        }

        return response()->noContent();
    }
}
