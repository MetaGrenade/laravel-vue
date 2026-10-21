<?php

namespace Tests\Feature\Admin;

use App\Enums\EntitlementType;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\SystemSetting;
use App\Models\TebexPackage;
use App\Models\TebexPackageEntitlement;
use App\Models\User;
use App\Models\UserEntitlement;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithTebex;
use Tests\TestCase;

/**
 * The entitlements page in the ACP, and choosing which Tebex packages give which.
 */
class EntitlementManagementTest extends TestCase
{
    use InteractsWithTebex;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTebex();
        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('admin');
    }

    private function viewer(): User
    {
        $viewer = User::factory()->create()->assignRole('editor');
        $viewer->givePermissionTo('commerce.acp.view');

        return $viewer;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function entitlement(array $attributes = []): Entitlement
    {
        return Entitlement::factory()->create($attributes);
    }

    // --- Access ---------------------------------------------------------------------------------------------------------------

    #[Test]
    public function the_page_needs_a_signed_in_user_with_commerce_access(): void
    {
        $this->get(route('acp.commerce.entitlements.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('acp.commerce.entitlements.index'))->assertForbidden();
        $this->actingAs($this->viewer())->get(route('acp.commerce.entitlements.index'))->assertOk();
    }

    #[Test]
    public function looking_is_not_enough_to_change_anything(): void
    {
        $viewer = $this->viewer();
        $entitlement = $this->entitlement();
        $package = TebexPackage::factory()->create();

        $this->actingAs($viewer)->post(route('acp.commerce.entitlements.store'), ['key' => 'reports', 'label' => 'Reports', 'type' => 'feature'])->assertForbidden();
        $this->actingAs($viewer)->put(route('acp.commerce.entitlements.update', $entitlement), ['label' => 'New'])->assertForbidden();
        $this->actingAs($viewer)->delete(route('acp.commerce.entitlements.destroy', $entitlement))->assertForbidden();
        $this->actingAs($viewer)->put(route('acp.commerce.tebex.packages.entitlements.update', $package), ['entitlements' => []])->assertForbidden();

        $this->actingAs($viewer)->get(route('acp.commerce.entitlements.index'))
            ->assertInertia(fn (Assert $page) => $page->where('can', ['create' => false, 'edit' => false, 'delete' => false]));
        $this->assertSame(1, Entitlement::query()->count());
    }

    #[Test]
    public function the_shop_section_being_off_hides_the_page(): void
    {
        SystemSetting::set('website_sections', ['blog' => true, 'forum' => true, 'support' => true, 'commerce' => false]);

        $this->actingAs($this->admin())->get(route('acp.commerce.entitlements.index'))->assertNotFound();
    }

    // --- The list -------------------------------------------------------------------------------------------------------------

    #[Test]
    public function the_list_says_how_many_packages_give_each_and_how_many_purchases_hold_it(): void
    {
        $reports = $this->entitlement(['key' => 'reports', 'label' => 'Reports']);
        $this->entitlement(['key' => 'seats', 'label' => 'Seats', 'type' => EntitlementType::Limit]);
        TebexPackageEntitlement::create(['tebex_package_id' => TebexPackage::factory()->create()->id, 'entitlement_id' => $reports->id]);
        TebexPackageEntitlement::create(['tebex_package_id' => TebexPackage::factory()->create()->id, 'entitlement_id' => $reports->id]);
        UserEntitlement::factory()->create(['entitlement_id' => $reports->id]);
        UserEntitlement::factory()->revoked()->create(['entitlement_id' => $reports->id]);
        UserEntitlement::factory()->expired()->create(['entitlement_id' => $reports->id]);

        $this->actingAs($this->admin())->get(route('acp.commerce.entitlements.index'))->assertInertia(fn (Assert $page) => $page
            ->component('acp/CommerceEntitlements')
            ->has('entitlements', 2)
            ->where('entitlements.0.key', 'reports')
            ->where('entitlements.0.type', 'feature')
            ->where('entitlements.0.type_label', 'Feature')
            ->where('entitlements.0.packages_count', 2)
            ->where('entitlements.0.grants_count', 1)
            ->where('entitlements.1.key', 'seats')
            ->where('entitlements.1.type', 'limit')
            ->where('entitlements.1.packages_count', 0)
            ->where('entitlements.1.grants_count', 0)
            ->where('can', ['create' => true, 'edit' => true, 'delete' => true]));
    }

    // --- Adding, changing, deleting ----------------------------------------------------------------------------------------------

    #[Test]
    public function an_entitlement_can_be_added(): void
    {
        $this->actingAs($this->admin())->post(route('acp.commerce.entitlements.store'), [
            'key' => '  Team.Seats ',
            'label' => 'Team seats',
            'description' => 'How many people can join the team.',
            'type' => 'limit',
        ])->assertSessionHas('success');

        $entitlement = Entitlement::query()->sole();
        $this->assertSame('team.seats', $entitlement->key, 'a key is lower case and trimmed');
        $this->assertSame('Team seats', $entitlement->label);
        $this->assertSame('How many people can join the team.', $entitlement->description);
        $this->assertSame(EntitlementType::Limit, $entitlement->type);
    }

    #[Test]
    public function a_bad_entitlement_is_refused(): void
    {
        $admin = $this->admin();
        $this->entitlement(['key' => 'taken']);

        $cases = [
            'no key' => [['label' => 'X', 'type' => 'feature'], 'key'],
            'spaces in the key' => [['key' => 'team seats', 'label' => 'X', 'type' => 'feature'], 'key'],
            'a key that starts with a number' => [['key' => '1st', 'label' => 'X', 'type' => 'feature'], 'key'],
            'a key that is too long' => [['key' => str_repeat('a', 65), 'label' => 'X', 'type' => 'feature'], 'key'],
            'a key already used' => [['key' => 'taken', 'label' => 'X', 'type' => 'feature'], 'key'],
            'no name' => [['key' => 'fine', 'type' => 'feature'], 'label'],
            'no kind' => [['key' => 'fine', 'label' => 'X'], 'type'],
            'a kind that does not exist' => [['key' => 'fine', 'label' => 'X', 'type' => 'role'], 'type'],
        ];

        foreach ($cases as $name => [$data, $field]) {
            $this->actingAs($admin)->post(route('acp.commerce.entitlements.store'), $data)->assertSessionHasErrors($field, $name);
        }

        $this->assertSame(1, Entitlement::query()->count());
    }

    #[Test]
    public function only_the_name_and_description_can_be_changed(): void
    {
        $entitlement = $this->entitlement(['key' => 'reports', 'label' => 'Reports', 'type' => EntitlementType::Feature]);

        $this->actingAs($this->admin())->put(route('acp.commerce.entitlements.update', $entitlement), [
            'key' => 'something-else',
            'type' => 'limit',
            'label' => 'Monthly reports',
            'description' => 'Now with a description.',
        ])->assertSessionHas('success');

        $entitlement->refresh();
        $this->assertSame('Monthly reports', $entitlement->label);
        $this->assertSame('Now with a description.', $entitlement->description);
        $this->assertSame('reports', $entitlement->key, 'code asks for it by this');
        $this->assertSame(EntitlementType::Feature, $entitlement->type, 'what a grant of it means depends on this');
    }

    #[Test]
    public function the_edit_form_can_send_the_key_and_kind_back_as_they_are(): void
    {
        $entitlement = $this->entitlement(['key' => 'reports', 'label' => 'Reports', 'type' => EntitlementType::Feature]);

        // The page sends everything it shows, and the key is not "taken" by the entitlement it already belongs to.
        $this->actingAs($this->admin())->put(route('acp.commerce.entitlements.update', $entitlement), [
            'key' => 'reports',
            'type' => 'feature',
            'label' => 'Monthly reports',
            'description' => '',
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame('Monthly reports', $entitlement->fresh()->label);
        $this->assertNull($entitlement->fresh()->description);
    }

    #[Test]
    public function an_entitlement_nobody_holds_can_be_deleted_with_what_belongs_to_it(): void
    {
        $entitlement = $this->entitlement();
        TebexPackageEntitlement::create(['tebex_package_id' => TebexPackage::factory()->create()->id, 'entitlement_id' => $entitlement->id]);
        $old = UserEntitlement::factory()->revoked()->create(['entitlement_id' => $entitlement->id]);

        $this->actingAs($this->admin())->delete(route('acp.commerce.entitlements.destroy', $entitlement))->assertSessionHas('success');

        $this->assertModelMissing($entitlement);
        $this->assertModelMissing($old);
        $this->assertSame(0, TebexPackageEntitlement::query()->count());
    }

    #[Test]
    public function an_entitlement_somebody_still_holds_cannot_be_deleted(): void
    {
        $entitlement = $this->entitlement(['label' => 'Reports']);
        UserEntitlement::factory()->create(['entitlement_id' => $entitlement->id]);

        $this->actingAs($this->admin())->delete(route('acp.commerce.entitlements.destroy', $entitlement))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'Reports is held through 1 purchase'));

        $this->assertModelExists($entitlement);
        $this->assertSame(1, UserEntitlement::query()->count());
    }

    // --- Choosing what a package gives ----------------------------------------------------------------------------------------------

    #[Test]
    public function a_package_can_be_set_to_give_a_feature_and_a_limit(): void
    {
        $package = TebexPackage::factory()->create(['name' => 'VIP rank']);
        $feature = $this->entitlement(['key' => 'reports']);
        $limit = $this->entitlement(['key' => 'seats', 'type' => EntitlementType::Limit]);

        $this->actingAs($this->admin())->put(route('acp.commerce.tebex.packages.entitlements.update', $package), [
            'entitlements' => [
                ['id' => $feature->id, 'value' => 99],
                ['id' => $limit->id, 'value' => 5],
            ],
        ])->assertSessionHas('success');

        $links = TebexPackageEntitlement::query()->where('tebex_package_id', $package->id)->get()->keyBy('entitlement_id');
        $this->assertCount(2, $links);
        $this->assertNull($links[$feature->id]->value, 'an amount sent with a feature is dropped');
        $this->assertSame(5, $links[$limit->id]->value);
    }

    #[Test]
    public function setting_a_package_again_replaces_what_it_gave_and_an_empty_list_gives_nothing(): void
    {
        $package = TebexPackage::factory()->create();
        $feature = $this->entitlement(['key' => 'reports']);
        $limit = $this->entitlement(['key' => 'seats', 'type' => EntitlementType::Limit]);
        TebexPackageEntitlement::create(['tebex_package_id' => $package->id, 'entitlement_id' => $feature->id]);
        TebexPackageEntitlement::create(['tebex_package_id' => $package->id, 'entitlement_id' => $limit->id, 'value' => 5]);
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('acp.commerce.tebex.packages.entitlements.update', $package), ['entitlements' => [['id' => $limit->id, 'value' => 8]]])->assertSessionHas('success');

        $links = TebexPackageEntitlement::query()->where('tebex_package_id', $package->id)->get();
        $this->assertCount(1, $links);
        $this->assertSame($limit->id, $links->first()->entitlement_id);
        $this->assertSame(8, $links->first()->value);

        $this->actingAs($admin)->put(route('acp.commerce.tebex.packages.entitlements.update', $package), ['entitlements' => []])->assertSessionHas('success');

        $this->assertSame(0, TebexPackageEntitlement::query()->count());
    }

    #[Test]
    public function setting_one_package_leaves_the_others_alone(): void
    {
        $package = TebexPackage::factory()->create();
        $other = TebexPackage::factory()->create();
        $feature = $this->entitlement(['key' => 'reports']);
        TebexPackageEntitlement::create(['tebex_package_id' => $other->id, 'entitlement_id' => $feature->id]);

        $this->actingAs($this->admin())->put(route('acp.commerce.tebex.packages.entitlements.update', $package), ['entitlements' => []])->assertSessionHas('success');

        $this->assertSame(1, TebexPackageEntitlement::query()->where('tebex_package_id', $other->id)->count());
    }

    #[Test]
    public function a_bad_choice_for_a_package_is_refused(): void
    {
        $admin = $this->admin();
        $package = TebexPackage::factory()->create();
        $feature = $this->entitlement(['key' => 'reports']);
        $limit = $this->entitlement(['key' => 'seats', 'type' => EntitlementType::Limit]);
        $url = route('acp.commerce.tebex.packages.entitlements.update', $package);

        $cases = [
            'no list at all' => [[], 'entitlements'],
            'a limit with no amount' => [['entitlements' => [['id' => $limit->id]]], 'entitlements.0.value'],
            'a limit with an amount of nothing' => [['entitlements' => [['id' => $limit->id, 'value' => 0]]], 'entitlements.0.value'],
            'an amount too big' => [['entitlements' => [['id' => $limit->id, 'value' => 1000001]]], 'entitlements.0.value'],
            'an entitlement that does not exist' => [['entitlements' => [['id' => 9999]]], 'entitlements.0.id'],
            'the same one twice' => [['entitlements' => [['id' => $feature->id], ['id' => $feature->id]]], 'entitlements.0.id'],
        ];

        foreach ($cases as $name => [$data, $field]) {
            $this->actingAs($admin)->put($url, $data)->assertSessionHasErrors($field, $name);
        }

        $this->assertSame(0, TebexPackageEntitlement::query()->count());
    }

    #[Test]
    public function a_synchronisation_of_the_catalogue_does_not_touch_what_a_package_gives(): void
    {
        $package = TebexPackage::factory()->create(['tebex_id' => 50001]);
        $feature = $this->entitlement(['key' => 'reports']);
        TebexPackageEntitlement::create(['tebex_package_id' => $package->id, 'entitlement_id' => $feature->id]);

        // The catalogue copy is rewritten from Tebex's; what the shop attached to the row stays attached.
        $package->update(['name' => 'Renamed at Tebex', 'removed_at' => now()]);

        $this->assertSame(1, $package->fresh()->entitlementLinks()->count());
    }

    // --- Where it shows ------------------------------------------------------------------------------------------------------------

    #[Test]
    public function the_tebex_page_lists_what_each_package_gives_and_everything_it_could(): void
    {
        $package = TebexPackage::factory()->create(['name' => 'Seats pack']);
        TebexPackage::factory()->create(['name' => 'Plain']);
        $feature = $this->entitlement(['key' => 'reports', 'label' => 'Reports']);
        $limit = $this->entitlement(['key' => 'seats', 'label' => 'Seats', 'type' => EntitlementType::Limit]);
        TebexPackageEntitlement::create(['tebex_package_id' => $package->id, 'entitlement_id' => $feature->id]);
        TebexPackageEntitlement::create(['tebex_package_id' => $package->id, 'entitlement_id' => $limit->id, 'value' => 5]);

        $this->actingAs($this->admin())->get(route('acp.commerce.tebex.index'))->assertInertia(fn (Assert $page) => $page
            ->has('entitlements', 2)
            ->where('entitlements.0.key', 'reports')
            ->where('entitlements.1.key', 'seats')
            ->where('entitlements.1.type', 'limit')
            ->where('packages.data', fn ($rows) => collect($rows)->firstWhere('name', 'Seats pack')['entitlements'] === [
                ['id' => $feature->id, 'key' => 'reports', 'label' => 'Reports', 'type' => 'feature', 'value' => null],
                ['id' => $limit->id, 'key' => 'seats', 'label' => 'Seats', 'type' => 'limit', 'value' => 5],
            ] && collect($rows)->firstWhere('name', 'Plain')['entitlements'] === []));
    }

    #[Test]
    public function the_order_page_shows_what_the_order_gave_and_whether_it_is_still_held(): void
    {
        $order = Order::factory()->create();
        $item = $order->items()->create(['description' => 'VIP rank', 'quantity' => 1, 'unit_price' => '1.00', 'subtotal' => '1.00']);
        $held = $this->entitlement(['key' => 'reports', 'label' => 'Reports']);
        $taken = $this->entitlement(['key' => 'seats', 'label' => 'Seats', 'type' => EntitlementType::Limit]);
        UserEntitlement::factory()->create(['entitlement_id' => $held->id, 'order_id' => $order->id, 'order_item_id' => $item->id, 'source' => 'tebex']);
        UserEntitlement::factory()->revoked()->create(['entitlement_id' => $taken->id, 'order_id' => $order->id, 'order_item_id' => $item->id, 'value' => 5, 'source' => 'tebex']);

        $this->actingAs($this->admin())->get(route('acp.commerce.orders.show', $order))->assertInertia(fn (Assert $page) => $page
            ->has('entitlements', 2)
            ->where('entitlements.0.key', 'reports')
            ->where('entitlements.0.active', true)
            ->where('entitlements.0.line', 'VIP rank')
            ->where('entitlements.0.held_by_account', false)
            ->where('entitlements.1.key', 'seats')
            ->where('entitlements.1.value', 5)
            ->where('entitlements.1.active', false)
            ->where('entitlements.1.revoked_reason', 'refund'));
    }

    #[Test]
    public function a_customer_sees_what_their_purchases_give_them_and_nothing_that_was_taken_back(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $reports = $this->entitlement(['key' => 'reports', 'label' => 'Reports']);
        $seats = $this->entitlement(['key' => 'seats', 'label' => 'Seats', 'type' => EntitlementType::Limit]);
        $gone = $this->entitlement(['key' => 'gone', 'label' => 'Gone']);
        $mine = ['owner_type' => $user->getMorphClass(), 'owner_id' => $user->id];

        UserEntitlement::factory()->create([...$mine, 'entitlement_id' => $reports->id]);
        UserEntitlement::factory()->create([...$mine, 'entitlement_id' => $seats->id, 'value' => 5]);
        UserEntitlement::factory()->create([...$mine, 'entitlement_id' => $seats->id, 'value' => 3]);
        UserEntitlement::factory()->revoked()->create([...$mine, 'entitlement_id' => $gone->id]);
        UserEntitlement::factory()->create(['owner_type' => $other->getMorphClass(), 'owner_id' => $other->id, 'entitlement_id' => $this->entitlement(['key' => 'theirs', 'label' => 'Theirs'])->id]);

        $this->actingAs($user)->get(route('shop.orders'))->assertInertia(fn (Assert $page) => $page
            ->component('commerce/Orders')
            ->has('entitlements', 2)
            ->where('entitlements.0.label', 'Reports')
            ->where('entitlements.0.value', null)
            ->where('entitlements.1.label', 'Seats')
            ->where('entitlements.1.value', 8));
    }
}
