<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTokenRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

class TokenController extends Controller
{
    use InteractsWithInertiaPagination;

    /**
     * Show list of tokens + stats.
     */
    public function index(Request $request): Response
    {
        $perPage = (int) $request->get('per_page', 10);

        $tokenQuery = PersonalAccessToken::query();

        // eager‐load owner
        $tokens = (clone $tokenQuery)
            ->with('tokenable:id,nickname,email')
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();

        $tokenItems = $tokens->getCollection()
            ->map(function (PersonalAccessToken $token) {
                $user = $token->tokenable;

                return [
                    'id' => $token->id,
                    'name' => $token->name,
                    'created_at' => optional($token->created_at)->toIso8601String(),
                    'last_used_at' => optional($token->last_used_at)->toIso8601String(),
                    'expires_at' => optional($token->expires_at)->toIso8601String(),
                    'revoked_at' => optional($token->revoked_at)->toIso8601String(),
                    'user' => $user ? [
                        'id' => $user->id,
                        'nickname' => $user->nickname,
                        'email' => $user->email,
                    ] : null,
                ];
            })
            ->values()
            ->all();

        $revokedCount = Schema::hasColumn('personal_access_tokens', 'revoked_at')
            ? (clone $tokenQuery)->whereNotNull('revoked_at')->count()
            : 0;

        $tokenStats = [
            'total' => $tokens->total(),
            'active' => (clone $tokenQuery)->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })->count(),
            'expired' => (clone $tokenQuery)->whereNotNull('expires_at')
                ->where('expires_at', '<=', now())->count(),
            'revoked' => $revokedCount,
        ];

        $userList = User::select('id','nickname','email')->get();

        return inertia('acp/Tokens', [
            'tokens' => array_merge([
                'data' => $tokenItems,
            ], $this->inertiaPagination($tokens)),
            'tokenStats' => $tokenStats,
            'userList' => $userList,
        ]);
    }

    /**
     * Create a new personal access token.
     */
    public function store(StoreTokenRequest $request)
    {
        $data = $request->validated();

        $user = User::findOrFail($data['user_id']);

        $user->createToken(
            $data['name'],
            $data['abilities'] ?? ['*'],
            $data['expires_at'] ? Carbon::parse($data['expires_at']) : null
        );

        return redirect()->route('acp.tokens.index')
            ->with('success', 'Token created.');
    }

    /**
     * Delete a token.
     */
    public function destroy(PersonalAccessToken $token)
    {
        $token->delete();
        return redirect()->route('acp.tokens.index')
            ->with('success','Token revoked.');
    }
}
