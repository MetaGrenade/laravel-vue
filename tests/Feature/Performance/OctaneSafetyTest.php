<?php

namespace Tests\Feature\Performance;

use App\Models\SystemSetting;
use App\Payments\PaymentManager;
use App\Payments\Tebex\TebexStoreProfile;
use App\Support\Audit\Audit;
use App\Support\OAuth\OAuthManager;
use App\Support\Seo\Seo;
use App\Support\Settings\SettingsSnapshot;
use Illuminate\Container\Container;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * What the app keeps in memory from one request to the next (docs/octane.md). PHP-FPM forgets everything when a request ends; Octane
 * and a queue worker do not, so state held in a static property, in a singleton or in a closure bound to the container the providers
 * booted in is shared by every request that process serves. Nothing here starts Octane: it is not installed. These hold the audit's
 * findings in place and fail when new code adds state of that kind without anyone having looked at it.
 */
class OctaneSafetyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every static property in app/, the file (from app/) and its name, with why it is safe for a process to keep it.
     *
     * @var array<string, string>
     */
    private const STATIC_PROPERTIES = [
        'Http/Controllers/Webhooks/StripeWebhookController.php::$applying' => 'Set when a delivery starts and removed in a finally, so it is empty between requests; it only stops one delivery being handled twice at once.',
        'Support/Localization/PreferenceOptions.php::$timezoneOptions' => 'PHP\'s own list of time zones: the same for every request.',
        'Support/Localization/PreferenceOptions.php::$localeOptions' => 'ICU\'s list of locales, each named in its own language rather than the request\'s: the same for every request.',
        'Support/Ownership.php::$owners' => 'Set once when teams plug in (1.1), a closure that is given the user and holds nothing of a request. The tests flush it.',
        'Support/Ownership.php::$newRecordOwner' => 'The same, for who owns what a user creates.',
    ];

    /**
     * Every container binding in app/ that is kept for the life of the process, and why that is safe.
     *
     * @var array<string, string>
     */
    private const SHARED_BINDINGS = [
        'SubscriptionManager::class' => 'Holds only the promotion-code service, which holds nothing.',
        'HtmlSanitizer::class' => 'Builds its sanitisers from fixed rules, and holds no request, user or setting.',
    ];

    /** @return array<string, string> Each PHP file in app/ (its path from app/) and what is in it. */
    private function sourceFiles(): array
    {
        $files = [];

        foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
            $files[str_replace('\\', '/', $file->getRelativePathname())] = $file->getContents();
        }

        return $files;
    }

    #[Test]
    public function every_static_property_is_one_that_a_process_may_keep(): void
    {
        $found = [];

        foreach ($this->sourceFiles() as $path => $source) {
            // `private static array $x`, and a `static $x` inside a function. A `static function` is not a property.
            preg_match_all('/^\s*(?:(?:public|protected|private|readonly)\s+)*static\s+(?:(?:public|protected|private|readonly)\s+)*(?!function\b|fn\b)[^;()=]*?\$(\w+)/m', $source, $properties);
            preg_match_all('/^\s*static\s+(?!function\b|fn\b)(?:\??[\w\\\\|]+\s+)?\$(\w+)/m', $source, $variables);

            foreach ([...$properties[1], ...$variables[1]] as $name) {
                $found[] = "{$path}::\${$name}";
            }
        }

        sort($found);
        $listed = array_keys(self::STATIC_PROPERTIES);
        sort($listed);

        $this->assertSame(
            [],
            array_values(array_diff($found, $listed)),
            'A static property lives as long as the process, so under Octane or a queue worker every request and job shares it. If it holds '
            .'anything that belongs to a request, a user or a setting, keep it somewhere that is rebuilt for each one (a `scoped` binding). '
            .'If it is safe, add it to STATIC_PROPERTIES with the reason.',
        );
        $this->assertSame([], array_values(array_diff($listed, $found)), 'Listed here, but no longer in app/: remove it from STATIC_PROPERTIES.');
    }

    #[Test]
    public function every_binding_kept_for_the_life_of_the_process_is_one_that_may_be(): void
    {
        $found = [];

        foreach ($this->sourceFiles() as $source) {
            preg_match_all('/->(?:singleton|singletonIf|instance)\(\s*([^,)]+)/', $source, $bindings);

            foreach ($bindings[1] as $abstract) {
                $found[] = trim($abstract);
            }
        }

        sort($found);
        $listed = array_keys(self::SHARED_BINDINGS);
        sort($listed);

        $this->assertSame(
            [],
            array_values(array_diff($found, $listed)),
            'A `singleton` is built once and kept, so under Octane or a queue worker it serves every request and job. If it holds the '
            .'container, the request, the session, a user, or anything read from the database or the settings, bind it with `scoped` '
            .'(rebuilt for each request and job) instead. If it holds none of that, add it to SHARED_BINDINGS with the reason.',
        );
        $this->assertSame([], array_values(array_diff($listed, $found)), 'Listed here, but no longer bound: remove it from SHARED_BINDINGS.');
    }

    #[Test]
    public function a_worker_boots_without_building_anything_that_belongs_to_a_request(): void
    {
        // Octane builds the application once and copies it for each request, so whatever booting resolves is shared by all of them.
        // Nothing here has made a request yet: this is what the providers and the test's own set-up left behind.
        foreach ($this->perRequest() as $class) {
            $this->assertFalse($this->app->resolved($class), "{$class} is resolved while the application boots, so a worker would keep it for good.");
        }
    }

    #[Test]
    public function what_belongs_to_a_request_is_built_again_for_the_next_one(): void
    {
        foreach ($this->perRequest() as $class) {
            $request = $this->app->make($class);

            $this->assertSame($request, $this->app->make($class), "{$class} is one object for the whole request");

            // What Octane and `queue:work` do between requests and jobs.
            $this->app->forgetScopedInstances();

            $this->assertNotSame($request, $this->app->make($class), "{$class} is kept for the next request");
        }
    }

    /** @return list<class-string> */
    private function perRequest(): array
    {
        return [
            SettingsSnapshot::class,
            Seo::class,
            Audit::class,
            OAuthManager::class,
            PaymentManager::class,
            TebexStoreProfile::class,
        ];
    }

    #[Test]
    public function a_rollback_forgets_the_settings_of_the_container_that_serves_the_request(): void
    {
        SystemSetting::set('mode', 'before');
        $this->app->forgetScopedInstances();

        // What Octane does for each request: serves it from a copy of the application the providers were booted in, and points the
        // container helper and the facades at the copy. The listeners the providers registered stay as they were.
        $sandbox = clone $this->app;
        Container::setInstance($sandbox);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($sandbox);

        try {
            try {
                DB::transaction(function () {
                    SystemSetting::set('mode', 'inside');
                    $this->assertSame('inside', SystemSetting::get('mode'));

                    throw new RuntimeException('Something later in the transaction failed.');
                });
            } catch (RuntimeException) {
            }

            $this->assertSame('before', SystemSetting::get('mode'), 'the request still holds what the rolled back transaction wrote');
        } finally {
            Container::setInstance($this->app);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($this->app);
        }
    }

    #[Test]
    public function mysql_and_mariadb_connections_are_set_to_utc_when_they_are_made(): void
    {
        // Not a statement run once at boot: a worker that loses its connection and makes another would otherwise be in the server's
        // own time zone from then on.
        foreach (['mysql', 'mariadb'] as $connection) {
            $this->assertSame('+00:00', config("database.connections.{$connection}.timezone"), $connection);
        }
    }

    #[Test]
    public function a_new_mysql_connection_is_in_utc_without_anything_having_been_run_on_it(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('The connection tests on MySQL and MariaDB run this.');
        }

        $connection = DB::connectUsing('time-zone-probe', (array) config('database.connections.'.config('database.default')), true);

        try {
            $this->assertSame('+00:00', $connection->selectOne('select @@session.time_zone as zone')->zone);
        } finally {
            DB::purge('time-zone-probe');
        }
    }

    #[Test]
    public function permissions_held_in_memory_are_reset_for_each_octane_request(): void
    {
        // The admin area changes which role holds which permission. Without this, a worker that did not make the change keeps serving
        // the old list until it restarts. The package registers the listener only when Octane is installed.
        $this->assertTrue(config('permission.register_octane_reset_listener'));
    }
}
