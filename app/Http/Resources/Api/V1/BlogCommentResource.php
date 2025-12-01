<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\BlogComment
 */
class BlogCommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'body' => $this->body,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
            'permissions' => [
                'can_update' => $user?->can('update', $this->resource) ?? false,
                'can_delete' => $user?->can('delete', $this->resource) ?? false,
                'can_report' => $user?->can('report', $this->resource) ?? false,
            ],
            'user' => new UserSummaryResource($this->whenLoaded('user')),
        ];
    }
}
