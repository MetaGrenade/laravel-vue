<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Support\Search\MeilisearchCircuitBreaker;
use App\Support\Search\SearchHealth;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeMeilisearch;
use Tests\TestCase;

/**
 * The search card on the ACP System page (docs/search-index.md): asked for after the page is shown, so a slow Meilisearch cannot hold
 * up the settings, and it needs no more than the permission the page already needs.
 */
class SystemSearchHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function viewer(): User
    {
        $viewer = User::factory()->create()->assignRole('editor');
        $viewer->givePermissionTo('system.acp.view');

        return $viewer;
    }

    private function meilisearch(FakeMeilisearch $fake): void
    {
        config([
            'search.driver' => 'meilisearch',
            'scout.driver' => 'meilisearch',
            'scout.prefix' => '',
            'scout.meilisearch.host' => 'http://meili.test',
        ]);

        $this->app->instance(SearchHealth::class, new SearchHealth($fake->client(), new MeilisearchCircuitBreaker));
    }

    #[Test]
    public function the_page_is_shown_without_asking_meilisearch_anything(): void
    {
        $fake = FakeMeilisearch::serving();
        $this->meilisearch($fake);

        $response = $this->actingAs($this->viewer())->get(route('acp.system'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('acp/System')->missing('searchHealth'));
        $this->assertSame(['searchHealth'], $response->viewData('page')['deferredProps']['default'] ?? null, 'it is promised for afterwards');
        $this->assertSame([], $fake->requests, 'and nothing was asked of Meilisearch to show the page');
    }

    #[Test]
    public function the_card_is_filled_in_afterwards(): void
    {
        $fake = FakeMeilisearch::serving();
        $this->meilisearch($fake);

        $this->actingAs($this->viewer())->get(route('acp.system'))->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $loaded) => $loaded
                ->where('searchHealth.status', 'healthy')
                ->where('searchHealth.host', 'http://meili.test')
                ->where('searchHealth.version', '1.12.8')
                ->has('searchHealth.indexes', 4)
                ->where('searchHealth.problems', [])));

        $this->assertTrue($fake->asked('GET /health'));
    }

    #[Test]
    public function it_can_be_asked_for_again_on_its_own(): void
    {
        $fake = FakeMeilisearch::serving();
        $this->meilisearch($fake);

        $this->actingAs($this->viewer())->get(route('acp.system'))->assertInertia(fn (Assert $page) => $page
            ->reloadOnly('searchHealth', fn (Assert $reloaded) => $reloaded->where('searchHealth.status', 'healthy')->missing('settings')));
    }

    #[Test]
    public function with_search_on_the_database_it_says_meilisearch_is_not_in_use(): void
    {
        $this->actingAs($this->viewer())->get(route('acp.system'))->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $loaded) => $loaded
                ->where('searchHealth.status', 'unused')
                ->where('searchHealth.driver', 'database')));
    }

    #[Test]
    public function a_meilisearch_that_is_down_does_not_break_the_page(): void
    {
        $this->meilisearch(FakeMeilisearch::serving()->unreachable());

        $this->actingAs($this->viewer())->get(route('acp.system'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('settings')
            ->loadDeferredProps(fn (Assert $loaded) => $loaded
                ->where('searchHealth.status', 'down')
                ->has('searchHealth.problems', 1)));
    }

    #[Test]
    public function the_card_asks_for_nothing_beyond_the_permission_the_page_needs(): void
    {
        $this->meilisearch($fake = FakeMeilisearch::serving());
        $editor = User::factory()->create()->assignRole('editor');

        $this->actingAs($editor)->get(route('acp.system'))->assertForbidden();
        $this->assertSame([], $fake->requests);

        $this->actingAs($this->viewer())->get(route('acp.system'))->assertOk();
    }
}
