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

        $perPage = (int) config('billing.invoice_pagination', 25);

        $invoices = BillingInvoice::query()
            ->with(['user:id,nickname,email', 'plan:id,name'])
            ->latest('created_at')
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
        ]);
    }
}
