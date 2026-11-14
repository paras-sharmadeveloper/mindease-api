<?php

use App\Http\Controllers\Api\AssessmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\ContentController;
use App\Http\Controllers\Api\HomeController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\PointsController;
use App\Http\Controllers\Api\ProgramActionController;
use App\Http\Controllers\Api\ProgressController;
use App\Http\Controllers\Api\TherapistController;
use App\Http\Controllers\Panel;
use App\Http\Controllers\Webhooks\StripeWebhookController;
use Illuminate\Support\Facades\Route;

/*
| MindEase API v1 - base URL https://api.<domain>/v1  (prefix set in bootstrap/app.php)
| Contract: docs/API_CONTRACT.md
*/

// ---- Auth (no bearer token) ----------------------------------------------------------
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->middleware(['throttle:auth-general', 'throttle:auth-register']);
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
    Route::post('refresh', [AuthController::class, 'refresh'])->middleware('throttle:auth-general');
    Route::post('password-reset', [AuthController::class, 'requestPasswordReset'])->middleware('throttle:auth-reset');
    Route::post('password-reset/verify', [AuthController::class, 'verifyPasswordReset'])->middleware('throttle:auth-reset');
    Route::post('password-reset/confirm', [AuthController::class, 'confirmPasswordReset'])->middleware('throttle:auth-reset');
    Route::post('logout', [AuthController::class, 'logout'])->middleware('throttle:auth-general');
});

// ---- Webhooks (no bearer token; verified by signature) --------------------------------
Route::post('webhooks/stripe', StripeWebhookController::class);

// ---- Everything else needs Authorization: Bearer <access token> ------------------------
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::get('me', [MeController::class, 'show']);
    Route::post('me', [MeController::class, 'update']); // the one profile update: JSON, or form-data with a photo
    Route::delete('me', [MeController::class, 'destroy']);
    Route::put('me/password', [MeController::class, 'password'])->middleware('throttle:auth-general');

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
    // One endpoint per action (same rules as /events, no Idempotency-Key needed)
    Route::post('me/programs/{programId}/lessons/{lessonId}/read', [ProgramActionController::class, 'lessonRead']);
    Route::post('me/programs/{programId}/videos/{videoId}/progress', [ProgramActionController::class, 'videoProgress']);
    Route::post('me/programs/{programId}/activities/{activityId}', [ProgramActionController::class, 'activity']);
    Route::post('me/programs/{programId}/quizzes/{quizId}/submit', [ProgramActionController::class, 'quiz']);

    // Home screen: continue where you left off, your focus, points
    Route::get('me/home', [HomeController::class, 'show']);

    // Points (read-only)
    Route::get('me/points', [PointsController::class, 'show']);

    // Therapist booking (Phase 2). Paid via Stripe; confirmed only by the Stripe webhook.
    Route::get('focus-areas', [TherapistController::class, 'focusAreas']);
    Route::get('therapists', [TherapistController::class, 'index']);
    Route::get('therapists/{therapistId}', [TherapistController::class, 'show']);
    Route::get('therapists/{therapistId}/slots', [TherapistController::class, 'slots']);
    Route::post('me/bookings', [BookingController::class, 'store'])->middleware(['throttle:bookings-create', 'idempotent']);
    Route::get('me/bookings', [BookingController::class, 'index']);
    Route::get('me/bookings/{bookingId}', [BookingController::class, 'show']);
    Route::post('me/bookings/{bookingId}/cancel', [BookingController::class, 'cancel']);
    Route::post('me/bookings/{bookingId}/video-token', [BookingController::class, 'videoToken']);
});

// ---- Web panel (therapists + admins). Same tokens as the app; role-gated. ----------------
Route::middleware(['auth:sanctum', 'throttle:api', 'role:admin,therapist'])->prefix('panel')->group(function () {
    Route::get('me', [Panel\AccountController::class, 'me']);
    Route::put('me/password', [Panel\AccountController::class, 'password']);
    Route::get('dashboard', Panel\DashboardController::class);

    Route::get('therapists/{therapistId}', [Panel\TherapistController::class, 'show']);
    Route::patch('therapists/{therapistId}', [Panel\TherapistController::class, 'update']);
    Route::get('therapists/{therapistId}/availability', [Panel\AvailabilityController::class, 'show']);
    Route::put('therapists/{therapistId}/availability', [Panel\AvailabilityController::class, 'update']);
    Route::post('therapists/{therapistId}/exceptions', [Panel\AvailabilityController::class, 'storeException']);
    Route::delete('therapists/{therapistId}/exceptions/{exceptionId}', [Panel\AvailabilityController::class, 'destroyException'])->whereNumber('exceptionId');

    Route::get('bookings', [Panel\BookingController::class, 'index']);
    Route::get('bookings/{bookingId}', [Panel\BookingController::class, 'show']);
    Route::post('bookings/{bookingId}/cancel', [Panel\BookingController::class, 'cancel']);
    Route::delete('bookings/{bookingId}', [Panel\BookingController::class, 'destroy']);
    Route::post('bookings/{bookingId}/outcome', [Panel\BookingController::class, 'outcome']);
    Route::post('bookings/{bookingId}/video-token', [Panel\BookingController::class, 'videoToken']);

    Route::middleware('role:admin')->group(function () {
        Route::get('therapists', [Panel\TherapistController::class, 'index']);
        Route::post('therapists', [Panel\TherapistController::class, 'store']);

        // People who signed up in the app.
        Route::get('users', [Panel\UserController::class, 'index']);

        // Content shown in the app (live on save).
        Route::get('programs', [Panel\ProgramController::class, 'index']);
        Route::post('programs', [Panel\ProgramController::class, 'store']);
        Route::get('programs/{programId}', [Panel\ProgramController::class, 'show']);
        Route::patch('programs/{programId}', [Panel\ProgramController::class, 'update']);
        Route::put('programs/{programId}/content', [Panel\ProgramController::class, 'replaceContent']);
        Route::get('questionnaire', [Panel\QuestionnaireController::class, 'show']);
        Route::put('questionnaire', [Panel\QuestionnaireController::class, 'update']);

        // Email delivery check.
        Route::get('email', [Panel\EmailController::class, 'show']);
        Route::post('email/test', [Panel\EmailController::class, 'test']);
    });
});
