<?php

namespace Tests\Feature\Ownership;

use App\Models\Address;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProviderSubscription;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Support\Commerce\AddressBook;
use App\Support\Commerce\AddressData;
use App\Support\Entitlements\Entitlements;
use App\Support\Ownership;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use PhpToken;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Tests\Support\StandInTeam;
use Tests\TestCase;

/**
 * Teams arrive in 1.1 (docs/ownership.md). Until then a person is their own only owner, so nothing in 1.0 can tell "the records
 * this user owns" from "the records with this user's id". These tests give somebody a second owner, a stand-in for a team, and
 * check that everything a person is allowed to see or do follows the owners and nothing else: not the `user_id` that records who
 * acted, and not a guess that an owner is a user. A place that compared ids would pass every other test and fail here.
 */
class OwnerResolutionTest extends TestCase
{
    use RefreshDatabase;

    /** The user acts for these teams as well as for themselves; everyone else for themselves only. */
    private function actsFor(User $member, StandInTeam ...$teams): void
    {
        Ownership::resolveUsing(
            fn (User $user) => $user->is($member) ? [$user, ...$teams] : [$user],
            fn (User $user) => $user->is($member) && $teams !== [] ? $teams[0] : $user,
        );
    }

    private function orderOwnedBy(Model $owner, array $state = []): Order
    {
        $order = Order::factory()->create($state);
        $order->assignOwner($owner)->save();

        return $order;
    }

    private function addressOwnedBy(Model $owner): Address
    {
        return Address::factory()->create(['owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->getKey()]);
    }

    private function grant(Model $owner, string $key, ?int $value = null, bool $limit = false): UserEntitlement
    {
        $entitlement = Entitlement::query()->where('key', $key)->first()
            ?? ($limit ? Entitlement::factory()->limit() : Entitlement::factory())->create(['key' => $key, 'label' => ucfirst($key)]);

        return UserEntitlement::factory()->create([
            'entitlement_id' => $entitlement->id,
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'value' => $value,
        ]);
    }

    // --- Orders --------------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_user_may_view_the_orders_of_every_owner_they_act_for_and_no_others(): void
    {
        $user = User::factory()->create();
        $team = StandInTeam::withId(7);
        $this->actsFor($user, $team);

        $mine = $this->orderOwnedBy($user);
        $theirs = $this->orderOwnedBy($team);
        $anotherTeams = $this->orderOwnedBy(StandInTeam::withId(8));
        $anotherUsers = $this->orderOwnedBy(User::factory()->create());

        $this->assertTrue(Gate::forUser($user)->allows('view', $mine));
        $this->assertTrue(Gate::forUser($user)->allows('view', $theirs), 'the team the user acts for owns it');
        $this->assertFalse(Gate::forUser($user)->allows('view', $anotherTeams));
        $this->assertFalse(Gate::forUser($user)->allows('view', $anotherUsers));

        $this->assertEqualsCanonicalizing(
            [$mine->id, $theirs->id],
            Order::query()->visibleTo($user)->pluck('id')->all(),
        );
    }

    #[Test]
    public function without_the_team_the_same_user_cannot_view_its_orders(): void
    {
        $user = User::factory()->create();
        $team = StandInTeam::withId(7);
        $order = $this->orderOwnedBy($team);

        $this->actsFor($user, $team);
        $this->assertTrue(Gate::forUser($user)->allows('view', $order));

        Ownership::flush();
        $this->assertFalse(Gate::forUser($user)->allows('view', $order), 'the owners given are what grants it, not the data');
        $this->assertSame(0, Order::query()->visibleTo($user)->count());
    }

    #[Test]
    public function the_user_who_acted_is_not_the_owner(): void
    {
        $user = User::factory()->create();
        $this->actsFor($user, StandInTeam::withId(7));

        // Placed by the user, for a team they do not act for: the user_id records who clicked, not who may see it.
        $order = $this->orderOwnedBy(StandInTeam::withId(8), ['user_id' => $user->id]);

        $this->assertFalse(Gate::forUser($user)->allows('view', $order));
        $this->assertSame(0, Order::query()->visibleTo($user)->count());
    }

    #[Test]
    public function an_owner_of_another_kind_is_never_mistaken_for_the_user_with_the_same_key(): void
    {
        $user = User::factory()->create();
        $this->actsFor($user, StandInTeam::withId(7));

        // A team whose id is the user's id: a check that compared ids (or only owner_id) would let the user in.
        $order = $this->orderOwnedBy(StandInTeam::withId($user->id));

        $this->assertFalse(Gate::forUser($user)->allows('view', $order));
        $this->assertSame(0, Order::query()->visibleTo($user)->count());
    }

    #[Test]
    public function a_team_order_opens_for_a_member_on_the_site_and_for_nobody_else(): void
    {
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $this->actsFor($member, StandInTeam::withId(7));

        $order = $this->orderOwnedBy(StandInTeam::withId(7), ['user_id' => $outsider->id]);
        $url = route('shop.checkout.complete', ['order' => $order->public_id]);

        $this->actingAs($member)->get($url)->assertOk();
        $this->actingAs($outsider)->get($url)->assertForbidden();
    }

    #[Test]
    public function the_order_history_lists_what_the_teams_a_user_acts_for_ordered(): void
    {
        $member = User::factory()->create();
        $this->actsFor($member, StandInTeam::withId(7));

        $own = $this->orderOwnedBy($member);
        $team = $this->orderOwnedBy(StandInTeam::withId(7), ['user_id' => User::factory()->create()->id]);
        $this->orderOwnedBy(StandInTeam::withId(8), ['user_id' => $member->id]);

        $this->actingAs($member)
            ->get(route('shop.orders'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('commerce/Orders')
                ->has('orders.data', 2)
                ->where('orders.data', fn ($orders) => collect($orders)->pluck('number')->sort()->values()->all()
                    === collect([$own->number, $team->number])->sort()->values()->all()));
    }

    // --- Addresses -----------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_user_may_use_the_saved_addresses_of_every_owner_they_act_for_and_no_others(): void
    {
        $user = User::factory()->create();
        $this->actsFor($user, StandInTeam::withId(7));

        $mine = $this->addressOwnedBy($user);
        $theirs = $this->addressOwnedBy(StandInTeam::withId(7));
        $anotherTeams = $this->addressOwnedBy(StandInTeam::withId(8));

        foreach (['view', 'update', 'delete'] as $ability) {
            $this->assertTrue(Gate::forUser($user)->allows($ability, $mine), "$ability, own");
            $this->assertTrue(Gate::forUser($user)->allows($ability, $theirs), "$ability, the team's");
            $this->assertFalse(Gate::forUser($user)->allows($ability, $anotherTeams), "$ability, another team's");
        }

        $this->assertEqualsCanonicalizing(
            [$mine->id, $theirs->id],
            app(AddressBook::class)->for($user)->pluck('id')->all(),
        );
    }

    #[Test]
    public function a_team_address_can_be_removed_by_a_member_and_by_nobody_else(): void
    {
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $this->actsFor($member, StandInTeam::withId(7));

        $address = $this->addressOwnedBy(StandInTeam::withId(7));

        $this->actingAs($outsider)->delete(route('settings.addresses.destroy', $address))->assertForbidden();
        $this->assertModelExists($address);

        $this->actingAs($member)->delete(route('settings.addresses.destroy', $address))->assertRedirect();
        $this->assertModelMissing($address);
    }

    #[Test]
    public function an_address_saved_by_a_user_belongs_to_the_owner_new_records_are_given_to(): void
    {
        $user = User::factory()->create();
        $team = StandInTeam::withId(7);
        $this->actsFor($user, $team);

        $address = app(AddressBook::class)->create($user, Address::factory()->make()->only(['name', 'line1', 'city', 'postal_code', 'country']));

        $this->assertTrue($address->isOwnedBy($team));
        $this->assertFalse($address->isOwnedBy($user));

        // And anybody else still saves to their own account.
        $other = User::factory()->create();
        $this->assertTrue(app(AddressBook::class)->create($other, Address::factory()->make()->only(['name', 'line1', 'city', 'postal_code', 'country']))->isOwnedBy($other));
    }

    #[Test]
    public function an_address_already_saved_for_the_team_is_found_again_not_saved_twice(): void
    {
        $user = User::factory()->create();
        $team = StandInTeam::withId(7);
        $this->actsFor($user, $team);

        $data = new AddressData(name: 'Ada Lovelace', line1: '1 Crescent Road', city: 'London', country: 'GB', postalCode: 'N1 1AA');
        $book = app(AddressBook::class);

        $first = $book->remember($user, $data);
        $second = $book->remember($user, $data);

        $this->assertTrue($first->isOwnedBy($team));
        $this->assertTrue($first->is_default, 'the first address of the team is its default');
        $this->assertTrue($second->is($first), 'looked for among the addresses of the owner it would be saved to');
        $this->assertSame(1, Address::query()->count());
    }

    // --- Entitlements --------------------------------------------------------------------------------------------------------

    #[Test]
    public function what_a_team_holds_reaches_the_people_who_act_for_it(): void
    {
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $team = StandInTeam::withId(7);
        $this->actsFor($member, $team);

        $this->grant($team, 'reports');

        $this->assertTrue($member->canUse('reports'));
        $this->assertFalse($outsider->canUse('reports'));
        $this->assertSame(['reports'], array_column(app(Entitlements::class)->held($member), 'key'));
        $this->assertSame([], app(Entitlements::class)->held($outsider));
        $this->assertTrue(app(Entitlements::class)->for($team)->has('reports'), 'asked of the owner itself');
    }

    #[Test]
    public function limits_add_up_across_the_owners_a_user_acts_for(): void
    {
        $member = User::factory()->create();
        $team = StandInTeam::withId(7);
        $this->actsFor($member, $team);

        $this->grant($member, 'seats', 2, limit: true);
        $this->grant($team, 'seats', 3, limit: true);
        $this->grant(StandInTeam::withId(8), 'seats', 50, limit: true);

        $this->assertSame(5, $member->limitOf('seats'));
        $this->assertSame(5, collect(app(Entitlements::class)->held($member))->firstWhere('key', 'seats')['value']);
    }

    // --- Subscriptions -------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_user_sees_the_recurring_payments_of_every_owner_they_act_for_and_no_others(): void
    {
        $user = User::factory()->create();
        $this->actsFor($user, StandInTeam::withId(7));

        $mine = $this->subscriptionOwnedBy($user);
        $theirs = $this->subscriptionOwnedBy(StandInTeam::withId(7));
        $this->subscriptionOwnedBy(StandInTeam::withId(8));

        $this->assertEqualsCanonicalizing(
            [$mine->id, $theirs->id],
            ProviderSubscription::query()->visibleTo($user)->pluck('id')->all(),
        );
    }

    private function subscriptionOwnedBy(Model $owner): ProviderSubscription
    {
        $order = $this->orderOwnedBy($owner);

        return ProviderSubscription::factory()->create([
            'order_id' => $order->id,
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
        ]);
    }

    // --- Payments ------------------------------------------------------------------------------------------------------------

    #[Test]
    public function payments_are_found_through_the_owner_like_everything_else(): void
    {
        $user = User::factory()->create();
        $this->actsFor($user, StandInTeam::withId(7));

        $mine = Payment::factory()->create(['owner_type' => $user->getMorphClass(), 'owner_id' => $user->id]);
        $theirs = Payment::factory()->create(['owner_type' => StandInTeam::withId(7)->getMorphClass(), 'owner_id' => 7]);
        Payment::factory()->create(['owner_type' => StandInTeam::withId(8)->getMorphClass(), 'owner_id' => 8]);

        $this->assertEqualsCanonicalizing(
            [$mine->id, $theirs->id],
            Payment::query()->visibleTo($user)->pluck('id')->all(),
        );
    }

    // --- The way the owners are given ----------------------------------------------------------------------------------------

    #[Test]
    public function in_1_0_a_person_is_their_own_only_owner(): void
    {
        $user = User::factory()->create();

        $this->assertSame([$user], Ownership::ownersFor($user));
        $this->assertSame($user, Ownership::newRecordOwnerFor($user));
    }

    #[Test]
    public function the_owners_given_are_used_for_new_records_only_when_they_say_so(): void
    {
        $user = User::factory()->create();
        $team = StandInTeam::withId(7);

        Ownership::resolveUsing(fn (User $user) => [$user, $team]);

        $this->assertSame([$user, $team], Ownership::ownersFor($user));
        $this->assertSame($user, Ownership::newRecordOwnerFor($user), 'a new record is the user\'s own unless said otherwise');

        Ownership::resolveUsing(fn (User $user) => [$user, $team], fn () => $team);
        $this->assertSame($team, Ownership::newRecordOwnerFor($user));

        Ownership::flush();
        $this->assertSame([$user], Ownership::ownersFor($user));
        $this->assertSame($user, Ownership::newRecordOwnerFor($user));
    }

    #[Test]
    public function owners_may_be_given_as_any_iterable(): void
    {
        $user = User::factory()->create();
        $team = StandInTeam::withId(7);

        Ownership::resolveUsing(function (User $user) use ($team) {
            yield $user;
            yield $team;
        });

        $this->assertSame([$user, $team], Ownership::ownersFor($user));
    }

    #[Test]
    #[Depends('owners_may_be_given_as_any_iterable')]
    public function owners_given_in_one_test_are_gone_in_the_next(): void
    {
        // The test before this one left owners given and did not clear them: the base test case does, after every test.
        $user = User::factory()->create();

        $this->assertSame([$user], Ownership::ownersFor($user));
    }

    // --- Nothing goes round the owner ----------------------------------------------------------------------------------------

    /**
     * The tests above prove the places that exist follow the owner. This is for the place that does not exist yet: a policy or a query
     * written later that asks `user_id` whether a person may have a record. It reads the source, so it is blunt, and it is the first
     * thing to look at when it fails: either the code goes round the owner, or it has a good reason and belongs in the allowances.
     */
    #[Test]
    public function no_policy_of_an_owned_model_asks_who_the_user_is(): void
    {
        foreach ($this->ownedModels() as $model) {
            $policy = base_path("app/Policies/{$model}Policy.php");

            if (! is_file($policy)) {
                continue;
            }

            $source = $this->withoutComments((string) file_get_contents($policy));

            $this->assertStringNotContainsString('user_id', $source, "{$model}Policy must go through Ownership, not user_id");
            $this->assertStringContainsString('isOwnedByAnyOwnerOf', $source, "{$model}Policy must ask the owners the user acts for");
        }
    }

    #[Test]
    public function no_code_finds_an_owned_model_by_user_id(): void
    {
        $owned = $this->ownedModels();
        $this->assertNotEmpty($owned);

        $found = [];

        foreach ($this->sourceFiles() as $path => $source) {
            // A statement at a time, so a query chained over several lines is read as one.
            foreach (explode(';', $source) as $statement) {
                foreach ($owned as $model) {
                    if (preg_match('/\b'.$model.'::/', $statement) !== 1) {
                        continue;
                    }

                    if (preg_match('/->(?:or)?where(?:In|Not|NotIn)?\(\s*[\'"](?:\w+\.)?user_id[\'"]|->(?:or)?whereUserId\(/i', $statement) === 1) {
                        $found[] = "$path: $model";
                    }
                }
            }
        }

        $this->assertSame([], $found, "These find an owned model by the user who acted, not its owner (use visibleTo() or ownedBy()):\n".implode("\n", $found));
    }

    #[Test]
    public function the_scans_look_at_the_files_they_are_meant_to(): void
    {
        // The two scans above would pass for ever if they looked at nothing: check they are looking at the right files.
        $this->assertContains('Order', $this->ownedModels());
        $this->assertContains('Address', $this->ownedModels());
        $this->assertFileExists(base_path('app/Policies/OrderPolicy.php'));
        $this->assertArrayHasKey('app/Support/Commerce/CustomerOrders.php', $this->sourceFiles());
    }

    /**
     * The code of a file, for a scan: what a comment says about `user_id` is not a use of it.
     */
    private function withoutComments(string $source): string
    {
        $code = '';

        foreach (PhpToken::tokenize($source) as $token) {
            if (! $token->is([T_COMMENT, T_DOC_COMMENT])) {
                $code .= $token->text;
            }
        }

        return $code;
    }

    /**
     * @return list<string> The short names of the models that carry an owner.
     */
    private function ownedModels(): array
    {
        $models = [];

        foreach ((new Finder)->files()->in(base_path('app/Models'))->name('*.php') as $file) {
            if (str_contains($file->getContents(), 'use HasOwner;')) {
                $models[] = $file->getBasename('.php');
            }
        }

        sort($models);

        return $models;
    }

    /**
     * @return array<string, string> Every file under app/ other than the models themselves, by its path from the project's root.
     */
    private function sourceFiles(): array
    {
        $files = [];

        foreach ((new Finder)->files()->in(base_path('app'))->name('*.php')->notPath('Models') as $file) {
            $files['app/'.str_replace('\\', '/', $file->getRelativePathname())] = $this->withoutComments($file->getContents());
        }

        if ($files === []) {
            throw new RuntimeException('Found no source to read.');
        }

        return $files;
    }
}
