<?php

namespace App\Http\Middleware;

use App\Support\Api\Scopes;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds an API key to its scopes: `scope:blog:write` lets a request through only if the key holds `blog:write` (or `*`). With several
 * scopes listed, holding any one of them is enough. A visitor signed in with a session, such as the site's own pages, is not limited.
 *
 * The scope must be one the site has (config/api.php); a route naming one that does not exist is a mistake, and fails loudly.
 */
class EnsureTokenScope
{
    public function __construct(private readonly Scopes $scopes) {}

    public function handle(Request $request, Closure $next, string ...$required): Response
    {
        foreach ($required as $scope) {
            $this->scopes->get($scope);
        }

        $user = $request->user();

        if ($user === null) {
            throw new AuthenticationException;
        }

        // Whoever has no token at all (which should not get this far) holds no scope, so is refused like a key without it.
        foreach ($required as $scope) {
            if ($user->tokenCan($scope)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'This API key does not have the "'.$required[0].'" scope.',
            'scope' => $required[0],
        ], 403);
    }
}
