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
            'answers' => [], 'step' => 0, 'completedAt' => null, 'nextStepsSeen' => false, 'questionnaireVersion' => null,
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
}
