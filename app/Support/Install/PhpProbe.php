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
