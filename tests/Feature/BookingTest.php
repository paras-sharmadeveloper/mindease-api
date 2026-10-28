<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Therapist;
use App\Models\User;
use App\Services\Payments\PaymentGateway;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Fakes\FakePaymentGateway;

/**
 * Now = Mon 2026-11-02 00:00Z = Mon 11:00 Sydney (AEDT, +11).
 * Therapist works Mon-Fri 09:00-17:00 Sydney; 50 min sessions every 60 min.
 */
class BookingTest extends ApiTestCase
{
    private const TUE_9AM = '2026-11-02T22:00:00.000Z'; // 22h ahead: bookable, inside 24h refund cutoff

    private const WED_9AM = '2026-11-03T22:00:00.000Z'; // 46h ahead: full refund on cancel

    private const WEBHOOK_SECRET = 'whsec_test';

    private FakePaymentGateway $stripe;

    private Therapist $therapist;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-02T00:00:00Z'));
        $this->stripe = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->stripe);
        config([
            'services.stripe.webhook_secret' => self::WEBHOOK_SECRET,
            'services.stripe.publishable_key' => 'pk_test_123',
            'services.zoom.sdk_key' => 'zoom_key',
            'services.zoom.sdk_secret' => 'zoom_secret_that_is_long_enough_for_hs256',
        ]);

        $this->therapist = Therapist::create([
            'user_id' => User::factory()->create(['name' => 'Dr Sam Lee'])->id,
            'specialties' => ['anxiety'],
            'timezone' => 'Australia/Sydney',
        ]);
        foreach ([1, 2, 3, 4, 5] as $weekday) {
            $this->therapist->availability()->create(['weekday' => $weekday, 'start_time' => '09:00', 'end_time' => '17:00']);
        }
    }

    private function book(string $startsAt = self::TUE_9AM, ?string $key = null, array $extra = [])
    {
        return $this->postJson('/v1/me/bookings', ['therapistId' => $this->therapist->id, 'startsAt' => $startsAt] + $extra, [
            'Idempotency-Key' => $key ?? (string) Str::uuid(),
        ]);
    }

    private function webhook(string $type, array $object, ?string $id = null, ?string $signature = null)
    {
        $payload = json_encode([
            'id' => $id ?? 'evt_'.Str::random(12), 'object' => 'event', 'type' => $type,
            'data' => ['object' => $object],
        ]);
        $t = time();
        $signature ??= "t={$t},v1=".hash_hmac('sha256', "{$t}.{$payload}", self::WEBHOOK_SECRET);

        return $this->call('POST', '/v1/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature,
        ], $payload);
    }

    private function pay(string $bookingId): void
    {
        $intent = Booking::findOrFail($bookingId)->payment->stripe_payment_intent_id;
        $this->webhook('payment_intent.succeeded', ['id' => $intent, 'object' => 'payment_intent', 'status' => 'succeeded'])->assertNoContent();
    }

    private function confirmedBooking(string $startsAt = self::TUE_9AM): string
    {
        $id = $this->book($startsAt)->assertCreated()->json('booking.id');
        $this->pay($id);

        return $id;
    }

    public function test_lists_active_therapists_and_slots(): void
    {
        $this->signIn();
        Therapist::create(['user_id' => User::factory()->create()->id, 'specialties' => [], 'active' => false]);

        $this->getJson('/v1/therapists')->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Dr Sam Lee')->assertJsonPath('0.priceCents', 15000)->assertJsonPath('0.currency', 'aud');

        $slots = $this->getJson("/v1/therapists/{$this->therapist->id}/slots?from=2026-11-02T00:00:00Z&to=2026-11-03T12:00:00Z")
            ->assertOk()->json('slots');

        // Mon 11:00-17:00 Sydney is inside the 12h notice; Tue 09:00..16:00 = 8 slots.
        $this->assertCount(8, $slots);
        $this->assertSame(self::TUE_9AM, $slots[0]['startsAt']);
        $this->assertSame('2026-11-02T22:50:00.000Z', $slots[0]['endsAt']);
    }

    public function test_shows_one_therapist_profile(): void
    {
        $this->signIn();
        $hidden = Therapist::create(['user_id' => User::factory()->create()->id, 'specialties' => [], 'active' => false]);

        $this->getJson("/v1/therapists/{$this->therapist->id}")->assertOk()
            ->assertJsonPath('id', $this->therapist->id)->assertJsonPath('name', 'Dr Sam Lee');
        $this->getJson("/v1/therapists/{$hidden->id}")->assertNotFound()->assertJsonPath('error.code', 'therapist_not_found');
        $this->getJson('/v1/therapists/not-a-uuid')->assertNotFound();
    }

    public function test_slots_follow_daylight_saving(): void
    {
        $this->signIn();
        $this->travelTo(Carbon::parse('2026-03-30T00:00:00Z')); // Sydney leaves DST on Sun 5 April 2026

        $slots = collect($this->getJson("/v1/therapists/{$this->therapist->id}/slots?from=2026-04-02T00:00:00Z&to=2026-04-07T00:00:00Z")->json('slots'))
            ->pluck('startsAt');

        $this->assertContains('2026-04-02T22:00:00.000Z', $slots); // Fri 3 Apr 09:00 AEDT (+11)
        $this->assertContains('2026-04-05T23:00:00.000Z', $slots); // Mon 6 Apr 09:00 AEST (+10)
    }

    public function test_booking_holds_slot_and_starts_payment_at_server_price(): void
    {
        $user = $this->signIn();

        $res = $this->book(extra: ['priceCents' => 1, 'notes' => 'First session'])->assertCreated()
            ->assertJsonPath('booking.status', 'pending')
            ->assertJsonPath('booking.priceCents', 15000)
            ->assertJsonPath('booking.holdExpiresAt', '2026-11-02T00:15:00.000Z')
            ->assertJsonPath('booking.notes', 'First session')
            ->assertJsonPath('payment.publishableKey', 'pk_test_123');

        $intent = array_values($this->stripe->intents)[0];
        $this->assertSame(15000, $intent['amount']);
        $this->assertSame(['booking_id' => $res->json('booking.id'), 'user_id' => $user->id], $intent['metadata']);
        $this->assertStringStartsWith($intent['id'], $res->json('payment.clientSecret'));

        // Notes are encrypted at rest.
        $this->assertNotSame('First session', DB::table('bookings')->value('notes'));

        $slots = $this->getJson("/v1/therapists/{$this->therapist->id}/slots?from=2026-11-02T00:00:00Z&to=2026-11-03T12:00:00Z")->json('slots');
        $this->assertNotContains(self::TUE_9AM, array_column($slots, 'startsAt'));
    }

    public function test_slot_cannot_be_double_booked_or_off_grid(): void
    {
        $this->signIn();
        $this->book()->assertCreated();

        $this->signIn(User::factory()->create());
        $this->book()->assertStatus(409)->assertJsonPath('error.code', 'slot_unavailable');
        $this->book('2026-11-02T22:30:00Z')->assertStatus(409)->assertJsonPath('error.code', 'slot_unavailable');
        $this->book('2026-11-02T01:00:00Z')->assertStatus(409); // inside min notice
    }

    public function test_idempotent_replay_and_retry_replaces_own_hold(): void
    {
        $this->signIn();
        $key = (string) Str::uuid();

        $first = $this->book(key: $key)->assertCreated()->json('booking.id');
        $this->book(key: $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('booking.id', $first);
        $this->assertCount(1, $this->stripe->intents);

        // App was killed mid-payment; the user books the same slot again with a new key.
        $second = $this->book()->assertCreated()->json('booking.id');
        $this->assertNotSame($first, $second);
        $this->assertSame('replaced', Booking::find($first)->cancel_reason);
        $this->assertContains(Booking::find($first)->payment->stripe_payment_intent_id, $this->stripe->cancelled);
    }

    public function test_stripe_failure_releases_the_slot(): void
    {
        $this->signIn();
        $this->stripe->failNext = true;

        $this->book()->assertStatus(502)->assertJsonPath('error.code', 'payment_unavailable');
        $this->book()->assertCreated();
    }

    public function test_only_a_signed_webhook_confirms(): void
    {
        $this->signIn();
        $id = $this->book()->json('booking.id');
        $intent = Booking::find($id)->payment->stripe_payment_intent_id;

        $this->webhook('payment_intent.succeeded', ['id' => $intent], signature: 't=1,v1=bad')
            ->assertStatus(400)->assertJsonPath('error.code', 'invalid_signature');
        $this->getJson("/v1/me/bookings/{$id}")->assertJsonPath('status', 'pending');

        $this->webhook('payment_intent.succeeded', ['id' => $intent, 'status' => 'succeeded'], 'evt_1')->assertNoContent();
        $this->webhook('payment_intent.succeeded', ['id' => $intent, 'status' => 'succeeded'], 'evt_1')->assertNoContent();

        $this->getJson("/v1/me/bookings/{$id}")->assertOk()
            ->assertJsonPath('status', 'confirmed')
            ->assertJsonPath('holdExpiresAt', null)
            ->assertJsonPath('payment.status', 'succeeded')
            ->assertJsonPath('joinOpensAt', '2026-11-02T21:50:00.000Z');
        $this->assertSame(1, DB::table('stripe_webhook_events')->count());
    }

    public function test_unpaid_hold_lapses_and_frees_the_slot(): void
    {
        $this->signIn();
        $id = $this->book()->json('booking.id');

        $this->travel(16)->minutes();
        $this->artisan('mindease:expire-booking-holds')->assertSuccessful();

        $this->getJson("/v1/me/bookings/{$id}")->assertJsonPath('status', 'cancelled')->assertJsonPath('cancelReason', 'expired');
        $this->assertContains(Booking::find($id)->payment->stripe_payment_intent_id, $this->stripe->cancelled);

        $this->signIn(User::factory()->create());
        $this->book()->assertCreated();
    }

    public function test_late_payment_reconfirms_if_free_else_refunds(): void
    {
        $this->signIn();
        $free = $this->book(self::WED_9AM)->json('booking.id');
        $taken = $this->book()->json('booking.id');

        $this->travel(16)->minutes();
        $this->artisan('mindease:expire-booking-holds');
        $this->signIn(User::factory()->create());
        $this->book()->assertCreated(); // someone else takes Tuesday 9am

        $this->pay($free);
        $this->pay($taken);

        $this->assertSame('confirmed', Booking::find($free)->status);
        $this->assertSame('cancelled', Booking::find($taken)->status);
        $this->assertSame(['paymentIntent' => Booking::find($taken)->payment->stripe_payment_intent_id, 'amount' => 15000],
            $this->stripe->refunds["booking-{$taken}-refund"]);
        $this->assertSame('refunded', Booking::find($taken)->payment->status);
    }

    public function test_cancel_refund_rules(): void
    {
        $this->signIn();
        $early = $this->confirmedBooking(self::WED_9AM);
        $late = $this->confirmedBooking(self::TUE_9AM);

        $this->getJson("/v1/me/bookings/{$early}")->assertJsonPath('refundIfCancelledCents', 15000);
        $this->postJson("/v1/me/bookings/{$early}/cancel")->assertOk()
            ->assertJsonPath('status', 'cancelled')->assertJsonPath('payment.refundedCents', 15000)->assertJsonPath('payment.status', 'refunded');

        $this->postJson("/v1/me/bookings/{$late}/cancel")->assertOk()->assertJsonPath('payment.refundedCents', 0);
        $this->assertCount(1, $this->stripe->refunds);

        // Retrying a cancel is harmless.
        $this->postJson("/v1/me/bookings/{$early}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertCount(1, $this->stripe->refunds);
    }

    public function test_cancel_pending_cancels_the_payment_intent(): void
    {
        $this->signIn();
        $id = $this->book()->json('booking.id');

        $this->postJson("/v1/me/bookings/{$id}/cancel")->assertOk()->assertJsonPath('cancelReason', 'user');
        $this->assertContains(Booking::find($id)->payment->stripe_payment_intent_id, $this->stripe->cancelled);
    }

    public function test_cannot_cancel_after_start(): void
    {
        $this->signIn();
        $id = $this->confirmedBooking();
        $this->travelTo(Carbon::parse(self::TUE_9AM)->addMinutes(5));

        $this->postJson("/v1/me/bookings/{$id}/cancel")->assertStatus(409)->assertJsonPath('error.code', 'booking_not_cancellable');
    }

    public function test_dashboard_refund_webhook_is_recorded(): void
    {
        $this->signIn();
        $id = $this->confirmedBooking();
        $intent = Booking::find($id)->payment->stripe_payment_intent_id;

        $this->webhook('charge.refunded', ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => $intent, 'amount_refunded' => 5000])->assertNoContent();

        $this->getJson("/v1/me/bookings/{$id}")->assertJsonPath('payment.refundedCents', 5000)->assertJsonPath('payment.status', 'partially_refunded');
    }

    public function test_other_users_bookings_are_hidden(): void
    {
        $this->signIn();
        $id = $this->book()->json('booking.id');

        $this->signIn(User::factory()->create());
        $this->getJson('/v1/me/bookings')->assertOk()->assertJsonCount(0);
        $this->getJson("/v1/me/bookings/{$id}")->assertNotFound();
        $this->postJson("/v1/me/bookings/{$id}/cancel")->assertNotFound();
        $this->postJson("/v1/me/bookings/{$id}/video-token")->assertNotFound();
        $this->getJson('/v1/me/bookings/not-a-uuid')->assertNotFound();
    }

    public function test_booking_list_scopes(): void
    {
        $this->signIn();
        $upcoming = $this->confirmedBooking(self::WED_9AM);
        $cancelled = $this->book()->json('booking.id');
        $this->postJson("/v1/me/bookings/{$cancelled}/cancel")->assertOk();

        $this->getJson('/v1/me/bookings?scope=upcoming')->assertJsonCount(1)->assertJsonPath('0.id', $upcoming);
        $this->getJson('/v1/me/bookings?scope=past')->assertJsonCount(1)->assertJsonPath('0.id', $cancelled);
        $this->getJson('/v1/me/bookings')->assertJsonCount(2);
    }

    public function test_video_token_only_for_confirmed_bookings_in_window(): void
    {
        $user = $this->signIn();
        $pending = $this->book(self::WED_9AM)->json('booking.id');
        $id = $this->confirmedBooking();

        $this->postJson("/v1/me/bookings/{$pending}/video-token")->assertStatus(409)->assertJsonPath('error.code', 'booking_not_confirmed');
        $this->postJson("/v1/me/bookings/{$id}/video-token")->assertStatus(409)->assertJsonPath('error.code', 'join_window_closed');

        $this->travelTo(Carbon::parse('2026-11-02T21:51:00Z')); // 9 min before start
        $res = $this->postJson("/v1/me/bookings/{$id}/video-token")->assertOk()
            ->assertJsonPath('sessionName', $id)->assertJsonPath('role', 'participant');

        JWT::$timestamp = Carbon::now()->getTimestamp();
        $claims = (array) JWT::decode($res->json('token'), new Key('zoom_secret_that_is_long_enough_for_hs256', 'HS256'));
        JWT::$timestamp = null;
        $this->assertSame('zoom_key', $claims['app_key']);
        $this->assertSame($id, $claims['tpc']);
        $this->assertSame(0, $claims['role_type']);
        $this->assertSame(str_replace('-', '', $user->id), $claims['user_identity']);
        $this->assertSame(Carbon::parse('2026-11-02T23:05:00Z')->getTimestamp(), $claims['exp']); // end + 15 min

        $this->travelTo(Carbon::parse('2026-11-02T22:51:00Z')); // after end
        $this->postJson("/v1/me/bookings/{$id}/video-token")->assertStatus(409);
    }

    public function test_video_token_needs_zoom_config(): void
    {
        $this->signIn();
        $id = $this->confirmedBooking();
        config(['services.zoom.sdk_secret' => null]);
        $this->travelTo(Carbon::parse(self::TUE_9AM));

        $this->postJson("/v1/me/bookings/{$id}/video-token")->assertStatus(503)->assertJsonPath('error.code', 'video_unavailable');
    }

    public function test_delete_me_removes_bookings_and_payments(): void
    {
        $user = $this->signIn();
        $this->confirmedBooking();

        $this->deleteJson('/v1/me')->assertNoContent();

        $this->assertSame(0, DB::table('bookings')->where('user_id', $user->id)->count());
        $this->assertSame(0, DB::table('payments')->where('user_id', $user->id)->count());
    }
}
