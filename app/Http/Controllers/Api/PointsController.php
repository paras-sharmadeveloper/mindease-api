<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PhaseCompletion;
use App\Support\Iso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PointsController extends Controller
{
    /** { total, awards: [{ programId, phaseId, points, awardedAt }] } - read-only; points are only created by phase completion. */
    public function show(Request $request): JsonResponse
    {
        $awards = $request->user()->phaseCompletions()->orderBy('completed_at')->get();

        return response()->json([
            'total' => (int) $awards->sum('points'),
            'awards' => $awards->map(fn (PhaseCompletion $a) => [
                'programId' => $a->program_id,
                'phaseId' => $a->phase_id,
                'points' => $a->points,
                'awardedAt' => Iso::utc($a->completed_at),
            ])->values(),
        ]);
    }
}
