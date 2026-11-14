<?php

namespace App\Http\Controllers\Panel;

use App\Models\Booking;
use App\Models\User;
use App\Services\ProfilePhoto;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

#[Group('App users', 'People who signed up in the app (admin only).', weight: 5)]
class UserController extends PanelController
{
    /**
     * List app sign-ups (admin).
     *
     * App accounts only (therapists, admins and developers are left out), newest first, 25 per page.
     * Each row has the sign-up time, whether the questionnaire is done, booking and phase counts, points, and
     * `lastActiveAt` (when the app last signed in or refreshed its token).
     * `total` is the number of rows matching the filter; `counts` are the totals for all sign-ups.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            /** Search name, email or mobile. */
            'q' => ['nullable', 'string', 'max:100'],
            /** Only sign-ups in the last day, 7 days or 30 days. */
            'period' => ['nullable', 'in:all,24h,7d,30d'],
            /** Page number, from 1. */
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $since = match ($data['period'] ?? 'all') {
            '24h' => now()->subDay(),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            default => null,
        };
        $appUsers = fn () => User::where('role', User::ROLE_USER);

        $q = trim($data['q'] ?? '');
        $page = $appUsers()
            ->when($since, fn ($query) => $query->where('created_at', '>=', $since))
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.addcslashes(strtolower($q), '%_\\').'%';
                $query->where(fn ($w) => $w->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$like])
                    ->orWhere('mobile', 'like', $like));
            })
            ->with('assessment:user_id,completed_at')
            ->withCount([
                'bookings',
                'bookings as sessions_completed_count' => fn ($b) => $b->where('status', Booking::COMPLETED),
                'phaseCompletions',
            ])
            ->withSum('phaseCompletions as points', 'points')
            ->orderByDesc('created_at')->orderBy('id')
            ->paginate(25);

        // Refresh tokens rotate on every refresh, so the newest one is when the app was last opened.
        $lastActive = DB::table('refresh_tokens')->whereIn('user_id', $page->pluck('id'))
            ->groupBy('user_id')->pluck(DB::raw('MAX(created_at)'), 'user_id');

        return response()->json([
            'data' => $page->getCollection()->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'mobile' => $u->mobile,
                'photoUrl' => ProfilePhoto::url($u->photo_path),
                'emailVerified' => $u->email_verified_at !== null,
                'signedUpAt' => $u->created_at?->toIso8601ZuluString(),
                'lastActiveAt' => isset($lastActive[$u->id]) ? Carbon::parse($lastActive[$u->id])->toIso8601ZuluString() : null,
                'questionnaireCompleted' => $u->assessment?->completed_at !== null,
                'bookings' => $u->bookings_count,
                'sessionsCompleted' => $u->sessions_completed_count,
                'phasesCompleted' => $u->phase_completions_count,
                'points' => (int) $u->points,
            ])->values(),
            'page' => $page->currentPage(),
            'perPage' => $page->perPage(),
            'lastPage' => $page->lastPage(),
            'total' => $page->total(),
            'counts' => [
                'all' => $appUsers()->count(),
                'last24Hours' => $appUsers()->where('created_at', '>=', now()->subDay())->count(),
                'last7Days' => $appUsers()->where('created_at', '>=', now()->subDays(7))->count(),
                'last30Days' => $appUsers()->where('created_at', '>=', now()->subDays(30))->count(),
            ],
        ]);
    }
}
