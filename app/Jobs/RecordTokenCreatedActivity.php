<?php

namespace App\Jobs;

use App\Jobs\Concerns\NotifiesOperationsOnFailure;
use App\Models\PersonalAccessToken;
use App\Models\TokenLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecordTokenCreatedActivity implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use NotifiesOperationsOnFailure;

    public int $tries = 3;

    public int $backoff = 20;

    public string $queue = 'logging';

    public function __construct(
        public int $tokenId,
        public int $actorId,
    ) {
    }

    public function handle(): void
    {
        $token = PersonalAccessToken::query()->find($this->tokenId);

        if (! $token) {
            return;
        }

        TokenLog::create([
            'personal_access_token_id' => $token->id,
            'token_name' => $token->name,
            'route' => 'acp.tokens.store',
            'method' => 'POST',
            'status' => 'created',
            'request_payload' => [
                'created_by' => $this->actorId,
                'tokenable_id' => $token->tokenable_id,
                'tokenable_type' => $token->tokenable_type,
                'abilities' => $token->abilities,
                'hourly_quota' => $token->hourly_quota,
                'daily_quota' => $token->daily_quota,
            ],
        ]);

        Cache::increment('metrics:tokens.created');

        Log::info('Recorded personal access token creation.', [
            'token_id' => $token->id,
            'actor_id' => $this->actorId,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $this->notifyOfFailure($exception, [
            'job' => static::class,
            'reference' => sprintf('token_id=%d', $this->tokenId),
            'message' => 'Failed to record token creation activity.',
        ]);
    }
}
