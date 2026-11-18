<?php

namespace Tests\Feature\Blog;

use App\Jobs\PublishDueBlogs;
use App\Models\Blog;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Scout\EngineManager;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\KeepingSearchEngine;
use Tests\TestCase;

/**
 * A scheduled post goes live when its time comes. It used to when somebody next opened the blog (or the posts in the admin area): until
 * then it stayed `scheduled`, and with Meilisearch out of the search, because a post that is still in the future is not in the index and
 * nothing else said that the time had come (docs/search-index.md).
 */
class PublishDueBlogsTest extends TestCase
{
    use RefreshDatabase;

    private KeepingSearchEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = $engine = new KeepingSearchEngine;
        $this->app->make(EngineManager::class)->extend('keeping', fn () => $engine);
        config(['scout.driver' => 'keeping', 'scout.queue' => false]);
    }

    /**
     * A scheduled post that is due and that the index has not been told about, which is what a post is when its time comes (it was saved
     * while it was still in the future). One saved already due is sent as it is saved, so these are made without the search.
     */
    private function due(string $title, ?Carbon $at = null, ?User $author = null): Blog
    {
        return Blog::withoutSyncingToSearch(fn () => Blog::factory()->create([
            'title' => $title,
            'status' => 'scheduled',
            'scheduled_for' => $at ?? now()->subMinutes(5),
            'published_at' => null,
        ] + ($author === null ? [] : ['user_id' => $author->id])));
    }

    #[Test]
    public function the_job_publishes_a_post_whose_time_has_come_as_of_the_moment_it_was_scheduled_for(): void
    {
        $at = now()->subHours(3)->startOfSecond();
        $blog = $this->due('Due post', $at);

        (new PublishDueBlogs)->handle();

        $blog->refresh();

        $this->assertSame('published', $blog->status);
        $this->assertTrue($at->equalTo($blog->published_at));
        $this->assertNull($blog->scheduled_for);
    }

    #[Test]
    public function it_leaves_alone_everything_that_is_not_a_scheduled_post_that_is_due(): void
    {
        $future = Blog::factory()->scheduled()->create(['title' => 'Future post']);
        $draft = Blog::factory()->create(['title' => 'Draft post', 'status' => 'draft', 'scheduled_for' => now()->subHour()]);
        $archived = Blog::factory()->archived()->create(['title' => 'Archived post']);
        $published = Blog::factory()->published()->create(['title' => 'Published post', 'published_at' => now()->subDay()]);
        $publishedAt = $published->fresh()->published_at;

        (new PublishDueBlogs)->handle();

        $this->assertSame('scheduled', $future->fresh()->status);
        $this->assertNotNull($future->fresh()->scheduled_for);
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertNull($draft->fresh()->published_at);
        $this->assertSame('archived', $archived->fresh()->status);
        $this->assertTrue($publishedAt->equalTo($published->fresh()->published_at));
    }

    #[Test]
    public function a_post_that_is_due_this_second_is_published_and_one_a_second_from_now_is_not(): void
    {
        $this->travelTo(Carbon::parse('2026-11-18 12:00:00'));

        $now = $this->due('Due now', now());
        $soon = $this->due('Due soon', now()->addSecond());

        $this->assertSame(1, Blog::publishDue());

        $this->assertSame('published', $now->fresh()->status);
        $this->assertSame('scheduled', $soon->fresh()->status);
    }

    #[Test]
    public function one_post_is_published_only_when_it_is_scheduled_and_its_time_has_come(): void
    {
        $due = $this->due('Due post');
        $future = Blog::factory()->scheduled()->create();
        $draft = Blog::factory()->create(['status' => 'draft', 'scheduled_for' => now()->subHour(), 'published_at' => null]);
        $archived = Blog::factory()->archived()->create(['scheduled_for' => now()->subHour()]);
        $published = Blog::factory()->published()->create();
        $noTime = Blog::factory()->create(['status' => 'scheduled', 'scheduled_for' => null, 'published_at' => null]);

        $this->assertTrue($due->publishIfDue());
        $this->assertFalse($future->publishIfDue());
        $this->assertFalse($draft->publishIfDue());
        $this->assertFalse($archived->publishIfDue());
        $this->assertFalse($published->publishIfDue());
        $this->assertFalse($noTime->publishIfDue());

        $this->assertSame('published', $due->fresh()->status);
        $this->assertSame('scheduled', $future->fresh()->status);
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame('archived', $archived->fresh()->status);
        $this->assertSame('scheduled', $noTime->fresh()->status);

        // Asked again, it has nothing left to do.
        $this->assertFalse($due->publishIfDue());
    }

    #[Test]
    public function a_scheduled_post_with_no_time_is_left_as_it_is(): void
    {
        $blog = Blog::factory()->create(['status' => 'scheduled', 'scheduled_for' => null, 'published_at' => null]);

        $this->assertSame(0, Blog::publishDue());

        $this->assertSame('scheduled', $blog->fresh()->status);
    }

    #[Test]
    public function it_says_how_many_it_published_and_a_second_run_finds_nothing(): void
    {
        $this->due('One');
        $this->due('Two');
        Blog::factory()->scheduled()->create(['title' => 'Three']);

        $this->assertSame(2, Blog::publishDue());
        $this->assertSame(0, Blog::publishDue());
    }

    #[Test]
    public function every_due_post_is_published_however_many_there_are(): void
    {
        $author = User::factory()->create();

        for ($i = 0; $i < 250; $i++) {
            $this->due('Due '.$i, now()->subMinutes(10), $author);
        }

        $this->assertSame(250, Blog::publishDue());

        $this->assertSame(0, Blog::query()->where('status', 'scheduled')->count());
        $this->assertSame(250, Blog::query()->where('status', 'published')->count());
    }

    #[Test]
    public function the_due_posts_are_read_a_hundred_at_a_time_and_no_other_is_read(): void
    {
        $author = User::factory()->create();

        for ($i = 0; $i < 250; $i++) {
            $this->due('Due '.$i, null, $author);
        }

        $reads = 0;
        DB::listen(function ($query) use (&$reads): void {
            if (str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'scheduled_for')) {
                $reads++;
            }
        });

        $this->assertSame(250, Blog::publishDue());
        $this->assertSame(3, $reads, '250 posts are 100, 100 and 50: not all of them at once.');

        // A site that schedules a lot reads the ones that are not due yet every minute: it must not load them.
        for ($i = 0; $i < 150; $i++) {
            $this->due('Next '.$i, now()->addDay(), $author);
        }
        $reads = 0;

        $this->assertSame(0, Blog::publishDue());
        $this->assertSame(1, $reads);
    }

    #[Test]
    public function the_post_is_sent_to_the_search_index_when_it_goes_live_and_not_before(): void
    {
        // Saved while their time is still ahead, as they are in life: neither is listed yet.
        $due = Blog::factory()->create(['title' => 'Due lighthouse', 'status' => 'scheduled', 'scheduled_for' => now()->addMinutes(30), 'published_at' => null]);
        $future = Blog::factory()->create(['title' => 'Future lighthouse', 'status' => 'scheduled', 'scheduled_for' => now()->addDays(2), 'published_at' => null]);

        $this->assertSame([], $this->engine->documents[Blog::class] ?? []);

        // Half an hour passes. Nobody has opened the blog, and the scheduler's job runs.
        $this->travel(31)->minutes();

        (new PublishDueBlogs)->handle();

        $sent = $this->engine->documents[Blog::class] ?? [];

        $this->assertSame([$due->id], array_keys($sent));
        $this->assertSame('Due lighthouse', $sent[$due->id]['title']);
        $this->assertArrayNotHasKey($future->id, $sent);
        $this->assertSame(1, $this->engine->updates);

        // Run again a minute later: nothing is due, so nothing is sent again.
        (new PublishDueBlogs)->handle();

        $this->assertSame(1, $this->engine->updates);
    }

    #[Test]
    public function the_scheduler_queues_it_every_minute(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('PublishDueBlogs')->assertSuccessful();

        $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->description, 'PublishDueBlogs') || str_contains($event->command ?? '', 'PublishDueBlogs'));

        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events->first()->expression);
    }

    #[Test]
    public function a_visit_to_the_blog_still_publishes_what_is_due_for_a_site_with_no_scheduler(): void
    {
        $first = $this->due('Due on the list');
        $second = $this->due('Due as well');

        $this->get(route('blogs.index'))->assertOk();

        $this->assertSame('published', $first->fresh()->status);
        $this->assertSame('published', $second->fresh()->status, 'The list publishes every post that is due, not only the ones it shows.');
    }

    #[Test]
    public function the_page_of_a_post_publishes_it_when_it_is_due(): void
    {
        $blog = $this->due('Due on its page');

        $this->get(route('blogs.view', ['slug' => $blog->slug]))->assertOk();

        $blog->refresh();

        $this->assertSame('published', $blog->status);
        $this->assertNull($blog->scheduled_for);
        $this->assertNotNull($blog->published_at);
    }

    #[Test]
    public function the_feed_publishes_what_is_due(): void
    {
        $blog = $this->due('Due in the feed');

        $this->get(route('blogs.feed'))->assertOk();

        $this->assertSame('published', $blog->fresh()->status);
    }

    #[Test]
    public function the_posts_in_the_admin_area_publish_what_is_due(): void
    {
        Role::findOrCreate('editor');
        Permission::findOrCreate('blogs.acp.view');
        $editor = User::factory()->create();
        $editor->assignRole('editor');
        $editor->givePermissionTo('blogs.acp.view');

        $blog = $this->due('Due in the admin area');

        $this->actingAs($editor)->get(route('acp.blogs.index'))->assertOk();

        $this->assertSame('published', $blog->fresh()->status);
    }

    #[Test]
    public function a_post_that_is_not_due_is_not_published_by_looking_at_it(): void
    {
        $blog = Blog::factory()->scheduled()->create(['title' => 'Next week']);

        $this->get(route('blogs.index'))->assertOk();
        $this->get(route('blogs.feed'))->assertOk();
        $this->get(route('blogs.view', ['slug' => $blog->slug]))->assertNotFound();

        $this->assertSame('scheduled', $blog->fresh()->status);
    }
}
