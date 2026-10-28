<?php

namespace App\Http\Controllers\Panel;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Therapist;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Shared access rules for the web panel. Admins see everything; a therapist sees only their own records. */
abstract class PanelController extends Controller
{
    protected function therapistFor(Request $request, string $therapistId): Therapist
    {
        abort_unless(Str::isUuid($therapistId), 404);
        $therapist = Therapist::with('user')->find($therapistId)
            ?? throw new ApiException('therapist_not_found', 'We couldn\'t find that therapist.', 404);

        $this->authorizeTherapist($request->user(), $therapist);

        return $therapist;
    }

    protected function authorizeTherapist(User $user, Therapist $therapist): void
    {
        if (! $user->isAdmin() && $therapist->user_id !== $user->id) {
            throw new ApiException('therapist_not_found', 'We couldn\'t find that therapist.', 404);
        }
    }
}
