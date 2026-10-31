<?php

namespace Tests\Feature\Settings;

use App\Models\SystemSetting;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * A request reads the system settings with one query, not one for each setting it asks for, and still sees every change made to them
 * (docs/query-budgets.md).
 */
class SettingsSnapshotTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The queries the closure runs that read or write the settings table.
     *
     * @return list<string>
     */
    private function settingsQueries(Closure $work): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $work();
            $log = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        return array_values(array_filter(array_column($log, 'query'), fn (string $sql) => str_contains($sql, 'system_settings')));
    }

    #[Test]
    public function every_setting_a_request_asks_for_costs_one_query_in_all(): void
    {
        foreach (['maintenance_mode' => false, 'website_sections' => ['blog' => true], 'oauth_providers' => [], 'legal_pages' => ['terms' => 'x'], 'commerce.provider' => 'stripe'] as $key => $value) {
            SystemSetting::set($key, $value);
        }

        $queries = $this->settingsQueries(function () {
            foreach (range(1, 3) as $unused) {
                SystemSetting::get('maintenance_mode');
                SystemSetting::get('website_sections');
                SystemSetting::get('oauth_providers');
                SystemSetting::get('legal_pages');
                SystemSetting::get('commerce.provider');
                SystemSetting::get('never_saved', 'x');
            }
        });

        $this->assertCount(1, $queries);
    }

    #[Test]
    public function a_value_saved_is_the_value_read_and_only_a_missing_or_null_one_gives_the_default(): void
    {
        SystemSetting::set('off', false);
        SystemSetting::set('zero', 0);
        SystemSetting::set('nothing', []);
        SystemSetting::set('text', 'hello');
        SystemSetting::set('unset', null);

        $this->assertFalse(SystemSetting::get('off', true), 'false is a value');
        $this->assertSame(0, SystemSetting::get('zero', 9));
        $this->assertSame([], SystemSetting::get('nothing', ['x']));
        $this->assertSame('hello', SystemSetting::get('text'));
        $this->assertSame('fallback', SystemSetting::get('unset', 'fallback'), 'a null is no value, as it was before the snapshot');
        $this->assertSame('fallback', SystemSetting::get('missing', 'fallback'));
        $this->assertNull(SystemSetting::get('missing'));
    }

    #[Test]
    public function a_setting_that_is_saved_is_seen_by_the_next_read_in_the_same_request(): void
    {
        $this->assertSame('none', SystemSetting::get('mode', 'none')); // reads the table, which is empty

        SystemSetting::set('mode', 'on');
        $this->assertSame('on', SystemSetting::get('mode'), 'a new setting');

        SystemSetting::set('mode', 'off');
        $this->assertSame('off', SystemSetting::get('mode'), 'a changed one');

        SystemSetting::query()->where('key', 'mode')->firstOrFail()->update(['value' => 'again']);
        $this->assertSame('again', SystemSetting::get('mode'), 'one saved as a model');
    }

    #[Test]
    public function a_setting_that_is_deleted_is_gone_from_the_next_read(): void
    {
        SystemSetting::set('mode', 'on');
        $this->assertSame('on', SystemSetting::get('mode'));

        SystemSetting::query()->where('key', 'mode')->firstOrFail()->delete();

        $this->assertSame('gone', SystemSetting::get('mode', 'gone'));
    }

    #[Test]
    public function a_setting_written_in_a_transaction_that_is_rolled_back_is_not_remembered(): void
    {
        SystemSetting::set('mode', 'before');

        try {
            DB::transaction(function () {
                SystemSetting::set('mode', 'inside');
                $this->assertSame('inside', SystemSetting::get('mode'));

                throw new RuntimeException('Something later in the transaction failed.');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame('before', SystemSetting::get('mode'));
    }

    #[Test]
    public function the_next_request_starts_from_the_table_again(): void
    {
        SystemSetting::set('mode', 'before');
        $this->assertSame('before', SystemSetting::get('mode'));

        // Somebody else (another server, a queue worker, a script) writes round the model, so nothing here can notice.
        DB::table('system_settings')->where('key', 'mode')->update(['value' => json_encode('after')]);
        $this->assertSame('before', SystemSetting::get('mode'), 'what this request has read it keeps reading');

        $this->app->forgetScopedInstances(); // what the start of a request, an Octane request or a queue job does

        $this->assertSame('after', SystemSetting::get('mode'));
    }

    #[Test]
    public function a_page_reads_the_settings_with_one_query(): void
    {
        $member = User::factory()->create();

        foreach ([
            'the home page' => fn () => $this->get('/'),
            'the forum' => fn () => $this->get('/forum'),
            'the shop' => fn () => $this->get('/shop'),
            'a member\'s dashboard' => fn () => $this->actingAs($member)->get('/dashboard'),
        ] as $page => $request) {
            $this->app->forgetScopedInstances();

            $queries = $this->settingsQueries(fn () => $request()->assertOk());

            $this->assertCount(1, $queries, "{$page} read the settings more than once:\n".implode("\n", $queries));
        }
    }
}
