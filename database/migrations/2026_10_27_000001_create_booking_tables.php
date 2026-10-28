<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 2: therapist booking (paid via Stripe) + Zoom Video SDK sessions.
     * All times are stored in UTC. Availability rows are wall-clock times in the therapist's timezone.
     */
    public function up(): void
    {
        // user | therapist | admin. Only the panel and seeders set it; never mass-assignable from the API.
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 16)->default('user')->after('email');
        });

        Schema::create('therapists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('bio')->nullable();
            $table->json('specialties');
            $table->string('photo_url')->nullable();
            $table->string('timezone', 64)->default('Australia/Sydney');
            $table->unsignedInteger('price_cents')->nullable(); // null = config default
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // Weekly recurring windows. weekday: 0 = Sunday ... 6 = Saturday (Carbon dayOfWeek).
        Schema::create('therapist_availability', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('therapist_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->time('start_time');
            $table->time('end_time');
            $table->index(['therapist_id', 'weekday']);
        });

        // Blocked periods (leave, public holidays), UTC.
        Schema::create('availability_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('therapist_id')->constrained()->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('reason')->nullable();
            $table->index(['therapist_id', 'starts_at']);
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->uuid('id')->primary();                // also the Zoom session name
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            // Restrict: deactivate a therapist instead of deleting them, so clients' bookings survive.
            $table->foreignUuid('therapist_id')->constrained()->restrictOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status', 16);                 // pending|confirmed|cancelled|completed|no_show
            $table->string('cancel_reason', 32)->nullable(); // user|expired|payment_failed|therapist
            // Equals starts_at while the booking holds the slot, null once cancelled.
            // Unique (therapist_id, slot_lock) stops double booking; NULLs never collide.
            $table->string('slot_lock', 32)->nullable();
            $table->timestamp('hold_expires_at')->nullable(); // unpaid pending holds lapse after this
            $table->unsignedInteger('price_cents');
            $table->string('currency', 3);
            $table->text('notes')->nullable();            // SENSITIVE: encrypted (model cast)
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['therapist_id', 'slot_lock']);
            $table->index(['user_id', 'starts_at']);
            $table->index(['status', 'hold_expires_at']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('booking_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_payment_intent_id')->unique();
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3);
            $table->string('status', 32);                 // Stripe PaymentIntent status, plus "refunded"
            $table->unsignedInteger('refunded_cents')->default(0);
            $table->timestamps();
        });

        // Each Stripe event is applied once (Stripe retries webhooks).
        Schema::create('stripe_webhook_events', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('type', 64);
            $table->timestamp('processed_at');
        });
    }

    public function down(): void
    {
        foreach (['stripe_webhook_events', 'payments', 'bookings', 'availability_exceptions',
            'therapist_availability', 'therapists'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
