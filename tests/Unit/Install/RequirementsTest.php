<?php

namespace Tests\Unit\Install;

use App\Support\Install\PhpProbe;
use App\Support\Install\Requirement;
use App\Support\Install\Requirements;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakePhpProbe;
use Tests\TestCase;

class RequirementsTest extends TestCase
{
    private FakePhpProbe $php;

    protected function setUp(): void
    {
        parent::setUp();

        $this->php = new FakePhpProbe;
    }

    /**
     * @param  list<string>  $writable
     * @param  list<string>  $redisFor
     * @return list<Requirement>
     */
    private function check(string $driver = 'sqlite', array $writable = [], array $redisFor = [], int $uploadBytes = 0): array
    {
        return (new Requirements($this->php))->check($driver, $writable, $redisFor, $uploadBytes);
    }

    /**
     * @param  list<Requirement>  $results
     */
    private function find(array $results, string $name): Requirement
    {
        foreach ($results as $one) {
            if ($one->name === $name) {
                return $one;
            }
        }

        $this->fail("No requirement called \"{$name}\" in: ".implode(', ', array_map(fn (Requirement $one) => $one->name, $results)));
    }

    /**
     * @param  list<Requirement>  $results
     * @return list<string>
     */
    private function failed(array $results): array
    {
        return array_map(fn (Requirement $one) => $one->name, Requirements::failures($results));
    }

    #[Test]
    public function a_machine_that_has_everything_passes(): void
    {
        $this->assertSame([], $this->failed($this->check('sqlite', ['/srv/storage'], ['cache', 'queue'], 50 * 1048576)));
    }

    #[Test]
    public function an_old_php_fails_and_says_which_one_it_is(): void
    {
        $this->php->version = '8.3.9';

        $one = $this->find($this->check(), 'PHP '.Requirements::PHP_MINIMUM.' or newer');

        $this->assertFalse($one->ok);
        $this->assertTrue($one->required);
        $this->assertStringContainsString('8.3.9', $one->detail);
    }

    #[Test]
    public function the_minimum_php_itself_passes(): void
    {
        $this->php->version = Requirements::PHP_MINIMUM;

        $this->assertTrue($this->find($this->check(), 'PHP '.Requirements::PHP_MINIMUM.' or newer')->ok);
    }

    #[Test]
    public function every_missing_extension_is_named_at_once(): void
    {
        $this->php->missingExtensions = ['bcmath', 'zip'];

        $one = $this->find($this->check(), 'PHP extensions');

        $this->assertFalse($one->ok);
        $this->assertSame('missing: bcmath, zip', $one->detail);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function drivers(): array
    {
        return [
            'sqlite' => ['sqlite', 'pdo_sqlite'],
            'mysql' => ['mysql', 'pdo_mysql'],
            'mariadb' => ['mariadb', 'pdo_mysql'],
            'pgsql' => ['pgsql', 'pdo_pgsql'],
        ];
    }

    #[Test]
    #[DataProvider('drivers')]
    public function the_database_driver_needs_its_pdo_extension(string $driver, string $extension): void
    {
        $this->assertTrue($this->find($this->check($driver), "Database driver ({$driver})")->ok);

        $this->php->missingExtensions = [$extension];
        $one = $this->find($this->check($driver), "Database driver ({$driver})");

        $this->assertFalse($one->ok);
        $this->assertStringContainsString($extension, $one->detail);
    }

    #[Test]
    public function a_driver_the_installer_does_not_know_is_not_held_against_the_machine(): void
    {
        $one = $this->find($this->check('sqlsrv'), 'Database driver (sqlsrv)');

        $this->assertTrue($one->ok);
    }

    #[Test]
    public function gd_without_webp_fails(): void
    {
        $this->php->webp = false;

        $this->assertContains('GD with WebP', $this->failed($this->check()));
    }

    #[Test]
    public function redis_is_asked_for_only_where_it_is_used(): void
    {
        $this->php->missingExtensions = ['redis'];

        $this->assertSame([], $this->failed($this->check()));
        $this->assertSame(['Redis for the queue'], $this->failed($this->check('sqlite', [], ['queue', 'queue'])));
        $this->assertSame(['Redis for the cache', 'Redis for the session'], $this->failed($this->check('sqlite', [], ['cache', 'session'])));
    }

    #[Test]
    public function a_folder_the_app_cannot_write_to_fails_and_is_shown_from_the_project_root(): void
    {
        $this->php->unwritable = [base_path('storage')];

        $results = $this->check('sqlite', [base_path('storage'), '/elsewhere']);

        $this->assertSame(['Writable: storage'], $this->failed($results));
        $this->assertTrue($this->find($results, 'Writable: /elsewhere')->ok);
    }

    #[Test]
    public function exif_is_advice_not_a_condition(): void
    {
        $this->php->missingExtensions = ['exif'];

        $one = $this->find($this->check(), 'exif');

        $this->assertFalse($one->ok);
        $this->assertFalse($one->required);
        $this->assertSame([], $this->failed($this->check()));
    }

    #[Test]
    public function upload_limits_below_the_largest_download_are_advice_that_says_both_sizes(): void
    {
        $this->php->bytes = ['upload_max_filesize' => 2 * 1048576, 'post_max_size' => 8 * 1048576];

        $results = $this->check('sqlite', [], [], 100 * 1048576);

        $upload = $this->find($results, 'PHP upload_max_filesize');
        $post = $this->find($results, 'PHP post_max_size');

        $this->assertFalse($upload->ok);
        $this->assertFalse($upload->required);
        $this->assertStringContainsString('2 MB', $upload->detail);
        $this->assertStringContainsString('100 MB', $upload->detail);
        $this->assertFalse($post->ok);
        $this->assertSame([], $this->failed($results));
    }

    #[Test]
    public function an_unlimited_or_unreadable_limit_is_enough(): void
    {
        $this->php->bytes = ['upload_max_filesize' => -1, 'post_max_size' => null];

        $results = $this->check('sqlite', [], [], 100 * 1048576);

        $this->assertTrue($this->find($results, 'PHP upload_max_filesize')->ok);
        $this->assertTrue($this->find($results, 'PHP post_max_size')->ok);
    }

    #[Test]
    public function no_download_limit_means_no_upload_rows(): void
    {
        $names = array_map(fn (Requirement $one) => $one->name, $this->check());

        $this->assertNotContains('PHP upload_max_filesize', $names);
        $this->assertNotContains('PHP post_max_size', $names);
    }

    #[Test]
    public function only_a_required_row_that_is_not_ok_is_a_failure(): void
    {
        $failures = Requirements::failures([
            new Requirement('fine', true, true, ''),
            new Requirement('advice', false, false, ''),
            new Requirement('broken', false, true, ''),
        ]);

        $this->assertSame(['broken'], array_map(fn (Requirement $one) => $one->name, $failures));
    }

    #[Test]
    public function the_extension_list_is_the_one_the_readme_gives(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__, 3).'/README.md');

        preg_match('/^\s*- Required: (.+)$/m', $readme, $line);
        preg_match_all('/`([a-z]+)`/', (string) ($line[1] ?? ''), $names);

        $this->assertNotSame([], $names[1], 'The README has no "Required:" line of extensions under Prerequisites.');

        $listed = $names[1];
        $ours = Requirements::EXTENSIONS;
        sort($listed);
        sort($ours);

        $this->assertSame($ours, $listed, 'Requirements::EXTENSIONS and the README list of extensions have drifted apart.');
    }

    #[Test]
    public function the_minimum_php_is_the_one_composer_requires(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('^'.implode('.', array_slice(explode('.', Requirements::PHP_MINIMUM), 0, 2)), $composer['require']['php']);
    }

    #[Test]
    public function the_probe_reads_the_machine_it_runs_on(): void
    {
        $probe = new PhpProbe;

        $this->assertSame(PHP_VERSION, $probe->version());
        $this->assertTrue($probe->extensionLoaded('json'));
        $this->assertFalse($probe->extensionLoaded('no_such_extension'));
        $this->assertTrue($probe->writable(sys_get_temp_dir()));
        $this->assertFalse($probe->writable(sys_get_temp_dir().DIRECTORY_SEPARATOR.'metaforger-no-such-folder'));
        $this->assertNull($probe->bytes('no_such_setting'));
    }

    #[Test]
    public function the_probe_reads_a_size_setting_in_bytes(): void
    {
        $before = ini_get('memory_limit');

        try {
            ini_set('memory_limit', '256M');
            $this->assertSame(256 * 1048576, (new PhpProbe)->bytes('memory_limit'));

            ini_set('memory_limit', '-1');
            $this->assertSame(-1, (new PhpProbe)->bytes('memory_limit'));
        } finally {
            ini_set('memory_limit', (string) $before);
        }
    }
}
