<?php

namespace Tests\Feature\Admin;

use App\Models\Blog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BlogsIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function actingAsAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        return $admin;
    }

    public function test_search_results_are_paginated_on_server(): void
    {
        $this->actingAsAdmin();

        Carbon::setTestNow(now());

        $matchingBlogs = Blog::factory()
            ->count(5)
            ->sequence(fn ($sequence) => [
                'title' => "Searchable {$sequence->index}",
                'slug' => "searchable-{$sequence->index}",
                'created_at' => now()->subMinutes($sequence->index),
            ])
            ->create();

        Blog::factory()
            ->count(3)
            ->sequence(fn ($sequence) => [
                'title' => "Other {$sequence->index}",
                'slug' => "other-{$sequence->index}",
                'created_at' => now()->subMinutes(10 + $sequence->index),
            ])
            ->create();

        $perPage = 2;
        $search = 'Searchable';

        $response = $this->get(route('acp.blogs.index', [
            'search' => $search,
            'page' => 2,
            'per_page' => $perPage,
        ]));

        $response->assertOk();

        $expectedBlogs = $matchingBlogs
            ->sortByDesc('created_at')
            ->values()
            ->slice($perPage, $perPage)
            ->values();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('acp/Blogs')
            ->where('filters.search', $search)
            ->where('filters.status', null)
            ->where('blogs.meta.current_page', 2)
            ->where('blogs.meta.per_page', $perPage)
            ->where('blogs.meta.total', $matchingBlogs->count())
            ->where('blogs.data', function ($blogs) use ($expectedBlogs) {
                if (count($blogs) !== $expectedBlogs->count()) {
                    return false;
                }

                $ids = collect($blogs)->pluck('id');

                return $ids->values()->all() === $expectedBlogs->pluck('id')->values()->all();
            })
        );
    }

    public function test_status_filters_apply_before_pagination(): void
    {
        $this->actingAsAdmin();

        Carbon::setTestNow(now());

        $publishedBlogs = Blog::factory()
            ->published()
            ->count(4)
            ->sequence(fn ($sequence) => [
                'title' => "Published {$sequence->index}",
                'slug' => "published-{$sequence->index}",
                'created_at' => now()->subMinutes($sequence->index),
            ])
            ->create();

        Blog::factory()
            ->count(4)
            ->sequence(fn ($sequence) => [
                'title' => "Draft {$sequence->index}",
                'slug' => "draft-{$sequence->index}",
                'status' => 'draft',
                'created_at' => now()->subMinutes(10 + $sequence->index),
            ])
            ->create();

        $perPage = 3;

        $response = $this->get(route('acp.blogs.index', [
            'status' => ['published'],
            'page' => 2,
            'per_page' => $perPage,
        ]));

        $response->assertOk();

        $expectedBlogs = $publishedBlogs
            ->sortByDesc('created_at')
            ->values()
            ->slice($perPage, $perPage)
            ->values();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('acp/Blogs')
            ->where('filters.search', null)
            ->where('filters.status', ['published'])
            ->where('blogs.meta.current_page', 2)
            ->where('blogs.meta.per_page', $perPage)
            ->where('blogs.meta.total', $publishedBlogs->count())
            ->where('blogs.data', function ($blogs) use ($expectedBlogs) {
                if (count($blogs) !== $expectedBlogs->count()) {
                    return false;
                }

                return collect($blogs)->every(fn ($blog) => $blog['status'] === 'published')
                    && collect($blogs)->pluck('id')->values()->all() === $expectedBlogs->pluck('id')->values()->all();
            })
        );
    }
}

