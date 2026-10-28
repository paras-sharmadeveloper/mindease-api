<?php

namespace App\Http\Controllers\Api;

use App\Domain\ProgressionService;
use App\Http\Controllers\Controller;
use App\Services\ContentRepository;
use App\Services\Progress\ProgressEventHandler;
use App\Services\Progress\ProgressPresenter;
use App\Services\Progress\ProgressRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProgressController extends Controller
{
    /** ProgramProgress for the signed-in user. */
    public function show(Request $request, string $programId, ContentRepository $content, ProgressRepository $repository, ProgressPresenter $presenter): JsonResponse
    {
        $program = $content->program($programId);
        $rules = new ProgressionService($program->data(), $content->config());

        return response()->json(
            $presenter->present($rules, $repository->load($request->user()->id, $programId), $program->content_version)
        );
    }

    /**
     * Record one progress event. Requires an `Idempotency-Key` header (UUID); replays return the same result.
     *
     * Returns { progress: ProgramProgress, completion: PhaseCompletion | null }.
     * 403 phase_locked if the phase isn't unlocked yet.
     */
    public function event(Request $request, string $programId, ProgressEventHandler $handler): JsonResponse
    {
        return response()->json($handler->handle($request->user(), $programId, $request->all()));
    }
}
