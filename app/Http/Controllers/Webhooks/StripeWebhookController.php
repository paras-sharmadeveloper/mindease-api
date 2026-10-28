<?php

namespace App\Http\Controllers\Webhooks;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\StripeWebhookEvent;
use App\Services\Booking\BookingService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

/**
 * POST /v1/webhooks/stripe - no bearer auth; trust comes from the Stripe-Signature header.
 * Each event id is applied once. Handlers are idempotent too, so a retry after a crash is safe.
 * Subscribe to: payment_intent.succeeded, payment_intent.payment_failed, payment_intent.canceled, charge.refunded.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, BookingService $bookings): Response
    {
        $secret = config('services.stripe.webhook_secret')
            ?: throw new ApiException('webhook_not_configured', 'Webhook secret is not set.', 503);

        try {
            $event = Webhook::constructEvent($request->getContent(), (string) $request->header('Stripe-Signature'), $secret);
        } catch (SignatureVerificationException|\UnexpectedValueException) {
            throw new ApiException('invalid_signature', 'Invalid webhook signature.', 400);
        }

        if (StripeWebhookEvent::whereKey($event->id)->exists()) {
            return response()->noContent();
        }

        $object = $event->data->object;

        match ($event->type) {
            'payment_intent.succeeded' => $bookings->paymentSucceeded($object->id),
            'payment_intent.payment_failed', 'payment_intent.canceled' => $bookings->paymentStatusChanged($object->id, $object->status),
            'charge.refunded' => $object->payment_intent
                ? $bookings->refunded($object->payment_intent, (int) $object->amount_refunded)
                : null,
            default => null,
        };

        try {
            StripeWebhookEvent::create(['id' => $event->id, 'type' => $event->type, 'processed_at' => now()]);
        } catch (UniqueConstraintViolationException) {
            // Processed concurrently by a retry; handlers are idempotent.
        }

        return response()->noContent();
    }
}
