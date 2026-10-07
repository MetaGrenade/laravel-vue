<?php

namespace App\Payments\Webhooks;

use App\Models\BillingWebhookCall;
use App\Payments\Data\WebhookOutcome;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The store-then-process pattern every provider's webhook goes through.
 *
 * Each delivery is recorded under (provider, external id). If that event was
 * already processed the delivery is acknowledged and nothing runs again, so
 * the provider's retries are harmless. The work runs in one transaction, so an
 * event is either fully applied or not at all; on failure the error is kept
 * on the call and the provider is asked to try again.
 */
class WebhookReceiver
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  Closure(BillingWebhookCall): (WebhookOutcome|null)  $process
     */
    public function receive(
        string $provider,
        string $externalId,
        string $type,
        array $payload,
        Closure $process,
    ): WebhookOutcome {
        $call = $this->record($provider, $externalId, $type, $payload);

        try {
            $outcome = DB::transaction(function () use ($call, $process) {
                $locked = BillingWebhookCall::query()->lockForUpdate()->findOrFail($call->id);

                if ($locked->processed_at !== null) {
                    return WebhookOutcome::duplicate();
                }

                $outcome = $process($locked) ?? WebhookOutcome::handled();

                $locked->forceFill([
                    'attempts' => $locked->attempts + 1,
                    'error' => null,
                    'processed_at' => now(),
                ])->save();

                return $outcome;
            });
        } catch (Throwable $exception) {
            report($exception);

            BillingWebhookCall::query()->whereKey($call->id)->update([
                'attempts' => DB::raw('attempts + 1'),
                'error' => mb_substr($exception->getMessage(), 0, 1000),
            ]);

            return WebhookOutcome::failed();
        }

        return $outcome;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function record(string $provider, string $externalId, string $type, array $payload): BillingWebhookCall
    {
        $attributes = [
            'type' => $type,
            'payload' => $payload,
            // The admin screens still read stripe_id.
            'stripe_id' => $provider === 'stripe' ? $externalId : null,
        ];

        $existing = BillingWebhookCall::query()
            ->where('provider', $provider)
            ->where('external_id', $externalId)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return BillingWebhookCall::create([
                'provider' => $provider,
                'external_id' => $externalId,
                ...$attributes,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent delivery of the same event recorded it first.
            return BillingWebhookCall::query()
                ->where('provider', $provider)
                ->where('external_id', $externalId)
                ->firstOrFail();
        }
    }
}
