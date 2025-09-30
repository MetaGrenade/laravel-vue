<?php

namespace App\Http\Middleware;

use App\Models\TokenLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class LogTokenActivity
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->logRequest($request, $startedAt, null, $exception);

            throw $exception;
        }

        $this->logRequest($request, $startedAt, $response);

        return $response;
    }

    /**
     * Persist the API request details when a personal access token was used.
     */
    protected function logRequest(Request $request, float $startedAt, ?Response $response = null, ?Throwable $exception = null): void
    {
        $user = $request->user();
        $token = method_exists($user, 'currentAccessToken') ? $user?->currentAccessToken() : null;

        if (! $token) {
            return;
        }

        $responseStatus = $response?->getStatusCode();
        $status = 'success';

        if ($exception) {
            $status = 'failed';
            $responseStatus = $this->resolveExceptionStatus($exception) ?? $responseStatus ?? 500;
        } elseif ($responseStatus && $responseStatus >= 400) {
            $status = 'failed';
        }

        $requestPayload = $this->sanitizePayload($request->all());

        TokenLog::create([
            'personal_access_token_id' => $token->id,
            'token_name' => $token->name,
            'route' => '/' . ltrim($request->path(), '/'),
            'method' => $request->method(),
            'status' => $status,
            'http_status' => $responseStatus,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'request_payload' => $requestPayload ?: null,
            'response_summary' => $this->extractResponseSummary($response),
            'response_time_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'error_message' => $exception?->getMessage(),
        ]);
    }

    protected function sanitizePayload(array $payload): array
    {
        $hiddenKeys = ['password', 'password_confirmation', 'token'];

        return collect($payload)
            ->except($hiddenKeys)
            ->map(function ($value) {
                if (is_array($value)) {
                    return $this->sanitizePayload($value);
                }

                if (is_object($value)) {
                    return (string) $value;
                }

                if (is_string($value)) {
                    return Str::limit($value, 500);
                }

                return $value;
            })
            ->all();
    }

    protected function extractResponseSummary(?Response $response): ?array
    {
        if (! $response) {
            return null;
        }

        $contentType = $response->headers->get('Content-Type');

        if ($contentType && str_contains($contentType, 'application/json')) {
            $content = $response->getContent();

            $decoded = json_decode($content, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return null;
    }

    protected function resolveExceptionStatus(Throwable $exception): ?int
    {
        if (method_exists($exception, 'getStatusCode')) {
            return $exception->getStatusCode();
        }

        $code = $exception->getCode();

        return is_int($code) && $code >= 100 && $code < 600
            ? $code
            : null;
    }
}
