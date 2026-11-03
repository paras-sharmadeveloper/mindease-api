<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/** Admin panel: Account → Email delivery. */
class PanelEmailTest extends ApiTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['email' => 'admin@mindease.me']);
        $this->admin->forceFill(['role' => User::ROLE_ADMIN])->save();
    }

    public function test_only_admins(): void
    {
        $therapist = User::factory()->create();
        $therapist->forceFill(['role' => User::ROLE_THERAPIST])->save();
        foreach ([User::factory()->create(), $therapist] as $u) {
            $this->signIn($u);
            $this->getJson('/v1/panel/email')->assertForbidden();
            $this->postJson('/v1/panel/email/test')->assertForbidden();
        }
    }

    public function test_status_shows_settings_without_the_password_and_the_queue(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.hostinger.com', 'mail.mailers.smtp.port' => 465,
            'mail.mailers.smtp.scheme' => 'smtps', 'mail.mailers.smtp.username' => 'no-reply@mindease.me',
            'mail.mailers.smtp.password' => 'super-secret-pass', 'mail.from.address' => 'no-reply@mindease.me', 'queue.default' => 'database']);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->timestamp, 'created_at' => now()->subMinutes(5)->timestamp]);
        DB::table('failed_jobs')->insert(['uuid' => 'u1', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}',
            'exception' => "Symfony\\Component\\Mailer\\Exception\\TransportException: Failed to authenticate\n#0 /var/www/stack/trace.php", 'failed_at' => now()]);

        $this->signIn($this->admin);
        $res = $this->getJson('/v1/panel/email')->assertOk()
            ->assertJsonPath('settings.mailer', 'smtp')->assertJsonPath('settings.host', 'smtp.hostinger.com')
            ->assertJsonPath('settings.port', 465)->assertJsonPath('settings.encryption', 'SSL')
            ->assertJsonPath('settings.sendsEmail', true)
            ->assertJsonPath('queue.connection', 'database')->assertJsonPath('queue.waiting', 1)->assertJsonPath('queue.failed', 1)
            ->assertJsonPath('queue.lastError', 'Symfony\\Component\\Mailer\\Exception\\TransportException: Failed to authenticate');
        $this->assertStringNotContainsString('super-secret-pass', $res->getContent());
        $this->assertStringNotContainsString('stack/trace', $res->getContent());
    }

    public function test_send_test_email_to_me_or_another_address(): void
    {
        Mail::fake();
        $this->signIn($this->admin);

        $this->postJson('/v1/panel/email/test')->assertOk()->assertJsonPath('sent', true)->assertJsonPath('to', 'admin@mindease.me');
        $this->postJson('/v1/panel/email/test', ['to' => 'someone@example.com'])->assertOk()->assertJsonPath('to', 'someone@example.com');
        $this->postJson('/v1/panel/email/test', ['to' => 'nope'])->assertStatus(422)->assertJsonPath('error.field', 'to');
    }

    public function test_smtp_errors_come_back_readable(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 2]);
        $this->signIn($this->admin);

        $res = $this->postJson('/v1/panel/email/test')->assertStatus(502)->assertJsonPath('error.code', 'email_failed');
        $this->assertStringStartsWith("The email couldn't be sent: ", $res->json('error.message'));
    }

    public function test_rate_limited(): void
    {
        Mail::fake();
        $this->signIn($this->admin);
        foreach (range(1, 10) as $i) {
            $this->postJson('/v1/panel/email/test')->assertOk();
        }
        $this->postJson('/v1/panel/email/test')->assertStatus(429);
    }
}
