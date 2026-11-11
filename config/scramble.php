<?php

// Partial override of dedoc/scramble defaults. Docs UI: /docs/api  JSON: /docs/api.json
return [
    'api_path' => 'v1',

    'info' => [
        'version' => env('API_VERSION', '1.0.0'),
        'description' => <<<'MD'
            **Mobile app (Flutter) only.** Every endpoint the MindEase app calls. Contract: `docs/API_CONTRACT.md`.

            The therapist/admin **web panel has its own, separate API docs at [`/docs/panel`](/docs/panel)**.
            Never call `/v1/panel/*` from the app: those endpoints are role-gated and an app user's token gets `403`.

            - Auth: `Authorization: Bearer <accessToken>` on everything except **Auth**.
            - Errors: `{ "error": { "code", "message", "field" } }`. Validation errors add `error.fields`.
            - Timestamps are ISO 8601 UTC.
            MD,
    ],

    'ui' => [
        'title' => 'MindEase Mobile App API',
    ],

    // Docs need a sign-in at /docs/login in every environment (replaces Scramble's local-only bypass).
    'middleware' => [
        'web',
        \App\Http\Middleware\EnsureDocsAccess::class,
    ],
];
