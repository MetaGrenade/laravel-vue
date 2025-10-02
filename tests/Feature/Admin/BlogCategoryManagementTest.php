<?php

namespace Tests\Feature\Admin;

use App\Models\Blog;
use App\Models\BlogCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BlogCategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin', 'web');

        foreach ([
            'blogs.acp.view',
            'blogs.acp.create',
            'blogs.acp.edit',
            'blogs.acp.delete',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    private function createAdmin(): User
    {
        $user = User::factory()->create();

        $role = Role::findByName('admin');
        $permissions = Permission::whereIn('name', [
            'blogs.acp.view',
            'blogs.acp.create',
            'blogs.acp.edit',
            'blogs.acp.delete',
        ])->get();

        $role->syncPermissions($permissions);
        $user->assignRole($role);

        return $user;
    }

    public function test_admin_can_view_blog_category_index(): void
    {
        $admin = $this->createAdmin();

        $category = BlogCategory::factory()->create(['name' => 'Announcements']);
        $blog = Blog::factory()->create();
        $blog->categories()->attach($category->id);

        $response = $this->actingAs($admin)->get(route('acp.blog-categories.index'));

        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('acp/BlogCategories')
            ->where('categories', fn ($categories) => collect($categories)
                ->contains(fn ($item) => $item['name'] === 'Announcements' && $item['blogs_count'] === 1)
            )
        );
    }

    public function test_index_returns_json_category_options(): void
    {
        $admin = $this->createAdmin();

        $categoryA = BlogCategory::factory()->create(['name' => 'Tech']);
        $categoryB = BlogCategory::factory()->create(['name' => 'Insights']);

        $response = $this->actingAs($admin)->getJson(route('acp.blog-categories.index'));

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonFragment(['name' => $categoryA->name]);
        $response->assertJsonFragment(['name' => $categoryB->name]);
    }

    public function test_admin_can_create_blog_category(): void
    {
        $admin = $this->createAdmin();

        $response = $this->actingAs($admin)->from(route('acp.blog-categories.create'))->post(
            route('acp.blog-categories.store'),
            [
                'name' => 'Product Updates',
                'slug' => '',
            ],
        );

        $response->assertRedirect(route('acp.blog-categories.index'));
        $response->assertSessionHas('success', 'Blog category created successfully.');

        $this->assertDatabaseHas('blog_categories', [
            'name' => 'Product Updates',
            'slug' => Str::slug('Product Updates'),
        ]);
    }

    public function test_admin_can_update_blog_category(): void
    {
        $admin = $this->createAdmin();

        $category = BlogCategory::factory()->create([
            'name' => 'Releases',
            'slug' => 'releases',
        ]);

        $response = $this->actingAs($admin)->from(route('acp.blog-categories.edit', $category))->put(
            route('acp.blog-categories.update', $category),
            [
                'name' => 'Release Notes',
                'slug' => '',
            ],
        );

        $response->assertRedirect(route('acp.blog-categories.edit', $category));
        $response->assertSessionHas('success', 'Blog category updated successfully.');

        $category->refresh();

        $this->assertSame('Release Notes', $category->name);
        $this->assertSame(Str::slug('Release Notes'), $category->slug);
    }

    public function test_admin_can_delete_blog_category(): void
    {
        $admin = $this->createAdmin();

        $category = BlogCategory::factory()->create();
        $blog = Blog::factory()->create();
        $blog->categories()->attach($category->id);

        $response = $this->actingAs($admin)->from(route('acp.blog-categories.index'))
            ->delete(route('acp.blog-categories.destroy', $category));

        $response->assertRedirect(route('acp.blog-categories.index'));
        $response->assertSessionHas('success', 'Blog category deleted successfully.');

        $this->assertDatabaseMissing('blog_categories', [
            'id' => $category->id,
        ]);

        $this->assertDatabaseMissing('blog_blog_category', [
            'blog_category_id' => $category->id,
        ]);
    }
}
