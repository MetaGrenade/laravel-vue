<?php

namespace Tests\Feature\Frontend;

use App\Support\Frontend\StaffAreaVite;
use Illuminate\Foundation\Vite;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Vite's prefetch lists every page the front end has and everything they import, with no way to leave pages out. `StaffAreaVite` reads
 * the manifest without the staff area's pages for a member who is not staff (app/Support/Frontend/StaffAreaVite.php). These run it on a
 * small manifest made here, so they hold the rule in place whether or not the front end has been built.
 */
class StaffAreaViteTest extends TestCase
{
    private string $publicPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publicPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'staff-area-vite-'.bin2hex(random_bytes(4));
        mkdir($this->publicPath.DIRECTORY_SEPARATOR.'build', 0777, true);
        file_put_contents($this->publicPath.DIRECTORY_SEPARATOR.'build'.DIRECTORY_SEPARATOR.'manifest.json', json_encode($this->manifest()));

        // Vite keeps a manifest by its path for the life of the process, so each test's own folder is a manifest of its own.
        $this->app->usePublicPath($this->publicPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->publicPath.DIRECTORY_SEPARATOR.'build'.DIRECTORY_SEPARATOR.'manifest.json');
        @rmdir($this->publicPath.DIRECTORY_SEPARATOR.'build');
        @rmdir($this->publicPath);

        parent::tearDown();
    }

    /**
     * Three pages and what they import: a page of the site, a page of the staff area with a file of its own, and the one page in the
     * staff area's folder that a member can open. Both pages of the staff area import a file the site's page does too.
     *
     * @return array<string, array<string, mixed>>
     */
    private function manifest(): array
    {
        return [
            'resources/js/app.ts' => [
                'file' => 'assets/app-aaa.js',
                'src' => 'resources/js/app.ts',
                'isEntry' => true,
                'dynamicImports' => [
                    'resources/js/pages/Blog.vue',
                    'resources/js/pages/acp/Users.vue',
                    'resources/js/pages/acp/ForumPostHistory.vue',
                ],
            ],
            'resources/js/pages/Blog.vue' => [
                'file' => 'assets/Blog-bbb.js',
                'src' => 'resources/js/pages/Blog.vue',
                'isDynamicEntry' => true,
                'imports' => ['resources/js/app.ts', '_shared-ccc.js'],
            ],
            'resources/js/pages/acp/Users.vue' => [
                'file' => 'assets/Users-ddd.js',
                'src' => 'resources/js/pages/acp/Users.vue',
                'isDynamicEntry' => true,
                'imports' => ['resources/js/app.ts', '_shared-ccc.js', '_staffonly-eee.js'],
            ],
            'resources/js/pages/acp/ForumPostHistory.vue' => [
                'file' => 'assets/ForumPostHistory-fff.js',
                'src' => 'resources/js/pages/acp/ForumPostHistory.vue',
                'isDynamicEntry' => true,
                'imports' => ['resources/js/app.ts', '_shared-ccc.js'],
            ],
            '_shared-ccc.js' => ['file' => 'assets/shared-ccc.js'],
            '_staffonly-eee.js' => ['file' => 'assets/staffonly-eee.js'],
        ];
    }

    /** What `@vite` writes for the page that loads the front end, with the files to fetch in the background. */
    private function pageAssets(bool $withoutStaffArea): string
    {
        $vite = $this->app->make(Vite::class);
        $this->assertInstanceOf(StaffAreaVite::class, $vite);

        return (string) $vite->prefetch(concurrency: 3)->withoutStaffAreaPages($withoutStaffArea)->__invoke(['resources/js/app.ts']);
    }

    #[Test]
    public function staff_are_sent_every_page(): void
    {
        $html = $this->pageAssets(withoutStaffArea: false);

        foreach (['Blog-bbb.js', 'Users-ddd.js', 'ForumPostHistory-fff.js', 'shared-ccc.js', 'staffonly-eee.js'] as $file) {
            $this->assertStringContainsString($file, $html, "{$file} is not fetched for staff");
        }
    }

    #[Test]
    public function a_member_is_not_sent_the_staff_areas_pages_or_what_only_they_import(): void
    {
        $html = $this->pageAssets(withoutStaffArea: true);

        $this->assertStringNotContainsString('Users-ddd.js', $html);
        $this->assertStringNotContainsString('staffonly-eee.js', $html);
    }

    #[Test]
    public function a_member_is_still_sent_the_rest_including_what_a_page_of_the_site_shares_with_the_staff_area(): void
    {
        $html = $this->pageAssets(withoutStaffArea: true);

        $this->assertStringContainsString('Blog-bbb.js', $html);
        $this->assertStringContainsString('shared-ccc.js', $html);
    }

    #[Test]
    public function a_member_is_sent_the_page_in_the_staff_areas_folder_that_a_member_can_open(): void
    {
        $this->assertStringContainsString('ForumPostHistory-fff.js', $this->pageAssets(withoutStaffArea: true));
    }

    #[Test]
    public function the_pages_stay_in_the_manifest_so_one_asked_for_by_name_is_still_found(): void
    {
        $vite = $this->app->make(Vite::class)->withoutStaffAreaPages();

        $html = (string) $vite->__invoke(['resources/js/app.ts', 'resources/js/pages/acp/Users.vue']);

        $this->assertStringContainsString('Users-ddd.js', $html);
    }

    #[Test]
    public function what_one_request_asked_for_is_not_left_for_the_next(): void
    {
        $this->pageAssets(withoutStaffArea: true);

        // The manifest the parent keeps is shared by the whole process: a copy was filtered, not that one.
        $this->assertStringContainsString('Users-ddd.js', $this->pageAssets(withoutStaffArea: false));
    }

    #[Test]
    public function every_page_in_the_staff_areas_folder_that_a_controller_outside_the_admin_area_renders_is_listed_as_open_to_members(): void
    {
        $rendered = [];

        foreach ((new Finder)->files()->in(base_path('app'))->name('*.php')->notPath('Http/Controllers/Admin') as $file) {
            preg_match_all("/(?:Inertia::render|inertia)\(\s*'acp\/(\w+)'/", $file->getContents(), $pages);

            array_push($rendered, ...$pages[1]);
        }

        $rendered = array_values(array_unique($rendered));
        sort($rendered);
        $listed = StaffAreaVite::OPEN_TO_MEMBERS;
        sort($listed);

        $this->assertSame(
            $listed,
            $rendered,
            'A page in resources/js/pages/acp that a controller outside Http/Controllers/Admin renders is one a member who is not staff can '
            .'open, so it belongs in StaffAreaVite::OPEN_TO_MEMBERS; and a name listed there must still be rendered by such a controller.',
        );
    }
}
