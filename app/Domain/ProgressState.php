<?php

namespace App\Domain;

use DateTimeInterface;

/**
 * Plain snapshot of one user's progress in one program. No framework code,
 * so ProgressionService can be unit-tested in isolation (mirrors the Dart domain layer).
 */
final class ProgressState
{
    /**
     * @param  array<string, DateTimeInterface>  $lessonsRead  lessonId => readAt
     * @param  array<string, array{watched: string, position: float, completedAt: ?DateTimeInterface}>  $videos  videoId => state
     * @param  array<string, array{completedAt: ?DateTimeInterface}>  $activities  phaseId => state
     * @param  array<string, list<array{passed: bool, score: int, createdAt: DateTimeInterface}>>  $quizAttempts  phaseId => attempts
     * @param  array<string, array{completedAt: DateTimeInterface, points: int}>  $phaseCompleted  phaseId => completion
     */
    public function __construct(
        public array $lessonsRead = [],
        public array $videos = [],
        public array $activities = [],
        public array $quizAttempts = [],
        public array $phaseCompleted = [],
        public ?DateTimeInterface $programCompletedAt = null,
    ) {}

    public function isPhaseCompleted(string $phaseId): bool
    {
        return isset($this->phaseCompleted[$phaseId]);
    }

    public function isLessonRead(string $lessonId): bool
    {
        return isset($this->lessonsRead[$lessonId]);
    }

    public function isVideoComplete(string $videoId): bool
    {
        return ($this->videos[$videoId]['completedAt'] ?? null) !== null;
    }

    public function isActivityComplete(string $phaseId): bool
    {
        return ($this->activities[$phaseId]['completedAt'] ?? null) !== null;
    }

    public function isQuizPassed(string $phaseId): bool
    {
        foreach ($this->quizAttempts[$phaseId] ?? [] as $attempt) {
            if ($attempt['passed']) {
                return true;
            }
        }

        return false;
    }
}
