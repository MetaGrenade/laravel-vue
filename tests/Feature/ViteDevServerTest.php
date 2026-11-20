<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `npm run dev` watches the whole project folder. The test suite writes files into storage/framework/testing/ (a data export zip is
 * made under a temporary name, then renamed) and the app writes logs, compiled views and uploads into storage/ as it runs. Nothing
 * Vite builds comes from there, and on Windows a file PHP still has open cannot be watched: the dev server printed one
 * "file watcher error: EBUSY" line for every file a test made. The watch list must keep ignoring storage/ as a whole.
 */
class ViteDevServerTest extends TestCase
{
    #[Test]
    public function the_dev_server_does_not_watch_storage(): void
    {
        $ignored = $this->ignoredByTheWatcher();

        $this->assertContains(
            '**/storage/**',
            $ignored,
            'vite.config.ts must keep `**/storage/**` in server.watch.ignored: the files tests and the app write there are not sources, and watching them prints "file watcher error: EBUSY" on Windows.',
        );
    }

    #[Test]
    public function the_watch_list_still_skips_what_it_skipped_before(): void
    {
        $ignored = $this->ignoredByTheWatcher();

        foreach (['**/vendor/**', '**/.claude/**'] as $pattern) {
            $this->assertContains($pattern, $ignored, "vite.config.ts no longer ignores `$pattern` in server.watch.");
        }
    }

    /** @return list<string> the patterns of `server.watch.ignored` in vite.config.ts */
    private function ignoredByTheWatcher(): array
    {
        $config = file_get_contents(base_path('vite.config.ts'));
        $this->assertIsString($config);

        $found = preg_match('/watch:\s*\{.*?ignored:\s*\[(?<list>[^\]]*)\]/s', $config, $matches);
        $this->assertSame(1, $found, 'vite.config.ts has no server.watch.ignored list.');

        preg_match_all('/\'([^\']+)\'/', $matches['list'], $patterns);

        return $patterns[1];
    }
}
