<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Support\Iso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use stdClass;

/**
 * Questionnaire answers. SENSITIVE: encrypted at rest, owner-only (always the token's user),
 * never logged, never sent to analytics.
 */
class AssessmentController extends Controller
{
    /** { answers, step, completedAt, nextStepsSeen, questionnaireVersion } - defaults if nothing saved yet. */
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->present($request->user()->assessment));
    }

    /** Saved on every answer so the user can resume. Full replace of the document. */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'answers' => ['present', 'array'],
            'step' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'completedAt' => ['nullable', 'date'],
            'nextStepsSeen' => ['nullable', 'boolean'],
            'questionnaireVersion' => ['nullable', 'string', 'max:64'],
        ]);

        if (strlen(json_encode($data['answers'])) > 32_768) {
            abort(413, 'Answers are too large.');
        }

        // Keep `answers` an object: {} decodes to [] in PHP, so re-decode as objects.
        $answers = json_decode(json_encode($data['answers'] ?: new stdClass));

        $assessment = Assessment::updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'answers' => $answers,
                'step' => $data['step'] ?? 0,
                'completed_at' => $data['completedAt'] ?? null,
                'next_steps_seen' => $data['nextStepsSeen'] ?? false,
                'questionnaire_version' => $data['questionnaireVersion'] ?? null,
            ],
        );

        return response()->json($this->present($assessment));
    }

    private function present(?Assessment $a): array
    {
        return [
            'answers' => $a?->answers ?? new stdClass,
            'step' => $a?->step ?? 0,
            'completedAt' => Iso::utc($a?->completed_at),
            'nextStepsSeen' => $a?->next_steps_seen ?? false,
            'questionnaireVersion' => $a?->questionnaire_version,
        ];
    }
}
