<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Web-panel admin for servers (staging/production):
 *   php artisan db:seed --class=AdminSeeder --force
 * Change the password in the panel (Account) after the first sign-in.
 * Safe to re-run: an existing admin keeps the password they set later.
 */
class AdminSeeder extends Seeder
{
    private const EMAIL = 'admin@mindease.me';

    private const PASSWORD = 'mindease@123!';

    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => self::EMAIL],
            ['name' => 'MindEase Admin', 'password' => self::PASSWORD],
        );
        $user->forceFill(['role' => User::ROLE_ADMIN])->save();

        $this->command?->info($user->wasRecentlyCreated
            ? 'Admin created: '.self::EMAIL.' (change the password after signing in)'
            : 'Admin '.self::EMAIL.' already exists (password unchanged).');
    }
}
