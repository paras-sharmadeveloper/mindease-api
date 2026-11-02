<?php

namespace App\Http\Controllers\Panel;

use App\Models\Booking;
use App\Models\Therapist;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends PanelController
{
    /** Counts for the panel home. Scoped to the therapist's own bookings unless admin. */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $bookings = fn () => Booking::query()->when(! $user->isAdmin(),
            fn ($q) => $q->whereHas('therapist', fn ($t) => $t->where('user_id', $user->id)));

        $today = now()->startOfDay();
        $appUsers = fn () => User::where('role', User::ROLE_USER);

        return response()->json([
            'upcoming' => $bookings()->where('status', Booking::CONFIRMED)->where('ends_at', '>', now())->count(),
            'next7Days' => $bookings()->where('status', Booking::CONFIRMED)->whereBetween('starts_at', [now(), now()->addDays(7)])->count(),
            'completedLast30Days' => $bookings()->where('status', Booking::COMPLETED)->where('starts_at', '>=', $today->copy()->subDays(30))->count(),
            'cancelledLast30Days' => $bookings()->where('status', Booking::CANCELLED)->where('cancel_reason', '!=', 'expired')
                ->where('cancelled_at', '>=', $today->copy()->subDays(30))->count(),
            'activeTherapists' => $user->isAdmin() ? Therapist::where('active', true)->count() : null,
            // App sign-ups (therapists and admins excluded). Deleted accounts drop out of these counts.
            'totalUsers' => $user->isAdmin() ? $appUsers()->count() : null,
            'newUsersLast24Hours' => $user->isAdmin() ? $appUsers()->where('created_at', '>=', now()->subDay())->count() : null,
            'newUsersLast7Days' => $user->isAdmin() ? $appUsers()->where('created_at', '>=', now()->subDays(7))->count() : null,
            'newUsersLast30Days' => $user->isAdmin() ? $appUsers()->where('created_at', '>=', now()->subDays(30))->count() : null,
        ]);
    }
}
