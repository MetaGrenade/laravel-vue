<?php

namespace App\Notifications;

use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\User;
use App\Notifications\Concerns\SendsBroadcastsSynchronously;
use App\Support\Forum\PostPages;
use App\Support\Mail\MailText;
use App\Support\Notifications\RecordsForDigest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class ForumFollowedMemberPosted extends Notification implements RecordsForDigest, ShouldQueue
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
            ->subject(__('mail.forum.followed_member_posted.title', ['name' => $name, 'title' => $this->thread->title]))
            ->greeting(MailText::greeting($notifiable))
            ->line(__('mail.forum.followed_member_posted.line', ['name' => $name, 'title' => $this->thread->title]))
            ->line(Str::limit(strip_tags((string) $this->post->body), 200))
            ->action(__('mail.forum.followed_member_posted.action'), $this->url())
            ->line(__('mail.forum.followed_member_posted.reason', ['name' => $name]));
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
        $title = __('mail.forum.followed_member_posted.title', ['name' => $name, 'title' => $this->thread->title]);

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
        // A reply after the first page has to name its page, or the link opens where the post is not (PostPages).
        return PostPages::of([$this->post])->address($this->post, $this->thread->board->slug, $this->thread->slug);
    }
}
