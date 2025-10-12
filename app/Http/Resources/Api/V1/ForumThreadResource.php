<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ForumThread
 */
class ForumThreadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt,
            'is_locked' => $this->is_locked,
            'is_pinned' => $this->is_pinned,
            'views' => $this->views,
            'last_posted_at' => optional($this->last_posted_at)->toIso8601String(),
            'board' => $this->whenLoaded('board', function () {
                return [
                    'id' => $this->board->id,
                    'name' => $this->board->name,
                    'slug' => $this->board->slug,
                ];
            }),
            'author' => new UserSummaryResource($this->whenLoaded('author')),
            'latest_post' => $this->whenLoaded('latestPost', function () {
                $post = $this->latestPost;

                if (! $post) {
                    return null;
                }

                return [
                    'id' => $post->id,
                    'body' => $post->body,
                    'created_at' => optional($post->created_at)->toIso8601String(),
                    'author' => new UserSummaryResource($post->author),
                ];
            }),
        ];
    }
}
