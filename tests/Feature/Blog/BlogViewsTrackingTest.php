<?php

namespace Tests\Feature\Blog;

use App\Models\Blog;
use App\Models\BlogView;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlogViewsTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_view_is_recorded_in_blog_views(): void
    {
        $user = User::factory()->create();
        $blog = Blog::factory()->published()->create();

        $this->actingAs($user);

        $this->get(route('blogs.view', $blog->slug))->assertOk();

        $this->assertDatabaseHas('blog_views', [
            'user_id' => $user->id,
            'blog_id' => $blog->id,
            'view_count' => 1,
        ]);

        $record = BlogView::query()->where('user_id', $user->id)->where('blog_id', $blog->id)->firstOrFail();
        $this->assertNotNull($record->last_viewed_at);

        $initialTimestamp = $record->last_viewed_at;

        $this->travel(10)->minutes();

        $this->get(route('blogs.view', $blog->slug))->assertOk();

        $updatedRecord = BlogView::query()->where('user_id', $user->id)->where('blog_id', $blog->id)->firstOrFail();

        $this->assertSame(2, $updatedRecord->view_count);
        $this->assertTrue($updatedRecord->last_viewed_at->greaterThan($initialTimestamp));
    }
}
