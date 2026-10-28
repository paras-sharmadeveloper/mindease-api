<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ContentImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    /** Correct answers for every quiz in the sample program. */
    protected const QUIZ_PASS = [1, 0, 1, 0, 1];

    protected const QUIZ_FAIL = [1, 0, 1, 1, 0]; // 3/5 = 60%

    protected function setUp(): void
    {
        parent::setUp();
        app(ContentImporter::class)->importDirectory(database_path('content'));
    }

    protected function signIn(?User $user = null): User
    {
        $user ??= User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    protected function event(array $payload, ?string $key = null, string $program = 'mhfa')
    {
        return $this->postJson("/v1/me/programs/{$program}/events", $payload, [
            'Idempotency-Key' => $key ?? (string) Str::uuid(),
        ]);
    }

    /** Completes every item of a phase (lessons, videos, activity, quiz). Returns the last response. */
    protected function completePhase(string $phaseId)
    {
        $program = json_decode(file_get_contents(database_path('content/mhfa_program.json')), true);
        $phase = collect($program['phases'])->firstWhere('id', $phaseId);

        foreach ($phase['lessons'] as $lesson) {
            $this->event(['type' => 'lesson_read', 'phaseId' => $phaseId, 'lessonId' => $lesson['id']])->assertOk();
        }
        foreach ($phase['videos'] as $video) {
            $this->event([
                'type' => 'video_progress', 'phaseId' => $phaseId, 'videoId' => $video['id'],
                'position' => $video['duration'], 'watched' => str_repeat('1', $video['duration']),
            ])->assertOk();
        }
        $this->event([
            'type' => 'activity_save', 'phaseId' => $phaseId, 'complete' => true,
            'answers' => ['q1' => ['text' => 'I noticed I was tired', 'chips' => ['tired']]],
        ])->assertOk();

        return $this->event(['type' => 'quiz_submit', 'phaseId' => $phaseId, 'answers' => self::QUIZ_PASS])->assertOk();
    }
}
