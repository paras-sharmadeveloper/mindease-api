<?php

namespace App\Http\Controllers\Api;

use App\Domain\RecommendationEngine;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Services\ContentRepository;
use App\Support\Iso;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Questionnaire answers. SENSITIVE: encrypted at rest, owner-only (always the token's user),
 * never logged, never sent to analytics.
 */
#[Group('Onboarding questionnaire', 'The onboarding questions (content) and the user\'s saved answers.', weight: 5)]
class AssessmentController extends Controller
{
    public const PROGRAM_ID = 'mhfa';

    /**
     * Get my questionnaire answers.
     *
     * `{ answers, step, completedAt, nextStepsSeen, questionnaireVersion, recommendation }`. `answers` holds every
     * question answered so far, from all steps. `recommendation` (null until something is answered) is what the
     * Results / "Your focus" screens show: the program phases to focus on, in order, with plain-language reasons.
     */
    public function show(Request $request, RecommendationEngine $engine, ContentRepository $content): JsonResponse
    {
        return response()->json($this->present(Assessment::find($request->user()->id), $engine, $content));
    }

    /**
     * Save my questionnaire answers.
     *
     * Send what changed on each step, e.g. `{ "answers": { "stress": "high" }, "step": 3 }`. Answers are **merged**
     * into the saved ones (a key sent as `null` removes that answer), so earlier steps are kept. Every other field is
     * optional and keeps its saved value when left out; send `completedAt` when the user finishes, and
     * `"completedAt": null` to start a retake. Sending the whole document every time also works.
     */
    public function update(Request $request, RecommendationEngine $engine, ContentRepository $content): JsonResponse
    {
        $data = $request->validate([
            'answers' => ['sometimes', 'array', 'max:50'],
            'step' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000'],
            'completedAt' => ['sometimes', 'nullable', 'date'],
            'nextStepsSeen' => ['sometimes', 'nullable', 'boolean'],
            'questionnaireVersion' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        foreach ($data['answers'] ?? [] as $key => $value) {
            $ok = $value === null || is_string($value)
                || (is_array($value) && array_is_list($value) && count($value) <= 30 && collect($value)->every(fn ($v) => is_string($v)));
            if (! $ok || strlen((string) $key) > 64) {
                throw new ApiException('validation_failed', 'Each answer must be an option value or a list of option values.', 422, "answers.{$key}");
            }
        }

        $assessment = DB::transaction(function () use ($request, $data) {
            $assessment = Assessment::lockForUpdate()->find($request->user()->id)
                ?? new Assessment(['user_id' => $request->user()->id, 'step' => 0, 'next_steps_seen' => false]);

            if (array_key_exists('answers', $data)) {
                $answers = (array) json_decode(json_encode($assessment->answers ?? new stdClass), true);
                foreach ($data['answers'] as $key => $value) {
                    if ($value === null) {
                        unset($answers[$key]);
                    } else {
                        $answers[$key] = $value;
                    }
                }
                if (strlen(json_encode($answers)) > 32_768) {
                    throw new ApiException('payload_too_large', 'Answers are too large.', 413, 'answers');
                }
                // Keep `answers` an object: {} decodes to [] in PHP, so re-decode as objects.
                $assessment->answers = json_decode(json_encode($answers ?: new stdClass));
            }
            $assessment->answers ??= new stdClass;

            $fields = ['step' => 'step', 'completedAt' => 'completed_at', 'nextStepsSeen' => 'next_steps_seen', 'questionnaireVersion' => 'questionnaire_version'];
            foreach ($fields as $key => $column) {
                if (array_key_exists($key, $data)) {
                    $assessment->{$column} = $data[$key] ?? ($key === 'step' ? 0 : ($key === 'nextStepsSeen' ? false : null));
                }
            }

            $assessment->save();

            return $assessment;
        });

        return response()->json($this->present($assessment, $engine, $content));
    }

    private function present(?Assessment $a, RecommendationEngine $engine, ContentRepository $content): array
    {
        $answers = $a?->answers ?? new stdClass;

        return [
            'answers' => $answers,
            'step' => $a?->step ?? 0,
            'completedAt' => Iso::utc($a?->completed_at),
            'nextStepsSeen' => $a?->next_steps_seen ?? false,
            'questionnaireVersion' => $a?->questionnaire_version,
            'recommendation' => self::recommendation((array) json_decode(json_encode($answers), true), $engine, $content),
        ];
    }

    /** Null until at least one question is answered. Shared with GET /me/home. */
    public static function recommendation(array $answers, RecommendationEngine $engine, ContentRepository $content): ?array
    {
        if ($answers === []) {
            return null;
        }

        $r = $engine->recommend($answers);
        try {
            $phases = collect($content->program(self::PROGRAM_ID)->data()['phases'] ?? [])->keyBy('id');
        } catch (ApiException) {
            $phases = collect();
        }

        $labels = config('mindease.focus_areas');

        return [
            'programId' => self::PROGRAM_ID,
            'focusPhases' => collect($r['reasonsByPhase'])->map(fn (array $reasons, string $phaseId) => [
                'phaseId' => $phaseId,
                'number' => $phases[$phaseId]['number'] ?? null,
                'title' => $phases[$phaseId]['title'] ?? null,
                'color' => $phases[$phaseId]['color'] ?? null,
                'reasons' => $reasons,
            ])->values(),
            'showSupport' => $r['showSupport'],
            'suggestProfessional' => $r['suggestProfessional'],
            'dailyMinutes' => $r['dailyMinutes'],
            'learningStyle' => $r['learningStyle'],
            'preferredKind' => $r['preferredKind'],
            'focusAreas' => collect($engine->focusAreas($answers))
                ->map(fn (string $key) => ['key' => $key, 'label' => $labels[$key] ?? $key])->values(),
        ];
    }
}
