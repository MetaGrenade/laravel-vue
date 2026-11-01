<?php

namespace App\Console\Commands;

use App\Models\Blog;
use App\Models\BlogRevision;
use App\Support\Blog\BlogCovers;
use App\Support\Commerce\Catalogue\ImageRejected;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class UpgradeBlogCoversCommand extends Command
{
    protected $signature = 'metaforge:blog:covers:upgrade {--dry-run : Report which covers would be drawn again without changing anything}';

    protected $description = 'Draw blog covers that are stored as one file again at every size (docs/images.md)';

    private const REDRAWN = 'redrawn';

    private const MISSING = 'file missing';

    private const REFUSED = 'not accepted as a picture';

    private const CHANGED = 'changed while running';

    public function handle(BlogCovers $covers): int
    {
        $paths = Blog::query()->toBase()->whereNotNull('cover_image')->distinct()->pluck('cover_image')
            ->merge(BlogRevision::query()->toBase()->whereNotNull('cover_image')->distinct()->pluck('cover_image'))
            ->filter(fn ($path) => is_string($path) && $path !== '' && ! $covers->hasSizes($path))
            ->unique()
            ->values();

        $this->info(sprintf('%d cover(s) are stored as one file.', $paths->count()));

        $counts = [self::REDRAWN => 0, self::MISSING => 0, self::REFUSED => 0, self::CHANGED => 0];

        foreach ($paths as $path) {
            $counts[$this->upgrade($covers, $path)]++;
        }

        $this->table(['Result', 'Count'], collect($counts)->map(fn ($count, $result) => [$result, $count])->values()->all());

        if ($counts[self::MISSING] > 0) {
            $this->warn('Some covers had no file on disk (often one a post used before its cover was replaced, kept by an old revision). Their rows were left as they are.');
        }

        if ($counts[self::REFUSED] > 0) {
            $this->warn('Some files are not pictures the site accepts (too many pixels, or damaged). They were left as they are and are still shown at their one size.');
        }

        return self::SUCCESS;
    }

    private function upgrade(BlogCovers $covers, string $path): string
    {
        if ($this->option('dry-run')) {
            $this->line(($exists = Storage::disk('public')->exists($path)) ? "would redraw $path" : "no file for $path");

            return $exists ? self::REDRAWN : self::MISSING;
        }

        try {
            $upgraded = $covers->upgrade($path);
        } catch (ImageRejected $exception) {
            $this->line("$path: {$exception->getMessage()}");

            return self::REFUSED;
        }

        if ($upgraded === null) {
            $this->line("no file for $path");

            return self::MISSING;
        }

        // The posts and the revisions that kept the old path now keep the new one, and the old file goes
        // once nothing points at it. A cover someone replaced meanwhile matches no row: what was just
        // drawn is then nobody's, so it is removed again.
        $rows = DB::transaction(fn () => Blog::query()->toBase()->where('cover_image', $path)->update(['cover_image' => $upgraded])
            + BlogRevision::query()->toBase()->where('cover_image', $path)->update(['cover_image' => $upgraded]));

        if ($rows === 0) {
            $covers->delete($upgraded);

            return self::CHANGED;
        }

        $covers->delete($path);

        return self::REDRAWN;
    }
}
