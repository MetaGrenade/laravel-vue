<?php

namespace Tests\Feature\Admin;

use App\Models\Blog;
use App\Models\BlogTag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BlogTagManagementTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'admin']);
        $user->assignRole($role);

        $this->actingAs($user);

        return $user;
    }

    public function test_admin_can_view_tag_index_page(): void
    {
        $this->actingAsAdmin();

        $alpha = BlogTag::factory()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $bravo = BlogTag::factory()->create(['name' => 'Bravo', 'slug' => 'bravo']);

        $response = $this->get(route('acp.blog-tags.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('acp/BlogTags')
            ->has('tags', 2)
            ->where('tags.0.name', 'Alpha')
            ->where('tags.1.name', 'Bravo')
        );
    }

    public function test_tag_index_returns_json_payload(): void
    {
        $this->actingAsAdmin();

        $tag = BlogTag::factory()->create(['name' => 'Campaigns', 'slug' => 'campaigns']);
        $blog = Blog::factory()->create();
        $blog->tags()->attach($tag);

        $response = $this->getJson(route('acp.blog-tags.index'));

        $response->assertOk()
            ->assertJsonPath('tags.0.name', 'Campaigns')
            ->assertJsonPath('tags.0.blogs_count', 1);
    }

    public function test_admin_can_create_tag(): void
    {
        $this->actingAsAdmin();

        $response = $this->post(route('acp.blog-tags.store'), [
            'name' => 'Laravel Tips',
            'slug' => '',
        ]);

        $response->assertRedirect(route('acp.blog-tags.index'));

        $this->assertDatabaseHas('blog_tags', [
            'name' => 'Laravel Tips',
            'slug' => 'laravel-tips',
        ]);
    }

    public function test_tag_slug_must_be_unique(): void
    {
        $this->actingAsAdmin();

        BlogTag::factory()->create(['slug' => 'duplicate']);

        $response = $this->from(route('acp.blog-tags.create'))->post(route('acp.blog-tags.store'), [
            'name' => 'Another Tag',
            'slug' => 'duplicate',
        ]);

        $response->assertRedirect(route('acp.blog-tags.create'));
        $response->assertSessionHasErrors('slug');
    }

    public function test_admin_can_update_tag(): void
    {
        $this->actingAsAdmin();

        $tag = BlogTag::factory()->create([
            'name' => 'Original',
            'slug' => 'original',
        ]);

        $response = $this->put(route('acp.blog-tags.update', $tag), [
            'name' => 'Updated Name',
            'slug' => '',
        ]);

        $response->assertRedirect(route('acp.blog-tags.index'));

        $tag->refresh();
        $this->assertSame('Updated Name', $tag->name);
        $this->assertSame('updated-name', $tag->slug);
    }

    public function test_admin_can_reuse_slug_on_same_tag(): void
    {
        $this->actingAsAdmin();

        $tag = BlogTag::factory()->create([
            'name' => 'Announcements',
            'slug' => 'announcements',
        ]);

        $response = $this->put(route('acp.blog-tags.update', $tag), [
            'name' => 'Announcements',
            'slug' => 'announcements',
        ]);

        $response->assertRedirect(route('acp.blog-tags.index'));
        $this->assertDatabaseHas('blog_tags', [
            'id' => $tag->id,
            'slug' => 'announcements',
        ]);
    }

    public function test_admin_can_delete_tag(): void
    {
        $this->actingAsAdmin();

        $tag = BlogTag::factory()->create();
        $blog = Blog::factory()->create();
        $blog->tags()->attach($tag);

        $response = $this->from(route('acp.blog-tags.index'))
            ->delete(route('acp.blog-tags.destroy', $tag));

        $response->assertRedirect(route('acp.blog-tags.index'));

        $this->assertDatabaseMissing('blog_tags', ['id' => $tag->id]);
        $this->assertDatabaseMissing('blog_blog_tag', ['blog_tag_id' => $tag->id]);
    }

    public function test_blog_create_page_includes_available_tags(): void
    {
        $this->actingAsAdmin();

        $tag = BlogTag::factory()->create(['name' => 'Fresh Tag', 'slug' => 'fresh-tag']);

        $response = $this->get(route('acp.blogs.create'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('acp/BlogCreate')
            ->where('tags', fn (array $tags) => collect($tags)->pluck('id')->contains($tag->id))
        );
    }
}
