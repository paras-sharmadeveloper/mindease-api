<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Therapist;
use App\Services\Booking\BookingPresenter;
use App\Services\Booking\SlotService;
use App\Support\Iso;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TherapistController extends Controller
{
    /** Active therapists. */
    public function index(BookingPresenter $presenter): JsonResponse
    {
        $therapists = Therapist::with('user')->where('active', true)->get()->sortBy('user.name')->values();

        return response()->json($therapists->map(fn (Therapist $t) => $presenter->therapist($t)));
    }

    /** One active therapist (profile screen). Same shape as a list item. */
    public function show(string $therapistId, BookingPresenter $presenter): JsonResponse
    {
        abort_unless(Str::isUuid($therapistId), 404);
        $therapist = Therapist::with('user')->where('active', true)->find($therapistId)
            ?? throw new ApiException('therapist_not_found', 'We couldn\'t find that therapist.', 404);

        return response()->json($presenter->therapist($therapist));
    }

    /**
     * Bookable start times (UTC) between `from` and `to` (ISO 8601; default now -> +14 days, max 31 days).
     * Returns { therapistId, sessionMinutes, slots: [{ startsAt, endsAt }] }.
     */
    public function slots(Request $request, string $therapistId, SlotService $slots): JsonResponse
    {
        abort_unless(Str::isUuid($therapistId), 404);
        $therapist = Therapist::where('active', true)->findOrFail($therapistId);
        $input = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after:from'],
        ]);

        $from = isset($input['from']) ? CarbonImmutable::parse($input['from'])->utc() : CarbonImmutable::now();
        $to = isset($input['to']) ? CarbonImmutable::parse($input['to'])->utc() : $from->addDays(14);

        if ($from->diffInDays($to) > 31) {
            throw new ApiException('range_too_long', 'Ask for at most 31 days of slots at a time.', 422, 'to');
        }

        $minutes = config('mindease.bookings.session_minutes');

        return response()->json([
            'therapistId' => $therapist->id,
            'sessionMinutes' => $minutes,
            'slots' => array_map(fn (CarbonImmutable $s) => [
                'startsAt' => Iso::utc($s),
                'endsAt' => Iso::utc($s->addMinutes($minutes)),
            ], $slots->slots($therapist, $from, $to)),
        ]);
    }
}
