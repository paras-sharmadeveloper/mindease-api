<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Profile screen fields. `name` stays (contract `user.name`, panel, bookings) and is kept equal to
 * "first last" by the User model. Existing names are split into first/last here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name', 50)->nullable()->after('name');
            $table->string('last_name', 50)->nullable()->after('first_name');
            $table->string('preferred_name', 50)->nullable()->after('last_name');
            $table->string('mobile', 16)->nullable()->after('email'); // E.164, e.g. +61412345678
            $table->string('pending_email')->nullable()->after('mobile'); // waiting for the verification link
            $table->boolean('reminders_enabled')->default(true)->after('pending_email');
        });

        DB::table('users')->select('id', 'name')->orderBy('id')->chunk(500, function ($users) {
            foreach ($users as $u) {
                [$first, $last] = array_pad(preg_split('/\s+/', trim((string) $u->name), 2), 2, null);
                DB::table('users')->where('id', $u->id)->update(['first_name' => $first ?: null, 'last_name' => $last ?: null]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name', 'preferred_name', 'mobile', 'pending_email', 'reminders_enabled']);
        });
    }
};
