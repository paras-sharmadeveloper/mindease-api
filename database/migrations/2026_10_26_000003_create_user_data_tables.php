<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Everything here cascades on user delete (DELETE /me, App Store 5.1.1(v)).
     * program_id / phase_id / item ids are content ids (strings) - they must stay stable once live.
     */
    public function up(): void
    {
        // Questionnaire answers - SENSITIVE: `answers` is encrypted at rest (model cast).
        Schema::create('assessments', function (Blueprint $table) {
            $table->foreignUuid('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->text('answers');
            $table->unsignedSmallInteger('step')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->boolean('next_steps_seen')->default(false);
            $table->string('questionnaire_version', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('lesson_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('program_id', 64);
            $table->string('phase_id', 64);
            $table->string('lesson_id', 64);
            $table->timestamp('read_at');
            $table->unique(['user_id', 'program_id', 'lesson_id']);
        });

        Schema::create('video_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('program_id', 64);
            $table->string('phase_id', 64);
            $table->string('video_id', 64);
            $table->text('watched');                  // "1" per second actually played, OR-merged, never cleared
            $table->decimal('position', 10, 2)->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'program_id', 'video_id']);
        });

        // Journal responses - SENSITIVE: `answers` is encrypted at rest (model cast).
        Schema::create('activity_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('program_id', 64);
            $table->string('phase_id', 64);
            $table->string('activity_id', 64);
            $table->text('answers');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'program_id', 'phase_id']);
        });

        // Every attempt is stored. First pass time = earliest passed attempt.
        Schema::create('quiz_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('program_id', 64);
            $table->string('phase_id', 64);
            $table->string('quiz_id', 64);
            $table->json('answers');
            $table->unsignedSmallInteger('correct');
            $table->unsignedSmallInteger('total');
            $table->unsignedSmallInteger('score');
            $table->unsignedSmallInteger('threshold');
            $table->boolean('passed');
            $table->timestamp('created_at');
            $table->index(['user_id', 'program_id', 'phase_id']);
        });

        // Phase completion == points award. The unique key is what guarantees "exactly once".
        // This is the ONLY place points are written.
        Schema::create('phase_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('program_id', 64);
            $table->string('phase_id', 64);
            $table->unsignedInteger('points');
            $table->timestamp('completed_at');
            $table->unique(['user_id', 'program_id', 'phase_id']);
        });

        Schema::create('program_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('program_id', 64);
            $table->timestamp('completed_at');
            $table->unique(['user_id', 'program_id']);
        });

        // Idempotency-Key replays for POST /events. `response_body` is encrypted (it can contain journal text).
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('status_code')->nullable(); // null = still processing
            $table->longText('response_body')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        foreach (['idempotency_keys', 'program_completions', 'phase_completions', 'quiz_attempts',
            'activity_responses', 'video_progress', 'lesson_reads', 'assessments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
