<?php

namespace App\Services\Progress;

use App\Domain\ProgressState;
use App\Models\ProgramCompletion;
use Illuminate\Support\Collection;

/** DB rows for one user + program, plus the pure ProgressState built from them. */
final class LoadedProgress
{
    public function __construct(
        public ProgressState $state,
        public Collection $lessonReads,     // keyBy lesson_id
        public Collection $videos,          // keyBy video_id
        public Collection $activities,      // keyBy phase_id
        public Collection $quizAttempts,    // groupBy phase_id
        public Collection $phaseCompletions, // keyBy phase_id
        public ?ProgramCompletion $programCompletion,
    ) {}
}
