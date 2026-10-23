<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Usage\Usage;
use App\Support\Usage\UsageStatus;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Counts a request as one use of a meter, `usage:api-calls`, and holds the account to what it may use each month (see docs/usage.md).
 * A request with nobody signed in is not counted.
 *
 * Where a route group lists it does not matter: it is in the middleware priority list (bootstrap/app.php) ahead of SubstituteBindings, so
 * it runs after authentication and the rate limits and before Laravel looks up what the address names. A request for something that
 * is not there is therefore still a use (the caller's mistake), and an account at its limit is refused, not told "not found".
 *
 * - Over the allowance, and the meter's `overage` is `block`: the request is refused with a 429 and is not counted.
 * - Otherwise it runs, and is counted unless it failed on the server's side (a 5xx, or an exception) or was told to slow down (a 429,
 *   such as a token's own quota, which is checked after the meter): neither was the caller's use.
 * - An account with an allowance is told where it stands in `X-Usage-Limit`, `X-Usage-Remaining` and `X-Usage-Resets-At`.
 *
 * It is a soft limit: two requests at the same moment can both pass when one use is left.
 */
class MeterUsage
{
    public function __construct(private readonly Usage $usage) {}

    public function handle(Request $request, Closure $next, string $meter): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $status = $this->usage->status($user, $meter);

        if ($status->blocks()) {
            return $this->refuse($status);
        }

        $response = $next($request);

        // Not a use: a failure on the server's side, or being told to slow down (a rate limit further in that stopped it).
        $code = $response->getStatusCode();
        $counted = $code < 500 && $code !== 429 && $this->count($user, $meter);

        return $this->tell($response, $status, $counted ? 1 : 0);
    }

    /**
     * Count the request. A ledger that cannot be written to must not turn a request that worked into a failure: it is reported, and the
     * use is lost.
     */
    private function count(User $user, string $meter): bool
    {
        // A request made with a session (the single page app) has a transient token, which has no id.
        $tokenId = (int) ($user->currentAccessToken()->id ?? 0);

        try {
            return $this->usage->record($user, $meter, tokenId: $tokenId > 0 ? $tokenId : null);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    private function refuse(UsageStatus $status): JsonResponse
    {
        $limit = (int) $status->allowance;

        return response()->json([
            'message' => 'You have reached this month\'s limit of '.number_format($limit).' '.Str::plural($status->meter->unit, $limit).'. It resets on '.$status->period->end->toDateString().'.',
            'meter' => $status->meter->key,
            'limit' => $limit,
            'used' => $status->used,
            'resets_at' => $status->period->end->toIso8601String(),
        ], 429, [
            'Retry-After' => max(1, (int) now()->diffInSeconds($status->period->end, true)),
            ...$this->headers($status, 0),
        ]);
    }

    /**
     * @param  int  $added  Uses this request has just added.
     */
    private function tell(Response $response, UsageStatus $status, int $added): Response
    {
        foreach ($this->headers($status, $added) as $name => $value) {
            $response->headers->set($name, (string) $value);
        }

        return $response;
    }

    /**
     * @return array<string, int|string>
     */
    private function headers(UsageStatus $status, int $added): array
    {
        if ($status->allowance === null) {
            return [];
        }

        return [
            'X-Usage-Limit' => $status->allowance,
            'X-Usage-Remaining' => max(0, $status->allowance - $status->used - $added),
            'X-Usage-Resets-At' => $status->period->end->toIso8601String(),
        ];
    }
}
