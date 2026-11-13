<?php

namespace App\Http\Controllers\Api;

use App\Domain\ProgressionService;
use App\Http\Controllers\Controller;
use App\Services\ContentRepository;
use App\Services\Progress\ProgressEventHandler;
use App\Services\Progress\ProgressPresenter;
use App\Services\Progress\ProgressRepository;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\HeaderParameter;
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
     * `Idempotency-Key` header is optional for now. Send a UUID to make retries safe: the same key returns the
     * same result instead of running twice.
     *
     * Returns { progress: ProgramProgress, completion: PhaseCompletion | null }.
     * 403 phase_locked if the phase isn't unlocked yet.
     */
    #[HeaderParameter('Idempotency-Key', 'Optional for now. A new UUID v4 per action, and the same one when retrying it: a retry then returns the first response (header `Idempotent-Replayed: true`) instead of running twice. Without it the request runs normally.', required: false, type: 'string', format: 'uuid', example: '3f1c9a2e-7b4d-4c1e-9f0a-2d6b8e5c1a47')]
    public function event(Request $request, string $programId, ProgressEventHandler $handler): JsonResponse
    {
        return response()->json($handler->handle($request->user(), $programId, $request->all()));
    }
}
