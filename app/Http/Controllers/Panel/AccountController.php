<?php

namespace App\Http\Controllers\Panel;

use App\Exceptions\ApiException;
use App\Services\Booking\PanelPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AccountController extends PanelController
{
    /** { user, role, therapist | null }. The panel calls this right after /auth/login. */
    public function me(Request $request, PanelPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->me($request->user()->load('therapist')));
    }

    /** Change own password (therapists get a temporary one from an admin). */
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
