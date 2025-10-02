<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreFaqRequest;
use App\Http\Requests\Admin\StoreSupportTicketMessageRequest;
use App\Http\Requests\Admin\StoreSupportTicketRequest;
use App\Http\Requests\Admin\UpdateFaqRequest;
use App\Http\Requests\Admin\UpdateSupportTicketRequest;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\SupportTicketMessageAttachment;
use App\Models\Faq;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
            ->with(['user:id,nickname,email', 'assignee:id,nickname,email', 'resolver:id,nickname,email'])
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
                    'resolved_at' => optional($ticket->resolved_at)->toIso8601String(),
                    'resolved_by' => $ticket->resolved_by,
                    'customer_satisfaction_rating' => $ticket->customer_satisfaction_rating,
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
                    'resolver' => $ticket->resolver ? [
                        'id' => $ticket->resolver->id,
                        'nickname' => $ticket->resolver->nickname,
                        'email' => $ticket->resolver->email,
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

        $assignableAgents = User::orderBy('nickname')
            ->get(['id', 'nickname', 'email'])
            ->map(fn (User $agent) => [
                'id' => $agent->id,
                'nickname' => $agent->nickname,
                'email' => $agent->email,
            ])
            ->all();

        return Inertia::render('acp/Support', [
            'tickets' => array_merge([
                'data' => $ticketItems,
            ], $this->inertiaPagination($tickets)),
            'faqs' => array_merge([
                'data' => $faqItems,
            ], $this->inertiaPagination($faqs)),
            'supportStats' => $stats,
            'assignableAgents' => $assignableAgents,
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
            'resolver:id,nickname,email',
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
        $validated = $request->validated();

        if (array_key_exists('status', $validated)) {
            $validated += $this->resolutionAttributes($ticket, $validated['status'], (int) $request->user()->id);
        }

        $ticket->update($validated);

        return redirect()
            ->route('acp.support.index')
            ->with('success', 'Ticket updated.');
    }

    public function destroyTicket(SupportTicket $ticket)
    {
        $ticket->delete();
        return back()->with('success','Ticket deleted.');
    }

    public function assignTicket(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $validated = $request->validate([
            'assigned_to' => ['nullable', 'exists:users,id'],
        ]);

        $ticket->update([
            'assigned_to' => $validated['assigned_to'] ?? null,
        ]);

        $message = $ticket->assigned_to
            ? 'Ticket assigned to agent.'
            : 'Ticket unassigned.';

        return back()->with('success', $message);
    }

    public function updateTicketPriority(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $validated = $request->validate([
            'priority' => ['required', Rule::in(['low', 'medium', 'high'])],
        ]);

        $ticket->update([
            'priority' => $validated['priority'],
        ]);

        return back()->with('success', 'Ticket priority updated.');
    }

    public function showTicket(Request $request, SupportTicket $ticket): Response
    {
        abort_unless($request->user()?->can('support.acp.view'), 403);

        $ticket->load([
            'assignee:id,nickname,email',
            'resolver:id,nickname,email',
            'user:id,nickname,email',
            'messages.author:id,nickname,email',
            'messages.attachments',
        ]);

        $messages = $ticket->messages
            ->map(fn (SupportTicketMessage $message) => $this->formatTicketMessage($ticket, $message))
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
                'attachments' => [],
            ];
        }

        $canReply = $request->user()?->can('support.acp.reply') && $ticket->status !== 'closed';

        return Inertia::render('acp/SupportTicketView', [
            'ticket' => [
                'id' => $ticket->id,
                'subject' => $ticket->subject,
                'body' => $ticket->body,
                'status' => $ticket->status,
                'priority' => $ticket->priority,
                'created_at' => optional($ticket->created_at)->toIso8601String(),
                'updated_at' => optional($ticket->updated_at)->toIso8601String(),
                'resolved_at' => optional($ticket->resolved_at)->toIso8601String(),
                'resolved_by' => $ticket->resolved_by,
                'assignee' => $ticket->assignee ? [
                    'id' => $ticket->assignee->id,
                    'nickname' => $ticket->assignee->nickname,
                    'email' => $ticket->assignee->email,
                ] : null,
                'resolver' => $ticket->resolver ? [
                    'id' => $ticket->resolver->id,
                    'nickname' => $ticket->resolver->nickname,
                    'email' => $ticket->resolver->email,
                ] : null,
                'user' => $ticket->user ? [
                    'id' => $ticket->user->id,
                    'nickname' => $ticket->user->nickname,
                    'email' => $ticket->user->email,
                ] : null,
            ],
            'messages' => $messages,
            'canReply' => (bool) $canReply,
        ]);
    }

    public function storeTicketMessage(
        StoreSupportTicketMessageRequest $request,
        SupportTicket $ticket
    ): RedirectResponse {
        abort_unless($request->user()?->can('support.acp.reply'), 403);

        $validated = $request->validated();

        DB::transaction(function () use ($request, $ticket, $validated): void {
            $message = $ticket->messages()->create([
                'user_id' => $request->user()->id,
                'body' => $validated['body'],
            ]);

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

        return redirect()
            ->route('acp.support.tickets.show', $ticket)
            ->with('success', 'Reply sent.');
    }

    private function formatTicketMessage(SupportTicket $ticket, SupportTicketMessage $message): array
    {
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
                : true,
            'attachments' => $message->attachments
                ->map(function (SupportTicketMessageAttachment $attachment) {
                    return [
                        'id' => $attachment->id,
                        'name' => $attachment->name,
                        'size' => $attachment->size,
                        'download_url' => Storage::disk($attachment->disk)->url($attachment->path),
                    ];
                })
                ->values()
                ->all(),
        ];
    }

    public function updateTicketStatus(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['open', 'pending', 'closed'])],
        ]);

        $updates = [
            'status' => $validated['status'],
        ];

        $updates += $this->resolutionAttributes($ticket, $validated['status'], (int) $request->user()->id);

        $ticket->update($updates);

        $message = match ($validated['status']) {
            'open' => 'Ticket opened.',
            'closed' => 'Ticket closed.',
            default => 'Ticket status updated.',
        };

        return back()->with('success', $message);
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

    public function reorderFaq(Request $request, Faq $faq): RedirectResponse
    {
        $validated = $request->validate([
            'direction' => ['required', Rule::in(['up', 'down'])],
        ]);

        $direction = $validated['direction'];

        $neighbor = Faq::query()
            ->when(
                $direction === 'up',
                fn ($query) => $query->where('order', '<', $faq->order)->orderByDesc('order'),
                fn ($query) => $query->where('order', '>', $faq->order)->orderBy('order')
            )
            ->first();

        if (! $neighbor) {
            $message = $direction === 'up'
                ? 'FAQ is already at the top.'
                : 'FAQ is already at the bottom.';

            throw ValidationException::withMessages([
                'direction' => $message,
            ]);
        }

        $currentOrder = $faq->order;

        $faq->update(['order' => $neighbor->order]);
        $neighbor->update(['order' => $currentOrder]);

        return back()->with('success', 'FAQ order updated.');
    }

    public function publishFaq(Faq $faq): RedirectResponse
    {
        if ($faq->published) {
            throw ValidationException::withMessages([
                'published' => 'FAQ is already published.',
            ]);
        }

        $faq->update(['published' => true]);

        return back()->with('success', 'FAQ published.');
    }

    public function unpublishFaq(Faq $faq): RedirectResponse
    {
        if (! $faq->published) {
            throw ValidationException::withMessages([
                'published' => 'FAQ is already unpublished.',
            ]);
        }

        $faq->update(['published' => false]);

        return back()->with('success', 'FAQ unpublished.');
    }

    protected function resolutionAttributes(SupportTicket $ticket, string $nextStatus, int $userId): array
    {
        if ($nextStatus === 'closed') {
            if ($ticket->status !== 'closed' || ! $ticket->resolved_at) {
                return [
                    'resolved_at' => now(),
                    'resolved_by' => $userId,
                ];
            }

            return [];
        }

        if ($ticket->status === 'closed' && $nextStatus !== 'closed') {
            return [
                'resolved_at' => null,
                'resolved_by' => null,
                'customer_satisfaction_rating' => null,
            ];
        }

        return [];
    }
}
