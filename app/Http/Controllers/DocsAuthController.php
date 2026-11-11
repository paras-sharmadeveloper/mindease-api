<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureDocsAccess;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** Sign-in for the API docs (/docs/api, /docs/panel, /developers). Web session, not Sanctum tokens. */
class DocsAuthController extends Controller
{
    public function show(Request $request)
    {
        $user = Auth::guard('web')->user();
        if ($user && EnsureDocsAccess::allowed($user) && ! EnsureDocsAccess::expired()) {
            return redirect()->intended('/docs/api');
        }

        return response()->view('docs-login')->withHeaders(self::headers());
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $user = User::where('email', mb_strtolower(trim($data['email'])))->first();

        // Same message for "no such user", "wrong password" and "role not allowed".
        // Hash check runs even without a user so response time doesn't reveal which emails exist.
        // (Hash::make costs the same as a check and uses the current driver, so argon2id servers work too.)
        if ($user) {
            $ok = Hash::check($data['password'], $user->password);
        } else {
            Hash::make($data['password']);
            $ok = false;
        }
        if (! $user || ! $ok || ! EnsureDocsAccess::allowed($user)) {
            throw ValidationException::withMessages(['email' => 'These details are not valid, or this isn\'t a developer account.']);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put(EnsureDocsAccess::SIGNED_IN_AT, now()->getTimestamp());

        return redirect()->intended('/docs/api');
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('docs.login');
    }

    public static function headers(): array
    {
        return [
            'Cache-Control' => 'no-store',
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'same-origin',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'",
        ];
    }
}
