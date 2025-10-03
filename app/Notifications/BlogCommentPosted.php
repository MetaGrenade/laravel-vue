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
        $commentAuthor = $this->comment->user?->nickname ?? 'A community member';
        $url = route('blogs.view', ['slug' => $this->blog->slug]) . '#comment-' . $this->comment->id;
        $excerpt = Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags($this->comment->body)) ?? ''), 200);

        return (new MailMessage())
            ->subject('New comment on "' . $this->blog->title . '"')
            ->greeting('Hi ' . ($notifiable->nickname ?? $notifiable->name ?? 'there') . '!')
            ->line($commentAuthor . ' just left a new comment on "' . $this->blog->title . '".')
            ->line($excerpt)
            ->action('Read the reply', $url)
            ->line('You are receiving this email because you opted in to comment notifications for this post.');
    }

    public function toArray(object $notifiable): array
    {
        $excerpt = Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags($this->comment->body)) ?? ''), 140);

        return [
            'blog_id' => $this->blog->id,
            'blog_title' => $this->blog->title,
            'comment_id' => $this->comment->id,
            'comment_excerpt' => $excerpt,
            'url' => route('blogs.view', ['slug' => $this->blog->slug]) . '#comment-' . $this->comment->id,
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
}
