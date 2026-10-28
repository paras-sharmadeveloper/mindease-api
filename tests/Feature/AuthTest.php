<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

class AuthTest extends ApiTestCase
{
    private function register(array $overrides = [])
    {
        return $this->postJson('/v1/auth/register', $overrides + [
            'name' => 'Asha Patel', 'email' => 'Asha@Example.com', 'password' => 'secret123',
        ]);
    }

    public function test_register_returns_user_and_tokens(): void
    {
        $this->register()
            ->assertCreated()
            ->assertJsonStructure(['user' => ['id', 'name', 'email', 'createdAt'], 'accessToken', 'refreshToken'])
            ->assertJsonPath('user.email', 'asha@example.com');
    }

    public function test_register_validation_uses_contract_error_shape(): void
    {
        $this->register(['password' => 'short'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.field', 'password')
            ->assertJsonPath('error.message', 'Use at least 8 characters.');
    }

    public function test_duplicate_email_is_409_on_email_field(): void
    {
        $this->register()->assertCreated();
        $this->register(['email' => 'asha@example.com'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'email_taken')
            ->assertJsonPath('error.field', 'email');
    }

    public function test_login_and_generic_401(): void
    {
        $this->register();

        $this->postJson('/v1/auth/login', ['email' => 'asha@example.com', 'password' => 'secret123'])
            ->assertOk()->assertJsonStructure(['user', 'accessToken', 'refreshToken']);

        $wrongPassword = $this->postJson('/v1/auth/login', ['email' => 'asha@example.com', 'password' => 'nope12345']);
        $unknownEmail = $this->postJson('/v1/auth/login', ['email' => 'ghost@example.com', 'password' => 'secret123']);

        $wrongPassword->assertStatus(401)->assertJsonPath('error.code', 'invalid_credentials');
        $unknownEmail->assertStatus(401);
        $this->assertSame($wrongPassword->json('error.message'), $unknownEmail->json('error.message'));
    }

    public function test_access_token_works_for_protected_routes(): void
    {
        $token = $this->register()->json('accessToken');

        $this->getJson('/v1/me', ['Authorization' => "Bearer {$token}"])->assertOk()->assertJsonPath('name', 'Asha Patel');
        $this->app['auth']->forgetGuards();
        $this->getJson('/v1/me')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_refresh_rotates_and_detects_reuse(): void
    {
        $first = $this->register()->json('refreshToken');

        $second = $this->postJson('/v1/auth/refresh', ['refreshToken' => $first])
            ->assertOk()->assertJsonStructure(['accessToken', 'refreshToken'])
            ->json('refreshToken');

        // Reusing the rotated token revokes the whole family...
        $this->postJson('/v1/auth/refresh', ['refreshToken' => $first])
            ->assertStatus(401)->assertJsonPath('error.code', 'invalid_refresh_token');

        // ...so the newer one is dead too.
        $this->postJson('/v1/auth/refresh', ['refreshToken' => $second])->assertStatus(401);
    }

    public function test_logout_revokes_refresh_token(): void
    {
        $refresh = $this->register()->json('refreshToken');

        $this->postJson('/v1/auth/logout', ['refreshToken' => $refresh])->assertNoContent();
        $this->postJson('/v1/auth/refresh', ['refreshToken' => $refresh])->assertStatus(401);
    }

    public function test_password_reset_is_always_202(): void
    {
        Notification::fake();
        $this->register();

        $this->postJson('/v1/auth/password-reset', ['email' => 'asha@example.com'])->assertStatus(202);
        $this->postJson('/v1/auth/password-reset', ['email' => 'ghost@example.com'])->assertStatus(202);

        Notification::assertSentTo(User::where('email', 'asha@example.com')->first(), ResetPasswordNotification::class);
    }

    public function test_password_reset_confirm_changes_password_and_signs_out(): void
    {
        $refresh = $this->register()->json('refreshToken');
        $user = User::where('email', 'asha@example.com')->first();
        $token = Password::broker()->createToken($user);

        $this->postJson('/v1/auth/password-reset/confirm', [
            'email' => 'asha@example.com', 'token' => 'wrong', 'password' => 'newsecret1',
        ])->assertStatus(422)->assertJsonPath('error.code', 'invalid_reset_token');

        $this->postJson('/v1/auth/password-reset/confirm', [
            'email' => 'asha@example.com', 'token' => $token, 'password' => 'newsecret1',
        ])->assertNoContent();

        $this->postJson('/v1/auth/refresh', ['refreshToken' => $refresh])->assertStatus(401);
        $this->postJson('/v1/auth/login', ['email' => 'asha@example.com', 'password' => 'newsecret1'])->assertOk();
    }

    public function test_login_is_rate_limited(): void
    {
        foreach (range(1, 5) as $i) {
            $this->postJson('/v1/auth/login', ['email' => 'x@example.com', 'password' => 'whatever1']);
        }

        $this->postJson('/v1/auth/login', ['email' => 'x@example.com', 'password' => 'whatever1'])
            ->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
    }
}
