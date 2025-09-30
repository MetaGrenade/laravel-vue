<?php

namespace Database\Seeders;

use App\Models\TokenLog;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class TokenLogDemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $now = Carbon::now();
            $password = Hash::make('password');

            $admin = User::updateOrCreate(
                ['email' => 'token-log-admin@example.com'],
                [
                    'nickname' => 'Token Log Admin',
                    'password' => $password,
                    'email_verified_at' => $now->copy()->subDays(14),
                    'last_activity_at' => $now->copy()->subDay(),
                ]
            );

            $admin->forceFill([
                'created_at' => $now->copy()->subMonths(2),
                'updated_at' => $now,
            ])->saveQuietly();

            $serviceUser = User::updateOrCreate(
                ['email' => 'token-log-service@example.com'],
                [
                    'nickname' => 'Token Log Service',
                    'password' => $password,
                    'email_verified_at' => $now->copy()->subDays(30),
                    'last_activity_at' => $now->copy()->subHours(6),
                ]
            );

            $serviceUser->forceFill([
                'created_at' => $now->copy()->subMonths(6),
                'updated_at' => $now->copy()->subHours(2),
            ])->saveQuietly();

            $tokenDefinitions = [
                [
                    'key' => 'admin-web',
                    'user' => $admin,
                    'name' => 'Admin Dashboard Token',
                    'abilities' => ['tokens:read', 'tokens:manage'],
                    'created_at' => $now->copy()->subDays(40),
                    'last_used_at' => $now->copy()->subMinutes(50),
                ],
                [
                    'key' => 'service-webhook',
                    'user' => $serviceUser,
                    'name' => 'Automation Webhook Token',
                    'abilities' => ['webhooks:send', 'tokens:read'],
                    'created_at' => $now->copy()->subDays(9),
                    'last_used_at' => $now->copy()->subMinutes(5),
                ],
            ];

            $tokens = collect($tokenDefinitions)->mapWithKeys(function (array $definition) {
                $tokenValue = hash('sha256', 'demo-token-'.$definition['key']);

                $token = PersonalAccessToken::updateOrCreate(
                    ['token' => $tokenValue],
                    [
                        'tokenable_type' => User::class,
                        'tokenable_id' => $definition['user']->id,
                        'name' => $definition['name'],
                        'abilities' => json_encode($definition['abilities']),
                        'last_used_at' => $definition['last_used_at'],
                    ]
                );

                $token->forceFill([
                    'created_at' => $definition['created_at'],
                    'updated_at' => $definition['last_used_at'] ?? $definition['created_at'],
                ])->saveQuietly();

                return [$definition['key'] => $token];
            });

            $logDefinitions = [
                [
                    'id' => 900001,
                    'token_key' => 'admin-web',
                    'route' => 'api/admin/tokens',
                    'method' => 'GET',
                    'status' => 'success',
                    'http_status' => 200,
                    'ip_address' => '203.0.113.7',
                    'user_agent' => 'Laravel HTTP Client/10.x',
                    'request_payload' => [],
                    'response_summary' => ['count' => 23],
                    'response_time_ms' => 118,
                    'created_at' => $now->copy()->subMinutes(45),
                ],
                [
                    'id' => 900002,
                    'token_key' => 'admin-web',
                    'route' => 'api/admin/tokens/42/revoke',
                    'method' => 'DELETE',
                    'status' => 'success',
                    'http_status' => 204,
                    'ip_address' => '203.0.113.7',
                    'user_agent' => 'Laravel HTTP Client/10.x',
                    'request_payload' => ['token_id' => 42],
                    'response_summary' => ['message' => 'Revoked'],
                    'response_time_ms' => 86,
                    'created_at' => $now->copy()->subMinutes(32),
                ],
                [
                    'id' => 900003,
                    'token_key' => 'service-webhook',
                    'route' => 'api/webhooks/token-rotations',
                    'method' => 'POST',
                    'status' => 'success',
                    'http_status' => 201,
                    'ip_address' => '198.51.100.24',
                    'user_agent' => 'Acme Webhook Client/2.4',
                    'request_payload' => ['rotation_id' => Str::uuid()->toString()],
                    'response_summary' => ['accepted' => true],
                    'response_time_ms' => 153,
                    'created_at' => $now->copy()->subMinutes(14),
                ],
                [
                    'id' => 900004,
                    'token_key' => 'service-webhook',
                    'route' => 'api/webhooks/token-rotations',
                    'method' => 'POST',
                    'status' => 'error',
                    'http_status' => 422,
                    'ip_address' => '198.51.100.24',
                    'user_agent' => 'Acme Webhook Client/2.4',
                    'request_payload' => ['rotation_id' => Str::uuid()->toString()],
                    'response_summary' => ['accepted' => false],
                    'response_time_ms' => 210,
                    'error_message' => 'Token rotation payload failed validation.',
                    'created_at' => $now->copy()->subMinutes(4),
                ],
            ];

            foreach ($logDefinitions as $definition) {
                $token = $tokens->get($definition['token_key']);

                if (! $token) {
                    continue;
                }

                $log = TokenLog::find($definition['id']) ?? new TokenLog();

                $log->forceFill([
                    'id' => $definition['id'],
                    'personal_access_token_id' => $token->id,
                    'token_name' => $token->name,
                    'route' => $definition['route'],
                    'method' => $definition['method'],
                    'status' => $definition['status'],
                    'http_status' => $definition['http_status'],
                    'ip_address' => $definition['ip_address'],
                    'user_agent' => $definition['user_agent'],
                    'request_payload' => $definition['request_payload'],
                    'response_summary' => $definition['response_summary'],
                    'response_time_ms' => $definition['response_time_ms'],
                    'error_message' => $definition['error_message'] ?? null,
                    'created_at' => $definition['created_at'],
                    'updated_at' => $definition['created_at'],
                ])->save();
            }
        });

        $this->command?->info('Token log demo data seeded. Check the ACP token activity views to verify.');
    }
}
