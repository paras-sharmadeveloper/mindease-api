<?php

namespace App\Services\Payments;

/**
 * The only door to the payment provider. StripeGateway in production; tests bind a fake.
 * Implementations throw ApiException('payment_unavailable', ..., 502) when the provider fails.
 */
interface PaymentGateway
{
    /** @return array{id: string, clientSecret: string, status: string} */
    public function createPaymentIntent(int $amountCents, string $currency, array $metadata, string $idempotencyKey): array;

    public function cancelPaymentIntent(string $paymentIntentId): void;

    public function refund(string $paymentIntentId, int $amountCents, string $idempotencyKey): void;
}
