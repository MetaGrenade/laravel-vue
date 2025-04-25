<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTokenRequest;
use App\Models\User;
use Illuminate\Http\Request;
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
        $tokens = PersonalAccessToken::with('tokenable')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage)
            ->withQueryString();

        $tokenStats = [
            'total'   => PersonalAccessToken::count(),
            'active'  => PersonalAccessToken::whereNull('expires_at')->count(),
            'expired' => PersonalAccessToken::whereNotNull('expires_at')
                ->where('expires_at', '<', now())->count(),
            'revoked' => PersonalAccessToken::whereNotNull('expires_at')
                ->where('expires_at', '>=', now())->count(),
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
