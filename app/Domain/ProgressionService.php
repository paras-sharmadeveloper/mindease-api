<?php

namespace App\Domain;

use InvalidArgumentException;

/**
 * Server-side port of the app's ProgressionService (lib/features/program/domain/progression_service.dart).
 * Pure PHP: decides unlocks, counts, completion, quiz scoring and point values.
 * The backend is the source of truth; keep this in sync with the Dart rules.
 *
 *  - Phase 1 always open; phase N+1 needs phase N completed.
 *  - Phase complete = lessons read + videos watched + activity complete + quiz passed
 *    (each requirement switchable via app_config.phaseRequirements; an absent item counts as met).
 *  - Quiz pass: round(correct / total * 100) >= threshold (phase quiz.passThreshold, else config).
 *  - Video complete: seconds actually played / duration >= videoCompletePercent.
 *  - Points per phase: phase.pointsAwarded, else config.pointsPerPhase.
 */
final class ProgressionService
{
    /** @var array<string, array> */
    private array $phasesById = [];

    /** @var list<string> */
    private array $order = [];

    public function __construct(private array $program, private array $config)
    {
        foreach ($program['phases'] ?? [] as $phase) {
            $this->phasesById[$phase['id']] = $phase;
            $this->order[] = $phase['id'];
        }
    }

    public function programId(): string
    {
        return $this->program['id'];
    }

    /** @return list<string> */
    public function phaseIds(): array
    {
        return $this->order;
    }

    public function hasPhase(string $phaseId): bool
    {
        return isset($this->phasesById[$phaseId]);
    }

    public function phase(string $phaseId): array
    {
        return $this->phasesById[$phaseId] ?? throw new InvalidArgumentException("Unknown phase {$phaseId}");
    }

    public function isUnlocked(string $phaseId, ProgressState $state): bool
    {
        $index = array_search($phaseId, $this->order, true);

        if ($index === false) {
            return false;
        }

        return $index === 0 || $state->isPhaseCompleted($this->order[$index - 1]);
    }

    public function nextPhaseId(string $phaseId): ?string
    {
        $index = array_search($phaseId, $this->order, true);

        return $index === false ? null : ($this->order[$index + 1] ?? null);
    }

    public function isLastPhase(string $phaseId): bool
    {
        return end($this->order) === $phaseId;
    }

    public function allPhasesComplete(ProgressState $state): bool
    {
        foreach ($this->order as $id) {
            if (! $state->isPhaseCompleted($id)) {
                return false;
            }
        }

        return $this->order !== [];
    }

    // ---- Items --------------------------------------------------------------------------

    public function lesson(string $phaseId, string $lessonId): ?array
    {
        return $this->find($this->phase($phaseId)['lessons'] ?? [], $lessonId);
    }

    public function video(string $phaseId, string $videoId): ?array
    {
        return $this->find($this->phase($phaseId)['videos'] ?? [], $videoId);
    }

    public function activity(string $phaseId): ?array
    {
        return $this->phase($phaseId)['activity'] ?? null;
    }

    public function quiz(string $phaseId): ?array
    {
        return $this->phase($phaseId)['quiz'] ?? null;
    }

    // ---- Counts & completion ------------------------------------------------------------

    /**
     * @return array{lessons: array{done: int, total: int}, videos: array{done: int, total: int}, activity: bool, quiz: bool}
     */
    public function counts(string $phaseId, ProgressState $state): array
    {
        $phase = $this->phase($phaseId);

        $lessons = $phase['lessons'] ?? [];
        $videos = $phase['videos'] ?? [];

        return [
            'lessons' => [
                'done' => count(array_filter($lessons, fn ($l) => $state->isLessonRead($l['id']))),
                'total' => count($lessons),
            ],
            'videos' => [
                'done' => count(array_filter($videos, fn ($v) => $state->isVideoComplete($v['id']))),
                'total' => count($videos),
            ],
            'activity' => $state->isActivityComplete($phaseId),
            'quiz' => $state->isQuizPassed($phaseId),
        ];
    }

    public function requirementsMet(string $phaseId, ProgressState $state): bool
    {
        $req = $this->config['phaseRequirements'] ?? [];
        $c = $this->counts($phaseId, $state);

        if (($req['lessons'] ?? true) && $c['lessons']['done'] < $c['lessons']['total']) {
            return false;
        }
        if (($req['videos'] ?? true) && $c['videos']['done'] < $c['videos']['total']) {
            return false;
        }
        if (($req['activity'] ?? true) && $this->activity($phaseId) && ! $c['activity']) {
            return false;
        }
        if (($req['quiz'] ?? true) && $this->quiz($phaseId) && ! $c['quiz']) {
            return false;
        }

        return true;
    }

    public function pointsFor(string $phaseId): int
    {
        return (int) ($this->phase($phaseId)['pointsAwarded'] ?? $this->config['pointsPerPhase'] ?? 100);
    }

    // ---- Quiz ---------------------------------------------------------------------------

    public function passThreshold(string $phaseId): int
    {
        return (int) ($this->quiz($phaseId)['passThreshold'] ?? $this->config['passThresholdPercent'] ?? 80);
    }

    /**
     * Scores against the stored correct answers - the client's own verdict is never trusted.
     *
     * @param  list<int>  $answers  0-based option index per question, in question order
     * @return array{correct: int, total: int, score: int, threshold: int, passed: bool}
     */
    public function scoreQuiz(string $phaseId, array $answers): array
    {
        $questions = $this->quiz($phaseId)['questions'] ?? [];
        $total = count($questions);
        $correct = 0;

        foreach ($questions as $i => $question) {
            if (array_key_exists($i, $answers) && $answers[$i] === $question['answer']) {
                $correct++;
            }
        }

        // PHP round() is half-away-from-zero, same as Dart's num.round().
        $score = $total > 0 ? (int) round($correct / $total * 100) : 0;
        $threshold = $this->passThreshold($phaseId);

        return [
            'correct' => $correct,
            'total' => $total,
            'score' => $score,
            'threshold' => $threshold,
            'passed' => $total > 0 && $score >= $threshold,
        ];
    }

    // ---- Video --------------------------------------------------------------------------

    /**
     * OR-merge of the "watched" maps (1 char per second). Bits are never cleared.
     * The map is sized to the video's real duration from content, not the client.
     */
    public function mergeWatched(string $existing, string $incoming, int $duration): string
    {
        $merged = '';
        for ($i = 0; $i < $duration; $i++) {
            $merged .= (($existing[$i] ?? '0') === '1' || ($incoming[$i] ?? '0') === '1') ? '1' : '0';
        }

        return $merged;
    }

    /** Anti-tamper: keep at most $allowed newly-set seconds (the earliest ones). */
    public function capNewSeconds(string $existing, string $merged, int $allowed): string
    {
        $out = '';
        for ($i = 0, $n = strlen($merged); $i < $n; $i++) {
            $wasSet = ($existing[$i] ?? '0') === '1';
            $isNew = ! $wasSet && $merged[$i] === '1';

            if ($isNew && $allowed <= 0) {
                $out .= '0';

                continue;
            }
            if ($isNew) {
                $allowed--;
            }
            $out .= $merged[$i];
        }

        return $out;
    }

    public function watchedSeconds(string $watched): int
    {
        return substr_count($watched, '1');
    }

    public function isVideoWatched(string $watched, int $duration): bool
    {
        $percent = (int) ($this->config['videoCompletePercent'] ?? 90);

        // Integer comparison: watched/duration >= percent/100, without float drift.
        return $duration > 0 && $this->watchedSeconds($watched) * 100 >= $percent * $duration;
    }

    public function watchedPercent(string $watched, int $duration): int
    {
        return $duration > 0 ? (int) floor($this->watchedSeconds($watched) / $duration * 100) : 0;
    }

    private function find(array $items, string $id): ?array
    {
        foreach ($items as $item) {
            if (($item['id'] ?? null) === $id) {
                return $item;
            }
        }

        return null;
    }
}
