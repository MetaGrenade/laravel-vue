<?php

namespace Tests\Feature\Install;

use App\Models\Badge;
use App\Models\Poll;
use App\Models\SubscriptionPlan;
use App\Models\SupportTicketCategory;
use App\Models\User;
use App\Support\Install\Requirements;
use Database\Seeders\BaselineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\FakePhpProbe;
use Tests\TestCase;

/**
 * `php artisan metaforge:install` (docs/install.md). The test database is the one a fresh checkout has after its migrations, and the
 * environment file is a folder of its own, so the project's own `.env` is never read or written.
 */
class InstallCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'metaforge-install-'.bin2hex(random_bytes(6));
        mkdir($this->dir);

        $this->app->useEnvironmentPath($this->dir);
        $this->app->loadEnvironmentFrom('.env');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function envPath(): string
    {
        return $this->dir.DIRECTORY_SEPARATOR.'.env';
    }

    private function env(): string
    {
        return (string) file_get_contents($this->envPath());
    }

    /**
     * @return array<string, mixed>
     */
    private function adminOptions(): array
    {
        return [
            '--admin-nickname' => 'owner',
            '--admin-email' => 'owner@example.test',
            '--admin-password' => self::PASSWORD,
        ];
    }

    /**
     * The command as a script or a CI job runs it: nothing is asked, nothing is linked into the project's public folder.
     *
     * @param  array<string, mixed>  $options
     */
    private function install(array $options = [])
    {
        return $this->artisan('metaforge:install', [
            '--no-interaction' => true,
            '--no-storage-link' => true,
            ...$options,
        ]);
    }

    private function assertNothingWritten(): void
    {
        $this->assertFileDoesNotExist($this->envPath());
        $this->assertSame(0, Role::count());
        $this->assertSame(0, User::count());
        $this->assertSame(0, SupportTicketCategory::count());
    }

    #[Test]
    public function it_sets_up_a_fresh_site_with_its_first_administrator(): void
    {
        $this->install([
            '--app-name' => 'Forge Town',
            '--app-url' => 'https://forge.example.test/',
            ...$this->adminOptions(),
        ])
            ->expectsOutputToContain('The site is set up.')
            ->expectsOutputToContain('https://forge.example.test/login as owner@example.test')
            ->assertSuccessful();

        $this->assertStringContainsString('APP_NAME="Forge Town"', $this->env());
        $this->assertStringContainsString("APP_URL=https://forge.example.test\n", $this->env());

        $this->assertEqualsCanonicalizing(['admin', 'editor', 'moderator', 'user'], Role::pluck('name')->all());
        $this->assertGreaterThan(0, SupportTicketCategory::count());
        $this->assertGreaterThan(0, Badge::count());

        $admin = User::query()->where('email', 'owner@example.test')->sole();
        $this->assertSame('owner', $admin->nickname);
        $this->assertTrue($admin->hasRole('admin'));
        $this->assertNotNull($admin->email_verified_at);
        $this->assertTrue(Hash::check(self::PASSWORD, $admin->password));
        $this->assertSame(1, User::count());
    }

    #[Test]
    public function the_administrator_is_the_one_who_was_asked_for_whatever_id_it_gets(): void
    {
        // Member 1 is whoever the database numbers first. RolePermissionSeeder would give them the administrator role if it ran after
        // them, so the command makes its administrator after the seeding and gives the role by name.
        User::factory()->create()->delete();

        $this->install($this->adminOptions())->assertSuccessful();

        $admin = User::query()->where('email', 'owner@example.test')->sole();

        $this->assertGreaterThan(1, $admin->id);
        $this->assertSame([$admin->id], Role::findByName('admin')->users()->pluck('id')->all());
    }

    #[Test]
    public function it_keeps_an_env_that_is_already_there_byte_for_byte(): void
    {
        $original = "# mine\nAPP_NAME=\"Kept Name\"\nAPP_KEY=base64:c29tZXRoaW5n\nDB_CONNECTION=sqlite\r\nCUSTOM=1 # note\n";
        file_put_contents($this->envPath(), $original);

        $this->install($this->adminOptions())
            ->expectsOutputToContain('already there, kept as it is')
            ->assertSuccessful();

        $this->assertSame($original, $this->env());
    }

    #[Test]
    public function it_changes_only_the_two_settings_it_was_given(): void
    {
        file_put_contents($this->envPath(), "# mine\nAPP_NAME=Old\nAPP_URL=http://old.test\nCUSTOM=1\n");

        $this->install(['--app-name' => 'New Name', '--app-url' => 'http://new.test', ...$this->adminOptions()])->assertSuccessful();

        $this->assertSame("# mine\nAPP_NAME=\"New Name\"\nAPP_URL=http://new.test\nCUSTOM=1\n", $this->env());
    }

    #[Test]
    public function it_makes_env_from_the_example_when_there_is_none(): void
    {
        $this->install($this->adminOptions())->assertSuccessful();

        $example = (string) file_get_contents(base_path('.env.example'));

        // The key may have been made too, so the line is left out of the comparison.
        $this->assertSame(
            preg_replace('/^APP_KEY=.*$/m', '', $example),
            preg_replace('/^APP_KEY=.*$/m', '', $this->env()),
        );
    }

    #[Test]
    public function it_makes_the_application_key_when_the_app_has_none(): void
    {
        config(['app.key' => '']);

        $this->install($this->adminOptions())->assertSuccessful();

        $this->assertMatchesRegularExpression('/^APP_KEY=base64:[A-Za-z0-9+\/]{43}=$/m', $this->env());
        $this->assertNotSame('', config('app.key'));
    }

    #[Test]
    public function it_makes_the_application_key_when_the_env_has_no_line_for_it(): void
    {
        config(['app.key' => '']);
        file_put_contents($this->envPath(), "APP_NAME=NoKeyLine\n");

        $this->install($this->adminOptions())->assertSuccessful();

        $this->assertMatchesRegularExpression('/^APP_KEY=base64:/m', $this->env());
        $this->assertStringContainsString('APP_NAME=NoKeyLine', $this->env());
    }

    #[Test]
    public function it_never_replaces_a_key_that_is_there(): void
    {
        file_put_contents($this->envPath(), "APP_KEY=base64:c29tZXRoaW5n\n");

        $this->install($this->adminOptions())->assertSuccessful();

        $this->assertSame("APP_KEY=base64:c29tZXRoaW5n\n", $this->env());
    }

    #[Test]
    public function it_asks_what_it_needs_when_it_is_run_by_a_person(): void
    {
        $this->artisan('metaforge:install', ['--no-storage-link' => true])
            ->expectsQuestion('Name of the site (APP_NAME)', 'Asked Name')
            ->expectsQuestion('Address of the site (APP_URL)', 'https://asked.test/')
            ->expectsQuestion('Nickname', 'chief')
            ->expectsQuestion('Email address', 'chief@example.test')
            ->expectsQuestion('Password', self::PASSWORD)
            ->expectsQuestion('Password again', self::PASSWORD)
            ->expectsConfirmation('Create the tables in this database?', 'yes')
            ->assertSuccessful();

        $this->assertStringContainsString('APP_NAME="Asked Name"', $this->env());
        $this->assertStringContainsString("APP_URL=https://asked.test\n", $this->env());
        $this->assertTrue(User::query()->where('email', 'chief@example.test')->sole()->hasRole('admin'));
    }

    #[Test]
    public function it_asks_again_when_the_answers_are_no_good(): void
    {
        $this->artisan('metaforge:install', ['--no-storage-link' => true, '--app-name' => 'Site', '--app-url' => 'http://site.test'])
            ->expectsQuestion('Nickname', 'chief')
            ->expectsQuestion('Email address', 'not-an-email')
            ->expectsQuestion('Email address', 'chief@example.test')
            ->expectsQuestion('Password', 'short')
            ->expectsQuestion('Password', self::PASSWORD)
            ->expectsQuestion('Password again', 'something else entirely')
            ->expectsQuestion('Password', self::PASSWORD)
            ->expectsQuestion('Password again', self::PASSWORD)
            ->expectsConfirmation('Create the tables in this database?', 'yes')
            ->expectsOutputToContain('The two passwords are not the same.')
            ->assertSuccessful();

        $this->assertTrue(Hash::check(self::PASSWORD, User::query()->where('email', 'chief@example.test')->sole()->password));
    }

    #[Test]
    public function a_person_who_declines_the_tables_leaves_the_database_alone(): void
    {
        $this->artisan('metaforge:install', ['--no-storage-link' => true, '--skip-admin' => true, '--app-name' => 'Site', '--app-url' => 'http://site.test'])
            ->expectsConfirmation('Create the tables in this database?', 'no')
            ->expectsOutputToContain('Nothing has been changed in the database')
            ->assertFailed();

        $this->assertSame(0, Role::count());
    }

    #[Test]
    public function it_can_skip_the_administrator(): void
    {
        $this->install(['--skip-admin' => true])->assertSuccessful();

        $this->assertSame(0, User::count());
        $this->assertSame(4, Role::count());
    }

    #[Test]
    public function without_options_and_without_a_person_it_stops_before_writing_anything(): void
    {
        $this->install()
            ->expectsOutputToContain('Give --admin-nickname, --admin-email and --admin-password')
            ->assertFailed();

        $this->assertNothingWritten();
    }

    #[Test]
    public function a_half_given_administrator_stops_before_writing_anything(): void
    {
        $this->install(['--admin-nickname' => 'owner', '--admin-email' => 'owner@example.test'])->assertFailed();

        $this->assertNothingWritten();
    }

    #[Test]
    public function a_weak_password_stops_before_writing_anything(): void
    {
        $this->install([...$this->adminOptions(), '--admin-password' => 'short'])->assertFailed();

        $this->assertNothingWritten();
    }

    #[Test]
    public function an_email_that_is_not_one_stops_before_writing_anything(): void
    {
        $this->install([...$this->adminOptions(), '--admin-email' => 'owner at example'])->assertFailed();

        $this->assertNothingWritten();
    }

    #[Test]
    public function an_address_that_cannot_be_served_stops_before_writing_anything(): void
    {
        foreach (['ftp://files.example.test', 'forge.example.test', 'not an address'] as $url) {
            $this->install(['--app-url' => $url, ...$this->adminOptions()])
                ->expectsOutputToContain('is not an address the site can be served from')
                ->assertFailed();
        }

        $this->assertNothingWritten();
    }

    #[Test]
    public function a_name_with_a_line_break_stops_before_writing_anything(): void
    {
        $this->install(['--app-name' => "One\nTwo", ...$this->adminOptions()])->assertFailed();

        $this->assertNothingWritten();
    }

    #[Test]
    public function a_site_that_has_an_administrator_is_not_seeded_again(): void
    {
        $this->install($this->adminOptions())->assertSuccessful();

        // What staff may have done on purpose since: removed a category and a badge, and taken a permission from a role.
        SupportTicketCategory::query()->first()->delete();
        Badge::query()->first()->delete();
        $categories = SupportTicketCategory::count();
        $badges = Badge::count();

        $editor = Role::findByName('editor');
        $revoked = $editor->permissions()->first();
        $editor->revokePermissionTo($revoked);

        $this->install()
            ->expectsOutputToContain('This site already has an administrator')
            ->expectsOutputToContain('left as staff have them')
            ->assertSuccessful();

        $this->assertSame($categories, SupportTicketCategory::count());
        $this->assertSame($badges, Badge::count());
        $this->assertFalse(Role::findByName('editor')->hasPermissionTo($revoked));
    }

    #[Test]
    public function a_second_run_does_not_make_a_second_administrator_even_when_asked_to(): void
    {
        $this->install($this->adminOptions())->assertSuccessful();

        $this->install([...$this->adminOptions(), '--admin-nickname' => 'second', '--admin-email' => 'second@example.test'])->assertSuccessful();

        $this->assertSame(1, User::count());
        $this->assertFalse(User::query()->where('email', 'second@example.test')->exists());
    }

    #[Test]
    public function members_without_an_administrator_are_not_given_the_role_by_guessing(): void
    {
        $member = User::factory()->create();

        $this->install($this->adminOptions())
            ->expectsOutputToContain('has members but no administrator')
            ->assertFailed();

        $this->assertSame(0, Role::count());
        $this->assertSame(1, User::count());
        $this->assertFalse($member->fresh()->hasRole('admin'));
    }

    #[Test]
    public function members_without_an_administrator_are_refused_with_skip_admin_too(): void
    {
        // RolePermissionSeeder gives the administrator role to member 1, so seeding would make an administrator all the same.
        User::factory()->create();

        $this->install(['--skip-admin' => true])->expectsOutputToContain('has members but no administrator')->assertFailed();

        $this->assertSame(0, Role::count());
    }

    #[Test]
    public function a_machine_that_is_not_ready_stops_everything_and_says_why(): void
    {
        $php = new FakePhpProbe;
        $php->webp = false;
        $php->version = '8.3.0';
        $this->app->instance(Requirements::class, new Requirements($php));

        $this->install($this->adminOptions())
            ->expectsOutputToContain('FAIL: this is PHP 8.3.0')
            ->expectsOutputToContain('FAIL: product pictures and blog covers cannot be saved without it')
            ->expectsOutputToContain('Nothing has been changed')
            ->assertFailed();

        $this->assertNothingWritten();
    }

    #[Test]
    public function advice_about_the_machine_does_not_stop_the_installation(): void
    {
        $php = new FakePhpProbe;
        $php->missingExtensions = ['exif'];
        $php->bytes = ['upload_max_filesize' => 1048576, 'post_max_size' => 1048576];
        $this->app->instance(Requirements::class, new Requirements($php));

        $this->install($this->adminOptions())
            ->expectsOutputToContain('without it a photo taken sideways stays sideways')
            ->expectsOutputToContain('below the')
            ->assertSuccessful();
    }

    #[Test]
    public function a_production_site_needs_force_and_nothing_is_written_without_it(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->install($this->adminOptions())
            ->expectsConfirmation('Are you sure you want to run this command?', 'no')
            ->assertFailed();

        $this->assertNothingWritten();
    }

    /**
     * Run with no APP_ENV anywhere, as on a clone that has no .env yet. Laravel then calls the site "production" for want of an answer.
     */
    private function withoutAppEnv(callable $run): void
    {
        $saved = [$_ENV['APP_ENV'] ?? null, $_SERVER['APP_ENV'] ?? null, getenv('APP_ENV')];

        unset($_ENV['APP_ENV'], $_SERVER['APP_ENV']);
        putenv('APP_ENV');
        $this->app->detectEnvironment(fn () => 'production');

        try {
            $run();
        } finally {
            [$env, $server, $process] = $saved;

            if ($env !== null) {
                $_ENV['APP_ENV'] = $env;
            }

            if ($server !== null) {
                $_SERVER['APP_ENV'] = $server;
            }

            if ($process !== false) {
                putenv("APP_ENV={$process}");
            }
        }
    }

    #[Test]
    public function a_clone_with_no_env_is_not_taken_for_production_just_because_laravel_defaults_to_it(): void
    {
        $this->withoutAppEnv(function () {
            $this->install($this->adminOptions())->assertSuccessful();

            $this->assertFileExists($this->envPath());
        });
    }

    #[Test]
    public function a_clone_with_no_env_but_a_production_app_env_in_the_environment_still_needs_force(): void
    {
        $this->withoutAppEnv(function () {
            $_SERVER['APP_ENV'] = 'production';

            try {
                $this->install($this->adminOptions())
                    ->expectsConfirmation('Are you sure you want to run this command?', 'no')
                    ->assertFailed();

                $this->assertNothingWritten();
            } finally {
                unset($_SERVER['APP_ENV']);
            }
        });
    }

    #[Test]
    public function demo_data_is_refused_on_a_clone_whose_environment_says_production(): void
    {
        $this->withoutAppEnv(function () {
            $_SERVER['APP_ENV'] = 'production';

            try {
                $this->install([...$this->adminOptions(), '--force' => true, '--demo' => true])
                    ->expectsOutputToContain('never added to a production site')
                    ->assertFailed();
            } finally {
                unset($_SERVER['APP_ENV']);
            }
        });
    }

    #[Test]
    public function a_production_site_can_be_set_up_with_force(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->install([...$this->adminOptions(), '--force' => true])->assertSuccessful();

        $this->assertTrue(User::query()->where('email', 'owner@example.test')->sole()->hasRole('admin'));
        $this->assertSame(0, SubscriptionPlan::count());
        $this->assertSame(0, Poll::count());
    }

    #[Test]
    public function demo_data_is_never_added_to_a_production_site_even_with_force(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->install([...$this->adminOptions(), '--force' => true, '--demo' => true])
            ->expectsOutputToContain('never added to a production site')
            ->assertFailed();

        $this->assertNothingWritten();
    }

    #[Test]
    public function a_plain_install_adds_no_demo_data(): void
    {
        $this->install($this->adminOptions())->assertSuccessful();

        $this->assertSame(0, SubscriptionPlan::count());
        $this->assertSame(0, Poll::count());
        $this->assertSame(1, User::count());
    }

    #[Test]
    public function demo_data_is_added_on_request_and_the_warning_about_its_password_is_given(): void
    {
        $this->install([...$this->adminOptions(), '--demo' => true])
            ->expectsOutputToContain('Demo accounts have the password "password"')
            ->assertSuccessful();

        $this->assertGreaterThan(0, SubscriptionPlan::count());
        $this->assertGreaterThan(0, Poll::count());
        $this->assertTrue(User::query()->where('email', 'owner@example.test')->sole()->hasRole('admin'));
    }

    #[Test]
    public function the_migrations_it_has_not_run_are_the_only_ones_that_run(): void
    {
        // RefreshDatabase has run them all already, which is the state of a site that was set up before.
        $this->install($this->adminOptions())
            ->expectsOutputToContain('already up to date')
            ->assertSuccessful();
    }

    #[Test]
    public function an_existing_public_storage_link_is_left_alone(): void
    {
        $public = $this->dir.DIRECTORY_SEPARATOR.'public';
        mkdir($public.DIRECTORY_SEPARATOR.'storage', recursive: true);
        $this->app->usePublicPath($public);

        $this->artisan('metaforge:install', ['--no-interaction' => true, ...$this->adminOptions()])
            ->expectsOutputToContain('already linked')
            ->assertSuccessful();
    }

    #[Test]
    public function it_links_public_storage_when_it_is_not_linked(): void
    {
        $public = $this->dir.DIRECTORY_SEPARATOR.'public';
        $link = $public.DIRECTORY_SEPARATOR.'storage';
        mkdir($public);
        $this->app->usePublicPath($public);
        config(['filesystems.links' => [$link => storage_path('app/public')]]);

        try {
            $this->artisan('metaforge:install', ['--no-interaction' => true, ...$this->adminOptions()])->assertSuccessful();

            if (! is_link($link)) {
                $this->markTestSkipped('This machine does not let PHP make symbolic links (on Windows that needs developer mode or an elevated terminal).');
            }

            $this->assertSame(realpath(storage_path('app/public')), realpath($link));
        } finally {
            // A directory link is removed with rmdir on Windows. Deleting the folder around it does not follow the link into storage.
            if (is_link($link)) {
                PHP_OS_FAMILY === 'Windows' ? rmdir($link) : unlink($link);
            }
        }
    }

    #[Test]
    public function when_the_link_cannot_be_made_it_says_what_to_run_and_carries_on(): void
    {
        $public = $this->dir.DIRECTORY_SEPARATOR.'public';
        mkdir($public);
        $this->app->usePublicPath($public);
        config(['filesystems.links' => []]);

        $this->artisan('metaforge:install', ['--no-interaction' => true, ...$this->adminOptions()])
            ->expectsOutputToContain('Could not link public/storage. Run `php artisan storage:link` yourself')
            ->assertSuccessful();

        $this->assertTrue(User::query()->where('email', 'owner@example.test')->exists());
    }

    #[Test]
    public function baseline_seeding_makes_no_member_and_no_demo_data(): void
    {
        $this->seed(BaselineSeeder::class);

        $this->assertSame(0, User::count());
        $this->assertSame(0, SubscriptionPlan::count());
        $this->assertSame(0, Poll::count());
        $this->assertGreaterThan(0, Permission::count());
        $this->assertGreaterThan(0, SupportTicketCategory::count());
        $this->assertGreaterThan(0, Badge::count());
    }
}
