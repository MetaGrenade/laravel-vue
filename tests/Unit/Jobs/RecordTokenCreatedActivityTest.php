<?php

namespace Tests\Unit\Jobs;

use App\Jobs\RecordTokenCreatedActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordTokenCreatedActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_creates_token_log_and_increments_metric(): void
    {
        $tokenOwner = User::factory()->create();
        $actor = User::factory()->create();

        $newToken = $tokenOwner->createToken('Example token');
        cache()->forget('metrics:tokens.created');

        $job = new RecordTokenCreatedActivity($newToken->accessToken->id, $actor->id);
        $job->handle();

        $this->assertDatabaseHas('token_logs', [
            'personal_access_token_id' => $newToken->accessToken->id,
            'status' => 'created',
            'route' => 'acp.tokens.store',
        ]);

        $this->assertSame(1, cache()->get('metrics:tokens.created'));
    }
}
