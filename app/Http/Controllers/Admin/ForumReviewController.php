<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Http\Controllers\Controller;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\User;
use App\Support\Forum\ForumReview;
use App\Support\Localization\DateFormatter;
use App\Support\Moderation\Automod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the forum's automatic moderation has held for a moderator (docs/automod.md): replies and threads out of sight of everybody but
 * their authors and the moderators until one of them approves it, or rejects it and it goes. Everything here needs `forums.acp.review`.
 */
class ForumReviewController extends Controller
{
    use InteractsWithInertiaPagination;

    public function index(Request $request): Response
    {
        $this->authorise($request);

        $type = $request->query('type') === 'threads' ? 'threads' : 'replies';
        $formatter = DateFormatter::for($request->user());

        $canModerate = (bool) ($request->user()?->can('users.acp.warn') || $request->user()?->can('users.acp.ban'));

        // Oldest first: the one that has waited longest is the next to be looked at.
        $paginator = $type === 'replies'
            ? ForumPost::query()
                ->withoutGlobalScope(ForumPost::VISIBLE)
                ->whereNotNull('held_at')
                ->with([
                    'author' => fn ($query) => $query->select('id', 'nickname', 'created_at')->withCount('forumPosts'),
                    'thread:id,title,slug,forum_board_id',
                    'thread.board:id,title,slug',
                ])
                ->orderBy('held_at')->orderBy('id')
                ->paginate(25)->withQueryString()
            : ForumThread::query()
                ->whereNotNull('held_at')
                ->with([
                    'author' => fn ($query) => $query->select('id', 'nickname', 'created_at')->withCount('forumPosts'),
                    'board:id,title,slug',
                    'posts' => fn ($query) => $query->oldest('id')->limit(1),
                ])
                ->orderBy('held_at')->orderBy('id')
                ->paginate(25)->withQueryString();

        $data = $paginator->getCollection()->map(fn (ForumPost|ForumThread $row) => $row instanceof ForumPost
            ? $this->item($row, 'reply', $row->thread, $row->author, $formatter, $canModerate)
            : $this->item($row->posts->first() ?? $row, 'thread', $row, $row->author, $formatter, $canModerate))->values()->all();

        return Inertia::render('acp/ForumReview', [
            'type' => $type,
            'items' => ['data' => $data, ...$this->inertiaPagination($paginator)],
            'counts' => [
                'replies' => ForumPost::query()->withoutGlobalScope(ForumPost::VISIBLE)->whereNotNull('held_at')->count(),
                'threads' => ForumThread::query()->whereNotNull('held_at')->count(),
            ],
        ]);
    }

    public function approveReply(Request $request, int $post, ForumReview $review): RedirectResponse
    {
        $this->authorise($request);

        $found = ForumPost::query()->withoutGlobalScope(ForumPost::VISIBLE)->findOrFail($post);

        return $this->answer($review->approveReply($found), 'The reply is approved and is now in the thread.');
    }

    public function rejectReply(Request $request, int $post, ForumReview $review): RedirectResponse
    {
        $this->authorise($request);

        $found = ForumPost::query()->withoutGlobalScope(ForumPost::VISIBLE)->findOrFail($post);

        return $this->answer($review->rejectReply($found), 'The reply is rejected and has been removed.');
    }

    public function approveThread(Request $request, ForumThread $thread, ForumReview $review): RedirectResponse
    {
        $this->authorise($request);

        return $this->answer($review->approveThread($thread), 'The thread is approved and is now published.');
    }

    public function rejectThread(Request $request, ForumThread $thread, ForumReview $review): RedirectResponse
    {
        $this->authorise($request);

        return $this->answer($review->rejectThread($thread), 'The thread is rejected and has been removed.');
    }

    /**
     * Approve or reject several at once.
     */
    public function bulk(Request $request, ForumReview $review): RedirectResponse
    {
        $this->authorise($request);

        $validated = $request->validate([
            'action' => ['required', Rule::in(['approve', 'reject'])],
            'replies' => ['sometimes', 'array', 'max:200'],
            'replies.*' => ['integer'],
            'threads' => ['sometimes', 'array', 'max:200'],
            'threads.*' => ['integer'],
        ]);

        $done = 0;

        foreach (array_unique($validated['replies'] ?? []) as $id) {
            $post = ForumPost::query()->withoutGlobalScope(ForumPost::VISIBLE)->find($id);

            if ($post !== null) {
                $done += (int) ($validated['action'] === 'approve' ? $review->approveReply($post) : $review->rejectReply($post));
            }
        }

        foreach (array_unique($validated['threads'] ?? []) as $id) {
            $thread = ForumThread::query()->find($id);

            if ($thread !== null) {
                $done += (int) ($validated['action'] === 'approve' ? $review->approveThread($thread) : $review->rejectThread($thread));
            }
        }

        $verb = $validated['action'] === 'approve' ? 'approved' : 'rejected';

        return back()->with('success', $done === 0 ? 'Nothing was left to '.rtrim($verb, 'd').'.' : ($done === 1 ? "1 item {$verb}." : "{$done} items {$verb}."));
    }

    private function authorise(Request $request): void
    {
        abort_unless($request->user()?->can('forums.acp.review'), 403);
    }

    /**
     * What to tell the moderator: what was done, or that somebody else already did it.
     */
    private function answer(bool $done, string $message): RedirectResponse
    {
        return $done
            ? back()->with('success', $message)
            : back()->with('info', 'Somebody else has already dealt with that one.');
    }

    /**
     * @return array<string, mixed>
     */
    private function item(ForumPost|ForumThread $content, string $kind, ?ForumThread $thread, ?User $author, DateFormatter $formatter, bool $canModerate): array
    {
        $text = $content instanceof ForumPost ? $content->body : $content->excerpt;
        $subject = $kind === 'thread' ? 'forum_thread:'.$thread?->getKey() : 'forum_post:'.$content->getKey();

        return [
            'id' => $kind === 'thread' ? $thread?->getKey() : $content->getKey(),
            'kind' => $kind,
            'title' => $thread?->title,
            'excerpt' => Str::limit(Automod::plain((string) $text), 400),
            'reason' => $content->held_reason ?? $thread?->held_reason,
            'held_at' => $formatter->iso($content->held_at ?? $thread?->held_at),
            'board' => $thread?->board?->title,
            'url' => $thread !== null && $thread->board !== null ? route('forum.threads.show', ['board' => $thread->board, 'thread' => $thread]) : null,
            'author' => $author === null ? null : [
                'id' => $author->id,
                'nickname' => $author->nickname,
                'account_created_at' => $formatter->iso($author->created_at),
                'approved_posts' => (int) $author->getAttribute('forum_posts_count'),
            ],
            'moderate_url' => $canModerate && $author !== null && $author->id !== request()->user()?->id
                ? route('acp.users.moderation.show', ['user' => $author->id, 'subject' => $subject])
                : null,
        ];
    }
}
