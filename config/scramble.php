<?php

// Partial override of dedoc/scramble defaults. Docs UI: /docs/api  JSON: /docs/api.json
return [
    'api_path' => 'v1',

    'info' => [
        'version' => env('API_VERSION', '1.0.0'),
        'description' => 'MindEase mobile API v1. Contract: docs/API_CONTRACT.md. Errors: { "error": { "code", "message", "field" } }.',
    ],
];
