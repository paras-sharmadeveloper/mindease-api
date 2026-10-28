<?php

namespace Tests\Unit;

use App\Domain\ProgressionService;
use App\Domain\ProgressState;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/** Mirrors the app's ProgressionService unit tests (unlocks, scoring, seek-proof video, completion). */
class ProgressionServiceTest extends TestCase
{
    private array $program;

    private array $config;

    protected function setUp(): void
    {
        $dir = dirname(__DIR__, 2).'/database/content';
        $this->program = json_decode(file_get_contents("{$dir}/mhfa_program.json"), true);
        $this->config = json_decode(file_get_contents("{$dir}/app_config.json"), true);
    }

    private function rules(?array $config = null): ProgressionService
    {
        return new ProgressionService($this->program, $config ?? $this->config);
    }

    private function completedPhase1(): ProgressState
    {
        $now = new DateTimeImmutable;
        $s = new ProgressState;
        $phase = $this->program['phases'][0];
        foreach ($phase['lessons'] as $l) {
            $s->lessonsRead[$l['id']] = $now;
        }
        foreach ($phase['videos'] as $v) {
            $s->videos[$v['id']] = ['watched' => str_repeat('1', $v['duration']), 'position' => 0, 'completedAt' => $now];
        }
        $s->activities['p1'] = ['completedAt' => $now];
        $s->quizAttempts['p1'] = [['passed' => true, 'score' => 80, 'createdAt' => $now]];

        return $s;
    }

    public function test_phase_one_open_and_later_phases_locked(): void
    {
        $s = new ProgressState;
        $this->assertTrue($this->rules()->isUnlocked('p1', $s));
        $this->assertFalse($this->rules()->isUnlocked('p2', $s));
        $this->assertFalse($this->rules()->isUnlocked('p4', $s));
    }

    public function test_next_phase_unlocks_only_after_completion(): void
    {
        $s = $this->completedPhase1();
        $this->assertFalse($this->rules()->isUnlocked('p2', $s));
        $s->phaseCompleted['p1'] = ['completedAt' => new DateTimeImmutable, 'points' => 100];
        $this->assertTrue($this->rules()->isUnlocked('p2', $s));
    }

    public function test_quiz_pass_mark_is_80_percent(): void
    {
        $this->assertTrue($this->rules()->scoreQuiz('p1', [1, 0, 1, 0, 0])['passed']);  // 4/5 = 80
        $result = $this->rules()->scoreQuiz('p1', [1, 0, 1, 1, 0]);                    // 3/5 = 60
        $this->assertSame(60, $result['score']);
        $this->assertFalse($result['passed']);
    }

    public function test_phase_threshold_override_wins(): void
    {
        $this->program['phases'][0]['quiz']['passThreshold'] = 100;
        $this->assertFalse($this->rules()->scoreQuiz('p1', [1, 0, 1, 0, 0])['passed']);
    }

    public function test_watched_map_is_or_merged_and_never_cleared(): void
    {
        $r = $this->rules();
        $merged = $r->mergeWatched('1100', '0011', 70);
        $this->assertSame(70, strlen($merged));
        $this->assertStringStartsWith('1111', $merged);
        $this->assertSame($merged, $r->mergeWatched($merged, '', 70));
    }

    public function test_video_needs_90_percent_of_seconds_played(): void
    {
        $r = $this->rules();
        $this->assertFalse($r->isVideoWatched(str_repeat('1', 62).str_repeat('0', 8), 70));
        $this->assertTrue($r->isVideoWatched(str_repeat('1', 63).str_repeat('0', 7), 70));
        // Seeking to the end without playing marks nothing.
        $this->assertFalse($r->isVideoWatched(str_repeat('0', 70), 70));
    }

    public function test_phase_completion_requires_every_item(): void
    {
        $s = $this->completedPhase1();
        $this->assertTrue($this->rules()->requirementsMet('p1', $s));

        $s->quizAttempts['p1'] = [['passed' => false, 'score' => 60, 'createdAt' => new DateTimeImmutable]];
        $this->assertFalse($this->rules()->requirementsMet('p1', $s));
    }

    public function test_requirements_are_configurable(): void
    {
        $s = $this->completedPhase1();
        $s->quizAttempts = [];
        $config = $this->config;
        $config['phaseRequirements']['quiz'] = false;
        $this->assertTrue($this->rules($config)->requirementsMet('p1', $s));
    }

    public function test_points_default_and_override(): void
    {
        $this->assertSame(100, $this->rules()->pointsFor('p1'));
        $this->program['phases'][1]['pointsAwarded'] = 250;
        $this->assertSame(250, $this->rules()->pointsFor('p2'));
    }
}
