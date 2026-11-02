<?php

namespace App\Support\Install;

/**
 * What the machine must have before MetaForger can be installed on it (README, Prerequisites; docs/install.md). It only looks: nothing is
 * written, so the installer can run it before it touches a file or a database.
 */
class Requirements
{
    /** Keep in step with `require.php` in composer.json: a test checks it. */
    public const PHP_MINIMUM = '8.4.0';

    /**
     * Keep in step with the list in the README: a test checks it.
     *
     * @var list<string>
     */
    public const EXTENSIONS = [
        'bcmath', 'ctype', 'curl', 'dom', 'fileinfo', 'filter', 'iconv', 'json', 'libxml',
        'mbstring', 'openssl', 'session', 'tokenizer', 'xml', 'xmlwriter', 'zip',
    ];

    /** Database driver to the PDO extension it needs. */
    private const PDO = [
        'sqlite' => 'pdo_sqlite',
        'mysql' => 'pdo_mysql',
        'mariadb' => 'pdo_mysql',
        'pgsql' => 'pdo_pgsql',
    ];

    public function __construct(private readonly PhpProbe $php = new PhpProbe) {}

    /**
     * @param  string  $driver  The database driver in use (`config('database.connections.x.driver')`).
     * @param  list<string>  $writable  Folders the application writes to.
     * @param  list<string>  $redisFor  What is set to use Redis (`cache`, `queue`, `session`), which needs the extension.
     * @param  int  $uploadBytes  The largest file staff may upload (the digital download limit).
     * @return list<Requirement>
     */
    public function check(string $driver, array $writable, array $redisFor = [], int $uploadBytes = 0): array
    {
        $results = [];

        $results[] = new Requirement(
            'PHP '.self::PHP_MINIMUM.' or newer',
            version_compare($this->php->version(), self::PHP_MINIMUM, '>='),
            true,
            'this is PHP '.$this->php->version(),
        );

        $missing = array_values(array_filter(self::EXTENSIONS, fn (string $name) => ! $this->php->extensionLoaded($name)));

        $results[] = new Requirement(
            'PHP extensions',
            $missing === [],
            true,
            $missing === [] ? 'all '.count(self::EXTENSIONS).' are loaded' : 'missing: '.implode(', ', $missing),
        );

        $pdo = self::PDO[$driver] ?? null;

        $results[] = $pdo === null
            ? new Requirement("Database driver ({$driver})", true, true, 'not one the installer knows, so not checked')
            : new Requirement("Database driver ({$driver})", $this->php->extensionLoaded($pdo), true, $pdo.($this->php->extensionLoaded($pdo) ? ' is loaded' : ' is missing'));

        $results[] = new Requirement(
            'GD with WebP',
            $this->php->webp(),
            true,
            $this->php->webp() ? 'product pictures and blog covers can be saved' : 'product pictures and blog covers cannot be saved without it',
        );

        foreach (array_unique($redisFor) as $use) {
            $results[] = new Requirement(
                "Redis for the {$use}",
                $this->php->extensionLoaded('redis'),
                true,
                $this->php->extensionLoaded('redis') ? 'redis is loaded' : 'the redis extension (phpredis) is missing',
            );
        }

        foreach ($writable as $path) {
            $results[] = new Requirement(
                'Writable: '.$this->shorten($path),
                $this->php->writable($path),
                true,
                $this->php->writable($path) ? 'yes' : 'the web server and the queue worker must be able to write here',
            );
        }

        $results[] = new Requirement(
            'exif',
            $this->php->extensionLoaded('exif'),
            false,
            $this->php->extensionLoaded('exif') ? 'photos taken sideways are turned upright' : 'without it a photo taken sideways stays sideways',
        );

        if ($uploadBytes > 0) {
            foreach (['upload_max_filesize', 'post_max_size'] as $setting) {
                $limit = $this->php->bytes($setting);
                $enough = $limit === null || $limit < 0 || $limit >= $uploadBytes;

                $results[] = new Requirement(
                    "PHP {$setting}",
                    $enough,
                    false,
                    $enough ? 'allows the largest download staff may upload' : 'is '.$this->megabytes((int) $limit)." in the command line PHP (the web server's php.ini can differ), below the ".$this->megabytes($uploadBytes).' that staff may upload as a download',
                );
            }
        }

        return $results;
    }

    /**
     * @param  list<Requirement>  $results
     * @return list<Requirement>
     */
    public static function failures(array $results): array
    {
        return array_values(array_filter($results, fn (Requirement $one) => $one->required && ! $one->ok));
    }

    private function megabytes(int $bytes): string
    {
        return round($bytes / 1048576, 1).' MB';
    }

    private function shorten(string $path): string
    {
        $base = str_replace('\\', '/', base_path()).'/';
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
