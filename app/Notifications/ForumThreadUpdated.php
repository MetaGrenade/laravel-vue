<?php

namespace App\Notifications;

use App\Models\ForumPost;
use App\Models\ForumThread;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class ForumThreadUpdated extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param array<int, string> $channels
     */
    public function __construct(
        protected ForumThread $thread,
        protected ForumPost $post,
        protected array $channels = ['mail', 'database'],
    ) {
        $this->thread->setRelation('latestPost', $this->post);
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
        $url = route('forum.threads.show', [
            'board' => $this->thread->board?->slug ?? $this->thread->board->slug,
            'thread' => $this->thread->slug,
            'page' => null,
        ]);

        return (new MailMessage())
            ->subject('New reply in "' . $this->thread->title . '"')
            ->greeting('Hi ' . ($notifiable->nickname ?? $notifiable->name ?? 'there') . '!')
            ->line('There is a new reply in a thread you follow: "' . $this->thread->title . '".')
            ->line(Str::limit(strip_tags($this->post->body), 200))
            ->action('View reply', $url)
            ->line('You are receiving this email because you opted to follow this thread.');
    }

    public function toArray(object $notifiable): array
    {
        $excerpt = Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags($this->post->body)) ?? ''), 140);

        return [
            'thread_id' => $this->thread->id,
            'thread_title' => $this->thread->title,
            'post_id' => $this->post->id,
            'excerpt' => $excerpt,
            'url' => route('forum.threads.show', [
                'board' => $this->thread->board?->slug ?? $this->thread->board->slug,
                'thread' => $this->thread->slug,
            ]) . '#post-' . $this->post->id,
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
