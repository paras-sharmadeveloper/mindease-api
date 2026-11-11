<?php

namespace App\Notifications;

use App\Models\User;
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
        // Therapists and admins use the web panel, not the app: always our own reset page, which then links to the panel.
        $url = in_array($notifiable->role, [User::ROLE_THERAPIST, User::ROLE_ADMIN], true)
            ? route('password.reset', ['token' => $this->token, 'email' => $notifiable->email, 'for' => 'panel'])
            : strtr(config('mindease.password_reset.url'), [
                '{token}' => urlencode($this->token),
                '{email}' => urlencode($notifiable->email),
            ]);

        $minutes = config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Reset your MindEase password')
            ->action('Reset password', $url) // kept for tests and logs; the views below render the email
            ->view(['emails.reset-password', 'emails.reset-password-text'], [
                'name' => $notifiable->preferred_name ?: $notifiable->first_name ?: strtok((string) $notifiable->name, ' '),
                'url' => $url,
                'minutes' => $minutes,
            ]);
    }
}
