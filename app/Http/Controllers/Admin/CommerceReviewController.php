<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReviewStatus;
use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Http\Controllers\Controller;
use App\Models\ProductReview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Product reviews in the ACP: what is waiting to be published, and what to do about a review. Rejecting
 * keeps the review (so its writer cannot simply write it again); deleting removes it.
 */
class CommerceReviewController extends Controller
{
    use InteractsWithInertiaPagination;

    /** What the list can be narrowed to besides a review's own status. */
    private const ALL = 'all';

    public function index(Request $request): Response
    {
        $user = $request->user();
        $search = trim((string) $request->query('search', ''));

        $status = (string) $request->query('status', ReviewStatus::Pending->value);
        $status = in_array($status, [...array_column(ReviewStatus::cases(), 'value'), self::ALL], true) ? $status : ReviewStatus::Pending->value;

        $rating = (int) $request->query('rating', 0);
        $rating = $rating >= 1 && $rating <= 5 ? $rating : null;

        $paginator = ProductReview::query()
            ->with(['product:id,name,slug', 'user:id,nickname,email', 'moderator:id,nickname'])
            ->when($status !== self::ALL, fn ($query) => $query->where('status', $status))
            ->when($rating !== null, fn ($query) => $query->where('rating', $rating))
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                $query->whereLike('title', "%{$search}%")
                    ->orWhereLike('body', "%{$search}%")
                    ->orWhereHas('product', fn ($product) => $product->whereLike('name', "%{$search}%"))
                    ->orWhereHas('user', fn ($author) => $author->whereLike('nickname', "%{$search}%")->orWhereLike('email', "%{$search}%"));
            }))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        // How many there are in each state, whatever is being looked at.
        $counts = ProductReview::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $reviews = $paginator->getCollection()->map(fn (ProductReview $review) => [
            'id' => $review->id,
            'rating' => $review->rating,
            'title' => $review->title,
            'body' => $review->body,
            'status' => $review->status->value,
            'status_label' => $review->status->label(),
            'verified_purchase' => $review->verified_purchase,
            'created_at' => $review->created_at?->toIso8601String(),
            'moderated_at' => $review->moderated_at?->toIso8601String(),
            'moderated_by' => $review->moderator?->nickname,
            'product' => $review->product === null ? null : [
                'id' => $review->product->id,
                'name' => $review->product->name,
                'slug' => $review->product->slug,
            ],
            'author' => $review->user === null ? null : [
                'id' => $review->user->id,
                'nickname' => $review->user->nickname,
                'email' => $review->user->email,
            ],
        ])->values()->all();

        $totals = [];

        foreach (ReviewStatus::cases() as $case) {
            $totals[$case->value] = (int) ($counts[$case->value] ?? 0);
        }

        return Inertia::render('acp/CommerceReviews', [
            'reviews' => [
                'data' => $reviews,
                ...$this->inertiaPagination($paginator),
            ],
            'filters' => ['status' => $status, 'search' => $search, 'rating' => $rating],
            'counts' => [...$totals, 'all' => array_sum($totals)],
            'settings' => [
                'enabled' => (bool) config('commerce.reviews.enabled', true),
                'approval' => (string) config('commerce.reviews.approval', 'unverified'),
                'require_purchase' => (bool) config('commerce.reviews.require_purchase', false),
            ],
            'can' => [
                'edit' => (bool) $user?->can('commerce.acp.edit'),
                'delete' => (bool) $user?->can('commerce.acp.delete'),
            ],
        ]);
    }

    /**
     * Publish a review, reject it, or put it back to wait for a decision.
     */
    public function update(Request $request, ProductReview $review): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(array_column(ReviewStatus::cases(), 'value'))],
        ]);

        $status = ReviewStatus::from($validated['status']);

        $review->forceFill([
            'status' => $status,
            'moderated_by' => $request->user()->id,
            'moderated_at' => now(),
        ])->save();

        return back()->with('success', match ($status) {
            ReviewStatus::Published => 'Review published.',
            ReviewStatus::Rejected => 'Review rejected.',
            ReviewStatus::Pending => 'Review put back in the queue.',
        });
    }

    public function destroy(ProductReview $review): RedirectResponse
    {
        $review->delete();

        return back()->with('success', 'Review deleted.');
    }
}
