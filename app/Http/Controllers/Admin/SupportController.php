<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreFaqRequest;
use App\Http\Requests\Admin\StoreSupportResponseTemplateRequest;
use App\Http\Requests\Admin\StoreSupportTicketMessageRequest;
use App\Http\Requests\Admin\StoreSupportTicketRequest;
use App\Http\Requests\Admin\UpdateFaqRequest;
use App\Http\Requests\Admin\UpdateSupportResponseTemplateRequest;
use App\Http\Requests\Admin\UpdateSupportTicketRequest;
use App\Models\SupportResponseTemplate;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\SupportTicketMessageAttachment;
use App\Models\SupportTicketCategory;
use App\Models\SupportTeam;
use App\Models\Faq;
use App\Models\FaqCategory;
use App\Models\FaqFeedback;
use App\Models\User;
use App\Notifications\TicketOpened;
use App\Notifications\TicketReplied;
use App\Notifications\TicketStatusUpdated;
use App\Support\Localization\DateFormatter;
use App\Support\SupportTicketAutoAssigner;
use App\Support\SupportTicketNotificationDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Stringable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SupportController extends Controller
{
    use InteractsWithInertiaPagination;

    public function __construct(
        private SupportTicketNotificationDispatcher $ticketNotifier,
        private SupportTicketAutoAssigner $ticketAssigner,
    ) {
    }

    public function templates(Request $request): Response
    {
        abort_unless($request->user()?->can('support.acp.view'), 403);

        $formatter = DateFormatter::for($request->user());

        $templates = SupportResponseTemplate::query()
            ->with(['category:id,name', 'team:id,name'])
            ->orderBy('title')
            ->get()
            ->map(function (SupportResponseTemplate $template) use ($formatter) {
                return [
                    'id' => $template->id,
                    'title' => $template->title,
                    'body' => $template->body,
                    'is_active' => $template->is_active,
                    'support_ticket_category_id' => $template->support_ticket_category_id,
                    'support_team_id' => $template->support_team_id,
                    'category' => $template->category ? [
                        'id' => $template->category->id,
                        'name' => $template->category->name,
                    ] : null,
                    'team' => $template->team ? [
                        'id' => $template->team->id,
                        'name' => $template->team->name,
                    ] : null,
                    'created_at' => $formatter->iso($template->created_at),
                    'updated_at' => $formatter->iso($template->updated_at),
                ];
            })
            ->values()
            ->all();

        $categories = SupportTicketCategory::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (SupportTicketCategory $category) => [
                'id' => $category->id,
                'name' => $category->name,
            ])
            ->values()
            ->all();

        $teams = SupportTeam::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (SupportTeam $team) => [
                'id' => $team->id,
                'name' => $team->name,
            ])
            ->values()
            ->all();

        return Inertia::render('acp/SupportTemplates', [
            'templates' => $templates,
            'categories' => $categories,
            'teams' => $teams,
            'can' => [
                'create' => (bool) $request->user()?->can('support.acp.create'),
                'edit' => (bool) $request->user()?->can('support.acp.edit'),
                'delete' => (bool) $request->user()?->can('support.acp.delete'),
            ],
        ]);
    }

    public function storeTemplate(StoreSupportResponseTemplateRequest $request): RedirectResponse
    {
        SupportResponseTemplate::create($request->validated());

        return redirect()
            ->route('acp.support.templates.index')
            ->with('success', 'Response template created.');
    }

    public function updateTemplate(
        UpdateSupportResponseTemplateRequest $request,
        SupportResponseTemplate $template
    ): RedirectResponse {
        $template->update($request->validated());

        return redirect()
            ->route('acp.support.templates.index')
            ->with('success', 'Response template updated.');
    }

    public function destroyTemplate(Request $request, SupportResponseTemplate $template): RedirectResponse
    {
        abort_unless($request->user()?->can('support.acp.delete'), 403);

        $template->delete();

        return redirect()
            ->route('acp.support.templates.index')
            ->with('success', 'Response template deleted.');
    }

    public function index(Request $request): Response
    {
        $perPage = (int) $request->get('per_page', 25);

        $ticketsSearch = $request->string('tickets_search')->trim();
        $faqsSearch = $request->string('faqs_search')->trim();

        $ticketsSearchTerm = $ticketsSearch->isNotEmpty() ? $ticketsSearch->value() : null;
        $faqsSearchTerm = $faqsSearch->isNotEmpty() ? $faqsSearch->value() : null;

        $statusFilter = $this->normalizeStatusFilter($request->string('status'));
        $priorityFilter = $this->normalizePriorityFilter($request->string('priority'));
        $assigneeFilter = $this->normalizeAssigneeFilter($request->string('assignee'));
        [$createdFrom, $createdTo] = $this->normalizeDateRange(
            $request->string('date_from'),
            $request->string('date_to'),
        );

        $formatter = DateFormatter::for($request->user());

        $ticketQuery = SupportTicket::query()
            ->when($ticketsSearchTerm, function ($query) use ($ticketsSearchTerm) {
                $escaped = $this->escapeForLike($ticketsSearchTerm);
                $like = "%{$escaped}%";

                $query->where(function ($query) use ($like) {
                    $query
                        ->where('subject', 'like', $like)
                        ->orWhere('body', 'like', $like)
                        ->orWhere('status', 'like', $like)
                        ->orWhere('priority', 'like', $like)
                        ->orWhereHas('user', function ($query) use ($like) {
                            $query
                                ->where('nickname', 'like', $like)
                                ->orWhere('email', 'like', $like);
                        })
                        ->orWhereHas('assignee', function ($query) use ($like) {
                            $query
                                ->where('nickname', 'like', $like)
                                ->orWhere('email', 'like', $like);
                        })
                        ->orWhereHas('resolver', function ($query) use ($like) {
                            $query
                                ->where('nickname', 'like', $like)
                                ->orWhere('email', 'like', $like);
                        });
                });
            })
            ->when($statusFilter, fn ($query, $status) => $query->where('status', $status))
            ->when($priorityFilter, fn ($query, $priority) => $query->where('priority', $priority))
            ->when($assigneeFilter !== null, function ($query) use ($assigneeFilter) {
                if ($assigneeFilter === 'unassigned') {
                    $query->whereNull('assigned_to');

                    return;
                }

                $query->where('assigned_to', $assigneeFilter);
            })
            ->when($createdFrom, fn ($query, $from) => $query->where('created_at', '>=', $from))
            ->when($createdTo, fn ($query, $to) => $query->where('created_at', '<=', $to));

        $faqQuery = Faq::query()
            ->with('category:id,name,slug')
            ->withCount([
                'feedback as helpful_feedback_count' => fn ($query) => $query->where('is_helpful', true),
                'feedback as not_helpful_feedback_count' => fn ($query) => $query->where('is_helpful', false),
            ])
            ->when($faqsSearchTerm, function ($query) use ($faqsSearchTerm) {
                $escaped = $this->escapeForLike($faqsSearchTerm);
                $like = "%{$escaped}%";

                $query->where(function ($query) use ($like) {
                    $query
                        ->where('question', 'like', $like)
                        ->orWhere('answer', 'like', $like);
                });
            });

        $tickets = (clone $ticketQuery)
            ->with([
                'user:id,nickname,email',
                'assignee:id,nickname,email',
                'resolver:id,nickname,email',
                'category:id,name',
            ])
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'tickets_page')
            ->withQueryString();

        $faqs = (clone $faqQuery)
            ->orderBy('order')
            ->paginate($perPage, ['*'], 'faqs_page')
            ->withQueryString();

        $ticketItems = $tickets->getCollection()
            ->map(function (SupportTicket $ticket) use ($formatter) {
                return [
                    'id' => $ticket->id,
                    'subject' => $ticket->subject,
                    'body' => $ticket->body,
                    'status' => $ticket->status,
                    'priority' => $ticket->priority,
                    'support_ticket_category_id' => $ticket->support_ticket_category_id,
                    'created_at' => $formatter->iso($ticket->created_at),
                    'updated_at' => $formatter->iso($ticket->updated_at),
                    'resolved_at' => $formatter->iso($ticket->resolved_at),
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
                    'category' => $ticket->category ? [
                        'id' => $ticket->category->id,
                        'name' => $ticket->category->name,
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
                    'helpful_feedback_count' => (int) ($faq->helpful_feedback_count ?? 0),
                    'not_helpful_feedback_count' => (int) ($faq->not_helpful_feedback_count ?? 0),
                    'category' => $faq->category ? [
                        'id' => $faq->category->id,
                        'name' => $faq->category->name,
                        'slug' => $faq->category->slug,
                    ] : null,
                ];
            })
            ->values()
            ->all();

        $stats = [
            'total' => $tickets->total(),
            'open' => (clone $ticketQuery)->where('status', 'open')->count(),
            'closed' => (clone $ticketQuery)->where('status', 'closed')->count(),
            'faqs' => $faqs->total(),
            'faq_helpful_feedback' => FaqFeedback::where('is_helpful', true)->count(),
            'faq_not_helpful_feedback' => FaqFeedback::where('is_helpful', false)->count(),
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
            'ticketFilters' => [
                'status' => $statusFilter,
                'priority' => $priorityFilter,
                'assignee' => $assigneeFilter,
                'date_from' => optional($createdFrom)?->toDateString(),
                'date_to' => optional($createdTo)?->toDateString(),
            ],
        ]);
    }

    private function escapeForLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function normalizeStatusFilter(Stringable $status): ?string
    {
        $statusValue = $status->trim()->lower();

        if ($statusValue->isEmpty()) {
            return null;
        }

        return in_array($statusValue->value(), ['open', 'pending', 'closed'], true)
            ? $statusValue->value()
            : null;
    }

    private function normalizePriorityFilter(Stringable $priority): ?string
    {
        $priorityValue = $priority->trim()->lower();

        if ($priorityValue->isEmpty()) {
            return null;
        }

        return in_array($priorityValue->value(), ['low', 'medium', 'high'], true)
            ? $priorityValue->value()
            : null;
    }

    private function normalizeAssigneeFilter(Stringable $assignee): int|string|null
    {
        $assigneeValue = $assignee->trim();

        if ($assigneeValue->isEmpty()) {
            return null;
        }

        if ($assigneeValue->value() === 'unassigned') {
            return 'unassigned';
        }

        return ctype_digit($assigneeValue->value())
            ? (int) $assigneeValue->value()
            : null;
    }

    private function normalizeDateRange(Stringable $from, Stringable $to): array
    {
        $fromValue = $from->trim();
        $toValue = $to->trim();

        $parsedFrom = null;
        $parsedTo = null;

        if ($fromValue->isNotEmpty()) {
            $parsedFrom = $this->parseDateBoundary($fromValue->value(), 'start');
        }

        if ($toValue->isNotEmpty()) {
            $parsedTo = $this->parseDateBoundary($toValue->value(), 'end');
        }

        if ($parsedFrom && $parsedTo && $parsedFrom->greaterThan($parsedTo)) {
            return [$parsedTo->copy()->startOfDay(), $parsedTo];
        }

        return [$parsedFrom, $parsedTo];
    }

    private function parseDateBoundary(string $value, string $boundary): ?Carbon
    {
        try {
            $date = Carbon::parse($value);

            return $boundary === 'start'
                ? $date->startOfDay()
                : $date->endOfDay();
        } catch (\Exception) {
            return null;
        }
    }

    // Tickets
    /**
     * Show the form for creating a new support ticket.
     */
    public function createTicket()
    {
        $categories = SupportTicketCategory::orderBy('name')
            ->get(['id', 'name']);

        return inertia('acp/SupportTicketCreate', [
            'categories' => $categories,
        ]);
    }

    public function storeTicket(StoreSupportTicketRequest $request)
    {
        $data = $request->validated();
        $data['user_id'] = $data['user_id'] ?? (int) $request->user()->id;
        $data['support_ticket_category_id'] = $data['support_ticket_category_id'] ?? null;

        $ticket = SupportTicket::create($data);

        $this->ticketAssigner->assign($ticket);

        $this->ticketNotifier->dispatch($ticket, function (string $audience, array $channels) use ($ticket) {
            return (new TicketOpened($ticket))
                ->forAudience($audience)
                ->withChannels($channels);
        });

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
            'category:id,name',
        ]);

        $agents = User::orderBy('nickname')
            ->get(['id', 'nickname', 'email']);

        $categories = SupportTicketCategory::orderBy('name')
            ->get(['id', 'name']);

        return inertia('acp/SupportTicketEdit', [
            'ticket' => $ticket,
            'agents' => $agents,
            'categories' => $categories,
        ]);
    }

    public function updateTicket(UpdateSupportTicketRequest $request, SupportTicket $ticket)
    {
        $validated = $request->validated();

        $previousStatus = $ticket->status;

        if (array_key_exists('status', $validated)) {
            $validated += $this->resolutionAttributes($ticket, $validated['status'], (int) $request->user()->id);
        }

        if (array_key_exists('user_id', $validated)) {
            $validated['user_id'] = $validated['user_id'] ?? (int) $request->user()->id;
        }

        if (array_key_exists('support_ticket_category_id', $validated)) {
            $validated['support_ticket_category_id'] = $validated['support_ticket_category_id'] ?? null;
        }

        $ticket->update($validated);

        if (
            array_key_exists('status', $validated)
            && $previousStatus !== $ticket->status
        ) {
            $this->ticketNotifier->dispatch($ticket, function (string $audience, array $channels) use ($ticket, $previousStatus) {
                return (new TicketStatusUpdated($ticket, $previousStatus))
                    ->forAudience($audience)
                    ->withChannels($channels);
            });
        }

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

    public function searchUsers(Request $request)
    {
        $user = $request->user();

        abort_unless($user && ($user->can('support.acp.create') || $user->can('support.acp.edit')), 403);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        if ($validated['id'] ?? false) {
            $found = User::query()
                ->whereKey($validated['id'])
                ->get(['id', 'nickname', 'email'])
                ->map(fn (User $match) => [
                    'id' => $match->id,
                    'nickname' => $match->nickname,
                    'email' => $match->email,
                ])
                ->all();

            return response()->json(['data' => $found]);
        }

        $term = $validated['q'] ?? '';

        if ($term === '') {
            return response()->json(['data' => []]);
        }

        $escaped = $this->escapeForLike($term);
        $like = "%{$escaped}%";

        $results = User::query()
            ->where(function ($query) use ($like) {
                $query
                    ->where('nickname', 'like', $like)
                    ->orWhere('email', 'like', $like);
            })
            ->orderBy('nickname')
            ->limit(10)
            ->get(['id', 'nickname', 'email'])
            ->map(fn (User $match) => [
                'id' => $match->id,
                'nickname' => $match->nickname,
                'email' => $match->email,
            ])
            ->all();

        return response()->json(['data' => $results]);
    }

    public function showTicket(Request $request, SupportTicket $ticket): Response
    {
        abort_unless($request->user()?->can('support.acp.view'), 403);

        $formatter = DateFormatter::for($request->user());

        $ticket->load([
            'assignee:id,nickname,email',
            'resolver:id,nickname,email',
            'user:id,nickname,email',
            'category:id,name',
            'messages.author:id,nickname,email',
            'messages.attachments',
        ]);

        $messages = $ticket->messages
            ->map(fn (SupportTicketMessage $message) => $this->formatTicketMessage($ticket, $message, $formatter))
            ->values()
            ->all();

        if (count($messages) === 0) {
            $messages[] = [
                'id' => -$ticket->id,
                'body' => $ticket->body,
                'created_at' => $formatter->iso($ticket->created_at),
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

        $assignableAgents = User::orderBy('nickname')
            ->get(['id', 'nickname', 'email'])
            ->map(fn (User $agent) => [
                'id' => $agent->id,
                'nickname' => $agent->nickname,
                'email' => $agent->email,
            ])
            ->all();

        $templates = SupportResponseTemplate::query()
            ->with(['category:id,name', 'team:id,name'])
            ->where('is_active', true)
            ->where(function ($query) use ($ticket) {
                $query->whereNull('support_ticket_category_id');

                if ($ticket->support_ticket_category_id) {
                    $query->orWhere('support_ticket_category_id', $ticket->support_ticket_category_id);
                }
            })
            ->orderBy('title')
            ->get()
            ->map(function (SupportResponseTemplate $template) {
                return [
                    'id' => $template->id,
                    'title' => $template->title,
                    'body' => $template->body,
                    'is_active' => $template->is_active,
                    'support_ticket_category_id' => $template->support_ticket_category_id,
                    'support_team_id' => $template->support_team_id,
                    'category' => $template->category ? [
                        'id' => $template->category->id,
                        'name' => $template->category->name,
                    ] : null,
                    'team' => $template->team ? [
                        'id' => $template->team->id,
                        'name' => $template->team->name,
                    ] : null,
                ];
            })
            ->values()
            ->all();

        return Inertia::render('acp/SupportTicketView', [
            'ticket' => [
                'id' => $ticket->id,
                'subject' => $ticket->subject,
                'body' => $ticket->body,
                'status' => $ticket->status,
                'priority' => $ticket->priority,
                'support_ticket_category_id' => $ticket->support_ticket_category_id,
                'assigned_to' => $ticket->assigned_to,
                'created_at' => $formatter->iso($ticket->created_at),
                'updated_at' => $formatter->iso($ticket->updated_at),
                'resolved_at' => $formatter->iso($ticket->resolved_at),
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
                'category' => $ticket->category ? [
                    'id' => $ticket->category->id,
                    'name' => $ticket->category->name,
                ] : null,
            ],
            'messages' => $messages,
            'canReply' => (bool) $canReply,
            'assignableAgents' => $assignableAgents,
            'templates' => $templates,
        ]);
    }

    public function storeTicketMessage(
        StoreSupportTicketMessageRequest $request,
        SupportTicket $ticket
    ): RedirectResponse {
        abort_unless($request->user()?->can('support.acp.reply'), 403);
        abort_if($ticket->status === 'closed', 403);

        $validated = $request->validated();

        $message = null;

        DB::transaction(function () use ($request, $ticket, $validated, &$message): void {
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
            $message = $message->refresh();

            $this->ticketNotifier->dispatch($ticket, function (string $audience, array $channels) use ($ticket, $message) {
                return (new TicketReplied($ticket, $message))
                    ->forAudience($audience)
                    ->withChannels($channels);
            });
        }

        return redirect()
            ->route('acp.support.tickets.show', $ticket)
            ->with('success', 'Reply sent.');
    }

    private function formatTicketMessage(
        SupportTicket $ticket,
        SupportTicketMessage $message,
        DateFormatter $formatter,
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

        $previousStatus = $ticket->status;

        $ticket->update($updates);

        if ($previousStatus !== $ticket->status) {
            $this->ticketNotifier->dispatch($ticket, function (string $audience, array $channels) use ($ticket, $previousStatus) {
                return (new TicketStatusUpdated($ticket, $previousStatus))
                    ->forAudience($audience)
                    ->withChannels($channels);
            });
        }

        $message = match ($validated['status']) {
            'open' => 'Ticket opened.',
            'pending' => 'Ticket marked as pending.',
            'closed' => 'Ticket closed.',
            default => 'Ticket status updated.',
        };

        return back()->with('success', $message);
    }

    public function bulkUpdateStatus(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('support.acp.status'), 403);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['open', 'pending', 'closed'])],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct', 'exists:support_tickets,id'],
        ]);

        $ids = array_values(array_unique(array_map('intval', $validated['ids'])));

        $tickets = SupportTicket::query()
            ->whereIn('id', $ids)
            ->get();

        if ($tickets->isEmpty()) {
            return back()->with('success', 'No support tickets required updates.');
        }

        $userId = (int) $request->user()->id;
        $updatedCount = 0;

        DB::transaction(function () use ($tickets, $validated, $userId, &$updatedCount) {
            foreach ($tickets as $ticket) {
                $previousStatus = $ticket->status;
                $resolutionUpdates = $this->resolutionAttributes($ticket, $validated['status'], $userId);

                if ($previousStatus === $validated['status'] && $resolutionUpdates === []) {
                    continue;
                }

                $ticket->update(array_merge([
                    'status' => $validated['status'],
                ], $resolutionUpdates));

                $updatedCount++;

                if ($previousStatus !== $ticket->status) {
                    $this->ticketNotifier->dispatch($ticket, function (string $audience, array $channels) use ($ticket, $previousStatus) {
                        return (new TicketStatusUpdated($ticket, $previousStatus))
                            ->forAudience($audience)
                            ->withChannels($channels);
                    });
                }
            }
        });

        $message = match ($updatedCount) {
            0 => 'No support tickets required updates.',
            1 => 'Updated 1 support ticket.',
            default => "Updated {$updatedCount} support tickets.",
        };

        return back()->with('success', $message);
    }

    // FAQ
    /**
     * Show the form for creating a new FAQ.
     */
    public function createFaq()
    {
        $categories = FaqCategory::orderBy('order')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'description']);

        return inertia('acp/SupportFaqCreate', [
            'categories' => $categories
                ->map(fn (FaqCategory $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'description' => $category->description,
                ])
                ->values()
                ->all(),
        ]);
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
        $categories = FaqCategory::orderBy('order')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'description']);

        $formatter = DateFormatter::for(request()->user());

        return inertia('acp/SupportFaqEdit', [
            'faq' => [
                'id' => $faq->id,
                'question' => $faq->question,
                'answer' => $faq->answer,
                'order' => $faq->order,
                'published' => (bool) $faq->published,
                'faq_category_id' => $faq->faq_category_id,
                'created_at' => $formatter->iso($faq->created_at),
                'updated_at' => $formatter->iso($faq->updated_at),
            ],
            'categories' => $categories
                ->map(fn (FaqCategory $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'description' => $category->description,
                ])
                ->values()
                ->all(),
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
