<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Http\Controllers\Controller;
use App\Models\BillingWebhookCall;
use App\Models\Entitlement;
use App\Models\ProviderSubscription;
use App\Models\TebexCategory;
use App\Models\TebexPackage;
use App\Models\TebexSync;
use App\Payments\Providers\TebexProvider;
use App\Payments\Tebex\TebexCatalogue;
use App\Payments\Tebex\TebexCheckoutClient;
use App\Payments\Tebex\TebexClient;
use App\Payments\Tebex\TebexException;
use App\Payments\Tebex\TebexSelling;
use App\Payments\Tebex\TebexStoreProfile;
use App\Payments\Tebex\TebexWebhookVerifier;
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
        private readonly TebexCheckoutClient $checkoutClient,
        private readonly TebexCatalogue $catalogue,
        private readonly TebexStoreProfile $profile,
        private readonly TebexWebhookVerifier $webhooks,
    ) {}

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', 'listed');
        $status = in_array($status, self::STATUSES, true) ? $status : 'listed';
        $category = $request->query('category');
        $category = is_numeric($category) ? (int) $category : null;

        $paginator = TebexPackage::query()
            ->with(['category:id,name', 'entitlementLinks.entitlement'])
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
        $lastDelivery = BillingWebhookCall::query()->where('provider', TebexProvider::KEY)->latest('id')->first();

        return Inertia::render('acp/CommerceTebex', [
            // What the shop sells through Tebex: its packages (the Headless API), or its own products (the Checkout API).
            'mode' => TebexSelling::checkoutApi() ? 'checkout_api' : 'packages',
            'checkout_api' => [
                'configured' => $this->checkoutClient->isConfigured(),
                'project_id' => $this->maskedProjectId(),
                'has_private_key' => filled(config('tebex.private_key')),
            ],
            'connection' => [
                'configured' => $this->client->isConfigured(),
                'token' => $this->maskedToken(),
                'has_private_key' => filled(config('tebex.private_key')),
            ],
            // The store as the shop last saw it, and whether it can sell for it.
            'store' => $this->profile->current() === null ? null : [
                'name' => $this->profile->current()['name'] ?? null,
                'platform' => $this->profile->platform(),
                'supported' => $this->profile->isSupported(),
                'reason' => $this->profile->unsupportedReason(),
                // What buyers are asked before they can pay: nothing, a username, or to sign in.
                'identity' => $this->profile->identity(),
                'username_label' => $this->profile->usernameLabel(),
            ],
            // Where Tebex sends payments. The secret is never sent, only whether there is one.
            'webhook' => [
                'url' => route('tebex.webhook'),
                'secret_configured' => $this->webhooks->isConfigured(),
                'ip_check' => (bool) config('tebex.webhook.check_ip', true),
                'last_received_at' => $lastDelivery?->created_at?->toIso8601String(),
                'needs_a_look' => BillingWebhookCall::query()->where('provider', TebexProvider::KEY)->whereNotNull('error')->count(),
                'can_view_log' => (bool) $request->user()?->can('billing.acp.view'),
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
            // Where the recurring payments the shop has been told about stand (Tebex charges and ends them).
            'recurring' => $this->recurring(),
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
                    // What buying it gives the buyer's account in the shop (set here; Tebex knows nothing of it).
                    'entitlements' => $package->entitlementLinks
                        ->sortBy(fn ($link) => $link->entitlement->label)
                        ->map(fn ($link) => [
                            'id' => $link->entitlement->id,
                            'key' => $link->entitlement->key,
                            'label' => $link->entitlement->label,
                            'type' => $link->entitlement->type->value,
                            'value' => $link->value,
                        ])->values()->all(),
                ])->values()->all(),
                ...$this->inertiaPagination($paginator),
            ],
            'filters' => ['search' => $search, 'status' => $status, 'category' => $category],
            'shop_currency' => strtoupper((string) config('commerce.currency', 'USD')),
            'currencies' => $currencies,
            // Everything a package can be set to give.
            'entitlements' => Entitlement::query()->orderBy('label')->get()
                ->map(fn (Entitlement $entitlement) => [
                    'id' => $entitlement->id,
                    'key' => $entitlement->key,
                    'label' => $entitlement->label,
                    'type' => $entitlement->type->value,
                ])->values()->all(),
            'can' => ['edit' => (bool) $request->user()?->can('commerce.acp.edit')],
        ]);
    }

    /**
     * Ask Tebex who the token belongs to: the quickest way to find out that the keys are right.
     */
    public function test(): RedirectResponse
    {
        if (TebexSelling::checkoutApi()) {
            return $this->testCheckoutApi();
        }

        try {
            $store = $this->client->webstore();
        } catch (TebexException $exception) {
            return back()->with('tebex_test', ['ok' => false, 'message' => $exception->getMessage()]);
        }

        $this->profile->remember($store);

        return back()->with('tebex_test', [
            'ok' => true,
            'name' => $store->name,
            'currency' => $store->currency,
            'url' => $store->url,
            'disabled' => $store->disabled,
            'platform' => $store->platform,
            'supported' => $this->profile->isSupported(),
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

    /**
     * @return array{running: int, overdue: int, ending: int, ended: int}
     */
    private function recurring(): array
    {
        $running = ProviderSubscription::query()->where('provider', TebexProvider::KEY)->whereNull('ended_at')->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::Overdue->value]);

        return [
            'running' => (clone $running)->count(),
            'overdue' => (clone $running)->where('status', SubscriptionStatus::Overdue->value)->count(),
            // Still running, but they have asked for it to stop at the end of the period.
            'ending' => (clone $running)->whereNotNull('cancellation_requested_at')->count(),
            'ended' => ProviderSubscription::query()->where('provider', TebexProvider::KEY)->where(fn ($query) => $query->whereNotNull('ended_at')->orWhereIn('status', [SubscriptionStatus::Expired->value, SubscriptionStatus::Cancelled->value]))->count(),
        ];
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
     * Find out whether the project id and private key are accepted. The Checkout API has no call that says who is asking,
     * so this says only that Tebex let the request in (and not what the store is called).
     */
    private function testCheckoutApi(): RedirectResponse
    {
        try {
            $this->checkoutClient->ping();
        } catch (TebexException $exception) {
            return back()->with('tebex_test', ['ok' => false, 'message' => $exception->getMessage()]);
        }

        return back()->with('tebex_test', ['ok' => true, 'checkout_api' => true]);
    }

    /**
     * The end of the project id, enough to recognise which project's it is. (It is not a secret, but nothing needs all of it.)
     */
    private function maskedProjectId(): ?string
    {
        $id = config('tebex.checkout_api.project_id');

        if (! is_string($id) || trim($id) === '') {
            return null;
        }

        return '…'.substr(trim($id), -3);
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
