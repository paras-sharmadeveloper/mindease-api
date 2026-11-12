<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\ActivityResponse;
use App\Models\LessonRead;
use App\Models\Program;
use App\Models\QuizAttempt;
use App\Models\VideoProgress;
use App\Services\ContentRepository;
use App\Support\ContentResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Content endpoints return the app's JSON files verbatim, with ETag / X-Content-Version.
 * Send If-None-Match to get 304 when nothing changed.
 */
class ContentController extends Controller
{
    public function __construct(private ContentRepository $content) {}

    /**
     * Get app settings.
     *
     * Same shape as `assets/content/app_config.json`: pass mark, points per phase, video completion %, phase requirements.
     */
    #[Group('App settings & help', 'Settings and support numbers the app shows. Cacheable with ETag / If-None-Match.', weight: 3)]
    public function config(Request $request): Response
    {
        return $this->document($request, ContentRepository::CONFIG);
    }

    /**
     * Get the onboarding questionnaire.
     *
     * The questions shown after sign-up. Same shape as `onboarding_questionnaire.json`. Save the user's answers with `PUT /me/assessment`.
     */
    #[Group('Onboarding questionnaire', 'The onboarding questions (content) and the user\'s saved answers.', weight: 5)]
    public function onboardingQuestionnaire(Request $request): Response
    {
        return $this->document($request, ContentRepository::ONBOARDING);
    }

    /**
     * List programs.
     *
     * Programs for the Programs tab: `[{ id, title, short, status, displayOrder, coverImage }]`, sorted by `displayOrder`.
     */
    #[Group('Programs', 'Program content (phases, lessons, videos, activity, quiz). Cacheable with ETag / If-None-Match.', weight: 4)]
    public function programs(): JsonResponse
    {
        $programs = Program::where('status', '!=', 'hidden')
            ->orderBy('display_order')->orderBy('title')
            ->get()
            ->map(fn (Program $p) => [
                'id' => $p->id,
                'title' => $p->title,
                'short' => $p->short,
                'status' => $p->status,
                'displayOrder' => $p->display_order,
                'coverImage' => $p->cover_image,
            ]);

        return response()->json($programs);
    }

    /**
     * Get a program.
     *
     * The full program: phases, lessons, videos, activity and quiz. Same shape as `mhfa_program.json`, plus the
     * signed-in user's status:
     *
     * - **Lessons:** `is_read` (true/false), right after `intro`. Set by `POST /me/programs/{programId}/lessons/{lessonId}/read`.
     * - **Videos:** `is_video_watched` (true/false), last field. True once the video is complete through
     *   `POST /me/programs/{programId}/videos/{videoId}/progress` (90% of its seconds played).
     * - **Activity:** `is_draft` and `is_completed` (true/false), right after `instructions`. Both `false` = never
     *   saved; `is_draft: true` = saved with `complete: false` and not submitted yet; `is_completed: true` = submitted
     *   (stays true; `is_draft` is then false). Set by `POST /me/programs/{programId}/activities/{activityId}`.
     * - **Activity prompts:** `answer`, last field: the user's saved answer `{ text, chips }` for that prompt
     *   (draft or submitted), or `null` if they haven't answered it. Use it to fill the activity screen again.
     * - **Quiz:** `is_completed` (submitted at least once) and `is_passed` (passed at least once: this is what
     *   counts for the phase), right after the quiz `id`. Set by `POST /me/programs/{programId}/quizzes/{quizId}/submit`.
     * - **Quiz questions:** `user_answer`, last field: the 0-based option index the user picked in their latest
     *   attempt, or `null` before the first submit. (`answer` is the correct option, as before.)
     *
     * The answers are private: only the signed-in user gets their own. The ETag is per user and changes whenever
     * any of this changes.
     */
    #[Group('Programs', 'Program content (phases, lessons, videos, activity, quiz). Cacheable with ETag / If-None-Match.', weight: 4)]
    public function program(Request $request, string $programId): Response
    {
        $program = $this->content->program($programId);
        $userId = $request->user()->id;

        $readIds = LessonRead::where('user_id', $userId)
            ->where('program_id', $program->id)
            ->orderBy('lesson_id')
            ->pluck('lesson_id')
            ->all();

        $watchedIds = VideoProgress::where('user_id', $userId)
            ->where('program_id', $program->id)
            ->whereNotNull('completed_at')
            ->orderBy('video_id')
            ->pluck('video_id')
            ->all();

        $activities = ActivityResponse::where('user_id', $userId)
            ->where('program_id', $program->id)
            ->orderBy('phase_id')
            ->get()
            ->keyBy('activity_id');

        // Per quiz: latest attempt + whether any attempt passed.
        $quizzes = [];
        foreach (QuizAttempt::where('user_id', $userId)->where('program_id', $program->id)->orderBy('id')->get() as $attempt) {
            $quizzes[$attempt->quiz_id] = [
                'latest' => $attempt,
                'passed' => ($quizzes[$attempt->quiz_id]['passed'] ?? false) || $attempt->passed,
            ];
        }

        $json = $this->withUserStatus($program->body, array_flip($readIds), array_flip($watchedIds), $activities->all(), $quizzes);

        // Hash of the per-user parts (answers included, so two saves in the same second still change it).
        $activityState = $activities->map(fn (ActivityResponse $a) => [
            $a->activity_id, $a->completed_at?->getTimestamp(), $a->answers,
        ])->values();
        $quizState = array_map(fn ($q) => [$q['latest']->id, $q['passed']], $quizzes);
        $etag = '"'.sha1($program->etag.'|'.implode(',', $readIds).'|'.implode(',', $watchedIds).'|'.json_encode($activityState).'|'.json_encode($quizState)).'"';

        return ContentResponse::make($request, $json, $etag, $program->content_version);
    }

    /**
     * Adds the user's status to the program JSON (see program()). Decodes as objects so `{}` stays `{}`.
     *
     * @param  array<string, int>  $readIds  lesson ids the user has read (as keys)
     * @param  array<string, int>  $watchedIds  video ids the user has completed (as keys)
     * @param  array<string, ActivityResponse>  $activities  saved activities, keyed by activity id
     * @param  array<string, array{latest: QuizAttempt, passed: bool}>  $quizzes  keyed by quiz id
     */
    private function withUserStatus(string $body, array $readIds, array $watchedIds, array $activities, array $quizzes): string
    {
        $data = json_decode($body);
        if (! is_object($data) || ! is_array($data->phases ?? null)) {
            return $body;
        }

        foreach ($data->phases as $phase) {
            if (! is_object($phase)) {
                continue;
            }
            foreach (is_array($phase->lessons ?? null) ? $phase->lessons : [] as $i => $lesson) {
                if (is_object($lesson)) {
                    $phase->lessons[$i] = $this->withKeys($lesson, ['is_read' => isset($readIds[$lesson->id ?? ''])], after: 'intro');
                }
            }
            foreach (is_array($phase->videos ?? null) ? $phase->videos : [] as $i => $video) {
                if (is_object($video)) {
                    $phase->videos[$i] = $this->withKeys($video, ['is_video_watched' => isset($watchedIds[$video->id ?? ''])]);
                }
            }
            if (is_object($phase->activity ?? null)) {
                $phase->activity = $this->withActivityStatus($phase->activity, $activities[$phase->activity->id ?? ''] ?? null);
            }
            if (is_object($phase->quiz ?? null)) {
                $phase->quiz = $this->withQuizStatus($phase->quiz, $quizzes[$phase->quiz->id ?? ''] ?? null);
            }
        }

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** `is_draft` / `is_completed` after `instructions`, and each prompt's saved `answer` (or null). */
    private function withActivityStatus(object $activity, ?ActivityResponse $saved): object
    {
        $completed = $saved?->completed_at !== null;
        $answers = $saved?->answers ?? new \stdClass;

        if (is_array($activity->prompts ?? null)) {
            foreach ($activity->prompts as $i => $prompt) {
                if (! is_object($prompt)) {
                    continue;
                }
                $answer = $answers->{$prompt->id ?? ''} ?? null;
                $activity->prompts[$i] = $this->withKeys($prompt, ['answer' => is_object($answer) ? [
                    'text' => (string) ($answer->text ?? ''),
                    'chips' => array_values((array) ($answer->chips ?? [])),
                ] : null]);
            }
        }

        return $this->withKeys($activity, [
            'is_draft' => $saved !== null && ! $completed,
            'is_completed' => $completed,
        ], after: 'instructions');
    }

    /**
     * `is_completed` / `is_passed` after the quiz `id`, and each question's `user_answer` from the latest attempt.
     * Attempts store the chosen option per question in question order.
     *
     * @param  array{latest: QuizAttempt, passed: bool}|null  $state
     */
    private function withQuizStatus(object $quiz, ?array $state): object
    {
        $chosen = $state ? array_values((array) $state['latest']->answers) : [];

        if (is_array($quiz->questions ?? null)) {
            foreach ($quiz->questions as $i => $question) {
                if (is_object($question)) {
                    $quiz->questions[$i] = $this->withKeys($question, [
                        'user_answer' => array_key_exists($i, $chosen) ? (int) $chosen[$i] : null,
                    ]);
                }
            }
        }

        return $this->withKeys($quiz, [
            'is_completed' => $state !== null,
            'is_passed' => $state['passed'] ?? false,
        ], after: 'id');
    }

    /**
     * Returns a copy of `$item` with `$values` placed right after the key `$after` (or last when it isn't there).
     * Keys that already exist in `$item` with these names are replaced.
     *
     * @param  array<string, mixed>  $values
     */
    private function withKeys(object $item, array $values, ?string $after = null): object
    {
        $out = new \stdClass;
        $placed = false;
        foreach (get_object_vars($item) as $key => $v) {
            if (array_key_exists($key, $values)) {
                continue;
            }
            $out->{$key} = $v;
            if ($key === $after) {
                foreach ($values as $k => $value) {
                    $out->{$k} = $value;
                }
                $placed = true;
            }
        }
        if (! $placed) {
            foreach ($values as $k => $value) {
                $out->{$k} = $value;
            }
        }

        return $out;
    }

    /**
     * Get support resources (Get Help).
     *
     * Support numbers for the Get Help screen. Same shape as `support_resources_au.json`. `?region=AU` (default AU).
     */
    #[Group('App settings & help', 'Settings and support numbers the app shows. Cacheable with ETag / If-None-Match.', weight: 3)]
    public function supportResources(Request $request): Response
    {
        $region = strtolower($request->validate(['region' => ['nullable', 'string', 'alpha', 'size:2']])['region'] ?? 'AU');

        return $this->document($request, 'support_resources.'.$region);
    }

    private function document(Request $request, string $key): Response
    {
        $doc = $this->content->document($key);

        return ContentResponse::make($request, $doc->body, $doc->etag, $doc->content_version);
    }
}
