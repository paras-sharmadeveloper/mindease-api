<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

class AccountDeletionTest extends ApiTestCase
{
    public function test_delete_me_removes_account_and_all_data(): void
    {
        $user = $this->signIn();
        $this->putJson('/v1/me/assessment', ['answers' => ['mood' => 'low'], 'step' => 2])->assertOk();
        $this->completePhase('p1');

        $this->deleteJson('/v1/me')->assertNoContent();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        foreach (['assessments', 'lesson_reads', 'video_progress', 'activity_responses', 'quiz_attempts',
            'phase_completions', 'refresh_tokens', 'idempotency_keys'] as $table) {
            $this->assertSame(0, DB::table($table)->where('user_id', $user->id)->count(), "{$table} not emptied");
        }
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count());
    }
}
