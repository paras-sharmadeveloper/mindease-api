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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

#[Group('Account', 'The signed-in user: profile, password and account deletion.', weight: 2)]
class MeController extends Controller
{
    public const PHOTO_TOO_LARGE = 'This photo is too large. Choose one under 8 MB.';

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
     * The one endpoint for the profile screen's "Save changes". Send only the fields that changed:
     * `firstName`, `lastName`, `preferredName` ("What should we call you?"), `email`,
     * `mobile` (Australian mobile, e.g. `0412 345 678`; saved and returned as `+61412345678`; `null` clears it),
     * `remindersEnabled`, `photo` (file: JPG, PNG or WebP, up to 8 MB) and `removePhoto: true`.
     *
     * Send JSON, or `multipart/form-data` when it includes a `photo`. The photo is cropped to a square, resized to
     * 512x512 and stripped of metadata such as location. Nothing is saved if any field is invalid.
     * Returns the full `user`: `firstName`, `lastName`, `preferredName`, `email`, `mobile`, `photoUrl` and the rest.
     *
     * Changing `email` doesn't change it straight away: a confirmation link is emailed to the new address and the
     * response shows it as `pendingEmail` until the user opens the link. `409 email_taken` if another account uses it.
     */
    public function update(Request $request, ProfilePhoto $photos): UserResource
    {
        // Form-data sends booleans as "true"/"false" strings.
        foreach (['remindersEnabled', 'removePhoto'] as $key) {
            if (is_string($request->input($key))) {
                $flag = filter_var($request->input($key), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
                if ($flag !== null) {
                    $request->merge([$key => $flag]);
                }
            }
        }
        foreach (['firstName', 'lastName', 'preferredName', 'email'] as $key) {
            if ($request->has($key) && is_string($request->input($key))) {
                $request->merge([$key => trim($request->input($key))]);
            }
        }
        if ($request->has('mobile') && is_string($request->input('mobile'))) {
            $request->merge(['mobile' => self::normaliseMobile($request->input('mobile'))]);
        }

        // PHP itself refused the file (over upload_max_filesize): say it's too large, not "choose a photo".
        $upload = $request->file('photo');
        if ($upload instanceof UploadedFile && in_array($upload->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw ValidationException::withMessages(['photo' => self::PHOTO_TOO_LARGE]);
        }

        $data = $request->validate([
            'firstName' => ['sometimes', 'required', 'string', 'max:50'],
            'lastName' => ['sometimes', 'nullable', 'string', 'max:50'],
            'preferredName' => ['sometimes', 'nullable', 'string', 'max:50'],
            'mobile' => ['sometimes', 'nullable', 'string', 'regex:/^\+614\d{8}$/'],
            'remindersEnabled' => ['sometimes', 'boolean'],
            'email' => ['sometimes', 'required', 'string', 'email:rfc', 'max:255'],
            'photo' => ['sometimes', 'file', 'max:8192'],
            'removePhoto' => ['sometimes', 'boolean'],
        ], [
            'firstName.required' => 'Please enter your first name.',
            'mobile.regex' => 'Enter an Australian mobile number, like 0412 345 678.',
            'email.required' => 'Please enter your email.',
            'email.email' => 'Please enter a valid email address.',
            'photo.file' => 'Choose a photo to upload.',
            'photo.uploaded' => 'Choose a photo to upload.',
            'photo.max' => self::PHOTO_TOO_LARGE,
        ]);

        $user = $request->user();

        // Check the photo before anything is saved, so a bad photo doesn't leave a half-saved profile.
        $jpeg = null;
        if ($request->hasFile('photo')) {
            $key = 'photo:'.$user->id;
            if (RateLimiter::tooManyAttempts($key, 20)) {
                throw new ApiException('rate_limited', 'Too many photo uploads. Please wait a while and try again.', 429, 'photo');
            }
            RateLimiter::hit($key, 3600);
            $jpeg = $photos->encode($request->file('photo'));
        }

        $columns = ['firstName' => 'first_name', 'lastName' => 'last_name', 'preferredName' => 'preferred_name', 'mobile' => 'mobile', 'remindersEnabled' => 'reminders_enabled'];
        foreach ($columns as $key => $column) {
            if (array_key_exists($key, $data)) {
                $user->{$column} = $data[$key] === '' ? null : $data[$key];
            }
        }

        $notifyEmail = array_key_exists('email', $data) ? $this->requestEmailChange($user, Str::lower($data['email'])) : null;

        $user->save();
        if ($jpeg !== null) {
            $photos->store($user, $jpeg);
        } elseif (! empty($data['removePhoto'])) {
            $photos->remove($user);
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
