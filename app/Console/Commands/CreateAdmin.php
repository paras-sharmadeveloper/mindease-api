<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Creates the first web-panel admin on a server (seeders only create demo users locally),
 * or promotes an existing account. The password is asked for hidden, so it never lands in shell history.
 */
class CreateAdmin extends Command
{
    protected $signature = 'mindease:create-admin
        {email : Email to sign in with}
        {--name= : Full name (asked for if missing)}';

    protected $description = 'Create a web panel admin, or make an existing user an admin';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));
        if (Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->fails()) {
            $this->error('That email address isn\'t valid.');

            return self::FAILURE;
        }

        if ($user = User::where('email', $email)->first()) {
            if ($user->isAdmin()) {
                $this->info("{$email} is already an admin.");

                return self::SUCCESS;
            }
            if (! $this->confirm("{$email} exists with role \"{$user->role}\". Make it an admin?")) {
                return self::FAILURE;
            }
            $user->forceFill(['role' => User::ROLE_ADMIN])->save();
            $this->info("{$email} is now an admin. Sign in with the existing password.");

            return self::SUCCESS;
        }

        $name = $this->option('name') ?: $this->ask('Full name');
        $password = $this->secret('Password (at least 8 characters)');

        $validator = Validator::make(
            ['name' => $name, 'password' => $password, 'password_confirmation' => $this->secret('Repeat password')],
            ['name' => ['required', 'string', 'max:255'], 'password' => ['required', 'confirmed', Password::min(8)]],
        );
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        $user = User::create(['name' => $name, 'email' => $email, 'password' => $password]);
        $user->forceFill(['role' => User::ROLE_ADMIN])->save();

        $this->info("Admin {$email} created. Sign in to the web panel with this email and password.");

        return self::SUCCESS;
    }
}
