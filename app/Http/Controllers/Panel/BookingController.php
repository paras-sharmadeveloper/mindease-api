<?php

namespace App\Http\Controllers\Panel;

use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Services\Booking\BookingService;
use App\Services\Booking\PanelPresenter;
use App\Services\Video\ZoomVideoToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BookingController extends PanelController
{
    /**
     * ?scope=upcoming|past|all (default upcoming) &therapistId= (admin filter) &from=&to= (ISO, on startsAt).
     * Therapists only ever see their own bookings. Unpaid holds are hidden.
     */
    public function index(Request $request, PanelPresenter $presenter): JsonResponse
    {
        $input = $request->validate([
            'scope' => ['nullable', 'in:upcoming,past,all'],
            'therapistId' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $user = $request->user();

        $query = Booking::with(['user', 'therapist.user', 'payment'])->where('status', '!=', Booking::PENDING);

        if ($user->isAdmin()) {
            $query->when($input['therapistId'] ?? null, fn ($q, $id) => $q->where('therapist_id', $id));
        } else {
            $query->whereHas('therapist', fn ($q) => $q->where('user_id', $user->id));
        }

        $query->when($input['from'] ?? null, fn ($q, $from) => $q->where('starts_at', '>=', $from))
            ->when($input['to'] ?? null, fn ($q, $to) => $q->where('starts_at', '<', $to));

        match ($input['scope'] ?? 'upcoming') {
            'upcoming' => $query->where('ends_at', '>', now())->where('status', Booking::CONFIRMED)->orderBy('starts_at'),
            'past' => $query->where(fn ($q) => $q->where('ends_at', '<=', now())->orWhere('status', '!=', Booking::CONFIRMED))->orderByDesc('starts_at'),
            'all' => $query->orderByDesc('starts_at'),
        };

        return response()->json($query->limit(500)->get()->map(fn (Booking $b) => $presenter->booking($b, $user)));
    }

    public function show(Request $request, string $bookingId, PanelPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->booking($this->visible($request, $bookingId), $request->user()));
    }

    /** Therapist or admin cancels: always a full refund to the client. */
    public function cancel(Request $request, string $bookingId, BookingService $bookings, PanelPresenter $presenter): JsonResponse
    {
        $booking = $bookings->cancel($this->visible($request, $bookingId), 'therapist');

        return response()->json($presenter->booking($booking->load(['user', 'therapist.user', 'payment']), $request->user()));
    }

    /** { status: completed | no_show }. Only after the session has started. */
    public function outcome(Request $request, string $bookingId, BookingService $bookings, PanelPresenter $presenter): JsonResponse
    {
        $status = $request->validate(['status' => ['required', 'in:'.Booking::COMPLETED.','.Booking::NO_SHOW]])['status'];
        $booking = $bookings->markOutcome($this->visible($request, $bookingId), $status);

        return response()->json($presenter->booking($booking, $request->user()));
    }

    /** Host token for Zoom Video SDK (Web). Only the booking's own therapist; admins can't join sessions. */
    public function videoToken(Request $request, string $bookingId, ZoomVideoToken $zoom): JsonResponse
    {
        $booking = $this->visible($request, $bookingId);
        if ($booking->therapist->user_id !== $request->user()->id) {
            throw new ApiException('forbidden', 'Only the session\'s therapist can join this call.', 403);
        }

        return response()->json($zoom->issue($booking, $request->user(), host: true) + [
            'displayName' => $request->user()->name,
        ]);
    }

    private function visible(Request $request, string $bookingId): Booking
    {
        abort_unless(Str::isUuid($bookingId), 404);
        $booking = Booking::with(['user', 'therapist.user', 'payment'])->findOrFail($bookingId);
        $this->authorizeTherapist($request->user(), $booking->therapist);

        return $booking;
    }
}
