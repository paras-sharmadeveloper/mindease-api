<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards /docs/api, /docs/panel, their JSON documents and /developers.
 * Needs a web-session sign-in (/docs/login) by a user whose role is in `mindease.docs.roles`.
 * Applies in every environment, local included.
 */
class EnsureDocsAccess
{
    public const SIGNED_IN_AT = 'docs_signed_in_at';

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        if (! $user || ! self::allowed($user) || self::expired()) {
            if ($user) {
                // Role no longer qualifies, or the docs sign-in is past its time limit (if one is set).
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                $request->session()->flash('docs_status', 'Your session has ended. Please sign in again.');
            }

            if ($request->expectsJson() || str_ends_with($request->path(), '.json')) {
                return response()->json(['error' => ['code' => 'unauthenticated', 'message' => 'Sign in at /docs/login.', 'field' => null]], 401);
            }

            if ($request->isMethod('GET')) {
                $request->session()->put('url.intended', $request->fullUrl());
            }

            return redirect()->route('docs.login');
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'same-origin');

        return $response;
    }

    /** When the current docs sign-in ends, or null when there is no time limit (or no sign-in). */
    public static function expiresAt(): ?\Carbon\CarbonImmutable
    {
        $minutes = (int) config('mindease.docs.session_minutes', 0);
        $at = session(self::SIGNED_IN_AT);

        return $minutes > 0 && is_int($at)
            ? \Carbon\CarbonImmutable::createFromTimestamp($at)->addMinutes($minutes)
            : null;
    }

    /** Only with a time limit set (mindease.docs.session_minutes > 0). Otherwise the normal session lifetime applies. */
    public static function expired(): bool
    {
        if ((int) config('mindease.docs.session_minutes', 0) <= 0) {
            return false;
        }
        $expires = self::expiresAt();

        return ! $expires || $expires->isPast();
    }

    public static function allowed($user): bool
    {
        return in_array($user->role, (array) config('mindease.docs.roles', ['admin']), true);
    }
}
