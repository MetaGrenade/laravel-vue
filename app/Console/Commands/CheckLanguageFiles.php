<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;

/**
 * Fails when a language is missing keys that the reference language has.
 * Run in CI so a translation can never silently fall behind.
 */
class CheckLanguageFiles extends Command
{
    protected $signature = 'lang:check
        {--reference= : Language to compare against (default: the fallback locale)}
        {--path= : Language directory (default: lang/)}';

    protected $description = 'Check that every language has all the translation keys of the reference language';

    public function handle(): int
    {
        $path = (string) ($this->option('path') ?: lang_path());
        $reference = (string) ($this->option('reference') ?: config('app.fallback_locale', 'en'));

        if (! is_dir("{$path}/{$reference}")) {
            $this->error("Reference language directory not found: {$path}/{$reference}");

            return self::FAILURE;
        }

        $expected = $this->keys("{$path}/{$reference}");
        $problems = 0;

        foreach (File::directories($path) as $directory) {
            $locale = basename($directory);

            if ($locale === $reference) {
                continue;
            }

            $actual = $this->keys($directory);
            $missing = array_values(array_diff($expected, $actual));
            $extra = array_values(array_diff($actual, $expected));

            foreach ($missing as $key) {
                $this->line("<fg=red>[{$locale}] missing</> {$key}");
            }

            foreach ($extra as $key) {
                $this->line("<fg=yellow>[{$locale}] not in {$reference}</> {$key}");
            }

            $problems += count($missing);
        }

        if ($problems > 0) {
            $this->error("{$problems} translation key(s) are missing.");

            return self::FAILURE;
        }

        $this->info('All languages have every key of '.$reference.' ('.count($expected).' keys).');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function keys(string $directory): array
    {
        $keys = [];

        foreach (File::files($directory) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $group = $file->getFilenameWithoutExtension();
            $lines = require $file->getPathname();

            foreach (array_keys(Arr::dot((array) $lines)) as $key) {
                $keys[] = "{$group}.{$key}";
            }
        }

        sort($keys);

        return $keys;
    }
}
