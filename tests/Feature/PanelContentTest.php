<?php

namespace Tests\Feature;

use App\Models\User;

/** Admin panel: programs and the onboarding questionnaire. Saves are live in the app API. */
class PanelContentTest extends ApiTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['role' => User::ROLE_ADMIN])->save();
    }

    private function program(): array
    {
        return json_decode(file_get_contents(database_path('content/mhfa_program.json')), true);
    }

    public function test_only_admins_manage_content(): void
    {
        $therapist = User::factory()->create();
        $therapist->forceFill(['role' => User::ROLE_THERAPIST])->save();

        foreach ([User::factory()->create(), $therapist] as $user) {
            $this->signIn($user);
            $this->getJson('/v1/panel/programs')->assertForbidden();
            $this->patchJson('/v1/panel/programs/mhfa', ['title' => 'x'])->assertForbidden();
            $this->getJson('/v1/panel/questionnaire')->assertForbidden();
            $this->putJson('/v1/panel/questionnaire', ['questions' => []])->assertForbidden();
        }
    }

    public function test_admin_lists_programs_and_hiding_one_removes_it_from_the_app(): void
    {
        $this->signIn($this->admin);

        $this->getJson('/v1/panel/programs')->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.id', 'mhfa')->assertJsonPath('0.status', 'active')
            ->assertJsonPath('0.counts.phases', 4);
        $this->getJson('/v1/panel/programs/mhfa')->assertOk()->assertJsonPath('content.phases.0.id', 'p1');

        $this->patchJson('/v1/panel/programs/mhfa', ['title' => 'Mental Health First Aid', 'status' => 'hidden', 'displayOrder' => 3])
            ->assertOk()->assertJsonPath('title', 'Mental Health First Aid')->assertJsonPath('status', 'hidden')->assertJsonPath('displayOrder', 3);
        $this->patchJson('/v1/panel/programs/mhfa', ['status' => 'deleted'])->assertStatus(422)->assertJsonPath('error.field', 'status');

        $this->signIn();
        $this->getJson('/v1/programs')->assertOk()->assertJsonCount(0);
        $this->getJson('/v1/programs/mhfa')->assertNotFound();

        $this->signIn($this->admin);
        $this->patchJson('/v1/panel/programs/mhfa', ['status' => 'active'])->assertOk();
        $this->app->forgetScopedInstances();
        $this->signIn();
        $this->getJson('/v1/programs')->assertJsonPath('0.title', 'Mental Health First Aid')->assertJsonPath('0.displayOrder', 3);
        $this->getJson('/v1/programs/mhfa')->assertOk()->assertJsonPath('title', 'Mental Health First Aid');
    }

    public function test_admin_replaces_program_content_with_the_import_checks(): void
    {
        $this->signIn($this->admin);
        $etag = \App\Models\Program::find('mhfa')->etag;

        $content = $this->program();
        $content['phases'][0]['lessons'][0]['title'] = 'What feelings tell us';
        $this->putJson('/v1/panel/programs/mhfa/content', ['content' => $content])->assertOk()
            ->assertJsonPath('content.phases.0.lessons.0.title', 'What feelings tell us');
        $this->assertNotSame($etag, trim((string) $this->getJson('/v1/programs/mhfa')->assertJsonPath('phases.0.lessons.0.title', 'What feelings tell us')->headers->get('ETag'), '"W/'));

        $bad = $content;
        $bad['phases'][0]['quiz']['questions'][0]['answer'] = 9;
        $this->putJson('/v1/panel/programs/mhfa/content', ['content' => $bad])
            ->assertStatus(422)->assertJsonPath('error.code', 'invalid_content');

        $renamed = $content;
        $renamed['id'] = 'other';
        $this->putJson('/v1/panel/programs/mhfa/content', ['content' => $renamed])
            ->assertStatus(422)->assertJsonPath('error.field', 'content.id');

        // A lesson someone has read can't disappear.
        $this->signIn();
        $this->event(['type' => 'lesson_read', 'phaseId' => 'p1', 'lessonId' => 'p1l1'])->assertOk();
        $this->signIn($this->admin);
        $dropped = $content;
        array_shift($dropped['phases'][0]['lessons']);
        $this->putJson('/v1/panel/programs/mhfa/content', ['content' => $dropped])
            ->assertStatus(422)->assertJsonPath('error.code', 'invalid_content');
        $this->assertStringContainsString('p1l1', $this->putJson('/v1/panel/programs/mhfa/content', ['content' => $dropped])->json('error.message'));
    }

    public function test_admin_adds_a_program_that_starts_hidden(): void
    {
        $this->signIn($this->admin);
        $content = $this->program();
        $content['id'] = 'sleep';
        $content['title'] = 'Better sleep';

        $this->postJson('/v1/panel/programs', ['content' => $content])->assertCreated()
            ->assertJsonPath('id', 'sleep')->assertJsonPath('status', 'hidden');
        $this->postJson('/v1/panel/programs', ['content' => $content])->assertStatus(409)->assertJsonPath('error.code', 'program_exists');
        $this->postJson('/v1/panel/programs', ['content' => ['id' => 'Bad Id', 'title' => 'x', 'phases' => [['id' => 'a']]]])
            ->assertStatus(422)->assertJsonPath('error.field', 'content.id');

        $this->signIn();
        $this->getJson('/v1/programs')->assertJsonCount(1);
        $this->signIn($this->admin);
        $this->patchJson('/v1/panel/programs/sleep', ['status' => 'coming_soon'])->assertOk();
        $this->signIn();
        $this->getJson('/v1/programs')->assertJsonCount(2);
    }

    public function test_admin_edits_and_adds_questionnaire_questions(): void
    {
        $this->signIn($this->admin);
        $doc = $this->getJson('/v1/panel/questionnaire')->assertOk()->json();
        $this->assertCount(9, $doc['questions']);

        $questions = $doc['questions'];
        $questions[0]['q'] = 'What would you most like help with?';
        $questions[] = [
            'id' => 'exercise', 'type' => 'multi', 'q' => 'Which of these do you do?', 'exclusive' => 'none',
            'options' => [['v' => 'walk', 'label' => 'Walking'], ['v' => 'gym', 'label' => 'Gym'], ['v' => 'none', 'label' => 'None of these']],
        ];

        $this->putJson('/v1/panel/questionnaire', ['questions' => $questions])->assertOk()
            ->assertJsonCount(10, 'questions')->assertJsonPath('version', $doc['version'])->assertJsonPath('note', $doc['note']);

        $this->signIn();
        $this->getJson('/v1/questionnaires/onboarding')->assertOk()
            ->assertJsonPath('questions.0.q', 'What would you most like help with?')
            ->assertJsonPath('questions.9.id', 'exercise')->assertJsonPath('questions.9.exclusive', 'none');
    }

    public function test_questionnaire_validation(): void
    {
        $this->signIn($this->admin);
        $q = fn (array $over = []) => $over + ['id' => 'mood', 'type' => 'single', 'q' => 'How?', 'options' => [['v' => 'a', 'label' => 'A'], ['v' => 'b', 'label' => 'B']]];

        $this->putJson('/v1/panel/questionnaire', ['questions' => []])->assertStatus(422);
        $this->putJson('/v1/panel/questionnaire', ['questions' => [$q(), $q()]])->assertStatus(422)->assertJsonPath('error.field', 'questions.0.id');
        $this->putJson('/v1/panel/questionnaire', ['questions' => [$q(['type' => 'slider'])]])->assertStatus(422)->assertJsonPath('error.field', 'questions.0.type');
        $this->putJson('/v1/panel/questionnaire', ['questions' => [$q(['options' => [['v' => 'a', 'label' => 'A']]])]])->assertStatus(422);
        $this->putJson('/v1/panel/questionnaire', ['questions' => [$q(['options' => [['v' => 'a', 'label' => 'A'], ['v' => 'a', 'label' => 'B']]])]])
            ->assertStatus(422)->assertJsonPath('error.field', 'questions.0.options');
        $this->putJson('/v1/panel/questionnaire', ['questions' => [$q(['exclusive' => 'a'])]])
            ->assertStatus(422)->assertJsonPath('error.field', 'questions.0.exclusive');
        $this->putJson('/v1/panel/questionnaire', ['questions' => [$q(['type' => 'multi', 'exclusive' => 'zzz'])]])
            ->assertStatus(422)->assertJsonPath('error.field', 'questions.0.exclusive');

        $this->putJson('/v1/panel/questionnaire', ['questions' => [$q(['exclusive' => ''])]])->assertOk()
            ->assertJsonCount(1, 'questions')->assertJsonMissingPath('questions.0.exclusive');
    }
}
