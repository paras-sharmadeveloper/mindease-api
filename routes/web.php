<?php

use App\Http\Controllers\DocsAuthController;
use App\Http\Middleware\EnsureDocsAccess;
use App\Models\User;
use App\Services\ProfilePhoto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Visitors opening the API host in a browser go to the docs (which ask for a sign-in).
Route::get('/', fn () => redirect('/docs/api'));

// Sign-in for the API docs (/docs/api, /docs/panel, /developers). Web session; only roles in mindease.docs.roles.
Route::get('docs/login', [DocsAuthController::class, 'show'])->middleware('throttle:docs')->name('docs.login');
Route::post('docs/login', [DocsAuthController::class, 'login'])->middleware('throttle:docs-login')->name('docs.login.submit');
Route::post('docs/logout', [DocsAuthController::class, 'logout'])->name('docs.logout');

// Developer portal: guides + reference + "Try it" console for the mobile team. Needs a docs sign-in.
Route::get('developers', function () {
    abort_unless(config('mindease.developer_docs'), 404);

    return view('developers', ['baseUrl' => url('/v1')]);
})->middleware(['throttle:docs', EnsureDocsAccess::class]);

// Password reset page: the reset email links here (MINDEASE_RESET_URL). It calls the public
// /v1/auth/password-reset/verify and /confirm endpoints, so all rules and rate limits stay in the API.
Route::get('reset-password', function () {
    $nonce = Str::random(24);

    return response()
        ->view('reset-password', [
            'nonce' => $nonce,
            'apiBase' => url('/v1'),
            'minutes' => config('auth.passwords.users.expire', 60),
        ])
        ->withHeaders([
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; script-src 'nonce-{$nonce}'; style-src 'nonce-{$nonce}'; "
                ."connect-src 'self'; img-src 'self' data:; form-action 'none'; frame-ancestors 'none'; base-uri 'none'",
        ]);
})->name('password.reset');

// Confirms an email change: the link in VerifyNewEmailNotification (signed, 60 minutes).
Route::get('verify-email-change/{user}/{hash}', function (Request $request, string $user, string $hash) {
    $account = Str::isUuid($user) ? User::find($user) : null;
    $ok = $request->hasValidSignature() && $account && $account->pending_email
        && hash_equals(sha1($account->pending_email), $hash);

    $status = 'expired';
    if ($ok) {
        if (User::where('email', $account->pending_email)->whereKeyNot($account->id)->exists()) {
            $status = 'taken';
        } else {
            DB::transaction(function () use ($account) {
                DB::table('password_reset_tokens')->where('email', $account->email)->delete();
                $account->forceFill(['email' => $account->pending_email, 'pending_email' => null])->save();
            });
            $status = 'done';
        }
    }

    return response()->view('email-change', ['status' => $status, 'email' => $account?->email])
        ->withHeaders([
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'",
        ]);
})->name('email.change.verify');

// Profile photos (random 40-character names, re-encoded JPEGs). A new upload gets a new name, so cache forever.
Route::get('avatars/{file}', function (string $file) {
    $path = ProfilePhoto::DIR.'/'.$file;
    abort_unless(Storage::disk('local')->exists($path), 404);

    return response(Storage::disk('local')->get($path), 200, [
        'Content-Type' => 'image/jpeg',
        'Cache-Control' => 'public, max-age=31536000, immutable',
        'X-Content-Type-Options' => 'nosniff',
    ]);
})->where('file', '[A-Za-z0-9]{40}\\.jpg');
