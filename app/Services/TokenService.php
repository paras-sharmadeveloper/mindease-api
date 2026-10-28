<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Access token  = Sanctum personal access token, short TTL.
 * Refresh token = random 64-char string, stored hashed, rotated on every use.
 * All refresh tokens from one sign-in share a family_id; replaying a revoked
 * token revokes the whole family and its access tokens.
 */
class TokenService
{
    /** @return array{accessToken: string, refreshToken: string} */
    public function issue(User $user, ?string $familyId = null): array
    {
        $access = $user->createToken(
            'mobile',
            ['*'],
            now()->addMinutes(config('mindease.tokens.access_ttl_minutes')),
        );

        $plainRefresh = Str::random(64);

        RefreshToken::create([
            'user_id' => $user->id,
            'family_id' => $familyId ?? (string) Str::uuid(),
            'token_hash' => RefreshToken::hash($plainRefresh),
            'access_token_id' => $access->accessToken->id,
            'expires_at' => now()->addDays(config('mindease.tokens.refresh_ttl_days')),
        ]);

        return ['accessToken' => $access->plainTextToken, 'refreshToken' => $plainRefresh];
    }

    /** @return array{accessToken: string, refreshToken: string} */
    public function rotate(string $plainRefresh): array
    {
        $invalid = new ApiException('invalid_refresh_token', 'Your session has expired. Please sign in again.', 401);

        // Return null instead of throwing inside the transaction: a throw would roll back the family revoke.
        $issued = DB::transaction(function () use ($plainRefresh) {
            $token = RefreshToken::where('token_hash', RefreshToken::hash($plainRefresh))
                ->lockForUpdate()
                ->first();

            if (! $token) {
                return null;
            }

            if ($token->revoked_at !== null) {
                // Reuse of a rotated token => likely stolen. Kill the whole session family.
                $this->revokeFamily($token->family_id);

                return null;
            }

            if ($token->expires_at->isPast()) {
                $this->revokeFamily($token->family_id);

                return null;
            }

            $token->update(['revoked_at' => now()]);
            $this->deleteAccessToken($token->access_token_id);

            return $this->issue($token->user, $token->family_id);
        });

        return $issued ?? throw $invalid;
    }

    /** Logout: revoke the session family the refresh token belongs to. Silent if unknown. */
    public function revokeByPlain(string $plainRefresh): void
    {
        $token = RefreshToken::where('token_hash', RefreshToken::hash($plainRefresh))->first();

        if ($token) {
            $this->revokeFamily($token->family_id);
        }
    }

    public function revokeFamily(string $familyId): void
    {
        $tokens = RefreshToken::where('family_id', $familyId)->get();

        PersonalAccessToken::whereIn('id', $tokens->pluck('access_token_id')->filter())->delete();
        RefreshToken::where('family_id', $familyId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    /** Sign out everywhere (password reset, account delete). */
    public function revokeAll(User $user): void
    {
        $user->tokens()->delete();
        $user->refreshTokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    private function deleteAccessToken(?int $id): void
    {
        if ($id) {
            PersonalAccessToken::whereKey($id)->delete();
        }
    }
}
