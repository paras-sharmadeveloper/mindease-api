<?php

namespace App\Services\Booking;

use App\Models\Booking;
use App\Models\Therapist;
use App\Support\Iso;

/** JSON shapes for therapists and bookings (mobile API). */
class BookingPresenter
{
    public function __construct(private readonly BookingService $bookings) {}

    /** @param list<string>|null $focus the user's focus keys: adds `matchedFocus` (the specialties that match) */
    public function therapist(Therapist $therapist, ?array $focus = null): array
    {
        $keys = array_values($therapist->specialties ?? []);

        return [
            'id' => $therapist->id,
            'name' => $therapist->user->name,
            'bio' => $therapist->bio,
            'specialties' => self::focusAreas($keys),
            'photoUrl' => $therapist->photo_url,
            'timezone' => $therapist->timezone,
            'priceCents' => $therapist->priceCents(),
            'currency' => config('mindease.bookings.currency'),
            'sessionMinutes' => config('mindease.bookings.session_minutes'),
        ] + ($focus === null ? [] : ['matchedFocus' => array_values(array_intersect($keys, $focus))]);
    }

    /** Focus keys -> [{ key, label }] (labels from config('mindease.focus_areas')). */
    public static function focusAreas(array $keys): array
    {
        $labels = config('mindease.focus_areas');

        return array_map(fn (string $k) => ['key' => $k, 'label' => $labels[$k] ?? ucfirst(str_replace('_', ' ', $k))], array_values($keys));
    }

    /** Only ever returned to the booking's owner (notes are sensitive). */
    public function booking(Booking $booking): array
    {
        $payment = $booking->payment;
        $joinOpensAt = $booking->starts_at->copy()->subMinutes(config('mindease.bookings.join_opens_minutes_before'));

        return [
            'id' => $booking->id,
            'status' => $booking->status,
            'cancelReason' => $booking->cancel_reason,
            'startsAt' => Iso::utc($booking->starts_at),
            'endsAt' => Iso::utc($booking->ends_at),
            'holdExpiresAt' => $booking->status === Booking::PENDING ? Iso::utc($booking->hold_expires_at) : null,
            'therapist' => [
                'id' => $booking->therapist->id,
                'name' => $booking->therapist->user->name,
                'photoUrl' => $booking->therapist->photo_url,
            ],
            'priceCents' => $booking->price_cents,
            'currency' => $booking->currency,
            'notes' => $booking->notes,
            'focus' => self::focusAreas($booking->focus ?? []),
            'mobile' => $booking->contact_mobile,
            'payment' => $payment ? [
                'status' => $payment->status,
                'amountCents' => $payment->amount_cents,
                'refundedCents' => $payment->refunded_cents,
            ] : null,
            'canCancel' => $this->bookings->isCancellable($booking),
            'refundIfCancelledCents' => $this->bookings->refundIfCancelledNow($booking),
            'joinOpensAt' => $booking->status === Booking::CONFIRMED ? Iso::utc($joinOpensAt) : null,
            'createdAt' => Iso::utc($booking->created_at),
        ];
    }
}
