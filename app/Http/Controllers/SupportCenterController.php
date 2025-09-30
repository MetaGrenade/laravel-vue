<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Http\Requests\StorePublicSupportTicketRequest;
use App\Models\Faq;
use App\Models\SupportTicket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        SupportTicket::create([
            'user_id' => $validated['user_id'],
            'subject' => $validated['subject'],
            'body' => $validated['body'],
            'priority' => $validated['priority'] ?? 'medium',
        ]);

        return redirect()
            ->route('support')
            ->with('success', 'Support ticket submitted successfully.');
    }
}
