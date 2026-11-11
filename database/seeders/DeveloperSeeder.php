<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Developer account for the API docs (/docs/api, /docs/panel, /developers), e.g. for the mobile team.
 * Set both in .env, then run it:
 *   MINDEASE_DEV_EMAIL=dev@mindease.me
 *   MINDEASE_DEV_PASSWORD=...   (at least 8 characters)
 *   php artisan config:clear && php artisan db:seed --class=DeveloperSeeder --force
 *
 * No email or password in the code. `developer` has no web panel access.
 * Safe to re-run: an existing account keeps the password it has, and becomes a developer if it was an app user.
 */
class DeveloperSeeder extends Seeder
{
    public function run(): void
    {
        // Read through config: env() returns null once the config is cached on a server.
        $email = Str::lower(trim((string) config('mindease.docs.developer_email')));
        $password = (string) config('mindease.docs.developer_password');

        $problem = match (true) {
            Validator::make(['e' => $email], ['e' => ['required', 'email', 'max:255']])->fails() => 'MINDEASE_DEV_EMAIL is missing or not a valid email.',
            mb_strlen($password) < 8 || mb_strlen($password) > 255 => 'MINDEASE_DEV_PASSWORD is missing or shorter than 8 characters.',
            default => null,
        };
        if ($problem) {
            $this->command?->error($problem.' Set it in .env, run php artisan config:clear, then run this seeder again. Nothing was created.');

            return;
        }

        $user = User::where('email', $email)->first();

        if ($user) {
            if (in_array($user->role, [User::ROLE_ADMIN, User::ROLE_THERAPIST], true)) {
                $this->command?->warn("{$email} is a {$user->role}; left unchanged. Use another MINDEASE_DEV_EMAIL.");

                return;
            }
            $user->forceFill(['role' => User::ROLE_DEVELOPER])->save();
            $this->command?->info("Developer {$email} already exists (password unchanged).");

            return;
        }

        $user = User::create(['name' => 'MindEase Developer', 'email' => $email, 'password' => $password]);
        $user->forceFill(['role' => User::ROLE_DEVELOPER, 'email_verified_at' => now()])->save();

        $this->command?->info("Developer created: {$email}. Sign in at ".url('/docs/login'));
    }
}
