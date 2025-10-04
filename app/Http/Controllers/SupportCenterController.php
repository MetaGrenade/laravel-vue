<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Http\Requests\StoreFaqFeedbackRequest;
use App\Http\Requests\StorePublicSupportTicketMessageRequest;
use App\Http\Requests\StorePublicSupportTicketRatingRequest;
use App\Http\Requests\StorePublicSupportTicketRequest;
use App\Models\Faq;
use App\Models\FaqCategory;
use App\Models\FaqFeedback;
use App\Models\SupportTicket;
use App\Models\SupportTicketCategory;
use App\Models\SupportTicketMessage;
use App\Models\SupportTicketMessageAttachment;
use App\Notifications\TicketOpened;
use App\Notifications\TicketReplied;
use App\Support\SupportTicketNotificationDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SupportCenterController extends Controller
{
    use InteractsWithInertiaPagination;

    public function __construct(
        private SupportTicketNotificationDispatcher $ticketNotifier
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $request->user();

        $ticketsPerPage = max(1, (int) $request->query('tickets_per_page', 10));
        $ticketsSearch = $request->string('tickets_search')->trim();
        $faqsSearch = $request->string('faqs_search')->trim();
        $selectedFaqCategoryId = $request->query('faq_category_id');
        $selectedTicketCategoryId = $request->query('ticket_category_id');

        $faqCategoryId = is_numeric($selectedFaqCategoryId) && (int) $selectedFaqCategoryId > 0
            ? (int) $selectedFaqCategoryId
            : null;

        $ticketsSearchTerm = $ticketsSearch->isNotEmpty() ? $ticketsSearch->value() : null;
        $faqsSearchTerm = $faqsSearch->isNotEmpty() ? $faqsSearch->value() : null;
        $ticketCategoryId = is_numeric($selectedTicketCategoryId) && (int) $selectedTicketCategoryId > 0
            ? (int) $selectedTicketCategoryId
            : null;

        $ticketsPayload = [
            'data' => [],
            'meta' => null,
            'links' => null,
        ];

        if ($user) {
            $tickets = SupportTicket::query()
                ->where('user_id', $user->id)
                ->when($ticketCategoryId, function ($query) use ($ticketCategoryId) {
                    $query->where('support_ticket_category_id', $ticketCategoryId);
                })
                ->when($ticketsSearchTerm, function ($query) use ($ticketsSearchTerm) {
                    $escaped = $this->escapeForLike($ticketsSearchTerm);
                    $like = "%{$escaped}%";

                    $query->where(function ($query) use ($like) {
                        $query
                            ->where('subject', 'like', $like)
                            ->orWhere('status', 'like', $like)
                            ->orWhere('priority', 'like', $like)
                            ->orWhereHas('assignee', function ($query) use ($like) {
                                $query
                                    ->where('nickname', 'like', $like)
                                    ->orWhere('email', 'like', $like);
                            });
                    });
                })
                ->with(['assignee:id,nickname,email', 'category:id,name'])
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
                            'support_ticket_category_id' => $ticket->support_ticket_category_id,
                            'created_at' => optional($ticket->created_at)->toIso8601String(),
                            'updated_at' => optional($ticket->updated_at)->toIso8601String(),
                            'customer_satisfaction_rating' => $ticket->customer_satisfaction_rating,
                            'assignee' => $ticket->assignee ? [
                                'id' => $ticket->assignee->id,
                                'nickname' => $ticket->assignee->nickname,
                                'email' => $ticket->assignee->email,
                            ] : null,
                            'category' => $ticket->category ? [
                                'id' => $ticket->category->id,
                                'name' => $ticket->category->name,
                            ] : null,
                        ];
                    })
                    ->values()
                    ->all(),
            ], $this->inertiaPagination($tickets));
        }

        $ticketCategories = SupportTicketCategory::orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (SupportTicketCategory $category) => [
                'id' => $category->id,
                'name' => $category->name,
            ])
            ->all();

        $categories = FaqCategory::query()
            ->withCount([
                'faqs as published_faqs_count' => fn ($query) => $query->where('published', true),
            ])
            ->orderBy('order')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'description', 'order']);

        $faqCollection = Faq::query()
            ->with('category:id,name,slug,description,order')
            ->withCount([
                'feedback as helpful_feedback_count' => fn ($query) => $query->where('is_helpful', true),
                'feedback as not_helpful_feedback_count' => fn ($query) => $query->where('is_helpful', false),
            ])
            ->where('published', true)
            ->when($faqCategoryId, fn ($query) => $query->where('faq_category_id', $faqCategoryId))
            ->when($faqsSearchTerm, function ($query) use ($faqsSearchTerm) {
                $escaped = $this->escapeForLike($faqsSearchTerm);
                $like = "%{$escaped}%";

                $query->where(function ($query) use ($like) {
                    $query
                        ->where('question', 'like', $like)
                        ->orWhere('answer', 'like', $like);
                });
            })
            ->orderBy('order')
            ->get();

        $categorySortIndex = $categories
            ->mapWithKeys(function (FaqCategory $category) {
                $key = sprintf('%011d-%s', $category->order, mb_strtolower($category->name));

                return [$category->id => $key];
            });

        $userFeedbackByFaq = collect();

        if ($user) {
            $userFeedbackByFaq = FaqFeedback::query()
                ->where('user_id', $user->id)
                ->whereIn('faq_id', $faqCollection->pluck('id'))
                ->get()
                ->keyBy('faq_id');
        }

        $faqGroups = $faqCollection
            ->groupBy(fn (Faq $faq) => $faq->faq_category_id)
            ->map(function ($items) {
                /** @var Faq $first */
                $first = $items->first();

                $category = $first->category;

                return [
                    'category' => $category ? [
                        'id' => $category->id,
                        'name' => $category->name,
                        'slug' => $category->slug,
                        'description' => $category->description,
                        'order' => $category->order,
                    ] : null,
                    'faqs' => $items
                        ->map(function (Faq $faq) use ($userFeedbackByFaq) {
                            $feedback = $userFeedbackByFaq->get($faq->id);
                            $userFeedback = null;

                            if ($feedback) {
                                $userFeedback = $feedback->is_helpful ? 'helpful' : 'not_helpful';
                            }

                            return [
                                'id' => $faq->id,
                                'question' => $faq->question,
                                'answer' => $faq->answer,
                                'helpful_feedback_count' => (int) ($faq->helpful_feedback_count ?? 0),
                                'not_helpful_feedback_count' => (int) ($faq->not_helpful_feedback_count ?? 0),
                                'user_feedback' => $userFeedback,
                            ];
                        })
                        ->values()
                        ->all(),
                ];
            })
            ->sortBy(function (array $group) use ($categorySortIndex) {
                $categoryId = $group['category']['id'] ?? null;

                if ($categoryId && $categorySortIndex->has($categoryId)) {
                    return $categorySortIndex->get($categoryId);
                }

                return sprintf('%011d-%s', PHP_INT_MAX, $group['category']['name'] ?? '');
            })
            ->values()
            ->all();

        return Inertia::render('Support', [
            'tickets' => $ticketsPayload,
            'faqs' => [
                'groups' => $faqGroups,
                'filters' => [
                    'search' => $faqsSearchTerm,
                    'selectedCategoryId' => $faqCategoryId,
                    'categories' => $categories
                        ->map(fn (FaqCategory $category) => [
                            'id' => $category->id,
                            'name' => $category->name,
                            'slug' => $category->slug,
                            'description' => $category->description,
                            'order' => $category->order,
                            'published_faqs_count' => (int) $category->published_faqs_count,
                        ])
                        ->values()
                        ->all(),
                    'totalPublished' => (int) $categories->sum('published_faqs_count'),
                ],
                'matchingCount' => $faqCollection->count(),
            ],
            'canSubmitTicket' => (bool) $user,
            'ticketCategories' => $ticketCategories,
        ]);
    }

    public function storeFaqFeedback(StoreFaqFeedbackRequest $request, Faq $faq): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user, 403);

        $isHelpful = $request->validated()['value'] === 'helpful';

        $feedback = $faq->feedback()->firstOrNew([
            'user_id' => $user->id,
        ]);

        if ($feedback->exists && $feedback->is_helpful === $isHelpful) {
            return back()->with('info', 'Thanks! You already told us how helpful this answer was.');
        }

        $feedback->is_helpful = $isHelpful;
        $feedback->user()->associate($user);

        $feedback->save();

        $message = $feedback->wasRecentlyCreated
            ? 'Feedback submitted. Thanks for helping us improve our FAQs!'
            : 'Feedback updated. Thanks for helping us improve our FAQs!';

        return back()->with('success', $message);
    }

    public function store(StorePublicSupportTicketRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $ticket = null;
        $message = null;

        DB::transaction(function () use ($request, $validated, &$ticket, &$message): void {
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
        });

        if ($ticket && $message) {
            $this->ticketNotifier->dispatch($ticket, function (string $audience, array $channels) use ($ticket, $message) {
                return (new TicketOpened($ticket, $message))
                    ->forAudience($audience)
                    ->withChannels($channels);
            });
        }

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
            'category:id,name',
            'messages.author:id,nickname,email',
            'messages.attachments',
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
                'attachments' => [],
            ];
        }

        return Inertia::render('SupportTicketView', [
            'ticket' => [
                'id' => $ticket->id,
                'subject' => $ticket->subject,
                'body' => $ticket->body,
                'status' => $ticket->status,
                'priority' => $ticket->priority,
                'support_ticket_category_id' => $ticket->support_ticket_category_id,
                'created_at' => optional($ticket->created_at)->toIso8601String(),
                'updated_at' => optional($ticket->updated_at)->toIso8601String(),
                'customer_satisfaction_rating' => $ticket->customer_satisfaction_rating,
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
                'category' => $ticket->category ? [
                    'id' => $ticket->category->id,
                    'name' => $ticket->category->name,
                ] : null,
            ],
            'messages' => $messages,
            'canReply' => $ticket->status !== 'closed',
            'canRate' => $ticket->status === 'closed' && $ticket->customer_satisfaction_rating === null,
        ]);
    }

    public function storeMessage(
        StorePublicSupportTicketMessageRequest $request,
        SupportTicket $ticket
    ): RedirectResponse {
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
            $this->ticketNotifier->dispatch($ticket, function (string $audience, array $channels) use ($ticket, $message) {
                return (new TicketReplied($ticket, $message))
                    ->forAudience($audience)
                    ->withChannels($channels);
            });
        }

        return redirect()
            ->route('support.tickets.show', $ticket)
            ->with('success', 'Your message has been sent.');
    }

    public function storeRating(
        StorePublicSupportTicketRatingRequest $request,
        SupportTicket $ticket
    ): RedirectResponse {
        $validated = $request->validated();

        $ticket->update([
            'customer_satisfaction_rating' => (int) $validated['rating'],
        ]);

        return redirect()
            ->route('support.tickets.show', $ticket)
            ->with('success', 'Thanks for sharing your feedback.');
    }

    public function updateStatus(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user && (int) $ticket->user_id === (int) $user->id, 403);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['closed'])],
        ]);

        if ($ticket->status === $validated['status']) {
            return back()->with('info', 'This ticket is already closed.');
        }

        $updates = [
            'status' => $validated['status'],
        ];

        if (! $ticket->resolved_at) {
            $updates['resolved_at'] = now();
        }

        if (! $ticket->resolved_by) {
            $updates['resolved_by'] = $user->id;
        }

        $ticket->update($updates);

        return back()->with('success', 'Ticket closed.');
    }

    public function reopen(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user && (int) $ticket->user_id === (int) $user->id, 403);

        if ($ticket->status !== 'closed') {
            return back()->with('info', 'This ticket is already open.');
        }

        $ticket->update([
            'status' => 'open',
            'resolved_at' => null,
            'resolved_by' => null,
            'customer_satisfaction_rating' => null,
        ]);

        return back()->with('success', 'Ticket reopened. We will take another look.');
    }

    private function escapeForLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
