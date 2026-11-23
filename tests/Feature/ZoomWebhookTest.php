<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Therapist;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class ZoomWebhookTest extends ApiTestCase
{
    private const SECRET = 'zoom_webhook_secret';

    private Booking $booking;

    private User $client;

    private User $therapistUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-02T22:00:00Z'));
        config([
            'services.zoom.webhook_secret' => self::SECRET,
            'services.zoom.sdk_key' => 'zoom_key',
            'services.zoom.sdk_secret' => 'zoom_secret_that_is_long_enough_for_hs256',
        ]);

        $this->client = User::factory()->create();
        $this->therapistUser = User::factory()->create(['role' => 'therapist']);
        $therapist = Therapist::create(['user_id' => $this->therapistUser->id, 'specialties' => [], 'timezone' => 'Australia/Sydney']);

        $this->booking = Booking::create([
            'user_id' => $this->client->id, 'therapist_id' => $therapist->id,
            'starts_at' => '2026-11-02T22:00:00Z', 'ends_at' => '2026-11-02T22:50:00Z',
            'status' => Booking::CONFIRMED, 'price_cents' => 15000, 'currency' => 'aud', 'confirmed_at' => now(),
        ]);
    }

    private function zoom(array $body, ?string $signature = null, ?int $timestamp = null)
    {
        $raw = json_encode($body);
        $ts = (string) ($timestamp ?? time());
        $signature ??= 'v0='.hash_hmac('sha256', "v0:{$ts}:{$raw}", self::SECRET);

        return $this->call('POST', '/v1/webhooks/zoom-video', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_ZM_SIGNATURE' => $signature, 'HTTP_X_ZM_REQUEST_TIMESTAMP' => $ts,
        ], $raw);
    }

    private function zoomEvent(string $type, array $object): array
    {
        return ['event' => $type, 'event_ts' => now()->getTimestampMs(), 'payload' => ['account_id' => 'acc', 'object' => $object + [
            'id' => 'zs_1', 'session_id' => 'sess-uuid-1', 'session_name' => $this->booking->id,
        ]]];
    }

    private function joined(User $user, string $at): array
    {
        return $this->zoomEvent('session.user_joined', ['user' => ['id' => 'u', 'name' => 'x', 'join_time' => $at, 'user_key' => str_replace('-', '', $user->id)]]);
    }

    public function test_url_validation_returns_the_encrypted_token(): void
    {
        $this->zoom(['event' => 'endpoint.url_validation', 'payload' => ['plainToken' => 'qgg8vlvZRS6UYooatFL8Aw']])
            ->assertOk()->assertExactJson([
                'plainToken' => 'qgg8vlvZRS6UYooatFL8Aw',
                'encryptedToken' => hash_hmac('sha256', 'qgg8vlvZRS6UYooatFL8Aw', self::SECRET),
            ]);
    }

    public function test_bad_or_stale_signature_is_rejected(): void
    {
        $body = $this->zoomEvent('session.started', ['start_time' => '2026-11-02T22:01:00Z']);

        $this->zoom($body, 'v0=bad')->assertStatus(401)->assertJsonPath('error.code', 'invalid_signature');
        $this->zoom($body, timestamp: time() - 600)->assertStatus(401);
        $this->assertNull($this->booking->fresh()->video_started_at);
    }

    public function test_not_configured_returns_503(): void
    {
        config(['services.zoom.webhook_secret' => null]);
        $this->zoom($this->zoomEvent('session.started', []))->assertStatus(503);
    }

    public function test_full_call_with_both_sides_completes_the_booking_and_replays_are_harmless(): void
    {
        $events = [
            $this->zoomEvent('session.started', ['start_time' => '2026-11-02T22:01:00Z']),
            $this->joined($this->therapistUser, '2026-11-02T22:01:00Z'),
            $this->joined($this->client, '2026-11-02T22:03:00Z'),
            $this->zoomEvent('session.ended', ['start_time' => '2026-11-02T22:01:00Z', 'end_time' => '2026-11-02T22:46:00Z']),
        ];

        foreach ([...$events, ...$events] as $body) { // every event delivered twice
            $this->zoom($body)->assertNoContent();
        }

        $b = $this->booking->fresh();
        $this->assertSame('completed', $b->status);
        $this->assertSame('sess-uuid-1', $b->zoom_session_id);
        $this->assertSame('2026-11-02 22:01:00', $b->video_started_at->utc()->toDateTimeString());
        $this->assertSame('2026-11-02 22:03:00', $b->client_joined_at->utc()->toDateTimeString());
        $this->assertSame('2026-11-02 22:46:00', $b->video_ended_at->utc()->toDateTimeString());
        $this->assertSame(45 * 60, $b->video_duration_seconds);
    }

    public function test_client_never_joined_leaves_the_outcome_to_the_therapist(): void
    {
        $this->zoom($this->zoomEvent('session.started', ['start_time' => '2026-11-02T22:01:00Z']));
        $this->zoom($this->joined($this->therapistUser, '2026-11-02T22:01:00Z'));
        $this->zoom($this->zoomEvent('session.ended', ['start_time' => '2026-11-02T22:01:00Z', 'end_time' => '2026-11-02T22:20:00Z']));

        $b = $this->booking->fresh();
        $this->assertSame('confirmed', $b->status);
        $this->assertNull($b->client_joined_at);
        $this->assertSame(19 * 60, $b->video_duration_seconds);

        $this->signIn($this->therapistUser);
        $this->getJson("/v1/panel/bookings/{$b->id}")->assertJsonPath('video.clientJoined', false)
            ->assertJsonPath('video.therapistJoined', true)->assertJsonPath('video.durationSeconds', 1140);
    }

    public function test_session_restarted_keeps_first_start_and_last_end(): void
    {
        $this->zoom($this->zoomEvent('session.started', ['start_time' => '2026-11-02T22:01:00Z']));
        $this->zoom($this->zoomEvent('session.ended', ['start_time' => '2026-11-02T22:01:00Z', 'end_time' => '2026-11-02T22:10:00Z']));
        $this->zoom($this->zoomEvent('session.started', ['session_id' => 'sess-uuid-2', 'start_time' => '2026-11-02T22:12:00Z']));
        $this->zoom($this->zoomEvent('session.ended', ['session_id' => 'sess-uuid-2', 'start_time' => '2026-11-02T22:12:00Z', 'end_time' => '2026-11-02T22:40:00Z']));

        $b = $this->booking->fresh();
        $this->assertSame('2026-11-02 22:01:00', $b->video_started_at->utc()->toDateTimeString());
        $this->assertSame(39 * 60, $b->video_duration_seconds);
    }

    public function test_unknown_session_name_is_ignored(): void
    {
        $this->zoom(['event' => 'session.started', 'payload' => ['object' => ['session_name' => 'someone-elses-session']]])->assertNoContent();
        $this->zoom(['event' => 'session.started', 'payload' => ['object' => ['session_name' => '00000000-0000-0000-0000-000000000000']]])->assertNoContent();
    }

    public function test_token_issue_is_logged_without_the_token_and_rate_limited(): void
    {
        Log::spy();
        $this->signIn($this->client);

        $token = $this->postJson("/v1/me/bookings/{$this->booking->id}/video-token")->assertOk()->json('token');

        Log::shouldHaveReceived('info')->withArgs(fn ($msg, $ctx) => $msg === 'zoom.video_token_issued'
            && $ctx === ['booking_id' => $this->booking->id, 'user_id' => $this->client->id, 'role' => 'participant']
            && ! str_contains(json_encode($ctx), $token));

        foreach (range(2, 10) as $_) {
            $this->postJson("/v1/me/bookings/{$this->booking->id}/video-token")->assertOk();
        }
        $this->postJson("/v1/me/bookings/{$this->booking->id}/video-token")->assertStatus(429);
    }

    public function test_unauthenticated_token_request_is_401(): void
    {
        $this->postJson("/v1/me/bookings/{$this->booking->id}/video-token")->assertUnauthorized();
    }
}
