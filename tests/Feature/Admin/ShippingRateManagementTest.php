<?php

namespace Tests\Feature\Admin;

use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShippingRateManagementTest extends TestCase
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
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function ratePayload(array $overrides = []): array
    {
        return array_replace(['name' => 'Standard', 'amount' => '5.00'], $overrides);
    }

    #[Test]
    public function a_rate_can_be_added_to_a_zone(): void
    {
        $zone = ShippingZone::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('acp.commerce.shipping.rates.store', $zone), $this->ratePayload([
                'description' => '3–5 business days',
                'amount' => '5',
                'min_subtotal' => '10',
                'max_subtotal' => '99.5',
            ]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $rate = $zone->rates()->sole();
        $this->assertSame('Standard', $rate->name);
        $this->assertSame('3–5 business days', $rate->description);
        $this->assertSame('5.00', $rate->amount, 'amounts are stored with two decimals');
        $this->assertSame('10.00', $rate->min_subtotal);
        $this->assertSame('99.50', $rate->max_subtotal);
        $this->assertTrue($rate->is_active);
        $this->assertSame(1, $rate->position);
    }

    #[Test]
    public function free_shipping_is_a_zero_amount(): void
    {
        $zone = ShippingZone::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('acp.commerce.shipping.rates.store', $zone), $this->ratePayload(['name' => 'Free over 100', 'amount' => '0', 'min_subtotal' => '100']))
            ->assertSessionHasNoErrors();

        $this->assertSame('0.00', $zone->rates()->sole()->amount);
    }

    #[Test]
    public function new_rates_go_after_the_zones_existing_ones(): void
    {
        $zone = ShippingZone::factory()->create();
        ShippingRate::factory()->for($zone, 'zone')->create(['position' => 4]);
        $other = ShippingZone::factory()->create();
        ShippingRate::factory()->for($other, 'zone')->create(['position' => 99]);

        $this->actingAs($this->admin())->post(route('acp.commerce.shipping.rates.store', $zone), $this->ratePayload());

        $newest = ShippingRate::query()->where('shipping_zone_id', $zone->id)->orderByDesc('id')->first();
        $this->assertSame(5, $newest->position, "another zone's positions do not matter");
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidRates(): array
    {
        return [
            'no name' => [['name' => ''], 'name'],
            'no amount' => [['amount' => ''], 'amount'],
            'negative amount' => [['amount' => '-1'], 'amount'],
            'three decimals' => [['amount' => '5.123'], 'amount'],
            'words' => [['amount' => 'five'], 'amount'],
            'a comma decimal' => [['amount' => '5,00'], 'amount'],
            'too many digits' => [['amount' => '123456789'], 'amount'],
            'bad minimum' => [['min_subtotal' => 'lots'], 'min_subtotal'],
            'bad maximum' => [['max_subtotal' => '1.234'], 'max_subtotal'],
            'maximum below minimum' => [['min_subtotal' => '50', 'max_subtotal' => '10'], 'max_subtotal'],
            'long description' => [['description' => str_repeat('a', 256)], 'description'],
            'negative position' => [['position' => -1], 'position'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[Test]
    #[DataProvider('invalidRates')]
    public function rate_input_is_validated(array $payload, string $field): void
    {
        $zone = ShippingZone::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('acp.commerce.shipping.rates.store', $zone), $this->ratePayload($payload))
            ->assertSessionHasErrors($field);

        $this->assertSame(0, ShippingRate::count());
    }

    #[Test]
    public function a_minimum_equal_to_the_maximum_is_allowed(): void
    {
        $zone = ShippingZone::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('acp.commerce.shipping.rates.store', $zone), $this->ratePayload(['min_subtotal' => '25', 'max_subtotal' => '25']))
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function limits_are_optional_and_can_be_cleared(): void
    {
        $zone = ShippingZone::factory()->create();
        $rate = ShippingRate::factory()->for($zone, 'zone')->create(['min_subtotal' => '10.00', 'max_subtotal' => '50.00']);

        $this->actingAs($this->admin())
            ->put(route('acp.commerce.shipping.rates.update', $rate), $this->ratePayload(['min_subtotal' => '', 'max_subtotal' => null]))
            ->assertSessionHasNoErrors();

        $rate->refresh();
        $this->assertNull($rate->min_subtotal);
        $this->assertNull($rate->max_subtotal);
    }

    #[Test]
    public function a_rate_can_be_edited_and_switched_off(): void
    {
        $zone = ShippingZone::factory()->create();
        $rate = ShippingRate::factory()->for($zone, 'zone')->create(['name' => 'Old', 'amount' => '5.00', 'position' => 2]);

        $this->actingAs($this->admin())
            ->put(route('acp.commerce.shipping.rates.update', $rate), $this->ratePayload(['name' => 'Express', 'amount' => '15', 'is_active' => false]))
            ->assertSessionHasNoErrors();

        $rate->refresh();
        $this->assertSame('Express', $rate->name);
        $this->assertSame('15.00', $rate->amount);
        $this->assertFalse($rate->is_active);
        $this->assertSame(2, $rate->position);
        $this->assertSame($zone->id, $rate->shipping_zone_id, 'a rate never moves to another zone');
    }

    #[Test]
    public function a_rate_can_be_deleted_without_touching_the_zone_or_other_rates(): void
    {
        $zone = ShippingZone::factory()->create();
        $gone = ShippingRate::factory()->for($zone, 'zone')->create(['name' => 'Gone']);
        ShippingRate::factory()->for($zone, 'zone')->create(['name' => 'Kept']);

        $this->actingAs($this->admin())->delete(route('acp.commerce.shipping.rates.destroy', $gone))->assertSessionHas('success');

        $this->assertSame(['Kept'], $zone->rates()->pluck('name')->all());
        $this->assertNotNull(ShippingZone::find($zone->id));
    }

    #[Test]
    public function a_rate_for_a_zone_that_does_not_exist_is_not_found(): void
    {
        $this->actingAs($this->admin())->post(route('acp.commerce.shipping.rates.store', 9999), $this->ratePayload())->assertNotFound();
    }

    #[Test]
    public function each_action_needs_its_own_permission(): void
    {
        $zone = ShippingZone::factory()->create();
        $rate = ShippingRate::factory()->for($zone, 'zone')->create();

        $viewer = User::factory()->create()->assignRole('editor');
        $viewer->givePermissionTo('commerce.acp.view');

        $this->actingAs($viewer)->post(route('acp.commerce.shipping.rates.store', $zone), $this->ratePayload())->assertForbidden();
        $this->actingAs($viewer)->put(route('acp.commerce.shipping.rates.update', $rate), $this->ratePayload())->assertForbidden();
        $this->actingAs($viewer)->delete(route('acp.commerce.shipping.rates.destroy', $rate))->assertForbidden();

        $this->assertSame(1, ShippingRate::count());
    }

    #[Test]
    public function a_zero_decimal_store_keeps_whole_amounts(): void
    {
        config(['commerce.currency' => 'JPY']);
        $zone = ShippingZone::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('acp.commerce.shipping.rates.store', $zone), $this->ratePayload(['amount' => '500', 'min_subtotal' => '10000']))
            ->assertSessionHasNoErrors();

        $rate = $zone->rates()->sole();
        $this->assertSame('500.00', $rate->amount);
        $this->assertSame('10000.00', $rate->min_subtotal);
    }
}
