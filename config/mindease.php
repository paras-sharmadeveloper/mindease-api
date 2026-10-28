<?php

return [

    'tokens' => [
        // Short-lived bearer token (Sanctum). The app refreshes it with /auth/refresh.
        'access_ttl_minutes' => (int) env('MINDEASE_ACCESS_TTL_MINUTES', 15),
        // Rotated on every refresh; reuse of an old one revokes the whole session family.
        'refresh_ttl_days' => (int) env('MINDEASE_REFRESH_TTL_DAYS', 60),
    ],

    'password_reset' => [
        // Link in the reset email. {token} and {email} are replaced (URL-encoded).
        // App deep link, e.g. mindease://reset-password?token={token}&email={email}
        // or a web page, e.g. https://app.example.com/reset-password?token={token}&email={email}
        'url' => env('MINDEASE_RESET_URL', 'mindease://reset-password?token={token}&email={email}'),
    ],

    'progress' => [
        // Anti-tamper for video_progress: newly watched seconds may not exceed
        // (wall-clock seconds since last event) x factor + grace.
        // Keep OFF while QA runs with PREVIEW_SPEED=30 (preview videos play 30x faster).
        'video_cap_enabled' => (bool) env('MINDEASE_VIDEO_CAP_ENABLED', false),
        'video_cap_factor' => (float) env('MINDEASE_VIDEO_CAP_FACTOR', 1.5),
        'video_cap_grace_seconds' => (int) env('MINDEASE_VIDEO_CAP_GRACE', 15),
    ],

    'bookings' => [
        // Placeholders until the client confirms pricing (fixed vs per therapist, GST-inclusive?).
        // A therapist's own price_cents overrides the default. Amounts are in cents, GST-inclusive.
        'default_price_cents' => (int) env('MINDEASE_BOOKING_PRICE_CENTS', 15000),
        'currency' => env('MINDEASE_BOOKING_CURRENCY', 'aud'),
        'session_minutes' => (int) env('MINDEASE_SESSION_MINUTES', 50),
        'buffer_minutes' => (int) env('MINDEASE_SESSION_BUFFER_MINUTES', 10),
        'min_notice_hours' => (int) env('MINDEASE_BOOKING_MIN_NOTICE_HOURS', 12),
        'max_days_ahead' => (int) env('MINDEASE_BOOKING_MAX_DAYS_AHEAD', 60),
        // An unpaid pending booking holds the slot this long, then lapses.
        'hold_minutes' => (int) env('MINDEASE_BOOKING_HOLD_MINUTES', 15),
        // Placeholder refund rule until the client confirms: cancel >= cutoff before start = full refund,
        // later = late_cancel_refund_percent.
        'refund_cutoff_hours' => (int) env('MINDEASE_REFUND_CUTOFF_HOURS', 24),
        'late_cancel_refund_percent' => (int) env('MINDEASE_LATE_CANCEL_REFUND_PERCENT', 0),
        // Video token window around the session.
        'join_opens_minutes_before' => (int) env('MINDEASE_JOIN_OPENS_MINUTES_BEFORE', 10),
    ],

    // First web-panel admin on a server (database/seeders/AdminSeeder.php). Password only in the server .env.
    'admin' => [
        'email' => env('MINDEASE_ADMIN_EMAIL', 'admin@mindease.me'),
        'password' => env('MINDEASE_ADMIN_PASSWORD'),
    ],

    // Developer portal at /developers (guides, reference, "Try it" console). Off in production unless enabled.
    'developer_docs' => (bool) env('MINDEASE_DEVELOPER_DOCS', env('APP_ENV') !== 'production'),

    // Fallbacks used only when app_config has not been imported yet.
    'defaults' => [
        'passThresholdPercent' => 80,
        'pointsPerPhase' => 100,
        'videoCompletePercent' => 90,
        'phaseRequirements' => ['lessons' => true, 'videos' => true, 'activity' => true, 'quiz' => true],
    ],
];
