<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PhaseCompletion;
use App\Support\Iso;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Points', 'Points earned by completing phases.', weight: 7)]
class PointsController extends Controller
{
    /**
     * Get my points.
     *
     * `{ total, awards: [{ programId, phaseId, points, awardedAt }] }`. Read-only: points come only from completing phases.
     */
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
