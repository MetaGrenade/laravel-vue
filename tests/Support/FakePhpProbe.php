<?php

namespace Tests\Support;

use App\Support\Install\PhpProbe;
use App\Support\Install\Requirements;

/**
 * A machine as a test describes it. It starts as one that has everything the installer asks for; a test takes things away.
 */
class FakePhpProbe extends PhpProbe
{
    public string $version = '8.4.1';

    /** @var list<string> */
    public array $missingExtensions = [];

    /** @var list<string> Extensions the machine has beyond the required ones. */
    public array $extraExtensions = ['redis', 'exif', 'pdo_sqlite', 'pdo_mysql', 'pdo_pgsql'];

    public bool $webp = true;

    /** @var array<string, int|null> */
    public array $bytes = ['upload_max_filesize' => 104857600, 'post_max_size' => 104857600];

    /** @var int What this process has been given so far, which `memory_limit` is held against. */
    public int $memoryInUse = 0;

    /** @var list<string> */
    public array $unwritable = [];

    public function version(): string
    {
        return $this->version;
    }

    public function extensionLoaded(string $name): bool
    {
        if (in_array($name, $this->missingExtensions, true)) {
            return false;
        }

        return in_array($name, [...Requirements::EXTENSIONS, ...$this->extraExtensions], true);
    }

    public function webp(): bool
    {
        return $this->webp;
    }

    public function bytes(string $setting): ?int
    {
        return $this->bytes[$setting] ?? null;
    }

    public function memoryInUse(): int
    {
        return $this->memoryInUse;
    }

    public function writable(string $path): bool
    {
        return ! in_array($path, $this->unwritable, true);
    }
}
