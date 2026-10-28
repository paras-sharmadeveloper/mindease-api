<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Web-panel admin for servers (staging/production):
 *   php artisan db:seed --class=AdminSeeder --force
 * The password comes from MINDEASE_ADMIN_PASSWORD in the server's .env, never from git (the repo is public).
 * Safe to re-run: an existing admin keeps the password they set later in the panel.
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        // Read via config, not env(): deploys cache the config, after which env() returns null.
        $email = strtolower(trim((string) config('mindease.admin.email')));
        $password = (string) config('mindease.admin.password');

        if (User::where('email', $email)->exists()) {
            User::where('email', $email)->first()->forceFill(['role' => User::ROLE_ADMIN])->save();
            $this->command?->info("Admin {$email} already exists (password unchanged).");

            return;
        }

        if (strlen($password) < 8) {
            $this->command?->warn('Set MINDEASE_ADMIN_PASSWORD (8+ characters) in .env to create the panel admin. Skipped.');

            return;
        }

        User::create(['name' => 'MindEase Admin', 'email' => $email, 'password' => $password])
            ->forceFill(['role' => User::ROLE_ADMIN])->save();

        $this->command?->info("Admin created: {$email}");
    }
}
