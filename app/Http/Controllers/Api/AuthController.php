<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\TokenService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

#[Group('Auth', 'Sign up, sign in, refresh tokens, reset passwords and sign out. No bearer token needed.', weight: 1)]
class AuthController extends Controller
{
    /** Argon2id hash of a throwaway string; used to equalise timing for unknown emails. */
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$SWZ6N0tQRlpzUEoxY3E0NA$qYPzGPKoS6XDOYDoLggn4jBOpJBAvlgnGEwGxINw6QQ';

    public function __construct(private TokenService $tokens) {}

    /**
     * Create an account.
     *
     * Returns `201 { user, accessToken, refreshToken }`. `409 email_taken` (field: email) if the email is already registered.
     *
     * @unauthenticated
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();
        $taken = new ApiException('email_taken', 'An account with this email already exists.', 409, 'email');

        if (User::where('email', $data['email'])->exists()) {
            throw $taken;
        }

        try {
            $user = User::create($data);
        } catch (UniqueConstraintViolationException) {
            throw $taken; // two sign-ups racing on the same email
        }

        return response()->json($this->authPayload($user), 201);
    }

    /**
     * Sign in.
     *
     * Returns `{ user, accessToken, refreshToken }`. `401 invalid_credentials` uses one generic message, so it never reveals which part was wrong.
     * The web panel signs in here too, then calls `GET /panel/me`.
     *
     * @unauthenticated
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        $user = User::where('email', Str::lower(trim($data['email'])))->first();

        // Hash a dummy value when the user doesn't exist, so timing doesn't leak account existence.
        $valid = $user
            ? Hash::check($data['password'], $user->password)
            : (password_verify($data['password'], self::DUMMY_HASH) && false);

        if (! $valid) {
            throw new ApiException('invalid_credentials', 'That email and password don\'t match. Please try again.', 401);
        }

        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => $data['password']])->save();
        }

        return response()->json($this->authPayload($user));
    }

    /**
     * Refresh tokens.
     *
     * Exchanges a refresh token for a new pair. The old refresh token stops working; reusing it signs out that whole session.
     * Run one refresh at a time.
     *
     * @unauthenticated
     */
    public function refresh(Request $request): JsonResponse
    {
        $data = $request->validate(['refreshToken' => ['required', 'string', 'max:200']]);

        return response()->json($this->tokens->rotate($data['refreshToken']));
    }

    /**
     * Request a password reset email.
     *
     * Always `202`, so the response never reveals whether an account exists. The email links to
     * `mindease://reset-password?token=…&email=…`.
     *
     * @unauthenticated
     */
    public function requestPasswordReset(Request $request): Response
    {
        $data = $request->validate(['email' => ['required', 'string', 'email:rfc', 'max:255']]);

        Password::broker()->sendResetLink(['email' => Str::lower(trim($data['email']))]);

        return response()->noContent(202);
    }

    /**
     * Check a password reset link.
     *
     * Call it when the reset link opens, before asking for a new password. `204` if the token is valid (it is not used up),
     * `422 invalid_reset_token` if it is wrong, used or expired.
     *
     * @unauthenticated
     */
    public function verifyPasswordReset(Request $request): Response
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'token' => ['required', 'string', 'max:200'],
        ]);

        $broker = Password::broker();
        $user = $broker->getUser(['email' => Str::lower(trim($data['email']))]);

        if (! $user || ! $broker->tokenExists($user, $data['token'])) {
            throw new ApiException('invalid_reset_token', 'This reset link is invalid or has expired. Please request a new one.', 422, 'token');
        }

        return response()->noContent();
    }

    /**
     * Set a new password (reset).
     *
     * Uses the `token` and `email` from the reset link. Signs out every device. `422 invalid_reset_token` if the link is wrong, used or expired.
     *
     * @unauthenticated
     */
    public function confirmPasswordReset(Request $request): Response
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'token' => ['required', 'string', 'max:200'],
            'password' => ['required', 'string', 'min:8', 'max:200'],
        ], ['password.min' => 'Use at least 8 characters.']);

        $status = Password::broker()->reset(
            ['email' => Str::lower(trim($data['email'])), 'token' => $data['token'], 'password' => $data['password']],
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])->save();
                $this->tokens->revokeAll($user);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw new ApiException('invalid_reset_token', 'This reset link is invalid or has expired. Please request a new one.', 422, 'token');
        }

        return response()->noContent();
    }

    /**
     * Sign out.
     *
     * Revokes the session this refresh token belongs to. Always `204`; clear local tokens even if the call fails.
     *
     * @unauthenticated
     */
    public function logout(Request $request): Response
    {
        $data = $request->validate(['refreshToken' => ['required', 'string', 'max:200']]);

        $this->tokens->revokeByPlain($data['refreshToken']);

        return response()->noContent();
    }

    private function authPayload(User $user): array
    {
        return ['user' => new UserResource($user)] + $this->tokens->issue($user);
    }
}
