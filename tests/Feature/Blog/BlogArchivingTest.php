<?php

namespace Tests\Feature\Blog;

use App\Models\Blog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BlogArchivingTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'admin']);
        $user->assignRole($role);

        $this->actingAs($user);

        return $user;
    }

    public function test_admin_can_archive_a_blog_post(): void
    {
        $user = $this->actingAsAdmin();
        $blog = Blog::factory()->published()->for($user)->create();

        $response = $this->from(route('acp.blogs.index'))
            ->put(route('acp.blogs.archive', $blog));

        $response->assertRedirect(route('acp.blogs.index'));

        $blog->refresh();

        $this->assertSame('archived', $blog->status);
        $this->assertNull($blog->published_at);
    }

    public function test_admin_can_unarchive_a_blog_post(): void
    {
        $user = $this->actingAsAdmin();
        $blog = Blog::factory()->archived()->for($user)->create();

        $response = $this->from(route('acp.blogs.index'))
            ->put(route('acp.blogs.unarchive', $blog));

        $response->assertRedirect(route('acp.blogs.index'));

        $blog->refresh();

        $this->assertSame('draft', $blog->status);
        $this->assertNull($blog->published_at);
    }

    public function test_archived_posts_do_not_appear_in_public_listing(): void
    {
        $publishedBlog = Blog::factory()->published()->create(['title' => 'Visible Post']);
        Blog::factory()->archived()->create(['title' => 'Hidden Post']);

        $response = $this->get(route('blogs.index'));

        $response->assertStatus(200);

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Blog')
            ->where('blogs.data', fn ($data) => collect($data)->pluck('id')->contains($publishedBlog->id))
            ->where('blogs.data', fn ($data) => !collect($data)->pluck('title')->contains('Hidden Post'))
        );
    }
}
