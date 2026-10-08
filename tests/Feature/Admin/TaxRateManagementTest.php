<?php

namespace Tests\Feature\Admin;

use App\Models\SystemSetting;
use App\Models\TaxRate;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TaxRateManagementTest extends TestCase
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
    private function payload(array $overrides = []): array
    {
        return array_replace(['name' => 'VAT', 'country' => 'GB', 'rate' => '20'], $overrides);
    }

    #[Test]
    public function the_page_needs_a_signed_in_user_with_commerce_access(): void
    {
        $this->get(route('acp.commerce.tax-rates.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('acp.commerce.tax-rates.index'))->assertForbidden();
    }

    #[Test]
    public function the_page_lists_rates_with_tidy_percentages(): void
    {
        TaxRate::factory()->create(['name' => 'Sales tax', 'country' => 'US', 'region' => 'California', 'rate' => '7.2500']);
        TaxRate::factory()->create(['name' => 'VAT', 'country' => 'GB', 'rate' => '20.0000']);
        TaxRate::factory()->create(['name' => 'Fallback', 'country' => '*', 'rate' => '8.8750', 'applies_to_shipping' => false]);

        $this->actingAs($this->admin())->get(route('acp.commerce.tax-rates.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('acp/CommerceTax')
                ->where('rates.0.country', '*')
                ->where('rates.0.rate', '8.875')
                ->where('rates.0.applies_to_shipping', false)
                ->where('rates.1.country', 'GB')
                ->where('rates.1.rate', '20')
                ->where('rates.2.region', 'California')
                ->where('rates.2.rate', '7.25')
                ->has('countries', 249)
                ->where('can.create', true));
    }

    #[Test]
    public function a_viewer_can_see_the_table_but_not_change_it(): void
    {
        $viewer = User::factory()->create()->assignRole('editor');
        $viewer->givePermissionTo('commerce.acp.view');
        $rate = TaxRate::factory()->create();

        $this->actingAs($viewer)->get(route('acp.commerce.tax-rates.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can', ['create' => false, 'edit' => false, 'delete' => false]));

        $this->actingAs($viewer)->post(route('acp.commerce.tax-rates.store'), $this->payload())->assertForbidden();
        $this->actingAs($viewer)->put(route('acp.commerce.tax-rates.update', $rate), $this->payload())->assertForbidden();
        $this->actingAs($viewer)->delete(route('acp.commerce.tax-rates.destroy', $rate))->assertForbidden();

        $this->assertSame(1, TaxRate::count());
    }

    #[Test]
    public function a_rate_can_be_added(): void
    {
        $this->actingAs($this->admin())
            ->post(route('acp.commerce.tax-rates.store'), $this->payload(['country' => 'gb', 'rate' => '20.5', 'applies_to_shipping' => false]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $rate = TaxRate::sole();
        $this->assertSame('VAT', $rate->name);
        $this->assertSame('GB', $rate->country, 'the country code is capitalised');
        $this->assertNull($rate->region);
        $this->assertSame('20.5000', $rate->rate);
        $this->assertFalse($rate->applies_to_shipping);
        $this->assertTrue($rate->is_active);
    }

    #[Test]
    public function shipping_is_taxed_unless_told_otherwise(): void
    {
        $this->actingAs($this->admin())->post(route('acp.commerce.tax-rates.store'), $this->payload());

        $this->assertTrue(TaxRate::sole()->applies_to_shipping);
    }

    #[Test]
    public function a_regional_rate_and_a_rest_of_world_fallback_can_be_added(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('acp.commerce.tax-rates.store'), $this->payload(['name' => 'CA tax', 'country' => 'US', 'region' => '  California ', 'rate' => '7.25']))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('acp.commerce.tax-rates.store'), $this->payload(['name' => 'Global', 'country' => '*', 'rate' => '5']))->assertSessionHasNoErrors();

        $this->assertSame('California', TaxRate::where('country', 'US')->sole()->region, 'the region is trimmed');
        $this->assertSame(1, TaxRate::where('country', '*')->count());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidRates(): array
    {
        return [
            'no name' => [['name' => ''], 'name'],
            'no country' => [['country' => ''], 'country'],
            'unknown country' => [['country' => 'ZZ'], 'country'],
            'no rate' => [['rate' => ''], 'rate'],
            'words' => [['rate' => 'twenty'], 'rate'],
            'negative' => [['rate' => '-5'], 'rate'],
            'over one hundred' => [['rate' => '100.5'], 'rate'],
            'four digits' => [['rate' => '1000'], 'rate'],
            'five decimals' => [['rate' => '8.87512'], 'rate'],
            'a percent sign' => [['rate' => '20%'], 'rate'],
            'a region on the fallback' => [['country' => '*', 'region' => 'California'], 'region'],
            'a long region' => [['region' => str_repeat('a', 121)], 'region'],
            'bad shipping flag' => [['applies_to_shipping' => 'sometimes'], 'applies_to_shipping'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[Test]
    #[DataProvider('invalidRates')]
    public function tax_input_is_validated(array $payload, string $field): void
    {
        $this->actingAs($this->admin())
            ->post(route('acp.commerce.tax-rates.store'), $this->payload($payload))
            ->assertSessionHasErrors($field);

        $this->assertSame(0, TaxRate::count());
    }

    #[Test]
    public function the_edge_percentages_are_accepted(): void
    {
        $admin = $this->admin();

        foreach (['0', '100', '100.0000', '0.0001', '8.875'] as $rate) {
            $this->actingAs($admin)->post(route('acp.commerce.tax-rates.store'), $this->payload(['rate' => $rate]))->assertSessionHasNoErrors();
        }

        $this->assertSame(5, TaxRate::count());
    }

    #[Test]
    public function a_rate_can_be_edited_and_switched_off(): void
    {
        $rate = TaxRate::factory()->create(['name' => 'Old', 'country' => 'GB', 'rate' => '20.0000', 'region' => 'England']);

        $this->actingAs($this->admin())
            ->put(route('acp.commerce.tax-rates.update', $rate), $this->payload(['name' => 'New VAT', 'country' => 'IE', 'region' => '', 'rate' => '23', 'is_active' => false]))
            ->assertSessionHasNoErrors();

        $rate->refresh();
        $this->assertSame('New VAT', $rate->name);
        $this->assertSame('IE', $rate->country);
        $this->assertNull($rate->region, 'a blank region clears it');
        $this->assertSame('23.0000', $rate->rate);
        $this->assertFalse($rate->is_active);
    }

    #[Test]
    public function a_rate_can_be_deleted(): void
    {
        $gone = TaxRate::factory()->create(['name' => 'Gone']);
        TaxRate::factory()->create(['name' => 'Kept']);

        $this->actingAs($this->admin())->delete(route('acp.commerce.tax-rates.destroy', $gone))->assertSessionHas('success');

        $this->assertSame(['Kept'], TaxRate::pluck('name')->all());
    }

    #[Test]
    public function each_action_needs_its_own_permission(): void
    {
        $rate = TaxRate::factory()->create(['name' => 'Original']);

        $creator = User::factory()->create()->assignRole('editor');
        $creator->givePermissionTo(['commerce.acp.view', 'commerce.acp.create']);
        $this->actingAs($creator)->post(route('acp.commerce.tax-rates.store'), $this->payload())->assertRedirect();
        $this->actingAs($creator)->put(route('acp.commerce.tax-rates.update', $rate), $this->payload())->assertForbidden();
        $this->actingAs($creator)->delete(route('acp.commerce.tax-rates.destroy', $rate))->assertForbidden();

        $editor = User::factory()->create()->assignRole('editor');
        $editor->givePermissionTo(['commerce.acp.view', 'commerce.acp.edit']);
        $this->actingAs($editor)->put(route('acp.commerce.tax-rates.update', $rate), $this->payload(['name' => 'Renamed']))->assertRedirect();
        $this->actingAs($editor)->delete(route('acp.commerce.tax-rates.destroy', $rate))->assertForbidden();

        $this->assertSame('Renamed', $rate->fresh()->name);
    }

    #[Test]
    public function the_page_disappears_when_the_shop_is_switched_off(): void
    {
        SystemSetting::set('website_sections', ['blog' => true, 'forum' => true, 'support' => true, 'commerce' => false]);

        $this->actingAs($this->admin())->get(route('acp.commerce.tax-rates.index'))->assertNotFound();
    }
}
