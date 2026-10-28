# MindEase API — guide for Claude Code

Backend for the MindEase Flutter app (client project, mental-health self-reflection program, Australia).
Owner: Paras (ThunderClap). Talk to Paras in Hinglish; client-facing text in formal English.

## Stack

- Laravel 13, PHP 8.3+ (8.4 on servers), Sanctum 4 (access tokens), Scramble (OpenAPI at `/docs/api`), PHPUnit.
- API-only. Routes: `routes/api.php`, prefix `v1` (set in `bootstrap/app.php`).
- Users use UUID primary keys. Sanctum tokens table uses `uuidMorphs`.

## Source of truth

1. `docs/client-handoff/API_CONTRACT.md` — endpoints and server rules. Don't change shapes without telling the mobile team.
2. `docs/client-handoff/HANDOFF.md` §4 — behaviour rules that must hold in production.
3. `docs/client-handoff/QA_TEST_PLAN.md` — the QA cases; feature tests port sections C/D.
4. `docs/API_NOTES_FOR_MOBILE.md` — additions and open points we've told the mobile team about. Update it when behaviour changes.

## Layout

| Concern | File |
|---|---|
| Error format `{error:{code,message,field}}` + exception mapping | `bootstrap/app.php`, `app/Support/ApiError.php`, `app/Exceptions/ApiException.php` |
| Rate limiters | `app/Providers/AppServiceProvider.php` |
| Tokens (access + rotating refresh, family revoke) | `app/Services/TokenService.php` |
| Auth endpoints | `app/Http/Controllers/Api/AuthController.php` |
| Content import (app JSON → DB, verbatim) | `app/Services/ContentImporter.php`, `php artisan mindease:import-content` |
| Content read + ETag/304 | `app/Services/ContentRepository.php`, `app/Support/ContentResponse.php` |
| **Progression rules (pure PHP port of the Dart ProgressionService)** | `app/Domain/ProgressionService.php`, `app/Domain/ProgressState.php` |
| Progress events (transaction + user row lock, completion once) | `app/Services/Progress/ProgressEventHandler.php` |
| ProgramProgress JSON shape (**only place to change the shape**) | `app/Services/Progress/ProgressPresenter.php` |
| Idempotency-Key | `app/Http/Middleware/EnsureIdempotency.php` |
| Booking: slots (therapist tz, DST-safe), lifecycle, refunds | `app/Services/Booking/SlotService.php`, `BookingService.php`, `BookingPresenter.php` |
| Stripe (only via `PaymentGateway`; tests bind `tests/Fakes/FakePaymentGateway`) + webhook | `app/Services/Payments/`, `app/Http/Controllers/Webhooks/StripeWebhookController.php` |
| Zoom Video SDK JWT | `app/Services/Video/ZoomVideoToken.php` |
| Developer portal `/developers` (guides, reference, Try-it console; endpoint data lives in the page's `GROUPS` array — update it when an endpoint changes) | `resources/views/developers.blade.php`, `routes/web.php`, flag `MINDEASE_DEVELOPER_DOCS` |
| Config knobs | `config/mindease.php` (`bookings.*`), `config/services.php` (`stripe`, `zoom`) |

## Rules you must not break

- The client sends **events only**. Never accept points/completion flags from the client.
- Points are written **only** in `ProgressEventHandler` via `phase_completions` (unique user+program+phase). No other write path.
- Locked phase → `403 phase_locked` for every event type.
- Video: OR-merge the watched map, never clear bits; duration comes from content.
- Quiz is re-scored server-side: `round(correct/total*100) >= threshold`. Every attempt stored.
- Activity complete needs ≥1 non-empty answer; once complete it stays complete.
- **Sensitive data:** `assessments.answers`, `activity_responses.answers`, `idempotency_keys.response_body` are encrypted at rest. Never log them, never send them to analytics, only return them to their owner. Don't add request-body logging.
- Content ids are stable forever (progress is keyed by them). The importer refuses to drop ids that have progress unless `--force`.
- Every user table has `cascadeOnDelete` on `user_id`. New user tables must too (DELETE /me, Apple 5.1.1(v)).
- Host in an AU region.

## Commands

```bash
php artisan migrate:fresh --seed     # local reset (imports sample content, demo user)
php artisan test                     # all tests
php artisan test --filter=Progress
php artisan mindease:import-content [path] [--force]
php artisan mindease:create-admin you@example.com   # first web-panel admin on a server (password asked hidden)
```

## Status (v1 core)

Done: auth (register/login/refresh rotation/reset + confirm/logout/delete me), content endpoints with ETag, assessment, progress events + rules, points, idempotency, tests, OpenAPI.

Open items:
1. Get `user_progress.dart` from the mobile team → match `ProgressPresenter` output to `UserProgress.toJson()` exactly.
2. Replace `database/content/*.json` (SAMPLE) with the app's real `assets/content/*.json`.
3. Confirm reset-link target (app deep link vs web page) → `MINDEASE_RESET_URL`.
4. Email verification? (not in contract — ask client).
5. Staging deploy in ap-southeast-2 (SES Sydney for mail, queue worker, scheduler).

## Phase 2: therapist booking + web panel

**Built:** mobile booking API (therapists, slots, bookings, cancel + refunds, Stripe webhook, video token), `mindease:expire-booking-holds` (scheduled every minute), `users.role`, demo therapist seed, `tests/Feature/BookingTest.php`.
**Web panel (built):** React SPA in `../mindease-web` (not inside Laravel). API: `/v1/panel/*` in `routes/api.php`, `app/Http/Controllers/Panel/`, shapes in `app/Services/Booking/PanelPresenter.php`, tests `tests/Feature/PanelTest.php`. Same Sanctum + rotating refresh tokens as the app, gated by `role:admin,therapist` (`EnsureRole`). Admin: therapist CRUD (temporary password, price, active), all bookings, no client notes, no video join. Therapist: own profile/hours/time off/bookings, host video token, completed/no-show, cancel = full refund (`cancel_reason: therapist`). CORS origins: `CORS_ALLOWED_ORIGINS`.
**Not built yet:** therapist payouts, booking emails/reminders, admin managing other admins. A therapist who has bookings can't use `DELETE /me` (bookings restrict-on-delete): deactivate them instead. Booking confirms only from the webhook: never add a client-side confirm path.
Price/refund/session-length values in `config/mindease.php` are placeholders until the client confirms.

Client wants users to book therapist sessions from the app; therapists and admins see bookings in a **web panel** (no app install).

Decisions so far:
- Video: **in-app video is required by the client** → **Zoom Video SDK** (not Google Meet).
  - App: Flutter package `flutter_zoom_videosdk`. Therapist panel: Zoom Video SDK for Web (browser, no install).
  - Backend: `POST /me/bookings/{id}/video-token` returns a short-lived Video SDK JWT (signed with SDK key/secret, HS256, e.g. `firebase/php-jwt`). Session name = booking id. Only the booked user and its therapist can get a token, only in a window around `starts_at` (e.g. 10 min before → `ends_at`).
  - SDK key/secret live only in `.env`, never in the app. Turn cloud recording OFF unless the client asks (health data). Confirm Zoom data-region/consent wording with the client (AU Privacy Act).
- Panel: React SPA (`mindease-web`), role-based (`admin`, `therapist`). Changed from the earlier plan (Blade at `/panel` with a session guard) to bearer tokens, because the panel is a separate origin.
- Payments: **bookings are paid, via Stripe** (confirmed by client). Planned flow: `POST /me/bookings` holds the slot as `pending` and creates a PaymentIntent (amount set server-side, AUD); app pays with Stripe's Flutter SDK; the booking becomes `confirmed` only from the Stripe webhook (`payment_intent.succeeded`), never from the client. Unpaid holds expire and free the slot. Stripe secret + webhook secret only in `.env`. "Choose Your Plan" stays out of scope in v1.
- Still to confirm with client: price per session (fixed or per therapist, GST-inclusive?), refund rules on cancel/no-show, cancellation/reschedule rules, therapist payouts (Stripe Connect vs client pays therapists manually), therapist onboarding, timezones (store UTC, show therapist's local time).

Draft schema: `therapists` (user_id, bio, specialties, timezone, active), `therapist_availability` (weekday, start, end) + `availability_exceptions`, `bookings` (user_id, therapist_id, starts_at, ends_at, status: pending|confirmed|cancelled|completed|no_show, video_session_name, notes encrypted). Unique (therapist_id, starts_at) to stop double booking. `payments` (booking_id, user_id cascadeOnDelete, stripe_payment_intent_id unique, amount_cents, currency, status, refunded_cents) + `stripe_webhook_events` (event id unique, so each webhook is processed once).
Draft API: `GET /therapists`, `GET /therapists/{id}/slots?from=&to=`, `POST /me/bookings` (Idempotency-Key), `GET /me/bookings`, `POST /me/bookings/{id}/cancel`, `POST /me/bookings/{id}/video-token`, `POST /webhooks/stripe` (signature-verified, no auth). Panel: `/panel/bookings` (list, join call in browser, mark completed/no-show), `/panel/availability`.
