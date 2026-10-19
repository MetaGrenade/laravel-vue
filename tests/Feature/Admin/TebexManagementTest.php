<?php

namespace Tests\Feature\Admin;

use App\Models\SystemSetting;
use App\Models\TebexCategory;
use App\Models\TebexPackage;
use App\Models\TebexSync;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithTebex;
use Tests\TestCase;

/**
 * The Tebex page in the ACP.
 */
class TebexManagementTest extends TestCase
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

    // --- Access -----------------------------------------------------------------------------------

    #[Test]
    public function the_page_needs_a_signed_in_user_with_commerce_access(): void
    {
        $this->get(route('acp.commerce.tebex.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('acp.commerce.tebex.index'))->assertForbidden();
        $this->actingAs($this->viewer())->get(route('acp.commerce.tebex.index'))->assertOk();
    }

    #[Test]
    public function looking_is_not_enough_to_ask_tebex_for_anything(): void
    {
        Http::fake();
        $viewer = $this->viewer();

        $this->actingAs($viewer)->post(route('acp.commerce.tebex.test'))->assertForbidden();
        $this->actingAs($viewer)->post(route('acp.commerce.tebex.sync'))->assertForbidden();
        $this->actingAs($viewer)->get(route('acp.commerce.tebex.index'))
            ->assertInertia(fn (Assert $page) => $page->where('can.edit', false));

        Http::assertNothingSent();
    }

    #[Test]
    public function the_shop_section_being_off_hides_the_page(): void
    {
        SystemSetting::set('website_sections', ['blog' => true, 'forum' => true, 'support' => true, 'commerce' => false]);

        $this->actingAs($this->admin())->get(route('acp.commerce.tebex.index'))->assertNotFound();
    }

    // --- Secrets --------------------------------------------------------------------------------------

    #[Test]
    public function no_key_or_secret_and_not_the_whole_token_is_sent_to_the_browser(): void
    {
        config(['tebex.webhook_secret' => 'webhook-secret-that-must-never-be-shown']);
        $this->fakeTebex();
        $admin = $this->admin();

        $page = $this->actingAs($admin)->get(route('acp.commerce.tebex.index'));

        $page->assertOk()->assertInertia(fn (Assert $inertia) => $inertia
            ->where('connection.configured', true)
            ->where('connection.token', 'spk3…aaa')
            ->where('connection.has_private_key', true));

        $content = $page->getContent();
        $this->assertStringNotContainsString('private-key-that-must-never-be-shown', $content);
        $this->assertStringNotContainsString('webhook-secret-that-must-never-be-shown', $content);
        $this->assertStringNotContainsString($this->tebexToken, $content);

        // Nor after an action that uses them.
        $this->actingAs($admin)->post(route('acp.commerce.tebex.test'));
        $after = $this->actingAs($admin)->get(route('acp.commerce.tebex.index'))->getContent();
        $this->assertStringNotContainsString('private-key-that-must-never-be-shown', $after);
        $this->assertStringNotContainsString($this->tebexToken, $after);
    }

    #[Test]
    public function a_store_that_is_not_set_up_says_so(): void
    {
        config(['tebex.public_token' => null, 'tebex.private_key' => null]);

        $this->actingAs($this->admin())->get(route('acp.commerce.tebex.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('connection.configured', false)
                ->where('connection.token', null)
                ->where('connection.has_private_key', false)
                ->where('counts.packages', 0));
    }

    // --- Test connection ---------------------------------------------------------------------------------

    #[Test]
    public function the_connection_can_be_tested_and_the_answer_is_shown_once(): void
    {
        $this->fakeTebex();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('acp.commerce.tebex.test'))
            ->assertRedirect()
            ->assertSessionHas('tebex_test', ['ok' => true, 'name' => 'Example Store', 'currency' => 'USD', 'url' => 'https://example-store.tebex.io', 'disabled' => false, 'platform' => 'Universal (No Auth)', 'supported' => true]);

        $this->actingAs($admin)
            ->withSession(['_flash' => ['old' => ['tebex_test'], 'new' => []], 'tebex_test' => ['ok' => true, 'name' => 'Example Store', 'currency' => 'USD', 'url' => null, 'disabled' => false, 'platform' => 'Universal (No Auth)', 'supported' => true]])
            ->get(route('acp.commerce.tebex.index'))
            ->assertInertia(fn (Assert $page) => $page->where('test.ok', true)->where('test.name', 'Example Store'));
    }

    #[Test]
    public function a_failed_test_says_why(): void
    {
        $this->fakeTebexHttp(['headless.tebex.io/*' => Http::response([], 404)]);

        $this->actingAs($this->admin())->post(route('acp.commerce.tebex.test'))
            ->assertRedirect()
            ->assertSessionHas('tebex_test', fn (array $test) => $test['ok'] === false && str_contains($test['message'], 'does not know this store'));
    }

    // --- Refreshing the catalogue ---------------------------------------------------------------------

    #[Test]
    public function staff_can_refresh_the_catalogue_and_are_told_what_changed(): void
    {
        $this->fakeTebex();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('acp.commerce.tebex.sync'))
            ->assertRedirect()
            ->assertSessionHas('success', 'Catalogue refreshed: 3 packages added, 0 changed, 0 no longer listed.');

        $run = TebexSync::query()->sole();
        $this->assertSame(TebexSync::ADMIN, $run->trigger);
        $this->assertSame($admin->id, $run->user_id);
        $this->assertSame(3, TebexPackage::query()->count());
    }

    #[Test]
    public function a_failed_refresh_says_why_and_keeps_the_catalogue(): void
    {
        $this->fakeTebex();
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('acp.commerce.tebex.sync'));

        $this->fakeTebexHttp(['headless.tebex.io/*' => Http::response(['title' => 'Maintenance'], 503)]);

        $this->actingAs($admin)->post(route('acp.commerce.tebex.sync'))
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'Tebex answered 503'));

        $this->assertSame(3, TebexPackage::query()->listed()->count());
        $this->actingAs($admin)->get(route('acp.commerce.tebex.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('sync.latest.status', 'failed')
                ->where('sync.last_ok.status', 'ok')
                ->where('counts.packages', 3));
    }

    #[Test]
    public function a_refresh_while_another_is_running_is_refused_politely(): void
    {
        $this->fakeTebex();
        $lock = Cache::lock('tebex:sync', 60);
        $lock->get();

        $this->actingAs($this->admin())->post(route('acp.commerce.tebex.sync'))
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'already running'));

        $lock->release();
        Http::assertNothingSent();
    }

    // --- The list ----------------------------------------------------------------------------------------

    private function catalogue(): void
    {
        $this->fakeTebex();
        $this->actingAs($this->admin())->post(route('acp.commerce.tebex.sync'));
    }

    #[Test]
    public function the_page_shows_the_counts_the_runs_and_the_packages(): void
    {
        $this->catalogue();

        $this->actingAs($this->admin())->get(route('acp.commerce.tebex.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('counts', ['categories' => 2, 'packages' => 3, 'subscriptions' => 1, 'removed' => 0])
                ->where('sync.latest.status', 'ok')
                ->where('sync.latest.counts.packages.created', 3)
                ->where('sync.every_minutes', 60)
                ->has('sync.runs', 1)
                ->has('categories', 2)
                ->has('packages.data', 3)
                ->where('packages.data.0.name', 'VIP')
                ->where('packages.data.0.category', 'Ranks')
                ->where('packages.data.1.type', 'subscription')
                ->where('packages.data.1.has_variables', true)
                ->where('packages.data.2.discount', '0.50')
                ->where('shop_currency', 'USD')
                ->where('currencies', ['USD']));
    }

    #[Test]
    public function the_list_can_be_narrowed_by_status_category_and_search(): void
    {
        $this->catalogue();
        $admin = $this->admin();
        $cosmetics = TebexCategory::query()->where('tebex_id', 1002)->value('id');
        TebexPackage::query()->where('tebex_id', 50003)->update(['removed_at' => now()]);

        $this->actingAs($admin)->get(route('acp.commerce.tebex.index'))
            ->assertInertia(fn (Assert $page) => $page->has('packages.data', 2)->where('counts.removed', 1));
        $this->actingAs($admin)->get(route('acp.commerce.tebex.index', ['status' => 'removed']))
            ->assertInertia(fn (Assert $page) => $page->has('packages.data', 1)->where('packages.data.0.name', 'Pet cat')->where('packages.data.0.removed', true));
        $this->actingAs($admin)->get(route('acp.commerce.tebex.index', ['status' => 'all']))
            ->assertInertia(fn (Assert $page) => $page->has('packages.data', 3));
        $this->actingAs($admin)->get(route('acp.commerce.tebex.index', ['status' => 'all', 'category' => $cosmetics]))
            ->assertInertia(fn (Assert $page) => $page->has('packages.data', 1)->where('filters.category', $cosmetics));
        $this->actingAs($admin)->get(route('acp.commerce.tebex.index', ['search' => 'mvp']))
            ->assertInertia(fn (Assert $page) => $page->has('packages.data', 1)->where('packages.data.0.ident', 'mvp-monthly'));
        $this->actingAs($admin)->get(route('acp.commerce.tebex.index', ['search' => 'vip-rank']))
            ->assertInertia(fn (Assert $page) => $page->has('packages.data', 1)->where('packages.data.0.name', 'VIP'));
        $this->actingAs($admin)->get(route('acp.commerce.tebex.index', ['status' => 'nonsense']))
            ->assertInertia(fn (Assert $page) => $page->where('filters.status', 'listed'));
    }

    #[Test]
    public function the_list_is_paged(): void
    {
        $category = TebexCategory::factory()->create();
        TebexPackage::factory()->count(30)->create(['tebex_category_id' => $category->id]);

        $this->actingAs($this->admin())->get(route('acp.commerce.tebex.index'))
            ->assertInertia(fn (Assert $page) => $page->has('packages.data', 25)->where('packages.meta.total', 30)->where('packages.meta.last_page', 2));
        $this->actingAs($this->admin())->get(route('acp.commerce.tebex.index', ['page' => 2]))
            ->assertInertia(fn (Assert $page) => $page->has('packages.data', 5));
    }

    #[Test]
    public function prices_in_another_currency_than_the_shops_are_pointed_out(): void
    {
        $this->catalogue();
        TebexPackage::query()->where('tebex_id', 50001)->update(['currency' => 'EUR']);

        $this->actingAs($this->admin())->get(route('acp.commerce.tebex.index'))
            ->assertInertia(fn (Assert $page) => $page->where('currencies', ['EUR', 'USD'])->where('shop_currency', 'USD'));
    }
}
