<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTokenRequest;
use App\Http\Requests\Admin\UpdateTokenRequest;
use App\Models\PersonalAccessToken;
use App\Models\TokenLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class TokenController extends Controller
{
    use InteractsWithInertiaPagination;

    private const TOKEN_LOGS_PER_PAGE = 25;

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
                    'abilities' => $token->abilities ?? [],
                    'user' => $user ? [
                        'id' => $user->id,
                        'nickname' => $user->nickname,
                        'email' => $user->email,
                    ] : null,
                ];
            })
            ->values()
            ->all();

        $tokenStats = [
            'total' => $tokens->total(),
            'active' => (clone $tokenQuery)->whereNull('revoked_at')->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })->count(),
            'expired' => (clone $tokenQuery)->whereNull('revoked_at')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now())->count(),
            'revoked' => (clone $tokenQuery)->whereNotNull('revoked_at')->count(),
        ];

        $userList = User::select('id','nickname','email')->get();

        $tokenLogQuery = TokenLog::query()
            ->with('token:id,name')
            ->orderByDesc('created_at');

        $tokenFilter = trim((string) $request->query('token', ''));
        if ($tokenFilter !== '') {
            $tokenLogQuery->where(function ($query) use ($tokenFilter) {
                $query->where('token_name', 'like', "%{$tokenFilter}%")
                    ->orWhereHas('token', function ($innerQuery) use ($tokenFilter) {
                        $innerQuery->where('name', 'like', "%{$tokenFilter}%");
                    });
            });
        }

        $statusFilter = trim((string) $request->query('status', ''));
        if ($statusFilter !== '') {
            $tokenLogQuery->where('status', $statusFilter);
        }

        $dateFrom = $request->query('date_from');
        if (! empty($dateFrom)) {
            $parsedFrom = rescue(fn () => Carbon::parse($dateFrom), null, false);

            if ($parsedFrom) {
                $tokenLogQuery->where('created_at', '>=', $parsedFrom->startOfDay());
            }
        }

        $dateTo = $request->query('date_to');
        if (! empty($dateTo)) {
            $parsedTo = rescue(fn () => Carbon::parse($dateTo), null, false);

            if ($parsedTo) {
                $tokenLogQuery->where('created_at', '<=', $parsedTo->endOfDay());
            }
        }

        $logsPerPage = (int) $request->query('logs_per_page', self::TOKEN_LOGS_PER_PAGE);
        $logsPerPage = max(1, min($logsPerPage, 100));

        $tokenLogs = $tokenLogQuery
            ->paginate($logsPerPage, ['*'], 'logs_page')
            ->withQueryString();

        $tokenLogItems = $tokenLogs->getCollection()
            ->map(function (TokenLog $log) {
                $tokenName = $log->token_name ?? optional($log->token)->name;

                return [
                    'id' => $log->id,
                    'token_name' => $tokenName,
                    'api_route' => $log->route,
                    'method' => $log->method,
                    'status' => $log->status,
                    'http_status' => $log->http_status,
                    'timestamp' => optional($log->created_at)->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        return inertia('acp/Tokens', [
            'tokens' => array_merge([
                'data' => $tokenItems,
            ], $this->inertiaPagination($tokens)),
            'tokenStats' => $tokenStats,
            'userList' => $userList,
            'tokenLogs' => array_merge([
                'data' => $tokenLogItems,
            ], $this->inertiaPagination($tokenLogs)),
            'logFilters' => [
                'token' => $tokenFilter,
                'status' => $statusFilter,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'per_page' => $logsPerPage,
            ],
        ]);
    }

    /**
     * Create a new personal access token.
     */
    public function store(StoreTokenRequest $request)
    {
        $data = $request->validated();

        $user = User::findOrFail($data['user_id']);

        $newToken = $user->createToken(
            $data['name'],
            $data['abilities'] ?? ['*'],
            $data['expires_at'] ? Carbon::parse($data['expires_at']) : null
        );

        return redirect()->route('acp.tokens.index')
            ->with('success', 'Token created.')
            ->with('plain_text_token', $newToken->plainTextToken);
    }

    /**
     * Update a token's metadata.
     */
    public function update(UpdateTokenRequest $request, PersonalAccessToken $token)
    {
        $data = $request->validated();

        $token->forceFill([
            'name' => $data['name'],
            'abilities' => $data['abilities'] ?? [],
            'expires_at' => $data['expires_at'] ? Carbon::parse($data['expires_at']) : null,
        ]);

        if ($data['clear_revocation'] ?? false) {
            $token->revoked_at = null;
        }

        $token->save();

        return redirect()->route('acp.tokens.index')
            ->with('success', 'Token updated.');
    }

    /**
     * Revoke a token without deleting it.
     */
    public function revoke(Request $request, PersonalAccessToken $token)
    {
        abort_unless($request->user()->can('tokens.acp.edit'), 403);

        if (is_null($token->revoked_at)) {
            $token->forceFill([
                'revoked_at' => now(),
            ])->save();
        }

        return redirect()->route('acp.tokens.index')
            ->with('success', 'Token revoked.');
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

    public function showLog(TokenLog $tokenLog): Response
    {
        $tokenLog->loadMissing('token:id,name');

        $tokenName = $tokenLog->token_name ?? optional($tokenLog->token)->name;

        return Inertia::render('acp/TokenLogView', [
            'log' => [
                'id' => $tokenLog->id,
                'token_name' => $tokenName,
                'api_route' => $tokenLog->route,
                'method' => $tokenLog->method,
                'status' => $tokenLog->status,
                'http_status' => $tokenLog->http_status,
                'timestamp' => optional($tokenLog->created_at)->toIso8601String(),
                'ip' => $tokenLog->ip_address,
                'response_time_ms' => $tokenLog->response_time_ms,
                'request_payload' => $tokenLog->request_payload,
                'response_summary' => $tokenLog->response_summary,
                'user_agent' => $tokenLog->user_agent,
                'error_message' => $tokenLog->error_message,
            ],
        ]);
    }
}
