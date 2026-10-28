<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Queued, so /auth/password-reset takes the same time whether or not the email exists.
 */
class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = strtr(config('mindease.password_reset.url'), [
            '{token}' => urlencode($this->token),
            '{email}' => urlencode($notifiable->email),
        ]);

        $minutes = config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Reset your MindEase password')
            ->greeting('Hi '.strtok($notifiable->name, ' ').',')
            ->line('We received a request to reset your MindEase password.')
            ->action('Reset password', $url)
            ->line("This link expires in {$minutes} minutes.")
            ->line('If you didn\'t ask for this, you can ignore this email. Your password won\'t change.');
    }
}
