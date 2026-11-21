<?php

namespace Tests\Fakes;

use App\Exceptions\ApiException;
use App\Services\Payments\PaymentGateway;

/** Records calls instead of talking to Stripe. Honours idempotency keys like Stripe does. */
class FakePaymentGateway implements PaymentGateway
{
    /** @var array<string, array{id: string, amount: int, currency: string, metadata: array}> */
    public array $intents = [];

    public array $cancelled = [];

    /** @var array<string, array{paymentIntent: string, amount: int}> keyed by idempotency key */
    public array $refunds = [];

    public bool $failNext = false;

    public bool $declineNext = false;

    /** @var array<string, string> what Stripe would report for an intent id (default `requires_payment_method`) */
    public array $statuses = [];

    /** Status returned by createPaymentIntent for a card payment (Stripe returns `succeeded` for most test cards). */
    public string $cardStatus = 'processing';

    public function createPaymentIntent(int $amountCents, string $currency, array $metadata, string $idempotencyKey, ?string $paymentMethodId = null): array
    {
        if ($this->declineNext) {
            $this->declineNext = false;
            throw new ApiException('payment_declined', 'Your card was declined.', 402, 'paymentMethodId');
        }

        if ($this->failNext) {
            $this->failNext = false;
            throw new ApiException('payment_unavailable', 'We couldn\'t reach the payment service. Please try again.', 502);
        }

        $this->intents[$idempotencyKey] ??= [
            'id' => 'pi_'.substr(md5($idempotencyKey), 0, 16),
            'amount' => $amountCents, 'currency' => $currency, 'metadata' => $metadata, 'paymentMethod' => $paymentMethodId,
        ];

        $id = $this->intents[$idempotencyKey]['id'];

        // pm_card_threeDSecure2Required mimics a card that needs 3-D Secure.
        $status = match (true) {
            $paymentMethodId === null => 'requires_payment_method',
            str_contains($paymentMethodId, 'threeDSecure') => 'requires_action',
            default => $this->cardStatus,
        };

        return ['id' => $id, 'clientSecret' => "{$id}_secret_test", 'status' => $status];
    }

    public function paymentIntentStatus(string $paymentIntentId): string
    {
        if ($this->failNext) {
            $this->failNext = false;
            throw new ApiException('payment_unavailable', 'We couldn\'t reach the payment service. Please try again.', 502);
        }

        return $this->statuses[$paymentIntentId] ?? 'requires_payment_method';
    }

    public function cancelPaymentIntent(string $paymentIntentId): void
    {
        $this->cancelled[] = $paymentIntentId;
    }

    public function refund(string $paymentIntentId, int $amountCents, string $idempotencyKey): void
    {
        $this->refunds[$idempotencyKey] ??= ['paymentIntent' => $paymentIntentId, 'amount' => $amountCents];
    }
}
