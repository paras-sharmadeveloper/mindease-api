<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Notifications\VerifyNewEmailNotification;
use App\Services\ProfilePhoto;
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
     * `mobile` (or `phone`; Australian mobile, e.g. `0412 345 678`; saved and returned as `+61412345678`; `null` clears it),
     * `remindersEnabled`, `email` and `photo`.
     *
     * JSON with `PATCH /me`, or `multipart/form-data` with `POST /me` to include a `photo` file in the same request
     * (JPG, PNG or WebP, up to 8 MB). Nothing is saved if any field is invalid.
     * Returns the full `user`, including `firstName`, `lastName`, `email`, `mobile` and `photoUrl`.
     *
     * Changing `email` doesn't change it straight away: a confirmation link is emailed to the new address and the
     * response shows it as `pendingEmail` until the user opens the link. `409 email_taken` if another account uses it.
     */
    public function update(Request $request, ProfilePhoto $photos): UserResource
    {
        // PHP doesn't read form-data on PATCH, so the fields would silently be empty. Say so instead.
        if ($request->isMethod('PATCH') && str_contains((string) $request->header('Content-Type'), 'multipart/form-data')) {
            throw new ApiException('use_post_for_form_data', 'Send form-data (with a photo) to POST /me. PATCH /me takes JSON.', 415);
        }

        // Aliases and form-data values: `phone` = `mobile`; "true"/"false" strings for remindersEnabled.
        if ($request->has('phone') && ! $request->has('mobile')) {
            $request->merge(['mobile' => $request->input('phone')]);
        }
        if (is_string($request->input('remindersEnabled'))) {
            $flag = filter_var($request->input('remindersEnabled'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($flag !== null) {
                $request->merge(['remindersEnabled' => $flag]);
            }
        }
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
            'photo' => ['sometimes', 'file', 'max:8192'],
        ], [
            'firstName.required' => 'Please enter your first name.',
            'name.required' => 'Please enter your name.',
            'mobile.regex' => 'Enter an Australian mobile number, like 0412 345 678.',
            'email.required' => 'Please enter your email.',
            'email.email' => 'Please enter a valid email address.',
            'photo.file' => 'Choose a photo to upload.',
            'photo.max' => 'Choose a photo under 8 MB.',
        ]);

        // Check the photo before anything is saved, so a bad photo doesn't leave a half-saved profile.
        $jpeg = $request->hasFile('photo') ? $photos->encode($request->file('photo')) : null;

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

        $notifyEmail = array_key_exists('email', $data) ? $this->requestEmailChange($user, Str::lower($data['email'])) : null;

        $user->save();
        if ($jpeg !== null) {
            $photos->store($user, $jpeg);
        }
        if ($notifyEmail) {
            Notification::route('mail', $notifyEmail)->notify(new VerifyNewEmailNotification($user, $notifyEmail));
        }

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

    /** Sets pending_email and returns the address to send the confirmation link to (or null if nothing to send). */
    private function requestEmailChange(User $user, string $email): ?string
    {
        if ($email === $user->email) {
            $user->pending_email = null; // changed back: cancel a pending change

            return null;
        }
        if ($email === $user->pending_email) {
            return null; // already waiting for this one; don't send another email
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

        return $email;
    }

    /**
     * Upload my profile photo.
     *
     * `multipart/form-data` with a `photo` file: JPG, PNG or WebP, up to 8 MB. The server crops it to a square,
     * resizes it to 512x512 and removes photo metadata (such as location). Returns `user` with the new `photoUrl`;
     * the URL changes on every upload, so it is safe to cache.
     */
    public function uploadPhoto(Request $request, ProfilePhoto $photos): UserResource
    {
        $request->validate(
            ['photo' => ['required', 'file', 'max:8192']],
            ['photo.required' => 'Choose a photo to upload.', 'photo.file' => 'Choose a photo to upload.', 'photo.max' => 'Choose a photo under 8 MB.'],
        );
        $photos->replace($request->user(), $request->file('photo'));

        return new UserResource($request->user());
    }

    /**
     * Remove my profile photo.
     *
     * Returns `user` with `photoUrl: null`.
     */
    public function deletePhoto(Request $request, ProfilePhoto $photos): UserResource
    {
        $photos->remove($request->user());

        return new UserResource($request->user());
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
    public function destroy(Request $request, TokenService $tokens, ProfilePhoto $photos): Response
    {
        $user = $request->user();
        $photo = $user->photo_path;

        DB::transaction(function () use ($user, $tokens) {
            $tokens->revokeAll($user);
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            $user->delete();
        });
        $photos->deleteFile($photo);

        return response()->noContent();
    }
}
