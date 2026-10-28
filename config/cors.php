<?php

// The web panel (mindease-web) is a separate origin. Bearer tokens only, no cookies.
return [
    'paths' => ['v1/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_filter(explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173'))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['ETag', 'X-Content-Version', 'Idempotent-Replayed', 'Retry-After'],
    'max_age' => 3600,
    'supports_credentials' => false,
];
