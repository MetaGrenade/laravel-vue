<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BillingWebhookCall;
use App\Support\Billing\BillingWebhookProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class BillingWebhookCallController extends Controller
{
    public function __construct(
        private readonly BillingWebhookProcessor $processor,
    ) {
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('billing.acp.view'), 403);

        $perPage = (int) $request->query('per_page', 25);
        $perPage = max(1, min($perPage, 100));

        $query = BillingWebhookCall::query()
            ->with('user:id,nickname,email');

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function ($innerQuery) use ($search) {
                $innerQuery->where('stripe_id', 'like', "%{$search}%")
                    ->orWhere('type', 'like', "%{$search}%");
            });
        }

        $typeFilter = trim((string) $request->query('type', ''));
        if ($typeFilter !== '') {
            $query->where('type', $typeFilter);
        }

        $processedFilter = trim((string) $request->query('processed', ''));
        if ($processedFilter === 'processed') {
            $query->whereNotNull('processed_at');
        } elseif ($processedFilter === 'pending') {
            $query->whereNull('processed_at');
        }

        $calls = $query
            ->latest('created_at')
            ->paginate($perPage)
            ->withQueryString()
            ->through(function (BillingWebhookCall $call) {
                return [
                    'id' => $call->id,
                    'stripe_id' => $call->stripe_id,
                    'type' => $call->type,
                    'user' => $call->user ? [
                        'id' => $call->user->id,
                        'nickname' => $call->user->nickname,
                        'email' => $call->user->email,
                    ] : null,
                    'processed_at' => optional($call->processed_at)?->toIso8601String(),
                    'created_at' => optional($call->created_at)?->toIso8601String(),
                ];
            });

        $availableTypes = BillingWebhookCall::query()
            ->select('type')
            ->distinct()
            ->orderBy('type')
            ->pluck('type')
            ->all();

        if ($typeFilter !== '' && ! in_array($typeFilter, $availableTypes, true)) {
            $availableTypes[] = $typeFilter;
            sort($availableTypes);
        }

        return Inertia::render('acp/BillingWebhookCalls', [
            'calls' => $calls,
            'filters' => [
                'search' => $search,
                'type' => $typeFilter,
                'processed' => $processedFilter,
                'per_page' => $perPage,
            ],
            'availableTypes' => $availableTypes,
        ]);
    }

    public function show(Request $request, BillingWebhookCall $billingWebhookCall): Response
    {
        abort_unless($request->user()->can('billing.acp.view'), 403);

        $billingWebhookCall->load('user:id,nickname,email');

        return Inertia::render('acp/BillingWebhookCallView', [
            'call' => [
                'id' => $billingWebhookCall->id,
                'stripe_id' => $billingWebhookCall->stripe_id,
                'type' => $billingWebhookCall->type,
                'user' => $billingWebhookCall->user ? [
                    'id' => $billingWebhookCall->user->id,
                    'nickname' => $billingWebhookCall->user->nickname,
                    'email' => $billingWebhookCall->user->email,
                ] : null,
                'processed_at' => optional($billingWebhookCall->processed_at)?->toIso8601String(),
                'created_at' => optional($billingWebhookCall->created_at)?->toIso8601String(),
                'updated_at' => optional($billingWebhookCall->updated_at)?->toIso8601String(),
                'payload' => $billingWebhookCall->payload,
            ],
        ]);
    }

    public function replay(Request $request, BillingWebhookCall $billingWebhookCall): RedirectResponse
    {
        abort_unless($request->user()->can('billing.acp.view'), 403);

        try {
            $this->processor->replay($billingWebhookCall);
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->back()
                ->with('error', 'Failed to replay webhook: '.$exception->getMessage());
        }

        return redirect()
            ->route('acp.billing.webhooks.show', $billingWebhookCall)
            ->with('success', 'Webhook replay dispatched.');
    }
}
