<?php

namespace App\Services\Progress;

use App\Domain\ProgressionService;
use App\Exceptions\ApiException;
use App\Models\ActivityResponse;
use App\Models\LessonRead;
use App\Models\PhaseCompletion;
use App\Models\ProgramCompletion;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Models\VideoProgress;
use App\Services\ContentRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * POST /me/programs/{programId}/events
 *
 * The client sends EVENTS only - never points or completion flags.
 * Everything runs in one transaction with the user row locked, so concurrent
 * events from the same user are serialised (no double awards, no lost bitmap merges).
 */
class ProgressEventHandler
{
    public function __construct(
        private ContentRepository $content,
        private ProgressRepository $repository,
        private ProgressPresenter $presenter,
    ) {}

    /** @return array{progress: array, completion: ?array} */
    public function handle(User $user, string $programId, array $event): array
    {
        $program = $this->content->program($programId);
        $rules = new ProgressionService($program->data(), $this->content->config());

        $event = $this->validate($event);
        $phaseId = $event['phaseId'];

        if (! $rules->hasPhase($phaseId)) {
            throw ApiException::unknown('phaseId', 'phase');
        }

        return DB::transaction(function () use ($user, $programId, $rules, $event, $phaseId, $program) {
            User::whereKey($user->id)->lockForUpdate()->first();

            $loaded = $this->repository->load($user->id, $programId);

            // Rule 1: locked phases reject every event.
            if (! $rules->isUnlocked($phaseId, $loaded->state)) {
                throw ApiException::phaseLocked($phaseId);
            }

            $scope = ['user_id' => $user->id, 'program_id' => $programId, 'phase_id' => $phaseId];

            match ($event['type']) {
                'lesson_read' => $this->lessonRead($rules, $scope, $event),
                'video_progress' => $this->videoProgress($rules, $scope, $event),
                'activity_save' => $this->activitySave($rules, $scope, $event),
                'quiz_submit' => $this->quizSubmit($rules, $scope, $event),
            };

            // Rule 5: award completion (and points) exactly once.
            $loaded = $this->repository->load($user->id, $programId);
            $completion = null;

            if (! $loaded->state->isPhaseCompleted($phaseId) && $rules->requirementsMet($phaseId, $loaded->state)) {
                $points = $rules->pointsFor($phaseId);

                // Unique (user, program, phase) => a second insert is silently ignored.
                $inserted = PhaseCompletion::insertOrIgnore([
                    'user_id' => $user->id,
                    'program_id' => $programId,
                    'phase_id' => $phaseId,
                    'points' => $points,
                    'completed_at' => now(),
                ]);

                if ($inserted === 1) {
                    $loaded = $this->repository->load($user->id, $programId);
                    $programComplete = $rules->allPhasesComplete($loaded->state);

                    if ($programComplete) {
                        ProgramCompletion::insertOrIgnore([
                            'user_id' => $user->id,
                            'program_id' => $programId,
                            'completed_at' => now(),
                        ]);
                        $loaded = $this->repository->load($user->id, $programId);
                    }

                    // Rule 6: PhaseCompletion
                    $completion = [
                        'phaseId' => $phaseId,
                        'pointsAwarded' => $points,
                        'nextPhaseId' => $rules->nextPhaseId($phaseId),
                        'programComplete' => $programComplete,
                    ];
                }
            }

            return [
                'progress' => $this->presenter->present($rules, $loaded, $program->content_version),
                'completion' => $completion,
            ];
        });
    }

    private function validate(array $event): array
    {
        $type = $event['type'] ?? null;

        $rules = [
            'type' => ['required', 'string', 'in:lesson_read,video_progress,activity_save,quiz_submit'],
            'phaseId' => ['required', 'string', 'max:64'],
        ];

        $rules += match ($type) {
            'lesson_read' => ['lessonId' => ['required', 'string', 'max:64']],
            'video_progress' => [
                'videoId' => ['required', 'string', 'max:64'],
                'position' => ['required', 'numeric', 'min:0'],
                'watched' => ['present', 'nullable', 'string', 'max:20000', 'regex:/^[01]*$/'],
            ],
            'activity_save' => [
                'complete' => ['required', 'boolean'],
                'answers' => ['present', 'array', 'max:50'],
                'answers.*' => ['array'],
                'answers.*.text' => ['nullable', 'string', 'max:5000'],
                'answers.*.chips' => ['nullable', 'array', 'max:30'],
                'answers.*.chips.*' => ['string', 'max:100'],
            ],
            'quiz_submit' => [
                'answers' => ['required', 'array', 'max:100'],
                'answers.*' => ['required', 'integer', 'min:0'],
            ],
            default => [],
        };

        return Validator::make($event, $rules, [
            'type.in' => 'Unknown event type.',
        ])->validate();
    }

    private function lessonRead(ProgressionService $rules, array $scope, array $event): void
    {
        if (! $rules->lesson($scope['phase_id'], $event['lessonId'])) {
            throw ApiException::unknown('lessonId', 'lesson');
        }

        // First read time is kept; re-reading never changes it.
        LessonRead::insertOrIgnore($scope + ['lesson_id' => $event['lessonId'], 'read_at' => now()]);
    }

    private function videoProgress(ProgressionService $rules, array $scope, array $event): void
    {
        $video = $rules->video($scope['phase_id'], $event['videoId'])
            ?? throw ApiException::unknown('videoId', 'video');

        $duration = (int) $video['duration'];
        $row = VideoProgress::firstOrNew($scope + ['video_id' => $event['videoId']]);
        $existing = $row->exists ? $row->watched : '';

        // Rule 2: OR-merge, never clear bits. Sized by the content's duration, not the client's.
        $merged = $rules->mergeWatched($existing, (string) ($event['watched'] ?? ''), $duration);

        // Optional anti-tamper cap (config mindease.progress.video_cap_enabled).
        if (config('mindease.progress.video_cap_enabled') && $row->exists && $row->last_event_at) {
            $elapsed = max(0, now()->getTimestamp() - $row->last_event_at->getTimestamp());
            $allowed = (int) floor($elapsed * config('mindease.progress.video_cap_factor'))
                + (int) config('mindease.progress.video_cap_grace_seconds');
            $merged = $rules->capNewSeconds($existing, $merged, $allowed);
        }

        $row->watched = $merged;
        $row->position = min((float) $event['position'], (float) $duration);
        $row->last_event_at = now();

        if ($row->completed_at === null && $rules->isVideoWatched($merged, $duration)) {
            $row->completed_at = now();
        }

        $row->save();
    }

    private function activitySave(ProgressionService $rules, array $scope, array $event): void
    {
        $activity = $rules->activity($scope['phase_id'])
            ?? throw ApiException::unknown('phaseId', 'activity');

        $promptIds = array_column($activity['prompts'] ?? [], 'id');
        $answers = [];

        foreach ($event['answers'] as $promptId => $answer) {
            if (! in_array((string) $promptId, $promptIds, true)) {
                throw new ApiException('unknown_prompt', 'That question isn\'t part of this activity.', 422, "answers.{$promptId}");
            }
            $answers[(string) $promptId] = [
                'text' => $answer['text'] ?? '',
                'chips' => array_values($answer['chips'] ?? []),
            ];
        }

        $hasAnswer = collect($answers)->contains(
            fn ($a) => trim((string) $a['text']) !== '' || $a['chips'] !== []
        );

        $row = ActivityResponse::firstOrNew($scope);

        // Rule 3: complete needs >= 1 non-empty answer.
        if ($event['complete'] && ! $hasAnswer && $row->completed_at === null) {
            throw new ApiException('activity_empty', 'Answer at least one question before completing this activity.', 422, 'answers');
        }

        $row->activity_id = $activity['id'];
        $row->answers = json_decode(json_encode($answers ?: new \stdClass));

        // Once complete, it stays complete; later saves only update answers.
        if ($row->completed_at === null && $event['complete']) {
            $row->completed_at = now();
        }

        $row->save();
    }

    private function quizSubmit(ProgressionService $rules, array $scope, array $event): void
    {
        $quiz = $rules->quiz($scope['phase_id'])
            ?? throw ApiException::unknown('phaseId', 'quiz');

        $questions = $quiz['questions'] ?? [];
        $answers = array_values(array_map('intval', $event['answers']));

        if (count($answers) !== count($questions)) {
            throw new ApiException('quiz_incomplete', 'Please answer every question before submitting.', 422, 'answers');
        }

        foreach ($questions as $i => $question) {
            if ($answers[$i] >= count($question['options'] ?? [])) {
                throw new ApiException('invalid_answer', 'One of the answers isn\'t a valid option.', 422, "answers.{$i}");
            }
        }

        // Rule 4: score server-side; every attempt stored; first pass = earliest passed attempt.
        $result = $rules->scoreQuiz($scope['phase_id'], $answers);

        QuizAttempt::create($scope + [
            'quiz_id' => $quiz['id'],
            'answers' => $answers,
            'created_at' => now(),
        ] + $result);
    }
}
