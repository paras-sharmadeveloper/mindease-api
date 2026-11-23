<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zoom Video SDK session tracking, filled by the Zoom webhook (session.started / user_joined / ended).
 * Times and ids only: nothing said in the call is stored, and recording stays off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('zoom_session_id')->nullable()->after('therapist_hidden_at');
            $table->timestamp('video_started_at')->nullable()->after('zoom_session_id');
            $table->timestamp('client_joined_at')->nullable()->after('video_started_at');
            $table->timestamp('therapist_joined_at')->nullable()->after('client_joined_at');
            $table->timestamp('video_ended_at')->nullable()->after('therapist_joined_at');
            $table->unsignedInteger('video_duration_seconds')->nullable()->after('video_ended_at');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['zoom_session_id', 'video_started_at', 'client_joined_at', 'therapist_joined_at', 'video_ended_at', 'video_duration_seconds']);
        });
    }
};
