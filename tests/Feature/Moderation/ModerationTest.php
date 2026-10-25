<?php

namespace Tests\Feature\Moderation;

use App\Jobs\GenerateUserDataExport;
use App\Jobs\LiftRunOutBans;
use App\Models\Blog;
use App\Models\BlogComment;
use App\Models\DataExport;
use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumPostReport;
use App\Models\ForumThread;
use App\Models\ModerationAction;
use App\Models\User;
use App\Notifications\AccountBanned;
use App\Notifications\BanLifted;
use App\Notifications\WarningIssued;
use App\Support\Moderation\Moderation;
use App\Support\Moderation\ModerationRefused;
use App\Support\Moderation\ModerationSubject;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\ViewErrorBag;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Warnings and bans (docs/moderation.md): what each does and does not do, who may do it to whom, what the member is told, and how a
 * ban with an end date lifts itself.
 */
class ModerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The roles and permissions the way a site has them, so that what a moderator can do is what the seeder gives them.
        $this->seed(RolePermissionSeeder::class);
        Notification::fake();
    }

    private function as(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function member(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }

    private function moderation(): Moderation
    {
        return app(Moderation::class);
    }

    /**
     * The first message the last response put in the session for a field (it is kept as an array there).
     */
    private function sessionError(string $field): string
    {
        $errors = session('errors');

        return $errors instanceof ViewErrorBag ? $errors->first($field) : (string) ($errors['default']['messages'][$field][0] ?? '');
    }

    private function thread(User $author): ForumThread
    {
        $category = ForumCategory::create(['title' => 'General', 'slug' => 'general', 'description' => 'Chat', 'position' => 1]);
        $board = ForumBoard::create(['forum_category_id' => $category->id, 'title' => 'News', 'slug' => 'news', 'description' => 'News', 'position' => 1]);

        return ForumThread::create([
            'forum_board_id' => $board->id,
            'user_id' => $author->id,
            'title' => 'Buy cheap watches',
            'slug' => 'buy-cheap-watches',
            'excerpt' => 'Spam',
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => true,
            'views' => 0,
        ]);
    }

    // --- A warning -----------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_warning_is_kept_and_the_member_is_told_but_nothing_happens_to_their_account(): void
    {
        $moderator = $this->as('moderator');
        $member = $this->member();

        $action = $this->moderation()->warn($member, $moderator, 'Please keep to the topic of the board.', 'Third time this week');

        $this->assertSame(ModerationAction::WARNING, $action->type);
        $this->assertSame($member->id, $action->user_id);
        $this->assertSame($moderator->id, $action->actor_id);
        $this->assertSame('Please keep to the topic of the board.', $action->reason);
        $this->assertSame('Third time this week', $action->note);
        $this->assertTrue($action->notified);

        $member->refresh();
        $this->assertFalse($member->is_banned, 'a warning is a notice, not a penalty');
        $this->assertNull($member->banned_until);

        Notification::assertSentTo($member, WarningIssued::class, fn (WarningIssued $notice) => $notice->reason === 'Please keep to the topic of the board.');
    }

    #[Test]
    public function a_moderator_can_choose_not_to_tell_the_member(): void
    {
        $member = $this->member();

        $action = $this->moderation()->warn($member, $this->as('moderator'), 'Spam in the lounge', notify: false);

        $this->assertFalse($action->notified);
        Notification::assertNothingSent();
    }

    #[Test]
    public function a_warning_is_sent_whatever_the_members_notification_settings_say(): void
    {
        $member = $this->member();
        $member->notificationSettings()->create(['category' => 'forum', 'channel_mail' => false, 'channel_push' => false, 'channel_database' => false]);

        $this->moderation()->warn($member, $this->as('moderator'), 'Please read the rules again.');

        Notification::assertSentTo($member, WarningIssued::class, fn ($notice, array $channels) => $channels === ['mail']);
    }

    #[Test]
    public function what_a_moderator_wrote_is_shown_in_the_email_as_text_and_never_as_a_link(): void
    {
        $member = $this->member(['nickname' => 'Ada']);
        $notice = new WarningIssued('Read [the rules](https://evil.example/login) *now* <script>alert(1)</script>');

        $html = (string) $notice->toMail($member)->render();

        $this->assertStringNotContainsString('href="https://evil.example', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('the rules', $html);
        $this->assertStringContainsString('https://evil.example/login', $html, 'the words are all still there, as words');
    }

    // --- A ban ---------------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_ban_signs_the_member_out_for_good_until_it_is_lifted(): void
    {
        $moderator = $this->as('moderator');
        $member = $this->member();

        $action = $this->moderation()->ban($member, $moderator, 'Harassing other members');

        $member->refresh();
        $this->assertTrue($member->is_banned);
        $this->assertSame($moderator->id, $member->banned_by_id);
        $this->assertNotNull($member->banned_at);
        $this->assertNull($member->banned_until);
        $this->assertSame('Harassing other members', $member->ban_reason);

        $this->assertSame(ModerationAction::BAN, $action->type);
        $this->assertNull($action->expires_at);
        Notification::assertSentTo($member, AccountBanned::class, fn (AccountBanned $notice) => $notice->until === null);
    }

    #[Test]
    public function a_ban_can_end_on_a_date_and_the_member_is_told_when(): void
    {
        $this->freezeTime();
        $until = now()->addDays(7)->endOfDay();
        $member = $this->member();

        $action = $this->moderation()->ban($member, $this->as('moderator'), 'Edit-warring on the news board', $until);

        $this->assertSame($until->format('Y-m-d H:i:s'), $member->fresh()->banned_until->format('Y-m-d H:i:s'));
        $this->assertSame($until->format('Y-m-d H:i:s'), $action->expires_at->format('Y-m-d H:i:s'));
        // Whole seconds are what is sent on and stored: the end of a day has a fraction of a second, which MySQL would round up to the next day.
        Notification::assertSentTo($member, AccountBanned::class, fn (AccountBanned $notice) => $notice->until?->format('Y-m-d H:i:s') === $until->format('Y-m-d H:i:s') && $notice->until->micro === 0);
    }

    #[Test]
    public function a_ban_has_to_end_in_the_future(): void
    {
        $member = $this->member();

        $this->expectException(ModerationRefused::class);

        try {
            $this->moderation()->ban($member, $this->as('moderator'), 'Spam', now()->subMinute());
        } finally {
            $this->assertFalse($member->fresh()->is_banned);
            $this->assertSame(0, ModerationAction::query()->count());
        }
    }

    #[Test]
    public function a_member_who_is_banned_cannot_be_banned_again(): void
    {
        $member = $this->member();
        $this->moderation()->ban($member, $this->as('moderator'), 'Spam');

        try {
            $this->moderation()->ban($member->fresh(), $this->as('moderator'), 'Spam again');
            $this->fail('A second ban was accepted.');
        } catch (ModerationRefused $refused) {
            $this->assertStringContainsString('already banned', $refused->getMessage());
        }

        $this->assertSame(1, ModerationAction::query()->where('type', ModerationAction::BAN)->count());
    }

    #[Test]
    public function the_member_is_told_why_when_they_try_to_sign_in_but_not_before_they_have_the_password_right(): void
    {
        $this->freezeTime();
        $member = $this->member(['email' => 'spammer@example.com']);
        $this->moderation()->ban($member, $this->as('moderator'), 'Posting adverts in every board', now()->addDays(3)->endOfDay());

        // Somebody guessing at the password is not told who is banned, or why.
        $this->post(route('login'), ['email' => 'spammer@example.com', 'password' => 'wrong'])
            ->assertSessionHasErrors(['email' => trans('auth.failed')]);

        $this->post(route('login'), ['email' => 'spammer@example.com', 'password' => 'password']);

        $message = $this->sessionError('email');
        $this->assertStringContainsString('suspended until', $message);
        $this->assertStringContainsString(now()->addDays(3)->format('Y'), $message);
        $this->assertStringContainsString('Posting adverts in every board', $message);
        $this->assertGuest();
    }

    #[Test]
    public function a_permanent_ban_says_banned_and_the_contact_when_there_is_one(): void
    {
        $member = $this->member(['email' => 'rude@example.com']);
        $this->moderation()->ban($member, $this->as('moderator'), 'Abuse');

        $this->post(route('login'), ['email' => 'rude@example.com', 'password' => 'password']);
        $plain = $this->sessionError('email');
        $this->assertStringContainsString('Your account has been banned.', $plain);
        $this->assertStringNotContainsString('contact', $plain);

        config(['moderation.contact' => 'appeals@example.com']);
        $this->post(route('login'), ['email' => 'rude@example.com', 'password' => 'password']);
        $this->assertStringContainsString('contact appeals@example.com', $this->sessionError('email'));
    }

    #[Test]
    public function a_member_already_signed_in_is_put_out_at_their_next_request_with_the_reason(): void
    {
        $member = $this->member();
        $this->actingAs($member)->get(route('dashboard'))->assertOk();

        $this->moderation()->ban($member, $this->as('moderator'), 'Chargeback abuse');

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertStringContainsString('Chargeback abuse', $this->sessionError('email'));
        $this->assertGuest();
    }

    #[Test]
    public function a_banned_member_asking_for_json_is_told_why_in_the_refusal(): void
    {
        $member = $this->member();
        $this->moderation()->ban($member, $this->as('moderator'), 'Scraping the forum');

        $this->actingAs($member)->getJson('/api/user')->assertForbidden()->assertJsonPath('message', fn ($m) => str_contains($m, 'Scraping the forum'));
    }

    #[Test]
    public function a_banned_members_api_key_is_refused_while_the_ban_lasts_and_works_again_when_it_ends(): void
    {
        $moderator = $this->as('moderator');
        $member = $this->member();
        $key = $member->createToken('CI', ['*'])->plainTextToken;

        $this->withToken($key)->getJson('/api/user')->assertOk();

        $this->moderation()->ban($member, $moderator, 'Scraping the forum', now()->addDay());
        $this->app['auth']->forgetGuards();

        $this->withToken($key)->getJson('/api/user')->assertForbidden();
        $this->assertSame(1, $member->tokens()->count(), 'the key is not deleted: a ban can end');

        $this->moderation()->unban($member->fresh(), $moderator);
        $this->app['auth']->forgetGuards();

        $this->withToken($key)->getJson('/api/user')->assertOk();
    }

    // --- A ban that ends -----------------------------------------------------------------------------------------------------

    #[Test]
    public function a_suspension_lifts_itself_when_the_member_next_tries_to_sign_in(): void
    {
        $this->freezeTime();
        $member = $this->member(['email' => 'back@example.com']);
        $this->moderation()->ban($member, $this->as('moderator'), 'Cool off', now()->addDay()->endOfDay());

        $this->travel(2)->days();

        $this->post(route('login'), ['email' => 'back@example.com', 'password' => 'password'])->assertRedirect();
        $this->assertAuthenticatedAs($member);
        $this->assertFalse($member->fresh()->is_banned);
    }

    #[Test]
    public function a_suspension_lifts_itself_at_the_next_request_of_a_member_who_is_signed_in(): void
    {
        $this->freezeTime();
        $member = $this->member();
        $this->moderation()->ban($member, $this->as('moderator'), 'Cool off', now()->addHour());

        $this->actingAs($member)->get(route('dashboard'))->assertRedirect(route('login'));

        $this->travel(2)->hours();

        $this->actingAs($member->fresh())->get(route('dashboard'))->assertOk();
        $this->assertFalse($member->fresh()->is_banned);
    }

    #[Test]
    public function a_suspension_that_ran_out_is_in_the_history_as_the_systems_doing_and_the_member_is_told(): void
    {
        $this->freezeTime();
        $member = $this->member();
        $this->moderation()->ban($member, $this->as('moderator'), 'Cool off', now()->addHour());

        $this->travel(2)->hours();
        $this->assertTrue($this->moderation()->liftIfRunOut($member->fresh()));

        $lift = ModerationAction::query()->where('type', ModerationAction::UNBAN)->sole();
        $this->assertNull($lift->actor_id, 'nobody lifted it');
        $this->assertTrue($lift->notified);
        Notification::assertSentTo($member, BanLifted::class, fn (BanLifted $notice) => $notice->ranOut === true);

        $member->refresh();
        $this->assertNull($member->banned_until);
        $this->assertNull($member->ban_reason);
        $this->assertNull($member->banned_at);
    }

    #[Test]
    public function a_member_who_was_not_emailed_of_the_ban_is_not_emailed_when_it_ends(): void
    {
        $this->freezeTime();
        $member = $this->member();
        $this->moderation()->ban($member, $this->as('moderator'), 'Spam account', now()->addHour(), notify: false);

        $this->travel(2)->hours();
        $this->moderation()->liftIfRunOut($member->fresh());

        Notification::assertNothingSent();
        $this->assertFalse(ModerationAction::query()->where('type', ModerationAction::UNBAN)->sole()->notified);
    }

    #[Test]
    public function a_ban_with_no_end_date_never_runs_out(): void
    {
        $member = $this->member();
        $this->moderation()->ban($member, $this->as('moderator'), 'Abuse');

        $this->travel(5)->years();

        $this->assertFalse($this->moderation()->liftIfRunOut($member->fresh()));
        $this->assertTrue($member->fresh()->is_banned);
    }

    #[Test]
    public function the_job_lifts_the_suspensions_that_have_run_out_and_only_those(): void
    {
        $this->freezeTime();
        $moderator = $this->as('moderator');

        $ranOut = $this->member();
        $stillOn = $this->member();
        $forGood = $this->member();
        $this->moderation()->ban($ranOut, $moderator, 'Cool off', now()->addHour());
        $this->moderation()->ban($stillOn, $moderator, 'Cool off', now()->addDays(3));
        $this->moderation()->ban($forGood, $moderator, 'Abuse');

        $this->travel(2)->hours();

        (new LiftRunOutBans)->handle($this->moderation());

        $this->assertFalse($ranOut->fresh()->is_banned);
        $this->assertTrue($stillOn->fresh()->is_banned);
        $this->assertTrue($forGood->fresh()->is_banned);
        $this->assertSame(1, $this->moderation()->liftRunOut() + 1, 'a second run has nothing left to lift');
    }

    #[Test]
    public function the_job_is_on_the_schedule_every_minute(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) ($event->description ?? $event->command ?? ''), 'LiftRunOutBans'));

        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events->first()->expression);
    }

    #[Test]
    public function an_old_copy_of_a_member_cannot_lift_a_newer_ban(): void
    {
        $this->freezeTime();
        $moderator = $this->as('moderator');
        $member = $this->member();
        $this->moderation()->ban($member, $moderator, 'Cool off', now()->addHour());

        $this->travel(2)->hours();
        $stale = User::query()->find($member->id);

        // Between the two, another moderator bans them for good.
        $this->moderation()->ban($member->fresh(), $moderator, 'Back at it');

        $this->assertFalse($this->moderation()->liftIfRunOut($stale), 'the row says the ban is not over, whatever the copy in hand says');
        $this->assertTrue($member->fresh()->is_banned);
        $this->assertSame('Back at it', $member->fresh()->ban_reason);
    }

    #[Test]
    public function a_suspension_that_has_run_out_can_be_replaced_by_a_new_ban(): void
    {
        $this->freezeTime();
        $moderator = $this->as('moderator');
        $member = $this->member();
        $this->moderation()->ban($member, $moderator, 'Cool off', now()->addHour());
        $this->travel(2)->hours();

        $this->moderation()->ban($member->fresh(), $moderator, 'Again');

        $this->assertSame('Again', $member->fresh()->ban_reason);
        $this->assertNull($member->fresh()->banned_until);
    }

    // --- Lifting a ban -------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_moderator_can_lift_a_ban_and_it_stays_in_the_history(): void
    {
        $moderator = $this->as('moderator');
        $member = $this->member();
        $this->moderation()->ban($member, $moderator, 'Spam');

        $action = $this->moderation()->unban($member->fresh(), $moderator, 'They have promised to stop');

        $member->refresh();
        $this->assertFalse($member->is_banned);
        $this->assertNull($member->ban_reason);
        $this->assertNull($member->banned_by_id);
        $this->assertSame($moderator->id, $action->actor_id);

        $this->assertSame(
            [ModerationAction::UNBAN, ModerationAction::BAN],
            $this->moderation()->history($member)->pluck('type')->all(),
            'newest first, and the ban is still there',
        );
        Notification::assertSentTo($member, BanLifted::class, fn (BanLifted $notice) => $notice->ranOut === false && $notice->reason === 'They have promised to stop');
    }

    #[Test]
    public function a_member_who_is_not_banned_cannot_be_unbanned(): void
    {
        $this->expectException(ModerationRefused::class);

        $this->moderation()->unban($this->member(), $this->as('moderator'));
    }

    #[Test]
    public function a_moderator_can_lift_a_ban_without_telling_the_member(): void
    {
        $moderator = $this->as('moderator');
        $member = $this->member();
        $this->moderation()->ban($member, $moderator, 'Spam', notify: false);

        $this->moderation()->unban($member->fresh(), $moderator, notify: false);

        Notification::assertNothingSent();
    }

    // --- Who may do what to whom ---------------------------------------------------------------------------------------------

    #[Test]
    public function nobody_can_moderate_themselves(): void
    {
        $admin = $this->as('admin');

        foreach (['warn', 'ban', 'unban'] as $action) {
            $this->assertSame('You cannot moderate your own account.', $this->moderation()->refusal($admin, $admin, $action));
        }
    }

    #[Test]
    public function an_administrator_cannot_be_banned_by_anybody(): void
    {
        $this->assertStringContainsString('cannot be banned', $this->moderation()->refusal($this->as('admin'), $this->as('admin'), 'ban'));

        $this->expectException(ModerationRefused::class);
        $this->moderation()->ban($this->as('admin'), $this->as('admin'), 'Coup');
    }

    #[Test]
    public function only_an_administrator_can_moderate_a_member_of_staff(): void
    {
        $moderator = $this->as('moderator');
        $admin = $this->as('admin');

        foreach (['editor', 'moderator'] as $role) {
            $colleague = $this->as($role);

            foreach (['warn', 'ban'] as $action) {
                $this->assertSame('Only an administrator can moderate a member of staff.', $this->moderation()->refusal($moderator, $colleague, $action), "$role $action");
                $this->assertNull($this->moderation()->refusal($admin, $colleague, $action), "an administrator may $action a $role");
            }
        }
    }

    #[Test]
    public function a_moderator_can_warn_and_ban_an_ordinary_member(): void
    {
        $moderator = $this->as('moderator');
        $member = $this->member();

        foreach (['warn', 'ban', 'unban'] as $action) {
            $this->assertNull($this->moderation()->refusal($moderator, $member, $action));
        }
    }

    // --- What a warning or ban was about -------------------------------------------------------------------------------------

    #[Test]
    public function a_warning_can_say_what_it_was_about_and_the_history_keeps_it_when_the_post_is_gone(): void
    {
        $member = $this->member();
        $thread = $this->thread($member);

        $subject = ModerationSubject::resolve('forum_thread:'.$thread->id, $member);
        $this->assertSame('Thread "Buy cheap watches"', $subject['label']);

        $action = $this->moderation()->warn($member, $this->as('moderator'), 'No adverts, please.', subject: $subject);
        $this->assertSame('forum_thread', $action->subject_type);
        $this->assertSame($thread->id, $action->subject_id);
        $this->assertSame('Thread "Buy cheap watches"', $action->subject_label);

        $thread->delete();
        $this->assertSame('Thread "Buy cheap watches"', $action->fresh()->subject_label);
        $this->assertNull(ModerationSubject::url('forum_thread', $thread->id), 'there is no page to link to now');
    }

    #[Test]
    public function only_something_the_member_wrote_can_be_named(): void
    {
        $author = $this->member();
        $other = $this->member();
        $thread = $this->thread($author);
        $post = ForumPost::create(['forum_thread_id' => $thread->id, 'user_id' => $author->id, 'body' => 'Hello']);

        $resolve = fn ($reference, $member) => ModerationSubject::resolve($reference, $member);

        $this->assertNotNull($resolve('forum_post:'.$post->id, $author));
        $this->assertNull($resolve('forum_post:'.$post->id, $other), 'somebody elses post');
        $this->assertNull($resolve('forum_thread:'.$thread->id, $other));
        $this->assertNull($resolve('forum_post:999999', $author), 'nothing there');
        $this->assertNull($resolve('user:'.$author->id, $author), 'not a kind of thing that can be named');
        $this->assertNull($resolve('forum_post:abc', $author));
        $this->assertNull($resolve(['forum_post:'.$post->id], $author));
        $this->assertNull($resolve(null, $author));
        $this->assertNotNull(ModerationSubject::url('forum_post', $post->id), 'the page the post is on');
    }

    // --- The moderation page -------------------------------------------------------------------------------------------------

    #[Test]
    public function the_moderation_page_shows_where_a_member_stands_and_everything_done(): void
    {
        $moderator = $this->as('moderator');
        $member = $this->member(['nickname' => 'Pat']);
        $this->moderation()->warn($member, $moderator, 'Keep to the topic', 'Second warning');
        $this->moderation()->ban($member->fresh(), $moderator, 'Kept going', now()->addDays(2));

        $this->actingAs($moderator)->get(route('acp.users.moderation.show', $member))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('acp/UserModeration')
                ->where('member.nickname', 'Pat')
                ->where('standing.banned', true)
                ->where('standing.reason', 'Kept going')
                ->has('history', 2)
                ->where('history.0.type', 'ban')
                ->where('history.1.type', 'warning')
                ->where('history.1.note', 'Second warning')
                ->where('history.1.actor', $moderator->nickname)
                ->where('can.warn', true)
                ->where('can.ban', true)
                ->where('refusal.warn', null)
                ->where('subject', null));
    }

    #[Test]
    public function the_page_says_why_a_moderator_cannot_act_on_a_colleague(): void
    {
        $moderator = $this->as('moderator');
        $editor = $this->as('editor');

        $this->actingAs($moderator)->get(route('acp.users.moderation.show', $editor))
            ->assertInertia(fn (Assert $page) => $page
                ->where('refusal.warn', 'Only an administrator can moderate a member of staff.')
                ->where('refusal.ban', 'Only an administrator can moderate a member of staff.'));
    }

    #[Test]
    public function a_report_can_open_the_page_with_what_it_was_about_attached(): void
    {
        $moderator = $this->as('moderator');
        $author = $this->member();
        $thread = $this->thread($author);
        $post = ForumPost::create(['forum_thread_id' => $thread->id, 'user_id' => $author->id, 'body' => 'Buy watches']);

        $this->actingAs($moderator)->get(route('acp.users.moderation.show', ['user' => $author, 'subject' => 'forum_post:'.$post->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('subject.reference', 'forum_post:'.$post->id)
                ->where('subject.label', 'Reply in "Buy cheap watches"'));

        // Somebody elses post is not attached to this member.
        $other = $this->member();
        $this->get(route('acp.users.moderation.show', ['user' => $other, 'subject' => 'forum_post:'.$post->id]))
            ->assertInertia(fn (Assert $page) => $page->where('subject', null));
    }

    #[Test]
    public function the_page_is_for_staff_who_may_see_users(): void
    {
        $member = $this->member();

        $this->actingAs($this->member())->get(route('acp.users.moderation.show', $member))->assertForbidden();
        $this->actingAs($this->as('editor'))->get(route('acp.users.moderation.show', $member))->assertForbidden();
    }

    // --- The routes ----------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_warning_needs_its_own_permission(): void
    {
        $member = $this->member();
        $editor = $this->as('editor');
        $editor->givePermissionTo('users.acp.ban');

        $this->actingAs($editor)->post(route('acp.users.warn', $member), ['reason' => 'Please stop that'])->assertForbidden();
        $this->assertSame(0, ModerationAction::query()->count());
    }

    #[Test]
    public function a_ban_needs_its_own_permission(): void
    {
        $member = $this->member();
        $editor = $this->as('editor');
        $editor->givePermissionTo('users.acp.warn');

        $this->actingAs($editor)->put(route('acp.users.ban', $member), ['reason' => 'Please stop that'])->assertForbidden();
        $this->actingAs($editor)->put(route('acp.users.unban', $member))->assertForbidden();
        $this->assertFalse($member->fresh()->is_banned);
    }

    #[Test]
    public function the_seeded_moderator_can_warn_ban_and_lift_from_the_admin_area(): void
    {
        $moderator = $this->as('moderator');
        $member = $this->member();

        $this->actingAs($moderator)
            ->post(route('acp.users.warn', $member), ['reason' => 'Please keep to the topic'])
            ->assertRedirect(route('acp.users.moderation.show', $member))
            ->assertSessionHas('success');

        $this->put(route('acp.users.ban', $member), ['reason' => 'Ignored the warning', 'until' => now()->addDays(3)->format('Y-m-d')])
            ->assertRedirect(route('acp.users.moderation.show', $member));

        $this->assertTrue($member->fresh()->is_banned);
        $this->assertTrue($member->fresh()->banned_until->isFuture());

        $this->put(route('acp.users.unban', $member), ['reason' => 'Appealed'])->assertRedirect();
        $this->assertFalse($member->fresh()->is_banned);

        $this->assertSame(
            [ModerationAction::WARNING, ModerationAction::BAN, ModerationAction::UNBAN],
            ModerationAction::query()->orderBy('id')->pluck('type')->all(),
        );
    }

    #[Test]
    public function a_ban_until_a_date_lasts_through_the_end_of_that_day_in_the_moderators_time_zone(): void
    {
        $this->travelTo('2026-11-01 12:00:00');
        $moderator = $this->as('moderator');
        $moderator->forceFill(['timezone' => 'Pacific/Auckland'])->save();
        $member = $this->member();

        $this->actingAs($moderator)->put(route('acp.users.ban', $member), ['reason' => 'Cool off please', 'until' => '2026-11-05'])->assertRedirect();

        // 5 November 23:59:59 in Auckland (UTC+13 then) is 5 November 10:59:59 UTC.
        $this->assertSame('2026-11-05 10:59:59', $member->fresh()->banned_until->utc()->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function a_reason_is_needed_to_warn_or_ban_and_without_one_nothing_is_done(): void
    {
        $moderator = $this->as('moderator');
        $member = $this->member();

        $this->actingAs($moderator)->post(route('acp.users.warn', $member), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post(route('acp.users.warn', $member), ['reason' => 'no'])->assertSessionHasErrors('reason');
        $this->put(route('acp.users.ban', $member), [])->assertSessionHasErrors('reason');
        $this->put(route('acp.users.ban', $member), ['reason' => str_repeat('a', 1001)])->assertSessionHasErrors('reason');
        $this->put(route('acp.users.ban', $member), ['reason' => 'Spam adverts', 'until' => 'next week'])->assertSessionHasErrors('until');

        $this->assertSame(0, ModerationAction::query()->count());
        $this->assertFalse($member->fresh()->is_banned);
    }

    #[Test]
    public function a_refusal_is_shown_to_the_moderator_and_nothing_is_done(): void
    {
        $moderator = $this->as('moderator');
        $editor = $this->as('editor');

        $this->actingAs($moderator)->post(route('acp.users.warn', $editor), ['reason' => 'Please stop that'])
            ->assertSessionHas('error', 'Only an administrator can moderate a member of staff.');
        $this->post(route('acp.users.warn', $moderator), ['reason' => 'Please stop that'])
            ->assertSessionHas('error', 'You cannot moderate your own account.');

        $this->assertSame(0, ModerationAction::query()->count());
    }

    #[Test]
    public function a_warning_or_ban_from_a_report_keeps_what_it_was_about(): void
    {
        $moderator = $this->as('moderator');
        $author = $this->member();
        $thread = $this->thread($author);
        $post = ForumPost::create(['forum_thread_id' => $thread->id, 'user_id' => $author->id, 'body' => 'Buy watches']);

        $this->actingAs($moderator)->post(route('acp.users.warn', $author), ['reason' => 'No adverts', 'subject' => 'forum_post:'.$post->id]);

        $action = ModerationAction::query()->sole();
        $this->assertSame('forum_post', $action->subject_type);
        $this->assertSame($post->id, $action->subject_id);

        // A reference to somebody elses post is dropped, not trusted.
        $this->post(route('acp.users.warn', $this->member()), ['reason' => 'No adverts', 'subject' => 'forum_post:'.$post->id]);
        $this->assertNull(ModerationAction::query()->latest('id')->first()->subject_type);
    }

    // --- Several at once -----------------------------------------------------------------------------------------------------

    #[Test]
    public function a_bulk_ban_needs_a_reason_and_bans_each_through_the_same_rules(): void
    {
        $moderator = $this->as('moderator');
        $spammers = User::factory()->count(3)->create();
        $editor = $this->as('editor');
        $admin = $this->as('admin');

        $this->actingAs($moderator)->patch(route('acp.users.bulk-update'), ['action' => 'ban', 'ids' => $spammers->pluck('id')->all()])
            ->assertSessionHasErrors('reason');
        $this->assertSame(0, User::query()->where('is_banned', true)->count());

        $this->patch(route('acp.users.bulk-update'), [
            'action' => 'ban',
            'ids' => [...$spammers->pluck('id')->all(), $editor->id, $admin->id, $moderator->id],
            'reason' => 'A wave of spam accounts',
            'notify' => false,
        ])->assertSessionHas('success', 'Banned 3 users.')
            ->assertSessionHas('error', fn (string $error) => str_contains($error, 'Not banned') && str_contains($error, $editor->nickname) && str_contains($error, $admin->nickname) && str_contains($error, $moderator->nickname));

        foreach ($spammers as $spammer) {
            $this->assertTrue($spammer->fresh()->is_banned);
            $this->assertSame('A wave of spam accounts', $spammer->fresh()->ban_reason);
        }
        $this->assertFalse($editor->fresh()->is_banned);
        $this->assertFalse($admin->fresh()->is_banned);
        $this->assertSame(3, ModerationAction::query()->where('type', ModerationAction::BAN)->count());
        $this->assertSame([false], ModerationAction::query()->pluck('notified')->unique()->all());
        Notification::assertNothingSent();
    }

    #[Test]
    public function a_bulk_ban_can_have_an_end_date_and_a_bulk_unban_is_recorded_for_each(): void
    {
        $moderator = $this->as('moderator');
        $members = User::factory()->count(2)->create();

        $this->actingAs($moderator)->patch(route('acp.users.bulk-update'), [
            'action' => 'ban',
            'ids' => $members->pluck('id')->all(),
            'reason' => 'A cooling-off period',
            'until' => now()->addDays(2)->format('Y-m-d'),
        ]);

        foreach ($members as $member) {
            $this->assertTrue($member->fresh()->banned_until->isFuture());
        }

        $this->patch(route('acp.users.bulk-update'), ['action' => 'unban', 'ids' => $members->pluck('id')->all()])
            ->assertSessionHas('success', 'Unbanned 2 users.');

        $this->assertSame(2, ModerationAction::query()->where('type', ModerationAction::UNBAN)->count());
        $this->assertSame(0, User::query()->where('is_banned', true)->count());
    }

    #[Test]
    public function members_already_banned_are_left_alone_by_a_bulk_ban(): void
    {
        $moderator = $this->as('moderator');
        $member = $this->member();
        $this->moderation()->ban($member, $moderator, 'First reason');

        $this->actingAs($moderator)->patch(route('acp.users.bulk-update'), ['action' => 'ban', 'ids' => [$member->id], 'reason' => 'Second reason'])
            ->assertSessionHas('success', 'No users required ban updates.');

        $this->assertSame('First reason', $member->fresh()->ban_reason);
    }

    // --- Reports link to it --------------------------------------------------------------------------------------------------

    #[Test]
    public function a_forum_report_links_to_the_moderation_page_of_whoever_wrote_it_when_you_may_use_it(): void
    {
        $author = $this->member();
        $thread = $this->thread($author);
        $post = ForumPost::create(['forum_thread_id' => $thread->id, 'user_id' => $author->id, 'body' => 'Buy watches']);
        ForumPostReport::create(['forum_post_id' => $post->id, 'reporter_id' => $this->member()->id, 'reason_category' => 'spam', 'reason' => 'Advert', 'status' => 'pending']);

        $moderator = $this->as('moderator');
        $moderator->givePermissionTo('forums.acp.view');

        $this->actingAs($moderator)->get(route('acp.forums.reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('reports.data.0.moderate_url', route('acp.users.moderation.show', ['user' => $author->id, 'subject' => 'forum_post:'.$post->id])));

        // Somebody who may neither warn nor ban is not offered it.
        $viewer = $this->as('editor');
        $viewer->givePermissionTo('forums.acp.view');
        $this->actingAs($viewer)->get(route('acp.forums.reports.index'))
            ->assertInertia(fn (Assert $page) => $page->where('reports.data.0.moderate_url', null));
    }

    // --- What the member can read --------------------------------------------------------------------------------------------

    #[Test]
    public function a_member_reads_their_own_warnings_and_never_the_note_or_who_wrote_it(): void
    {
        $moderator = $this->as('moderator');
        $member = $this->member();
        $other = $this->member();
        $this->moderation()->warn($member, $moderator, 'Keep to the topic', 'Staff only: second offence');
        $this->moderation()->warn($other, $moderator, 'Somebody elses warning');

        $response = $this->actingAs($member)->get(route('settings.standing'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('settings/AccountStanding')
            ->has('notices', 1)
            ->where('notices.0.reason', 'Keep to the topic')
            ->where('notices.0.type', 'warning'));

        $content = json_encode($response->viewData('page')['props']['notices']);
        $this->assertStringNotContainsString('Staff only', $content);
        $this->assertStringNotContainsString($moderator->nickname, $content);
        $this->assertStringNotContainsString('actor', $content);
        $this->assertStringNotContainsString('Somebody elses', $content);
    }

    #[Test]
    public function the_standing_page_is_for_signed_in_members(): void
    {
        $this->get(route('settings.standing'))->assertRedirect(route('login'));
    }

    #[Test]
    public function a_member_with_no_warnings_is_told_they_are_in_good_standing(): void
    {
        $this->actingAs($this->member())->get(route('settings.standing'))
            ->assertInertia(fn (Assert $page) => $page->has('notices', 0));
    }

    #[Test]
    public function a_member_who_was_suspended_and_came_back_sees_the_suspension_in_their_standing(): void
    {
        $this->freezeTime();
        $member = $this->member();
        $this->moderation()->ban($member, $this->as('moderator'), 'Cool off', now()->addHour());
        $this->travel(2)->hours();
        $this->moderation()->liftIfRunOut($member->fresh());

        $this->actingAs($member->fresh())->get(route('settings.standing'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('notices', 2)
                ->where('notices.0.type', 'unban')
                ->where('notices.1.type', 'ban')
                ->where('notices.1.reason', 'Cool off'));
    }

    // --- Where it is kept ----------------------------------------------------------------------------------------------------

    #[Test]
    public function the_history_goes_with_the_member_when_their_account_is_deleted(): void
    {
        $member = $this->member();
        $this->moderation()->warn($member, $this->as('moderator'), 'Please stop that');

        $member->delete();

        $this->assertSame(0, ModerationAction::query()->count());
    }

    #[Test]
    public function the_history_outlives_the_moderator_who_wrote_it(): void
    {
        $moderator = $this->as('moderator');
        $member = $this->member();
        $this->moderation()->warn($member, $moderator, 'Please stop that');

        $moderator->delete();

        $this->assertNull(ModerationAction::query()->sole()->actor_id);
    }

    #[Test]
    public function a_data_export_has_what_the_member_was_told_and_not_the_staff_note(): void
    {
        $moderator = $this->as('moderator');
        $member = $this->member();
        $this->moderation()->warn($member, $moderator, 'Please stop that', 'Staff only: they are a repeat offender');

        $build = new \ReflectionMethod(GenerateUserDataExport::class, 'buildPayload');
        $data = $build->invoke(new GenerateUserDataExport(DataExport::factory()->for($member)->create()->id), $member);

        $this->assertCount(1, $data['moderation']);
        $this->assertSame('Please stop that', $data['moderation'][0]['reason']);
        $this->assertStringNotContainsString('Staff only', json_encode($data['moderation']));
        $this->assertStringNotContainsString($moderator->nickname, json_encode($data['moderation']));
    }
    // --- What the first pass at these tests missed ---------------------------------------------------------------------------

    #[Test]
    public function an_administrator_can_be_warned_by_an_administrator_but_not_by_a_moderator(): void
    {
        $this->assertNull($this->moderation()->refusal($this->as('admin'), $this->as('admin'), 'warn'));
        $this->assertSame('Only an administrator can moderate a member of staff.', $this->moderation()->refusal($this->as('moderator'), $this->as('admin'), 'warn'));
    }

    #[Test]
    public function two_moderators_banning_from_old_copies_of_the_member_make_one_ban(): void
    {
        $member = $this->member();
        $first = User::query()->find($member->id);
        $second = User::query()->find($member->id);

        $this->moderation()->ban($first, $this->as('moderator'), 'Spam adverts');

        try {
            $this->moderation()->ban($second, $this->as('moderator'), 'Spam adverts too');
            $this->fail('The second copy banned a member who was already banned.');
        } catch (ModerationRefused $refused) {
            $this->assertStringContainsString('already banned', $refused->getMessage());
        }

        $this->assertSame(1, ModerationAction::query()->where('type', ModerationAction::BAN)->count());
        $this->assertSame('Spam adverts', $member->fresh()->ban_reason);
    }

    #[Test]
    public function asking_whether_a_ban_has_run_out_costs_no_query_unless_it_has(): void
    {
        // It is asked on every request a signed-in member makes.
        $member = $this->member();
        $forGood = $this->member();
        $this->moderation()->ban($forGood, $this->as('moderator'), 'Abuse');
        $forGood = $forGood->fresh();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->assertFalse($this->moderation()->liftIfRunOut($member));
        $this->assertFalse($this->moderation()->liftIfRunOut($forGood));

        $this->assertSame([], DB::getQueryLog());
    }

    #[Test]
    public function the_session_of_a_banned_member_is_ended_and_not_only_their_sign_in(): void
    {
        $member = $this->member();
        $this->actingAs($member)->withSession(['basket' => 'kept'])->get(route('dashboard'))->assertOk();

        $this->moderation()->ban($member, $this->as('moderator'), 'Spam');

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertNull(session('basket'));
    }

    #[Test]
    public function a_members_history_is_only_theirs(): void
    {
        $moderator = $this->as('moderator');
        $one = $this->member();
        $other = $this->member();
        $this->moderation()->warn($one, $moderator, 'Please stop that');
        $this->moderation()->warn($other, $moderator, 'Please stop that too');
        $this->moderation()->ban($other, $moderator, 'And that');

        $this->assertCount(1, $this->moderation()->history($one));
        $this->assertCount(2, $this->moderation()->history($other));
    }

    #[Test]
    public function a_note_or_reason_of_nothing_but_spaces_is_none(): void
    {
        $moderator = $this->as('moderator');
        $member = $this->member();

        $this->assertNull($this->moderation()->warn($member, $moderator, 'Please stop that', '   ')->note);

        $this->moderation()->ban($member, $moderator, 'Spam');
        $this->assertNull($this->moderation()->unban($member->fresh(), $moderator, " \n ")->reason);
    }

    #[Test]
    public function a_blog_comment_can_be_named_only_by_whoever_wrote_it(): void
    {
        $writer = $this->member();
        $other = $this->member();
        $blog = Blog::factory()->for($writer)->create(['title' => 'Launch day']);
        $comment = BlogComment::factory()->for($blog)->for($writer)->create();

        $subject = ModerationSubject::resolve('blog_comment:'.$comment->id, $writer);

        $this->assertSame('Comment on "Launch day"', $subject['label']);
        $this->assertNull(ModerationSubject::resolve('blog_comment:'.$comment->id, $other));
        $this->assertSame(route('blogs.view', $blog->slug), ModerationSubject::url('blog_comment', $comment->id));
    }

    #[Test]
    public function a_member_is_emailed_unless_the_moderator_says_not_to(): void
    {
        $moderator = $this->as('moderator');
        $member = $this->member();

        $this->actingAs($moderator)->post(route('acp.users.warn', $member), ['reason' => 'Please stop that']);
        Notification::assertSentTo($member, WarningIssued::class);

        $this->put(route('acp.users.ban', $member), ['reason' => 'Ignored the warning']);
        Notification::assertSentTo($member, AccountBanned::class);

        $this->put(route('acp.users.unban', $member));
        Notification::assertSentTo($member, BanLifted::class);

        $quiet = $this->member();
        $this->post(route('acp.users.warn', $quiet), ['reason' => 'Please stop that', 'notify' => false]);
        $this->put(route('acp.users.ban', $quiet), ['reason' => 'Ignored the warning', 'notify' => false]);
        Notification::assertNotSentTo($quiet, WarningIssued::class);
        Notification::assertNotSentTo($quiet, AccountBanned::class);
    }

    #[Test]
    public function a_refused_ban_or_lifting_is_shown_to_the_moderator(): void
    {
        $admin = $this->as('admin');
        $editor = $this->as('editor');
        $member = $this->member();

        $this->actingAs($this->as('moderator'))->put(route('acp.users.ban', $editor), ['reason' => 'Because I can'])
            ->assertSessionHas('error', 'Only an administrator can moderate a member of staff.');

        $this->actingAs($this->as('admin'))->put(route('acp.users.ban', $admin), ['reason' => 'Because I can'])
            ->assertSessionHas('error', 'An administrator cannot be banned. Take the administrator role away first.');

        $this->put(route('acp.users.unban', $member))->assertSessionHas('error', "{$member->nickname} is not banned.");
        $this->assertFalse($admin->fresh()->is_banned);
    }

    #[Test]
    public function a_report_does_not_offer_moderating_your_own_post(): void
    {
        $moderator = $this->as('moderator');
        $moderator->givePermissionTo('forums.acp.view');
        $thread = $this->thread($moderator);
        $post = ForumPost::create(['forum_thread_id' => $thread->id, 'user_id' => $moderator->id, 'body' => 'My own post']);
        ForumPostReport::create(['forum_post_id' => $post->id, 'reporter_id' => $this->member()->id, 'reason_category' => 'other', 'reason' => 'Odd', 'status' => 'pending']);

        $this->actingAs($moderator)->get(route('acp.forums.reports.index'))
            ->assertInertia(fn (Assert $page) => $page->where('reports.data.0.moderate_url', null));
    }
}
