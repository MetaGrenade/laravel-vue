<?php

namespace App\Support\Install;

/**
 * What the PHP that runs the installer says about itself. A class of its own so a test can describe a machine that is not this one.
 */
class PhpProbe
{
    public function version(): string
    {
        return PHP_VERSION;
    }

    public function extensionLoaded(string $name): bool
    {
        return extension_loaded($name);
    }

    /**
     * Whether GD can write WebP, which product pictures and blog covers are saved as.
     */
    public function webp(): bool
    {
        if (! extension_loaded('gd')) {
            return false;
        }

        $info = gd_info();

        return ($info['WebP Support'] ?? false) === true;
    }

    /**
     * An ini setting in bytes, or null when PHP has none or it is not a size.
     */
    public function bytes(string $setting): ?int
    {
        $value = ini_get($setting);

        if ($value === false || $value === '') {
            return null;
        }

        $bytes = ini_parse_quantity($value);

        // -1 is "no limit"; 0 is what a size PHP cannot read comes out as.
        return $bytes === 0 && $value !== '0' ? null : $bytes;
    }

    /**
     * Whether the memory GD takes for a picture counts against `memory_limit`. It does when GD is the one that comes with
     * PHP, which asks PHP for its memory. It does not when GD is the system's library (the packages of Debian and Ubuntu):
     * that asks the system, PHP never sees the memory a picture takes, and `memory_limit` says nothing about it. Found out
     * by making a picture of 4 MB and seeing whether PHP's own count moves, because nothing else tells the two apart.
     */
    public function gdCountsAgainstMemoryLimit(): bool
    {
        if (! extension_loaded('gd')) {
            return false;
        }

        $before = memory_get_usage();
        $probe = imagecreatetruecolor(1024, 1024);
        $counted = memory_get_usage() - $before >= 3 * 1048576;

        unset($probe);

        return $counted;
    }

    /**
     * The memory this PHP process has been given by the system so far, which is what memory_limit is held against.
     */
    public function memoryInUse(): int
    {
        return memory_get_usage(true);
    }

    public function writable(string $path): bool
    {
        return is_dir($path) ? is_writable($path) : (is_file($path) && is_writable($path));
    }
}
