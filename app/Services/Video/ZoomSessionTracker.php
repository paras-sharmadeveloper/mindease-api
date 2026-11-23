<?php

namespace App\Services\Video;

use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Applies Zoom Video SDK webhook events to the booking whose id is the session name.
 * Every handler is idempotent (Zoom retries): replaying an event writes the same values.
 * A session can end and start again (everyone left, then someone rejoined), so the earliest start and
 * the latest end are kept.
 */
class ZoomSessionTracker
{
    public function started(array $session): void
    {
        $this->withBooking($session, function (Booking $booking) use ($session) {
            $at = self::time($session['start_time'] ?? null);

            $booking->update([
                'zoom_session_id' => $booking->zoom_session_id ?? ($session['session_id'] ?? $session['id'] ?? null),
                'video_started_at' => self::earliest($booking->video_started_at, $at),
            ]);
        });
    }

    public function userJoined(array $session): void
    {
        $this->withBooking($session, function (Booking $booking) use ($session) {
            $user = $session['user'] ?? [];
            $key = $user['user_key'] ?? $user['user_identity'] ?? null;
            $at = self::time($user['join_time'] ?? null);

            // Our tokens set user_key to the user id without dashes (see ZoomVideoToken).
            $column = match ($key) {
                self::key($booking->user_id) => 'client_joined_at',
                self::key($booking->therapist->user_id) => 'therapist_joined_at',
                default => null,
            };

            if ($column) {
                $booking->update([$column => self::earliest($booking->{$column}, $at)]);
            }
        });
    }

    /** Records the end and duration; marks the booking completed when both the client and the therapist joined. */
    public function ended(array $session): void
    {
        $this->withBooking($session, function (Booking $booking) use ($session) {
            $end = self::time($session['end_time'] ?? null);
            $start = self::earliest($booking->video_started_at, self::time($session['start_time'] ?? null));
            $end = $booking->video_ended_at && $booking->video_ended_at->greaterThan($end) ? CarbonImmutable::instance($booking->video_ended_at) : $end;

            $changes = [
                'video_started_at' => $start,
                'video_ended_at' => $end,
                'video_duration_seconds' => max(0, $end->getTimestamp() - $start->getTimestamp()),
            ];

            // Only one who actually met: if the client never joined, the therapist decides (no_show) in the panel.
            if ($booking->status === Booking::CONFIRMED && $booking->client_joined_at && $booking->therapist_joined_at) {
                $changes['status'] = Booking::COMPLETED;
            }

            $booking->update($changes);
        });
    }

    private function withBooking(array $session, callable $apply): void
    {
        $id = strtolower((string) ($session['session_name'] ?? ''));

        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id)) {
            return; // not one of our sessions
        }

        DB::transaction(function () use ($id, $apply) {
            $booking = Booking::whereKey($id)->lockForUpdate()->first();

            if ($booking) {
                $apply($booking);
            }
        });
    }

    private static function key(string $userId): string
    {
        return str_replace('-', '', $userId);
    }

    private static function time(?string $value): CarbonImmutable
    {
        return $value ? CarbonImmutable::parse($value)->utc() : CarbonImmutable::now();
    }

    private static function earliest(?\DateTimeInterface $current, CarbonImmutable $candidate): CarbonImmutable
    {
        return $current && $current < $candidate ? CarbonImmutable::instance($current) : $candidate;
    }
}
