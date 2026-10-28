<?php

use App\Models\IdempotencyKey;
use App\Models\RefreshToken;
use Illuminate\Support\Facades\Schedule;

// Housekeeping (needs `php artisan schedule:work` locally, or a cron running `schedule:run` every minute).
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('model:prune', ['--model' => [IdempotencyKey::class]])->hourly();
Schedule::call(fn () => RefreshToken::where('expires_at', '<', now()->subDays(7))
    ->orWhere('revoked_at', '<', now()->subDays(7))->delete())->daily()->name('prune-refresh-tokens');
Schedule::command('auth:clear-resets')->everyFifteenMinutes();
Schedule::command('mindease:expire-booking-holds')->everyMinute()->withoutOverlapping();
