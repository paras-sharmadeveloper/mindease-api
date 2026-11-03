<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyNewEmailNotification;

/** MindEase-branded email templates (HTML + plain text). */
class EmailTemplateTest extends ApiTestCase
{
    public function test_reset_email_uses_the_mindease_template(): void
    {
        config(['mindease.password_reset.url' => 'https://api.mindease.me/reset-password?token={token}&email={email}']);
        $user = User::factory()->create(['name' => 'Bittu Kumar', 'email' => 'bittu@example.com']);
        $mail = (new ResetPasswordNotification('tok123'))->toMail($user);

        $html = (string) $mail->render();
        $this->assertStringContainsString('Reset your password', $html);
        $this->assertStringContainsString('Hi Bittu,', $html);
        $this->assertStringContainsString('href="https://api.mindease.me/reset-password?token=tok123&amp;email=bittu%40example.com"', $html);
        $this->assertStringNotContainsString('Laravel', $html);

        $text = view($mail->view[1], $mail->viewData)->render();
        $this->assertStringContainsString('https://api.mindease.me/reset-password?token=tok123&email=bittu%40example.com', $text);
        $this->assertStringNotContainsString('<', $text);
    }

    public function test_confirm_email_uses_the_mindease_template(): void
    {
        $user = User::factory()->create(['name' => 'Asha Patel']);
        $user->forceFill(['preferred_name' => 'Ash'])->save();
        $mail = (new VerifyNewEmailNotification($user, 'new@example.com'))->toMail($user);

        $html = (string) $mail->render();
        $this->assertStringContainsString('Confirm your new email', $html);
        $this->assertStringContainsString('Hi Ash,', $html);
        $this->assertStringContainsString('<b>new@example.com</b>', $html);
        $this->assertStringContainsString('/verify-email-change/', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }
}
