<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Support\Members\StaffUserSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The pickers in the admin area that choose a member (who a support ticket is for, whose account a token is made on) look members up by a few
 * typed letters instead of being handed all of them: the Tokens page used to carry every member in its page, 1.4 MB at 20,000.
 */
class StaffUserSearchTest extends TestCase
{
    use RefreshDatabase;

    private ?User $admin = null;

    /**
     * The two routes that search, and a permission that opens each one.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function searches(): array
    {
        return [
            'the support picker' => ['acp.support.users.search', 'support.acp.create'],
            'the token picker' => ['acp.tokens.users.search', 'tokens.acp.create'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function routes(): array
    {
        return array_map(fn (array $search) => [$search[0]], self::searches());
    }

    private function admin(): User
    {
        if ($this->admin === null) {
            $this->admin = User::factory()->create(['nickname' => 'the-admin', 'email' => 'admin@example.test']);
            $this->admin->assignRole(Role::findOrCreate('admin'));
        }

        return $this->admin;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<string>
     */
    private function nicknames(string $route, array $query): array
    {
        return collect($this->actingAs($this->admin())->getJson(route($route, $query))->assertOk()->json('data'))->pluck('nickname')->all();
    }

    #[Test]
    #[DataProvider('routes')]
    public function it_finds_members_by_part_of_their_nickname_or_email_and_by_nothing_else(string $route): void
    {
        User::factory()->create(['nickname' => 'marigold', 'email' => 'm@example.test']);
        User::factory()->create(['nickname' => 'someone', 'email' => 'gold.digger@example.test']);
        User::factory()->create(['nickname' => 'unrelated', 'email' => 'u@example.test']);

        $this->assertEqualsCanonicalizing(['marigold', 'someone'], $this->nicknames($route, ['q' => 'gold']));
        $this->assertSame([], $this->nicknames($route, ['q' => 'nobody-is-called-this']));
    }

    #[Test]
    #[DataProvider('routes')]
    public function capital_letters_make_no_difference(string $route): void
    {
        User::factory()->create(['nickname' => 'Marigold', 'email' => 'm@example.test']);

        $this->assertSame(['Marigold'], $this->nicknames($route, ['q' => 'MARIGOLD']));
        $this->assertSame(['Marigold'], $this->nicknames($route, ['q' => 'marigo']));
    }

    #[Test]
    #[DataProvider('routes')]
    public function it_returns_the_id_nickname_and_email_and_nothing_more(string $route): void
    {
        $member = User::factory()->create(['nickname' => 'marigold', 'email' => 'm@example.test']);

        $row = $this->actingAs($this->admin())->getJson(route($route, ['q' => 'marigold']))->json('data.0');

        $this->assertSame(['id' => $member->id, 'nickname' => 'marigold', 'email' => 'm@example.test'], $row);
    }

    #[Test]
    #[DataProvider('routes')]
    public function it_returns_ten_at_most_in_the_order_of_their_nicknames(string $route): void
    {
        // Made in the opposite order, so that the order they come back in is the search's doing and not the order they were made in.
        foreach (range(14, 1) as $number) {
            User::factory()->create(['nickname' => sprintf('pick-%02d', $number)]);
        }

        $this->assertSame(array_map(fn (int $number) => sprintf('pick-%02d', $number), range(1, StaffUserSearch::LIMIT)), $this->nicknames($route, ['q' => 'pick-']));
    }

    #[Test]
    #[DataProvider('routes')]
    public function typed_wildcards_are_letters_not_patterns(string $route): void
    {
        User::factory()->create(['nickname' => 'a_b']);
        User::factory()->create(['nickname' => 'axb']);
        User::factory()->create(['nickname' => '100%']);
        User::factory()->create(['nickname' => '100 percent']);
        User::factory()->create(['nickname' => 'bang!', 'email' => 'bang@example.test']);
        User::factory()->create(['nickname' => 'first', 'email' => 'under_score@example.test']);
        User::factory()->create(['nickname' => 'second', 'email' => 'underXscore@example.test']);

        $this->assertSame(['first'], $this->nicknames($route, ['q' => 'under_score']));
        $this->assertSame(['a_b'], $this->nicknames($route, ['q' => 'a_b']));
        $this->assertSame(['100%'], $this->nicknames($route, ['q' => '100%']));
        // The character used to escape the others is a letter too.
        $this->assertSame(['bang!'], $this->nicknames($route, ['q' => 'bang!']));
    }

    #[Test]
    #[DataProvider('routes')]
    public function nothing_typed_finds_nobody_rather_than_everybody(string $route): void
    {
        User::factory()->count(3)->create();

        $this->assertSame([], $this->nicknames($route, []));
        $this->assertSame([], $this->nicknames($route, ['q' => '']));
    }

    #[Test]
    #[DataProvider('routes')]
    public function a_member_can_be_found_by_id_for_showing_a_choice_that_was_already_made(string $route): void
    {
        $member = User::factory()->create(['nickname' => 'marigold']);
        User::factory()->create(['nickname' => 'marigold-two']);

        $this->assertSame(['marigold'], $this->nicknames($route, ['id' => $member->id]));
    }

    #[Test]
    #[DataProvider('searches')]
    public function it_is_for_staff_with_the_permission_for_the_page_that_uses_it(string $route, string $permission): void
    {
        User::factory()->create(['nickname' => 'marigold']);

        $this->getJson(route($route, ['q' => 'mari']))->assertUnauthorized();

        $this->actingAs(User::factory()->create())->getJson(route($route, ['q' => 'mari']))->assertForbidden();

        // The admin area is for staff (a role) and then for what the page needs (a permission): the permission alone is not enough.
        $notStaff = User::factory()->create();
        $notStaff->givePermissionTo(Permission::findOrCreate($permission));
        $this->actingAs($notStaff)->getJson(route($route, ['q' => 'mari']))->assertForbidden();

        $staff = User::factory()->create()->assignRole(Role::findOrCreate('editor'));
        $staff->givePermissionTo(Permission::findOrCreate($permission));
        $this->actingAs($staff)->getJson(route($route, ['q' => 'mari']))->assertOk()->assertJsonPath('data.0.nickname', 'marigold');
    }

    #[Test]
    public function looking_at_tokens_is_not_enough_to_search_for_members_to_make_one_for(): void
    {
        $viewer = User::factory()->create()->assignRole(Role::findOrCreate('editor'));
        $viewer->givePermissionTo(Permission::findOrCreate('tokens.acp.view'));

        $this->actingAs($viewer)->get(route('acp.tokens.index'))->assertOk();

        $this->actingAs($viewer)->getJson(route('acp.tokens.users.search', ['q' => 'a']))->assertForbidden();
    }

    #[Test]
    public function the_tokens_page_no_longer_carries_every_member(): void
    {
        User::factory()->count(5)->create();

        $this->actingAs($this->admin())
            ->get(route('acp.tokens.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('acp/Tokens')->missing('userList'));
    }
}
