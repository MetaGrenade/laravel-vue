<?php

namespace App\Events;

use App\Models\ForumPost;
use App\Models\ForumThread;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class ForumPostCreated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public ForumThread $thread,
        public ForumPost $post,
    ) {
        $this->thread->loadMissing('board:id,slug');
        $this->post->loadMissing('author:id,nickname,avatar_url');
    }

    public function broadcastOn(): array
    {
        return [
            new PresenceChannel('forum.threads.' . $this->thread->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ForumPostCreated';
    }

    public function broadcastWith(): array
    {
        $boardSlug = $this->thread->board?->slug ?? $this->thread->board->slug;

        return [
            'thread_id' => $this->thread->id,
            'thread_title' => $this->thread->title,
            'post' => [
                'id' => $this->post->id,
                'author' => [
                    'id' => $this->post->author?->id,
                    'nickname' => $this->post->author?->nickname,
                    'avatar_url' => $this->post->author?->avatar_url,
                ],
                'excerpt' => Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags($this->post->body)) ?? ''), 160),
                'created_at' => optional($this->post->created_at)->toIso8601String(),
                'url' => route('forum.threads.show', [
                    'board' => $boardSlug,
                    'thread' => $this->thread->slug,
                ]) . '#post-' . $this->post->id,
            ],
        ];
    }
}
