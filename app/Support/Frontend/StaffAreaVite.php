<?php

namespace App\Support\Frontend;

use Illuminate\Foundation\Vite;

/**
 * Laravel's Vite, which can work from a build manifest without the pages of the staff area.
 *
 * `Vite::prefetch` fetches every page the front end has once a page has drawn: it takes the pages from what `resources/js/app.ts` can
 * load, and everything those pages import. It has no way to leave pages out, and the staff area is 85 of the 137 pages and about 30 %
 * of what is fetched (docs/bundle-budget.md). A member who is not staff is refused those pages, so for them the manifest is read without
 * them, and the files that only they need are never listed. The pages themselves stay in the manifest: a page that is asked for by name
 * is still found, and still loads when it is opened.
 *
 * Bound as `scoped` (AppServiceProvider), because what `withoutStaffAreaPages` sets belongs to one request
 * (`PrefetchAssetsForMembers` sets it on every web request, docs/octane.md).
 */
class StaffAreaVite extends Vite
{
    /** The entry that loads the pages: the pages are its dynamic imports in the manifest. */
    private const ENTRY = 'resources/js/app.ts';

    /** Where the pages of the staff area are, as the manifest names them. */
    private const STAFF_AREA = 'resources/js/pages/acp/';

    /**
     * Pages in that folder that a member who is not staff can open: the history of their own forum post. The folder is where the
     * staff area's pages are, not a promise that nobody else is sent one; `PrefetchAssetsTest` fails when a controller outside
     * `Admin` renders a page there and it is not listed.
     *
     * @var list<string>
     */
    public const OPEN_TO_MEMBERS = ['ForumPostHistory'];

    private bool $withoutStaffAreaPages = false;

    /**
     * Read the manifest without the staff area's pages (true), or with them (false, the default).
     */
    public function withoutStaffAreaPages(bool $without = true): static
    {
        $this->withoutStaffAreaPages = $without;

        return $this;
    }

    /**
     * @param  string  $buildDirectory
     * @return array<string, array<string, mixed>>
     */
    protected function manifest($buildDirectory)
    {
        $manifest = parent::manifest($buildDirectory);

        if (! $this->withoutStaffAreaPages || ! isset($manifest[self::ENTRY]['dynamicImports'])) {
            return $manifest;
        }

        // A copy: the parent keeps the manifest it read for as long as the process lives, and the next request may be staff.
        $manifest[self::ENTRY]['dynamicImports'] = array_values(array_filter(
            $manifest[self::ENTRY]['dynamicImports'],
            fn (string $page): bool => ! $this->isStaffAreaPage($page),
        ));

        return $manifest;
    }

    private function isStaffAreaPage(string $page): bool
    {
        if (! str_starts_with($page, self::STAFF_AREA)) {
            return false;
        }

        return ! in_array(basename($page, '.vue'), self::OPEN_TO_MEMBERS, true);
    }
}
