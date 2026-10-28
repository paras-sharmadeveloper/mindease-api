<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Ports of QA_TEST_PLAN sections C and D, enforced server-side. */
class ProgressTest extends ApiTestCase
{
    public function test_fresh_progress_has_only_phase_one_unlocked(): void
    {
        $this->signIn();

        $this->getJson('/v1/me/programs/mhfa/progress')->assertOk()
            ->assertJsonPath('phases.p1.unlocked', true)
            ->assertJsonPath('phases.p2.unlocked', false)
            ->assertJsonPath('points', 0)
            ->assertJsonPath('programCompletedAt', null);
    }

    public function test_events_on_locked_phase_are_403(): void // B3/B4
    {
        $this->signIn();

        $this->event(['type' => 'lesson_read', 'phaseId' => 'p3', 'lessonId' => 'p3l1'])
            ->assertStatus(403)->assertJsonPath('error.code', 'phase_locked');
    }

    public function test_unknown_items_are_rejected(): void
    {
        $this->signIn();

        $this->event(['type' => 'lesson_read', 'phaseId' => 'p1', 'lessonId' => 'p2l1'])
            ->assertStatus(422)->assertJsonPath('error.code', 'unknown_lesson');
        $this->event(['type' => 'lesson_read', 'phaseId' => 'p9', 'lessonId' => 'x'])
            ->assertStatus(422)->assertJsonPath('error.code', 'unknown_phase');
        $this->event(['type' => 'points_award', 'phaseId' => 'p1'])
            ->assertStatus(422)->assertJsonPath('error.field', 'type');
    }

    public function test_idempotency_key_is_required(): void
    {
        $this->signIn();

        $this->postJson('/v1/me/programs/mhfa/events', ['type' => 'lesson_read', 'phaseId' => 'p1', 'lessonId' => 'p1l1'])
            ->assertStatus(422)->assertJsonPath('error.code', 'idempotency_key_required');
    }

    public function test_lesson_read_counts_once(): void // C2
    {
        $this->signIn();

        $this->event(['type' => 'lesson_read', 'phaseId' => 'p1', 'lessonId' => 'p1l1'])->assertOk()
            ->assertJsonPath('progress.phases.p1.counts.lessons.done', 1)
            ->assertJsonPath('completion', null);
        $this->event(['type' => 'lesson_read', 'phaseId' => 'p1', 'lessonId' => 'p1l1'])->assertOk()
            ->assertJsonPath('progress.phases.p1.counts.lessons.done', 1);
    }

    public function test_seeking_to_end_without_playing_does_not_complete_video(): void // C5
    {
        $this->signIn();

        $this->event(['type' => 'video_progress', 'phaseId' => 'p1', 'videoId' => 'p1v1', 'position' => 70, 'watched' => ''])
            ->assertOk()
            ->assertJsonPath('progress.phases.p1.videos.p1v1.percent', 0)
            ->assertJsonPath('progress.phases.p1.videos.p1v1.completedAt', null);
    }

    public function test_video_completes_at_90_percent_and_merges_with_or(): void // C6/C7
    {
        $this->signIn(); // p1v1 duration = 70s -> needs 63s

        $this->event(['type' => 'video_progress', 'phaseId' => 'p1', 'videoId' => 'p1v1',
            'position' => 40, 'watched' => str_repeat('1', 40)])->assertOk()
            ->assertJsonPath('progress.phases.p1.videos.p1v1.completedAt', null);

        // Later event only reports seconds 40-62; earlier bits must survive.
        $res = $this->event(['type' => 'video_progress', 'phaseId' => 'p1', 'videoId' => 'p1v1',
            'position' => 63, 'watched' => str_repeat('0', 40).str_repeat('1', 23)])->assertOk();

        $res->assertJsonPath('progress.phases.p1.videos.p1v1.watchedSeconds', 63);
        // Whole-number floats encode as `63` in JSON, so compare by value, not type.
        $this->assertEquals(63, $res->json('progress.phases.p1.videos.p1v1.position'));
        $this->assertNotNull($res->json('progress.phases.p1.videos.p1v1.completedAt'));
        $this->assertSame(1, $res->json('progress.phases.p1.counts.videos.done'));
    }

    public function test_activity_needs_one_answer_and_stays_complete(): void // C8/C11
    {
        $this->signIn();

        $this->event(['type' => 'activity_save', 'phaseId' => 'p1', 'complete' => true,
            'answers' => ['q1' => ['text' => '   ', 'chips' => []]]])
            ->assertStatus(422)->assertJsonPath('error.code', 'activity_empty');

        $this->event(['type' => 'activity_save', 'phaseId' => 'p1', 'complete' => true,
            'answers' => ['q1' => ['text' => 'Felt calmer', 'chips' => ['calm']]]])
            ->assertOk()->assertJsonPath('progress.phases.p1.activity.complete', true);

        $this->event(['type' => 'activity_save', 'phaseId' => 'p1', 'complete' => false,
            'answers' => ['q2' => ['text' => 'Edited later']]])
            ->assertOk()
            ->assertJsonPath('progress.phases.p1.activity.complete', true)
            ->assertJsonPath('progress.phases.p1.activity.answers.q2.text', 'Edited later');
    }

    public function test_journal_answers_are_encrypted_at_rest(): void
    {
        $user = $this->signIn();

        $this->event(['type' => 'activity_save', 'phaseId' => 'p1', 'complete' => false,
            'answers' => ['q1' => ['text' => 'very private note']]])->assertOk();

        $raw = DB::table('activity_responses')->where('user_id', $user->id)->value('answers');
        $this->assertStringNotContainsString('very private note', $raw);
    }

    public function test_quiz_is_scored_server_side(): void // C14
    {
        $this->signIn();

        $res = $this->event(['type' => 'quiz_submit', 'phaseId' => 'p1', 'answers' => self::QUIZ_FAIL])->assertOk();
        $res->assertJsonPath('progress.phases.p1.quiz.attempts.0.score', 60)
            ->assertJsonPath('progress.phases.p1.quiz.passed', false)
            ->assertJsonPath('progress.phases.p1.completedAt', null);

        $this->event(['type' => 'quiz_submit', 'phaseId' => 'p1', 'answers' => [1, 0]])
            ->assertStatus(422)->assertJsonPath('error.code', 'quiz_incomplete');
        $this->event(['type' => 'quiz_submit', 'phaseId' => 'p1', 'answers' => [9, 0, 1, 0, 1]])
            ->assertStatus(422)->assertJsonPath('error.code', 'invalid_answer');
    }

    public function test_phase_completion_awards_points_once_and_unlocks_next(): void // D1/D2
    {
        $this->signIn();

        $this->completePhase('p1')
            ->assertJsonPath('completion.phaseId', 'p1')
            ->assertJsonPath('completion.pointsAwarded', 100)
            ->assertJsonPath('completion.nextPhaseId', 'p2')
            ->assertJsonPath('completion.programComplete', false)
            ->assertJsonPath('progress.phases.p2.unlocked', true)
            ->assertJsonPath('progress.points', 100);

        // Retake and pass again: no completion, points unchanged, attempts = 2.
        $this->event(['type' => 'quiz_submit', 'phaseId' => 'p1', 'answers' => self::QUIZ_PASS])->assertOk()
            ->assertJsonPath('completion', null)
            ->assertJsonPath('progress.points', 100)
            ->assertJsonCount(2, 'progress.phases.p1.quiz.attempts');

        $this->getJson('/v1/me/points')->assertOk()->assertJsonPath('total', 100)->assertJsonCount(1, 'awards');
    }

    public function test_full_program_completion(): void // D4
    {
        $this->signIn();

        foreach (['p1', 'p2', 'p3'] as $phase) {
            $this->completePhase($phase);
        }

        $this->completePhase('p4')
            ->assertJsonPath('completion.programComplete', true)
            ->assertJsonPath('completion.nextPhaseId', null)
            ->assertJsonPath('progress.points', 400);

        $this->assertNotNull($this->getJson('/v1/me/programs/mhfa/progress')->json('programCompletedAt'));
    }

    public function test_replayed_idempotency_key_returns_same_result_without_double_counting(): void
    {
        $this->signIn();
        $key = '4b3c1d6e-0000-4000-8000-000000000001';
        $payload = ['type' => 'quiz_submit', 'phaseId' => 'p1', 'answers' => self::QUIZ_FAIL];

        $first = $this->event($payload, $key)->assertOk();
        $replay = $this->event($payload, $key)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json(), $replay->json());
        $this->assertSame(1, DB::table('quiz_attempts')->count());

        $this->event(['type' => 'quiz_submit', 'phaseId' => 'p1', 'answers' => self::QUIZ_PASS], $key)
            ->assertStatus(422)->assertJsonPath('error.code', 'idempotency_key_reused');
    }

    public function test_progress_never_mixes_between_users(): void // D5
    {
        $this->signIn();
        $this->completePhase('p1');

        $this->signIn(User::factory()->create());
        $this->getJson('/v1/me/programs/mhfa/progress')->assertOk()
            ->assertJsonPath('points', 0)
            ->assertJsonPath('phases.p2.unlocked', false);
    }
}
