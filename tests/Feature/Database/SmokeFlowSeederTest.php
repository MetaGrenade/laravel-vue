<?php

namespace Tests\Feature\Database;

use App\Models\Blog;
use App\Models\ForumBoard;
use App\Models\ForumThread;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\SmokeFlowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Playwright smoke suite (tests/e2e/smoke.spec.ts) signs in as two people, buys two products and opens a board, a thread and a post
 * by their addresses. It runs in CI in a browser, a few minutes after everything else; this fails first when the seeder stops giving it
 * what it asks for.
 */
class SmokeFlowSeederTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_administrator_has_the_role_whatever_its_id_is(): void
    {
        // On MySQL and PostgreSQL the ids of earlier tests are not given back, so the seeder's first user is hardly ever number 1: the
        // role seeder gives the role to number 1, and the administrator must not depend on that.
        User::factory()->count(2)->create();

        $this->seed(SmokeFlowSeeder::class);

        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        $this->assertNotSame(1, $admin->id);
        $this->assertTrue($admin->hasRole('admin'));
    }

    #[Test]
    public function the_member_is_not_staff_and_both_sign_in_with_the_factory_password(): void
    {
        $this->seed(SmokeFlowSeeder::class);

        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $member = User::where('email', 'avery@example.com')->firstOrFail();

        $this->assertFalse($member->hasAnyRole(['admin', 'editor', 'moderator']));

        foreach ([$admin, $member] as $user) {
            $this->assertNotNull($user->email_verified_at);
            $this->assertTrue(Hash::check('password', $user->password));
        }
    }

    #[Test]
    public function the_hoodie_costs_ten_and_the_guide_costs_nothing_and_is_not_shipped(): void
    {
        $this->seed(SmokeFlowSeeder::class);

        $hoodie = Product::where('slug', 'hoodie')->firstOrFail();
        $guide = Product::where('slug', 'starter-guide')->firstOrFail();

        $this->assertEquals([10.0], $hoodie->prices->map(fn ($price) => (float) $price->amount)->all());
        $this->assertEquals([0.0], $guide->prices->map(fn ($price) => (float) $price->amount)->all());
        $this->assertFalse($guide->requires_shipping);
    }

    #[Test]
    public function the_board_the_thread_and_the_post_the_suite_opens_are_there(): void
    {
        $this->seed(SmokeFlowSeeder::class);

        $board = ForumBoard::where('slug', 'announcements')->firstOrFail();

        $this->assertTrue(ForumThread::where('forum_board_id', $board->id)->where('slug', 'welcome')->exists());
        $this->assertTrue(Blog::where('slug', 'a-first-post')->exists());
    }
}
