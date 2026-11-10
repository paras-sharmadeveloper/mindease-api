<?php

namespace App\Http\Controllers\Api;

use App\Domain\RecommendationEngine;
use App\Exceptions\ApiException;
use App\Models\Assessment;
use App\Http\Controllers\Controller;
use App\Models\Therapist;
use App\Services\Booking\BookingPresenter;
use App\Services\Booking\SlotService;
use App\Support\Iso;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

#[Group('Therapists', 'Therapists users can book, and their free slots.', weight: 8)]
class TherapistController extends Controller
{
    /**
     * List focus areas.
     *
     * The choices for the booking screen's "What would you like to focus on?" and for therapist specialties:
     * `[{ key, label }]`. Send the keys as `focus` to GET /therapists and POST /me/bookings.
     */
    public function focusAreas(): JsonResponse
    {
        return response()->json(BookingPresenter::focusAreas(array_keys(config('mindease.focus_areas'))));
    }

    /**
     * List therapists.
     *
     * Active therapists, best match first. `?focus=stress,sleep` (focus-area keys picked on the booking screen)
     * puts therapists whose specialties match the most of them first. Without `focus`, the user's onboarding
     * questionnaire answers decide (see `recommendation.focusAreas` on GET /me/assessment); with neither, sorted by name.
     * Each item has `matchedFocus`: the focus keys that therapist covers.
     */
    public function index(Request $request, BookingPresenter $presenter, RecommendationEngine $engine): JsonResponse
    {
        $input = $request->validate(['focus' => ['sometimes', 'nullable', 'string', 'max:500']]);
        $focus = isset($input['focus'])
            ? array_values(array_intersect(array_map('trim', explode(',', $input['focus'])), array_keys(config('mindease.focus_areas'))))
            : $engine->focusAreas((array) json_decode(json_encode(Assessment::find($request->user()->id)?->answers ?? []), true));

        $therapists = Therapist::with('user')->where('active', true)->get()
            ->sortBy([
                fn (Therapist $a, Therapist $b) => count(array_intersect($b->specialties ?? [], $focus)) <=> count(array_intersect($a->specialties ?? [], $focus)),
                fn (Therapist $a, Therapist $b) => strcasecmp($a->user->name, $b->user->name),
            ])->values();

        return response()->json($therapists->map(fn (Therapist $t) => $presenter->therapist($t, $focus)));
    }

    /**
     * Get a therapist.
     *
     * One active therapist for the profile screen. Same shape as a list item.
     */
    public function show(string $therapistId, BookingPresenter $presenter): JsonResponse
    {
        abort_unless(Str::isUuid($therapistId), 404);
        $therapist = Therapist::with('user')->where('active', true)->find($therapistId)
            ?? throw new ApiException('therapist_not_found', 'We couldn\'t find that therapist.', 404);

        return response()->json($presenter->therapist($therapist));
    }

    /**
     * List available slots.
     *
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
