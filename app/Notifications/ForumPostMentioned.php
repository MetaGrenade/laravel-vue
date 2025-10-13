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

class ForumPostMentioned extends Notification implements ShouldQueue
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
        ]) . '#post-' . $this->post->id;

        return (new MailMessage())
            ->subject('You were mentioned in "' . $this->thread->title . '"')
            ->greeting('Hi ' . ($notifiable->nickname ?? $notifiable->name ?? 'there') . '!')
            ->line('You were mentioned in a forum discussion.')
            ->line(Str::limit(strip_tags($this->post->body), 200))
            ->action('View mention', $url);
    }

    public function toArray(object $notifiable): array
    {
        return $this->payload('You were mentioned in "' . $this->thread->title . '"');
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->payload('You were mentioned in "' . $this->thread->title . '"'));
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
    protected function payload(string $title): array
    {
        $excerpt = Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags($this->post->body)) ?? ''), 140);

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
