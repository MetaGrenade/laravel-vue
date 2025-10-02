<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
