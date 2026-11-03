<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Notifications\VerifyNewEmailNotification;
use App\Services\TokenService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

#[Group('Account', 'The signed-in user: profile, password and account deletion.', weight: 2)]
class MeController extends Controller
{
    /**
     * Get my account.
     *
     * The signed-in user. Use it for the splash-screen session check and on Profile.
     */
    public function show(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    /**
     * Update my profile.
     *
     * Send only the fields that changed: `firstName`, `lastName`, `preferredName` ("What should we call you?"),
     * `mobile` (Australian mobile, e.g. `0412 345 678`; saved and returned as `+61412345678`; `null` clears it),
     * `remindersEnabled` and `email`.
     *
     * Changing `email` doesn't change it straight away: a confirmation link is emailed to the new address and the
     * response shows it as `pendingEmail` until the user opens the link. `409 email_taken` if another account uses it.
     */
    public function update(Request $request): UserResource
    {
        foreach (['firstName', 'lastName', 'preferredName', 'name', 'email'] as $key) {
            if ($request->has($key) && is_string($request->input($key))) {
                $request->merge([$key => trim($request->input($key))]);
            }
        }
        if ($request->has('mobile') && is_string($request->input('mobile'))) {
            $request->merge(['mobile' => self::normaliseMobile($request->input('mobile'))]);
        }

        $data = $request->validate([
            'firstName' => ['sometimes', 'required', 'string', 'max:50'],
            'lastName' => ['sometimes', 'nullable', 'string', 'max:50'],
            'preferredName' => ['sometimes', 'nullable', 'string', 'max:50'],
            'name' => ['sometimes', 'required', 'string', 'max:100'], // older app builds
            'mobile' => ['sometimes', 'nullable', 'string', 'regex:/^\+614\d{8}$/'],
            'remindersEnabled' => ['sometimes', 'boolean'],
            'email' => ['sometimes', 'required', 'string', 'email:rfc', 'max:255'],
        ], [
            'firstName.required' => 'Please enter your first name.',
            'name.required' => 'Please enter your name.',
            'mobile.regex' => 'Enter an Australian mobile number, like 0412 345 678.',
            'email.required' => 'Please enter your email.',
            'email.email' => 'Please enter a valid email address.',
        ]);

        $user = $request->user();
        $columns = ['firstName' => 'first_name', 'lastName' => 'last_name', 'preferredName' => 'preferred_name', 'mobile' => 'mobile', 'remindersEnabled' => 'reminders_enabled'];
        foreach ($columns as $key => $column) {
            if (array_key_exists($key, $data)) {
                $user->{$column} = $data[$key] === '' ? null : $data[$key];
            }
        }
        if (array_key_exists('name', $data) && ! array_key_exists('firstName', $data)) {
            $user->name = $data['name'];
        }

        if (array_key_exists('email', $data)) {
            $this->requestEmailChange($user, Str::lower($data['email']));
        }

        $user->save();

        return new UserResource($user);
    }

    /** "0412 345 678", "+61 412 345 678", "61412345678" -> "+61412345678". Anything else is left for the validator to reject. */
    public static function normaliseMobile(string $raw): ?string
    {
        $digits = preg_replace('/[\s\-().]/', '', trim($raw));
        if ($digits === '') {
            return null;
        }

        return match (true) {
            (bool) preg_match('/^04\d{8}$/', $digits) => '+61'.substr($digits, 1),
            (bool) preg_match('/^\+?614\d{8}$/', $digits) => '+'.ltrim($digits, '+'),
            default => $digits,
        };
    }

    private function requestEmailChange(User $user, string $email): void
    {
        if ($email === $user->email) {
            $user->pending_email = null; // changed back: cancel a pending change

            return;
        }
        if ($email === $user->pending_email) {
            return; // already waiting for this one; don't send another email
        }
        if (User::where('email', $email)->whereKeyNot($user->id)->exists()) {
            throw new ApiException('email_taken', 'An account with this email already exists.', 409, 'email');
        }

        $key = 'email-change:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw new ApiException('rate_limited', 'Too many email changes. Please wait a while and try again.', 429, 'email');
        }
        RateLimiter::hit($key, 3600);

        $user->pending_email = $email;
        Notification::route('mail', $email)->notify(new VerifyNewEmailNotification($user, $email));
    }

    /**
     * Change my password.
     *
     * Needs the current password. This device stays signed in; every other device is signed out.
     */
    public function password(Request $request, TokenService $tokens): Response
    {
        $data = $request->validate([
            'currentPassword' => ['required', 'string', 'max:200'],
            'password' => ['required', 'string', 'min:8', 'max:200', 'different:currentPassword'],
        ], [
            'password.min' => 'Use at least 8 characters.',
            'password.different' => 'Choose a password that is different from your current one.',
        ]);

        $user = $request->user();
        if (! Hash::check($data['currentPassword'], $user->password)) {
            throw new ApiException('invalid_credentials', 'Your current password is incorrect.', 422, 'currentPassword');
        }

        $current = $user->currentAccessToken();

        DB::transaction(function () use ($user, $data, $tokens, $current) {
            $user->forceFill(['password' => $data['password']])->save();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete(); // old reset links stop working
            $tokens->revokeOtherSessions($user, $current instanceof PersonalAccessToken ? $current->id : null);
        });

        return response()->noContent();
    }

    /**
     * Delete my account.
     *
     * Permanently deletes the user and all their data (Apple 5.1.1(v)). This can't be undone.
     */
    public function destroy(Request $request, TokenService $tokens): Response
    {
        $user = $request->user();

        DB::transaction(function () use ($user, $tokens) {
            $tokens->revokeAll($user);
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            $user->delete();
        });

        return response()->noContent();
    }
}
