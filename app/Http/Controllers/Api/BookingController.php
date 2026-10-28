<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Therapist;
use App\Services\Booking\BookingPresenter;
use App\Services\Booking\BookingService;
use App\Services\Video\ZoomVideoToken;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BookingController extends Controller
{
    /**
     * Hold a slot and start payment. Requires an `Idempotency-Key` header.
     *
     * Returns `201 { booking, payment: { clientSecret, publishableKey } }`. Pay with Stripe's
     * PaymentSheet; the booking becomes `confirmed` when Stripe tells the server, so poll
     * GET /me/bookings/{id} after the sheet closes. Unpaid holds lapse after `holdExpiresAt`.
     */
    public function store(Request $request, BookingService $bookings, BookingPresenter $presenter): JsonResponse
    {
        $input = $request->validate([
            'therapistId' => ['required', 'uuid'],
            'startsAt' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $therapist = Therapist::where('active', true)->find($input['therapistId'])
            ?? throw new ApiException('therapist_not_found', 'That therapist isn\'t available.', 404, 'therapistId');

        $result = $bookings->create(
            $request->user(),
            $therapist,
            CarbonImmutable::parse($input['startsAt'])->utc(),
            $input['notes'] ?? null,
        );

        return response()->json([
            'booking' => $presenter->booking($result['booking']),
            'payment' => [
                'clientSecret' => $result['clientSecret'],
                'publishableKey' => config('services.stripe.publishable_key'),
            ],
        ], 201);
    }

    /** The user's bookings. `?scope=upcoming|past` (default: all, newest start first). */
    public function index(Request $request, BookingPresenter $presenter): JsonResponse
    {
        $scope = $request->validate(['scope' => ['nullable', 'in:upcoming,past']])['scope'] ?? null;

        $query = $request->user()->bookings()->with(['therapist.user', 'payment']);

        match ($scope) {
            'upcoming' => $query->where('ends_at', '>', now())->whereIn('status', [Booking::PENDING, Booking::CONFIRMED])->orderBy('starts_at'),
            'past' => $query->where(fn ($q) => $q->where('ends_at', '<=', now())->orWhereNotIn('status', [Booking::PENDING, Booking::CONFIRMED]))->orderByDesc('starts_at'),
            default => $query->orderByDesc('starts_at'),
        };

        return response()->json($query->get()->map(fn (Booking $b) => $presenter->booking($b)));
    }

    public function show(Request $request, string $bookingId, BookingPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->booking($this->owned($request, $bookingId)));
    }

    /** Cancel. Confirmed bookings are refunded per the refund rule (see `refundIfCancelledCents`). Safe to retry. */
    public function cancel(Request $request, string $bookingId, BookingService $bookings, BookingPresenter $presenter): JsonResponse
    {
        $booking = $bookings->cancel($this->owned($request, $bookingId));

        return response()->json($presenter->booking($booking->load(['therapist.user', 'payment'])));
    }

    /**
     * Zoom Video SDK token to join the session in the app. Confirmed bookings only, from
     * a few minutes before the start until the end. Returns { token, sessionName, userIdentity, role, expiresAt }.
     */
    public function videoToken(Request $request, string $bookingId, ZoomVideoToken $zoom): JsonResponse
    {
        return response()->json($zoom->issue($this->owned($request, $bookingId), $request->user(), host: false));
    }

    /** Other users' bookings are a plain 404, so ids can't be probed. */
    private function owned(Request $request, string $bookingId): Booking
    {
        abort_unless(Str::isUuid($bookingId), 404);

        return $request->user()->bookings()->with(['therapist.user', 'payment'])->findOrFail($bookingId);
    }
}
