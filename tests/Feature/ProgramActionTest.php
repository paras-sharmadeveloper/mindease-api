<?php

namespace Tests\Feature;

/** Per-action endpoints (lesson read, video progress, activity, quiz) and GET /me/home. */
class ProgramActionTest extends ApiTestCase
{
    public function test_actions_drive_progress_and_complete_a_phase(): void
    {
        $this->signIn();
        $base = '/v1/me/programs/mhfa';

        foreach (['p1l1', 'p1l2', 'p1l3'] as $lesson) {
            $this->postJson("{$base}/lessons/{$lesson}/read")->assertOk()->assertJsonPath("progress.phases.p1.lessons.{$lesson}.readAt", fn ($v) => $v !== null);
        }
        foreach (['p1v1' => 70, 'p1v2' => 80, 'p1v3' => 90] as $video => $seconds) {
            $this->postJson("{$base}/videos/{$video}/progress", ['position' => $seconds, 'watched' => str_repeat('1', $seconds)])->assertOk();
        }
        $this->postJson("{$base}/activities/p1a", ['answers' => ['q1' => ['text' => 'Tired', 'chips' => []]], 'complete' => false])->assertOk()
            ->assertJsonPath('progress.phases.p1.activity.complete', false);
        $this->postJson("{$base}/activities/p1a", ['answers' => ['q1' => ['text' => 'Tired', 'chips' => ['tired']]], 'complete' => true])->assertOk();

        $this->postJson("{$base}/quizzes/p1q/submit", ['answers' => self::QUIZ_FAIL])->assertOk()
            ->assertJsonPath('progress.phases.p1.quiz.attempts.0.passed', false)->assertJsonPath('completion', null);
        $this->postJson("{$base}/quizzes/p1q/submit", ['answers' => self::QUIZ_PASS])->assertOk()
            ->assertJsonPath('completion.phaseId', 'p1')->assertJsonPath('completion.pointsAwarded', 100)
            ->assertJsonPath('progress.phases.p2.unlocked', true);
    }

    public function test_action_errors(): void
    {
        $this->signIn();
        $base = '/v1/me/programs/mhfa';

        $this->postJson("{$base}/lessons/p2l1/read")->assertStatus(403)->assertJsonPath('error.code', 'phase_locked');
        $this->postJson("{$base}/lessons/nope/read")->assertStatus(404)->assertJsonPath('error.code', 'unknown_lesson');
        $this->postJson("{$base}/quizzes/p1q/submit", ['answers' => [1, 0]])->assertStatus(422)->assertJsonPath('error.code', 'quiz_incomplete');
        $this->postJson("{$base}/activities/p1a", ['answers' => [], 'complete' => true])->assertStatus(422)->assertJsonPath('error.code', 'activity_empty');
        $this->postJson("{$base}/videos/p1v1/progress", [])->assertStatus(422)->assertJsonPath('error.field', 'position');
        $this->postJson('/v1/me/programs/nope/lessons/p1l1/read')->assertStatus(404);
    }

    public function test_home_continue_and_focus(): void
    {
        $this->signIn();

        // Fresh user: start with the first lesson, no focus yet.
        $home = $this->getJson('/v1/me/home')->assertOk();
        $home->assertJsonPath('continue.kind', 'learn')->assertJsonPath('continue.itemId', 'p1l1')
            ->assertJsonPath('continue.phaseId', 'p1')->assertJsonPath('focus', [])->assertJsonPath('points', 0)
            ->assertJsonPath('phases.0.unlocked', true)->assertJsonPath('phases.1.unlocked', false);

        // Learning style "watch" puts videos first; a half-watched video wins.
        $this->putJson('/v1/me/assessment', ['answers' => ['style' => 'watch', 'goals' => ['stress']], 'completedAt' => '2026-11-10T00:00:00Z']);
        $this->getJson('/v1/me/home')->assertJsonPath('continue.kind', 'watch')->assertJsonPath('continue.itemId', 'p1v1')
            ->assertJsonPath('focus.0.phaseId', 'p2')->assertJsonPath('focus.0.unlocked', false)->assertJsonPath('focus.0.percent', 0)
            ->assertJsonPath('questionnaireCompleted', true);
        $this->postJson('/v1/me/programs/mhfa/videos/p1v2/progress', ['position' => 30, 'watched' => str_repeat('1', 30)])->assertOk();
        $this->getJson('/v1/me/home')->assertJsonPath('continue.itemId', 'p1v2')->assertJsonPath('continue.resumePosition', 30);

        // Phase 1 done: continue moves to phase 2, points counted.
        $this->completePhase('p1');
        $this->getJson('/v1/me/home')->assertJsonPath('continue.phaseId', 'p2')->assertJsonPath('points', 100)
            ->assertJsonPath('phases.0.completed', true)->assertJsonPath('phases.0.percent', 100)
            ->assertJsonPath('focus.0.unlocked', true);
    }
}
