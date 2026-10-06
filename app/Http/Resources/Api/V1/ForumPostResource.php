<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ForumPost;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ForumPost
 */
class ForumPostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'edited_at' => optional($this->edited_at)->toIso8601String(),
            'author' => new UserSummaryResource($this->whenLoaded('author')),
        ];
    }
}
