<?php

namespace App\Http\Controllers\Panel;

use App\Exceptions\ApiException;
use App\Services\MailCheck;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/** Admin: check that the server can send email (password reset, email change). */
#[Group('Email delivery', 'Admin only. Check the mail settings and send a test email.', weight: 9)]
class EmailController extends PanelController
{
    /**
     * Get email status.
     *
     * `{ settings, queue }`. `settings`: the mailer in use (never the password); `sendsEmail` is false when
     * MAIL_MAILER is `log`. `queue`: emails waiting to be sent and failed ones. Reset and email-change emails go
     * through the queue, so waiting emails that don't go down mean the queue worker isn't running.
     */
    public function show(MailCheck $check): JsonResponse
    {
        return response()->json(['settings' => $check->settings(), 'queue' => $check->queue()]);
    }

    /**
     * Send a test email.
     *
     * `{ to }`, defaults to your own email. Sent straight away, so SMTP problems (wrong password, port or
     * encryption) come back here as `502 email_failed` with the server's message. 10 per hour.
     */
    public function test(Request $request, MailCheck $check): JsonResponse
    {
        $to = $request->validate(['to' => ['nullable', 'string', 'email:rfc', 'max:255']])['to'] ?? $request->user()->email;

        $key = 'test-email:'.$request->user()->id;
        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw new ApiException('rate_limited', 'Too many test emails. Please wait a while and try again.', 429);
        }
        RateLimiter::hit($key, 3600);

        if ($error = $check->sendTest($to)) {
            throw new ApiException('email_failed', 'The email couldn\'t be sent: '.$error, 502);
        }

        return response()->json(['sent' => true, 'to' => $to, 'settings' => $check->settings()]);
    }
}
