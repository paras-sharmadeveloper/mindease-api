<?php

namespace Tests\Feature;

use App\Models\User;

/** Bot protection: sign-up, app login, docs login and docs pages are rate limited. */
class RateLimitTest extends ApiTestCase
{
    public function test_sign_up_is_limited_per_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/v1/auth/register', ['email' => "bot{$i}@example.com", 'password' => 'secret123', 'name' => 'Bot User']);
        }

        $this->postJson('/v1/auth/register', ['email' => 'bot9@example.com', 'password' => 'secret123', 'name' => 'Bot User'])
            ->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');

        // Another IP can still sign up.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->postJson('/v1/auth/register', ['email' => 'real@example.com', 'password' => 'secret123', 'name' => 'Real User'])
            ->assertStatus(201);
    }

    public function test_sign_up_hourly_cap(): void
    {
        for ($i = 0; $i < 20; $i++) {
            if ($i > 0 && $i % 5 === 0) {
                $this->travel(61)->seconds();
            }
            $this->postJson('/v1/auth/register', ['email' => "bot{$i}@example.com", 'password' => 'secret123', 'name' => 'Bot User']);
        }
        $this->travel(61)->seconds();

        $this->postJson('/v1/auth/register', ['email' => 'bot99@example.com', 'password' => 'secret123', 'name' => 'Bot User'])
            ->assertStatus(429);
    }

    public function test_app_login_is_limited_per_email_across_ips(): void
    {
        User::factory()->create(['email' => 'asha@example.com']);

        for ($i = 0; $i < 30; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.1.'.intdiv($i, 250).'.'.($i % 250 + 1)])
                ->postJson('/v1/auth/login', ['email' => 'asha@example.com', 'password' => 'wrong']);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])
            ->postJson('/v1/auth/login', ['email' => 'asha@example.com', 'password' => 'password'])
            ->assertStatus(429);
    }

    public function test_docs_login_is_limited_per_email_across_ips(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.2.0.'.($i + 1)])
                ->post('/docs/login', ['email' => 'admin@example.com', 'password' => 'wrong']);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.2.1.1'])
            ->post('/docs/login', ['email' => 'admin@example.com', 'password' => 'x'])
            ->assertStatus(429);
    }

    public function test_docs_pages_are_limited_per_ip(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->get('/docs/login');
        }

        $this->get('/docs/login')->assertStatus(429);
        $this->get('/docs/api')->assertStatus(429);
    }
}
