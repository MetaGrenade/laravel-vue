<?php

namespace App\Support\Forum;

use App\Models\ForumBoard;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\User;
use App\Support\Database\Transaction;
use App\Support\Moderation\Automod;
use App\Support\Moderation\AutomodVerdict;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Somebody posts in the forum: a reply, or a thread with its first post (docs/automod.md).
 *
 * The rules set by an administrator are asked first. A post that breaks one that refuses is refused, with a message in the form's `body`
 * field. A post that breaks one that holds is saved but *held*: out of sight of everybody but its author and the moderators, with nothing
 * said to anybody else and nothing earned, until a moderator approves it. Anything else is posted, and what follows a post
 * ({@see ForumPublisher}) is done.
 */
class ForumSubmissions
{
    public const REJECTED = 'This looks like spam, so it was not posted. If you think that is a mistake, please contact a moderator.';

    public function __construct(
        private readonly Automod $automod,
        private readonly ForumPublisher $publisher,
    ) {}

    /**
     * @param  string  $body  What was written, already made safe.
     *
     * @throws ValidationException When a rule refuses it.
     */
    public function reply(User $author, ForumThread $thread, string $body): ForumSubmission
    {
        $verdict = $this->check($author, '', $body);

        $post = new ForumPost([
            'forum_thread_id' => $thread->id,
            'user_id' => $author->id,
            'body' => $body,
        ]);

        if ($verdict !== null) {
            $this->hold($post, $verdict);
        }

        $post->save();

        if ($verdict === null) {
            $this->publisher->replyPublished($thread, $post, $author);
        }

        return new ForumSubmission($thread, $post, $verdict !== null);
    }

    /**
     * @param  string  $body  What was written, already made safe.
     *
     * @throws ValidationException When a rule refuses it.
     */
    public function thread(User $author, ForumBoard $board, string $title, string $body): ForumSubmission
    {
        $verdict = $this->check($author, $title, $body);
        $bodyText = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '');

        $baseSlug = Str::slug($title);
        $baseSlug = $baseSlug === '' ? 'thread' : Str::limit($baseSlug, 240, '');

        do {
            $slug = $baseSlug.'-'.Str::random(6);
        } while (ForumThread::where('slug', $slug)->exists());

        $thread = null;
        $initialPost = null;

        Transaction::run(function () use ($board, $author, $title, $slug, $body, $bodyText, $verdict, &$thread, &$initialPost) {
            $thread = new ForumThread([
                'forum_board_id' => $board->id,
                'user_id' => $author->id,
                'title' => $title,
                'slug' => $slug,
                'excerpt' => Str::limit($bodyText, 160),
                'last_posted_at' => now(),
                'last_post_user_id' => $author->id,
            ]);

            if ($verdict !== null) {
                // Not published, which is what keeps it out of every list and page that is not the moderators'.
                $thread->is_published = false;
                $this->hold($thread, $verdict);
            }

            $thread->save();

            $initialPost = ForumPost::create([
                'forum_thread_id' => $thread->id,
                'user_id' => $author->id,
                'body' => $body,
            ]);

            $thread->forceFill([
                'last_posted_at' => $initialPost->created_at,
                'last_post_user_id' => $initialPost->user_id,
            ])->save();
        });

        if ($verdict === null) {
            $this->publisher->threadPublished($thread, $initialPost, $author);
        }

        return new ForumSubmission($thread, $initialPost, $verdict !== null);
    }

    /**
     * @throws ValidationException
     */
    private function check(User $author, string $title, string $body): ?AutomodVerdict
    {
        $verdict = $this->automod->check($author, $title, $body);

        if ($verdict?->rejects()) {
            throw ValidationException::withMessages(['body' => self::REJECTED]);
        }

        return $verdict;
    }

    private function hold(Model $content, AutomodVerdict $verdict): void
    {
        $content->forceFill([
            'held_at' => now(),
            'held_reason' => Str::limit($verdict->reason(), 250, ''),
            'held_by_rule_id' => $verdict->rule->id,
        ]);
    }
}
