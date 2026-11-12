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
     * Saves the user's answers to a phase's reflection activity, as a draft or as a submit.
     *
     * ### Where the ids come from
     * Both come from `GET /programs/{programId}`:
     * - `activityId` = `phases[].activity.id` (one per phase: `p1a`, `p2a`, `p3a`, `p4a`).
     * - Each key in `answers` = a prompt id from `phases[].activity.prompts[].id` (`q1`, `q2`, …).
     *
     * ### What to send for each prompt
     * Look at the prompt's `responseType`:
     *
     * | `responseType` | The app shows | Send |
     * |---|---|---|
     * | `text` | A text box | `{ "text": "…" }` |
     * | `chips_and_text` | The prompt's `chips` as tappable tags (any number) **and** a text box | `{ "chips": ["Head"], "text": "…" }` (either or both) |
     *
     * Send the `chips` values exactly as they appear in that prompt's `chips` list (the server stores them as
     * sent and doesn't check them against the list). Every prompt is optional: leave out the ones the user
     * skipped.
     *
     * ### Draft or submit
     * - `complete: false` saves a draft (the user left the screen, or autosave). The activity is not complete.
     * - `complete: true` submits (the Submit button). Needs at least one answer with text or a chip.
     * - Once complete it stays complete. Later saves only update the answers, even with `complete: false`.
     * - Each save replaces the saved answers, so always send every answer the user has given, not only the
     *   changed one.
     *
     * ### Example (phase 2 activity)
     * `POST /v1/me/programs/mhfa/activities/p2a`
     * ```json
     * {
     *   "answers": {
     *     "q1": { "chips": ["Head", "Jaw"], "text": "I get headaches before meetings" },
     *     "q2": { "text": "I sleep less" }
     *   },
     *   "complete": true
     * }
     * ```
     *
     * ### Response `200`
     * `{ progress, completion }`.
     * - `progress.phases.<phaseId>.activity` = `{ answers, complete, completedAt, updatedAt }`. Use it to show
     *   saved answers again (also returned by `GET /me/programs/{programId}/progress`).
     * - `completion` is `null`, unless this save finished the whole phase (lessons, videos, activity and quiz):
     *   then show the phase completion sheet.
     *
     * ### Errors
     * - `401` no or expired access token.
     * - `403 phase_locked` the phase isn't unlocked yet.
     * - `404 unknown_activity` no activity with this id; `404 program_not_found` wrong `programId`.
     * - `422 unknown_prompt` (`field: answers.<promptId>`) a key in `answers` isn't a prompt of this activity.
     * - `422 activity_empty` (`field: answers`) `complete: true` with no text or chip in any answer.
     * - `422 validation_failed` wrong types or lengths (see the body fields).
     */
    public function activity(Request $request, string $programId, string $activityId): JsonResponse
    {
        $phaseId = $this->phaseOf($programId, 'activity', $activityId);

        // Top-level rules here so the API docs show the body; ProgressEventHandler re-checks everything,
        // including each answer (text up to 5000 chars, chips up to 30 x 100 chars).
        $data = $request->validate([
            /**
             * `false` = save a draft, `true` = submit. Once complete, the activity stays complete.
             *
             * @example true
             */
            'complete' => ['required', 'boolean'],
            /**
             * Object keyed by prompt id (`phases[].activity.prompts[].id`), one entry per answered prompt, up to 50.
             * Each value is `{ text?, chips? }`: `text` = what the user wrote (up to 5000 characters); `chips` = tags
             * the user tapped, only for `chips_and_text` prompts (up to 30, each up to 100 characters). Send `{}` to
             * save an empty draft.
             *
             * @var array<string, array{text?: string|null, chips?: list<string>|null}>
             *
             * @example {"q1": {"chips": ["Head", "Jaw"], "text": "I get headaches before meetings"}, "q2": {"text": "I sleep less"}}
             */
            'answers' => ['present', 'array', 'max:50'],
        ]);

        return response()->json($this->handler->handle($request->user(), $programId, [
            'phaseId' => $phaseId, 'type' => 'activity_save', 'answers' => $data['answers'], 'complete' => $data['complete'],
        ]));
    }

    /**
     * Submit a quiz.
     *
     * Sends the user's answers to a phase's quiz. The server scores it and stores every attempt.
     *
     * ### Where the ids come from
     * Both come from `GET /programs/{programId}`:
     * - `quizId` = `phases[].quiz.id` (one per phase: `p1q`, `p2q`, `p3q`, `p4q`).
     * - Each key in `answers` = a question id from `phases[].quiz.questions[].id` (`p1q-1`, `p1q-2`, …).
     *
     * ### What to send
     * `answers` is an object: **key = question id, value = the 0-based index of the option the user picked** in
     * that question's `options`. Every question must be answered.
     * - `mc` question with options `["A", "B", "C", "D"]`: picking "B" = `1`.
     * - `tf` question (`options: ["True", "False"]`): True = `0`, False = `1`.
     *
     * ```json
     * { "answers": { "p1q-1": 1, "p1q-2": 0, "p1q-3": 1, "p1q-4": 0, "p1q-5": 1 } }
     * ```
     *
     * The older list form (indexes in question order, `{ "answers": [1, 0, 1, 0, 1] }`) still works.
     *
     * ### Scoring
     * `score = round(correct / total * 100)`; passed when `score >= 80` (or the quiz's `passThreshold`). Retakes
     * are unlimited; the quiz counts for the phase once any attempt passed.
     *
     * ### Response `200`
     * `{ progress, completion }`.
     * - The result of this attempt (`answers`, `correct`, `total`, `score`, `passed`) is the last item of
     *   `progress.phases.<phaseId>.quiz.attempts`.
     * - `completion` is `null`, unless this submit finished the whole phase: then show the phase completion sheet.
     * - `GET /programs/{programId}` then shows `is_completed`, `is_passed` and each question's `user_answer`.
     *
     * ### Errors
     * - `401` no or expired access token.
     * - `403 phase_locked` the phase isn't unlocked yet.
     * - `404 unknown_quiz` no quiz with this id; `404 program_not_found` wrong `programId`.
     * - `422 quiz_incomplete` (`field: answers.<questionId>`) a question has no answer.
     * - `422 unknown_question` (`field: answers.<key>`) a key isn't a question of this quiz.
     * - `422 invalid_answer` (`field: answers.<questionId>`) the index isn't one of that question's options.
     * - `422 validation_failed` an answer isn't a whole number of 0 or more.
     */
    public function quiz(Request $request, string $programId, string $quizId): JsonResponse
    {
        $phaseId = $this->phaseOf($programId, 'quiz', $quizId);

        // Top-level rule here so the API docs show the body; ProgressEventHandler re-checks every answer.
        $data = $request->validate([
            /**
             * Object keyed by question id (`phases[].quiz.questions[].id`); each value is the 0-based index of the
             * chosen option in that question's `options`. Every question must be answered.
             *
             * @var array<string, int>
             *
             * @example {"p1q-1": 1, "p1q-2": 0, "p1q-3": 1, "p1q-4": 0, "p1q-5": 1}
             */
            'answers' => ['required', 'array', 'max:100'],
        ]);

        return response()->json($this->handler->handle($request->user(), $programId, [
            'phaseId' => $phaseId, 'type' => 'quiz_submit', 'answers' => $data['answers'],
        ]));
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
