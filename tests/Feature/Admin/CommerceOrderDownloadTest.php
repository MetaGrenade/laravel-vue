<?php

namespace Tests\Feature\Admin;

use App\Models\DownloadCount;
use App\Models\DownloadGrant;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What staff see and can do about an order's digital delivery.
 */
class CommerceOrderDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['commerce.downloads.limit' => 5]);
        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('admin');
    }

    /**
     * @return array{0: Order, 1: DownloadGrant, 2: ProductFile}
     */
    private function deliveredOrder(): array
    {
        $product = Product::factory()->digital()->create(['name' => 'Ebook']);
        $file = ProductFile::factory()->for($product)->create(['name' => 'The ebook']);
        $order = Order::factory()->paid()->create();
        $item = $order->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '10.00', 'subtotal' => '10.00', 'description' => 'Ebook', 'requires_shipping' => false]);
        $grant = DownloadGrant::create(['order_id' => $order->id, 'order_item_id' => $item->id, 'product_id' => $product->id]);

        return [$order, $grant, $file];
    }

    private function url(string $action, Order $order, DownloadGrant $grant): string
    {
        return route("acp.commerce.orders.downloads.{$action}", ['order' => $order->public_id, 'grant' => $grant->public_id]);
    }

    #[Test]
    public function the_order_page_shows_what_can_be_downloaded_and_how_often_it_has_been(): void
    {
        [$order, $grant, $file] = $this->deliveredOrder();
        DownloadCount::create(['download_grant_id' => $grant->id, 'product_file_id' => $file->id, 'downloads' => 3]);

        $this->actingAs($this->admin())->get(route('acp.commerce.orders.show', $order->public_id))->assertInertia(fn (Assert $page) => $page
            ->has('downloads', 1)
            ->where('downloads.0.id', $grant->public_id)
            ->where('downloads.0.description', 'Ebook')
            ->where('downloads.0.status', 'active')
            ->where('downloads.0.files.0.name', 'The ebook')
            ->where('downloads.0.files.0.downloads', 3)
            ->where('downloads.0.files.0.limit', 5)
            ->missing('downloads.0.files.0.path'));
    }

    #[Test]
    public function an_order_without_files_has_no_delivery_card(): void
    {
        $order = Order::factory()->paid()->create();

        $this->actingAs($this->admin())->get(route('acp.commerce.orders.show', $order->public_id))->assertInertia(fn (Assert $page) => $page->where('downloads', []));
    }

    #[Test]
    public function the_counts_can_be_reset(): void
    {
        [$order, $grant, $file] = $this->deliveredOrder();
        DownloadCount::create(['download_grant_id' => $grant->id, 'product_file_id' => $file->id, 'downloads' => 5]);

        $this->actingAs($this->admin())->post($this->url('reset', $order, $grant))->assertRedirect()->assertSessionHas('success');

        $this->assertSame(0, (int) DownloadCount::query()->value('downloads'));
        $this->assertSame('Reset the download counts for Ebook', $order->events()->where('type', OrderEvent::NOTE)->latest('id')->value('message'));
    }

    #[Test]
    public function access_can_be_revoked_and_restored_by_hand(): void
    {
        [$order, $grant] = $this->deliveredOrder();
        $admin = $this->admin();

        $this->actingAs($admin)->post($this->url('revoke', $order, $grant))->assertSessionHas('success');

        $grant->refresh();
        $this->assertSame(DownloadGrant::REVOKED, $grant->status());
        $this->assertSame(DownloadGrant::BY_STAFF, $grant->revoked_reason);

        $this->post($this->url('restore', $order, $grant))->assertSessionHas('success');

        $this->assertTrue($grant->fresh()->isUsable());
        $this->assertSame(
            ['Revoked access to the downloads for Ebook', 'Restored access to the downloads for Ebook'],
            $order->events()->where('type', OrderEvent::NOTE)->orderBy('id')->pluck('message')->all(),
        );
    }

    #[Test]
    public function access_a_refund_took_away_is_not_restored_by_hand(): void
    {
        [$order, $grant] = $this->deliveredOrder();
        $grant->forceFill(['revoked_at' => now(), 'revoked_reason' => DownloadGrant::BY_REFUND])->save();

        $this->actingAs($this->admin())->post($this->url('restore', $order, $grant))->assertSessionHas('error');

        $this->assertFalse($grant->fresh()->isUsable());
    }

    #[Test]
    public function a_grant_of_another_order_cannot_be_touched_through_this_one(): void
    {
        [$order] = $this->deliveredOrder();
        [, $otherGrant] = $this->deliveredOrder();

        $this->actingAs($this->admin())->post($this->url('revoke', $order, $otherGrant))->assertNotFound();

        $this->assertTrue($otherGrant->fresh()->isUsable());
    }

    #[Test]
    public function changing_downloads_needs_the_edit_permission(): void
    {
        [$order, $grant] = $this->deliveredOrder();
        $viewer = User::factory()->create()->assignRole('editor');
        $viewer->givePermissionTo('commerce.acp.view');

        foreach (['reset', 'revoke', 'restore'] as $action) {
            $this->actingAs($viewer)->post($this->url($action, $order, $grant))->assertForbidden();
        }

        $viewer->givePermissionTo('commerce.acp.edit');
        $this->post($this->url('revoke', $order, $grant))->assertRedirect();
        $this->assertFalse($grant->fresh()->isUsable());
    }
}
