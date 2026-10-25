<?php

namespace App\Notifications;

use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\User;
use App\Notifications\Concerns\SendsBroadcastsSynchronously;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class ForumFollowedMemberPosted extends Notification implements ShouldQueue
{
    use Queueable;
    use SendsBroadcastsSynchronously;

    /**
     * @param  array<int, string>  $channels
     */
    public function __construct(
        protected ForumThread $thread,
        protected ForumPost $post,
        protected User $author,
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
        $name = (string) $this->author->nickname;

        return (new MailMessage)
            ->subject($name.' posted in "'.$this->thread->title.'"')
            ->greeting('Hi '.($notifiable->nickname ?? 'there').'!')
            ->line($name.' posted in "'.$this->thread->title.'".')
            ->line(Str::limit(strip_tags((string) $this->post->body), 200))
            ->action('View post', $this->url())
            ->line('You are receiving this email because you follow '.$name.'.');
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
        $name = (string) $this->author->nickname;
        $excerpt = Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags((string) $this->post->body)) ?? ''), 140);
        $title = $name.' posted in "'.$this->thread->title.'"';

        return [
            'thread_id' => $this->thread->id,
            'thread_title' => $this->thread->title,
            'post_id' => $this->post->id,
            'author' => $name,
            'excerpt' => $excerpt,
            'title' => $title,
            'url' => $this->url(),
            'created_at' => optional($this->post->created_at)->toIso8601String(),
        ];
    }

    private function url(): string
    {
        return route('forum.threads.show', [
            'board' => $this->thread->board->slug,
            'thread' => $this->thread->slug,
        ]).'#post-'.$this->post->id;
    }
}
