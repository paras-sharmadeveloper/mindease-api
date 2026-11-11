<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    // users.role. Set only by seeders and the admin panel; never mass-assignable.
    public const ROLE_USER = 'user';

    public const ROLE_THERAPIST = 'therapist';

    public const ROLE_ADMIN = 'admin';

    // Docs only (/docs/api, /docs/panel, /developers). No panel access; in the app it's an ordinary account.
    public const ROLE_DEVELOPER = 'developer';

    /**
     * Keeps `name` (contract `user.name`, panel, bookings) and first/last name in step:
     * setting first/last rebuilds `name`; setting only `name` (register, panel, seeders) splits it.
     */
    protected static function booted(): void
    {
        static::saving(function (User $user) {
            if ($user->isDirty(['first_name', 'last_name'])) {
                $user->name = trim($user->first_name.' '.$user->last_name) ?: $user->name;
            } elseif ($user->isDirty('name') || $user->first_name === null) {
                [$first, $last] = array_pad(preg_split('/\s+/', trim((string) $user->name), 2), 2, null);
                $user->first_name = $first ?: null;
                $user->last_name = $last ?: null;
            }
        });
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'reminders_enabled' => 'boolean',
        ];
    }

    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class);
    }

    public function assessment(): HasOne
    {
        return $this->hasOne(Assessment::class);
    }

    public function phaseCompletions(): HasMany
    {
        return $this->hasMany(PhaseCompletion::class);
    }

    public function therapist(): HasOne
    {
        return $this->hasOne(Therapist::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
