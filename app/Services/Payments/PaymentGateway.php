<?php

namespace App\Services\Payments;

/**
 * The only door to the payment provider. StripeGateway in production; tests bind a fake.
 * Implementations throw ApiException('payment_unavailable', ..., 502) when the provider fails.
 */
interface PaymentGateway
{
    /**
     * With $paymentMethodId (a card the app collected, e.g. `pm_...`), the intent is confirmed straight away:
     * status `succeeded`, `processing` or `requires_action` (3-D Secure: the app finishes it with the clientSecret).
     * A declined card throws ApiException('payment_declined', ..., 402).
     *
     * @return array{id: string, clientSecret: string, status: string}
     */
    public function createPaymentIntent(int $amountCents, string $currency, array $metadata, string $idempotencyKey, ?string $paymentMethodId = null): array;

    public function cancelPaymentIntent(string $paymentIntentId): void;

    public function refund(string $paymentIntentId, int $amountCents, string $idempotencyKey): void;
}
