<?php

namespace Tests\Feature\Admin;

use App\Models\Blog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BlogBulkActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'admin', 'guard_name' => 'web']);
        Permission::create(['name' => 'blogs.acp.publish', 'guard_name' => 'web']);
    }

    public function test_admin_can_bulk_publish_blogs(): void
    {
        Carbon::setTestNow('2025-02-15 10:00:00');

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->givePermissionTo('blogs.acp.publish');

        $blogs = Blog::factory()
            ->count(2)
            ->state(['status' => 'draft'])
            ->create();

        $response = $this->actingAs($admin)
            ->from(route('acp.blogs.index'))
            ->patch(route('acp.blogs.bulk-status'), [
                'action' => 'publish',
                'ids' => $blogs->pluck('id')->all(),
            ]);

        $response->assertRedirect(route('acp.blogs.index'));
        $response->assertSessionHas('success', 'Updated 2 blog posts.');

        foreach ($blogs as $blog) {
            $fresh = $blog->fresh();
            $this->assertSame('published', $fresh->status);
            $this->assertNotNull($fresh->published_at);
            $this->assertTrue($fresh->published_at->equalTo(Carbon::now()));
        }

        Carbon::setTestNow();
    }

    public function test_user_without_publish_permission_cannot_bulk_update_blogs(): void
    {
        $user = User::factory()->create();
        $blog = Blog::factory()->create();

        $response = $this->actingAs($user)
            ->patch(route('acp.blogs.bulk-status'), [
                'action' => 'publish',
                'ids' => [$blog->id],
            ]);

        $response->assertForbidden();
    }
}
