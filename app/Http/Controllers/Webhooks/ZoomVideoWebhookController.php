<?php

namespace App\Http\Controllers\Webhooks;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\Video\ZoomSessionTracker;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /v1/webhooks/zoom-video - no bearer auth; trust comes from the x-zm-signature header
 * (HMAC SHA-256 of `v0:{x-zm-request-timestamp}:{raw body}` with the event subscription's Secret Token).
 * Subscribe the Video SDK app to: session.started, session.user_joined, session.ended.
 */
#[Group('Zoom webhook (server only)', 'Called by Zoom, never by the app or the panel.', weight: 99)]
class ZoomVideoWebhookController extends Controller
{
    /** Older requests are refused, so a captured request can't be replayed later. */
    private const MAX_AGE_SECONDS = 300;

    /**
     * Zoom Video SDK webhook.
     *
     * Called by Zoom only, never by the app or the panel. Verified by the `x-zm-signature` header.
     *
     * @unauthenticated
     */
    public function __invoke(Request $request, ZoomSessionTracker $tracker): Response|JsonResponse
    {
        $secret = config('services.zoom.webhook_secret')
            ?: throw new ApiException('webhook_not_configured', 'Webhook secret is not set.', 503);

        $timestamp = (string) $request->header('x-zm-request-timestamp');
        $expected = 'v0='.hash_hmac('sha256', "v0:{$timestamp}:".$request->getContent(), $secret);

        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::MAX_AGE_SECONDS
            || ! hash_equals($expected, (string) $request->header('x-zm-signature'))) {
            throw new ApiException('invalid_signature', 'Invalid webhook signature.', 401);
        }

        $event = (string) $request->input('event');
        $payload = (array) $request->input('payload', []);

        // Zoom checks the URL when the subscription is saved (and every few days).
        if ($event === 'endpoint.url_validation') {
            $plain = (string) ($payload['plainToken'] ?? '');

            return response()->json(['plainToken' => $plain, 'encryptedToken' => hash_hmac('sha256', $plain, $secret)]);
        }

        $session = (array) ($payload['object'] ?? []);

        match ($event) {
            'session.started' => $tracker->started($session),
            'session.user_joined' => $tracker->userJoined($session),
            'session.ended' => $tracker->ended($session),
            default => null,
        };

        return response()->noContent();
    }
}
