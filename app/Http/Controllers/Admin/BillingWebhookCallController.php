<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BillingWebhookCall;
use App\Payments\Providers\TebexProvider;
use App\Payments\Tebex\TebexEvent;
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
    ) {}

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
                $innerQuery->whereLike('stripe_id', "%{$search}%")
                    ->orWhereLike('external_id', "%{$search}%")
                    ->orWhereLike('type', "%{$search}%");
            });
        }

        $typeFilter = trim((string) $request->query('type', ''));
        if ($typeFilter !== '') {
            $query->where('type', $typeFilter);
        }

        $providerFilter = trim((string) $request->query('provider', ''));
        if ($providerFilter !== '') {
            $query->where('provider', $providerFilter);
        }

        $processedFilter = trim((string) $request->query('processed', ''));
        if ($processedFilter === 'processed') {
            $query->whereNotNull('processed_at');
        } elseif ($processedFilter === 'pending') {
            $query->whereNull('processed_at');
        } elseif ($processedFilter === 'attention') {
            // Understood but not acted on, or failed and waiting for a retry: a payment that matched no order.
            $query->whereNotNull('error');
        }

        $calls = $query
            ->latest('created_at')
            ->paginate($perPage)
            ->withQueryString()
            ->through(function (BillingWebhookCall $call) {
                return [
                    'id' => $call->id,
                    'stripe_id' => $call->stripe_id,
                    'provider' => $call->provider,
                    'external_id' => $call->external_id,
                    'type' => $call->type,
                    'error' => $call->error,
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
                'provider' => $providerFilter,
                'per_page' => $perPage,
            ],
            'availableTypes' => $availableTypes,
            'availableProviders' => BillingWebhookCall::query()->whereNotNull('provider')->distinct()->orderBy('provider')->pluck('provider')->all(),
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
                'provider' => $billingWebhookCall->provider,
                'external_id' => $billingWebhookCall->external_id,
                'attempts' => $billingWebhookCall->attempts,
                'error' => $billingWebhookCall->error,
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

    public function replay(Request $request, BillingWebhookCall $billingWebhookCall, TebexProvider $tebex): RedirectResponse
    {
        abort_unless($request->user()->can('billing.acp.view'), 403);

        if ($billingWebhookCall->provider === TebexProvider::KEY) {
            return $this->replayTebex($billingWebhookCall, $tebex);
        }

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

    /**
     * A Tebex delivery is applied again by Tebex's own handler, which does nothing for one that was already applied:
     * what a replay can do is retry one that failed.
     */
    private function replayTebex(BillingWebhookCall $call, TebexProvider $tebex): RedirectResponse
    {
        $event = TebexEvent::fromArray($call->payload);

        if ($event === null) {
            return back()->with('error', 'Failed to replay webhook: its payload is missing or malformed.');
        }

        $outcome = $tebex->processEvent($event);

        return redirect()->route('acp.billing.webhooks.show', $call)->with(match ($outcome->status) {
            'failed' => ['error' => 'Failed to replay webhook: it failed again. The error is on this page.'],
            'duplicate' => ['info' => 'This delivery was already processed, so nothing was applied again.'],
            default => ['success' => 'Webhook replayed.'],
        });
    }
}
