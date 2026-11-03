<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Sent to the NEW address when a user changes their email in the app. The email only changes
 * once they open the link, which proves they own the new address.
 */
class VerifyNewEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const MINUTES = 60;

    public function __construct(public User $user, public string $newEmail) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public static function link(User $user, string $newEmail): string
    {
        return URL::temporarySignedRoute('email.change.verify', now()->addMinutes(self::MINUTES), [
            'user' => $user->id,
            'hash' => sha1($newEmail),
        ]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = self::link($this->user, $this->newEmail);

        return (new MailMessage)
            ->subject('Confirm your new MindEase email')
            ->action('Confirm new email', $url) // kept for tests and logs; the views below render the email
            ->view(['emails.verify-new-email', 'emails.verify-new-email-text'], [
                'name' => $this->user->preferred_name ?: $this->user->first_name ?: strtok((string) $this->user->name, ' '),
                'email' => $this->newEmail,
                'url' => $url,
                'minutes' => self::MINUTES,
            ]);
    }
}
