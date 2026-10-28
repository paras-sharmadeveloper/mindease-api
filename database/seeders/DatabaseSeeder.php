<?php

namespace Database\Seeders;

use App\Models\Therapist;
use App\Models\User;
use App\Services\ContentImporter;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(ContentImporter $importer): void
    {
        foreach ($importer->importDirectory(database_path('content')) as $line) {
            $this->command?->info("Imported {$line}");
        }

        if (app()->environment('local')) {
            User::firstOrCreate(
                ['email' => 'demo@mindease.test'],
                ['name' => 'Demo User', 'password' => 'password123'],
            );
            $this->command?->info('Demo user: demo@mindease.test / password123');

            $therapistUser = User::firstOrCreate(
                ['email' => 'therapist@mindease.test'],
                ['name' => 'Dr Sam Lee', 'password' => 'password123'],
            );
            $therapistUser->forceFill(['role' => 'therapist'])->save();

            $therapist = Therapist::firstOrCreate(['user_id' => $therapistUser->id], [
                'bio' => 'Registered psychologist (sample profile).',
                'specialties' => ['anxiety', 'stress'],
                'timezone' => 'Australia/Sydney',
            ]);
            if (! $therapist->availability()->exists()) {
                foreach ([1, 2, 3, 4, 5] as $weekday) { // Mon-Fri 09:00-17:00 Sydney
                    $therapist->availability()->create(['weekday' => $weekday, 'start_time' => '09:00', 'end_time' => '17:00']);
                }
            }
            $this->command?->info('Demo therapist: therapist@mindease.test / password123');
        }
    }
}
