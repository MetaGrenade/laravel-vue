<?php

namespace Tests\Feature\Admin;

use App\Models\Blog;
use App\Models\BlogCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BlogCategoryManagementTest extends TestCase
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

    public function test_admin_can_view_category_index_page(): void
    {
        $this->actingAsAdmin();

        $alpha = BlogCategory::factory()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $bravo = BlogCategory::factory()->create(['name' => 'Bravo', 'slug' => 'bravo']);

        $response = $this->get(route('acp.blog-categories.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('acp/BlogCategories')
            ->has('categories', 2)
            ->where('categories.0.name', 'Alpha')
            ->where('categories.1.name', 'Bravo')
        );
    }

    public function test_category_index_returns_json_payload(): void
    {
        $this->actingAsAdmin();

        $category = BlogCategory::factory()->create(['name' => 'Campaigns', 'slug' => 'campaigns']);
        $blog = Blog::factory()->create();
        $blog->categories()->attach($category);

        $response = $this->getJson(route('acp.blog-categories.index'));

        $response->assertOk()
            ->assertJsonPath('categories.0.name', 'Campaigns')
            ->assertJsonPath('categories.0.blogs_count', 1);
    }

    public function test_admin_can_create_category(): void
    {
        $this->actingAsAdmin();

        $response = $this->post(route('acp.blog-categories.store'), [
            'name' => 'Laravel Guides',
            'slug' => '',
        ]);

        $response->assertRedirect(route('acp.blog-categories.index'));

        $this->assertDatabaseHas('blog_categories', [
            'name' => 'Laravel Guides',
            'slug' => 'laravel-guides',
        ]);
    }

    public function test_category_slug_must_be_unique(): void
    {
        $this->actingAsAdmin();

        BlogCategory::factory()->create(['slug' => 'duplicate']);

        $response = $this->from(route('acp.blog-categories.create'))->post(route('acp.blog-categories.store'), [
            'name' => 'Another Category',
            'slug' => 'duplicate',
        ]);

        $response->assertRedirect(route('acp.blog-categories.create'));
        $response->assertSessionHasErrors('slug');
    }

    public function test_admin_can_update_category(): void
    {
        $this->actingAsAdmin();

        $category = BlogCategory::factory()->create([
            'name' => 'Original Category',
            'slug' => 'original-category',
        ]);

        $response = $this->put(route('acp.blog-categories.update', $category), [
            'name' => 'Updated Name',
            'slug' => '',
        ]);

        $response->assertRedirect(route('acp.blog-categories.index'));

        $category->refresh();
        $this->assertSame('Updated Name', $category->name);
        $this->assertSame('updated-name', $category->slug);
    }

    public function test_admin_can_reuse_slug_on_same_category(): void
    {
        $this->actingAsAdmin();

        $category = BlogCategory::factory()->create([
            'name' => 'Announcements',
            'slug' => 'announcements',
        ]);

        $response = $this->put(route('acp.blog-categories.update', $category), [
            'name' => 'Announcements',
            'slug' => 'announcements',
        ]);

        $response->assertRedirect(route('acp.blog-categories.index'));
        $this->assertDatabaseHas('blog_categories', [
            'id' => $category->id,
            'slug' => 'announcements',
        ]);
    }

    public function test_admin_can_delete_category(): void
    {
        $this->actingAsAdmin();

        $category = BlogCategory::factory()->create();
        $blog = Blog::factory()->create();
        $blog->categories()->attach($category);

        $response = $this->from(route('acp.blog-categories.index'))
            ->delete(route('acp.blog-categories.destroy', $category));

        $response->assertRedirect(route('acp.blog-categories.index'));

        $this->assertDatabaseMissing('blog_categories', ['id' => $category->id]);
        $this->assertDatabaseMissing('blog_blog_category', ['blog_category_id' => $category->id]);
    }

    public function test_blog_create_page_includes_available_categories(): void
    {
        $this->actingAsAdmin();

        $category = BlogCategory::factory()->create(['name' => 'Fresh Category', 'slug' => 'fresh-category']);

        $response = $this->get(route('acp.blogs.create'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('acp/BlogCreate')
            ->where('categories', fn ($categories) => collect($categories)->pluck('id')->contains($category->id))
        );
    }

    public function test_blog_edit_page_includes_category_payload(): void
    {
        $this->actingAsAdmin();

        $category = BlogCategory::factory()->create(['name' => 'Guides', 'slug' => 'guides']);
        $blog = Blog::factory()->create();
        $blog->categories()->attach($category);

        $response = $this->get(route('acp.blogs.edit', $blog));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('acp/BlogEdit')
            ->where('categories', fn ($categories) => collect($categories)->pluck('id')->contains($category->id))
            ->where('blog.categories', fn ($selected) => collect($selected)->pluck('id')->contains($category->id))
        );
    }
}
