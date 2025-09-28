<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTokenRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Laravel\Sanctum\PersonalAccessToken;

class TokenController extends Controller
{
    /**
     * Show list of tokens + stats.
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10);

        // eager‐load owner
        $tokens = PersonalAccessToken::with('tokenable:id,nickname,email')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage)
            ->withQueryString()
            ->through(function (PersonalAccessToken $token) {
                $user = $token->tokenable;

                return [
                    'id'          => $token->id,
                    'name'        => $token->name,
                    'created_at'  => $token->created_at,
                    'last_used_at'=> $token->last_used_at,
                    'expires_at'  => $token->expires_at,
                    'revoked_at'  => $token->revoked_at ?? null,
                    'user'        => $user ? [
                        'id'       => $user->id,
                        'nickname' => $user->nickname,
                        'email'    => $user->email,
                    ] : null,
                ];
            });

        $revokedCount = Schema::hasColumn('personal_access_tokens', 'revoked_at')
            ? PersonalAccessToken::whereNotNull('revoked_at')->count()
            : 0;

        $tokenStats = [
            'total'   => PersonalAccessToken::count(),
            'active'  => PersonalAccessToken::where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })->count(),
            'expired' => PersonalAccessToken::whereNotNull('expires_at')
                ->where('expires_at', '<=', now())->count(),
            'revoked' => $revokedCount,
        ];

        $userList = User::select('id','nickname','email')->get();

        return inertia('acp/Tokens', compact(['tokens', 'tokenStats', 'userList']));
    }

    /**
     * Create a new personal access token.
     */
    public function store(StoreTokenRequest $request)
    {
        $user = User::findOrFail($request->user_id);

        $token = $user->createToken(
            $request->name,
            $request->abilities ?? ['*'],
            $request->expires_at ? now()->parse($request->expires_at) : null
        );

        return redirect()->route('acp.tokens.index')
            ->with('success','Token created.');
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
