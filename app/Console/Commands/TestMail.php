<?php

namespace App\Console\Commands;

use App\Services\MailCheck;
use Illuminate\Console\Command;

/**
 * Sends one plain email right away (not queued), to check the MAIL_* settings in .env.
 * SMTP errors (wrong password, port, encryption) are printed here instead of ending up in the queue log.
 * Also reports queued emails that are waiting or failed (the admin panel shows the same under Account).
 */
class TestMail extends Command
{
    protected $signature = 'mindease:test-mail {to : Address to send the test email to}';

    protected $description = 'Send a test email with the current mail settings';

    public function handle(MailCheck $check): int
    {
        $to = $this->argument('to');
        $s = $check->settings();

        $this->line("Mailer: {$s['mailer']}".($s['host'] ? " ({$s['host']}:{$s['port']}, {$s['encryption']}, user {$s['username']})" : ''));
        $this->line("From:   {$s['fromAddress']} ({$s['fromName']})");
        if (! $s['sendsEmail']) {
            $this->warn("MAIL_MAILER={$s['mailer']}: emails are not sent. Set MAIL_MAILER=smtp to send them.");
        }

        if ($error = $check->sendTest($to)) {
            $this->error('Sending failed: '.$error);

            return self::FAILURE;
        }
        $this->info("Sent to {$to}. Check the inbox (and the spam folder).");

        $q = $check->queue();
        if ($q['waiting']) {
            $this->warn("{$q['waiting']} queued email(s) waiting (oldest {$q['oldestWaitingAt']}). If this doesn't go down, the queue worker isn't running: supervisorctl status mindease-worker");
        }
        if ($q['failed']) {
            $this->warn("{$q['failed']} failed job(s). Last: {$q['lastError']}");
        }

        return self::SUCCESS;
    }
}
