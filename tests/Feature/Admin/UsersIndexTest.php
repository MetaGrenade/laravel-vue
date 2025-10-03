<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UsersIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_recent_activity_timestamp_is_exposed(): void
    {
        $adminRole = Role::firstOrCreate(['name' => 'admin']);

        $admin = User::factory()->create();
        $admin->assignRole($adminRole);

        $this->actingAs($admin);

        $lastActiveAt = now()->subMinutes(2)->startOfSecond();

        $recentUser = User::factory()->create([
            'last_activity_at' => $lastActiveAt,
        ]);

        $response = $this->get(route('acp.users.index'));

        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('acp/Users')
            ->where('users.data', function ($users) use ($recentUser, $lastActiveAt) {
                return collect($users)->contains(function ($user) use ($recentUser, $lastActiveAt) {
                    return $user['id'] === $recentUser->id
                        && $user['last_activity_at'] === $lastActiveAt->toIso8601String();
                });
            })
        );
    }

    public function test_search_results_are_paginated_on_server(): void
    {
        $adminRole = Role::firstOrCreate(['name' => 'admin']);

        $admin = User::factory()->create();
        $admin->assignRole($adminRole);

        $this->actingAs($admin);

        $matchingUsers = User::factory()
            ->count(5)
            ->sequence(fn ($sequence) => [
                'nickname' => "Searchable {$sequence->index}",
                'email' => "searchable-{$sequence->index}@example.com",
            ])
            ->create();

        User::factory()
            ->count(3)
            ->sequence(fn ($sequence) => [
                'nickname' => "Other {$sequence->index}",
                'email' => "other-{$sequence->index}@example.com",
            ])
            ->create();

        $perPage = 2;
        $search = 'Searchable';

        $response = $this->get(route('acp.users.index', [
            'search' => $search,
            'page' => 2,
            'per_page' => $perPage,
        ]));

        $response->assertOk();

        $expectedUsers = $matchingUsers
            ->sortByDesc('created_at')
            ->values()
            ->slice($perPage, $perPage)
            ->values();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('acp/Users')
            ->where('filters.search', $search)
            ->where('users.meta.current_page', 2)
            ->where('users.meta.per_page', $perPage)
            ->where('users.meta.total', $matchingUsers->count())
            ->where('users.data', function ($users) use ($expectedUsers) {
                if (count($users) !== $expectedUsers->count()) {
                    return false;
                }

                $ids = collect($users)->pluck('id');

                return $ids->values()->all() === $expectedUsers->pluck('id')->values()->all();
            })
        );
    }

    public function test_role_filter_limits_results_to_selected_role(): void
    {
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $moderatorRole = Role::firstOrCreate(['name' => 'moderator']);
        $memberRole = Role::firstOrCreate(['name' => 'member']);

        $admin = User::factory()->create();
        $admin->assignRole($adminRole);

        $this->actingAs($admin);

        $moderator = User::factory()->create();
        $moderator->assignRole($moderatorRole);

        $member = User::factory()->create();
        $member->assignRole($memberRole);

        $response = $this->get(route('acp.users.index', [
            'role' => $moderatorRole->name,
        ]));

        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('acp/Users')
            ->where('filters.role', $moderatorRole->name)
            ->where('users.meta.total', 1)
            ->where('users.data', function ($users) use ($moderator, $member) {
                return count($users) === 1
                    && $users[0]['id'] === $moderator->id
                    && $users[0]['id'] !== $member->id;
            })
        );
    }

    public function test_verification_filter_limits_results_to_unverified_users(): void
    {
        $adminRole = Role::firstOrCreate(['name' => 'admin']);

        $admin = User::factory()->create();
        $admin->assignRole($adminRole);

        $this->actingAs($admin);

        $verified = User::factory()->create();
        $unverified = User::factory()->unverified()->create();

        $response = $this->get(route('acp.users.index', [
            'verification' => 'unverified',
        ]));

        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('acp/Users')
            ->where('filters.verification', 'unverified')
            ->where('users.meta.total', 1)
            ->where('users.data', function ($users) use ($unverified, $verified) {
                return count($users) === 1
                    && $users[0]['id'] === $unverified->id
                    && $users[0]['id'] !== $verified->id;
            })
        );
    }

    public function test_banned_filter_limits_results_to_banned_users(): void
    {
        $adminRole = Role::firstOrCreate(['name' => 'admin']);

        $admin = User::factory()->create();
        $admin->assignRole($adminRole);

        $this->actingAs($admin);

        $bannedUser = User::factory()->create(['is_banned' => true]);
        $activeUser = User::factory()->create(['is_banned' => false]);

        $response = $this->get(route('acp.users.index', [
            'banned' => 'banned',
        ]));

        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('acp/Users')
            ->where('filters.banned', 'banned')
            ->where('users.meta.total', 1)
            ->where('users.data', function ($users) use ($bannedUser, $activeUser) {
                return count($users) === 1
                    && $users[0]['id'] === $bannedUser->id
                    && $users[0]['id'] !== $activeUser->id;
            })
        );
    }

    public function test_activity_window_filter_limits_results_to_recently_active_users(): void
    {
        $adminRole = Role::firstOrCreate(['name' => 'admin']);

        $admin = User::factory()->create();
        $admin->assignRole($adminRole);

        $this->actingAs($admin);

        $now = now()->startOfMinute();
        Carbon::setTestNow($now);

        $recentUser = User::factory()->create([
            'last_activity_at' => $now->copy()->subMinutes(3),
        ]);

        $staleUser = User::factory()->create([
            'last_activity_at' => $now->copy()->subHours(2),
        ]);

        $response = $this->get(route('acp.users.index', [
            'activity_window' => 5,
        ]));

        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('acp/Users')
            ->where('filters.activity_window', 5)
            ->where('users.meta.total', 2)
            ->where('users.data', function ($users) use ($admin, $recentUser, $staleUser) {
                if (count($users) !== 2) {
                    return false;
                }

                $ids = collect($users)->pluck('id');

                return $ids->contains($admin->id)
                    && $ids->contains($recentUser->id)
                    && ! $ids->contains($staleUser->id);
            })
        );

        Carbon::setTestNow();
    }
}
