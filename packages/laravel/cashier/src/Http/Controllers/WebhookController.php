<?php

namespace Laravel\Cashier\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class WebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $payload = $request->all();
        $method = $this->eventToMethod($payload['type'] ?? '');

        if ($method && method_exists($this, $method)) {
            $response = $this->{$method}($payload);

            if ($response instanceof Response) {
                return $response;
            }
        }

        return new Response('Webhook Handled', 200);
    }

    protected function eventToMethod(string $event): ?string
    {
        if ($event === '') {
            return null;
        }

        return 'handle'.Str::studly(str_replace(['.', '::'], '_', $event));
    }

    protected function handleCustomerSubscriptionDeleted(array $payload)
    {
        // Intentionally left for child classes.
    }

    protected function handleInvoicePaymentFailed(array $payload)
    {
        // Intentionally left for child classes.
    }

    protected function handleInvoicePaymentSucceeded(array $payload)
    {
        // Intentionally left for child classes.
    }
}
