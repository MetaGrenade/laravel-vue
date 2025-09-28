<?php

namespace App\Http\Controllers\Admin;

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

class SupportController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 25);

        $ticketQuery = SupportTicket::query();
        $faqQuery    = Faq::query()->orderBy('order', 'asc');

        $tickets = (clone $ticketQuery)
            ->with(['user', 'assignee'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage)
            ->withQueryString();

        $faqs = (clone $faqQuery)
            ->paginate($perPage)
            ->withQueryString();

        $stats = [
            'total'  => $tickets->total(),
            'open'   => (clone $ticketQuery)->where('status', 'open')->count(),
            'closed' => (clone $ticketQuery)->where('status', 'closed')->count(),
            'faqs'   => $faqs->total(),
        ];

        return Inertia::render('acp/Support', [
            'tickets'      => $tickets,
            'faqs'         => $faqs,
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
