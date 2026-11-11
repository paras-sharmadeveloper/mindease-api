<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DeveloperSeeder;
use Illuminate\Support\Facades\Hash;

/** /docs/api, /docs/panel, their JSON and /developers need a sign-in at /docs/login (admin by default). */
class DocsAccessTest extends ApiTestCase
{
    private function admin(): User
    {
        $user = User::factory()->create(['email' => 'admin@example.com']);
        $user->forceFill(['role' => User::ROLE_ADMIN])->save();

        return $user;
    }

    public function test_guests_are_sent_to_login(): void
    {
        foreach (['/docs/api', '/docs/panel', '/developers'] as $path) {
            $this->get($path)->assertRedirect('/docs/login');
        }
        $this->get('/docs/api.json')->assertStatus(401);
        $this->get('/docs/panel.json')->assertStatus(401);
        $this->get('/docs/login')->assertOk()->assertSee('MindEase developer account')->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_admin_signs_in_and_sees_docs(): void
    {
        $this->admin();

        $this->get('/docs/panel');
        $this->post('/docs/login', ['email' => 'Admin@Example.com', 'password' => 'password'])
            ->assertRedirect('/docs/panel');

        $this->get('/docs/api')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/docs/api.json')->assertOk()->assertJsonPath('info.title', 'MindEase Mobile App API');
        $this->get('/docs/panel.json')->assertOk();

        $this->post('/docs/logout')->assertRedirect('/docs/login');
        $this->get('/docs/api')->assertRedirect('/docs/login');
    }

    public function test_wrong_password_and_app_users_are_refused(): void
    {
        $this->admin();
        User::factory()->create(['email' => 'asha@example.com']); // role: user
        $therapist = User::factory()->create(['email' => 't@example.com']);
        $therapist->forceFill(['role' => User::ROLE_THERAPIST])->save();

        foreach ([['admin@example.com', 'wrong'], ['asha@example.com', 'password'], ['t@example.com', 'password'], ['nobody@example.com', 'password']] as [$email, $pw]) {
            $this->post('/docs/login', ['email' => $email, 'password' => $pw])->assertSessionHasErrors('email');
            $this->assertGuest('web');
        }
        $this->get('/docs/api')->assertRedirect('/docs/login');
    }

    public function test_roles_are_configurable(): void
    {
        config(['mindease.docs.roles' => ['admin', 'therapist']]);
        $therapist = User::factory()->create(['email' => 't@example.com']);
        $therapist->forceFill(['role' => User::ROLE_THERAPIST])->save();

        $this->post('/docs/login', ['email' => 't@example.com', 'password' => 'password'])->assertRedirect('/docs/api');
        $this->get('/docs/api')->assertOk();
    }

    public function test_login_is_rate_limited(): void
    {
        $this->admin();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/docs/login', ['email' => 'admin@example.com', 'password' => 'wrong']);
        }
        $this->post('/docs/login', ['email' => 'admin@example.com', 'password' => 'password'])->assertStatus(429);
    }

    public function test_sign_in_ends_after_30_minutes_and_page_has_sign_out(): void
    {
        $this->admin();
        $this->post('/docs/login', ['email' => 'admin@example.com', 'password' => 'password']);

        $this->get('/docs/api')->assertOk()->assertSee('Sign out')->assertSee('admin@example.com')
            ->assertSee(route('docs.logout'), false);
        $this->get('/developers')->assertOk()->assertSee('Sign out');

        $this->travel(29)->minutes();
        $this->get('/docs/api')->assertOk();

        $this->travel(2)->minutes();
        $this->get('/docs/api')->assertRedirect('/docs/login');
        $this->get('/docs/login')->assertSee('Your session has ended');
        $this->assertGuest('web');
    }

    public function test_root_goes_to_docs(): void
    {
        $this->get('/')->assertRedirect('/docs/api');
    }

    public function test_unknown_email_works_with_argon2id(): void
    {
        config(['hashing.driver' => 'argon2id']);
        $this->post('/docs/login', ['email' => 'nobody@example.com', 'password' => 'password'])->assertSessionHasErrors('email');
    }

    public function test_sanctum_token_does_not_open_docs(): void
    {
        $admin = $this->admin();
        $token = $admin->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)->get('/docs/api')->assertRedirect('/docs/login');
    }

    public function test_developer_sees_docs_but_not_the_panel(): void
    {
        $dev = User::factory()->create(['email' => 'dev@example.com']);
        $dev->forceFill(['role' => User::ROLE_DEVELOPER])->save();

        $this->post('/docs/login', ['email' => 'dev@example.com', 'password' => 'password'])->assertRedirect('/docs/api');
        $this->get('/docs/api')->assertOk();
        $this->get('/docs/panel')->assertOk();

        $token = $dev->createToken('t')->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/v1/panel/me')
            ->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    }

    public function test_developer_seeder_needs_email_and_password_from_env(): void
    {
        config(['mindease.docs.developer_email' => 'Dev@Example.com', 'mindease.docs.developer_password' => 'from-env-123']);
        $this->seed(DeveloperSeeder::class);

        $dev = User::where('email', 'dev@example.com')->firstOrFail();
        $this->assertSame(User::ROLE_DEVELOPER, $dev->role);
        $this->assertTrue(Hash::check('from-env-123', $dev->password));

        // Re-run keeps the password.
        config(['mindease.docs.developer_password' => 'other-456']);
        $this->seed(DeveloperSeeder::class);
        $this->assertTrue(Hash::check('from-env-123', $dev->fresh()->password));

        // The account it made can sign in to the docs.
        $this->post('/docs/login', ['email' => 'dev@example.com', 'password' => 'from-env-123'])->assertRedirect('/docs/api');

        // Email or password missing (or too short): nothing is created.
        $before = User::count();
        foreach ([[null, 'long-enough-1'], ['dev2@example.com', null], ['dev2@example.com', 'short']] as [$email, $password]) {
            config(['mindease.docs.developer_email' => $email, 'mindease.docs.developer_password' => $password]);
            $this->seed(DeveloperSeeder::class);
        }
        $this->assertSame($before, User::count());
    }

    public function test_developer_seeder_never_downgrades_staff(): void
    {
        $this->admin();
        config(['mindease.docs.developer_email' => 'admin@example.com']);
        $this->seed(DeveloperSeeder::class);

        $this->assertSame(User::ROLE_ADMIN, User::where('email', 'admin@example.com')->value('role'));
    }
}
