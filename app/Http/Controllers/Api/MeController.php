<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\TokenService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
     * Only `name` can change for now: changing the email needs a verification step first.
     */
    public function update(Request $request): UserResource
    {
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $data = $request->validate(
            ['name' => ['required', 'string', 'max:100']],
            ['name.required' => 'Please enter your name.'],
        );

        $user = $request->user();
        $user->forceFill(['name' => $data['name']])->save();

        return new UserResource($user);
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
