<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTokenRequest;
use App\Http\Requests\Admin\UpdateTokenRequest;
use App\Models\PersonalAccessToken;
use App\Models\TokenLog;
use App\Models\User;
use App\Support\Localization\DateFormatter;
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

        $formatter = DateFormatter::for($request->user());

        $tokenQuery = PersonalAccessToken::query();

        $hourlyUsage = TokenLog::query()
            ->selectRaw('personal_access_token_id, COUNT(*) as aggregate')
            ->where('created_at', '>=', now()->subHour())
            ->groupBy('personal_access_token_id')
            ->pluck('aggregate', 'personal_access_token_id');

        $dailyUsage = TokenLog::query()
            ->selectRaw('personal_access_token_id, COUNT(*) as aggregate')
            ->where('created_at', '>=', now()->subDay())
            ->groupBy('personal_access_token_id')
            ->pluck('aggregate', 'personal_access_token_id');

        // eager‐load owner
        $tokens = (clone $tokenQuery)
            ->with('tokenable:id,nickname,email')
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();

        $tokenItems = $tokens->getCollection()
            ->map(function (PersonalAccessToken $token) use ($formatter) {
                $user = $token->tokenable;

                return [
                    'id' => $token->id,
                    'name' => $token->name,
                    'created_at' => $formatter->iso($token->created_at),
                    'last_used_at' => $formatter->iso($token->last_used_at),
                    'expires_at' => $formatter->iso($token->expires_at),
                    'revoked_at' => $formatter->iso($token->revoked_at),
                    'abilities' => $token->abilities ?? [],
                    'hourly_quota' => $token->hourly_quota,
                    'daily_quota' => $token->daily_quota,
                    'hourly_usage' => (int) ($hourlyUsage[$token->id] ?? 0),
                    'daily_usage' => (int) ($dailyUsage[$token->id] ?? 0),
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
            ->map(function (TokenLog $log) use ($formatter) {
                $tokenName = $log->token_name ?? optional($log->token)->name;

                return [
                    'id' => $log->id,
                    'token_name' => $tokenName,
                    'api_route' => $log->route,
                    'method' => $log->method,
                    'status' => $log->status,
                    'http_status' => $log->http_status,
                    'timestamp' => $formatter->iso($log->created_at),
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

        $newToken->accessToken->forceFill([
            'hourly_quota' => $data['hourly_quota'] ?? null,
            'daily_quota' => $data['daily_quota'] ?? null,
        ])->save();

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
            'hourly_quota' => $data['hourly_quota'] ?? null,
            'daily_quota' => $data['daily_quota'] ?? null,
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

        $formatter = DateFormatter::for(request()->user());

        return Inertia::render('acp/TokenLogView', [
            'log' => [
                'id' => $tokenLog->id,
                'token_name' => $tokenName,
                'api_route' => $tokenLog->route,
                'method' => $tokenLog->method,
                'status' => $tokenLog->status,
                'http_status' => $tokenLog->http_status,
                'timestamp' => $formatter->iso($tokenLog->created_at),
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
