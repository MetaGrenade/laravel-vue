<?php

namespace App\Support\Billing;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;

/**
 * Stripe's hosted billing portal: where a customer updates their card, reads their invoices and (as far as the shop's settings in
 * the Stripe Dashboard allow) changes or cancels their subscription. Stripe hosts and runs all of it, and tells the shop what
 * changed through the webhook, which is how the subscription here stays in step.
 */
class CustomerPortal
{
    /**
     * Whether this customer should be offered the portal: it is switched on, Stripe is set up, and they have a billing account.
     */
    public function availableTo(?User $user): bool
    {
        return $this->enabled()
            && filled(config('cashier.secret'))
            && $user !== null
            && filled($user->stripe_id);
    }

    /**
     * Whether the site offers the portal at all (`billing.portal.enabled`).
     */
    public function enabled(): bool
    {
        return (bool) config('billing.portal.enabled', true);
    }

    /**
     * A one-time address for this customer's portal, which sends them back to the billing page when they are done.
     *
     * @throws PortalUnavailable
     */
    public function urlFor(User $user): string
    {
        if (blank($user->stripe_id)) {
            throw PortalUnavailable::noBillingAccount();
        }

        $options = array_filter(['configuration' => config('billing.portal.configuration')]);

        try {
            $url = $user->billingPortalUrl(route('settings.billing.index'), $options);
        } catch (ApiErrorException $exception) {
            Log::error('Stripe would not open a billing portal session.', [
                'user_id' => $user->getKey(),
                'stripe_customer' => $user->stripe_id,
                'error' => $exception->getMessage(),
            ]);

            throw PortalUnavailable::cannotBeOpened($exception);
        }

        // Only ever send a customer to a secure address.
        if (! str_starts_with($url, 'https://')) {
            Log::error('Stripe gave a billing portal address that is not https.', ['user_id' => $user->getKey()]);

            throw PortalUnavailable::cannotBeOpened();
        }

        return $url;
    }
}
