<?php

namespace Tests\Feature;

use DirectoryIterator;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * The project is called MetaForger (metaforger.dev). It was called MetaForge until the domain for that name could not be had, and a
 * name left behind in a command, a document or a test is the kind of mistake that only shows when somebody follows it.
 */
class BrandNameTest extends TestCase
{
    /**
     * Names that belong to the Discord companion, which is a separate repository and package under its own name until it is renamed there.
     * They are taken out of a line before it is checked.
     */
    private const DISCORD_COMPANION = [
        'metagrenade/metaforge-discord',
        'MetaForge-DiscordBot',
        'MetaForge\\\\Discord',
        'MetaForge\\Discord',
        'METAFORGE_WEBHOOK_URL',
    ];

    #[Test]
    public function nothing_in_the_project_still_uses_the_old_name(): void
    {
        $found = [];

        foreach ($this->textFiles() as $path) {
            foreach (file($path) ?: [] as $number => $line) {
                if (preg_match('/metaforge(?!r)/i', str_ireplace(self::DISCORD_COMPANION, '', $line)) === 1) {
                    $found[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path).':'.($number + 1);
                }
            }
        }

        $this->assertSame([], $found, 'These still say MetaForge instead of MetaForger: '.implode(', ', $found));
    }

    #[Test]
    public function the_commands_of_the_project_are_named_after_it(): void
    {
        $names = array_keys(Artisan::all());

        $this->assertContains('metaforger:install', $names);
        $this->assertSame([], array_values(array_filter($names, fn (string $name): bool => str_starts_with($name, 'metaforge:'))));
    }

    /**
     * What the project wrote itself: its code, documents, configuration and tests. Not the changelog (it records the rename), and not this
     * file (it has to spell the old name), nor what is installed or built.
     *
     * @return list<string>
     */
    private function textFiles(): array
    {
        $files = [];

        foreach (['app', 'bootstrap', 'config', 'database', 'docs', 'resources', 'routes', 'scripts', 'tests', '.github'] as $directory) {
            if (is_dir(base_path($directory))) {
                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory), RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                    $files[] = $file;
                }
            }
        }

        foreach (new DirectoryIterator(base_path()) as $file) {
            if ($file->isFile()) {
                $files[] = new SplFileInfo($file->getPathname());
            }
        }

        // Written by a build or a test run, not by the project: the server-side bundle in bootstrap/ssr is whatever was built last.
        $generated = array_map(
            fn (string $path): string => DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path).DIRECTORY_SEPARATOR,
            ['node_modules', 'bootstrap/ssr', 'bootstrap/cache', 'test-results', 'playwright-report'],
        );

        $paths = [];

        foreach ($files as $file) {
            $path = $file->getPathname();

            if (in_array($file->getExtension(), ['php', 'js', 'mjs', 'cjs', 'ts', 'vue', 'md', 'json', 'yml', 'yaml', 'html', 'css', 'txt', 'xml', 'example'], true)
                && ! in_array($file->getFilename(), ['CHANGELOG.md', 'composer.lock', 'package-lock.json', basename(__FILE__)], true)
                && ! array_filter($generated, fn (string $fragment): bool => str_contains($path, $fragment))) {
                $paths[] = $path;
            }
        }

        sort($paths);

        return $paths;
    }
}
