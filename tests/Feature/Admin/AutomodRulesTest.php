<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\AutomodRule;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The screens for the rules of the forum's automatic moderation (docs/automod.md): who may use them, what each kind of rule needs,
 * what is stored, and the box that tries a text against them.
 */
class AutomodRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    /**
     * What a rule keeps, with its names in order: a JSON column hands the names back in the order its database likes (MySQL puts the shorter
     * ones first), which is nothing a rule depends on.
     *
     * @return array<string, mixed>
     */
    private function kept(AutomodRule $rule): array
    {
        $settings = $rule->settings;
        ksort($settings);

        return $settings;
    }

    // --- Who ------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function the_rules_are_an_administrators_unless_somebody_is_given_the_permission(): void
    {
        $this->get(route('acp.forums.automod.index'))->assertRedirect(route('login'));

        $moderator = User::factory()->create();
        $moderator->assignRole('moderator');
        $rule = AutomodRule::factory()->create();

        $this->actingAs($moderator)->get(route('acp.forums.automod.index'))->assertForbidden();
        $this->post(route('acp.forums.automod.store'), $this->words())->assertForbidden();
        $this->put(route('acp.forums.automod.update', $rule), $this->words())->assertForbidden();
        $this->patch(route('acp.forums.automod.enabled', $rule), ['is_enabled' => false])->assertForbidden();
        $this->delete(route('acp.forums.automod.destroy', $rule))->assertForbidden();

        $moderator->givePermissionTo('forums.acp.automod');
        $this->get(route('acp.forums.automod.index'))->assertOk();
        $this->assertDatabaseCount('automod_rules', 1);
    }

    // --- Making a rule --------------------------------------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function words(array $over = []): array
    {
        return array_replace_recursive([
            'name' => 'Watch spam',
            'type' => 'words',
            'action' => 'hold',
            'only_new_days' => null,
            'settings' => ['phrases' => ['cheap watches', 'replica'], 'whole_words' => false],
        ], $over);
    }

    #[Test]
    public function a_words_rule_is_made_and_what_is_stored_is_tidy(): void
    {
        $this->actingAs($this->admin())->post(route('acp.forums.automod.store'), $this->words([
            'name' => '  Watch spam  ',
            'only_new_days' => 14,
            'settings' => ['phrases' => ['  cheap watches ', 'replica', 'replica', '', '   '], 'whole_words' => true, 'something_else' => 'ignored'],
        ]))->assertRedirect()->assertSessionHas('success', 'The rule "Watch spam" has been added.');

        $rule = AutomodRule::query()->sole();

        $this->assertSame('Watch spam', $rule->name);
        $this->assertSame('words', $rule->type);
        $this->assertSame(['phrases' => ['cheap watches', 'replica'], 'whole_words' => true], $this->kept($rule));
        $this->assertSame('hold', $rule->action);
        $this->assertSame(14, $rule->only_new_days);
        $this->assertTrue($rule->is_enabled);
        $this->assertSame(0, $rule->hits);
    }

    #[Test]
    public function what_is_stored_is_tidy_whether_or_not_the_framework_trimmed_it_first(): void
    {
        $this->withoutMiddleware(TrimStrings::class);

        $this->actingAs($this->admin())->post(route('acp.forums.automod.store'), $this->words([
            'name' => '  Watch spam  ',
            'settings' => ['phrases' => ['  cheap watches ', 'replica', '   ']],
        ]))->assertSessionHasNoErrors();

        $rule = AutomodRule::query()->sole();

        $this->assertSame('Watch spam', $rule->name);
        $this->assertSame(['cheap watches', 'replica'], $rule->settings['phrases']);
    }

    #[Test]
    public function each_kind_of_rule_keeps_only_what_it_uses(): void
    {
        $admin = $this->actingAs($this->admin());

        $admin->post(route('acp.forums.automod.store'), ['name' => 'Links', 'type' => 'links', 'action' => 'reject', 'settings' => ['max_links' => 2, 'blocked_domains' => ['bad.example', 'bad.example', ' worse.example '], 'phrases' => ['ignored']]]);
        $admin->post(route('acp.forums.automod.store'), ['name' => 'Repeats', 'type' => 'duplicate', 'action' => 'hold', 'settings' => ['minutes' => 30, 'copies' => 2, 'posts' => 9]]);
        $admin->post(route('acp.forums.automod.store'), ['name' => 'Too fast', 'type' => 'rate', 'action' => 'hold', 'settings' => ['posts' => 5, 'minutes' => 10, 'copies' => 9]]);

        $this->assertSame(['blocked_domains' => ['bad.example', 'worse.example'], 'max_links' => 2], $this->kept(AutomodRule::query()->where('type', 'links')->sole()));
        $this->assertSame('reject', AutomodRule::query()->where('type', 'links')->sole()->action);
        $this->assertSame(['copies' => 2, 'minutes' => 30], $this->kept(AutomodRule::query()->where('type', 'duplicate')->sole()));
        $this->assertSame(['minutes' => 10, 'posts' => 5], $this->kept(AutomodRule::query()->where('type', 'rate')->sole()));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function badRules(): array
    {
        return [
            'no name' => [['name' => ''], 'name'],
            'a name that is too long' => [['name' => str_repeat('a', 121)], 'name'],
            'no kind' => [['type' => null], 'type'],
            'a kind there is not' => [['type' => 'magic'], 'type'],
            'no action' => [['action' => null], 'action'],
            'an action there is not' => [['action' => 'ban'], 'action'],
            'no days is not zero days' => [['only_new_days' => 0], 'only_new_days'],
            'too many days' => [['only_new_days' => 4000], 'only_new_days'],
            'no phrases' => [['settings' => ['phrases' => []]], 'settings.phrases'],
            'a phrase that is too long' => [['settings' => ['phrases' => [str_repeat('a', 121)]]], 'settings.phrases.0'],
            'too many phrases' => [['settings' => ['phrases' => array_map(fn ($n) => "phrase {$n}", range(1, 501))]], 'settings.phrases'],
        ];
    }

    /**
     * @param  array<string, mixed>  $over
     */
    #[Test]
    #[DataProvider('badRules')]
    public function a_rule_that_is_not_one_is_refused(array $over, string $field): void
    {
        $this->actingAs($this->admin())->post(route('acp.forums.automod.store'), array_replace($this->words(), $over))->assertSessionHasErrors($field);

        $this->assertDatabaseCount('automod_rules', 0);
    }

    #[Test]
    public function each_kind_of_rule_asks_for_what_it_needs(): void
    {
        $admin = $this->actingAs($this->admin());
        $base = ['name' => 'A rule', 'action' => 'hold'];

        $admin->post(route('acp.forums.automod.store'), $base + ['type' => 'links', 'settings' => []])->assertSessionHasErrors(['settings.max_links', 'settings.blocked_domains']);
        $admin->post(route('acp.forums.automod.store'), $base + ['type' => 'links', 'settings' => ['max_links' => 101]])->assertSessionHasErrors('settings.max_links');
        $admin->post(route('acp.forums.automod.store'), $base + ['type' => 'duplicate', 'settings' => ['minutes' => 0, 'copies' => 0]])->assertSessionHasErrors(['settings.minutes', 'settings.copies']);
        $admin->post(route('acp.forums.automod.store'), $base + ['type' => 'duplicate', 'settings' => ['minutes' => 1441, 'copies' => 21]])->assertSessionHasErrors(['settings.minutes', 'settings.copies']);
        $admin->post(route('acp.forums.automod.store'), $base + ['type' => 'rate', 'settings' => []])->assertSessionHasErrors(['settings.posts', 'settings.minutes']);
        $admin->post(route('acp.forums.automod.store'), $base + ['type' => 'rate', 'settings' => ['posts' => 101, 'minutes' => 10]])->assertSessionHasErrors('settings.posts');

        $this->assertDatabaseCount('automod_rules', 0);

        $admin->post(route('acp.forums.automod.store'), $base + ['type' => 'links', 'settings' => ['max_links' => 0]])->assertSessionHasNoErrors();
        $admin->post(route('acp.forums.automod.store'), $base + ['type' => 'links', 'settings' => ['blocked_domains' => ['bad.example']]])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('automod_rules', 2);
    }

    #[Test]
    public function a_list_of_nothing_but_blank_lines_is_no_list(): void
    {
        $admin = $this->actingAs($this->admin());

        $admin->post(route('acp.forums.automod.store'), $this->words(['settings' => ['phrases' => ['', '   ']]]))->assertSessionHasErrors('settings.phrases');
        $admin->post(route('acp.forums.automod.store'), ['name' => 'Links', 'type' => 'links', 'action' => 'hold', 'settings' => ['blocked_domains' => ['', '  ']]])->assertSessionHasErrors('settings.max_links');

        $this->assertDatabaseCount('automod_rules', 0);
    }

    #[Test]
    public function blank_lines_among_the_phrases_are_left_out_and_do_not_fail_the_rule(): void
    {
        $this->actingAs($this->admin())->post(route('acp.forums.automod.store'), $this->words(['settings' => ['phrases' => ['one', '', 'two', '   ']]]))->assertSessionHasNoErrors();

        $this->assertSame(['one', 'two'], AutomodRule::query()->sole()->settings['phrases']);
    }

    // --- Changing one ---------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_rule_is_changed_and_keeps_its_kind_and_its_count(): void
    {
        $rule = AutomodRule::factory()->words(['cheap watches'])->create(['hits' => 7]);

        $this->actingAs($this->admin())->put(route('acp.forums.automod.update', $rule), [
            'name' => 'Renamed',
            'type' => 'rate',
            'action' => 'reject',
            'only_new_days' => 30,
            'is_enabled' => false,
            'settings' => ['phrases' => ['replica'], 'whole_words' => true, 'posts' => 3, 'minutes' => 5],
        ])->assertRedirect()->assertSessionHas('success', 'The rule "Renamed" has been saved.');

        $rule->refresh();
        $this->assertSame('Renamed', $rule->name);
        $this->assertSame('words', $rule->type, 'what a rule looks for is what it is');
        $this->assertSame(['phrases' => ['replica'], 'whole_words' => true], $this->kept($rule));
        $this->assertSame('reject', $rule->action);
        $this->assertSame(30, $rule->only_new_days);
        $this->assertFalse($rule->is_enabled);
        $this->assertSame(7, $rule->hits);
    }

    #[Test]
    public function a_rule_is_switched_on_and_off_and_deleted(): void
    {
        $admin = $this->actingAs($this->admin());
        $rule = AutomodRule::factory()->create();

        $admin->patch(route('acp.forums.automod.enabled', $rule), ['is_enabled' => false])->assertSessionHas('success', 'The rule "Cheap watches" is off.');
        $this->assertFalse($rule->fresh()->is_enabled);

        $admin->patch(route('acp.forums.automod.enabled', $rule), ['is_enabled' => true])->assertSessionHas('success', 'The rule "Cheap watches" is on.');
        $this->assertTrue($rule->fresh()->is_enabled);

        $admin->patch(route('acp.forums.automod.enabled', $rule), [])->assertSessionHasErrors('is_enabled');

        $admin->delete(route('acp.forums.automod.destroy', $rule))->assertRedirect();
        $this->assertModelMissing($rule);
    }

    #[Test]
    public function a_rule_there_is_not_is_not_found(): void
    {
        $admin = $this->actingAs($this->admin());

        $admin->put(route('acp.forums.automod.update', 999999), $this->words())->assertNotFound();
        $admin->delete(route('acp.forums.automod.destroy', 999999))->assertNotFound();
    }

    // --- What is shown --------------------------------------------------------------------------------------------------------

    #[Test]
    public function the_page_lists_the_rules_with_how_often_each_has_caught_something(): void
    {
        AutomodRule::factory()->words(['cheap watches'])->create(['name' => 'First', 'hits' => 4, 'last_hit_at' => now()]);
        AutomodRule::factory()->rate(5, 10)->rejecting()->off()->create(['name' => 'Second']);

        $this->actingAs($this->admin())->get(route('acp.forums.automod.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('acp/ForumAutomod')
                ->has('rules', 2)
                ->where('rules.0.name', 'First')
                ->where('rules.0.hits', 4)
                ->where('rules.0.settings.phrases', ['cheap watches'])
                ->where('rules.1.name', 'Second')
                ->where('rules.1.action', 'reject')
                ->where('rules.1.is_enabled', false)
                ->where('tested', null));
    }

    #[Test]
    public function a_text_can_be_tried_against_the_rules_that_look_at_text_and_nothing_is_counted(): void
    {
        $words = AutomodRule::factory()->words(['cheap watches'])->create(['name' => 'Watch spam']);
        AutomodRule::factory()->links(1, [])->rejecting()->create(['name' => 'Few links']);
        AutomodRule::factory()->duplicates()->create(['name' => 'Repeats']);
        AutomodRule::factory()->rate()->create(['name' => 'Too fast']);
        AutomodRule::factory()->words(['cheap watches'])->off()->create(['name' => 'Off']);

        $this->actingAs($this->admin())->get(route('acp.forums.automod.index', ['test' => 'CHEAP watches at https://a.example and https://b.example']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('test', 'CHEAP watches at https://a.example and https://b.example')
                ->where('tested', [
                    ['rule' => 'Watch spam', 'action' => 'hold', 'detail' => 'contains "cheap watches"'],
                    ['rule' => 'Few links', 'action' => 'reject', 'detail' => '2 links, and the most allowed is 1'],
                ]));

        $this->assertSame(0, $words->fresh()->hits);
    }

    #[Test]
    public function a_text_that_breaks_no_rule_is_an_empty_list_and_not_nothing(): void
    {
        AutomodRule::factory()->words(['cheap watches'])->create();

        $this->actingAs($this->admin())->get(route('acp.forums.automod.index', ['test' => 'A perfectly good post']))
            ->assertInertia(fn (Assert $page) => $page->where('tested', []));
    }

    #[Test]
    public function the_text_to_try_has_a_limit(): void
    {
        $this->actingAs($this->admin())->get(route('acp.forums.automod.index', ['test' => str_repeat('a', 5001)]))->assertSessionHasErrors('test');
    }

    // --- The audit log --------------------------------------------------------------------------------------------------------

    #[Test]
    public function changes_to_the_rules_are_in_the_audit_log_as_they_were_and_became(): void
    {
        $admin = $this->actingAs($this->admin());

        $admin->post(route('acp.forums.automod.store'), $this->words());
        $rule = AutomodRule::query()->sole();
        $admin->put(route('acp.forums.automod.update', $rule), $this->words(['name' => 'Renamed', 'action' => 'reject']));
        $admin->delete(route('acp.forums.automod.destroy', $rule));

        $entries = AuditLog::query()->where('event', 'like', 'forums.automod.%')->orderBy('id')->get();

        $this->assertSame(['forums.automod.store', 'forums.automod.update', 'forums.automod.destroy'], $entries->pluck('event')->all());

        $update = $entries[1]->changes[0];
        $this->assertSame('AutomodRule', $update['model']);
        $this->assertSame('Watch spam', $update['old']['name']);
        $this->assertSame('Renamed', $update['new']['name']);
        $this->assertSame('hold', $update['old']['action']);
        $this->assertSame('reject', $update['new']['action']);
    }
}
