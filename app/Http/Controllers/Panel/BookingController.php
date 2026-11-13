<?php

namespace App\Http\Controllers\Panel;

use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Services\Booking\BookingService;
use App\Services\Booking\PanelPresenter;
use App\Services\Video\ZoomVideoToken;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

#[Group('Sessions', 'Booked sessions: list, cancel, mark the outcome and join the call.', weight: 6)]
class BookingController extends PanelController
{
    /**
     * List sessions.
     *
     * `?scope=upcoming|past|all` (default upcoming), `&therapistId=` (admin filter), `&from=&to=` (ISO, on startsAt).
     * Therapists only ever see their own bookings, minus cancelled ones they deleted. Unpaid holds are hidden.
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
            $query->whereHas('therapist', fn ($q) => $q->where('user_id', $user->id))->whereNull('therapist_hidden_at');
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

    /**
     * Get a session.
     */
    public function show(Request $request, string $bookingId, PanelPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->booking($this->visible($request, $bookingId), $request->user()));
    }

    /**
     * Cancel a session.
     *
     * Therapist or admin cancels: always a full refund to the client.
     */
    public function cancel(Request $request, string $bookingId, BookingService $bookings, PanelPresenter $presenter): JsonResponse
    {
        $booking = $bookings->cancel($this->visible($request, $bookingId), 'therapist');

        return response()->json($presenter->booking($booking->load(['user', 'therapist.user', 'payment']), $request->user()));
    }

    /**
     * Delete a cancelled session.
     *
     * Therapist only, and only for `cancelled` sessions (`canDelete: true`): removes it from the therapist's
     * session list. The booking and its payment/refund record are kept, the client still sees it in the app,
     * and admins still see it. Returns `204`. `409 not_deletable` if the session isn't cancelled.
     */
    public function destroy(Request $request, string $bookingId): Response
    {
        $booking = $this->visible($request, $bookingId);

        if ($booking->therapist->user_id !== $request->user()->id) {
            throw new ApiException('forbidden', 'Only the session\'s therapist can delete it from their list.', 403);
        }
        if ($booking->status !== Booking::CANCELLED) {
            throw new ApiException('not_deletable', 'Only cancelled sessions can be deleted.', 409);
        }

        $booking->forceFill(['therapist_hidden_at' => $booking->therapist_hidden_at ?? now()])->save();

        return response()->noContent();
    }

    /**
     * Mark a session completed or no-show.
     *
     * `{ status: completed | no_show }`. Only after the session has started.
     */
    public function outcome(Request $request, string $bookingId, BookingService $bookings, PanelPresenter $presenter): JsonResponse
    {
        $status = $request->validate(['status' => ['required', 'in:'.Booking::COMPLETED.','.Booking::NO_SHOW]])['status'];
        $booking = $bookings->markOutcome($this->visible($request, $bookingId), $status);

        return response()->json($presenter->booking($booking, $request->user()));
    }

    /**
     * Get a host video token.
     *
     * Zoom Video SDK (Web) host token. Only the booking's own therapist; admins can't join sessions.
     */
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
