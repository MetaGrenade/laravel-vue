<?php

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Admin → Audit log: looking at what has been done, and nothing else (docs/audit-log.md).
 */
class AuditLogViewerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
    }

    private function signInAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin'));
        $this->actingAs($admin);

        return $admin;
    }

    private function entry(array $attributes = []): AuditLog
    {
        return AuditLog::factory()->create($attributes);
    }

    private function page(array $query = [])
    {
        return $this->get(route('acp.audit-log.index', $query));
    }

    // --- Who may look ---------------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_visitor_is_sent_to_sign_in(): void
    {
        $this->get(route('acp.audit-log.index'))->assertRedirect(route('login'));
    }

    #[Test]
    public function somebody_with_no_staff_role_is_refused(): void
    {
        $this->actingAs(User::factory()->create())->get(route('acp.audit-log.index'))->assertForbidden();
    }

    #[Test]
    public function staff_need_the_permission_to_look(): void
    {
        $editor = User::factory()->create();
        $editor->assignRole(Role::findOrCreate('editor'));
        $this->actingAs($editor)->get(route('acp.audit-log.index'))->assertForbidden();

        $editor->givePermissionTo(Permission::findOrCreate('audit.acp.view'));
        $this->app['auth']->forgetGuards();

        $this->actingAs($editor->fresh())->get(route('acp.audit-log.index'))->assertOk();
    }

    #[Test]
    public function an_admin_may_look(): void
    {
        $this->signInAdmin();

        $this->page()->assertOk()->assertInertia(fn (Assert $page) => $page->component('acp/AuditLog'));
    }

    #[Test]
    public function the_permission_is_made_by_the_seeder_and_given_to_admins_and_nothing_more_is_made_for_it(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->assertTrue(Role::findByName('admin')->hasPermissionTo('audit.acp.view'));
        $this->assertSame(['audit.acp.view'], Permission::query()->where('name', 'like', 'audit.%')->pluck('name')->all(), 'nothing in the log is created, edited or deleted from the admin area');
        $this->assertFalse(Role::findByName('editor')->hasPermissionTo('audit.acp.view'));
    }

    #[Test]
    public function there_is_no_way_to_change_the_log_from_the_admin_area(): void
    {
        $methods = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (str_contains($route->uri(), 'audit-log')) {
                array_push($methods, ...array_diff($route->methods(), ['HEAD']));
            }
        }

        $this->assertSame(['GET'], array_values(array_unique($methods)));
    }

    #[Test]
    public function looking_at_the_log_is_not_itself_an_entry(): void
    {
        $this->signInAdmin();

        $this->page()->assertOk();

        $this->assertSame(0, AuditLog::query()->count());
    }

    // --- What is shown --------------------------------------------------------------------------------------------------------------

    #[Test]
    public function entries_are_shown_newest_first_with_what_they_hold(): void
    {
        $this->signInAdmin();
        $old = $this->entry(['event' => 'users.verify', 'created_at' => now()->subHours(2)]);
        $new = $this->entry([
            'event' => 'users.ban',
            'actor_id' => 7,
            'actor_label' => 'Ed Editor (ed@example.com)',
            'subject_type' => User::class,
            'subject_id' => 9,
            'subject_label' => 'Troublemaker',
            'ip_address' => '198.51.100.20',
            'user_agent' => 'TestBrowser/1.0',
            'changes' => [['model' => 'User', 'id' => 9, 'label' => 'Troublemaker', 'action' => 'updated', 'old' => ['is_banned' => false], 'new' => ['is_banned' => true]]],
            'meta' => ['route' => 'acp.users.ban', 'method' => 'PUT', 'path' => '/acp/users/9/ban', 'status' => 302, 'fields' => []],
            'created_at' => now()->subHour(),
        ]);

        $this->page()->assertInertia(fn (Assert $page) => $page
            ->has('entries.data', 2)
            ->where('entries.data.0.id', $new->id)
            ->where('entries.data.1.id', $old->id)
            ->where('entries.data.0.actor', ['id' => 7, 'label' => 'Ed Editor (ed@example.com)'])
            ->where('entries.data.0.event', 'users.ban')
            ->where('entries.data.0.event_label', 'Banned a user')
            ->where('entries.data.0.outcome', 'succeeded')
            ->where('entries.data.0.subject', ['type' => 'User', 'id' => 9, 'label' => 'Troublemaker'])
            ->where('entries.data.0.ip_address', '198.51.100.20')
            ->where('entries.data.0.user_agent', 'TestBrowser/1.0')
            ->where('entries.data.0.changes.0.new.is_banned', true)
            ->where('entries.data.0.changes.0.old.is_banned', false)
            ->where('entries.data.0.meta.route', 'acp.users.ban')
            ->where('entries.data.0.created_at', fn ($at) => str_starts_with($at, '2026-10-15T11:00'))
            ->where('entries.data.1.subject', null)
            ->where('entries.data.1.changes', [])
            ->where('entries.meta.total', 2));
    }

    #[Test]
    public function an_action_without_a_name_of_its_own_reads_as_its_route(): void
    {
        $this->signInAdmin();
        $this->entry(['event' => 'commerce.products.price-update']);

        $this->page()->assertInertia(fn (Assert $page) => $page->where('entries.data.0.event_label', 'Commerce products price update'));
    }

    #[Test]
    public function an_entry_still_names_somebody_who_has_since_been_deleted(): void
    {
        $this->signInAdmin();
        $gone = User::factory()->create(['nickname' => 'Departed', 'email' => 'departed@example.com']);
        $this->entry(['actor_id' => $gone->id, 'actor_label' => 'Departed (departed@example.com)', 'subject_type' => User::class, 'subject_id' => $gone->id, 'subject_label' => 'Departed']);

        $gone->delete();

        $this->page()->assertInertia(fn (Assert $page) => $page
            ->where('entries.data.0.actor.label', 'Departed (departed@example.com)')
            ->where('entries.data.0.subject.label', 'Departed'));
    }

    #[Test]
    public function the_page_offers_the_actions_people_and_results_that_there_are(): void
    {
        $this->signInAdmin();
        $this->entry(['event' => 'users.ban', 'actor_id' => 3, 'actor_label' => 'Zed (z@example.com)']);
        $this->entry(['event' => 'users.ban', 'actor_id' => 3, 'actor_label' => 'Zed (z@example.com)']);
        $this->entry(['event' => 'system.update', 'actor_id' => 4, 'actor_label' => 'Amy (a@example.com)']);

        $this->page()->assertInertia(fn (Assert $page) => $page
            ->where('events', [['value' => 'system.update', 'label' => 'Changed the system settings'], ['value' => 'users.ban', 'label' => 'Banned a user']])
            ->where('actors', [['id' => 4, 'label' => 'Amy (a@example.com)'], ['id' => 3, 'label' => 'Zed (z@example.com)']])
            ->where('outcomes', ['succeeded', 'failed', 'denied']));
    }

    // --- Filters --------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function the_search_looks_at_who_what_to_what_and_where_from(): void
    {
        $this->signInAdmin();
        $this->entry(['actor_label' => 'Alice (alice@example.com)', 'event' => 'users.ban']);
        $this->entry(['actor_label' => 'Bob (bob@example.com)', 'event' => 'users.verify', 'subject_label' => 'Carol']);
        $this->entry(['actor_label' => 'Bob (bob@example.com)', 'event' => 'system.update', 'ip_address' => '192.0.2.99']);

        $ids = fn (string $search) => collect($this->page(['search' => $search])->viewData('page')['props']['entries']['data'])->pluck('event')->sort()->values()->all();

        $this->assertSame(['users.ban'], $ids('alice'));
        $this->assertSame(['users.verify'], $ids('carol'));
        $this->assertSame(['system.update'], $ids('192.0.2'));
        $this->assertSame(['system.update'], $ids('system.up'));
        $this->assertSame(['system.update', 'users.verify'], $ids('bob'));
        $this->assertSame([], $ids('nobody-by-this-name'));
    }

    #[Test]
    public function entries_can_be_narrowed_to_an_action_a_result_or_a_person(): void
    {
        $this->signInAdmin();
        $this->entry(['event' => 'users.ban', 'actor_id' => 1]);
        $this->entry(['event' => 'users.ban', 'actor_id' => 2, 'outcome' => AuditLog::DENIED]);
        $this->entry(['event' => 'system.update', 'actor_id' => 2, 'outcome' => AuditLog::FAILED]);

        $count = fn (array $query) => $this->page($query)->viewData('page')['props']['entries']['meta']['total'];

        $this->assertSame(2, $count(['event' => 'users.ban']));
        $this->assertSame(1, $count(['outcome' => 'denied']));
        $this->assertSame(1, $count(['outcome' => 'failed']));
        $this->assertSame(2, $count(['actor' => 2]));
        $this->assertSame(1, $count(['actor' => 2, 'event' => 'users.ban']));
        $this->assertSame(3, $count(['outcome' => 'nonsense']), 'an outcome that is not one is ignored');
        $this->assertSame(3, $count(['actor' => 'abc']), 'so is an actor that is not a number');
    }

    #[Test]
    public function everything_done_on_one_account_can_be_looked_at(): void
    {
        $admin = $this->signInAdmin();
        $person = User::factory()->create(['nickname' => 'Pat', 'email' => 'pat@example.com']);
        $other = User::factory()->create();
        $this->entry(['event' => 'users.ban', 'owner_type' => User::class, 'owner_id' => $person->id]);
        $this->entry(['event' => 'tokens.store', 'owner_type' => User::class, 'owner_id' => $person->id]);
        $this->entry(['event' => 'users.ban', 'owner_type' => User::class, 'owner_id' => $other->id]);
        $this->entry(['event' => 'orders.update', 'owner_type' => 'App\Models\Team', 'owner_id' => $person->id]); // a team that happens to have the same number
        $this->entry(['event' => 'system.update']);

        $this->page(['account' => $person->id])->assertInertia(fn (Assert $page) => $page
            ->where('entries.meta.total', 2)
            ->where('account', ['id' => $person->id, 'nickname' => 'Pat', 'email' => 'pat@example.com'])
            ->where('filters.account', (string) $person->id));

        $this->page()->assertInertia(fn (Assert $page) => $page->where('account', null)->where('entries.meta.total', 5));
        $this->assertNotNull($admin);
    }

    #[Test]
    public function a_range_of_days_includes_both_its_ends_in_the_viewers_own_time_zone(): void
    {
        $this->signInAdmin()->forceFill(['timezone' => 'Asia/Tokyo'])->save();

        // 2026-10-10 in Tokyo runs from 2026-10-09 15:00 to 2026-10-10 14:59:59 UTC.
        $this->entry(['event' => 'before', 'created_at' => '2026-10-09 14:59:59']);
        $this->entry(['event' => 'first-moment', 'created_at' => '2026-10-09 15:00:00']);
        $this->entry(['event' => 'last-moment', 'created_at' => '2026-10-10 14:59:59']);
        $this->entry(['event' => 'after', 'created_at' => '2026-10-10 15:00:00']);

        $events = fn (array $query) => collect($this->page($query)->viewData('page')['props']['entries']['data'])->pluck('event')->sort()->values()->all();

        $this->assertSame(['first-moment', 'last-moment'], $events(['from' => '2026-10-10', 'to' => '2026-10-10']));
        $this->assertSame(['after', 'first-moment', 'last-moment'], $events(['from' => '2026-10-10']));
        $this->assertSame(['before', 'first-moment', 'last-moment'], $events(['to' => '2026-10-10']));
    }

    #[Test]
    public function entries_made_in_the_same_second_are_shown_newest_first(): void
    {
        $this->signInAdmin();
        $first = $this->entry(['created_at' => now()]);
        $second = $this->entry(['created_at' => now()]);

        $this->page()->assertInertia(fn (Assert $page) => $page->where('entries.data.0.id', $second->id)->where('entries.data.1.id', $first->id));
    }

    #[Test]
    public function something_that_is_not_a_day_is_ignored(): void
    {
        $this->signInAdmin();
        $this->entry(['created_at' => '2026-03-01 12:00:00']);

        foreach (['yesterday', '2026-13-01', '2026-02-31', '10/10/2026', '2026-10-1', '<script>'] as $value) {
            $this->page(['from' => $value, 'to' => $value])->assertInertia(fn (Assert $page) => $page->where('entries.meta.total', 1));
        }
    }

    #[Test]
    public function the_rows_on_a_page_are_kept_between_one_and_a_hundred(): void
    {
        $this->signInAdmin();
        AuditLog::factory()->count(130)->create();

        $perPage = fn (mixed $value) => $this->page(['per_page' => $value])->viewData('page')['props']['entries']['meta']['per_page'];

        $this->assertSame(25, $perPage(null) ?? 25);
        $this->assertSame(100, $perPage(1000));
        $this->assertSame(1, $perPage(0));
        $this->assertSame(1, $perPage(-5));
        $this->assertSame(50, $perPage(50));
    }

    #[Test]
    public function the_log_is_paged_and_the_filters_follow_the_pages(): void
    {
        $this->signInAdmin();
        AuditLog::factory()->count(30)->create(['event' => 'users.ban']);
        AuditLog::factory()->count(5)->create(['event' => 'system.update']);

        $this->page(['event' => 'users.ban', 'per_page' => 10, 'page' => 3])->assertInertia(fn (Assert $page) => $page
            ->has('entries.data', 10)
            ->where('entries.meta.current_page', 3)
            ->where('entries.meta.last_page', 3)
            ->where('entries.meta.total', 30)
            ->where('entries.links.next', null)
            ->where('entries.links.prev', fn ($url) => str_contains($url, 'event=users.ban') && str_contains($url, 'page=2')));
    }
}
