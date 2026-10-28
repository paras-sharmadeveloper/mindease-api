# MindEase API v1 — notes for the mobile team

Implements `docs/client-handoff/API_CONTRACT.md`. This page lists only what the contract leaves open, plus the few additions.

Live docs (local): `http://localhost:8000/docs/api` · OpenAPI JSON: `/docs/api.json` (importable into Postman).

## Numbers in JSON

- Decimal fields such as `videos.*.position` may come back as whole numbers, for example `63` rather than `63.0`. In Dart, read them as `(json['position'] as num).toDouble()`, not `as double`, which throws on an `int`.

## Base URL and errors

- Base: `http://localhost:8000/v1` (prod: `https://api.<domain>/v1`).
- Every error: `{ "error": { "code", "message", "field" } }`. Validation errors also carry `error.fields` (all messages, per field).
- Common codes: `validation_failed` 422, `unauthenticated` 401, `invalid_credentials` 401, `email_taken` 409, `invalid_refresh_token` 401, `rate_limited` 429, `not_found` / `program_not_found` / `content_not_found` 404, `phase_locked` 403.

## Auth

- Access token TTL: **15 min**. Refresh token TTL: **60 days**, **rotated on every refresh**.
- Always store the **new** refresh token from `/auth/refresh`. Reusing an old one revokes the whole session (stolen-token protection), so **run refresh calls one at a time** (single-flight). Don't fire two refreshes in parallel.
  - After a reuse, every token from that sign-in (including the newest one) returns `401 invalid_refresh_token`. Send the user to sign in again.
- **Added:** `POST /auth/password-reset/confirm { email, token, password }` → `204`, or `422 invalid_reset_token`. The reset email links to `mindease://reset-password?token=…&email=…` (configurable). It signs out all devices.
- **Added:** `GET /me` → `user` (handy for a splash session check).

## Content

- Responses are the app's JSON files verbatim. Send `If-None-Match: <ETag>` to get `304`. `X-Content-Version` header carries `contentVersion`.

## Assessment

- `PUT /me/assessment` is a full replace. Send the whole document each time.

## Progress events

- `Idempotency-Key` header is **required** on `POST /me/programs/{id}/events` (UUID per event; reuse the same key when retrying the same event).
  - Replay → same body, header `Idempotent-Replayed: true`.
  - Same key with a different body → `422 idempotency_key_reused`.
  - Same key while the first is in flight → `409 idempotency_in_progress` (retry).
- `video_progress.watched`: send the full local map (`"1"` per played second). The server OR-merges it, so partial maps are fine too. The map is sized by the content's `duration`.
- `quiz_submit.answers`: one 0-based option index per question, in question order. All questions must be answered.
- `activity_save.answers` keys must be prompt ids of that phase's activity.
- Error codes here: `phase_locked` 403, `unknown_phase|lesson|video` 422, `unknown_prompt` 422, `activity_empty` 422, `quiz_incomplete` 422, `invalid_answer` 422.

### ⚠ ProgramProgress shape — needs confirming

The contract says it must equal `UserProgress.toJson()` in `user_progress.dart`, which wasn't in the handoff. Current shape (one class to change: `app/Services/Progress/ProgressPresenter.php`):

```jsonc
{
  "programId": "mhfa",
  "contentVersion": "0.1.0-draft",
  "phases": {
    "p1": {
      "unlocked": true,
      "completedAt": null,
      "pointsAwarded": null,
      "counts": { "lessons": {"done": 1, "total": 3}, "videos": {"done": 0, "total": 3}, "activity": false, "quiz": false },
      "lessons": { "p1l1": { "readAt": "2026-10-26T11:45:24.000Z" } },
      "videos":  { "p1v1": { "position": 41.5, "watched": "1111…", "watchedSeconds": 38, "duration": 72, "percent": 52, "completedAt": null } },
      "activity": { "answers": { "q1": { "text": "…", "chips": [] } }, "complete": true, "completedAt": "…", "updatedAt": "…" },
      "quiz": { "attempts": [ { "answers": [1,0,1,1,1], "correct": 4, "total": 5, "score": 80, "passed": true, "submittedAt": "…" } ],
                "passed": true, "passedAt": "…", "bestScore": 80 }
    }
  },
  "phaseCompleted": { "p1": "2026-10-26T11:50:00.000Z" },
  "points": 100,
  "programCompletedAt": null
}
```

Send `user_progress.dart` and we'll match it exactly.

## Therapist booking (Phase 2, new)

Sessions are paid with Stripe and held over Zoom Video SDK. Prices, refund rules and session length are **placeholders** until the client confirms them; the API shape will not change when they do.

| Method | Path | Notes |
|---|---|---|
| GET | `/therapists` | `[{ id, name, bio, specialties, photoUrl, timezone, priceCents, currency, sessionMinutes }]` |
| GET | `/therapists/{id}` | **Added.** One therapist, same shape as a list item. `404 therapist_not_found` |
| GET | `/therapists/{id}/slots?from=&to=` | ISO 8601. Default: now → +14 days, at most 31 days. `{ therapistId, sessionMinutes, slots: [{ startsAt, endsAt }] }`, UTC |
| POST | `/me/bookings` | **`Idempotency-Key` required.** Body `{ therapistId, startsAt, notes? }`. `201 { booking, payment: { clientSecret, publishableKey } }` |
| GET | `/me/bookings?scope=upcoming\|past` | List of `booking` |
| GET | `/me/bookings/{id}` | `booking` |
| POST | `/me/bookings/{id}/cancel` | Returns `booking`. Safe to retry |
| POST | `/me/bookings/{id}/video-token` | `{ token, sessionName, userIdentity, role, expiresAt }` for `flutter_zoom_videosdk` |

`booking`: `{ id, status, cancelReason, startsAt, endsAt, holdExpiresAt, therapist: { id, name, photoUrl }, priceCents, currency, notes, payment: { status, amountCents, refundedCents } | null, canCancel, refundIfCancelledCents, joinOpensAt, createdAt }`

`status`: `pending` (slot held, awaiting payment) → `confirmed` → `completed` | `no_show`; or `cancelled` (`cancelReason`: `user`, `expired`, `replaced`, `payment_failed`, `therapist`).

**Payment flow**
1. `POST /me/bookings` and present Stripe's PaymentSheet with `payment.clientSecret` and `payment.publishableKey`.
2. When the sheet closes successfully, show "Confirming…" and poll `GET /me/bookings/{id}` every 2 seconds, for up to 30 seconds, until `status` is `confirmed`. The server confirms the booking only when Stripe notifies it; the app can never confirm a booking itself.
3. An unpaid booking holds the slot until `holdExpiresAt` (15 minutes), then becomes `cancelled` / `expired`. If the user books the same slot again (for example, after the app was closed mid-payment), the earlier hold is replaced.
4. The price is always set by the server. Any amount sent by the app is ignored.

**Cancellation:** `canCancel` and `refundIfCancelledCents` tell the app what to show before the user confirms. Current placeholder rule: a full refund when cancelled at least 24 hours before the start, otherwise no refund.

**Joining the call:** request a token from `joinOpensAt` (10 minutes before the start) until `endsAt`. Request a new token each time the user joins; do not store it.

**Errors:** `therapist_not_found` 404, `slot_unavailable` 409 (`field: startsAt`; refresh the slots), `payment_unavailable` 502 (retry), `booking_not_cancellable` 409, `booking_not_confirmed` 409, `join_window_closed` 409, `video_unavailable` 503, `range_too_long` 422.
