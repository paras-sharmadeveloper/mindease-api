<?php

namespace App\Services\Booking;

use App\Http\Resources\UserResource;
use App\Models\Booking;
use App\Models\Therapist;
use App\Models\User;
use App\Support\Iso;

/** JSON shapes for the therapist/admin web panel (/v1/panel). */
class PanelPresenter
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly BookingPresenter $app,
    ) {}

    public function me(User $user): array
    {
        return [
            'user' => new UserResource($user),
            'role' => $user->role,
            'therapist' => $user->therapist ? $this->therapist($user->therapist->load('user')) : null,
        ];
    }

    public function therapist(Therapist $therapist): array
    {
        return $this->app->therapist($therapist) + [
            'email' => $therapist->user->email,
            'active' => $therapist->active,
            'customPrice' => $therapist->price_cents !== null,
            'createdAt' => Iso::utc($therapist->created_at),
        ];
    }

    public function availability(Therapist $therapist): array
    {
        return [
            'therapistId' => $therapist->id,
            'timezone' => $therapist->timezone,
            'windows' => $therapist->availability()->orderBy('weekday')->orderBy('start_time')->get()
                ->map(fn ($w) => ['weekday' => $w->weekday, 'start' => substr($w->start_time, 0, 5), 'end' => substr($w->end_time, 0, 5)])
                ->values(),
            'exceptions' => $therapist->exceptions()->where('ends_at', '>', now())->orderBy('starts_at')->get()
                ->map(fn ($e) => ['id' => $e->id, 'startsAt' => Iso::utc($e->starts_at), 'endsAt' => Iso::utc($e->ends_at), 'reason' => $e->reason])
                ->values(),
        ];
    }

    /**
     * Notes are sensitive: only the booking's own therapist sees them, never an admin.
     */
    public function booking(Booking $booking, User $viewer): array
    {
        $payment = $booking->payment;
        $isTherapist = $booking->therapist->user_id === $viewer->id;
        $joinOpensAt = $booking->starts_at->copy()->subMinutes(config('mindease.bookings.join_opens_minutes_before'));

        return [
            'id' => $booking->id,
            'status' => $booking->status,
            'cancelReason' => $booking->cancel_reason,
            'startsAt' => Iso::utc($booking->starts_at),
            'endsAt' => Iso::utc($booking->ends_at),
            'client' => ['name' => $booking->user->name, 'email' => $booking->user->email],
            'therapist' => ['id' => $booking->therapist->id, 'name' => $booking->therapist->user->name],
            'priceCents' => $booking->price_cents,
            'currency' => $booking->currency,
            'notes' => $isTherapist ? $booking->notes : null,
            'payment' => $payment ? [
                'status' => $payment->status,
                'amountCents' => $payment->amount_cents,
                'refundedCents' => $payment->refunded_cents,
            ] : null,
            'canCancel' => $this->bookings->isCancellable($booking),
            'canMarkOutcome' => $this->bookings->canMarkOutcome($booking),
            'canJoin' => $isTherapist && $booking->status === Booking::CONFIRMED,
            'joinOpensAt' => $booking->status === Booking::CONFIRMED ? Iso::utc($joinOpensAt) : null,
            'createdAt' => Iso::utc($booking->created_at),
        ];
    }
}
