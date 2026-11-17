<?php

namespace Tests\Feature\Api;

use App\Jobs\PruneTokenLogs;
use App\Models\PersonalAccessToken;
use App\Models\TokenLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\NewAccessToken;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The log of requests made with an API key is kept for `api.token_log.retention_days`, and for ever if that is 0.
 *
 * Every request made with a key is a row, and nothing ever deleted one: the quota check read all of a busy key's history, and Admin →
 * Tokens read the whole table (130 ms and more at a million rows, see docs/api-keys.md).
 */
class PruneTokenLogsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-16 12:00:00'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function key(array $attributes = []): NewAccessToken
    {
        $key = User::factory()->create()->createToken('Integration');
        $key->accessToken->forceFill($attributes)->save();

        return $key;
    }

    /**
     * One row for a key for each age in the list (what each is old as, in words Carbon reads: "3 days").
     *
     * @param  list<string>  $ago
     * @return list<int>
     */
    private function logsFor(PersonalAccessToken $token, array $ago): array
    {
        $ids = [];

        foreach ($ago as $age) {
            $at = now()->sub($age);

            $ids[] = TokenLog::query()->insertGetId([
                'personal_access_token_id' => $token->id,
                'token_name' => $token->name,
                'route' => '/api/user',
                'method' => 'GET',
                'status' => 'success',
                'http_status' => 200,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }

        return $ids;
    }

    /**
     * @return list<int>
     */
    private function idsLeft(): array
    {
        return TokenLog::query()->orderBy('id')->pluck('id')->all();
    }

    #[Test]
    public function the_log_keeps_thirty_days_unless_told_otherwise(): void
    {
        $this->assertSame(30, config('api.token_log.retention_days'));
    }

    #[Test]
    public function nothing_is_deleted_unless_a_retention_is_set(): void
    {
        $this->logsFor($this->key()->accessToken, ['10 years']);

        config(['api.token_log.retention_days' => 0]);
        (new PruneTokenLogs)->handle();

        config(['api.token_log.retention_days' => -30]);
        (new PruneTokenLogs)->handle();

        $this->assertSame(1, TokenLog::query()->count());
    }

    #[Test]
    public function rows_older_than_the_retention_are_deleted_and_newer_ones_are_kept(): void
    {
        config(['api.token_log.retention_days' => 30]);
        [, , $exactly, $inside, $today] = $this->logsFor($this->key()->accessToken, ['31 days', '30 days 1 second', '30 days', '29 days', '1 hour']);

        (new PruneTokenLogs)->handle();

        $this->assertSame([$exactly, $inside, $today], $this->idsLeft(), 'a row exactly as old as the retention is not older than it');
    }

    #[Test]
    public function every_key_is_pruned_by_the_same_rule(): void
    {
        config(['api.token_log.retention_days' => 7]);
        $first = $this->key()->accessToken;
        $second = $this->key()->accessToken;
        $this->logsFor($first, ['8 days', '2 days']);
        $this->logsFor($second, ['9 days', '3 days', '1 day']);

        (new PruneTokenLogs)->handle();

        $this->assertSame(1, TokenLog::query()->where('personal_access_token_id', $first->id)->count());
        $this->assertSame(2, TokenLog::query()->where('personal_access_token_id', $second->id)->count());
    }

    #[Test]
    public function a_large_backlog_is_all_deleted_not_just_the_first_chunk(): void
    {
        config(['api.token_log.retention_days' => 30]);
        $key = $this->key()->accessToken;
        $at = now()->subDays(100);

        $rows = array_map(fn (int $i) => [
            'personal_access_token_id' => $key->id, 'route' => '/api/user', 'method' => 'GET', 'status' => 'success', 'created_at' => $at, 'updated_at' => $at,
        ], range(1, 5200));

        foreach (array_chunk($rows, 500) as $chunk) {
            TokenLog::query()->insert($chunk);
        }
        $this->logsFor($key, ['1 hour']);

        (new PruneTokenLogs)->handle();

        $this->assertSame(1, TokenLog::query()->count());
    }

    #[Test]
    public function a_keys_limit_is_the_same_before_and_after_a_run_even_with_a_retention_of_one_day(): void
    {
        config(['api.token_log.retention_days' => 1]);
        $key = $this->key(['daily_quota' => 3]);
        $headers = ['Authorization' => 'Bearer '.$key->plainTextToken, 'Accept' => 'application/json'];

        // Three calls made 23 hours ago use up the day's allowance of 3; a fourth, 30 hours ago, is outside the day and not counted.
        $this->logsFor($key->accessToken, ['23 hours', '23 hours', '23 hours', '30 hours']);
        $this->withHeaders($headers)->getJson('/api/user')->assertStatus(429);

        (new PruneTokenLogs)->handle();

        $this->assertCount(3, $this->idsLeft(), 'the row older than a day is gone and the three inside it are not');
        $this->app['auth']->forgetGuards();
        $this->withHeaders($headers)->getJson('/api/user')->assertStatus(429);
    }

    #[Test]
    public function the_job_is_scheduled_every_day_after_the_audit_log_one(): void
    {
        $this->artisan('schedule:list')->assertSuccessful();

        $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->description, 'PruneTokenLogs') || str_contains($event->command ?? '', 'PruneTokenLogs'));

        $this->assertCount(1, $events);
        $this->assertSame('30 2 * * *', $events->first()->expression);
    }
}
