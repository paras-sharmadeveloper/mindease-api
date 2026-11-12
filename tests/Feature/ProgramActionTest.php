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

    public function test_quiz_answers_can_be_keyed_by_question_id(): void
    {
        $this->signIn();
        $url = '/v1/me/programs/mhfa/quizzes/p1q/submit';

        // Order of keys doesn't matter; scored against the right questions.
        $this->postJson($url, ['answers' => ['p1q-5' => 1, 'p1q-4' => 0, 'p1q-3' => 1, 'p1q-2' => 0, 'p1q-1' => 1]])->assertOk()
            ->assertJsonPath('progress.phases.p1.quiz.attempts.0.score', 100)
            ->assertJsonPath('progress.phases.p1.quiz.attempts.0.passed', true)
            ->assertJsonPath('progress.phases.p1.quiz.attempts.0.answers', [1, 0, 1, 0, 1]);

        $this->postJson($url, ['answers' => ['p1q-1' => 1, 'p1q-2' => 0]])->assertStatus(422)
            ->assertJsonPath('error.code', 'quiz_incomplete')->assertJsonPath('error.field', 'answers.p1q-3');
        $this->postJson($url, ['answers' => ['p1q-1' => 1, 'p1q-2' => 0, 'p1q-3' => 1, 'p1q-4' => 0, 'p1q-5' => 1, 'p2q-1' => 0]])->assertStatus(422)
            ->assertJsonPath('error.code', 'unknown_question')->assertJsonPath('error.field', 'answers.p2q-1');
        $this->postJson($url, ['answers' => ['p1q-1' => 1, 'p1q-2' => 7, 'p1q-3' => 1, 'p1q-4' => 0, 'p1q-5' => 1]])->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_answer')->assertJsonPath('error.field', 'answers.p1q-2');
        $this->postJson('/v1/me/programs/mhfa/quizzes/nope/submit', ['answers' => ['p1q-1' => 1]])->assertStatus(404)
            ->assertJsonPath('error.code', 'unknown_quiz');
    }

    public function test_program_quiz_carries_completed_passed_and_user_answers(): void
    {
        $this->signIn();

        $first = $this->getJson('/v1/programs/mhfa')->assertOk()
            ->assertJsonPath('phases.0.quiz.is_completed', false)
            ->assertJsonPath('phases.0.quiz.is_passed', false)
            ->assertJsonPath('phases.0.quiz.questions.0.user_answer', null);
        $keys = array_keys($first->json('phases.0.quiz'));
        $this->assertSame(['id', 'is_completed', 'is_passed'], array_slice($keys, 0, 3));

        // Failed attempt: completed, not passed.
        $this->postJson('/v1/me/programs/mhfa/quizzes/p1q/submit', ['answers' => self::QUIZ_FAIL])->assertOk();
        $this->getJson('/v1/programs/mhfa')->assertOk()
            ->assertJsonPath('phases.0.quiz.is_completed', true)
            ->assertJsonPath('phases.0.quiz.is_passed', false)
            ->assertJsonPath('phases.0.quiz.questions.3.user_answer', self::QUIZ_FAIL[3]);

        // Pass, then a failed retake: still passed; user_answer shows the latest attempt.
        $this->postJson('/v1/me/programs/mhfa/quizzes/p1q/submit', ['answers' => self::QUIZ_PASS])->assertOk();
        $this->postJson('/v1/me/programs/mhfa/quizzes/p1q/submit', ['answers' => self::QUIZ_FAIL])->assertOk();
        $after = $this->getJson('/v1/programs/mhfa')->assertOk()
            ->assertJsonPath('phases.0.quiz.is_passed', true)
            ->assertJsonPath('phases.0.quiz.questions.0.answer', 1);
        $this->assertSame(self::QUIZ_FAIL, array_column($after->json('phases.0.quiz.questions'), 'user_answer'));

        // Another user starts clean.
        $this->signIn();
        $this->getJson('/v1/programs/mhfa')->assertJsonPath('phases.0.quiz.is_completed', false)
            ->assertJsonPath('phases.0.quiz.questions.0.user_answer', null);
    }
}
