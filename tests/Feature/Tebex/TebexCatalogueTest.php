<?php

namespace Tests\Feature\Tebex;

use App\Models\TebexCategory;
use App\Models\TebexPackage;
use App\Models\TebexSync;
use App\Models\User;
use App\Payments\Tebex\TebexCatalogue;
use App\Payments\Tebex\TebexException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\InteractsWithTebex;
use Tests\TestCase;

/**
 * Keeping the shop's copy of the Tebex catalogue in line with Tebex's.
 */
class TebexCatalogueTest extends TestCase
{
    use InteractsWithTebex;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTebex();
    }

    private function sync(string $trigger = TebexSync::COMMAND, ?User $by = null): TebexSync
    {
        return app(TebexCatalogue::class)->sync($trigger, $by);
    }

    private function package(int $tebexId): TebexPackage
    {
        return TebexPackage::query()->where('tebex_id', $tebexId)->firstOrFail();
    }

    // --- The first copy -----------------------------------------------------------------------------

    #[Test]
    public function the_first_sync_copies_the_categories_and_packages(): void
    {
        $this->fakeTebex();
        $staff = User::factory()->create();

        $run = $this->sync(TebexSync::ADMIN, $staff);

        $this->assertSame(TebexSync::OK, $run->status);
        $this->assertSame(TebexSync::ADMIN, $run->trigger);
        $this->assertSame($staff->id, $run->user_id);
        $this->assertNotNull($run->finished_at);
        $this->assertNull($run->error);
        $this->assertEquals(['created' => 2, 'updated' => 0, 'unchanged' => 0, 'removed' => 0], $run->counts['categories']);
        $this->assertEquals(['created' => 3, 'updated' => 0, 'unchanged' => 0, 'removed' => 0], $run->counts['packages']);

        $ranks = TebexCategory::query()->where('tebex_id', 1001)->sole();
        $this->assertSame('Ranks', $ranks->name);
        $this->assertSame('grid', $ranks->display_type);
        $this->assertNull($ranks->parent_tebex_id);
        $this->assertNull($ranks->removed_at);

        $cosmetics = TebexCategory::query()->where('tebex_id', 1002)->sole();
        $this->assertSame(1001, $cosmetics->parent_tebex_id);
        $this->assertSame('https://cdn.example.test/cosmetics.png', $cosmetics->image_url);

        $vip = $this->package(50001);
        $this->assertSame('vip-rank', $vip->ident);
        $this->assertSame($ranks->id, $vip->tebex_category_id);
        $this->assertSame('9.99', $vip->base_price);
        $this->assertSame('9.99', $vip->total_price);
        $this->assertSame('USD', $vip->currency);
        $this->assertSame(TebexPackage::SINGLE, $vip->type);
        $this->assertSame('<p>VIP perks for <em>everyone</em>.</p>', $vip->description);
        $this->assertSame('https://cdn.example.test/vip.png', $vip->image_url);
        $this->assertSame('https://cdn.example.test/vip.png', $vip->media[0]['url']);
        $this->assertFalse($vip->hasVariables());

        $mvp = $this->package(50002);
        $this->assertTrue($mvp->isSubscription());
        $this->assertSame('4.50', $mvp->base_price);
        $this->assertSame('0.90', $mvp->sales_tax);
        $this->assertSame('5.40', $mvp->total_price);
        $this->assertSame('2.25', $mvp->prorate_price);
        $this->assertTrue($mvp->disable_quantity);
        $this->assertTrue($mvp->disable_gifting);
        $this->assertSame(1, $mvp->user_limit);
        $this->assertTrue($mvp->hasVariables());
        $this->assertSame('server', $mvp->variables[0]['identifier']);

        $cat = $this->package(50003);
        $this->assertSame($cosmetics->id, $cat->tebex_category_id);
        $this->assertSame('2.00', $cat->base_price);
        $this->assertSame('0.50', $cat->discount);
        $this->assertSame('2027-01-01', $cat->expires_at->toDateString());
        $this->assertNull($cat->prorate_price);
    }

    #[Test]
    public function the_run_is_over_two_calls_to_tebex_and_nothing_is_sent_to_it(): void
    {
        $this->fakeTebex();

        $this->sync();

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->method() === 'GET');
    }

    #[Test]
    public function a_price_is_kept_to_the_cent_whatever_tebex_floats_do(): void
    {
        $body = $this->tebexCategoriesChanged(function (array $categories) {
            $categories[0]['packages'][0]['base_price'] = 0.1 + 0.2;
            $categories[0]['packages'][0]['total_price'] = 19.999;
            $categories[0]['packages'][0]['sales_tax'] = 1.005;

            return $categories;
        });
        $this->fakeTebex($body);

        $this->sync();

        $vip = $this->package(50001);
        $this->assertSame('0.30', $vip->base_price);
        $this->assertSame('20.00', $vip->total_price);
        $this->assertSame('1.01', $vip->sales_tax);
    }

    #[Test]
    public function a_package_without_a_currency_is_priced_in_the_stores(): void
    {
        $body = $this->tebexCategoriesChanged(function (array $categories) {
            unset($categories[0]['packages'][0]['currency']);

            return $categories;
        });
        $this->fakeTebex($body, ['data' => [...$this->tebexFixture('webstore')['data'], 'currency' => 'eur']]);

        $this->sync();

        $this->assertSame('EUR', $this->package(50001)->currency);
        $this->assertSame('USD', $this->package(50002)->currency, 'one that names its own keeps it');
    }

    #[Test]
    public function a_package_belongs_where_tebex_says_otherwise_where_it_was_listed(): void
    {
        $body = $this->tebexCategoriesChanged(function (array $categories) {
            // Listed under Ranks, but Tebex says its category is Cosmetics.
            $categories[0]['packages'][0]['category'] = ['id' => 1002, 'name' => 'Cosmetics'];
            // Names a category that was not listed at all.
            $categories[0]['packages'][1]['category'] = ['id' => 999999, 'name' => 'Hidden'];

            return $categories;
        });
        $this->fakeTebex($body);

        $this->sync();

        $this->assertSame(TebexCategory::query()->where('tebex_id', 1002)->value('id'), $this->package(50001)->tebex_category_id);
        $this->assertSame(TebexCategory::query()->where('tebex_id', 1001)->value('id'), $this->package(50002)->tebex_category_id);
    }

    // --- Changes ----------------------------------------------------------------------------------

    #[Test]
    public function a_sync_with_nothing_new_writes_nothing(): void
    {
        $this->fakeTebex();
        $this->sync();
        $before = TebexPackage::query()->orderBy('id')->get()->map->only(['id', 'updated_at'])->all();

        $this->travel(5)->minutes();
        $run = $this->sync();

        $this->assertEquals(['created' => 0, 'updated' => 0, 'unchanged' => 2, 'removed' => 0], $run->counts['categories']);
        $this->assertEquals(['created' => 0, 'updated' => 0, 'unchanged' => 3, 'removed' => 0], $run->counts['packages']);
        $this->assertEquals($before, TebexPackage::query()->orderBy('id')->get()->map->only(['id', 'updated_at'])->all());
        $this->assertSame(3, TebexPackage::query()->count());
    }

    #[Test]
    public function objects_kept_with_their_keys_in_another_order_are_not_a_change(): void
    {
        $this->fakeTebex();
        $this->sync();

        // MySQL keeps an object's keys shortest first, so what it gives back never has the order Tebex sent them in
        // (and used to make every package with media or variables look changed on every run). Store them that way on
        // any database.
        foreach ([50001 => 'media', 50002 => 'variables'] as $tebexId => $column) {
            $backwards = array_map(fn (array $object) => array_reverse($object, true), $this->package($tebexId)->{$column});

            DB::table('tebex_packages')->where('tebex_id', $tebexId)->update([$column => json_encode($backwards)]);
        }

        $this->travel(5)->minutes();
        $run = $this->sync();

        $this->assertEquals(['created' => 0, 'updated' => 0, 'unchanged' => 3, 'removed' => 0], $run->counts['packages']);
        $this->assertSame(0, TebexPackage::query()->where('updated_at', '>', now()->subMinute())->count(), 'nothing was written');
    }

    #[Test]
    public function a_real_change_inside_an_object_still_counts(): void
    {
        $this->fakeTebex();
        $this->sync();

        $this->fakeTebex($this->tebexCategoriesChanged(function (array $categories) {
            $categories[0]['packages'][1]['variables'][0]['options'][1]['name'] = 'Hardcore';

            return $categories;
        }));
        $run = $this->sync();

        $this->assertEquals(['created' => 0, 'updated' => 1, 'unchanged' => 2, 'removed' => 0], $run->counts['packages']);
        $this->assertSame('Hardcore', $this->package(50002)->variables[0]['options'][1]['name']);
    }

    #[Test]
    public function a_changed_package_is_updated_and_the_rest_are_left_alone(): void
    {
        $this->fakeTebex();
        $this->sync();
        $id = $this->package(50001)->id;

        $this->fakeTebex($this->tebexCategoriesChanged(function (array $categories) {
            $categories[0]['packages'][0]['name'] = 'VIP Plus';
            $categories[0]['packages'][0]['base_price'] = 12.5;
            $categories[0]['packages'][0]['total_price'] = 12.5;

            return $categories;
        }));
        $run = $this->sync();

        $this->assertEquals(['created' => 0, 'updated' => 1, 'unchanged' => 2, 'removed' => 0], $run->counts['packages']);
        $vip = $this->package(50001);
        $this->assertSame($id, $vip->id, 'the same row, so an order that refers to it still does');
        $this->assertSame('VIP Plus', $vip->name);
        $this->assertSame('12.50', $vip->base_price);
    }

    #[Test]
    public function a_package_tebex_no_longer_lists_is_kept_and_marked(): void
    {
        $this->fakeTebex();
        $this->sync();

        $this->fakeTebex($this->tebexCategoriesChanged(function (array $categories) {
            array_pop($categories[0]['packages']);

            return $categories;
        }));
        $run = $this->sync();

        $this->assertSame(1, $run->counts['packages']['removed']);
        $this->assertSame(3, TebexPackage::query()->count(), 'nothing is deleted: orders will refer to it');
        $this->assertNotNull($this->package(50002)->removed_at);
        $this->assertNull($this->package(50001)->removed_at);
        $this->assertSame(2, TebexPackage::query()->listed()->count());
    }

    #[Test]
    public function a_package_that_comes_back_is_listed_again_and_the_removal_is_not_counted_twice(): void
    {
        $gone = $this->tebexCategoriesChanged(function (array $categories) {
            array_pop($categories[0]['packages']);

            return $categories;
        });
        $this->fakeTebex();
        $this->sync();
        $this->fakeTebex($gone);
        $this->sync();
        $stamp = $this->package(50002)->removed_at;
        $this->assertNotNull($stamp);

        // Still gone: not removed again, and the original date stays.
        $this->travel(1)->hour();
        $still = $this->sync();
        $this->assertSame(0, $still->counts['packages']['removed']);
        $this->assertEquals($stamp, $this->package(50002)->removed_at);

        $this->fakeTebex();
        $back = $this->sync();

        $this->assertNull($this->package(50002)->removed_at);
        $this->assertSame(1, $back->counts['packages']['updated']);
        $this->assertSame(3, TebexPackage::query()->count());
    }

    #[Test]
    public function a_category_tebex_no_longer_lists_is_kept_and_marked_with_its_packages(): void
    {
        $this->fakeTebex();
        $this->sync();

        $this->fakeTebex($this->tebexCategoriesChanged(fn (array $categories) => [$categories[0]]));
        $run = $this->sync();

        $this->assertSame(1, $run->counts['categories']['removed']);
        $this->assertSame(1, $run->counts['packages']['removed']);
        $this->assertNotNull(TebexCategory::query()->where('tebex_id', 1002)->value('removed_at'));
        $this->assertNotNull($this->package(50003)->removed_at);
        $this->assertSame(2, TebexCategory::query()->count());
    }

    #[Test]
    public function a_store_with_nothing_in_it_marks_everything_as_no_longer_listed(): void
    {
        $this->fakeTebex();
        $this->sync();

        $this->fakeTebex(['data' => []]);
        $run = $this->sync();

        $this->assertSame(TebexSync::OK, $run->status);
        $this->assertSame(3, $run->counts['packages']['removed']);
        $this->assertSame(0, TebexPackage::query()->listed()->count());
    }

    // --- Failing safely ---------------------------------------------------------------------------

    #[Test]
    public function when_tebex_fails_the_copy_stays_as_it_was_and_the_run_says_why(): void
    {
        $this->fakeTebex();
        $this->sync();

        $this->fakeTebexHttp(['headless.tebex.io/*' => Http::response(['title' => 'Down for maintenance'], 503)]);
        $run = $this->sync(TebexSync::SCHEDULE);

        $this->assertSame(TebexSync::FAILED, $run->status);
        $this->assertStringContainsString('Tebex answered 503', $run->error);
        $this->assertNotNull($run->finished_at);
        $this->assertNull($run->counts);
        $this->assertSame(3, TebexPackage::query()->listed()->count());
    }

    /**
     * @return array<string, array{0: callable(array<int, array<string, mixed>>): array<int, array<string, mixed>>}>
     */
    public static function malformedCatalogues(): array
    {
        return [
            'a package that is a string' => [function (array $categories) {
                $categories[0]['packages'][1] = 'oops';

                return $categories;
            }],
            'a package that is null' => [function (array $categories) {
                $categories[0]['packages'][1] = null;

                return $categories;
            }],
            'a package that is a number' => [function (array $categories) {
                $categories[0]['packages'][1] = 42;

                return $categories;
            }],
            'packages that are an object, not a list' => [function (array $categories) {
                $categories[0]['packages'] = ['id' => 50001, 'name' => 'VIP'];

                return $categories;
            }],
            'packages keyed by something instead of listed' => [function (array $categories) {
                $categories[0]['packages'] = ['vip' => $categories[0]['packages'][0], 'mvp' => $categories[0]['packages'][1]];

                return $categories;
            }],
            'packages that are a string' => [function (array $categories) {
                $categories[0]['packages'] = 'none';

                return $categories;
            }],
        ];
    }

    /**
     * @param  callable(array<int, array<string, mixed>>): array<int, array<string, mixed>>  $break
     */
    #[Test]
    #[DataProvider('malformedCatalogues')]
    public function a_package_that_is_not_an_object_fails_the_run_instead_of_retiring_what_it_stood_for(callable $break): void
    {
        $this->fakeTebex();
        $this->sync();

        $this->fakeTebex($this->tebexCategoriesChanged($break));
        $run = $this->sync();

        $this->assertSame(TebexSync::FAILED, $run->status, 'a response the shop cannot understand is not a successful run');
        $this->assertStringContainsString('could not understand', $run->error);
        $this->assertStringContainsString('so nothing was changed', $run->error);
        $this->assertSame(3, TebexPackage::query()->listed()->count(), 'no package was marked as no longer listed');
        $this->assertSame(2, TebexCategory::query()->listed()->count());
    }

    #[Test]
    public function an_entry_that_makes_no_sense_changes_nothing_at_all(): void
    {
        $this->fakeTebex();
        $this->sync();

        $this->fakeTebex($this->tebexCategoriesChanged(function (array $categories) {
            // A real change first, then a package with no id.
            $categories[0]['packages'][0]['name'] = 'Renamed before the bad one';
            unset($categories[0]['packages'][1]['id']);
            $categories[] = ['id' => 1003, 'name' => 'A new category', 'packages' => []];

            return $categories;
        }));
        $run = $this->sync();

        $this->assertSame(TebexSync::FAILED, $run->status);
        $this->assertStringContainsString('it has no id), so nothing was changed', $run->error);
        $this->assertSame('VIP', $this->package(50001)->name);
        $this->assertSame(2, TebexCategory::query()->count());
        $this->assertSame(3, TebexPackage::query()->listed()->count());
    }

    #[Test]
    public function an_unexpected_failure_is_recorded_without_its_details_and_reported(): void
    {
        $this->fakeTebex();
        // The database refusing something is not Tebex's doing: the run says so without the details.
        TebexPackage::creating(fn () => throw new RuntimeException('secret detail: SQLSTATE[HY000]'));
        Exceptions::fake();

        $run = $this->sync();

        $this->assertSame(TebexSync::FAILED, $run->status);
        $this->assertStringNotContainsString('secret detail', $run->error);
        $this->assertStringContainsString('application log', $run->error);
        Exceptions::assertReported(fn (RuntimeException $exception) => str_contains($exception->getMessage(), 'secret detail'));
        $this->assertSame(0, TebexCategory::query()->count(), 'the categories saved before it went wrong were rolled back');
        $this->assertSame(0, TebexPackage::query()->count());
    }

    // --- Refusing to run -----------------------------------------------------------------------------

    #[Test]
    public function nothing_runs_without_a_token(): void
    {
        Http::fake();
        config(['tebex.public_token' => null]);

        try {
            $this->sync();
            $this->fail('A sync ran with no token.');
        } catch (TebexException $exception) {
            $this->assertStringContainsString('TEBEX_PUBLIC_TOKEN', $exception->getMessage());
        }

        $this->assertSame(0, TebexSync::query()->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function two_runs_do_not_overlap(): void
    {
        $this->fakeTebex();
        $lock = Cache::lock('tebex:sync', 60);
        $this->assertTrue($lock->get());

        try {
            $this->sync();
            $this->fail('A second run started while one was in progress.');
        } catch (TebexException $exception) {
            $this->assertStringContainsString('already running', $exception->getMessage());
        } finally {
            $lock->release();
        }

        $this->assertSame(0, TebexSync::query()->count());
        Http::assertNothingSent();

        $this->assertSame(TebexSync::OK, $this->sync()->status, 'and the lock is free once the other run is over');
    }

    #[Test]
    public function the_lock_is_released_even_when_the_run_fails(): void
    {
        $this->fakeTebexHttp(['headless.tebex.io/*' => Http::response([], 404)]);
        $this->assertSame(TebexSync::FAILED, $this->sync()->status);

        $this->fakeTebex();
        $this->assertSame(TebexSync::OK, $this->sync()->status);
    }

    // --- The record of runs -----------------------------------------------------------------------------

    #[Test]
    public function a_run_that_died_is_closed_off_by_the_next_one(): void
    {
        $dead = TebexSync::query()->create(['trigger' => TebexSync::SCHEDULE, 'status' => TebexSync::RUNNING, 'started_at' => now()->subMinutes(30)]);
        $live = TebexSync::query()->create(['trigger' => TebexSync::SCHEDULE, 'status' => TebexSync::RUNNING, 'started_at' => now()->subMinute()]);
        $this->fakeTebex();

        $this->sync();

        $this->assertSame(TebexSync::FAILED, $dead->fresh()->status);
        $this->assertStringContainsString('stopped before it finished', $dead->fresh()->error);
        $this->assertSame(TebexSync::RUNNING, $live->fresh()->status, 'one that may still be going is left alone');
    }

    #[Test]
    public function only_the_most_recent_runs_are_kept(): void
    {
        config(['tebex.sync.keep_runs' => 3]);
        $this->fakeTebex();

        // (Not "ids 3, 4 and 5": MySQL does not give ids back when a test rolls back, so they depend on what ran before.)
        $ids = array_map(fn () => $this->sync()->id, range(1, 5));

        $this->assertSame(3, TebexSync::query()->count());
        $this->assertSame(array_slice($ids, -3), TebexSync::query()->orderBy('id')->pluck('id')->all());
    }

    // --- When the scheduler runs it -----------------------------------------------------------------------

    #[Test]
    public function the_schedule_runs_it_only_when_it_is_set_up_switched_on_and_due(): void
    {
        $catalogue = app(TebexCatalogue::class);
        config(['tebex.sync.every_minutes' => 60]);

        $this->assertTrue($catalogue->isDue(), 'never run');

        $this->fakeTebex();
        $this->sync();
        $this->assertFalse($catalogue->isDue(), 'just run');

        $this->travel(30)->minutes();
        $this->assertFalse($catalogue->isDue());

        $this->travel(29)->minutes();
        $this->assertTrue($catalogue->isDue(), 'about an hour: a little early is fine');

        config(['tebex.sync.every_minutes' => 0]);
        $this->assertFalse($catalogue->isDue(), 'switched off');

        config(['tebex.sync.every_minutes' => 60, 'tebex.public_token' => null]);
        $this->assertFalse($catalogue->isDue(), 'not set up');
    }

    #[Test]
    public function a_failed_run_counts_as_a_try_so_a_store_that_is_down_is_not_hammered(): void
    {
        config(['tebex.sync.every_minutes' => 60]);
        $this->fakeTebexHttp(['headless.tebex.io/*' => Http::response([], 503)]);
        $this->sync(TebexSync::SCHEDULE);

        $this->assertFalse(app(TebexCatalogue::class)->isDue());
    }
}
