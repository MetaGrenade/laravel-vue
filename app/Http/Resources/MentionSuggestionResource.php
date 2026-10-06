<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;

/**
 * @mixin User
 */
class MentionSuggestionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $nickname = trim((string) $this->nickname);

        $profileUrl = null;

        if ($nickname !== '' && Route::has('members.show')) {
            $profileUrl = route('members.show', ['user' => $this->getRouteKey()]);
        }

        return [
            'id' => $this->getKey(),
            'nickname' => $nickname,
            'avatar_url' => $this->avatar_url,
            'profile_url' => $profileUrl,
        ];
    }
}
