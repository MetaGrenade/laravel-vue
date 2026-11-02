<?php

namespace Tests\Feature\Install;

use App\Models\Badge;
use App\Models\Poll;
use App\Models\SubscriptionPlan;
use App\Models\SupportTicketCategory;
use App\Models\User;
use App\Support\Legal\LegalPages;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `php artisan db:seed` is the demo site for a development machine (README, "Setting up by hand"): the baseline plus the demo, and the
 * first member can sign in and open the admin area, as the README promises.
 */
class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_makes_the_demo_member_the_administrator_and_adds_the_baseline_and_the_demo(): void
    {
        // On MySQL and PostgreSQL the id counter does not go back when a test rolls back, so in a full run the member is not user 1 (SQLite
        // hands the id out again, which hid this). The member is the administrator whatever its id is.
        User::factory()->create()->delete();

        $this->seed(DatabaseSeeder::class);

        $member = User::query()->where('email', 'test@example.com')->firstOrFail();

        $this->assertTrue($member->hasRole('admin'));
        $this->assertSame([$member->id], Role::findByName('admin')->users()->pluck('id')->all());

        $this->assertGreaterThan(0, SupportTicketCategory::count());
        $this->assertGreaterThan(0, Badge::count());
        $this->assertGreaterThan(0, SubscriptionPlan::count());
        $this->assertGreaterThan(0, Poll::count());
        $this->assertNotSame([], app(LegalPages::class)->publishedSlugs(), 'the starting text for the legal pages');
    }
}
