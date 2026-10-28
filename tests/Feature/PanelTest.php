<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Therapist;
use App\Models\User;
use App\Services\Payments\PaymentGateway;
use Illuminate\Support\Carbon;
use Tests\Fakes\FakePaymentGateway;

/** Web panel API (/v1/panel): admins manage therapists; therapists manage their own hours and sessions. */
class PanelTest extends ApiTestCase
{
    private FakePaymentGateway $stripe;

    private User $admin;

    private User $therapistUser;

    private Therapist $therapist;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-02T00:00:00Z'));
        $this->stripe = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->stripe);
        config([
            'services.zoom.sdk_key' => 'zoom_key',
            'services.zoom.sdk_secret' => 'zoom_secret_that_is_long_enough_for_hs256',
        ]);

        $this->admin = User::factory()->create();
        $this->admin->forceFill(['role' => User::ROLE_ADMIN])->save();
        $this->therapistUser = User::factory()->create(['name' => 'Dr Sam Lee']);
        $this->therapistUser->forceFill(['role' => User::ROLE_THERAPIST])->save();
        $this->therapist = Therapist::create(['user_id' => $this->therapistUser->id, 'specialties' => ['anxiety'], 'timezone' => 'Australia/Sydney']);
    }

    private function confirmedBooking(string $startsAt = '2026-11-03T22:00:00Z', ?User $client = null, ?Therapist $therapist = null): Booking
    {
        $start = Carbon::parse($startsAt);
        $booking = Booking::create([
            'user_id' => ($client ?? User::factory()->create(['name' => 'Alex Client']))->id,
            'therapist_id' => ($therapist ?? $this->therapist)->id,
            'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes(50),
            'status' => Booking::CONFIRMED, 'slot_lock' => Booking::slotLock($start->toImmutable()),
            'price_cents' => 15000, 'currency' => 'aud', 'notes' => 'Private note', 'confirmed_at' => now(),
        ]);
        Payment::create([
            'booking_id' => $booking->id, 'user_id' => $booking->user_id, 'stripe_payment_intent_id' => 'pi_'.$booking->id,
            'amount_cents' => 15000, 'currency' => 'aud', 'status' => 'succeeded',
        ]);

        return $booking;
    }

    public function test_app_users_cannot_use_the_panel(): void
    {
        $this->signIn();
        $this->getJson('/v1/panel/me')->assertForbidden()->assertJsonPath('error.code', 'forbidden');

        $this->signIn($this->therapistUser);
        $this->getJson('/v1/panel/me')->assertOk()->assertJsonPath('role', 'therapist')->assertJsonPath('therapist.id', $this->therapist->id);
        $this->getJson('/v1/panel/therapists')->assertForbidden();
        $this->postJson('/v1/panel/therapists', [])->assertForbidden();
    }

    public function test_admin_adds_a_therapist_who_can_then_sign_in(): void
    {
        $this->signIn($this->admin);

        $id = $this->postJson('/v1/panel/therapists', [
            'name' => 'Dr Jo Park', 'email' => 'Jo@Example.com', 'password' => 'temporary-pass',
            'bio' => 'Clinical psychologist', 'specialties' => ['grief'], 'timezone' => 'Australia/Perth', 'priceCents' => 18000,
        ])->assertCreated()
            ->assertJsonPath('email', 'jo@example.com')->assertJsonPath('priceCents', 18000)->assertJsonPath('customPrice', true)
            ->assertJsonPath('active', true)->json('id');

        $this->postJson('/v1/panel/therapists', ['name' => 'X', 'email' => 'jo@example.com', 'password' => 'temporary-pass'])
            ->assertUnprocessable()->assertJsonPath('error.field', 'email');

        $this->assertSame('therapist', User::where('email', 'jo@example.com')->value('role'));
        $this->getJson('/v1/panel/therapists')->assertOk()->assertJsonCount(2);

        // New therapists have no hours yet, so the app offers no slots.
        $this->getJson("/v1/therapists/{$id}/slots")->assertOk()->assertJsonCount(0, 'slots');

        $this->app['auth']->forgetGuards();
        $this->postJson('/v1/auth/login', ['email' => 'jo@example.com', 'password' => 'temporary-pass'])->assertOk();
    }

    public function test_admin_can_deactivate_and_reset_password(): void
    {
        $this->signIn($this->admin);

        $this->patchJson("/v1/panel/therapists/{$this->therapist->id}", ['active' => false, 'priceCents' => null, 'password' => 'brand-new-pass'])
            ->assertOk()->assertJsonPath('active', false)->assertJsonPath('customPrice', false);

        $this->getJson('/v1/therapists')->assertOk()->assertJsonCount(0);
        $this->assertTrue(password_verify('brand-new-pass', $this->therapistUser->refresh()->password));
    }

    public function test_therapist_edits_only_own_profile_fields(): void
    {
        $other = Therapist::create(['user_id' => User::factory()->create()->id, 'specialties' => []]);
        $this->signIn($this->therapistUser);

        $this->patchJson("/v1/panel/therapists/{$this->therapist->id}", ['bio' => 'Updated', 'priceCents' => 1, 'active' => false])
            ->assertOk()->assertJsonPath('bio', 'Updated')->assertJsonPath('priceCents', 15000)->assertJsonPath('active', true);

        $this->patchJson("/v1/panel/therapists/{$other->id}", ['bio' => 'Nope'])->assertNotFound();
        $this->getJson("/v1/panel/therapists/{$other->id}/availability")->assertNotFound();
    }

    public function test_weekly_hours_and_blocked_periods_drive_slots(): void
    {
        $this->signIn($this->therapistUser);
        $url = "/v1/panel/therapists/{$this->therapist->id}/availability";

        $this->putJson($url, ['windows' => [['weekday' => 2, 'start' => '09:00', 'end' => '12:00']]])
            ->assertOk()->assertJsonPath('windows.0', ['weekday' => 2, 'start' => '09:00', 'end' => '12:00']);

        // Tue 3 Nov Sydney 09:00-12:00 = 3 slots (50 min + 10 min buffer).
        $slots = fn () => $this->getJson("/v1/therapists/{$this->therapist->id}/slots?from=2026-11-02T12:00:00Z&to=2026-11-04T00:00:00Z")->json('slots');
        $this->assertCount(3, $slots());

        $this->putJson($url, ['windows' => [['weekday' => 2, 'start' => '09:00', 'end' => '12:00'], ['weekday' => 2, 'start' => '11:00', 'end' => '13:00']]])
            ->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
        $this->putJson($url, ['windows' => [['weekday' => 2, 'start' => '12:00', 'end' => '09:00']]])->assertUnprocessable();

        $exceptionId = $this->postJson("/v1/panel/therapists/{$this->therapist->id}/exceptions", [
            'startsAt' => '2026-11-02T22:00:00Z', 'endsAt' => '2026-11-03T00:00:00Z', 'reason' => 'Leave',
        ])->assertCreated()->json('exceptions.0.id');
        $this->assertCount(1, $slots());

        $this->deleteJson("/v1/panel/therapists/{$this->therapist->id}/exceptions/{$exceptionId}")->assertNoContent();
        $this->assertCount(3, $slots());
    }

    public function test_bookings_are_scoped_and_notes_stay_with_the_therapist(): void
    {
        $mine = $this->confirmedBooking();
        $otherTherapist = Therapist::create(['user_id' => User::factory()->create()->id, 'specialties' => []]);
        $theirs = $this->confirmedBooking('2026-11-04T22:00:00Z', therapist: $otherTherapist);

        $this->signIn($this->therapistUser);
        $this->getJson('/v1/panel/bookings')->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.id', $mine->id)->assertJsonPath('0.client.name', 'Alex Client')
            ->assertJsonPath('0.notes', 'Private note')->assertJsonPath('0.canJoin', true);
        $this->getJson("/v1/panel/bookings/{$theirs->id}")->assertNotFound();

        $this->signIn($this->admin);
        $this->getJson('/v1/panel/bookings')->assertOk()->assertJsonCount(2)->assertJsonPath('0.notes', null)->assertJsonPath('0.canJoin', false);
        $this->getJson("/v1/panel/bookings?therapistId={$otherTherapist->id}")->assertOk()->assertJsonCount(1);
        $this->getJson('/v1/panel/dashboard')->assertOk()->assertJsonPath('upcoming', 2)->assertJsonPath('activeTherapists', 2);
    }

    public function test_therapist_cancel_refunds_in_full_even_late(): void
    {
        $booking = $this->confirmedBooking('2026-11-02T22:00:00Z'); // 22h away: a user cancel would get no refund
        $this->signIn($this->therapistUser);

        $this->postJson("/v1/panel/bookings/{$booking->id}/cancel")->assertOk()
            ->assertJsonPath('status', 'cancelled')->assertJsonPath('cancelReason', 'therapist')
            ->assertJsonPath('payment.refundedCents', 15000);
    }

    public function test_outcome_only_after_start(): void
    {
        $booking = $this->confirmedBooking();
        $this->signIn($this->therapistUser);

        $this->postJson("/v1/panel/bookings/{$booking->id}/outcome", ['status' => 'completed'])->assertStatus(409)->assertJsonPath('error.code', 'booking_not_started');

        $this->travelTo(Carbon::parse('2026-11-03T22:30:00Z'));
        $this->postJson("/v1/panel/bookings/{$booking->id}/outcome", ['status' => 'no_show'])->assertOk()->assertJsonPath('status', 'no_show');
        $this->postJson("/v1/panel/bookings/{$booking->id}/outcome", ['status' => 'completed'])->assertOk()->assertJsonPath('status', 'completed');
        $this->postJson("/v1/panel/bookings/{$booking->id}/outcome", ['status' => 'cancelled'])->assertUnprocessable();
    }

    public function test_only_the_sessions_therapist_gets_a_host_token(): void
    {
        $booking = $this->confirmedBooking();
        $this->travelTo(Carbon::parse('2026-11-03T21:55:00Z'));

        $this->signIn($this->admin);
        $this->postJson("/v1/panel/bookings/{$booking->id}/video-token")->assertForbidden();

        $this->signIn($this->therapistUser);
        $this->postJson("/v1/panel/bookings/{$booking->id}/video-token")->assertOk()
            ->assertJsonPath('role', 'host')->assertJsonPath('sessionName', $booking->id)->assertJsonPath('displayName', 'Dr Sam Lee');
    }

    public function test_change_own_password(): void
    {
        $this->therapistUser->forceFill(['password' => 'old-password'])->save();
        $this->signIn($this->therapistUser);

        $this->putJson('/v1/panel/me/password', ['currentPassword' => 'wrong', 'password' => 'new-password'])
            ->assertUnprocessable()->assertJsonPath('error.field', 'currentPassword');
        $this->putJson('/v1/panel/me/password', ['currentPassword' => 'old-password', 'password' => 'new-password'])->assertNoContent();
    }

    public function test_create_admin_command(): void
    {
        $this->artisan('mindease:create-admin', ['email' => 'Owner@Example.com', '--name' => 'Owner'])
            ->expectsQuestion('Password (at least 8 characters)', 'long-enough-pass')
            ->expectsQuestion('Repeat password', 'long-enough-pass')
            ->assertSuccessful();
        $this->assertSame('admin', User::where('email', 'owner@example.com')->value('role'));

        $this->artisan('mindease:create-admin', ['email' => 'short@example.com', '--name' => 'X'])
            ->expectsQuestion('Password (at least 8 characters)', 'short')
            ->expectsQuestion('Repeat password', 'short')
            ->assertFailed();

        $this->artisan('mindease:create-admin', ['email' => $this->therapistUser->email])
            ->expectsConfirmation("{$this->therapistUser->email} exists with role \"therapist\". Make it an admin?", 'yes')
            ->assertSuccessful();
        $this->assertTrue($this->therapistUser->refresh()->isAdmin());
    }
}
