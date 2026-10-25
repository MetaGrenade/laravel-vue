<?php

namespace App\Support\Moderation;

use App\Models\Blog;
use App\Models\BlogComment;
use App\Models\ForumBoard;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * What a warning or a ban was about, when a moderator started from a report (docs/moderation.md): a forum post, a thread or a blog
 * comment, written as `forum_post:12`. Only content the member wrote can be named, so a reference cannot be used to put another
 * person's post in somebody's history.
 */
final class ModerationSubject
{
    public const FORUM_POST = 'forum_post';

    public const FORUM_THREAD = 'forum_thread';

    public const BLOG_COMMENT = 'blog_comment';

    public const TYPES = [self::FORUM_POST, self::FORUM_THREAD, self::BLOG_COMMENT];

    /**
     * What a reference names, or null when it names nothing the member wrote.
     *
     * @return array{type: string, id: int, label: string}|null
     */
    public static function resolve(mixed $reference, User $member): ?array
    {
        if (! is_string($reference) || preg_match('/^([a-z_]+):(\d{1,18})$/', $reference, $found) !== 1) {
            return null;
        }

        [, $type, $id] = $found;
        $id = (int) $id;

        $label = match ($type) {
            self::FORUM_POST => self::postLabel($id, $member),
            self::FORUM_THREAD => self::threadLabel($id, $member),
            self::BLOG_COMMENT => self::commentLabel($id, $member),
            default => null,
        };

        return $label === null ? null : ['type' => $type, 'id' => $id, 'label' => $label];
    }

    /**
     * Where the content is now, or null when it is gone.
     */
    public static function url(?string $type, ?int $id): ?string
    {
        if ($type === null || $id === null) {
            return null;
        }

        return match ($type) {
            self::FORUM_POST => self::threadUrl(ForumPost::query()->withoutGlobalScope(ForumPost::VISIBLE)->whereKey($id)->value('forum_thread_id')),
            self::FORUM_THREAD => self::threadUrl($id),
            self::BLOG_COMMENT => self::blogUrl(BlogComment::query()->whereKey($id)->value('blog_id')),
            default => null,
        };
    }

    private static function threadUrl(mixed $threadId): ?string
    {
        $thread = is_numeric($threadId) ? ForumThread::query()->find((int) $threadId) : null;
        $board = $thread !== null ? ForumBoard::query()->find($thread->forum_board_id) : null;

        return $thread !== null && $board !== null ? route('forum.threads.show', ['board' => $board, 'thread' => $thread]) : null;
    }

    private static function blogUrl(mixed $blogId): ?string
    {
        $slug = is_numeric($blogId) ? Blog::query()->whereKey((int) $blogId)->value('slug') : null;

        return is_string($slug) ? route('blogs.view', $slug) : null;
    }

    private static function postLabel(int $id, User $member): ?string
    {
        // A reply held for review is one a moderator can be moving to warn somebody about, so it is looked for as well.
        $threadId = ForumPost::query()->withoutGlobalScope(ForumPost::VISIBLE)->whereKey($id)->where('user_id', $member->id)->value('forum_thread_id');

        return $threadId === null ? null : 'Reply in "'.self::shorten(ForumThread::query()->whereKey($threadId)->value('title')).'"';
    }

    private static function threadLabel(int $id, User $member): ?string
    {
        $exists = ForumThread::query()->whereKey($id)->where('user_id', $member->id)->exists();

        return ! $exists ? null : 'Thread "'.self::shorten(ForumThread::query()->whereKey($id)->value('title')).'"';
    }

    private static function commentLabel(int $id, User $member): ?string
    {
        $blogId = BlogComment::query()->whereKey($id)->where('user_id', $member->id)->value('blog_id');

        return $blogId === null ? null : 'Comment on "'.self::shorten(Blog::query()->whereKey($blogId)->value('title')).'"';
    }

    private static function shorten(mixed $title): string
    {
        return Str::limit(is_string($title) ? $title : '', 180, '…');
    }
}
