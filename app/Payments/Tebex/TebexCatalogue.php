<?php

namespace App\Payments\Tebex;

use App\Models\TebexCategory;
use App\Models\TebexPackage;
use App\Models\TebexSync;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Keeps the shop's copy of a Tebex store's catalogue (categories and packages) up to date.
 *
 * Tebex owns the catalogue, so a run makes the copy match what Tebex lists: new rows are added, changed ones
 * updated, and rows Tebex no longer lists are stamped `removed_at` (never deleted: an order refers to the package it
 * was bought as). A row that comes back is unstamped. A run is all or nothing: everything is fetched first and applied
 * in one transaction, so a failure part way (Tebex down, an answer that makes no sense) leaves the copy as it was, and
 * the run is recorded as failed with the reason.
 *
 * Only one run happens at a time; a second one started meanwhile is refused.
 */
class TebexCatalogue
{
    private const LOCK = 'tebex:sync';

    /** Columns that hold what Tebex sent as it is: lists of objects. */
    private const JSON_COLUMNS = ['media', 'options', 'variables'];

    /** A run still marked as running after this long died without finishing. */
    private const STALE_AFTER_MINUTES = 10;

    public function __construct(
        private readonly TebexClient $client,
        private readonly TebexStoreProfile $profile,
    ) {}

    /**
     * Refresh the copy.
     *
     * @return TebexSync The run, finished: check its `status`.
     *
     * @throws TebexException When Tebex is not set up or another run is in progress (no run is recorded then).
     */
    public function sync(string $trigger = TebexSync::COMMAND, ?User $by = null): TebexSync
    {
        // With the Checkout API the catalogue is the shop's own: there is nothing of Tebex's to copy, and the copy is not used.
        if (TebexSelling::checkoutApi()) {
            throw new TebexException('The Tebex catalogue is not used while the Checkout API mode is on (TEBEX_CHECKOUT_API_ENABLED): the shop sells its own products.');
        }

        if (! $this->client->isConfigured()) {
            throw new TebexException('Tebex is not set up: add the store\'s public token (TEBEX_PUBLIC_TOKEN) to the environment.');
        }

        $lock = Cache::lock(self::LOCK, 300);

        if (! $lock->get()) {
            throw new TebexException('A synchronisation is already running. Try again in a minute.');
        }

        try {
            $this->closeStaleRuns();

            $run = TebexSync::query()->create([
                'trigger' => $trigger,
                'status' => TebexSync::RUNNING,
                'user_id' => $by?->id,
                'started_at' => now(),
            ]);

            try {
                $store = $this->client->webstore();
                $this->profile->remember($store);
                $categories = $this->client->categories();

                $counts = DB::transaction(fn () => $this->apply($categories, $store->currency));

                $run->forceFill(['status' => TebexSync::OK, 'finished_at' => now(), 'counts' => $counts])->save();
            } catch (TebexException $exception) {
                $run->forceFill(['status' => TebexSync::FAILED, 'finished_at' => now(), 'error' => mb_substr($exception->getMessage(), 0, 1000)])->save();
            } catch (Throwable $exception) {
                // Not something Tebex did: a bug or a database problem. The run says so without the details.
                report($exception);

                $run->forceFill(['status' => TebexSync::FAILED, 'finished_at' => now(), 'error' => 'The synchronisation failed unexpectedly; the details are in the application log.'])->save();
            }

            $this->prune();

            return $run->refresh();
        } finally {
            $lock->release();
        }
    }

    /**
     * Whether the scheduler should refresh the copy now: Tebex is set up, the schedule is on, and the last run (of
     * any kind, failed or not) started longer ago than the interval. A little early is fine: the scheduler looks every
     * five minutes, and a run that is two minutes short of due would otherwise wait for the next look.
     */
    public function isDue(): bool
    {
        $every = (int) config('tebex.sync.every_minutes', 0);

        if ($every <= 0 || TebexSelling::checkoutApi() || ! $this->client->isConfigured()) {
            return false;
        }

        $last = TebexSync::query()->latest('started_at')->first()?->started_at;

        return $last === null || $last->lte(now()->subMinutes(max(1, $every - 2)));
    }

    /**
     * Make the copy match Tebex's list.
     *
     * @param  list<array<string, mixed>>  $remote  The store's categories, each with its packages.
     * @return array<string, array<string, int>>
     *
     * @throws TebexException When an entry is not one the shop can use.
     */
    private function apply(array $remote, string $storeCurrency): array
    {
        $counts = [
            'categories' => ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'removed' => 0],
            'packages' => ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'removed' => 0],
        ];

        $categories = TebexCategory::query()->get()->keyBy('tebex_id');
        $packages = TebexPackage::query()->get()->keyBy('tebex_id');

        $seenCategories = [];
        $seenPackages = [];

        // Categories first, so that a package can point at its category whatever order Tebex lists them in.
        foreach ($remote as $data) {
            $id = $this->id($data, 'category');

            $category = $this->save($categories->get($id) ?? new TebexCategory, $this->categoryAttributes($id, $data), $counts['categories']);
            $categories->put($id, $category);
            $seenCategories[$id] = true;
        }

        foreach ($remote as $data) {
            $enclosing = $categories->get($this->id($data, 'category'));

            $listed = $data['packages'] ?? [];

            // Anything that is not a list of objects is refused, never skipped: a package left out here would be
            // taken for one Tebex no longer lists, and retired.
            if (! is_array($listed) || ! array_is_list($listed)) {
                throw new TebexException('Tebex sent a list of packages the shop could not understand, so nothing was changed.');
            }

            foreach ($listed as $package) {
                if (! is_array($package)) {
                    throw new TebexException('Tebex sent a package the shop could not understand (it is not an object), so nothing was changed.');
                }

                $id = $this->id($package, 'package');
                // Where Tebex says the package belongs, when that is a category it listed; otherwise where it was listed.
                $home = $categories->get((int) (($package['category']['id'] ?? 0))) ?? $enclosing;

                $saved = $this->save($packages->get($id) ?? new TebexPackage, $this->packageAttributes($id, $package, $home?->id, $storeCurrency), $counts['packages']);
                $packages->put($id, $saved);
                $seenPackages[$id] = true;
            }
        }

        $counts['categories']['removed'] = $this->retire($categories, $seenCategories);
        $counts['packages']['removed'] = $this->retire($packages, $seenPackages);

        return $counts;
    }

    /**
     * Save a row if it is new or changed, and count what happened.
     *
     * @template TModel of Model
     *
     * @param  TModel  $model
     * @param  array<string, mixed>  $attributes
     * @param  array<string, int>  $counts
     * @return TModel
     */
    private function save(Model $model, array $attributes, array &$counts): Model
    {
        $model->fill($attributes);

        if (! $model->exists) {
            $counts['created']++;
        } elseif ($this->changed($model)) {
            $counts['updated']++;
        } else {
            $counts['unchanged']++;

            return $model;
        }

        $model->save();

        return $model;
    }

    /**
     * Whether a saved row differs from what Tebex says.
     *
     * The JSON columns are compared without regard to the order of an object's keys: MySQL keeps them in an order of
     * its own (shortest first), so read back, they never equal what Tebex sent, and every package with media or
     * variables would count as changed, and be written, on every run.
     */
    private function changed(Model $model): bool
    {
        foreach (array_keys($model->getDirty()) as $key) {
            if (
                in_array($key, self::JSON_COLUMNS, true)
                && $this->canonical($model->getOriginal($key)) === $this->canonical($model->getAttribute($key))
            ) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * The same data with every object's keys in one order, so that two copies of it can be compared.
     */
    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(fn (mixed $item) => $this->canonical($item), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * Stamp the rows Tebex no longer lists. Returns how many were newly stamped.
     *
     * @template TModel of TebexCategory|TebexPackage
     *
     * @param  Collection<int|string, TModel>  $rows
     * @param  array<int, true>  $seen
     */
    private function retire(Collection $rows, array $seen): int
    {
        $gone = $rows->filter(fn (Model $row) => $row->removed_at === null && ! isset($seen[$row->tebex_id]));

        foreach ($gone as $row) {
            $row->forceFill(['removed_at' => now()])->save();
        }

        return $gone->count();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function categoryAttributes(int $id, array $data): array
    {
        $parent = is_array($data['parent'] ?? null) ? (int) ($data['parent']['id'] ?? 0) : 0;

        return [
            'tebex_id' => $id,
            'parent_tebex_id' => $parent > 0 ? $parent : null,
            'name' => $this->text($data['name'] ?? null, 255) ?? 'Category '.$id,
            'slug' => $this->text($data['slug'] ?? null, 255),
            'description' => $this->text($data['description'] ?? null),
            'sort_order' => max(0, (int) ($data['order'] ?? 0)),
            'display_type' => $this->text($data['display_type'] ?? null, 16) ?? 'list',
            'image_url' => $this->text($data['image_url'] ?? null),
            'is_dynamic' => (bool) ($data['dynamic'] ?? false),
            'is_tiered' => (bool) ($data['tiered'] ?? false),
            'removed_at' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function packageAttributes(int $id, array $data, ?int $categoryId, string $storeCurrency): array
    {
        $currency = strtoupper((string) ($data['currency'] ?? ''));

        return [
            'tebex_id' => $id,
            'ident' => $this->text($data['ident'] ?? null, 255),
            'tebex_category_id' => $categoryId,
            'name' => $this->text($data['name'] ?? null, 255) ?? 'Package '.$id,
            'slug' => $this->text($data['slug'] ?? null, 255),
            'description' => $this->text($data['description'] ?? null),
            'image_url' => $this->text($data['image'] ?? null),
            'type' => ($data['type'] ?? null) === TebexPackage::SUBSCRIPTION ? TebexPackage::SUBSCRIPTION : TebexPackage::SINGLE,
            'base_price' => $this->decimal($data['base_price'] ?? 0),
            'sales_tax' => $this->decimal($data['sales_tax'] ?? 0),
            'total_price' => $this->decimal($data['total_price'] ?? ($data['base_price'] ?? 0)),
            'currency' => strlen($currency) === 3 ? $currency : $storeCurrency,
            'prorate_price' => isset($data['prorate_price']) ? $this->decimal($data['prorate_price']) : null,
            'discount' => $this->decimal($data['discount'] ?? 0),
            'disable_quantity' => (bool) ($data['disable_quantity'] ?? false),
            'disable_gifting' => (bool) ($data['disable_gifting'] ?? false),
            'expires_at' => $this->date($data['expiration_date'] ?? null),
            'sort_order' => max(0, (int) ($data['order'] ?? 0)),
            'user_limit' => max(0, (int) ($data['user_limit'] ?? 0)),
            'media' => $this->list($data['media'] ?? null),
            'options' => $this->list($data['options'] ?? null),
            'variables' => $this->list($data['variables'] ?? null),
            'removed_at' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws TebexException
     */
    private function id(array $data, string $what): int
    {
        $id = $data['id'] ?? null;

        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            throw new TebexException("Tebex sent a {$what} the shop could not understand (it has no id), so nothing was changed.");
        }

        return (int) $id;
    }

    private function text(mixed $value, ?int $limit = null): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $limit === null ? $value : mb_substr($value, 0, $limit);
    }

    /**
     * Tebex sends prices as floats; the database keeps two decimals.
     */
    private function decimal(mixed $value): string
    {
        return number_format(round((float) $value, 2), 2, '.', '');
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<mixed>|null
     */
    private function list(mixed $value): ?array
    {
        return is_array($value) && $value !== [] ? array_values($value) : null;
    }

    /**
     * A run left as "running" by a process that died would otherwise show as running for ever.
     */
    private function closeStaleRuns(): void
    {
        TebexSync::query()
            ->where('status', TebexSync::RUNNING)
            ->where('started_at', '<', now()->subMinutes(self::STALE_AFTER_MINUTES))
            ->update([
                'status' => TebexSync::FAILED,
                'finished_at' => now(),
                'error' => 'The synchronisation stopped before it finished.',
            ]);
    }

    private function prune(): void
    {
        $keep = max(1, (int) config('tebex.sync.keep_runs', 50));
        $oldestToKeep = TebexSync::query()->orderByDesc('id')->skip($keep - 1)->value('id');

        if ($oldestToKeep !== null) {
            TebexSync::query()->where('id', '<', $oldestToKeep)->delete();
        }
    }
}
