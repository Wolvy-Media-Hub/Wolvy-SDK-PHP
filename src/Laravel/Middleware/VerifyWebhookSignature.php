<?php

namespace Wolvy\Laravel\Middleware;

use Closure;
use Symfony\Component\HttpFoundation\Response;
use Wolvy\Client;
use Wolvy\Exception\SignatureVerificationException;

/**
 * Verifies `Wolvy-Signature` on a webhook route with the configured WOLVY_WEBHOOK_SECRET.
 * A delivery that does not verify gets HTTP 400 and never reaches the controller; one that does
 * carries the event as the request attribute `wolvy_event` (a Wolvy\Model\WebhookEvent).
 *
 *     Route::post('/hooks/wolvy', WolvyWebhookController::class)
 *         ->middleware(\Wolvy\Laravel\Middleware\VerifyWebhookSignature::class);
 *
 *     $event = $request->attributes->get('wolvy_event');
 *
 * Put the route outside the `web` group (or exclude it from CSRF protection).
 */
class VerifyWebhookSignature
{
    /** @var Client */
    private $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * @param \Illuminate\Http\Request $request
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        try {
            $event = $this->client->verifyWebhook((string) $request->getContent(), $request->headers->get('Wolvy-Signature'));
        } catch (SignatureVerificationException $e) {
            return new Response('Invalid Wolvy signature.', 400, ['Content-Type' => 'text/plain']);
        }

        $request->attributes->set('wolvy_event', $event);

        return $next($request);
    }
}
