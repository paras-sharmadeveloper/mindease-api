<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\ContentRepository;
use App\Services\Progress\ProgressEventHandler;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One endpoint per program action, for the app's lesson / video / activity / quiz screens. Each one finds the
 * phase from the item id and runs the same server rules as POST /me/programs/{programId}/events (locked phases,
 * re-scored quizzes, points once). No Idempotency-Key needed: repeating any of these is harmless.
 */
#[Group('Program progress', weight: 6)]
class ProgramActionController extends Controller
{
    public function __construct(private ContentRepository $content, private ProgressEventHandler $handler) {}

    /**
     * Mark a lesson as read.
     *
     * Call when the user finishes a lesson. Returns `{ progress, completion }`; `completion` is set when this
     * finished the phase (show the completion sheet). `403 phase_locked`, `422 unknown_lesson`.
     */
    public function lessonRead(Request $request, string $programId, string $lessonId): JsonResponse
    {
        return $this->send($request, $programId, 'lessons', $lessonId, ['type' => 'lesson_read', 'lessonId' => $lessonId]);
    }

    /**
     * Save video progress.
     *
     * `{ position, watched }`: `position` = seconds into the video (to resume), `watched` = one character per
     * second of the video, `"1"` if that second was played (e.g. `"1111100000"`). Send every few seconds and when the
     * player closes. The video completes when 90% of its seconds were played; seeking ahead doesn't count.
     */
    public function videoProgress(Request $request, string $programId, string $videoId): JsonResponse
    {
        return $this->send($request, $programId, 'videos', $videoId, [
            'type' => 'video_progress', 'videoId' => $videoId,
            'position' => $request->input('position'), 'watched' => $request->input('watched'),
        ]);
    }

    /**
     * Save an activity.
     *
     * `{ answers: { "<promptId>": { text, chips } }, complete }`. `complete: false` saves a draft; `true` submits
     * (needs at least one answer, `422 activity_empty`). Once complete it stays complete; later saves update answers.
     */
    public function activity(Request $request, string $programId, string $activityId): JsonResponse
    {
        return $this->send($request, $programId, 'activity', $activityId, [
            'type' => 'activity_save', 'answers' => $request->input('answers'), 'complete' => $request->input('complete'),
        ]);
    }

    /**
     * Submit a quiz.
     *
     * `{ answers: [1, 0, 2, 0, 1] }`: the 0-based index of the chosen option for every question, in question order.
     * Scored by the server; the latest attempt (`correct`, `total`, `score`, `passed`) is the last item of
     * `progress.phases.<phaseId>.quiz.attempts`. Pass mark 80%. Retakes are unlimited.
     */
    public function quiz(Request $request, string $programId, string $quizId): JsonResponse
    {
        return $this->send($request, $programId, 'quiz', $quizId, ['type' => 'quiz_submit', 'answers' => $request->input('answers')]);
    }

    private function send(Request $request, string $programId, string $kind, string $itemId, array $event): JsonResponse
    {
        $phaseId = $this->phaseOf($programId, $kind, $itemId);

        return response()->json($this->handler->handle($request->user(), $programId, ['phaseId' => $phaseId] + $event));
    }

    private function phaseOf(string $programId, string $kind, string $itemId): string
    {
        foreach ($this->content->program($programId)->data()['phases'] ?? [] as $phase) {
            $items = match ($kind) {
                'lessons', 'videos' => $phase[$kind] ?? [],
                default => isset($phase[$kind]) ? [$phase[$kind]] : [],
            };
            foreach ($items as $item) {
                if (($item['id'] ?? null) === $itemId) {
                    return $phase['id'];
                }
            }
        }

        $name = ['lessons' => 'lesson', 'videos' => 'video', 'activity' => 'activity', 'quiz' => 'quiz'][$kind];

        throw new ApiException("unknown_{$name}", "We couldn't find that {$name}.", 404);
    }
}
