<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Payments\Providers\TebexProvider;
use App\Payments\Tebex\TebexWebhookVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Where Tebex tells the shop what happened to a payment.
 *
 * Tebex sends only from two addresses and signs every delivery; a sender at any other address is told the page does not
 * exist, as Tebex itself suggests. Anything but a 2xx makes Tebex send the delivery again, later, so only failures worth
 * retrying are not 2xx (see {@see TebexProvider::handleWebhook()}).
 */
class TebexWebhookController extends Controller
{
    public function __invoke(Request $request, TebexWebhookVerifier $verifier, TebexProvider $provider): Response|JsonResponse
    {
        abort_unless($verifier->isFromTebex($request), 404);

        $outcome = $provider->handleWebhook($request);

        // Tebex's validation delivery wants its id back as JSON; every other answer is just a status.
        return $outcome->body !== null
            ? new JsonResponse($outcome->body, $outcome->httpStatus)
            : new Response($outcome->message, $outcome->httpStatus);
    }
}
