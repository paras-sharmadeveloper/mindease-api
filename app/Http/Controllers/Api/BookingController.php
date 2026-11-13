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
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

#[Group('Bookings', 'Book, pay for, cancel and join therapy sessions.', weight: 9)]
class BookingController extends Controller
{
    /**
     * Book a session.
     *
     * The booking screen's "Book": `therapistId`, `startsAt` (the chosen slot's `startsAt` from
     * GET /therapists/{id}/slots), `focus` (focus-area keys from GET /focus-areas), `mobile` (Australian mobile,
     * e.g. `0412 345 678`; also saved to the profile if it has none), `notes` and, optionally, `paymentMethodId`.
     * `Idempotency-Key` header is optional for now; send a UUID so a retry after a network error can't book twice.
     *
     * Payment, either way:
     * - **PaymentSheet** (no `paymentMethodId`): present Stripe's PaymentSheet with `payment.clientSecret`.
     * - **Own card form** (`paymentMethodId`, the `pm_...` id from Stripe's `createPaymentMethod`): the server charges
     *   it at once. `payment.status` is `succeeded`/`processing`, or `requires_action` (3-D Secure: call Stripe's
     *   `handleNextAction(clientSecret)`). A declined card returns `402 payment_declined` and nothing is booked.
     *
     * Returns `201 { booking, payment: { clientSecret, publishableKey, status } }`. The booking becomes `confirmed`
     * only when Stripe tells the server, so poll GET /me/bookings/{id}. Unpaid holds lapse after `holdExpiresAt`.
     */
    #[HeaderParameter('Idempotency-Key', 'Optional for now. A new UUID v4 per action, and the same one when retrying it: a retry then returns the first response (header `Idempotent-Replayed: true`) instead of running twice. Without it the request runs normally.', required: false, type: 'string', format: 'uuid', example: '3f1c9a2e-7b4d-4c1e-9f0a-2d6b8e5c1a47')]
    public function store(Request $request, BookingService $bookings, BookingPresenter $presenter): JsonResponse
    {
        if (is_string($request->input('mobile'))) {
            $request->merge(['mobile' => MeController::normaliseMobile($request->input('mobile'))]);
        }

        $input = $request->validate([
            'therapistId' => ['required', 'uuid'],
            'startsAt' => ['required', 'date'],
            'focus' => ['sometimes', 'nullable', 'array', 'max:5'],
            'focus.*' => ['string', 'distinct', Rule::in(array_keys(config('mindease.focus_areas')))],
            'mobile' => ['sometimes', 'nullable', 'string', 'regex:/^\+614\d{8}$/'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'paymentMethodId' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^pm_[A-Za-z0-9_]+$/'],
        ], [
            'focus.*.in' => 'Choose what you\'d like to focus on from the list.',
            'mobile.regex' => 'Enter an Australian mobile number, like 0412 345 678.',
            'paymentMethodId.regex' => 'That card couldn\'t be used. Please enter it again.',
        ]);

        $therapist = Therapist::where('active', true)->find($input['therapistId'])
            ?? throw new ApiException('therapist_not_found', 'That therapist isn\'t available.', 404, 'therapistId');

        $user = $request->user();
        $mobile = $input['mobile'] ?? null;

        $result = $bookings->create($user, $therapist, CarbonImmutable::parse($input['startsAt'])->utc(), [
            'notes' => $input['notes'] ?? null,
            'focus' => isset($input['focus']) ? array_values($input['focus']) : null,
            'contactMobile' => $mobile,
            'paymentMethodId' => $input['paymentMethodId'] ?? null,
        ]);

        if ($mobile && ! $user->mobile) {
            $user->forceFill(['mobile' => $mobile])->save();
        }

        return response()->json([
            'booking' => $presenter->booking($result['booking']),
            'payment' => [
                'clientSecret' => $result['clientSecret'],
                'publishableKey' => config('services.stripe.publishable_key'),
                'status' => $result['paymentStatus'],
            ],
        ], 201);
    }

    /**
     * List my bookings.
     *
     * `?scope=upcoming|past` (default: all, newest start first).
     */
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

    /**
     * Get a booking.
     *
     * Poll this after the Stripe PaymentSheet closes until `status` is `confirmed`.
     */
    public function show(Request $request, string $bookingId, BookingPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->booking($this->owned($request, $bookingId)));
    }

    /**
     * Cancel a booking.
     *
     * Confirmed bookings are refunded per the refund rule (see `refundIfCancelledCents`). Safe to retry.
     */
    public function cancel(Request $request, string $bookingId, BookingService $bookings, BookingPresenter $presenter): JsonResponse
    {
        $booking = $bookings->cancel($this->owned($request, $bookingId));

        return response()->json($presenter->booking($booking->load(['therapist.user', 'payment'])));
    }

    /**
     * Get a video call token.
     *
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
