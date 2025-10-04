<?php

namespace App\Notifications;

use App\Models\Blog;
use App\Models\BlogComment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class BlogCommentPosted extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param array<int, string> $channels
     */
    public function __construct(
        protected Blog $blog,
        protected BlogComment $comment,
        protected array $channels = ['mail', 'database'],
    ) {
        $this->comment->setRelation('blog', $this->blog);
        $this->comment->loadMissing('user:id,nickname');
    }

    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function viaQueues(): array
    {
        return [
            'mail' => 'mail',
            'database' => 'default',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $commentAuthor = $this->commentAuthor();
        $url = $this->commentUrl();
        $excerpt = $this->commentExcerpt(200);

        return (new MailMessage())
            ->subject($this->notificationTitle())
            ->greeting('Hi ' . ($notifiable->nickname ?? $notifiable->name ?? 'there') . '!')
            ->line($commentAuthor . ' just left a new reply on "' . $this->blog->title . '".')
            ->line($excerpt)
            ->action('Read the reply', $url)
            ->line('You are receiving this email because you opted in to comment notifications for this post.');
    }

    public function toArray(object $notifiable): array
    {
        $commentAuthor = $this->commentAuthor();
        $excerpt = $this->commentExcerpt();
        $url = $this->commentUrl();
        $title = $this->notificationTitle();
        $excerptLine = $this->excerptLine($commentAuthor, $excerpt);

        return [
            'blog_id' => $this->blog->id,
            'blog_title' => $this->blog->title,
            'comment_id' => $this->comment->id,
            'comment_author_id' => $this->comment->user_id,
            'comment_author_nickname' => $this->comment->user?->nickname,
            'comment_excerpt' => $excerpt,
            'title' => $title,
            'thread_title' => $title,
            'excerpt' => Str::limit($excerptLine, 180),
            'url' => $url,
        ];
    }

    /**
     * Limit the notification delivery channels.
     *
     * @param array<int, string> $channels
     */
    public function withChannels(array $channels): self
    {
        $clone = clone $this;
        $clone->channels = $channels;

        return $clone;
    }

    protected function commentExcerpt(int $limit = 140): string
    {
        $body = trim(preg_replace('/\s+/', ' ', strip_tags($this->comment->body)) ?? '');

        return Str::limit($body, $limit);
    }

    protected function commentUrl(): string
    {
        return route('blogs.view', ['slug' => $this->blog->slug]) . '#comment-' . $this->comment->id;
    }

    protected function commentAuthor(): string
    {
        return $this->comment->user?->nickname
            ?? $this->comment->user?->name
            ?? 'A community member';
    }

    protected function notificationTitle(): string
    {
        return 'New reply on "' . $this->blog->title . '"';
    }

    protected function excerptLine(string $commentAuthor, string $excerpt): string
    {
        return $commentAuthor . ' replied: ' . $excerpt;
    }
}
