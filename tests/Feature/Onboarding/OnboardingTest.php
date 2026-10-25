<?php

namespace Tests\Feature\Onboarding;

use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\SubscriptionPlan;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\Impersonation\Impersonation;
use App\Support\Onboarding\Onboarding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The "Get started" checklist on the dashboard (docs/onboarding.md): what is on it, how a step ticks itself, and when it goes.
 */
class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    /** What a new customer is given on a plain site: no plans on sale, so no step for one. */
    private const PLAIN = ['verify_email', 'profile', 'two_factor', 'notifications', 'api_key', 'community'];

    /**
     * Somebody who has just signed up and done nothing yet (the factory's people have a confirmed address, a picture and a bio).
     */
    private function newcomer(array $attributes = []): User
    {
        return User::factory()->create($attributes + ['email_verified_at' => null, 'avatar_url' => null, 'profile_bio' => null]);
    }

    /**
     * @return array<string, bool> Each step on the checklist and whether it is done, or null if there is no checklist.
     */
    private function steps(User $user): ?array
    {
        $checklist = app(Onboarding::class)->for($user->fresh());

        return $checklist === null ? null : collect($checklist['steps'])->mapWithKeys(fn (array $step) => [$step['key'] => $step['done']])->all();
    }

    private function thread(User $author): ForumThread
    {
        $category = ForumCategory::create(['title' => 'General', 'slug' => 'general', 'description' => 'Chat', 'position' => 1]);
        $board = ForumBoard::create(['forum_category_id' => $category->id, 'title' => 'News', 'slug' => 'news', 'description' => 'News', 'position' => 1]);

        return ForumThread::create([
            'forum_board_id' => $board->id,
            'user_id' => $author->id,
            'title' => 'Hello',
            'slug' => 'hello',
            'excerpt' => 'Hello',
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => true,
            'views' => 0,
        ]);
    }

    private function subscribe(User $user): void
    {
        $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_'.Str::random(10),
            'stripe_status' => 'active',
            'stripe_price' => 'price_pro',
            'quantity' => 1,
        ]);
    }

    // --- What is on it -------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_new_customer_is_shown_the_steps_a_plain_site_has_all_undone(): void
    {
        $steps = $this->steps($this->newcomer());

        $this->assertSame(self::PLAIN, array_keys($steps));
        $this->assertSame([false], array_values(array_unique($steps)));
    }

    #[Test]
    public function the_dashboard_gets_the_checklist_with_where_to_go_for_each_step(): void
    {
        $user = $this->newcomer();

        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('onboarding.done', 0)
            ->where('onboarding.total', 6)
            ->where('onboarding.dismiss_url', route('onboarding.dismiss'))
            ->where('onboarding.steps.0', ['key' => 'verify_email', 'done' => false, 'url' => route('verification.notice')])
            ->where('onboarding.steps.1.url', route('profile.edit'))
            ->where('onboarding.steps.2.url', route('security.edit'))
            ->where('onboarding.steps.3.url', route('settings.notifications.edit'))
            ->where('onboarding.steps.4.url', route('settings.api-keys.index'))
            ->where('onboarding.steps.5.url', route('forum.index')));
    }

    #[Test]
    public function the_steps_come_in_the_order_the_settings_give(): void
    {
        config(['onboarding.steps' => ['community', 'two_factor', 'profile']]);

        $this->assertSame(['community', 'two_factor', 'profile'], array_keys($this->steps($this->newcomer())));
    }

    #[Test]
    public function a_step_the_settings_do_not_know_is_ignored(): void
    {
        config(['onboarding.steps' => ['profile', 'make_coffee', 42, 'two_factor']]);

        $this->assertSame(['profile', 'two_factor'], array_keys($this->steps($this->newcomer())));
    }

    #[Test]
    public function every_step_has_its_words(): void
    {
        foreach (config('onboarding.steps') as $key) {
            foreach (['title', 'description', 'action'] as $part) {
                $this->assertNotSame("ui.onboarding.steps.$key.$part", __("ui.onboarding.steps.$key.$part"), "$key $part");
            }
        }
    }

    // --- Each step ticks itself ----------------------------------------------------------------------------------------------

    #[Test]
    public function confirming_the_email_address_ticks_its_step(): void
    {
        $user = $this->newcomer();
        $this->assertFalse($this->steps($user)['verify_email']);

        $user->forceFill(['email_verified_at' => now()])->save();
        $this->assertTrue($this->steps($user)['verify_email']);
    }

    #[Test]
    public function a_picture_or_a_bio_ticks_the_profile_step(): void
    {
        $user = $this->newcomer();
        $this->assertFalse($this->steps($user)['profile']);

        $user->forceFill(['profile_bio' => '   '])->save();
        $this->assertFalse($this->steps($user)['profile'], 'spaces are not a bio');

        $user->forceFill(['profile_bio' => 'I build things.'])->save();
        $this->assertTrue($this->steps($user)['profile']);

        $user->forceFill(['profile_bio' => null, 'avatar_url' => 'https://cdn.example.com/a.png'])->save();
        $this->assertTrue($this->steps($user)['profile']);
    }

    #[Test]
    public function turning_two_factor_on_ticks_its_step_but_only_once_it_is_confirmed(): void
    {
        $user = $this->newcomer();

        $user->forceFill(['two_factor_secret' => 'secret', 'two_factor_confirmed_at' => null])->save();
        $this->assertFalse($this->steps($user)['two_factor'], 'started but not confirmed');

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->assertTrue($this->steps($user)['two_factor']);
    }

    #[Test]
    public function saving_notification_settings_ticks_their_step(): void
    {
        $user = $this->newcomer();
        $this->assertFalse($this->steps($user)['notifications']);

        $user->notificationSettings()->create(['category' => 'forum', 'channel_mail' => false, 'channel_push' => true, 'channel_database' => true]);
        $this->assertTrue($this->steps($user)['notifications']);

        $this->assertFalse($this->steps($this->newcomer())['notifications'], 'somebody else saving them does not');
    }

    #[Test]
    public function making_an_api_key_ticks_its_step_and_revoking_the_only_one_unticks_it(): void
    {
        $user = $this->newcomer();
        $this->assertFalse($this->steps($user)['api_key']);

        $token = $user->createToken('CI');
        $this->assertTrue($this->steps($user)['api_key']);

        $token->accessToken->forceFill(['revoked_at' => now()])->save();
        $this->assertFalse($this->steps($user)['api_key'], 'a key that no longer works is not a key');
    }

    #[Test]
    public function a_first_thread_or_reply_ticks_the_community_step(): void
    {
        $author = $this->newcomer();
        $replier = $this->newcomer();
        $reader = $this->newcomer();

        $thread = $this->thread($author);
        ForumPost::create(['forum_thread_id' => $thread->id, 'user_id' => $replier->id, 'body' => 'Welcome!']);

        $this->assertTrue($this->steps($author)['community'], 'a thread');
        $this->assertTrue($this->steps($replier)['community'], 'a reply');
        $this->assertFalse($this->steps($reader)['community'], 'reading is not saying hello');
    }

    // --- Steps a site may not have -------------------------------------------------------------------------------------------

    #[Test]
    public function the_plan_step_is_there_only_while_plans_are_on_sale_and_ticks_with_a_subscription(): void
    {
        $user = $this->newcomer();
        $this->assertArrayNotHasKey('plan', $this->steps($user));

        SubscriptionPlan::factory()->create(['is_active' => false]);
        $this->assertArrayNotHasKey('plan', $this->steps($user), 'a plan nobody can buy');

        SubscriptionPlan::factory()->create(['is_active' => true]);
        $this->assertFalse($this->steps($user)['plan']);
        $this->assertSame(['verify_email', 'profile', 'two_factor', 'notifications', 'plan', 'api_key', 'community'], array_keys($this->steps($user)));

        $this->subscribe($user);
        $this->assertTrue($this->steps($user)['plan']);
    }

    #[Test]
    public function the_community_step_is_there_only_while_the_forum_is_on(): void
    {
        SystemSetting::set('website_sections', ['blog' => true, 'forum' => false, 'support' => true, 'commerce' => true]);

        $this->assertArrayNotHasKey('community', $this->steps($this->newcomer()));
    }

    #[Test]
    public function the_email_step_is_not_there_where_confirming_is_needed_to_get_in(): void
    {
        SystemSetting::set('email_verification_required', true);

        $this->assertArrayNotHasKey('verify_email', $this->steps($this->newcomer()), 'whoever is on the dashboard has done it');
    }

    // --- When it goes --------------------------------------------------------------------------------------------------------

    #[Test]
    public function it_is_gone_when_every_step_is_done(): void
    {
        $user = $this->newcomer(['profile_bio' => 'Hi', 'two_factor_confirmed_at' => now()]);
        $user->notificationSettings()->create(['category' => 'forum', 'channel_mail' => true, 'channel_push' => true, 'channel_database' => true]);
        $user->createToken('CI');
        ForumPost::create(['forum_thread_id' => $this->thread($user)->id, 'user_id' => $user->id, 'body' => 'Hi']);

        // Five of six: the one left is still shown, with how far along they are.
        $checklist = app(Onboarding::class)->for($user->fresh());
        $this->assertSame(5, $checklist['done']);
        $this->assertSame(6, $checklist['total']);

        $user->forceFill(['email_verified_at' => now()])->save();

        $this->assertNull(app(Onboarding::class)->for($user->fresh()));
        $this->actingAs($user->fresh())->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('onboarding', null));
    }

    #[Test]
    public function it_is_gone_when_the_person_hides_it_and_stays_gone(): void
    {
        $user = $this->newcomer();
        $this->assertNotNull($this->steps($user));

        $this->actingAs($user)->post(route('onboarding.dismiss'))->assertRedirect(route('dashboard'));

        $this->assertNotNull($user->fresh()->onboarding_dismissed_at);
        $this->assertNull($this->steps($user));
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('onboarding', null));

        // Doing something later does not bring it back.
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->assertNull($this->steps($user));
    }

    #[Test]
    public function it_is_gone_when_the_account_is_older_than_it_is_shown_for(): void
    {
        config(['onboarding.days' => 30]);

        $this->assertNotNull($this->steps($this->newcomer(['created_at' => now()->subDays(29)])));
        $this->assertNull($this->steps($this->newcomer(['created_at' => now()->subDays(31)])), 'an account from before it was built does not get it');
    }

    #[Test]
    public function with_no_limit_on_days_it_is_shown_as_long_as_something_is_left(): void
    {
        config(['onboarding.days' => 0]);

        $this->assertNotNull($this->steps($this->newcomer(['created_at' => now()->subYears(3)])));
    }

    #[Test]
    public function it_can_be_switched_off(): void
    {
        config(['onboarding.enabled' => false]);

        $this->assertNull($this->steps($this->newcomer()));
    }

    #[Test]
    public function it_is_gone_when_no_step_is_one_this_site_has(): void
    {
        config(['onboarding.steps' => []]);
        $this->assertNull($this->steps($this->newcomer()));

        config(['onboarding.steps' => ['plan']]);
        $this->assertNull($this->steps($this->newcomer()), 'a plan step and no plans');
    }

    // --- Hiding it -----------------------------------------------------------------------------------------------------------

    #[Test]
    public function hiding_it_is_for_signed_in_people_only(): void
    {
        $this->post(route('onboarding.dismiss'))->assertRedirect(route('login'));
    }

    #[Test]
    public function hiding_it_hides_it_for_that_person_only(): void
    {
        $one = $this->newcomer();
        $other = $this->newcomer();

        $this->actingAs($one)->post(route('onboarding.dismiss'));

        $this->assertNull($this->steps($one));
        $this->assertNotNull($this->steps($other));
        $this->assertNull($other->fresh()->onboarding_dismissed_at);
    }

    #[Test]
    public function staff_viewing_as_a_customer_see_it_and_cannot_hide_it_for_them(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin'));
        $customer = $this->newcomer();

        $this->actingAs($admin)->post(route('acp.users.impersonate', $customer), ['reason' => 'Ticket 4411: where do I start'])
            ->assertSessionHas(Impersonation::SESSION_KEY.'.user_id', $customer->id);

        $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('impersonation.user.id', $customer->id)
            ->where('onboarding.total', 6));

        $this->post(route('onboarding.dismiss'))->assertSessionHas('error');

        $this->assertNull($customer->fresh()->onboarding_dismissed_at);
        $this->assertNull($admin->fresh()->onboarding_dismissed_at);
    }
}
