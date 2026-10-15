<?php

namespace App\Http\Controllers\Ecommerce;

use App\Http\Controllers\Controller;
use App\Models\TebexCategory;
use App\Models\TebexPackage;
use App\Payments\PaymentManager;
use App\Payments\Providers\TebexProvider;
use App\Payments\Tebex\TebexStoreProfile;
use App\Support\Commerce\CartManager;
use App\Support\Security\HtmlSanitizer;
use App\Support\Seo\Seo;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The shop window of a store that sells through Tebex: its categories and packages, read from the shop's local copy
 * of the catalogue (never from Tebex while the visitor waits).
 *
 * The pages exist only while Tebex is the store's provider. What Tebex wrote is shown with care: the description is
 * HTML from the Tebex panel, so it is cleaned first, and a picture is only used if it is an address on the web.
 */
class TebexStorefrontController extends Controller
{
    public function __construct(
        private readonly PaymentManager $payments,
        private readonly HtmlSanitizer $sanitizer,
        private readonly TebexStoreProfile $profile,
    ) {}

    public function index(Request $request): Response
    {
        $this->ensureSellsThroughTebex();

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'integer'],
        ]);

        $paginator = TebexPackage::query()
            ->onSale()
            ->with('category:id,name')
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($query) use ($search) {
                    $query->whereLike('name', "%{$search}%")->orWhereLike('description', "%{$search}%");
                });
            })
            ->when($filters['category'] ?? null, fn ($query, int $category) => $query->where('tebex_category_id', $category))
            ->orderBy('tebex_category_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate(24)
            ->withQueryString();

        app(Seo::class)->title('Packages')->description('Packages for sale.');

        return Inertia::render('commerce/TebexCatalog', [
            'packages' => [
                'data' => $paginator->getCollection()->map(fn (TebexPackage $package) => $this->card($package))->values()->all(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'prev_page_url' => $paginator->previousPageUrl(),
                'next_page_url' => $paginator->nextPageUrl(),
            ],
            'categories' => TebexCategory::query()
                ->listed()
                ->withCount(['packages as packages_count' => fn ($query) => $query->onSale()])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->filter(fn (TebexCategory $category) => $category->packages_count > 0)
                ->map(fn (TebexCategory $category) => ['id' => $category->id, 'name' => $category->name, 'count' => $category->packages_count])
                ->values()
                ->all(),
            'filters' => ['search' => (string) ($filters['search'] ?? ''), 'category' => isset($filters['category']) ? (int) $filters['category'] : null],
        ]);
    }

    public function show(TebexPackage $package): Response
    {
        $this->ensureSellsThroughTebex();

        // A package Tebex no longer lists has no page, and an expired one cannot be bought, but a page for it would
        // only be a dead end.
        abort_if($package->removed_at !== null || ($package->expires_at !== null && $package->expires_at->isPast()), 404);

        $package->load('category:id,name');

        $text = $this->plainText($package->description);

        app(Seo::class)
            ->title($package->name)
            ->description($text !== '' ? Str::limit($text, 160) : null)
            ->image($this->webAddress($package->image_url));

        return Inertia::render('commerce/TebexPackage', [
            'package' => $this->card($package) + [
                'description_html' => $this->sanitizer->article($package->description),
                'pictures' => $this->pictures($package),
                'category' => $package->category?->name,
                'max_quantity' => CartManager::maxQuantityFor($package),
                'sales_tax' => $package->sales_tax,
                'total_price' => $package->total_price,
            ],
        ]);
    }

    /**
     * What a package is in a list, and what is needed to buy it.
     *
     * @return array<string, mixed>
     */
    private function card(TebexPackage $package): array
    {
        // A store the shop cannot sell for sells nothing, whatever the package is.
        $reason = $package->whyNotForSale() !== null ? ucfirst($package->whyNotForSale()).'.' : $this->profile->unsupportedReason();

        return [
            'id' => $package->tebex_id,
            'name' => $package->name,
            'href' => route('shop.packages.show', $package->tebex_id),
            'image' => $this->webAddress($package->image_url),
            'excerpt' => Str::limit($this->plainText($package->description), 140),
            'category' => $package->category?->name,
            'recurring' => $package->isSubscription(),
            'currency' => $package->currency,
            'price' => $package->base_price,
            'discount' => $package->discount,
            'can_buy' => $reason === null,
            'reason' => $reason,
        ];
    }

    /**
     * Pictures of the package: the main one first, then the others Tebex has, each only if it is a web address.
     *
     * @return list<string>
     */
    private function pictures(TebexPackage $package): array
    {
        $urls = [$this->webAddress($package->image_url)];

        foreach ((array) $package->media as $media) {
            if (is_array($media) && ($media['type'] ?? null) === 'image') {
                $urls[] = $this->webAddress($media['url'] ?? null);
            }
        }

        return array_values(array_unique(array_filter($urls)));
    }

    private function webAddress(mixed $url): ?string
    {
        return is_string($url) && preg_match('#^https?://#i', $url) === 1 ? $url : null;
    }

    private function plainText(?string $html): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $html))) ?? '');
    }

    private function ensureSellsThroughTebex(): void
    {
        abort_unless($this->payments->activeKey() === TebexProvider::KEY, 404);
    }
}
