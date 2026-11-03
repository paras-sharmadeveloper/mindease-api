<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\Notification;

/** The web page the reset email opens (GET /reset-password). */
class ResetPasswordPageTest extends ApiTestCase
{
    public function test_page_loads_with_strict_headers(): void
    {
        $res = $this->get('/reset-password?token=abc&email=asha%40example.com')->assertOk()
            ->assertSee('Choose a new password')
            ->assertSee('/v1', false)
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Frame-Options', 'DENY');

        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $csp = $res->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'none'", $csp);
        preg_match("/script-src 'nonce-([^']+)'/", $csp, $m);
        $res->assertSee('<script nonce="'.$m[1].'">', false);
        // The token is read by the page's script; it is never printed into the HTML.
        $res->assertDontSee('abc', false);
    }

    public function test_reset_email_links_to_the_page(): void
    {
        config(['mindease.password_reset.url' => 'https://api.mindease.me/reset-password?token={token}&email={email}']);
        Notification::fake();
        $user = User::factory()->create(['email' => 'asha@example.com']);

        $this->postJson('/v1/auth/password-reset', ['email' => 'asha@example.com'])->assertStatus(202);

        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $n) use ($user) {
            $url = $n->toMail($user)->actionUrl;

            return str_starts_with($url, 'https://api.mindease.me/reset-password?token=')
                && str_ends_with($url, '&email=asha%40example.com');
        });
    }
}
