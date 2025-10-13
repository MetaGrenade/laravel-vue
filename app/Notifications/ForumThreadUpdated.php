<?php

namespace App\Notifications;

use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Notifications\Concerns\SendsBroadcastsSynchronously;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class ForumThreadUpdated extends Notification implements ShouldQueue
{
    use Queueable;
    use SendsBroadcastsSynchronously;

    /**
     * @param array<int, string> $channels
     */
    public function __construct(
        protected ForumThread $thread,
        protected ForumPost $post,
        protected array $channels = ['mail', 'database', 'push'],
    ) {
        $this->thread->setRelation('latestPost', $this->post);
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
        return $this->payload();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->payload());
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

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        $excerpt = Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags($this->post->body)) ?? ''), 140);
        $title = 'New reply in "' . $this->thread->title . '"';

        return [
            'thread_id' => $this->thread->id,
            'thread_title' => $this->thread->title,
            'post_id' => $this->post->id,
            'excerpt' => $excerpt,
            'title' => $title,
            'url' => route('forum.threads.show', [
                'board' => $this->thread->board?->slug ?? $this->thread->board->slug,
                'thread' => $this->thread->slug,
            ]) . '#post-' . $this->post->id,
            'created_at' => optional($this->post->created_at)->toIso8601String(),
        ];
    }
}
