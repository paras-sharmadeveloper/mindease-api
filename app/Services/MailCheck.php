<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Checks that email works: the settings in use (never the password), the queue that sends
 * password reset and email-change emails, and a test email sent straight away.
 * Used by `php artisan mindease:test-mail` and the admin panel (Account → Email delivery).
 */
class MailCheck
{
    /** @return array{mailer: string, host: ?string, port: ?int, encryption: ?string, username: ?string, fromAddress: ?string, fromName: ?string, sendsEmail: bool} */
    public function settings(): array
    {
        $mailer = (string) config('mail.default');
        $smtp = config('mail.mailers.smtp', []);
        $isSmtp = $mailer === 'smtp';

        return [
            'mailer' => $mailer,
            'host' => $isSmtp ? ($smtp['host'] ?? null) : null,
            'port' => $isSmtp ? (int) ($smtp['port'] ?? 0) ?: null : null,
            'encryption' => $isSmtp ? match ($smtp['scheme'] ?? null) {
                'smtps' => 'SSL',
                'smtp' => 'STARTTLS',
                default => null,
            } : null,
            'username' => $isSmtp ? ($smtp['username'] ?? null) : null,
            'fromAddress' => config('mail.from.address'),
            'fromName' => config('mail.from.name'),
            'sendsEmail' => ! in_array($mailer, ['log', 'array'], true),
        ];
    }

    /**
     * Emails that are waiting to be sent, and ones that failed. With QUEUE_CONNECTION=database, emails that stay
     * "waiting" for more than a minute or two mean the queue worker isn't running.
     *
     * @return array{connection: string, waiting: ?int, oldestWaitingAt: ?string, failed: ?int, lastFailedAt: ?string, lastError: ?string}
     */
    public function queue(): array
    {
        $connection = (string) config('queue.default');
        $result = ['connection' => $connection, 'waiting' => null, 'oldestWaitingAt' => null, 'failed' => null, 'lastFailedAt' => null, 'lastError' => null];

        try {
            if ($connection === 'database' && Schema::hasTable($table = config('queue.connections.database.table', 'jobs'))) {
                $result['waiting'] = DB::table($table)->count();
                $oldest = DB::table($table)->min('created_at');
                $result['oldestWaitingAt'] = $oldest ? date('Y-m-d\TH:i:s\Z', (int) $oldest) : null;
            }
            if (Schema::hasTable($failed = config('queue.failed.table', 'failed_jobs'))) {
                $result['failed'] = DB::table($failed)->count();
                $last = DB::table($failed)->orderByDesc('failed_at')->first(['failed_at', 'exception']);
                if ($last) {
                    $result['lastFailedAt'] = Carbon::parse($last->failed_at)->utc()->format('Y-m-d\TH:i:s\Z');
                    // First line only: the reason, without the stack trace.
                    $result['lastError'] = Str::limit(strtok((string) $last->exception, "\n"), 300);
                }
            }
        } catch (Throwable) {
            // Stats are best effort; never break the page for them.
        }

        return $result;
    }

    /** Sends the test email right away (not queued). Returns null when sent, or the error message. */
    public function sendTest(string $to): ?string
    {
        try {
            Mail::send(['emails.test', 'emails.test-text'], [
                'mailer' => config('mail.default'),
                'from' => config('mail.from.address'),
                'sentAt' => now()->toDayDateTimeString().' UTC',
            ], fn ($m) => $m->to($to)->subject('MindEase test email'));
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }
}
