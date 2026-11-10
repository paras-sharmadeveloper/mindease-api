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
}
