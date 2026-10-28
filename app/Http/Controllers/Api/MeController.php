<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\TokenService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class MeController extends Controller
{
    /** Current user (handy for the app's splash/session check). */
    public function show(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    /**
     * Delete the account and ALL its data (Apple 5.1.1(v)). Every user table cascades on users.id.
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
