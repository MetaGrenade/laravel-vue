<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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

    /**
     * Show the form for creating a new blog post.
     */
    public function createTicket()
    {
        return inertia('acp/SupportTicketCreate');
    }

    public function storeTicket(Request $request)
    {
        $data = $request->validate([
            'subject'  => 'required|string|max:255',
            'body'     => 'required|string',
            'priority' => 'in:low,medium,high',
        ]);

        SupportTicket::create(array_merge($data, [
            'user_id' => $request->user()->id,
        ]));

        return back()->with('success','Ticket created.');
    }

    public function updateTicket(Request $request, SupportTicket $ticket)
    {
        $data = $request->validate([
            'subject'    => 'sometimes|required|string|max:255',
            'body'       => 'sometimes|required|string',
            'status'     => 'in:open,pending,closed',
            'priority'   => 'in:low,medium,high',
            'assigned_to'=> 'nullable|exists:users,id',
        ]);

        $ticket->update($data);

        return back()->with('success','Ticket updated.');
    }

    public function destroyTicket(SupportTicket $ticket)
    {
        $ticket->delete();
        return back()->with('success','Ticket deleted.');
    }

    // FAQ CRU(D)
    /**
     * Show the form for creating a new blog post.
     */
    public function createFaq()
    {
        return inertia('acp/SupportFaqCreate');
    }

    public function storeFaq(Request $request)
    {
        $data = $request->validate([
            'question'  => 'required|string',
            'answer'    => 'required|string',
            'order'     => 'integer',
            'published' => 'boolean',
        ]);

        Faq::create($data);

        return back()->with('success','FAQ created.');
    }

    public function updateFaq(Request $request, Faq $faq)
    {
        $data = $request->validate([
            'question'  => 'sometimes|required|string',
            'answer'    => 'sometimes|required|string',
            'order'     => 'integer',
            'published' => 'boolean',
        ]);

        $faq->update($data);

        return back()->with('success','FAQ updated.');
    }

    public function destroyFaq(Faq $faq)
    {
        $faq->delete();
        return back()->with('success','FAQ deleted.');
    }
}
