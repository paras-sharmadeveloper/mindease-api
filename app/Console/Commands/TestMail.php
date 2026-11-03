<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends one plain email right away (not queued), to check the MAIL_* settings in .env.
 * SMTP errors (wrong password, port, encryption) are printed here instead of ending up in the queue log.
 */
class TestMail extends Command
{
    protected $signature = 'mindease:test-mail {to : Address to send the test email to}';

    protected $description = 'Send a test email with the current mail settings';

    public function handle(): int
    {
        $to = $this->argument('to');
        $mailer = config('mail.default');
        $smtp = config('mail.mailers.smtp');

        $this->line("Mailer: {$mailer}".($mailer === 'smtp'
            ? " ({$smtp['scheme']}://{$smtp['host']}:{$smtp['port']}, user {$smtp['username']})"
            : ''));
        $this->line('From:   '.config('mail.from.address').' ('.config('mail.from.name').')');

        if ($mailer === 'log') {
            $this->warn('MAIL_MAILER=log: emails are written to storage/logs, not sent. Set MAIL_MAILER=smtp to send them.');
        }

        try {
            Mail::send(['emails.test', 'emails.test-text'], [
                'mailer' => $mailer,
                'from' => config('mail.from.address'),
                'sentAt' => now()->toDayDateTimeString().' UTC',
            ], fn ($m) => $m->to($to)->subject('MindEase test email'));
        } catch (Throwable $e) {
            $this->error('Sending failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Sent to {$to}. Check the inbox (and the spam folder).");

        return self::SUCCESS;
    }
}
