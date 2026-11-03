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
- **Added:** `POST /auth/password-reset/confirm { email, token, password }` → `204`, or `422 invalid_reset_token`. The reset email links to a **web page**, `https://api.<domain>/reset-password?token=…&email=…`, where the user sets the new password; the app needs no reset screen. (A `mindease://` deep link is still possible via `MINDEASE_RESET_URL` if the app wants to handle it.) Confirming signs out all devices.
- **Added:** `POST /auth/password-reset/verify { email, token }` → `204` if the link is still valid, or `422 invalid_reset_token` (`field: token`). It does **not** use up the token. Call it when the reset deep link opens: on `422` show "This link has expired" with a button to request a new one, instead of letting the user type a new password first. Shares the reset rate limit (5 per hour per email).
- **Added:** `GET /me` → `user` (handy for a splash session check).

## Account (new)

| Method | Path | Body | Returns |
|---|---|---|---|
| POST | `/me` | any of `{ firstName, lastName, preferredName, email, mobile, remindersEnabled, photo, removePhoto }` | `200 user`. **The only profile update endpoint.** Send only what changed. See "Profile screen" below |
| PUT | `/me/password` | `{ currentPassword, password }` | `204`. Wrong current password → `422 invalid_credentials` (`field: currentPassword`). New password under 8 characters or the same as the current one → `422 validation_failed` (`field: password`) |

- Changing the password keeps **this** device signed in (its access and refresh tokens keep working) and signs out every other device. Old reset links stop working.

### Profile screen: one endpoint, `POST /me`

**"Save changes" = one `POST /me` request** with everything that changed, photo included. There is no separate photo endpoint, and no `PATCH /me`.

- Send JSON, or `multipart/form-data` when the user picked a new photo (file field `photo`). In form-data, send booleans as `true`/`false`.
- If anything is invalid (for example the photo), nothing is saved.
- The response is always the full `user`: `firstName`, `lastName`, `email`, `mobile`, `photoUrl` and the rest.
- One key per field: `mobile` (not `phone`), `firstName` + `lastName` (not `name`).

**⚠ Changed `user` shape** (in `/me`, `POST /me`, register and login):

```json
{ "id": "…", "email": "bittu01@yopmail.com", "firstName": "Bittu", "lastName": "Kumar", "preferredName": "Bit",
  "mobile": "+61412345678", "photoUrl": "https://api.mindease.me/avatars/….jpg", "remindersEnabled": true,
  "pendingEmail": null, "createdAt": "2026-10-26T11:45:24.000Z" }
```

`name` is **no longer returned**: it only repeated `firstName` + `lastName`. Show the full name as `firstName lastName`, and greet with `preferredName ?? firstName`. Register still takes `{ name, email, password }`; the name is split into `firstName` / `lastName`.

| Screen field | Field | Rules |
|---|---|---|
| First name | `firstName` | Required if sent, max 50 |
| Last name | `lastName` | Optional (`""` or `null` clears it), max 50 |
| What should we call you? | `preferredName` | Optional, max 50. Greet with `preferredName ?? firstName` |
| Email | `email` | See below |
| Mobile number | `mobile` | Australian mobile. Send it as typed (`0412 345 678`, `+61 412 345 678`); it is saved and returned as `+61412345678`. `null` clears it. Otherwise `422` (`field: mobile`) "Enter an Australian mobile number, like 0412 345 678." |
| Reminders | `remindersEnabled` | `true` / `false`. Default `true`. (Stored now; session reminders aren't sent yet.) |
| Member since | `createdAt` | Read-only |
| Profile photo | `photo` (send) / `photoUrl` (returned) | See "Profile photo" below. `photoUrl: null` = show initials |

**Profile photo:** add a `photo` file to the `POST /me` form-data (JPG, PNG or WebP, up to 8 MB; on iOS pick as JPEG, HEIC isn't accepted). The server crops it to a square, resizes it to 512×512 and strips metadata such as location; the response has the new `photoUrl`. To remove the photo, send `removePhoto: true` (`photoUrl` becomes `null`). Each upload gets a new URL, so the image can be cached forever; load it without an auth header. Errors: `422 invalid_photo` / `validation_failed` (`field: photo`), `429` (more than 20 uploads an hour), `503 photo_unavailable`.

**Email change:** sending a new `email` doesn't change it yet. The server emails a confirmation link (60 minutes) to the new address and returns `pendingEmail: "new@…"`; `email` stays the old one, and the user keeps signing in with it. Show "We've sent a link to {pendingEmail}". When the link is opened, `email` becomes the new address and `pendingEmail` goes back to `null` (refresh with `GET /me`). Sending the current email again cancels the change. Errors: `409 email_taken` (`field: email`), `429 rate_limited` (more than 5 changes an hour).

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
