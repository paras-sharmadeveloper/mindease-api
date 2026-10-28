<?php

namespace App\Services\Booking;

use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Therapist;
use App\Models\User;
use App\Services\Payments\PaymentGateway;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Booking lifecycle. The rules:
 *  - The client never confirms a booking. Only the Stripe webhook (paymentSucceeded) does.
 *  - The amount is decided here from the therapist/config, never from the request.
 *  - A slot is held by `slot_lock` (unique per therapist). Cancelling or lapsing clears it.
 *  - Network calls to Stripe happen outside DB transactions.
 */
class BookingService
{
    public function __construct(
        private readonly SlotService $slots,
        private readonly PaymentGateway $payments,
    ) {}

    /** @return array{booking: Booking, clientSecret: string} */
    public function create(User $user, Therapist $therapist, CarbonImmutable $startsAt, ?string $notes): array
    {
        $config = config('mindease.bookings');
        $released = [];

        try {
            // Throwing in here rolls back the hold releases too. That's fine: an expired hold
            // already doesn't block the slot, and the user's own replaced hold stays theirs.
            $booking = DB::transaction(function () use ($user, $therapist, $startsAt, $notes, $config, &$released) {
                Therapist::whereKey($therapist->id)->lockForUpdate()->first();

                // Free lapsed holds on this therapist, plus the user's own earlier hold on this
                // exact slot (app was killed mid-payment and they are trying again).
                $stale = $therapist->bookings()->where('status', Booking::PENDING)
                    ->where(fn ($q) => $q->where('hold_expires_at', '<=', now())
                        ->orWhere(fn ($q) => $q->where('user_id', $user->id)->where('slot_lock', Booking::slotLock($startsAt))))
                    ->with('payment')->get();

                foreach ($stale as $old) {
                    $old->update([
                        'status' => Booking::CANCELLED,
                        'cancel_reason' => $old->hold_expires_at->isPast() ? 'expired' : 'replaced',
                        'cancelled_at' => now(),
                        'slot_lock' => null,
                    ]);
                    $released[] = $old;
                }

                if (! $this->slots->isBookable($therapist, $startsAt)) {
                    throw $this->slotUnavailable();
                }

                return Booking::create([
                    'user_id' => $user->id,
                    'therapist_id' => $therapist->id,
                    'starts_at' => $startsAt,
                    'ends_at' => $startsAt->addMinutes($config['session_minutes']),
                    'status' => Booking::PENDING,
                    'slot_lock' => Booking::slotLock($startsAt),
                    'hold_expires_at' => now()->addMinutes($config['hold_minutes']),
                    'price_cents' => $therapist->priceCents(),
                    'currency' => $config['currency'],
                    'notes' => $notes,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->slotUnavailable();
        }

        foreach ($released as $old) {
            $this->cancelIntentQuietly($old);
        }

        try {
            $intent = $this->payments->createPaymentIntent(
                $booking->price_cents,
                $booking->currency,
                ['booking_id' => $booking->id, 'user_id' => $user->id],
                "booking-{$booking->id}",
            );
        } catch (ApiException $e) {
            $booking->update([
                'status' => Booking::CANCELLED, 'cancel_reason' => 'payment_failed',
                'cancelled_at' => now(), 'slot_lock' => null,
            ]);

            throw $e;
        }

        Payment::create([
            'booking_id' => $booking->id,
            'user_id' => $user->id,
            'stripe_payment_intent_id' => $intent['id'],
            'amount_cents' => $booking->price_cents,
            'currency' => $booking->currency,
            'status' => $intent['status'],
        ]);

        return ['booking' => $booking->refresh(), 'clientSecret' => $intent['clientSecret']];
    }

    /** User cancel. Cancelling an already-cancelled booking returns it unchanged (safe to retry). */
    public function cancel(Booking $booking, string $reason = 'user'): Booking
    {
        if ($booking->status === Booking::CANCELLED) {
            return $booking;
        }
        if (! $this->isCancellable($booking)) {
            throw new ApiException('booking_not_cancellable', 'This booking can no longer be cancelled.', 409);
        }

        $wasConfirmed = $booking->status === Booking::CONFIRMED;
        $refund = $wasConfirmed ? $this->refundIfCancelledNow($booking) : 0;

        // Refund first: if Stripe fails the booking is untouched and the user can retry.
        // The idempotency key means a retry never refunds twice.
        if ($refund > 0) {
            $this->payments->refund($booking->payment->stripe_payment_intent_id, $refund, "booking-{$booking->id}-refund");
        }

        $cancelled = DB::transaction(function () use ($booking, $reason, $refund) {
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->first();

            if (! in_array($locked->status, [Booking::PENDING, Booking::CONFIRMED], true)) {
                return false;
            }

            $locked->update([
                'status' => Booking::CANCELLED, 'cancel_reason' => $reason,
                'cancelled_at' => now(), 'slot_lock' => null, 'hold_expires_at' => null,
            ]);

            if ($refund > 0) {
                $this->recordRefund($locked->payment, $refund);
            }

            return true;
        });

        $booking->refresh();

        if ($cancelled && ! $wasConfirmed) {
            // If the payment slipped through meanwhile, paymentSucceeded() refunds it.
            $this->cancelIntentQuietly($booking);
        }

        return $booking;
    }

    public function isCancellable(Booking $booking): bool
    {
        return in_array($booking->status, [Booking::PENDING, Booking::CONFIRMED], true)
            && $booking->starts_at->isFuture();
    }

    /** Placeholder rule until the client confirms: full refund before the cutoff, else late_cancel_refund_percent. */
    public function refundIfCancelledNow(Booking $booking): int
    {
        if ($booking->status !== Booking::CONFIRMED || ! $booking->payment) {
            return 0;
        }

        $config = config('mindease.bookings');
        $paid = $booking->payment->amount_cents - $booking->payment->refunded_cents;

        if (now()->addHours($config['refund_cutoff_hours'])->lessThanOrEqualTo($booking->starts_at)) {
            return $paid;
        }

        return intdiv($paid * $config['late_cancel_refund_percent'], 100);
    }

    /** Scheduler: lapse unpaid holds so the slot is offered again. Returns how many lapsed. */
    public function expireHolds(): int
    {
        $count = 0;

        Booking::where('status', Booking::PENDING)->where('hold_expires_at', '<=', now())
            ->with('payment')->each(function (Booking $booking) use (&$count) {
                $updated = Booking::whereKey($booking->id)->where('status', Booking::PENDING)->update([
                    'status' => Booking::CANCELLED, 'cancel_reason' => 'expired',
                    'cancelled_at' => now(), 'slot_lock' => null,
                ]);

                if ($updated) {
                    $count++;
                    $this->cancelIntentQuietly($booking);
                }
            });

        return $count;
    }

    /**
     * Webhook payment_intent.succeeded. Confirms the booking. If the booking was cancelled or
     * lapsed before the money arrived, it is re-confirmed when the slot is still free,
     * otherwise the payment is refunded in full.
     */
    public function paymentSucceeded(string $paymentIntentId): void
    {
        $payment = Payment::where('stripe_payment_intent_id', $paymentIntentId)->first();

        if (! $payment) {
            return;
        }

        $refund = DB::transaction(function () use ($payment) {
            $booking = Booking::whereKey($payment->booking_id)->lockForUpdate()->first();
            $payment->update(['status' => 'succeeded']);

            if ($booking->status === Booking::PENDING) {
                $booking->update(['status' => Booking::CONFIRMED, 'confirmed_at' => now(), 'hold_expires_at' => null]);

                return false;
            }

            if ($booking->status !== Booking::CANCELLED) {
                return false; // already confirmed (webhook retry) or later
            }

            if ($booking->cancel_reason === 'expired' && $booking->starts_at->isFuture()) {
                try {
                    DB::transaction(fn () => $booking->update([
                        'status' => Booking::CONFIRMED, 'cancel_reason' => null, 'cancelled_at' => null,
                        'slot_lock' => Booking::slotLock($booking->starts_at), 'confirmed_at' => now(),
                    ]));

                    return false;
                } catch (UniqueConstraintViolationException) {
                    // Someone else took the slot after the hold lapsed.
                }
            }

            return true;
        });

        if ($refund) {
            $payment->refresh();
            $amount = $payment->amount_cents - $payment->refunded_cents;

            if ($amount > 0) {
                $this->payments->refund($paymentIntentId, $amount, "booking-{$payment->booking_id}-refund");
                $this->recordRefund($payment, $amount);
            }
        }
    }

    /** Webhook payment_intent.payment_failed / canceled: record it; the hold lapses on its own. */
    public function paymentStatusChanged(string $paymentIntentId, string $status): void
    {
        Payment::where('stripe_payment_intent_id', $paymentIntentId)
            ->whereNotIn('status', ['succeeded', 'refunded', 'partially_refunded'])
            ->update(['status' => $status]);
    }

    /** Webhook charge.refunded (also covers refunds made in the Stripe dashboard). */
    public function refunded(string $paymentIntentId, int $amountRefunded): void
    {
        $payment = Payment::where('stripe_payment_intent_id', $paymentIntentId)->first();

        if ($payment && $amountRefunded > $payment->refunded_cents) {
            $this->recordRefund($payment, $amountRefunded - $payment->refunded_cents);
        }
    }

    private function recordRefund(Payment $payment, int $amount): void
    {
        $total = min($payment->amount_cents, $payment->refunded_cents + $amount);

        $payment->update([
            'refunded_cents' => $total,
            'status' => $total >= $payment->amount_cents ? 'refunded' : 'partially_refunded',
        ]);
    }

    private function cancelIntentQuietly(Booking $booking): void
    {
        $intentId = $booking->payment?->stripe_payment_intent_id;

        if (! $intentId) {
            return;
        }

        try {
            $this->payments->cancelPaymentIntent($intentId);
        } catch (ApiException) {
            // Already succeeded/cancelled, or Stripe is down. A late success is refunded by paymentSucceeded().
        }
    }

    private function slotUnavailable(): ApiException
    {
        return new ApiException('slot_unavailable', 'That time is no longer available. Please pick another.', 409, 'startsAt');
    }
}
