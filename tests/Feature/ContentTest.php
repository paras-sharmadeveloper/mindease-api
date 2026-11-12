<?php

namespace Tests\Feature;

class ContentTest extends ApiTestCase
{
    public function test_content_requires_auth(): void
    {
        $this->getJson('/v1/config')->assertStatus(401);
    }

    public function test_config_and_questionnaire_are_served_verbatim(): void
    {
        $this->signIn();

        $this->getJson('/v1/config')->assertOk()->assertJsonPath('passThresholdPercent', 80);
        $this->getJson('/v1/questionnaires/onboarding')->assertOk()->assertJsonCount(9, 'questions');
        $this->getJson('/v1/support-resources?region=AU')->assertOk()->assertJsonPath('resources.0.number', '000');
        $this->getJson('/v1/support-resources?region=NZ')->assertStatus(404)->assertJsonPath('error.code', 'content_not_found');
    }

    public function test_programs_list_and_detail(): void
    {
        $this->signIn();

        $this->getJson('/v1/programs')->assertOk()
            ->assertJsonPath('0.id', 'mhfa')
            ->assertJsonStructure([['id', 'title', 'short', 'status', 'displayOrder', 'coverImage']]);

        $this->getJson('/v1/programs/mhfa')->assertOk()
            ->assertJsonCount(4, 'phases')
            ->assertJsonPath('phases.0.quiz.questions.0.answer', 1)
            ->assertHeader('X-Content-Version', '0.0.1-sample');

        $this->getJson('/v1/programs/nope')->assertStatus(404);
    }

    public function test_etag_returns_304_when_unchanged(): void
    {
        $this->signIn();

        $etag = $this->getJson('/v1/programs/mhfa')->assertOk()->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $this->get('/v1/programs/mhfa', ['If-None-Match' => $etag, 'Accept' => 'application/json'])->assertStatus(304);
    }

    public function test_the_real_app_content_in_database_content_imports_cleanly(): void
    {
        $log = app(\App\Services\ContentImporter::class)->importDirectory(database_path('content'), force: true);
        $this->assertNotEmpty($log);

        $this->signIn();
        $program = $this->getJson('/v1/programs/mhfa')->assertOk();
        $this->assertStringNotContainsString('Placeholder', $program->getContent());
        $program->assertJsonPath('phases.1.activity.prompts.0.responseType', 'chips_and_text');
        $this->getJson('/v1/questionnaires/onboarding')->assertOk()->assertJsonPath('questions.0.options.0.v', 'understand');
    }

    public function test_program_lessons_carry_is_read_after_intro(): void
    {
        $this->signIn();

        $first = $this->getJson('/v1/programs/mhfa')->assertOk()
            ->assertJsonPath('phases.0.lessons.0.is_read', false);
        $keys = array_keys($first->json('phases.0.lessons.0'));
        $this->assertSame(array_search('intro', $keys, true) + 1, array_search('is_read', $keys, true));
        $etag = $first->headers->get('ETag');

        $lessonId = $first->json('phases.0.lessons.0.id');
        $this->postJson("/v1/me/programs/mhfa/lessons/{$lessonId}/read")->assertOk();

        $this->get('/v1/programs/mhfa', ['If-None-Match' => $etag, 'Accept' => 'application/json'])->assertOk();
        $after = $this->getJson('/v1/programs/mhfa')->assertOk()
            ->assertJsonPath('phases.0.lessons.0.is_read', true);
        $this->assertNotSame($etag, $after->headers->get('ETag'));
        if ($after->json('phases.0.lessons.1')) {
            $after->assertJsonPath('phases.0.lessons.1.is_read', false);
        }
    }

    public function test_program_videos_carry_is_video_watched(): void
    {
        $this->signIn();

        $first = $this->getJson('/v1/programs/mhfa')->assertOk()
            ->assertJsonPath('phases.0.videos.0.is_video_watched', false);
        $etag = $first->headers->get('ETag');
        $video = $first->json('phases.0.videos.0');
        $base = "/v1/me/programs/mhfa/videos/{$video['id']}/progress";

        // Partly watched: still false.
        $this->postJson($base, ['position' => 1, 'watched' => '1'])->assertOk();
        $this->getJson('/v1/programs/mhfa')->assertJsonPath('phases.0.videos.0.is_video_watched', false);

        // Fully watched: true, and the old ETag no longer gives 304.
        $this->postJson($base, ['position' => $video['duration'], 'watched' => str_repeat('1', (int) ceil($video['duration']))])->assertOk();
        $this->get('/v1/programs/mhfa', ['If-None-Match' => $etag, 'Accept' => 'application/json'])->assertOk();
        $this->getJson('/v1/programs/mhfa')->assertOk()
            ->assertJsonPath('phases.0.videos.0.is_video_watched', true);
    }

    public function test_program_activity_carries_draft_completed_and_saved_answers(): void
    {
        $user = $this->signIn();

        $first = $this->getJson('/v1/programs/mhfa')->assertOk()
            ->assertJsonPath('phases.0.activity.is_draft', false)
            ->assertJsonPath('phases.0.activity.is_completed', false)
            ->assertJsonPath('phases.0.activity.prompts.0.answer', null);
        $keys = array_keys($first->json('phases.0.activity'));
        $at = array_search('instructions', $keys, true);
        $this->assertSame(['is_draft', 'is_completed'], array_slice($keys, $at + 1, 2));
        $etag = $first->headers->get('ETag');

        // Draft
        $this->postJson('/v1/me/programs/mhfa/activities/p1a', [
            'answers' => ['q1' => ['chips' => ['calm'], 'text' => 'Felt okay']], 'complete' => false,
        ])->assertOk();

        $this->get('/v1/programs/mhfa', ['If-None-Match' => $etag, 'Accept' => 'application/json'])->assertOk();
        $draft = $this->getJson('/v1/programs/mhfa')->assertOk()
            ->assertJsonPath('phases.0.activity.is_draft', true)
            ->assertJsonPath('phases.0.activity.is_completed', false)
            ->assertJsonPath('phases.0.activity.prompts.0.answer', ['text' => 'Felt okay', 'chips' => ['calm']])
            ->assertJsonPath('phases.0.activity.prompts.1.answer', null);
        $draftEtag = $draft->headers->get('ETag');

        // Submit (answers changed)
        $this->postJson('/v1/me/programs/mhfa/activities/p1a', [
            'answers' => ['q1' => ['chips' => ['calm']], 'q2' => ['text' => 'Rested']], 'complete' => true,
        ])->assertOk();

        $done = $this->getJson('/v1/programs/mhfa')->assertOk()
            ->assertJsonPath('phases.0.activity.is_draft', false)
            ->assertJsonPath('phases.0.activity.is_completed', true)
            ->assertJsonPath('phases.0.activity.prompts.0.answer', ['text' => '', 'chips' => ['calm']])
            ->assertJsonPath('phases.0.activity.prompts.1.answer', ['text' => 'Rested', 'chips' => []]);
        $this->assertNotSame($draftEtag, $done->headers->get('ETag'));

        // Another user never sees these answers.
        $this->signIn();
        $this->getJson('/v1/programs/mhfa')->assertOk()
            ->assertJsonPath('phases.0.activity.is_completed', false)
            ->assertJsonPath('phases.0.activity.prompts.0.answer', null)
            ->assertJsonPath('phases.0.activity.prompts.1.answer', null);
    }
}
