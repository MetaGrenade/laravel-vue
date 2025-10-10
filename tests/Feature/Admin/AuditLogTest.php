<?php

namespace Tests\Feature\Admin;

use App\Events\Payments\PaymentProcessed;
use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumThread;
use App\Models\ForumPost;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_login_is_logged(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('secret-password'),
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])->assertRedirect();

        $this->assertDatabaseHas(config('activitylog.table_name'), [
            'event' => 'auth.login',
            'causer_id' => $user->id,
            'causer_type' => User::class,
            'description' => 'User logged in',
        ]);
    }

    public function test_user_role_updates_are_logged(): void
    {
        $editorRole = Role::firstOrCreate(['name' => 'editor']);
        $moderatorRole = Role::firstOrCreate(['name' => 'moderator']);
        $adminRole = Role::firstOrCreate(['name' => 'admin']);

        $editPermission = Permission::firstOrCreate(['name' => 'users.acp.edit']);

        $admin = User::factory()->create();
        $admin->assignRole($adminRole);
        $admin->givePermissionTo($editPermission);

        $target = User::factory()->create();
        $target->assignRole($editorRole);

        $this->actingAs($admin);

        $this->put(route('acp.users.update', $target), [
            'nickname' => $target->nickname,
            'email' => $target->email,
            'roles' => [$editorRole->name, $moderatorRole->name],
        ])->assertRedirect();

        $this->assertDatabaseHas(config('activitylog.table_name'), [
            'event' => 'user.roles.updated',
            'subject_id' => $target->id,
            'subject_type' => User::class,
        ]);
    }

    public function test_thread_lock_activity_is_logged(): void
    {
        $category = ForumCategory::create([
            'title' => 'News',
            'slug' => 'news',
            'description' => 'Updates',
            'position' => 1,
        ]);

        $board = ForumBoard::create([
            'forum_category_id' => $category->id,
            'title' => 'Announcements',
            'slug' => 'announcements',
            'description' => 'Official updates',
            'position' => 1,
        ]);

        $author = User::factory()->create();

        $thread = ForumThread::create([
            'forum_board_id' => $board->id,
            'user_id' => $author->id,
            'title' => 'Release notes',
            'slug' => 'release-notes',
            'excerpt' => 'Initial release',
            'is_locked' => false,
            'is_published' => true,
        ]);

        $moderatorRole = Role::firstOrCreate(['name' => 'moderator']);
        $moderator = User::factory()->create();
        $moderator->assignRole($moderatorRole);

        $this->actingAs($moderator);

        $this->put(route('forum.threads.lock', ['board' => $board->slug, 'thread' => $thread->slug]))->assertRedirect();

        $this->assertDatabaseHas(config('activitylog.table_name'), [
            'event' => 'forum.thread.locked',
            'subject_id' => $thread->id,
            'subject_type' => ForumThread::class,
        ]);
    }

    public function test_moderator_post_deletion_is_logged(): void
    {
        $category = ForumCategory::create([
            'title' => 'General',
            'slug' => 'general',
            'description' => 'General chat',
            'position' => 1,
        ]);

        $board = ForumBoard::create([
            'forum_category_id' => $category->id,
            'title' => 'Discussion',
            'slug' => 'discussion',
            'description' => 'Talk about anything',
            'position' => 1,
        ]);

        $author = User::factory()->create();
        $thread = ForumThread::create([
            'forum_board_id' => $board->id,
            'user_id' => $author->id,
            'title' => 'Thoughts',
            'slug' => 'thoughts',
            'excerpt' => 'Random thoughts',
            'is_locked' => false,
            'is_published' => true,
        ]);

        $post = ForumPost::create([
            'forum_thread_id' => $thread->id,
            'user_id' => $author->id,
            'body' => 'Original content',
        ]);

        $moderatorRole = Role::firstOrCreate(['name' => 'moderator']);
        $moderator = User::factory()->create();
        $moderator->assignRole($moderatorRole);

        $this->actingAs($moderator);

        $this->delete(route('forum.posts.destroy', ['board' => $board->slug, 'thread' => $thread->slug, 'post' => $post->id]), [
            'page' => 1,
        ])->assertRedirect();

        $this->assertDatabaseHas(config('activitylog.table_name'), [
            'event' => 'forum.post.deleted',
            'subject_id' => $post->id,
            'subject_type' => ForumPost::class,
        ]);
    }

    public function test_payment_events_are_logged(): void
    {
        $customer = User::factory()->create();

        event(new PaymentProcessed(
            user: $customer,
            paymentId: 'pay_' . Str::random(6),
            provider: 'stripe',
            status: 'succeeded',
            amount: 49.99,
            currency: 'usd',
        ));

        $this->assertDatabaseHas(config('activitylog.table_name'), [
            'event' => 'billing.payment.processed',
            'causer_id' => $customer->id,
            'causer_type' => User::class,
        ]);
    }

    public function test_audit_logs_page_lists_entries(): void
    {
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $auditPermission = Permission::firstOrCreate(['name' => 'audits.acp.view']);

        $admin = User::factory()->create();
        $admin->assignRole($adminRole);
        $admin->givePermissionTo($auditPermission);

        $actor = User::factory()->create();

        AuditLogger::log('test.event', 'Recorded from test suite', ['context' => 'example'], $actor, $actor);

        $this->actingAs($admin);

        $response = $this->get(route('acp.audit-logs.index'));

        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('acp/AuditLogs')
            ->where('logs.data.0.event', 'test.event')
        );
    }
}

