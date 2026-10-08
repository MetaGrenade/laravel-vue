<?php

namespace Tests\Feature\Admin;

use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShippingZoneManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('admin');
    }

    /**
     * @param  list<string>  $permissions
     */
    private function userWith(array $permissions): User
    {
        // Staff with a limited role. (The admin role passes every permission check, so it cannot test them.)
        $user = User::factory()->create()->assignRole('editor');
        $user->givePermissionTo($permissions);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function zonePayload(array $overrides = []): array
    {
        return array_replace(['name' => 'United Kingdom', 'countries' => ['GB']], $overrides);
    }

    #[Test]
    public function the_page_needs_a_signed_in_user_with_commerce_access(): void
    {
        $this->get(route('acp.commerce.shipping.index'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())->get(route('acp.commerce.shipping.index'))->assertForbidden();
    }

    #[Test]
    public function the_page_lists_zones_in_order_with_their_rates(): void
    {
        $second = ShippingZone::factory()->serving(['DE'])->create(['name' => 'Germany', 'position' => 2]);
        $first = ShippingZone::factory()->serving(['GB', 'IE'])->withRate('Standard', '5.00')->create(['name' => 'UK & Ireland', 'position' => 1]);
        ShippingRate::factory()->for($second, 'zone')->create(['name' => 'Express', 'amount' => '12.50', 'min_subtotal' => '20.00', 'max_subtotal' => null]);

        $this->actingAs($this->admin())->get(route('acp.commerce.shipping.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('acp/CommerceShipping')
                ->where('zones.0.id', $first->id)
                ->where('zones.0.countries', ['GB', 'IE'])
                ->where('zones.0.rates.0.name', 'Standard')
                ->where('zones.0.rates.0.amount', '5.00')
                ->where('zones.1.id', $second->id)
                ->where('zones.1.rates.0.min_subtotal', '20.00')
                ->where('zones.1.rates.0.max_subtotal', null)
                ->where('currency', 'USD')
                ->has('countries', 249)
                ->where('can', ['create' => true, 'edit' => true, 'delete' => true]));
    }

    #[Test]
    public function the_page_says_whether_shipping_is_set_up(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('acp.commerce.shipping.index'))
            ->assertInertia(fn (Assert $page) => $page->where('configured', false)->where('zones', []));

        ShippingZone::factory()->inactive()->create();
        $this->actingAs($admin)->get(route('acp.commerce.shipping.index'))
            ->assertInertia(fn (Assert $page) => $page->where('configured', false));

        ShippingZone::factory()->create();
        $this->actingAs($admin)->get(route('acp.commerce.shipping.index'))
            ->assertInertia(fn (Assert $page) => $page->where('configured', true));
    }

    #[Test]
    public function a_viewer_can_see_the_page_but_not_change_anything(): void
    {
        $viewer = $this->userWith(['commerce.acp.view']);
        $zone = ShippingZone::factory()->create(['name' => 'Original']);

        $this->actingAs($viewer)->get(route('acp.commerce.shipping.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can', ['create' => false, 'edit' => false, 'delete' => false]));

        $this->actingAs($viewer)->post(route('acp.commerce.shipping.zones.store'), $this->zonePayload())->assertForbidden();
        $this->actingAs($viewer)->put(route('acp.commerce.shipping.zones.update', $zone), $this->zonePayload())->assertForbidden();
        $this->actingAs($viewer)->delete(route('acp.commerce.shipping.zones.destroy', $zone))->assertForbidden();

        $this->assertSame(1, ShippingZone::count());
        $this->assertSame('Original', $zone->fresh()->name);
    }

    #[Test]
    public function each_action_needs_its_own_permission(): void
    {
        $zone = ShippingZone::factory()->create(['name' => 'Original']);

        $creator = $this->userWith(['commerce.acp.view', 'commerce.acp.create']);
        $this->actingAs($creator)->post(route('acp.commerce.shipping.zones.store'), $this->zonePayload())->assertRedirect();
        $this->actingAs($creator)->put(route('acp.commerce.shipping.zones.update', $zone), $this->zonePayload())->assertForbidden();
        $this->actingAs($creator)->delete(route('acp.commerce.shipping.zones.destroy', $zone))->assertForbidden();

        $editor = $this->userWith(['commerce.acp.view', 'commerce.acp.edit']);
        $this->actingAs($editor)->put(route('acp.commerce.shipping.zones.update', $zone), $this->zonePayload(['name' => 'Renamed']))->assertRedirect();
        $this->actingAs($editor)->post(route('acp.commerce.shipping.zones.store'), $this->zonePayload())->assertForbidden();

        $deleter = $this->userWith(['commerce.acp.view', 'commerce.acp.delete']);
        $this->actingAs($deleter)->delete(route('acp.commerce.shipping.zones.destroy', $zone))->assertRedirect();

        $this->assertNull(ShippingZone::find($zone->id));
    }

    #[Test]
    public function a_zone_can_be_created(): void
    {
        $this->actingAs($this->admin())
            ->post(route('acp.commerce.shipping.zones.store'), $this->zonePayload(['name' => 'Europe', 'countries' => ['de', 'FR', 'gb', 'DE']]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $zone = ShippingZone::sole();
        $this->assertSame('Europe', $zone->name);
        $this->assertSame(['DE', 'FR', 'GB'], $zone->countries, 'codes are capitalised and duplicates dropped');
        $this->assertTrue($zone->is_active);
        $this->assertSame(1, $zone->position);
    }

    #[Test]
    public function a_new_zone_goes_after_the_existing_ones(): void
    {
        ShippingZone::factory()->create(['position' => 7]);

        $this->actingAs($this->admin())->post(route('acp.commerce.shipping.zones.store'), $this->zonePayload());

        $this->assertSame(8, ShippingZone::orderByDesc('id')->first()->position);
    }

    #[Test]
    public function a_rest_of_world_zone_is_allowed_on_its_own(): void
    {
        $this->actingAs($this->admin())
            ->post(route('acp.commerce.shipping.zones.store'), $this->zonePayload(['name' => 'Rest of world', 'countries' => ['*']]))
            ->assertSessionHasNoErrors();

        $this->assertSame(['*'], ShippingZone::sole()->countries);
        $this->assertTrue(ShippingZone::sole()->isCatchAll());
    }

    #[Test]
    public function zone_input_is_validated(): void
    {
        $admin = $this->admin();
        $post = fn (array $payload) => $this->actingAs($admin)->post(route('acp.commerce.shipping.zones.store'), $payload);

        $post(['countries' => ['GB']])->assertSessionHasErrors('name');
        $post(['name' => 'X', 'countries' => []])->assertSessionHasErrors('countries');
        $post(['name' => 'X'])->assertSessionHasErrors('countries');
        $post(['name' => 'X', 'countries' => 'GB'])->assertSessionHasErrors('countries');
        $post(['name' => 'X', 'countries' => ['GB', 'ZZ']])->assertSessionHasErrors('countries.1');
        $post(['name' => 'X', 'countries' => [['GB']]])->assertSessionHasErrors('countries.0');
        $post(['name' => str_repeat('a', 121), 'countries' => ['GB']])->assertSessionHasErrors('name');
        $post(['name' => 'X', 'countries' => ['GB'], 'position' => -1])->assertSessionHasErrors('position');
        $post(['name' => 'X', 'countries' => ['GB'], 'is_active' => 'maybe'])->assertSessionHasErrors('is_active');

        $this->assertSame(0, ShippingZone::count());
    }

    #[Test]
    public function rest_of_world_cannot_be_mixed_with_named_countries(): void
    {
        $this->actingAs($this->admin())
            ->post(route('acp.commerce.shipping.zones.store'), $this->zonePayload(['countries' => ['GB', '*']]))
            ->assertSessionHasErrors('countries');

        $this->assertSame(0, ShippingZone::count());
    }

    #[Test]
    public function a_zone_can_be_edited_and_switched_off(): void
    {
        $zone = ShippingZone::factory()->serving(['GB'])->create(['name' => 'UK', 'position' => 3]);

        $this->actingAs($this->admin())
            ->put(route('acp.commerce.shipping.zones.update', $zone), $this->zonePayload(['name' => 'UK and Ireland', 'countries' => ['GB', 'IE'], 'is_active' => false]))
            ->assertSessionHasNoErrors();

        $zone->refresh();
        $this->assertSame('UK and Ireland', $zone->name);
        $this->assertSame(['GB', 'IE'], $zone->countries);
        $this->assertFalse($zone->is_active);
        $this->assertSame(3, $zone->position, 'the position is kept when not given');
    }

    #[Test]
    public function deleting_a_zone_deletes_its_rates(): void
    {
        $zone = ShippingZone::factory()->withRate('Standard', '5.00')->create();
        $other = ShippingZone::factory()->withRate('Other', '1.00')->create();

        $this->actingAs($this->admin())->delete(route('acp.commerce.shipping.zones.destroy', $zone))
            ->assertSessionHas('success');

        $this->assertNull(ShippingZone::find($zone->id));
        $this->assertSame(['Other'], ShippingRate::pluck('name')->all());
        $this->assertNotNull(ShippingZone::find($other->id));
    }

    #[Test]
    public function the_pages_disappear_when_the_shop_is_switched_off(): void
    {
        SystemSetting::set('website_sections', ['blog' => true, 'forum' => true, 'support' => true, 'commerce' => false]);

        $this->actingAs($this->admin())->get(route('acp.commerce.shipping.index'))->assertNotFound();
        $this->actingAs($this->admin())->post(route('acp.commerce.shipping.zones.store'), $this->zonePayload())->assertNotFound();
    }
}
