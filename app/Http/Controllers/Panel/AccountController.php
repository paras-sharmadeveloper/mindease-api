<?php

namespace App\Http\Controllers\Panel;

use App\Exceptions\ApiException;
use App\Services\Booking\PanelPresenter;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

#[Group('Panel account', 'The signed-in therapist or admin.', weight: 2)]
class AccountController extends PanelController
{
    /**
     * Get my panel account.
     *
     * `{ user, role, therapist | null }`. Call it right after `POST /auth/login`; app users (role `user`) get `403`.
     */
    public function me(Request $request, PanelPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->me($request->user()->load('therapist')));
    }

    /**
     * Change my panel password.
     *
     * Therapists get a temporary password from an admin and change it here.
     */
    public function password(Request $request): Response
    {
        $data = $request->validate([
            'currentPassword' => ['required', 'string'],
            'password' => ['required', 'string', Password::min(8), 'different:currentPassword'],
        ]);

        $user = $request->user();
        if (! Hash::check($data['currentPassword'], $user->password)) {
            throw new ApiException('invalid_credentials', 'Your current password is incorrect.', 422, 'currentPassword');
        }

        $user->forceFill(['password' => $data['password']])->save();

        return response()->noContent();
    }
}
