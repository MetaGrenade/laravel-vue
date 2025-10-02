<?php

namespace Tests\Feature\Admin;

use App\Models\PersonalAccessToken;
use App\Models\TokenLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TokenLogPaginationTest extends TestCase
{
    use RefreshDatabase;

    private function signInAdmin(): User
    {
        $role = Role::firstOrCreate(['name' => 'admin']);
        $admin = User::factory()->create();
        $admin->assignRole($role);

        $this->actingAs($admin);

        return $admin;
    }

    private function createToken(User $user, string $name): PersonalAccessToken
    {
        return $user->createToken($name)->accessToken;
    }

    private function createTokenLog(PersonalAccessToken $token, array $overrides = []): TokenLog
    {
        $log = new TokenLog([
            'personal_access_token_id' => $overrides['personal_access_token_id'] ?? $token->id,
            'token_name' => $overrides['token_name'] ?? $token->name,
            'route' => $overrides['route'] ?? '/api/example',
            'method' => $overrides['method'] ?? 'GET',
            'status' => $overrides['status'] ?? 'success',
            'http_status' => $overrides['http_status'] ?? 200,
        ]);

        $createdAt = $overrides['created_at'] ?? Carbon::now();
        $updatedAt = $overrides['updated_at'] ?? $createdAt;

        $log->created_at = $createdAt;
        $log->updated_at = $updatedAt;

        $log->save();

        return $log;
    }

    public function test_token_logs_are_paginated(): void
    {
        $this->signInAdmin();

        $tokenOwner = User::factory()->create();
        $token = $this->createToken($tokenOwner, 'Primary token');

        foreach (range(1, 60) as $index) {
            $this->createTokenLog($token, [
                'route' => "/api/example/{$index}",
                'status' => $index % 2 === 0 ? 'success' : 'failed',
                'created_at' => Carbon::now()->subMinutes($index),
            ]);
        }

        $response = $this->get(route('acp.tokens.index', [
            'logs_per_page' => 25,
        ]));

        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('acp/Tokens')
            ->where('logFilters.per_page', 25)
            ->where('tokenLogs.meta.total', 60)
            ->where('tokenLogs.meta.per_page', 25)
            ->where('tokenLogs.meta.current_page', 1)
            ->where('tokenLogs.data', fn ($logs) => count($logs) === 25)
        );

        $secondPage = $this->get(route('acp.tokens.index', [
            'logs_page' => 2,
            'logs_per_page' => 25,
        ]));

        $secondPage->assertOk();

        $secondPage->assertInertia(fn (Assert $page) => $page
            ->component('acp/Tokens')
            ->where('tokenLogs.meta.current_page', 2)
            ->where('tokenLogs.meta.per_page', 25)
            ->where('logFilters.per_page', 25)
            ->where('tokenLogs.data', fn ($logs) => count($logs) === 25)
        );
    }

    public function test_token_log_filters_are_applied_and_persisted(): void
    {
        $this->signInAdmin();

        $user = User::factory()->create();
        $alphaToken = $this->createToken($user, 'Alpha Token');
        $betaToken = $this->createToken($user, 'Beta Token');

        $inRange = Carbon::now()->subDay();
        $outOfRange = Carbon::now()->subWeeks(2);

        $matchingLog = $this->createTokenLog($alphaToken, [
            'route' => '/api/alpha/success',
            'status' => 'success',
            'created_at' => $inRange,
        ]);

        $this->createTokenLog($alphaToken, [
            'route' => '/api/alpha/failed',
            'status' => 'failed',
            'created_at' => $inRange,
        ]);

        $this->createTokenLog($betaToken, [
            'route' => '/api/beta/success',
            'status' => 'success',
            'created_at' => $inRange,
        ]);

        $this->createTokenLog($alphaToken, [
            'route' => '/api/alpha/old',
            'status' => 'success',
            'created_at' => $outOfRange,
        ]);

        $dateFrom = $inRange->copy()->startOfDay()->format('Y-m-d\TH:i');
        $dateTo = $inRange->copy()->endOfDay()->format('Y-m-d\TH:i');

        $response = $this->get(route('acp.tokens.index', [
            'token' => 'Alpha',
            'status' => 'success',
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'logs_per_page' => 10,
        ]));

        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('acp/Tokens')
            ->where('logFilters.token', 'Alpha')
            ->where('logFilters.status', 'success')
            ->where('logFilters.date_from', $dateFrom)
            ->where('logFilters.date_to', $dateTo)
            ->where('logFilters.per_page', 10)
            ->where('tokenLogs.meta.total', 1)
            ->where('tokenLogs.data', fn ($logs) => count($logs) === 1 && $logs[0]['id'] === $matchingLog->id)
        );

        $secondResponse = $this->get(route('acp.tokens.index', [
            'token' => 'Alpha',
            'status' => 'success',
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'logs_page' => 2,
            'logs_per_page' => 10,
        ]));

        $secondResponse->assertOk();

        $secondResponse->assertInertia(fn (Assert $page) => $page
            ->component('acp/Tokens')
            ->where('logFilters.token', 'Alpha')
            ->where('logFilters.status', 'success')
            ->where('tokenLogs.meta.current_page', 2)
            ->where('tokenLogs.data', fn ($logs) => count($logs) === 0)
        );
    }
}
