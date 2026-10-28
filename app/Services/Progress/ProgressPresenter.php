<?php

namespace App\Services\Progress;

use App\Domain\ProgressionService;
use App\Models\QuizAttempt;
use App\Support\Iso;
use stdClass;

/**
 * Builds the `ProgramProgress` JSON.
 *
 * !! The contract says this must equal UserProgress.toJson() in the app's user_progress.dart.
 * !! That file wasn't in the handoff, so this is a proposed shape. Once we have the Dart file,
 * !! adjust ONLY this class - nothing else depends on the output shape.
 */
class ProgressPresenter
{
    public function present(ProgressionService $rules, LoadedProgress $p, ?string $contentVersion): array
    {
        $phases = [];

        foreach ($rules->phaseIds() as $phaseId) {
            $phase = $rules->phase($phaseId);
            $completion = $p->phaseCompletions->get($phaseId);

            $lessons = [];
            foreach ($phase['lessons'] ?? [] as $lesson) {
                if ($read = $p->lessonReads->get($lesson['id'])) {
                    $lessons[$lesson['id']] = ['readAt' => Iso::utc($read->read_at)];
                }
            }

            $videos = [];
            foreach ($phase['videos'] ?? [] as $video) {
                if ($row = $p->videos->get($video['id'])) {
                    $videos[$video['id']] = [
                        'position' => $row->position,
                        'watched' => $row->watched,
                        'watchedSeconds' => $rules->watchedSeconds($row->watched),
                        'duration' => $video['duration'],
                        'percent' => $rules->watchedPercent($row->watched, $video['duration']),
                        'completedAt' => Iso::utc($row->completed_at),
                    ];
                }
            }

            $activityRow = $p->activities->get($phaseId);
            $attempts = $p->quizAttempts->get($phaseId, collect());
            $firstPass = $attempts->first(fn (QuizAttempt $a) => $a->passed);

            $phases[$phaseId] = [
                'unlocked' => $rules->isUnlocked($phaseId, $p->state),
                'completedAt' => Iso::utc($completion?->completed_at),
                'pointsAwarded' => $completion?->points,
                'counts' => $rules->counts($phaseId, $p->state),
                'lessons' => $lessons ?: new stdClass,
                'videos' => $videos ?: new stdClass,
                'activity' => $activityRow ? [
                    'answers' => $activityRow->answers,
                    'complete' => $activityRow->completed_at !== null,
                    'completedAt' => Iso::utc($activityRow->completed_at),
                    'updatedAt' => Iso::utc($activityRow->updated_at),
                ] : null,
                'quiz' => [
                    'attempts' => $attempts->map(fn (QuizAttempt $a) => [
                        'answers' => $a->answers,
                        'correct' => $a->correct,
                        'total' => $a->total,
                        'score' => $a->score,
                        'passed' => $a->passed,
                        'submittedAt' => Iso::utc($a->created_at),
                    ])->values()->all(),
                    'passed' => $firstPass !== null,
                    'passedAt' => Iso::utc($firstPass?->created_at),
                    'bestScore' => $attempts->max('score'),
                ],
            ];
        }

        return [
            'programId' => $rules->programId(),
            'contentVersion' => $contentVersion,
            'phases' => $phases ?: new stdClass,
            'phaseCompleted' => $p->phaseCompletions->map(fn ($c) => Iso::utc($c->completed_at))->all() ?: new stdClass,
            'points' => (int) $p->phaseCompletions->sum('points'),
            'programCompletedAt' => Iso::utc($p->programCompletion?->completed_at),
        ];
    }
}
