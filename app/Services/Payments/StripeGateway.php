<?php

namespace App\Services\Payments;

use App\Exceptions\ApiException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Stripe\StripeClient;

class StripeGateway implements PaymentGateway
{
    public function __construct(private readonly StripeClient $stripe) {}

    public function createPaymentIntent(int $amountCents, string $currency, array $metadata, string $idempotencyKey, ?string $paymentMethodId = null): array
    {
        $params = [
            'amount' => $amountCents,
            'currency' => $currency,
            // An app can't follow redirects; 3-D Secure still works (status requires_action).
            'automatic_payment_methods' => ['enabled' => true] + ($paymentMethodId ? ['allow_redirects' => 'never'] : []),
            // Ids only - no health information goes to Stripe.
            'metadata' => $metadata,
            'description' => 'MindEase session',
        ];
        if ($paymentMethodId) {
            $params += ['payment_method' => $paymentMethodId, 'confirm' => true];
        }

        try {
            $intent = $this->stripe->paymentIntents->create($params, ['idempotency_key' => $idempotencyKey]);
        } catch (CardException $e) {
            throw new ApiException('payment_declined', $e->getError()?->message ?: 'Your card was declined. Try another card.', 402, 'paymentMethodId');
        } catch (ApiErrorException $e) {
            report($e);

            throw new ApiException('payment_unavailable', 'We couldn\'t reach the payment service. Please try again.', 502);
        }

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
