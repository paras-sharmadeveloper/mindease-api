<?php

namespace App\Services\Payments;

use App\Exceptions\ApiException;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

class StripeGateway implements PaymentGateway
{
    public function __construct(private readonly StripeClient $stripe) {}

    public function createPaymentIntent(int $amountCents, string $currency, array $metadata, string $idempotencyKey): array
    {
        $intent = $this->call(fn () => $this->stripe->paymentIntents->create([
            'amount' => $amountCents,
            'currency' => $currency,
            'automatic_payment_methods' => ['enabled' => true],
            // Ids only - no health information goes to Stripe.
            'metadata' => $metadata,
            'description' => 'MindEase session',
        ], ['idempotency_key' => $idempotencyKey]));

        return ['id' => $intent->id, 'clientSecret' => $intent->client_secret, 'status' => $intent->status];
    }

    public function cancelPaymentIntent(string $paymentIntentId): void
    {
        $this->call(fn () => $this->stripe->paymentIntents->cancel($paymentIntentId));
    }

    public function refund(string $paymentIntentId, int $amountCents, string $idempotencyKey): void
    {
        $this->call(fn () => $this->stripe->refunds->create([
            'payment_intent' => $paymentIntentId,
            'amount' => $amountCents,
        ], ['idempotency_key' => $idempotencyKey]));
    }

    private function call(callable $request): mixed
    {
        try {
            return $request();
        } catch (ApiErrorException $e) {
            report($e);

            throw new ApiException('payment_unavailable', 'We couldn\'t reach the payment service. Please try again.', 502);
        }
    }
}
