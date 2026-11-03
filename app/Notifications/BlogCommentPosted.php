<?php

namespace App\Notifications;

use App\Models\Blog;
use App\Models\BlogComment;
use App\Notifications\Concerns\SendsBroadcastsSynchronously;
use App\Support\Mail\MailText;
use App\Support\Notifications\RecordsForDigest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class BlogCommentPosted extends Notification implements RecordsForDigest, ShouldQueue
{
    use Queueable;
    use SendsBroadcastsSynchronously;

    /**
     * @param  array<int, string>  $channels
     */
    public function __construct(
        protected Blog $blog,
        protected BlogComment $comment,
        protected array $channels = ['mail', 'database', 'push'],
    ) {
        $this->comment->setRelation('blog', $this->blog);
        $this->comment->loadMissing('user:id,nickname');
    }

    public function via(object $notifiable): array
    {
        return array_map(
            static fn (string $channel) => $channel === 'push' ? 'broadcast' : $channel,
            $this->channels,
        );
    }

    public function viaQueues(): array
    {
        return [
            'mail' => 'mail',
            'database' => 'default',
            'broadcast' => 'default',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $commentAuthor = $this->commentAuthor();
        $url = $this->commentUrl();
        $excerpt = $this->commentExcerpt(200);

        return (new MailMessage)
            ->subject($this->notificationTitle())
            ->greeting(MailText::greeting($notifiable))
            ->line(__('mail.blog_comment.line', ['author' => $commentAuthor, 'title' => $this->blog->title]))
            ->line($excerpt)
            ->action(__('mail.blog_comment.action'), $url)
            ->line(__('mail.blog_comment.reason'));
    }

    public function toArray(object $notifiable): array
    {
        return $this->payload();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->payload());
    }

    /**
     * Limit the notification delivery channels.
     *
     * @param  array<int, string>  $channels
     */
    public function withChannels(array $channels): self
    {
        $clone = clone $this;
        $clone->channels = $channels;

        return $clone;
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
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
            'created_at' => optional($this->comment->created_at)->toIso8601String(),
        ];
    }

    protected function commentExcerpt(int $limit = 140): string
    {
        $body = trim(preg_replace('/\s+/', ' ', strip_tags($this->comment->body)) ?? '');

        return Str::limit($body, $limit);
    }

    protected function commentUrl(): string
    {
        return route('blogs.view', ['slug' => $this->blog->slug]).'#comment-'.$this->comment->id;
    }

    protected function commentAuthor(): string
    {
        return $this->comment->user?->nickname
            ?? $this->comment->user?->name
            ?? __('mail.blog_comment.anonymous_author');
    }

    protected function notificationTitle(): string
    {
        return __('mail.blog_comment.title', ['title' => $this->blog->title]);
    }

    protected function excerptLine(string $commentAuthor, string $excerpt): string
    {
        return __('mail.blog_comment.replied', ['author' => $commentAuthor, 'excerpt' => $excerpt]);
    }
}
