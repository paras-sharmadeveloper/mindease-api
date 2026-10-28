<?php

namespace App\Http\Controllers\Panel;

use App\Models\AvailabilityException;
use App\Services\Booking\PanelPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Weekly hours (wall clock in the therapist's timezone) and blocked periods (UTC).
 * Existing bookings are never touched: changing hours only changes which new slots are offered.
 */
class AvailabilityController extends PanelController
{
    public function show(Request $request, string $therapistId, PanelPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->availability($this->therapistFor($request, $therapistId)));
    }

    /** Full replace of the weekly windows: { windows: [{ weekday 0-6 (0 = Sunday), start "09:00", end "17:00" }] }. */
    public function update(Request $request, string $therapistId, PanelPresenter $presenter): JsonResponse
    {
        $therapist = $this->therapistFor($request, $therapistId);
        $data = $request->validate([
            'windows' => ['present', 'array', 'max:50'],
            'windows.*.weekday' => ['required', 'integer', 'between:0,6'],
            'windows.*.start' => ['required', 'date_format:H:i'],
            'windows.*.end' => ['required', 'date_format:H:i'],
        ]);

        $windows = collect($data['windows'])->sortBy(['weekday', 'start'])->values();
        foreach ($windows as $i => $w) {
            if ($w['end'] <= $w['start']) {
                throw ValidationException::withMessages(["windows.{$i}.end" => 'The end time must be after the start time.']);
            }
            $prev = $windows[$i - 1] ?? null;
            if ($prev && $prev['weekday'] === $w['weekday'] && $w['start'] < $prev['end']) {
                throw ValidationException::withMessages(["windows.{$i}.start" => 'Hours on the same day can\'t overlap.']);
            }
        }

        DB::transaction(function () use ($therapist, $windows) {
            $therapist->availability()->delete();
            foreach ($windows as $w) {
                $therapist->availability()->create(['weekday' => $w['weekday'], 'start_time' => $w['start'], 'end_time' => $w['end']]);
            }
        });

        return response()->json($presenter->availability($therapist));
    }

    /** Block a period (leave, holidays): { startsAt, endsAt, reason? } in ISO 8601. */
    public function storeException(Request $request, string $therapistId, PanelPresenter $presenter): JsonResponse
    {
        $therapist = $this->therapistFor($request, $therapistId);
        $data = $request->validate([
            'startsAt' => ['required', 'date'],
            'endsAt' => ['required', 'date', 'after:startsAt'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $therapist->exceptions()->create([
            'starts_at' => CarbonImmutable::parse($data['startsAt'])->utc(),
            'ends_at' => CarbonImmutable::parse($data['endsAt'])->utc(),
            'reason' => $data['reason'] ?? null,
        ]);

        return response()->json($presenter->availability($therapist), 201);
    }

    public function destroyException(Request $request, string $therapistId, int $exceptionId): Response
    {
        $therapist = $this->therapistFor($request, $therapistId);
        AvailabilityException::where('therapist_id', $therapist->id)->findOrFail($exceptionId)->delete();

        return response()->noContent();
    }
}
