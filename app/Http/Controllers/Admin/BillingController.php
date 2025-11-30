<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BillingInvoice;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BillingController extends Controller
{
    public function invoices(Request $request): Response
    {
        abort_unless($request->user()->can('billing.acp.view'), 403);

        $filters = $this->validatedFilters($request);
        $perPage = (int) config('billing.invoice_pagination', 25);

        $query = $this->filteredInvoices($filters)
            ->with(['user:id,nickname,email', 'plan:id,name'])
            ->latest('created_at');

        $invoices = $query
            ->paginate($perPage)
            ->through(function (BillingInvoice $invoice) {
                return [
                    'id' => $invoice->id,
                    'stripe_id' => $invoice->stripe_id,
                    'status' => $invoice->status,
                    'currency' => $invoice->currency,
                    'total' => $invoice->total,
                    'subtotal' => $invoice->subtotal,
                    'tax' => $invoice->tax,
                    'user' => $invoice->user ? [
                        'id' => $invoice->user->id,
                        'nickname' => $invoice->user->nickname,
                        'email' => $invoice->user->email,
                    ] : null,
                    'plan' => $invoice->plan ? [
                        'id' => $invoice->plan->id,
                        'name' => $invoice->plan->name,
                    ] : null,
                    'created_at' => optional($invoice->created_at)?->toIso8601String(),
                    'paid_at' => optional($invoice->paid_at)?->toIso8601String(),
                ];
            });

        return Inertia::render('acp/BillingInvoices', [
            'invoices' => $invoices,
            'filters' => $filters,
            'statusOptions' => $this->statusOptions(),
        ]);
    }

    /**
     * @return array<string, string|array<int, string>|null>
     */
    protected function validatedFilters(Request $request): array
    {
        $validated = $request->validate($this->filterRules());

        return array_merge([
            'status' => null,
            'search' => null,
            'date_from' => null,
            'date_to' => null,
        ], $validated);
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function filterRules(): array
    {
        return [
            'status' => ['nullable', 'string'],
            'search' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ];
    }

    /**
     * @param  array<string, string|null>  $filters
     */
    protected function filteredInvoices(array $filters)
    {
        return BillingInvoice::query()
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($subQuery) use ($search) {
                    $subQuery
                        ->where('stripe_id', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($userQuery) use ($search) {
                            $userQuery->where('nickname', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        })
                        ->orWhereHas('plan', function ($planQuery) use ($search) {
                            $planQuery->where('name', 'like', "%{$search}%");
                        });
                });
            })
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date));
    }

    /**
     * @return array<int, string>
     */
    protected function statusOptions(): array
    {
        return [
            'draft',
            'open',
            'paid',
            'uncollectible',
            'void',
        ];
    }
}
