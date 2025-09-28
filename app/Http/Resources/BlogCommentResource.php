<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\BlogComment */
class BlogCommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
            'user' => $this->user
                ? [
                    'id' => $this->user->id,
                    'nickname' => $this->user->nickname,
                    'avatar' => $this->user->avatar ?? null,
                ]
                : null,
        ];
    }
}
