<?php

namespace App\Services\Progress;

use App\Domain\ProgressState;
use App\Models\ActivityResponse;
use App\Models\LessonRead;
use App\Models\PhaseCompletion;
use App\Models\ProgramCompletion;
use App\Models\QuizAttempt;
use App\Models\VideoProgress;

class ProgressRepository
{
    public function load(string $userId, string $programId): LoadedProgress
    {
        $scope = fn ($q) => $q->where('user_id', $userId)->where('program_id', $programId);

        $lessonReads = LessonRead::query()->tap($scope)->get()->keyBy('lesson_id');
        $videos = VideoProgress::query()->tap($scope)->get()->keyBy('video_id');
        $activities = ActivityResponse::query()->tap($scope)->get()->keyBy('phase_id');
        $attempts = QuizAttempt::query()->tap($scope)->orderBy('created_at')->orderBy('id')->get()->groupBy('phase_id');
        $completions = PhaseCompletion::query()->tap($scope)->get()->keyBy('phase_id');
        $programCompletion = ProgramCompletion::query()->tap($scope)->first();

        $state = new ProgressState(
            lessonsRead: $lessonReads->map(fn (LessonRead $r) => $r->read_at)->all(),
            videos: $videos->map(fn (VideoProgress $v) => [
                'watched' => $v->watched,
                'position' => $v->position,
                'completedAt' => $v->completed_at,
            ])->all(),
            activities: $activities->map(fn (ActivityResponse $a) => ['completedAt' => $a->completed_at])->all(),
            quizAttempts: $attempts->map(fn ($list) => $list->map(fn (QuizAttempt $q) => [
                'passed' => $q->passed,
                'score' => $q->score,
                'createdAt' => $q->created_at,
            ])->values()->all())->all(),
            phaseCompleted: $completions->map(fn (PhaseCompletion $c) => [
                'completedAt' => $c->completed_at,
                'points' => $c->points,
            ])->all(),
            programCompletedAt: $programCompletion?->completed_at,
        );

        return new LoadedProgress($state, $lessonReads, $videos, $activities, $attempts, $completions, $programCompletion);
    }
}
