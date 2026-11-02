<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Install\EnvironmentFile;
use App\Support\Install\Requirement;
use App\Support\Install\Requirements;
use Database\Seeders\BaselineSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\LegalPagesSeeder;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * Sets up a site that was just cloned: checks the machine, makes `.env` and the app key, creates the tables, links `public/storage`, adds
 * the roles, support categories and badges, and makes the first administrator (docs/install.md).
 *
 * Every step can be run again. A site that already has an administrator keeps its roles, categories and badges as staff left them, and
 * the command only runs migrations that are still to run. It never empties a table and it never touches `.env` beyond the two settings
 * it was asked to change.
 */
class InstallCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'metaforger:install
        {--app-name= : Name of the site (APP_NAME). Asked for when there is no .env yet}
        {--app-url= : Address the site is served from (APP_URL). Asked for when there is no .env yet}
        {--admin-nickname= : Nickname of the first administrator}
        {--admin-email= : Email address of the first administrator}
        {--admin-password= : Password of the first administrator. Other people on this machine can see it while the command runs, so prefer the prompt}
        {--skip-admin : Do not make an administrator}
        {--demo : Add demo data: a shop, a poll and demo accounts with the password "password". Never for a production site}
        {--legal-pages : Add a starting text for the terms, the privacy policy and the cookie policy where those pages are empty. They are public as soon as they have text}
        {--no-storage-link : Do not link public/storage}
        {--force : Run in production}';

    protected $description = 'Set up a new MetaForger site: check the machine, make .env and the key, create the tables and the first administrator';

    /** @var array{nickname: string, email: string, password: string}|null */
    private ?array $admin = null;

    public function handle(Requirements $requirements): int
    {
        $env = new EnvironmentFile($this->laravel->environmentFilePath());

        if (! $this->confirmToProceed('Application In Production', fn () => $this->isProduction($env))) {
            return self::FAILURE;
        }

        if ($this->option('demo') && $this->isProduction($env)) {
            $this->components->error('Demo data has accounts with a password everybody knows, so it is never added to a production site. Run the command without --demo.');

            return self::FAILURE;
        }

        // Everything that can be found out before anything is written is found out first.
        if (! $this->machineIsReady($requirements)) {
            return self::FAILURE;
        }

        $settings = $this->askForSettings($env);

        if ($settings === null || ! $this->planAdministrator()) {
            return self::FAILURE;
        }

        try {
            $this->components->info('Setting up');

            $this->prepareEnvironment($env, $settings);

            if (! $this->prepareDatabase()) {
                return self::FAILURE;
            }

            $this->linkStorage();

            if (! $this->seedAndMakeAdministrator()) {
                return self::FAILURE;
            }

            if ($this->option('demo')) {
                $this->components->task('Adding demo data', fn () => $this->callSilent('db:seed', ['--class' => DemoSeeder::class, '--force' => true]) === self::SUCCESS);
            }

            if ($this->option('legal-pages')) {
                $this->addLegalPages();
            }
        } catch (Throwable $error) {
            $this->newLine();
            $this->components->error($error->getMessage());
            $this->line('  Fix that and run the command again: it carries on where it stopped.');

            return self::FAILURE;
        }

        $this->finish($settings['url'] ?? (string) config('app.url'));

        return self::SUCCESS;
    }

    /**
     * Whether this is, or is about to become, a production site. A checkout that has no `.env` and no APP_ENV anywhere has no answer, and
     * Laravel reads that as "production", so on a fresh clone the `.env` this command makes from the example is what decides.
     */
    private function isProduction(EnvironmentFile $env): bool
    {
        if ($env->exists() || $this->option('env') !== null || Env::get('APP_ENV') !== null) {
            return $this->laravel->environment('production');
        }

        return strtolower((new EnvironmentFile(base_path('.env.example')))->get('APP_ENV') ?? 'production') === 'production';
    }

    private function machineIsReady(Requirements $requirements): bool
    {
        $this->components->info('Checking this machine');

        $config = $this->connectionConfig();
        $driver = (string) $config['driver'];
        $sqliteFile = $driver === 'sqlite' ? $this->sqliteFile($config) : null;
        $redisFor = array_keys(array_filter([
            'cache' => config('cache.default') === 'redis',
            'queue' => config('queue.default') === 'redis',
            'session' => config('session.driver') === 'redis',
        ]));

        $results = $requirements->check(
            $driver,
            [storage_path(), base_path('bootstrap/cache'), ...($sqliteFile !== null ? [dirname($sqliteFile)] : [])],
            $redisFor,
            max(0, (int) config('commerce.downloads.max_kilobytes')) * 1024,
        );

        foreach ($results as $one) {
            $this->components->twoColumnDetail($one->name, $this->verdict($one));
        }

        $failures = Requirements::failures($results);

        if ($failures !== []) {
            $this->newLine();
            $this->components->error('Install what is marked FAIL, then run this command again. Nothing has been changed.');

            return false;
        }

        return true;
    }

    private function verdict(Requirement $one): string
    {
        if ($one->ok) {
            return '<fg=green>'.$one->detail.'</>';
        }

        return $one->required ? '<fg=red>FAIL: '.$one->detail.'</>' : '<fg=yellow>'.$one->detail.'</>';
    }

    /**
     * The name and the address of the site, for a site that has no .env yet or when they were given.
     *
     * @return array{name?: string, url?: string}|null Null when what was given is not usable.
     */
    private function askForSettings(EnvironmentFile $env): ?array
    {
        $settings = [];
        $fresh = ! $env->exists();
        $example = new EnvironmentFile(base_path('.env.example'));

        $name = $this->option('app-name');
        $url = $this->option('app-url');

        if ($name === null && $fresh && $this->input->isInteractive()) {
            $name = $this->ask('Name of the site (APP_NAME)', $example->get('APP_NAME') ?? 'MetaForger');
        }

        if ($url === null && $fresh && $this->input->isInteractive()) {
            $this->line('  The address you will open the site at: http://localhost:8000 for `php artisan serve`, or the .test address under Herd.');
            $url = $this->ask('Address of the site (APP_URL)', $example->get('APP_URL') ?? 'http://localhost');
        }

        if (is_string($name) && $name !== '') {
            if (preg_match('/[\r\n]/', $name) === 1) {
                $this->components->error('The name of the site cannot have a line break in it.');

                return null;
            }

            $settings['name'] = trim($name);
        }

        if (is_string($url) && $url !== '') {
            $url = rtrim(trim($url), '/');

            if (filter_var($url, FILTER_VALIDATE_URL) === false || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
                $this->components->error("\"{$url}\" is not an address the site can be served from. Start it with http:// or https://.");

                return null;
            }

            $settings['url'] = $url;
        }

        return $settings;
    }

    /**
     * Work out who the administrator is before anything is written, so that a missing password does not leave a half-made site.
     */
    private function planAdministrator(): bool
    {
        if ($this->option('skip-admin')) {
            return true;
        }

        if ($this->administratorExists()) {
            if ($this->option('demo') && User::query()->whereIn('email', array_keys(DemoSeeder::accounts()))->role('admin')->exists()) {
                $this->components->error('An administrator of this site has an address the demo data uses, and adding the demo data would reset their password to "password". Leave out --demo. Nothing has been changed.');

                return false;
            }

            $this->components->info('This site already has an administrator, so a new one is not made.');

            return true;
        }

        $interactive = $this->input->isInteractive();
        $nickname = $this->option('admin-nickname');
        $email = $this->option('admin-email');
        $password = $this->option('admin-password');

        if (! $interactive && ($nickname === null || $email === null || $password === null)) {
            $this->components->error('There is no administrator yet. Give --admin-nickname, --admin-email and --admin-password, or --skip-admin to make one later. Nothing has been changed.');

            return false;
        }

        $this->newLine();
        $this->components->info('The first administrator');

        $nickname = is_string($nickname) ? $nickname : $this->askValid('Nickname', ['required', 'string', 'max:255']);
        $email = is_string($email) ? $email : $this->askValid('Email address', ['required', 'string', 'lowercase', 'email', 'max:255']);

        $problems = Validator::make(
            ['nickname' => $nickname, 'email' => $email],
            ['nickname' => ['required', 'string', 'max:255'], 'email' => ['required', 'string', 'lowercase', 'email', 'max:255']],
        )->errors()->all();

        if ($this->option('demo')) {
            $problems = [...$problems, ...$this->demoCollisions((string) $nickname, (string) $email)];
        }

        if (is_string($password)) {
            $problems = [...$problems, ...Validator::make(['password' => $password], ['password' => ['required', Password::defaults()]])->errors()->all()];
        } else {
            $password = $this->askForPassword();
        }

        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->components->error($problem);
            }

            return false;
        }

        $this->admin = ['nickname' => (string) $nickname, 'email' => (string) $email, 'password' => (string) $password];

        return true;
    }

    /**
     * What would go wrong if the demo data were added after this administrator was made. The demo seeders reset an account that has one of
     * their email addresses to the password "password" (and it would still be an administrator), and nicknames are unique.
     *
     * @return list<string>
     */
    private function demoCollisions(string $nickname, string $email): array
    {
        $problems = [];

        foreach (DemoSeeder::accounts() as $demoEmail => $demoNickname) {
            if (strtolower(trim($email)) === strtolower($demoEmail)) {
                $problems[] = "The demo data has an account with the address {$demoEmail}, and adding it would reset the password of the account with that address to \"password\". Give the administrator another address, or leave out --demo.";
            }

            if (mb_strtolower(trim($nickname)) === mb_strtolower($demoNickname)) {
                $problems[] = "The demo data has an account with the nickname \"{$demoNickname}\", and nicknames are unique. Give the administrator another nickname, or leave out --demo.";
            }
        }

        return $problems;
    }

    /**
     * @param  list<string>  $rules
     */
    private function askValid(string $label, array $rules): string
    {
        while (true) {
            $answer = (string) $this->ask($label);
            $errors = Validator::make(['value' => $answer], ['value' => $rules])->errors()->all();

            if ($errors === []) {
                return $answer;
            }

            foreach ($errors as $error) {
                $this->components->error(str_replace('value', strtolower($label), $error));
            }
        }
    }

    private function askForPassword(): string
    {
        while (true) {
            $password = (string) $this->secret('Password');
            $errors = Validator::make(['password' => $password], ['password' => ['required', Password::defaults()]])->errors()->all();

            if ($errors !== []) {
                foreach ($errors as $error) {
                    $this->components->error($error);
                }

                continue;
            }

            if ($password !== (string) $this->secret('Password again')) {
                $this->components->error('The two passwords are not the same.');

                continue;
            }

            return $password;
        }
    }

    /**
     * @param  array{name?: string, url?: string}  $settings
     */
    private function prepareEnvironment(EnvironmentFile $env, array $settings): void
    {
        $created = ! $env->exists();
        $changed = $created;

        if ($created) {
            $this->components->task('Making .env from .env.example', fn () => $env->createFrom(base_path('.env.example')));
        } else {
            $this->components->twoColumnDetail('.env', '<fg=green>already there, kept as it is</>');
        }

        foreach (['name' => 'APP_NAME', 'url' => 'APP_URL'] as $setting => $key) {
            if (isset($settings[$setting])) {
                $this->components->task("Setting {$key}", fn () => $env->set($key, $settings[$setting]));
                $changed = true;
            }
        }

        if (config('app.key') === null || config('app.key') === '') {
            if ($env->get('APP_KEY') === null) {
                $env->set('APP_KEY', '');
            }

            $this->components->task('Making the application key', fn () => $this->callSilent('key:generate', ['--force' => true]) === self::SUCCESS);
            $changed = true;
        }

        if ($changed && $this->laravel->configurationIsCached()) {
            $this->components->warn('The configuration is cached, so .env is not read until you run `php artisan config:clear`.');
        }
    }

    private function prepareDatabase(): bool
    {
        $config = $this->connectionConfig();
        $driver = (string) $config['driver'];
        $database = (string) ($config['database'] ?? '');
        $sqliteFile = $driver === 'sqlite' ? $this->sqliteFile($config) : null;
        $where = $driver === 'sqlite'
            ? $database
            : trim(($config['host'] ?? '').(isset($config['port']) ? ':'.$config['port'] : '').'/'.$database, '/');

        $this->components->twoColumnDetail('Database', $driver.' '.$where);

        if ($this->input->isInteractive() && ! $this->components->confirm('Create the tables in this database?', true)) {
            $this->components->warn('Stopped. Nothing has been changed in the database. Set DB_* in .env and run the command again.');

            return false;
        }

        if ($sqliteFile !== null && ! is_file($sqliteFile)) {
            $this->components->task('Making the SQLite file', fn () => touch($sqliteFile) || throw new RuntimeException("Could not make {$sqliteFile}."));
        }

        $before = $this->migrationCount();

        $this->components->task('Creating the tables', fn () => $this->callSilent('migrate', ['--force' => true]) === self::SUCCESS);

        $ran = $this->migrationCount() - $before;
        $this->components->twoColumnDetail('Migrations', $ran > 0 ? "{$ran} run" : '<fg=green>already up to date</>');

        return true;
    }

    /**
     * The settings of the default connection as Laravel will use them. `DB_URL` is folded into them the way the database manager does it,
     * so it can change the driver, the host and the file: the raw `config()` entry is not what the connection will be.
     *
     * @return array<string, mixed>
     */
    private function connectionConfig(): array
    {
        $connection = (string) config('database.default');
        $config = (array) config("database.connections.{$connection}", []);

        $config = (new ConfigurationUrlParser)->parseConfiguration($config);
        $config['driver'] ??= $connection;

        return $config;
    }

    /**
     * The file of a SQLite database, or null where there is none to make: in memory, or a `file:` address that SQLite opens itself. A path
     * that is not absolute is looked for in the project folder, as the connector does.
     *
     * @param  array<string, mixed>  $config
     */
    private function sqliteFile(array $config): ?string
    {
        $database = (string) ($config['database'] ?? '');

        if ($database === '' || $database === ':memory:' || str_starts_with($database, 'file:') || str_contains($database, 'mode=memory')) {
            return null;
        }

        return preg_match('~^([A-Za-z]:)?[\\\\/]~', $database) === 1 ? $database : base_path($database);
    }

    private function migrationCount(): int
    {
        return Schema::hasTable('migrations') ? DB::table('migrations')->count() : 0;
    }

    private function linkStorage(): void
    {
        if ($this->option('no-storage-link')) {
            return;
        }

        if ($this->storageIsLinked()) {
            $this->components->twoColumnDetail('public/storage', '<fg=green>already linked</>');

            return;
        }

        $this->callSilent('storage:link');

        if ($this->storageIsLinked()) {
            $this->components->twoColumnDetail('public/storage', '<fg=green>linked</>');
        } else {
            $this->components->warn('Could not link public/storage. Run `php artisan storage:link` yourself (on Windows that needs a terminal that may make symbolic links). Public uploads need it.');
        }
    }

    /**
     * A link that points nowhere (its folder was deleted) is still a link, and `storage:link` would refuse to replace it.
     *
     * @phpstan-impure It reads the file system, which `storage:link` changes between two calls.
     */
    private function storageIsLinked(): bool
    {
        clearstatcache();

        return file_exists(public_path('storage')) || is_link(public_path('storage'));
    }

    private function seedAndMakeAdministrator(): bool
    {
        if ($this->administratorExists()) {
            $this->components->twoColumnDetail('Roles, categories and badges', '<fg=green>left as staff have them</>');

            return true;
        }

        // RolePermissionSeeder gives the administrator role to member 1. On a database that has members but no administrator that would
        // be somebody who did not ask for it, with or without --skip-admin.
        if (User::query()->exists()) {
            $this->components->error('This database has members but no administrator, and the installer does not choose who becomes one. Give the role yourself: `php artisan tinker`, then `User::find(1)->assignRole("admin")` after `php artisan db:seed --class=BaselineSeeder`.');

            return false;
        }

        $this->components->task('Adding roles, support categories and badges', fn () => $this->callSilent('db:seed', ['--class' => BaselineSeeder::class, '--force' => true]) === self::SUCCESS);

        $admin = $this->admin;

        if ($admin === null) {
            return true;
        }

        $this->components->task('Making the administrator', function () use ($admin) {
            $user = User::create([
                'nickname' => $admin['nickname'],
                'email' => $admin['email'],
                'password' => Hash::make($admin['password']),
                'email_verified_at' => now(),
            ]);

            $user->assignRole('admin');
        });

        return true;
    }

    /**
     * The seeder fills only the pages that are empty and says what is left to fill in, so its report is shown (a silent run would hide
     * the list). The pages are public as soon as they have text, which is why this is asked for and not done for everybody.
     */
    private function addLegalPages(): void
    {
        $this->components->info('Legal pages');

        if ($this->call('db:seed', ['--class' => LegalPagesSeeder::class, '--force' => true]) !== self::SUCCESS) {
            throw new RuntimeException('The starting text for the legal pages could not be added.');
        }
    }

    private function administratorExists(): bool
    {
        try {
            if (! Schema::hasTable('roles') || ! Schema::hasTable('model_has_roles')) {
                return false;
            }

            $role = Role::query()->where('name', 'admin')->first();

            return $role !== null && $role->users()->exists();
        } catch (Throwable) {
            return false;
        }
    }

    private function finish(string $url): void
    {
        $this->newLine();
        $this->components->info('The site is set up.');

        $steps = [];

        if ($this->admin !== null) {
            $steps[] = 'Sign in at '.rtrim($url, '/').'/login as '.$this->admin['email'].'.';
        }

        if (! is_file(public_path('build/manifest.json')) && ! is_file(public_path('hot'))) {
            $steps[] = 'Build the front end: `npm run build`. It is not built yet, so pages cannot load their scripts.';
        }

        $steps[] = 'Run it: `composer dev` starts the site, the queue worker, the scheduler and Vite. Under Herd or Valet the site is already served; start the rest with `php artisan metaforger:queue listen --tries=1`, `php artisan schedule:work` and `npm run dev`.';
        $steps[] = $this->option('legal-pages')
            ? 'In the admin area (Admin > Legal pages): fill in the [Bracketed items] listed above and delete the note at the top of each page. The pages are public already. Then review the system settings.'
            : 'In the admin area: write the legal pages (Admin > Legal pages), or start from a text with `php artisan db:seed --class=LegalPagesSeeder`, which fills only empty pages. Then review the system settings.';
        $steps[] = 'Mail is written to storage/logs until you set MAIL_MAILER in .env. Payments need the Stripe keys (docs/commerce.md).';

        if ($this->option('demo')) {
            $steps[] = 'Demo accounts have the password "password". Do not leave them on a site anyone can reach.';
        }

        $this->components->bulletList($steps);
    }
}
