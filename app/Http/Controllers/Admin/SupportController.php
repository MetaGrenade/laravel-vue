<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreFaqRequest;
use App\Http\Requests\Admin\StoreSupportTicketRequest;
use App\Http\Requests\Admin\UpdateFaqRequest;
use App\Http\Requests\Admin\UpdateSupportTicketRequest;
use App\Models\SupportTicket;
use App\Models\Faq;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SupportController extends Controller
{
    use InteractsWithInertiaPagination;

    public function index(Request $request): Response
    {
        $perPage = (int) $request->get('per_page', 25);

        $ticketQuery = SupportTicket::query();
        $faqQuery = Faq::query();

        $tickets = (clone $ticketQuery)
            ->with(['user:id,nickname,email', 'assignee:id,nickname,email'])
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'tickets_page')
            ->withQueryString();

        $faqs = (clone $faqQuery)
            ->orderBy('order')
            ->paginate($perPage, ['*'], 'faqs_page')
            ->withQueryString();

        $ticketItems = $tickets->getCollection()
            ->map(function (SupportTicket $ticket) {
                return [
                    'id' => $ticket->id,
                    'subject' => $ticket->subject,
                    'body' => $ticket->body,
                    'status' => $ticket->status,
                    'priority' => $ticket->priority,
                    'created_at' => optional($ticket->created_at)->toIso8601String(),
                    'updated_at' => optional($ticket->updated_at)->toIso8601String(),
                    'user' => $ticket->user ? [
                        'id' => $ticket->user->id,
                        'nickname' => $ticket->user->nickname,
                        'email' => $ticket->user->email,
                    ] : null,
                    'assignee' => $ticket->assignee ? [
                        'id' => $ticket->assignee->id,
                        'nickname' => $ticket->assignee->nickname,
                        'email' => $ticket->assignee->email,
                    ] : null,
                ];
            })
            ->values()
            ->all();

        $faqItems = $faqs->getCollection()
            ->map(function (Faq $faq) {
                return [
                    'id' => $faq->id,
                    'question' => $faq->question,
                    'answer' => $faq->answer,
                    'order' => $faq->order,
                    'published' => (bool) $faq->published,
                ];
            })
            ->values()
            ->all();

        $stats = [
            'total' => $tickets->total(),
            'open' => (clone $ticketQuery)->where('status', 'open')->count(),
            'closed' => (clone $ticketQuery)->where('status', 'closed')->count(),
            'faqs' => $faqs->total(),
        ];

        return Inertia::render('acp/Support', [
            'tickets' => array_merge([
                'data' => $ticketItems,
            ], $this->inertiaPagination($tickets)),
            'faqs' => array_merge([
                'data' => $faqItems,
            ], $this->inertiaPagination($faqs)),
            'supportStats' => $stats,
        ]);
    }

    // Tickets
    /**
     * Show the form for creating a new support ticket.
     */
    public function createTicket()
    {
        return inertia('acp/SupportTicketCreate');
    }

    public function storeTicket(StoreSupportTicketRequest $request)
    {
        SupportTicket::create($request->validated());

        return redirect()
            ->route('acp.support.index')
            ->with('success', 'Ticket created.');
    }

    public function editTicket(SupportTicket $ticket)
    {
        $ticket->load([
            'user:id,nickname,email',
            'assignee:id,nickname,email',
        ]);

        $agents = User::orderBy('nickname')
            ->get(['id', 'nickname', 'email']);

        return inertia('acp/SupportTicketEdit', [
            'ticket' => $ticket,
            'agents' => $agents,
        ]);
    }

    public function updateTicket(UpdateSupportTicketRequest $request, SupportTicket $ticket)
    {
        $ticket->update($request->validated());

        return redirect()
            ->route('acp.support.index')
            ->with('success', 'Ticket updated.');
    }

    public function destroyTicket(SupportTicket $ticket)
    {
        $ticket->delete();
        return back()->with('success','Ticket deleted.');
    }

    // FAQ
    /**
     * Show the form for creating a new FAQ.
     */
    public function createFaq()
    {
        return inertia('acp/SupportFaqCreate');
    }

    public function storeFaq(StoreFaqRequest $request)
    {
        Faq::create($request->validated());

        return redirect()
            ->route('acp.support.index')
            ->with('success', 'FAQ created.');
    }

    public function editFaq(Faq $faq)
    {
        return inertia('acp/SupportFaqEdit', [
            'faq' => $faq,
        ]);
    }

    public function updateFaq(UpdateFaqRequest $request, Faq $faq)
    {
        $faq->update($request->validated());

        return redirect()
            ->route('acp.support.index')
            ->with('success', 'FAQ updated.');
    }

    public function destroyFaq(Faq $faq)
    {
        $faq->delete();
        return back()->with('success','FAQ deleted.');
    }
}
