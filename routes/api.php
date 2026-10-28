<?php

use App\Http\Controllers\Api\AssessmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\ContentController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\PointsController;
use App\Http\Controllers\Api\ProgressController;
use App\Http\Controllers\Api\TherapistController;
use App\Http\Controllers\Webhooks\StripeWebhookController;
use Illuminate\Support\Facades\Route;

/*
| MindEase API v1 - base URL https://api.<domain>/v1  (prefix set in bootstrap/app.php)
| Contract: docs/API_CONTRACT.md
*/

// ---- Auth (no bearer token) ----------------------------------------------------------
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:auth-general');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
    Route::post('refresh', [AuthController::class, 'refresh'])->middleware('throttle:auth-general');
    Route::post('password-reset', [AuthController::class, 'requestPasswordReset'])->middleware('throttle:auth-reset');
    Route::post('password-reset/confirm', [AuthController::class, 'confirmPasswordReset'])->middleware('throttle:auth-reset');
    Route::post('logout', [AuthController::class, 'logout'])->middleware('throttle:auth-general');
});

// ---- Webhooks (no bearer token; verified by signature) --------------------------------
Route::post('webhooks/stripe', StripeWebhookController::class);

// ---- Everything else needs Authorization: Bearer <access token> ------------------------
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::get('me', [MeController::class, 'show']);
    Route::delete('me', [MeController::class, 'destroy']);

    // Content (cacheable: ETag / If-None-Match, X-Content-Version)
    Route::get('config', [ContentController::class, 'config']);
    Route::get('questionnaires/onboarding', [ContentController::class, 'onboardingQuestionnaire']);
    Route::get('programs', [ContentController::class, 'programs']);
    Route::get('programs/{programId}', [ContentController::class, 'program']);
    Route::get('support-resources', [ContentController::class, 'supportResources']);

    // Assessment (sensitive)
    Route::get('me/assessment', [AssessmentController::class, 'show']);
    Route::put('me/assessment', [AssessmentController::class, 'update']);

    // Progress
    Route::get('me/programs/{programId}/progress', [ProgressController::class, 'show']);
    Route::post('me/programs/{programId}/events', [ProgressController::class, 'event'])->middleware('idempotent');

    // Points (read-only)
    Route::get('me/points', [PointsController::class, 'show']);

    // Therapist booking (Phase 2). Paid via Stripe; confirmed only by the Stripe webhook.
    Route::get('therapists', [TherapistController::class, 'index']);
    Route::get('therapists/{therapistId}', [TherapistController::class, 'show']);
    Route::get('therapists/{therapistId}/slots', [TherapistController::class, 'slots']);
    Route::post('me/bookings', [BookingController::class, 'store'])->middleware('idempotent');
    Route::get('me/bookings', [BookingController::class, 'index']);
    Route::get('me/bookings/{bookingId}', [BookingController::class, 'show']);
    Route::post('me/bookings/{bookingId}/cancel', [BookingController::class, 'cancel']);
    Route::post('me/bookings/{bookingId}/video-token', [BookingController::class, 'videoToken']);
});
