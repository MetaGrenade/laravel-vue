<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // The `users.name` column was renamed `nickname` (2025_04_25_064824), and a member has no other name. This used to read `name`,
            // which is no longer anything, so every author in the API was `null`.
            'nickname' => $this->nickname,
            'email' => $this->when($request->user()?->is($this->resource) ?? false, $this->email),
        ];
    }
}
