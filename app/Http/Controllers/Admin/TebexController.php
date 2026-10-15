<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Http\Controllers\Controller;
use App\Models\TebexCategory;
use App\Models\TebexPackage;
use App\Models\TebexSync;
use App\Payments\Tebex\TebexCatalogue;
use App\Payments\Tebex\TebexClient;
use App\Payments\Tebex\TebexException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tebex in the ACP: whether the connection works, the shop's copy of the Tebex catalogue and how fresh it is.
 *
 * The catalogue is read-only here, because Tebex owns it: packages and prices are changed in the Tebex panel and
 * arrive with the next synchronisation. Nothing on this page, or in anything it sends the browser, contains the private
 * key or the webhook secret; the public token is shown only in part.
 */
class TebexController extends Controller
{
    use InteractsWithInertiaPagination;

    /** What the package list can be narrowed to. */
    private const STATUSES = ['listed', 'removed', 'all'];

    public function __construct(
        private readonly TebexClient $client,
        private readonly TebexCatalogue $catalogue,
    ) {}

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', 'listed');
        $status = in_array($status, self::STATUSES, true) ? $status : 'listed';
        $category = $request->query('category');
        $category = is_numeric($category) ? (int) $category : null;

        $paginator = TebexPackage::query()
            ->with('category:id,name')
            ->when($status === 'listed', fn ($query) => $query->whereNull('removed_at'))
            ->when($status === 'removed', fn ($query) => $query->whereNotNull('removed_at'))
            ->when($category !== null, fn ($query) => $query->where('tebex_category_id', $category))
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                $query->whereLike('name', "%{$search}%")
                    ->orWhereLike('ident', "%{$search}%")
                    ->orWhereLike('slug', "%{$search}%");
            }))
            ->orderBy('tebex_category_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        $latest = TebexSync::query()->latest('started_at')->latest('id')->first();
        $lastOk = $latest?->status === TebexSync::OK ? $latest : $this->lastOk();
        $currencies = TebexPackage::query()->listed()->distinct()->orderBy('currency')->pluck('currency')->all();

        return Inertia::render('acp/CommerceTebex', [
            'connection' => [
                'configured' => $this->client->isConfigured(),
                'token' => $this->maskedToken(),
                'has_private_key' => filled(config('tebex.private_key')),
            ],
            // The answer to "Test connection", for the request that asked.
            'test' => $request->session()->get('tebex_test'),
            'sync' => [
                'latest' => $latest ? $this->run($latest) : null,
                'last_ok' => $lastOk ? $this->run($lastOk) : null,
                'every_minutes' => (int) config('tebex.sync.every_minutes', 0),
                'runs' => TebexSync::query()->with('user:id,nickname')->latest('started_at')->latest('id')->limit(10)->get()
                    ->map(fn (TebexSync $run) => $this->run($run))->values()->all(),
            ],
            'counts' => [
                'categories' => TebexCategory::query()->listed()->count(),
                'packages' => TebexPackage::query()->listed()->count(),
                'subscriptions' => TebexPackage::query()->listed()->where('type', TebexPackage::SUBSCRIPTION)->count(),
                'removed' => TebexPackage::query()->whereNotNull('removed_at')->count(),
            ],
            'categories' => TebexCategory::query()->listed()->orderBy('sort_order')->orderBy('id')->get(['id', 'name'])
                ->map(fn (TebexCategory $row) => ['id' => $row->id, 'name' => $row->name])->values()->all(),
            'packages' => [
                'data' => $paginator->getCollection()->map(fn (TebexPackage $package) => [
                    'id' => $package->id,
                    'tebex_id' => $package->tebex_id,
                    'ident' => $package->ident,
                    'name' => $package->name,
                    'category' => $package->category?->name,
                    'type' => $package->type,
                    'base_price' => $package->base_price,
                    'sales_tax' => $package->sales_tax,
                    'total_price' => $package->total_price,
                    'discount' => $package->discount,
                    'currency' => $package->currency,
                    'has_variables' => $package->hasVariables(),
                    'removed' => $package->removed_at !== null,
                ])->values()->all(),
                ...$this->inertiaPagination($paginator),
            ],
            'filters' => ['search' => $search, 'status' => $status, 'category' => $category],
            'shop_currency' => strtoupper((string) config('commerce.currency', 'USD')),
            'currencies' => $currencies,
            'can' => ['edit' => (bool) $request->user()?->can('commerce.acp.edit')],
        ]);
    }

    /**
     * Ask Tebex who the token belongs to: the quickest way to find out that the keys are right.
     */
    public function test(): RedirectResponse
    {
        try {
            $store = $this->client->webstore();
        } catch (TebexException $exception) {
            return back()->with('tebex_test', ['ok' => false, 'message' => $exception->getMessage()]);
        }

        return back()->with('tebex_test', [
            'ok' => true,
            'name' => $store->name,
            'currency' => $store->currency,
            'url' => $store->url,
            'disabled' => $store->disabled,
        ]);
    }

    public function sync(Request $request): RedirectResponse
    {
        try {
            $run = $this->catalogue->sync(TebexSync::ADMIN, $request->user());
        } catch (TebexException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        if ($run->status !== TebexSync::OK) {
            return back()->with('error', $run->error ?? 'The synchronisation failed.');
        }

        $counts = (array) $run->counts;
        $packages = (array) ($counts['packages'] ?? []);

        return back()->with('success', sprintf(
            'Catalogue refreshed: %d %s added, %d changed, %d no longer listed.',
            (int) ($packages['created'] ?? 0),
            (int) ($packages['created'] ?? 0) === 1 ? 'package' : 'packages',
            (int) ($packages['updated'] ?? 0),
            (int) ($packages['removed'] ?? 0),
        ));
    }

    private function lastOk(): ?TebexSync
    {
        return TebexSync::query()->where('status', TebexSync::OK)->latest('started_at')->latest('id')->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function run(TebexSync $run): array
    {
        return [
            'id' => $run->id,
            'status' => $run->status,
            'trigger' => $run->trigger,
            'by' => $run->relationLoaded('user') ? $run->user?->nickname : null,
            'started_at' => $run->started_at->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'counts' => $run->counts,
            'error' => $run->error,
        ];
    }

    /**
     * The start of the public token, enough to recognise which store's it is. (It is not a secret, but nothing needs
     * all of it.)
     */
    private function maskedToken(): ?string
    {
        $token = config('tebex.public_token');

        if (! is_string($token) || trim($token) === '') {
            return null;
        }

        return substr(trim($token), 0, 4).'…'.substr(trim($token), -3);
    }
}
