<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class AssessmentTest extends ApiTestCase
{
    public function test_defaults_before_anything_is_saved(): void
    {
        $this->signIn();

        $this->getJson('/v1/me/assessment')->assertOk()->assertExactJson([
            'answers' => [], 'step' => 0, 'completedAt' => null, 'nextStepsSeen' => false, 'questionnaireVersion' => null, 'recommendation' => null,
        ]);
    }

    public function test_save_resume_and_encrypted_at_rest(): void
    {
        $user = $this->signIn();

        $this->putJson('/v1/me/assessment', [
            'answers' => ['mood' => 'very_low', 'goals' => ['stress', 'sleep']],
            'step' => 4,
            'questionnaireVersion' => '0.0.1-sample',
        ])->assertOk()->assertJsonPath('step', 4);

        $this->getJson('/v1/me/assessment')->assertOk()
            ->assertJsonPath('answers.mood', 'very_low')
            ->assertJsonPath('answers.goals.1', 'sleep');

        $raw = DB::table('assessments')->where('user_id', $user->id)->value('answers');
        $this->assertStringNotContainsString('very_low', $raw);
    }

    public function test_answers_are_owner_only(): void
    {
        $this->signIn();
        $this->putJson('/v1/me/assessment', ['answers' => ['mood' => 'low'], 'step' => 1])->assertOk();

        $this->signIn(User::factory()->create());
        $this->getJson('/v1/me/assessment')->assertOk()->assertJsonPath('step', 0);
    }

    public function test_answers_from_each_step_are_merged_not_replaced(): void
    {
        $this->signIn();

        $this->putJson('/v1/me/assessment', ['answers' => ['goals' => ['stress', 'support']], 'step' => 1])->assertOk();
        $this->putJson('/v1/me/assessment', ['answers' => ['mood' => 'low'], 'step' => 2])->assertOk();
        $this->putJson('/v1/me/assessment', ['answers' => ['sleep' => 'poor'], 'step' => 3, 'completedAt' => '2026-11-10T01:00:00Z'])->assertOk();
        $this->putJson('/v1/me/assessment', ['nextStepsSeen' => true])->assertOk(); // other fields untouched

        $res = $this->getJson('/v1/me/assessment')->assertOk()
            ->assertJsonPath('answers.goals', ['stress', 'support'])
            ->assertJsonPath('answers.mood', 'low')
            ->assertJsonPath('answers.sleep', 'poor')
            ->assertJsonPath('step', 3)
            ->assertJsonPath('completedAt', '2026-11-10T01:00:00.000Z')
            ->assertJsonPath('nextStepsSeen', true);

        // Retake: null removes one answer, completedAt null starts over.
        $this->putJson('/v1/me/assessment', ['answers' => ['sleep' => null], 'completedAt' => null, 'step' => 0])->assertOk()
            ->assertJsonMissingPath('answers.sleep')->assertJsonPath('answers.mood', 'low')->assertJsonPath('completedAt', null);

        $this->putJson('/v1/me/assessment', ['answers' => ['mood' => ['nested' => 'object']]])->assertStatus(422)->assertJsonPath('error.field', 'answers.mood');
    }

    public function test_recommendation_follows_the_app_rules(): void
    {
        $this->signIn();

        $rec = $this->putJson('/v1/me/assessment', ['answers' => [
            'goals' => ['stress', 'support'], 'stress' => 'overwhelming', 'body' => ['none'],
            'express' => 'hard', 'mood' => 'low', 'support' => ['noone'], 'style' => 'watch', 'time' => '10',
        ]])->assertOk()->json('recommendation');

        $this->assertSame(['p2', 'p3', 'p4'], array_column($rec['focusPhases'], 'phaseId'));
        $this->assertSame(['You want help managing stress', 'Your stress has been high lately'], $rec['focusPhases'][0]['reasons']);
        $this->assertSame(2, $rec['focusPhases'][0]['number']);
        $this->assertNotNull($rec['focusPhases'][0]['title']);
        $this->assertTrue($rec['showSupport']);
        $this->assertTrue($rec['suggestProfessional']);
        $this->assertSame('watch', $rec['preferredKind']);
        $this->assertSame('10', $rec['dailyMinutes']);
        $this->assertSame(['stress', 'low_mood', 'emotions', 'supporting_others'], array_column($rec['focusAreas'], 'key'));

        // Nothing that maps to a phase: start at phase 1.
        $this->signIn(User::factory()->create());
        $this->putJson('/v1/me/assessment', ['answers' => ['time' => '5']])
            ->assertJsonPath('recommendation.focusPhases.0.phaseId', 'p1')
            ->assertJsonPath('recommendation.focusPhases.0.reasons', ['A great place for anyone to start']);
    }
}
