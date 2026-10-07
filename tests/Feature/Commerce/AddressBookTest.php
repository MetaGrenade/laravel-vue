<?php

namespace Tests\Feature\Commerce;

use App\Models\Address;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AddressBookTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function fields(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Ada Lovelace',
            'line1' => '12 Analytical Way',
            'city' => 'London',
            'postal_code' => 'N1 1AA',
            'country' => 'GB',
        ], $overrides);
    }

    #[Test]
    public function the_address_book_requires_signing_in(): void
    {
        $this->get(route('settings.addresses.index'))->assertRedirect(route('login'));
        $this->post(route('settings.addresses.store'), $this->fields())->assertRedirect(route('login'));
    }

    #[Test]
    public function it_lists_only_the_customers_own_addresses_with_the_default_first(): void
    {
        $user = User::factory()->create();
        $other = Address::factory()->forOwner($user)->create(['name' => 'Work']);
        $default = Address::factory()->forOwner($user)->default()->create(['name' => 'Home']);
        Address::factory()->forOwner(User::factory()->create())->create(['name' => 'Someone else']);

        $this->actingAs($user)
            ->get(route('settings.addresses.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Addresses')
                ->has('addresses', 2)
                ->where('addresses.0.id', $default->id)
                ->where('addresses.1.id', $other->id)
                ->has('countries'));
    }

    #[Test]
    public function an_address_can_be_added_and_belongs_to_its_owner(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('settings.addresses.store'), $this->fields(['label' => 'Home', 'country' => 'gb']))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $address = Address::sole();
        $this->assertTrue($address->isOwnedBy($user));
        $this->assertSame('GB', $address->country, 'country codes are stored in capitals');
        $this->assertSame('Home', $address->label);
        $this->assertFalse($address->is_default);
    }

    #[Test]
    public function an_address_can_be_made_the_default_when_added_and_only_one_is_the_default(): void
    {
        $user = User::factory()->create();
        $first = Address::factory()->forOwner($user)->default()->create();

        $this->actingAs($user)->post(route('settings.addresses.store'), $this->fields(['is_default' => true]));

        $this->assertSame(1, Address::query()->visibleTo($user)->where('is_default', true)->count());
        $this->assertFalse($first->fresh()->is_default);
    }

    #[Test]
    public function the_default_can_be_changed(): void
    {
        $user = User::factory()->create();
        $first = Address::factory()->forOwner($user)->default()->create();
        $second = Address::factory()->forOwner($user)->create();

        $this->actingAs($user)->put(route('settings.addresses.default', $second))->assertRedirect();

        $this->assertTrue($second->fresh()->is_default);
        $this->assertFalse($first->fresh()->is_default);
    }

    #[Test]
    public function making_a_default_does_not_touch_another_customers_default(): void
    {
        $user = User::factory()->create();
        $mine = Address::factory()->forOwner($user)->create();
        $theirs = Address::factory()->forOwner(User::factory()->create())->default()->create();

        $this->actingAs($user)->put(route('settings.addresses.default', $mine));

        $this->assertTrue($theirs->fresh()->is_default);
    }

    #[Test]
    public function an_address_can_be_edited(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->forOwner($user)->create(['line1' => 'Old Road']);

        $this->actingAs($user)->put(route('settings.addresses.update', $address), $this->fields(['line1' => 'New Road']))
            ->assertSessionHasNoErrors();

        $this->assertSame('New Road', $address->fresh()->line1);
    }

    #[Test]
    public function deleting_the_default_hands_it_to_the_oldest_remaining_address(): void
    {
        $user = User::factory()->create();
        $default = Address::factory()->forOwner($user)->default()->create();
        $older = Address::factory()->forOwner($user)->create();
        Address::factory()->forOwner($user)->create();

        $this->actingAs($user)->delete(route('settings.addresses.destroy', $default))->assertRedirect();

        $this->assertNull(Address::find($default->id));
        $this->assertTrue($older->fresh()->is_default);
    }

    #[Test]
    public function deleting_the_last_address_leaves_no_default(): void
    {
        $user = User::factory()->create();
        $only = Address::factory()->forOwner($user)->default()->create();

        $this->actingAs($user)->delete(route('settings.addresses.destroy', $only));

        $this->assertSame(0, Address::count());
    }

    #[Test]
    public function nobody_can_change_someone_elses_address(): void
    {
        $theirs = Address::factory()->forOwner(User::factory()->create())->create(['line1' => 'Private Road']);
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('settings.addresses.update', $theirs), $this->fields())->assertForbidden();
        $this->actingAs($user)->put(route('settings.addresses.default', $theirs))->assertForbidden();
        $this->actingAs($user)->delete(route('settings.addresses.destroy', $theirs))->assertForbidden();

        $this->assertSame('Private Road', $theirs->fresh()->line1);
        $this->assertFalse($theirs->fresh()->is_default);
    }

    #[Test]
    public function address_fields_are_validated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('settings.addresses.store'), ['country' => 'ZZ'])
            ->assertSessionHasErrors(['name', 'line1', 'city', 'postal_code', 'country']);

        $this->assertSame(0, Address::count());
    }

    #[Test]
    public function a_postal_code_is_optional_only_where_the_country_has_none(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('settings.addresses.store'), $this->fields(['postal_code' => '']))
            ->assertSessionHasErrors('postal_code');

        $this->actingAs($user)->post(route('settings.addresses.store'), $this->fields(['country' => 'HK', 'postal_code' => '']))
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function access_follows_the_owner_not_a_user_id(): void
    {
        $user = User::factory()->create();
        $someoneElse = User::factory()->create();

        $mine = Address::factory()->forOwner($user)->create();
        $notMine = Address::factory()->forOwner($someoneElse)->create();

        $this->assertTrue(Gate::forUser($user)->allows('update', $mine));
        $this->assertFalse(Gate::forUser($user)->allows('update', $notMine));

        // An owner of another kind never matches a user who happens to share the id (1.1 adds teams).
        $notMine->forceFill(['owner_type' => 'App\\Models\\Team', 'owner_id' => $user->id])->save();
        $this->assertFalse(Gate::forUser($user)->allows('view', $notMine->fresh()));
    }

    #[Test]
    public function the_address_book_goes_away_with_the_shop(): void
    {
        SystemSetting::set('website_sections', ['blog' => true, 'forum' => true, 'support' => true, 'commerce' => false]);

        $this->actingAs(User::factory()->create())->get(route('settings.addresses.index'))->assertNotFound();
    }
}
