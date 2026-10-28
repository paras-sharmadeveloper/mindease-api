<?php

namespace App\Services\Video;

use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\User;
use App\Support\Iso;
use Carbon\CarbonImmutable;
use Firebase\JWT\JWT;

/**
 * Short-lived Zoom Video SDK JWT (HS256, signed with the SDK secret). Session name = booking id.
 * Only for confirmed bookings, only from join_opens_minutes_before the start until the end.
 * The caller checks WHO may join (the booked user, or the booking's therapist as host).
 */
class ZoomVideoToken
{
    /** @return array{token: string, sessionName: string, userIdentity: string, role: string, expiresAt: string} */
    public function issue(Booking $booking, User $user, bool $host): array
    {
        $key = config('services.zoom.sdk_key');
        $secret = config('services.zoom.sdk_secret');

        if (! $key || ! $secret) {
            throw new ApiException('video_unavailable', 'Video sessions aren\'t available right now. Please try again later.', 503);
        }
        if ($booking->status !== Booking::CONFIRMED) {
            throw new ApiException('booking_not_confirmed', 'This session isn\'t confirmed yet.', 409);
        }

        $now = CarbonImmutable::now();
        $opensAt = CarbonImmutable::instance($booking->starts_at)->subMinutes(config('mindease.bookings.join_opens_minutes_before'));
        $endsAt = CarbonImmutable::instance($booking->ends_at);

        if ($now < $opensAt || $now > $endsAt) {
            throw new ApiException('join_window_closed', 'You can join from '.config('mindease.bookings.join_opens_minutes_before').' minutes before the session until it ends.', 409);
        }

        // Zoom: exp at most 48h after iat; user_identity at most 35 chars.
        $iat = $now->subSeconds(30); // clock skew
        $exp = $endsAt->addMinutes(15)->min($iat->addHours(48));
        $identity = str_replace('-', '', $user->id);

        $token = JWT::encode([
            'app_key' => $key,
            'role_type' => $host ? 1 : 0,
            'tpc' => $booking->id,
            'version' => 1,
            'user_identity' => $identity,
            'iat' => $iat->getTimestamp(),
            'exp' => $exp->getTimestamp(),
        ], $secret, 'HS256');

        return [
            'token' => $token,
            'sessionName' => $booking->id,
            'userIdentity' => $identity,
            'role' => $host ? 'host' : 'participant',
            'expiresAt' => Iso::utc($exp),
        ];
    }
}
