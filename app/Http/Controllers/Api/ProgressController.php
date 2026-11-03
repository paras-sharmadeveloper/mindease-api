<?php

namespace App\Http\Controllers\Api;

use App\Domain\ProgressionService;
use App\Http\Controllers\Controller;
use App\Services\ContentRepository;
use App\Services\Progress\ProgressEventHandler;
use App\Services\Progress\ProgressPresenter;
use App\Services\Progress\ProgressRepository;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Program progress', 'The user\'s progress through a program. The app sends events; the server decides completion and points.', weight: 6)]
class ProgressController extends Controller
{
    /**
     * Get my program progress.
     *
     * Unlocked and completed phases, lesson/video/activity/quiz state, points and `programCompletedAt`.
     */
    public function show(Request $request, string $programId, ContentRepository $content, ProgressRepository $repository, ProgressPresenter $presenter): JsonResponse
    {
        $program = $content->program($programId);
        $rules = new ProgressionService($program->data(), $content->config());

        return response()->json(
            $presenter->present($rules, $repository->load($request->user()->id, $programId), $program->content_version)
        );
    }

    /**
     * Send a progress event.
     *
     * One endpoint for `lesson_read`, `video_progress`, `activity_save` and `quiz_submit`.
     * Requires an `Idempotency-Key` header (UUID); retries with the same key return the same result.
     *
     * Returns { progress: ProgramProgress, completion: PhaseCompletion | null }.
     * 403 phase_locked if the phase isn't unlocked yet.
     */
    public function event(Request $request, string $programId, ProgressEventHandler $handler): JsonResponse
    {
        return response()->json($handler->handle($request->user(), $programId, $request->all()));
    }
}
