<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Booking screen fields: what the user wants to focus on (focus-area keys) and a mobile number for the therapist. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->text('focus')->nullable()->after('notes');          // SENSITIVE: encrypted JSON list of keys (model cast)
            $table->text('contact_mobile')->nullable()->after('focus'); // encrypted (model cast), E.164
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['focus', 'contact_mobile']);
        });
    }
};
