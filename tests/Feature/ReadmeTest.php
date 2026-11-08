<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The README is the front page: what the project is, how to start it, and where everything else is written down. An explanation that
 * grows inside it becomes a second copy of a page in docs/ that drifts from the first, and a front page that takes minutes to read
 * is one nobody reads. Explanations go in docs/ (listed in docs/README.md, which DocsIndexTest keeps complete); the README links.
 */
class ReadmeTest extends TestCase
{
    /** The README is 110 lines today, about 40 of them the section banners. The room above that is for a new link, not for a new section of text. */
    private const MAX_LINES = 140;

    #[Test]
    public function the_readme_stays_a_front_page(): void
    {
        $lines = substr_count($this->readme(), "\n") + 1;

        $this->assertLessThanOrEqual(
            self::MAX_LINES,
            $lines,
            "README.md is $lines lines long; the most it may be is ".self::MAX_LINES.'. Put the explanation in a page of docs/, list that page in docs/README.md, and link to it from the README.',
        );
    }

    #[Test]
    public function the_readme_starts_a_site_and_points_to_the_guides(): void
    {
        $readme = $this->readme();

        foreach (['php artisan metaforger:install', 'npm run build', 'composer dev'] as $command) {
            $this->assertStringContainsString($command, $readme, "The README's quick start no longer has `$command`.");
        }

        $links = [
            'docs/README.md' => 'the index of every guide',
            'docs/install.md' => 'installing',
            'docs/development.md' => 'working on it on your own machine',
            'docs/deployment.md' => 'putting a site on a server',
            'docs/docker.md' => 'running it in Docker',
            'docs/upgrading.md' => 'moving a running site to a newer version',
            'docs/configuration.md' => 'every setting',
            'CONTRIBUTING.md' => 'contributing',
            'SECURITY.md' => 'reporting a vulnerability',
            'LICENSE.md' => 'the licence',
        ];

        foreach ($links as $target => $what) {
            $this->assertStringContainsString("]($target", $readme, "The README does not link to $target ($what).");
        }
    }

    private function readme(): string
    {
        return (string) file_get_contents(base_path('README.md'));
    }
}
