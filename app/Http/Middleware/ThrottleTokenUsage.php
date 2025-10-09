<?php

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use App\Models\TokenLog;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class ThrottleTokenUsage
{
    private const QUOTA_WINDOWS = [
        'hourly_quota' => 3600,
        'daily_quota' => 86400,
    ];

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->resolveToken($request);

        if (! $token) {
            return $next($request);
        }

        $breach = $this->detectQuotaBreach($token);

        if ($breach instanceof JsonResponse) {
            return $breach;
        }

        $response = $next($request);

        foreach (array_keys(self::QUOTA_WINDOWS) as $attribute) {
            Cache::forget($this->usageCacheKey($token->id, $attribute));
            Cache::forget($this->retryCacheKey($token->id, $attribute));
        }

        return $response;
    }

    private function resolveToken(Request $request): ?PersonalAccessToken
    {
        $user = $request->user();

        if (! $user || ! method_exists($user, 'currentAccessToken')) {
            return null;
        }

        /** @var PersonalAccessToken|null $token */
        $token = $user->currentAccessToken();

        return $token;
    }

    private function detectQuotaBreach(PersonalAccessToken $token): ?JsonResponse
    {
        foreach (self::QUOTA_WINDOWS as $attribute => $window) {
            $limit = $token->{$attribute};

            if (! $limit) {
                continue;
            }

            $usage = $this->usageCount($token->id, $attribute, $window);

            if ($usage >= $limit) {
                return $this->buildThrottleResponse($token->id, $attribute, $limit, $usage, $window);
            }
        }

        return null;
    }

    private function usageCount(int $tokenId, string $attribute, int $windowSeconds): int
    {
        $cacheKey = $this->usageCacheKey($tokenId, $attribute);

        return Cache::remember(
            $cacheKey,
            now()->addSeconds(min($windowSeconds, 60)),
            function () use ($tokenId, $windowSeconds) {
                return TokenLog::query()
                    ->where('personal_access_token_id', $tokenId)
                    ->where('created_at', '>=', now()->subSeconds($windowSeconds))
                    ->count();
            }
        );
    }

    private function retryAfterSeconds(int $tokenId, string $attribute, int $windowSeconds): int
    {
        $cacheKey = $this->retryCacheKey($tokenId, $attribute);

        return Cache::remember(
            $cacheKey,
            now()->addSeconds(min($windowSeconds, 60)),
            function () use ($tokenId, $windowSeconds) {
                $oldestLog = TokenLog::query()
                    ->where('personal_access_token_id', $tokenId)
                    ->where('created_at', '>=', now()->subSeconds($windowSeconds))
                    ->oldest()
                    ->first();

                if (! $oldestLog || ! $oldestLog->created_at) {
                    return $windowSeconds;
                }

                $retryAt = $oldestLog->created_at->copy()->addSeconds($windowSeconds);

                if ($retryAt->lessThanOrEqualTo(now())) {
                    return 1;
                }

                return max(1, $retryAt->diffInSeconds(now()));
            }
        );
    }

    private function buildThrottleResponse(
        int $tokenId,
        string $attribute,
        int $limit,
        int $usage,
        int $windowSeconds
    ): JsonResponse {
        $period = $attribute === 'hourly_quota' ? 'hour' : 'day';
        $retryAfter = $this->retryAfterSeconds($tokenId, $attribute, $windowSeconds);

        $response = response()->json([
            'message' => 'API quota exceeded for this token.',
            'limit' => $limit,
            'usage' => $usage,
            'period' => $period,
            'retry_after' => $retryAfter,
        ], Response::HTTP_TOO_MANY_REQUESTS);

        $response->headers->set('Retry-After', (string) $retryAfter);

        return $response;
    }

    private function usageCacheKey(int $tokenId, string $attribute): string
    {
        return sprintf('tokens:usage:%d:%s', $tokenId, $attribute);
    }

    private function retryCacheKey(int $tokenId, string $attribute): string
    {
        return sprintf('tokens:retry:%d:%s', $tokenId, $attribute);
    }
}
