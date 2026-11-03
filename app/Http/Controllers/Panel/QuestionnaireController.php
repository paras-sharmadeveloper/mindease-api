<?php

namespace App\Http\Controllers\Panel;

use App\Models\ContentDocument;
use App\Services\ContentImporter;
use App\Services\ContentRepository;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Validator;

/**
 * Admin: the onboarding questionnaire the app shows after sign-up (GET /questionnaires/onboarding in the app API).
 * Saves are live straight away. Users' saved answers are keyed by question id and option value (`v`),
 * so keep those stable; change the wording (`q`, `label`) freely.
 */
#[Group('Questionnaire', 'Admin only. Edit the onboarding questions the app shows after sign-up.', weight: 8)]
class QuestionnaireController extends PanelController
{
    public const TYPES = ['single', 'multi', 'mood'];

    /**
     * Get the questionnaire.
     *
     * The full document the app receives: `{ version, questions: [{ id, type, q, options: [{ v, label }], exclusive? }] }`
     * plus any other top-level fields the app's file has.
     */
    public function show(): JsonResponse
    {
        $doc = ContentDocument::find(ContentRepository::ONBOARDING);

        return response()->json(($doc?->data() ?? []) + ['questions' => []] + ['updatedAt' => $doc?->updated_at?->toIso8601ZuluString()]);
    }

    /**
     * Save the questionnaire.
     *
     * Body: `{ questions: [...] }`, the full ordered list (full replace). Other top-level fields of the document are kept.
     * `type`: `single` (one answer), `multi` (several), `mood` (mood scale). `exclusive` (multi only) names the option
     * value that clears the others, such as "none".
     */
    public function update(Request $request, ContentImporter $importer): JsonResponse
    {
        $validator = validator($request->all(), [
            'questions' => ['required', 'array', 'min:1', 'max:50'],
            'questions.*' => ['required', 'array'],
            'questions.*.id' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/', 'distinct'],
            'questions.*.type' => ['required', 'in:'.implode(',', self::TYPES)],
            'questions.*.q' => ['required', 'string', 'max:500'],
            'questions.*.options' => ['required', 'array', 'min:2', 'max:20'],
            'questions.*.options.*.v' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'questions.*.options.*.label' => ['required', 'string', 'max:200'],
            'questions.*.exclusive' => ['nullable', 'string'],
        ], [
            'questions.*.id.required' => 'Every question needs an ID.',
            'questions.*.id.regex' => 'Question IDs use lowercase letters, numbers and _ only.',
            'questions.*.type.in' => 'Choose single, multiple choice or mood scale.',
            'questions.*.options.*.v.required' => 'Every option needs a value.',
            'questions.*.id.distinct' => 'Each question needs its own ID.',
            'questions.*.q.required' => 'Every question needs its text.',
            'questions.*.options.min' => 'Every question needs at least 2 options.',
            'questions.*.options.*.v.regex' => 'Option values use lowercase letters, numbers and _ only.',
            'questions.*.options.*.label.required' => 'Every option needs a label.',
        ]);

        $validator->after(function (Validator $v) use ($request) {
            foreach ((array) $request->input('questions', []) as $i => $q) {
                $values = array_column(is_array($q['options'] ?? null) ? $q['options'] : [], 'v');
                if (count($values) !== count(array_unique($values))) {
                    $v->errors()->add("questions.$i.options", 'Option values must be different within a question.');
                }
                $exclusive = $q['exclusive'] ?? null;
                if ($exclusive !== null && $exclusive !== '') {
                    if (($q['type'] ?? null) !== 'multi') {
                        $v->errors()->add("questions.$i.exclusive", 'Only multi-choice questions can have an exclusive option.');
                    } elseif (! in_array($exclusive, $values, true)) {
                        $v->errors()->add("questions.$i.exclusive", 'The exclusive option must be one of the question\'s option values.');
                    }
                }
            }
        });
        $validator->validate();

        // Keep each question as sent (the app's own fields survive), minus an empty `exclusive`.
        $questions = array_map(function (array $q) {
            if (($q['exclusive'] ?? null) === null || $q['exclusive'] === '') {
                unset($q['exclusive']);
            }
            $q['options'] = array_values($q['options']);

            return $q;
        }, array_values($request->input('questions')));

        $doc = ContentDocument::find(ContentRepository::ONBOARDING)?->data() ?? [];
        $doc['questions'] = $questions;
        $importer->saveDocument(ContentRepository::ONBOARDING, $doc);

        return $this->show();
    }
}
