<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreFaqRequest;
use App\Http\Requests\Admin\StoreSupportTicketRequest;
use App\Http\Requests\Admin\UpdateFaqRequest;
use App\Http\Requests\Admin\UpdateSupportTicketRequest;
use App\Models\SupportTicket;
use App\Models\Faq;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SupportController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 25);

        $tickets = SupportTicket::with(['user','assignee'])
            ->orderBy('created_at','desc')
            ->paginate($perPage)
            ->withQueryString();

        $faqs = Faq::orderBy('order','asc')
            ->paginate($perPage)
            ->withQueryString();

        $stats = [
            'total' => SupportTicket::count(),
            'open'  => SupportTicket::where('status','open')->count(),
            'closed'=> SupportTicket::where('status','closed')->count(),
            'faqs'  => $faqs->count(),
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
        return back()->with('success','Ticket created.');
    }

    public function updateTicket(UpdateSupportTicketRequest $request, SupportTicket $ticket)
    {
        $ticket->update($request->validated());
        return back()->with('success','Ticket updated.');
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
        return back()->with('success','FAQ created.');
    }

    public function updateFaq(UpdateFaqRequest $request, Faq $faq)
    {
        $faq->update($request->validated());
        return back()->with('success','FAQ updated.');
    }

    public function destroyFaq(Faq $faq)
    {
        $faq->delete();
        return back()->with('success','FAQ deleted.');
    }
}
