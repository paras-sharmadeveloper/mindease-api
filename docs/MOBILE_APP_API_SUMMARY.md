# MindEase — Mobile App API Summary (v1)

This is a single-page summary of every endpoint the **Flutter patient app** uses, plus a clear list of endpoints the app must **not** call because they belong to the therapist/admin web panel.

- Base URL: `https://api.<domain>/v1` (local: `http://localhost:8000/v1`)
- Auth: `Authorization: Bearer <accessToken>` on every endpoint except `/auth/*`.
- Timestamps: ISO 8601, UTC.
- Errors (all endpoints): `{ "error": { "code": "...", "message": "...", "field": "email" | null } }`. Validation errors also include `error.fields`.
- Full detail: `docs/client-handoff/API_CONTRACT.md` and `docs/API_NOTES_FOR_MOBILE.md`. Live reference: `/docs/api`.

> **The app never sends a user id.** The signed-in user always comes from the bearer token. Every `/me/...` endpoint returns data for that user only, so one user can never read or change another user's data.

---

## 1. Auth

| Method | Path | Body | Returns |
|---|---|---|---|
| POST | `/auth/register` | `{ name, email, password }` | `201 { user, accessToken, refreshToken }` · `409 email_taken` |
| POST | `/auth/login` | `{ email, password }` | `200 { user, accessToken, refreshToken }` · `401 invalid_credentials` |
| POST | `/auth/refresh` | `{ refreshToken }` | `200 { accessToken, refreshToken }` · `401 invalid_refresh_token` |
| POST | `/auth/password-reset` | `{ email }` | Always `202` |
| POST | `/auth/password-reset/confirm` | `{ email, token, password }` | `204` · `422 invalid_reset_token` |
| POST | `/auth/logout` | `{ refreshToken }` | `204` |

- Access token lasts 15 minutes. Refresh token lasts 60 days and is **rotated on every refresh**: always save the new one, and run only one refresh call at a time.
- The password reset email opens `mindease://reset-password?token=…&email=…`.

## 2. Account

| Method | Path | Returns |
|---|---|---|
| GET | `/me` | `user: { id, name, email, createdAt }` — use this for the splash session check |
| DELETE | `/me` | `204`. Deletes the account and all its data (App Store requirement) |

## 3. Content (cacheable)

Send `If-None-Match: <ETag>` to get `304 Not Modified`. The `X-Content-Version` header carries the content version.

| Method | Path | Returns |
|---|---|---|
| GET | `/config` | App config: `passThresholdPercent`, `pointsPerPhase`, `videoCompletePercent`, `phaseRequirements` |
| GET | `/questionnaires/onboarding` | Onboarding questionnaire |
| GET | `/programs` | `[{ id, title, short, status, displayOrder, coverImage }]` |
| GET | `/programs/{programId}` | Full program: phases, lessons, videos, activity, quiz |
| GET | `/support-resources?region=AU` | "Get help now" crisis lines |

## 4. Assessment (onboarding questionnaire)

| Method | Path | Body | Returns |
|---|---|---|---|
| GET | `/me/assessment` | — | `{ answers, step, completedAt, nextStepsSeen, questionnaireVersion }` |
| PUT | `/me/assessment` | Same shape (full replace) | `200` |

Save after every answer so the user can resume. These answers are sensitive: never log them.

---

## 5. Program progress — lesson, video, activity, quiz

**All four actions use ONE endpoint.** There are no separate "watch video", "complete lesson", "submit quiz" or "submit activity" endpoints. The app sends an **event** with a `type`.

```
POST /me/programs/{programId}/events
Headers:
  Authorization: Bearer <accessToken>
  Idempotency-Key: <new UUID per event>   ← required
  Content-Type: application/json
```

| App action | `type` | Body |
|---|---|---|
| Watch video | `video_progress` | `{ "type": "video_progress", "phaseId": "p1", "videoId": "p1v1", "position": 41.5, "watched": "1111100111…" }` |
| Mark lesson as read | `lesson_read` | `{ "type": "lesson_read", "phaseId": "p1", "lessonId": "p1l1" }` |
| Submit quiz | `quiz_submit` | `{ "type": "quiz_submit", "phaseId": "p1", "answers": [1, 0, 2, 1, 3] }` |
| Save / submit activity | `activity_save` | `{ "type": "activity_save", "phaseId": "p1", "complete": true, "answers": { "q1": { "text": "…", "chips": [] } } }` |

### 5.1 Watch video (`video_progress`)
- `watched` holds one character per second of the video: `"1"` = played, `"0"` = not played. Send the full local map; the server OR-merges it, so seconds are never lost.
- `position` = current playback position in seconds (used for resume).
- Send it periodically while playing (e.g. every 10–15 s), and on pause and exit.
- The video counts as complete when played seconds ÷ duration ≥ `videoCompletePercent` (currently 90%). Seeking to the end without playing does **not** complete it.

### 5.2 Mark lesson as read (`lesson_read`)
- Send it once when the user taps "I've read this lesson". Sending it again is harmless; the first read time is kept.

### 5.3 Submit quiz (`quiz_submit`)
- `answers` = one **0-based option index** per question, in question order. Every question must be answered.
- The server re-scores the quiz. A pass is `round(correct / total × 100) ≥ passThresholdPercent` (currently 80). Every attempt is stored, so retakes are allowed.
- Read the result from the response: `progress.phases.<phaseId>.quiz.attempts` (last item has `correct`, `total`, `score`, `passed`), plus `quiz.passed` and `quiz.bestScore`.

### 5.4 Save / submit activity (`activity_save`)
- `complete: false` = save draft. `complete: true` = submit.
- `answers` keys must be the prompt ids of that phase's activity.
- Submitting needs at least one non-empty answer. Once complete, it stays complete; later saves only update the answers.

### 5.5 Response (same for all four)

```jsonc
{
  "progress": { /* ProgramProgress — see 5.7 */ },
  "completion": null          // or, the moment a phase finishes:
  // { "phaseId": "p1", "pointsAwarded": 100, "nextPhaseId": "p2", "programComplete": false }
}
```

- `completion` is non-null **only** on the event that finishes a phase. Use it to show the "Phase complete / +100 points" screen.
- When `completion.programComplete` is `true`, the whole program has just been completed.
- The app never sends points or completion flags; the server decides both.

### 5.6 Progress errors

| Code | HTTP | Meaning |
|---|---|---|
| `phase_locked` | 403 | Phase isn't unlocked yet (finish the previous phase first) |
| `unknown_phase` / `unknown_lesson` / `unknown_video` | 422 | Id not in this program |
| `unknown_prompt` | 422 | Activity answer key isn't a prompt of this activity |
| `activity_empty` | 422 | `complete: true` with no answers |
| `quiz_incomplete` | 422 | Not every quiz question answered |
| `invalid_answer` | 422 | Option index out of range |
| `idempotency_key_reused` | 422 | Same key sent with a different body |
| `idempotency_in_progress` | 409 | Same key still processing — retry |

Retrying the **same** event: reuse the **same** `Idempotency-Key`. The server returns the original result (header `Idempotent-Replayed: true`) and never counts it twice.

### 5.7 Read progress / is the program complete?

| Method | Path | Returns |
|---|---|---|
| GET | `/me/programs/{programId}/progress` | `ProgramProgress` for the signed-in user |

**Program completed (true / false):** `programCompletedAt != null`.

```dart
final completed = progress['programCompletedAt'] != null;   // true / false
```

Other useful fields:

| Need | Field |
|---|---|
| Phase completed? | `phases.<phaseId>.completedAt != null` (or key present in `phaseCompleted`) |
| Phase unlocked? | `phases.<phaseId>.unlocked` |
| Item counts for progress bars | `phases.<phaseId>.counts` → `{ lessons: {done,total}, videos: {done,total}, activity: bool, quiz: bool }` |
| Lesson read? | key present in `phases.<phaseId>.lessons` |
| Video resume / % | `phases.<phaseId>.videos.<videoId>` → `position`, `percent`, `completedAt` |
| Activity draft / done | `phases.<phaseId>.activity` → `answers`, `complete` |
| Quiz passed / best score | `phases.<phaseId>.quiz.passed`, `bestScore`, `attempts[]` |
| Program points | `points` |

> ⚠ The exact `ProgramProgress` shape is still to be matched to the app's `UserProgress.toJson()` (`user_progress.dart`). Field names above are the current server output.

## 6. Points

| Method | Path | Returns |
|---|---|---|
| GET | `/me/points` | `{ total, awards: [{ programId, phaseId, points, awardedAt }] }` — read-only |

## 7. Therapist booking

| Method | Path | Body / query | Returns |
|---|---|---|---|
| GET | `/therapists` | — | `[{ id, name, bio, specialties, photoUrl, timezone, priceCents, currency, sessionMinutes }]` |
| GET | `/therapists/{therapistId}` | — | One therapist · `404 therapist_not_found` |
| GET | `/therapists/{therapistId}/slots` | `?from=&to=` (ISO, max 31 days; default next 14 days) | `{ therapistId, sessionMinutes, slots: [{ startsAt, endsAt }] }` |
| POST | `/me/bookings` | `{ therapistId, startsAt, notes? }` + **`Idempotency-Key`** | `201 { booking, payment: { clientSecret, publishableKey } }` |
| GET | `/me/bookings` | `?scope=upcoming` or `?scope=past` | `[booking]` |
| GET | `/me/bookings/{bookingId}` | — | `booking` |
| POST | `/me/bookings/{bookingId}/cancel` | — | `booking` (safe to retry) |
| POST | `/me/bookings/{bookingId}/video-token` | — | `{ token, sessionName, userIdentity, role, expiresAt }` for `flutter_zoom_videosdk` |

**Payment:** create the booking → show Stripe PaymentSheet with `clientSecret` → on success poll `GET /me/bookings/{id}` every 2 s (up to 30 s) until `status = confirmed`. Only the Stripe webhook confirms a booking; the app never does. Unpaid holds expire after 15 minutes.

**Cancel:** show `canCancel` and `refundIfCancelledCents` before the user confirms.

**Join call:** request a new video token each time, between `joinOpensAt` (10 min before start) and `endsAt`. Never store the token.

**Booking errors:** `slot_unavailable` 409, `payment_unavailable` 502, `booking_not_cancellable` 409, `booking_not_confirmed` 409, `join_window_closed` 409, `video_unavailable` 503, `range_too_long` 422.

---

## 8. ⛔ Do not use in the mobile app — Web panel only

The endpoints below are for the **therapist/admin web panel** (`mindease-web`). They are role-gated (`admin`, `therapist`); a patient token gets `403`. **Do not call any of these from the mobile app.**

| Method | Path | Used by |
|---|---|---|
| GET | `/panel/me` | Web panel — signed-in therapist/admin |
| PUT | `/panel/me/password` | Web panel — change panel password |
| GET | `/panel/dashboard` | Web panel — dashboard |
| GET | `/panel/therapists` | Web panel — admin only |
| POST | `/panel/therapists` | Web panel — admin only (create therapist) |
| GET | `/panel/therapists/{therapistId}` | Web panel |
| PATCH | `/panel/therapists/{therapistId}` | Web panel |
| GET | `/panel/therapists/{therapistId}/availability` | Web panel |
| PUT | `/panel/therapists/{therapistId}/availability` | Web panel |
| POST | `/panel/therapists/{therapistId}/exceptions` | Web panel — time off |
| DELETE | `/panel/therapists/{therapistId}/exceptions/{exceptionId}` | Web panel — time off |
| GET | `/panel/bookings` | Web panel |
| GET | `/panel/bookings/{bookingId}` | Web panel |
| POST | `/panel/bookings/{bookingId}/cancel` | Web panel — therapist cancel (full refund) |
| POST | `/panel/bookings/{bookingId}/outcome` | Web panel — mark completed / no-show |
| POST | `/panel/bookings/{bookingId}/video-token` | Web panel — therapist (host) video token |
| POST | `/webhooks/stripe` | **Stripe server only** — never called by any app |

The mobile app's equivalents are: `/therapists*` (not `/panel/therapists*`), `/me/bookings*` (not `/panel/bookings*`), and `/me/bookings/{id}/video-token` (not the panel one).

---

## Quick map: app screen → endpoint

| Screen / action | Endpoint |
|---|---|
| Splash session check | `GET /me` |
| Sign up / Sign in / Forgot password | `/auth/*` |
| Questionnaire (save + resume) | `GET` / `PUT /me/assessment` |
| Programs list | `GET /programs` |
| Program overview + phases | `GET /programs/{id}` + `GET /me/programs/{id}/progress` |
| Lesson → "I've read this lesson" | `POST …/events` `lesson_read` |
| Video player | `POST …/events` `video_progress` |
| Activity → Save draft / Complete | `POST …/events` `activity_save` |
| Quiz → Submit | `POST …/events` `quiz_submit` |
| Phase complete / program complete screen | `completion` in the events response |
| Is program completed? | `programCompletedAt != null` in progress |
| Profile points | `GET /me/points` |
| Get help now | `GET /support-resources?region=AU` |
| Book a session | `/therapists`, `/therapists/{id}/slots`, `POST /me/bookings` |
| My appointments | `GET /me/bookings?scope=upcoming\|past` |
| Join video session | `POST /me/bookings/{id}/video-token` |
| Delete account | `DELETE /me` |
