<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CouponType;
use App\Enums\OrderStatus;
use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CouponRequest;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\Commerce\Discounts\CouponRedemptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Discount codes. A code that orders have used can be switched off but not deleted, so the count of
 * its uses (and what each order was given) stays true.
 */
class CouponController extends Controller
{
    use InteractsWithInertiaPagination;

    public function index(Request $request): Response
    {
        $user = $request->user();
        $search = trim((string) $request->query('search', ''));
        $currency = $this->currency();

        $paginator = Coupon::query()
            ->withCount(['products', 'categories'])
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                $query->whereLike('code', '%'.strtoupper($search).'%')->orWhereLike('description', "%{$search}%");
            }))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $uses = Order::query()
            ->whereIn('coupon_id', $paginator->pluck('id'))
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->groupBy('coupon_id')
            ->selectRaw('coupon_id, count(*) as uses')
            ->pluck('uses', 'coupon_id');

        $coupons = $paginator->getCollection()
            ->map(fn (Coupon $coupon) => $this->row($coupon, (int) ($uses[$coupon->id] ?? 0), $currency))
            ->values()
            ->all();

        return Inertia::render('acp/CommerceCoupons', [
            'coupons' => [
                'data' => $coupons,
                ...$this->inertiaPagination($paginator),
            ],
            'filters' => ['search' => $search],
            'currency' => $currency,
            'can' => [
                'create' => (bool) $user?->can('commerce.acp.create'),
                'edit' => (bool) $user?->can('commerce.acp.edit'),
                'delete' => (bool) $user?->can('commerce.acp.delete'),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('acp/CommerceCouponCreate', [
            'categories' => $this->categories(),
            'currency' => $this->currency(),
        ]);
    }

    public function store(CouponRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $coupon = Coupon::create($this->attributes($validated));
        $this->syncRestrictions($coupon, $validated);

        return redirect()->route('acp.commerce.coupons.edit', $coupon)->with('success', 'Code created.');
    }

    public function edit(Request $request, Coupon $coupon, CouponRedemptions $redemptions): Response
    {
        $user = $request->user();
        $currency = $this->currency();
        $usage = $redemptions->usage($coupon, $currency);

        return Inertia::render('acp/CommerceCouponEdit', [
            'coupon' => [
                'id' => $coupon->id,
                'code' => $coupon->code,
                'description' => $coupon->description,
                'type' => $coupon->type->value,
                'value' => $coupon->value === null ? null : $this->trim((string) $coupon->value),
                'currency' => $coupon->currency,
                'minimum_subtotal' => $coupon->minimum_subtotal,
                'starts_at' => $coupon->starts_at?->toIso8601String(),
                'ends_at' => $coupon->ends_at?->toIso8601String(),
                'max_redemptions' => $coupon->max_redemptions,
                'max_redemptions_per_customer' => $coupon->max_redemptions_per_customer,
                'is_active' => $coupon->is_active,
                'status' => $coupon->status($usage['orders']),
                'category_ids' => $coupon->categories()->pluck('product_categories.id')->all(),
                'products' => $coupon->products()->orderBy('name')->get(['products.id', 'products.name'])
                    ->map(fn (Product $product) => ['id' => $product->id, 'name' => $product->name])->values(),
            ],
            'usage' => $usage,
            'categories' => $this->categories(),
            'currency' => $currency,
            'can' => [
                'edit' => (bool) $user?->can('commerce.acp.edit'),
                'delete' => (bool) $user?->can('commerce.acp.delete'),
            ],
        ]);
    }

    public function update(CouponRequest $request, Coupon $coupon): RedirectResponse
    {
        $validated = $request->validated();

        $coupon->update($this->attributes($validated));
        $this->syncRestrictions($coupon, $validated);

        return back()->with('success', 'Code saved.');
    }

    public function destroy(Coupon $coupon): RedirectResponse
    {
        if ($coupon->orders()->exists()) {
            return back()->with('error', 'This code has been used on orders, so it cannot be deleted. Switch it off instead.');
        }

        $coupon->delete();

        return redirect()->route('acp.commerce.coupons.index')->with('success', 'Code deleted.');
    }

    /**
     * Products to pick from when limiting a code, found by name as the admin types.
     */
    public function productSearch(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));

        $products = Product::query()
            ->when($search !== '', fn ($query) => $query->whereLike('name', "%{$search}%"))
            ->orderBy('name')
            ->limit(15)
            ->get(['id', 'name']);

        return response()->json(['data' => $products->map(fn (Product $product) => ['id' => $product->id, 'name' => $product->name])->values()]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributes(array $validated): array
    {
        $type = CouponType::from($validated['type']);

        return [
            'code' => $validated['code'],
            'description' => $validated['description'] ?? null,
            'type' => $type,
            'value' => $type === CouponType::FreeShipping ? null : $validated['value'],
            // A fixed amount is money in the shop's currency; a percentage has no currency.
            'currency' => $type === CouponType::Fixed ? $this->currency() : null,
            'minimum_subtotal' => $validated['minimum_subtotal'] ?? null,
            'starts_at' => $this->moment($validated['starts_at'] ?? null),
            'ends_at' => $this->moment($validated['ends_at'] ?? null),
            'max_redemptions' => $validated['max_redemptions'] ?? null,
            'max_redemptions_per_customer' => $validated['max_redemptions_per_customer'] ?? null,
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ];
    }

    /**
     * What a code is limited to. Free shipping is not about items, so it takes no limits.
     *
     * @param  array<string, mixed>  $validated
     */
    private function syncRestrictions(Coupon $coupon, array $validated): void
    {
        $limited = CouponType::from($validated['type'])->discountsItems();

        $coupon->products()->sync($limited ? ($validated['product_ids'] ?? []) : []);
        $coupon->categories()->sync($limited ? ($validated['category_ids'] ?? []) : []);
    }

    /**
     * A time the browser sent (an ISO date with its zone) as a time in the app's own zone.
     */
    private function moment(?string $value): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value)->setTimezone(config('app.timezone'));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Coupon $coupon, int $uses, string $currency): array
    {
        return [
            'id' => $coupon->id,
            'code' => $coupon->code,
            'description' => $coupon->description,
            'type' => $coupon->type->value,
            'value' => $coupon->value === null ? null : $this->trim((string) $coupon->value),
            'currency' => $coupon->currency,
            // A fixed amount in another currency than the shop sells in cannot be applied.
            'currency_mismatch' => $coupon->type === CouponType::Fixed && strtoupper((string) $coupon->currency) !== $currency,
            'minimum_subtotal' => $coupon->minimum_subtotal,
            'starts_at' => $coupon->starts_at?->toIso8601String(),
            'ends_at' => $coupon->ends_at?->toIso8601String(),
            'max_redemptions' => $coupon->max_redemptions,
            'max_redemptions_per_customer' => $coupon->max_redemptions_per_customer,
            'is_active' => $coupon->is_active,
            'status' => $coupon->status($uses),
            'uses' => $uses,
            'restricted' => $coupon->products_count > 0 || $coupon->categories_count > 0,
        ];
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function categories(): array
    {
        return ProductCategory::query()->orderBy('name')->get(['id', 'name'])
            ->map(fn (ProductCategory $category) => ['id' => $category->id, 'name' => $category->name])->values()->all();
    }

    private function currency(): string
    {
        return strtoupper((string) config('commerce.currency', 'USD'));
    }

    /**
     * "10.0000" reads better as "10" and "12.5000" as "12.5".
     */
    private function trim(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
