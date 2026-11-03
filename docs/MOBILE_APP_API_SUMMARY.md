# MindEase — Mobile App API Summary (v1)

A single-page summary of every endpoint the **Flutter patient app** uses, the rules the app must follow when calling them, and a clear list of endpoints the app must **not** call because they belong to the therapist/admin web panel.

Built from: `docs/client-handoff/API_CONTRACT.md`, `docs/client-handoff/HANDOFF.md` §4, `docs/client-handoff/QA_TEST_PLAN.md`, `docs/client-handoff/CONTENT_GUIDE.md`, `docs/API_NOTES_FOR_MOBILE.md` and `routes/api.php`. If anything here disagrees with those files, the contract wins — tell the backend team.

## 0. Basics

- **Base URL:** `https://api.<domain>/v1` (local: `http://localhost:8000/v1`).
- **Auth:** `Authorization: Bearer <accessToken>` on every endpoint except `/auth/*`.
- **Timestamps:** ISO 8601, UTC. Show them in the device time zone.
- **Errors (all endpoints):** `{ "error": { "code": "...", "message": "...", "field": "email" | null } }`. Validation errors also include `error.fields` (all messages, per field). Show `message` to the user; branch on `code`.
- **Common codes:** `validation_failed` 422, `unauthenticated` 401, `invalid_credentials` 401, `email_taken` 409, `invalid_refresh_token` 401, `rate_limited` 429, `not_found` / `program_not_found` / `content_not_found` 404, `phase_locked` 403.
- **Rate limits:** login 5/min per email; password reset 5/hour per email; other auth calls 30/min per IP; everything else 180/min per user. On `429`, back off and retry.
- **Numbers:** decimal fields such as `position` can arrive as whole numbers (`63`, not `63.0`). In Dart read them as `(json['x'] as num).toDouble()`, never `as double`.
- **Live reference:** `/docs/api` · OpenAPI JSON `/docs/api.json` (importable into Postman).

> **The app never sends a user id.** The signed-in user always comes from the bearer token. Every `/me/...` endpoint reads and writes that user's data only, so accounts can never mix (QA D5).

---

## 1. Auth — `ApiAuthRepository`

| Method | Path | Body | Returns |
|---|---|---|---|
| POST | `/auth/register` | `{ name, email, password }` | `201 { user, accessToken, refreshToken }` · `409 email_taken` (`field: email`) |
| POST | `/auth/login` | `{ email, password }` | `200 { user, accessToken, refreshToken }` · `401 invalid_credentials` |
| POST | `/auth/refresh` | `{ refreshToken }` | `200 { accessToken, refreshToken }` · `401 invalid_refresh_token` |
| POST | `/auth/password-reset` | `{ email }` | Always `202` (never reveals whether the email exists) |
| POST | `/auth/password-reset/confirm` | `{ email, token, password }` | `204` · `422 invalid_reset_token` |
| POST | `/auth/logout` | `{ refreshToken }` | `204` |

`user`: `{ id, name, email, createdAt }`

- Password: at least 8 characters (validate in the form too — QA A2).
- Store both tokens in `flutter_secure_storage`.
- Access token lasts **15 minutes**; refresh token **60 days**, **rotated on every refresh**. Always save the **new** refresh token.
- Run refresh **single-flight** (one at a time). Reusing an old refresh token revokes the whole session; after that every token returns `401 invalid_refresh_token` → send the user to Sign In.
- The reset email opens `mindease://reset-password?token=…&email=…`; the confirm call signs out all devices.

## 2. Account

| Method | Path | Returns |
|---|---|---|
| GET | `/me` | `user` — use it for the splash session check |
| DELETE | `/me` | `204`. Deletes the account and all its data (Apple 5.1.1(v)) |

## 3. Content — API `ContentBundle` loader

Responses are the app's `assets/content/*.json` files **verbatim**, so the existing models parse them unchanged. Send `If-None-Match: <ETag>` to get `304 Not Modified`; `X-Content-Version` carries `contentVersion`. Cache the bodies for offline use.

| Method | Path | Same shape as |
|---|---|---|
| GET | `/config` | `app_config.json` — `passThresholdPercent` (80), `pointsPerPhase` (100), `videoCompletePercent` (90), `phaseRequirements`, `previewVideosWithoutMedia` |
| GET | `/questionnaires/onboarding` | `onboarding_questionnaire.json` |
| GET | `/programs` | `[{ id, title, short, status, displayOrder, coverImage }]` |
| GET | `/programs/{programId}` | `mhfa_program.json` — phases, lessons, videos, activity, quiz |
| GET | `/support-resources?region=AU` | `support_resources_au.json` (region defaults to AU) |

- Content ids (`p1`, `p1l1`, `p1v1`, `q1`, …) are stable forever; progress is keyed by them.
- Quiz correct answers ship in the program content, so the app can show instant feedback. The server still re-scores every submission.
- `videos[].mediaUrl` may be `null` until media is uploaded; follow `previewVideosWithoutMedia` from `/config`.

## 4. Assessment (onboarding questionnaire) — `ApiAssessmentRepository`

| Method | Path | Body | Returns |
|---|---|---|---|
| GET | `/me/assessment` | — | `{ answers, step, completedAt, nextStepsSeen, questionnaireVersion }` (defaults if nothing saved) |
| PUT | `/me/assessment` | Same shape — **full replace** | `200` with the saved document |

- **Save on every tap** (send the whole document) so a user who closes the app resumes on the same question (QA A8). `step` = current question index.
- Set `completedAt` when the user finishes Q9 / sees Results; set `nextStepsSeen: true` after "Your Next Steps".
- Router guard: signed in **and** `completedAt == null` → onboarding. Otherwise → Home (QA A11).
- Retake from Profile: load the saved `answers` to pre-select them, then PUT the updated document (QA A13).
- Focus phases, the inline support card and "Suggested for you" are worked out **in the app** (`recommendation_engine.dart`); the server only stores answers.
- Answers are sensitive: never log them or send them to analytics.

---

## 5. Program progress — `ApiProgressRepository`

### 5.1 One endpoint for lesson, video, activity and quiz

There are **no separate** "watch video", "complete lesson", "submit quiz" or "submit activity" endpoints. The app sends an **event**:

```
POST /me/programs/{programId}/events
Authorization: Bearer <accessToken>
Idempotency-Key: <new UUID per event>    ← required
Content-Type: application/json
```

| App action | `type` | Body |
|---|---|---|
| Watch video | `video_progress` | `{ "type": "video_progress", "phaseId": "p1", "videoId": "p1v1", "position": 41.5, "watched": "1111100111…" }` |
| Mark lesson as read | `lesson_read` | `{ "type": "lesson_read", "phaseId": "p1", "lessonId": "p1l1" }` |
| Submit quiz | `quiz_submit` | `{ "type": "quiz_submit", "phaseId": "p1", "answers": [1, 0, 2, 1, 3] }` |
| Save draft / complete activity | `activity_save` | `{ "type": "activity_save", "phaseId": "p1", "complete": true, "answers": { "q1": { "text": "…", "chips": ["Walks"] } } }` |

The app sends **events only** — never points, completion flags or scores. The server decides all of them.

### 5.2 Lesson — `lesson_read`
- Send it **only** when the user taps "I've read this lesson". Opening or scrolling a lesson does not count (HANDOFF §4, QA C2).
- Sending it again is harmless; the first read time is kept (`phases.<id>.lessons.<lessonId>.readAt`).

### 5.3 Video — `video_progress`
- `watched` = one character per second of the video: `"1"` = actually played, `"0"` = not played. Build it from real playback (`VideoProgress.recordPlayback`). **Seeking or dragging the scrubber never marks seconds** (QA C5).
- `position` = current playback position in seconds, used to resume (QA C7).
- Send the full local map; the server OR-merges it (bits are never cleared) and sizes it by the content's `duration`.
- When to send: every 10–15 s while playing, and on pause, on completion and when leaving the screen.
- Complete when played seconds ÷ duration ≥ `videoCompletePercent` (90%). Show "Video marked as watched" when `phases.<id>.videos.<videoId>.completedAt` first becomes non-null (QA C6). Tapping the card never counts.

### 5.4 Activity — `activity_save`
- `complete: false` = save draft (QA C10). `complete: true` = complete (QA C11).
- `answers` keys must be the prompt ids of that phase's activity (`q1`, `q2`, …). `text` and `chips` are both optional per prompt.
- Completing needs at least one answered prompt, otherwise `422 activity_empty` → show "Answer at least one question before completing this activity." (QA C8).
- Once complete it stays complete; later saves (`complete: false` or `true`) only update the answers, so completed activities stay editable.
- The Save / Discard / Keep editing sheet is app-side; send an event only when the user chooses Save.
- Activity answers are private journal content: never log them or send them to analytics.

### 5.5 Quiz — `quiz_submit`
- `answers` = one **0-based option index** per question, in question order. Every question must be answered (`422 quiz_incomplete`).
- Send it **once, on final Submit**. If the user leaves mid-quiz, send nothing — the attempt is not saved (QA C15).
- The server re-scores: pass when `round(correct / total × 100) ≥ passThreshold` (phase `quiz.passThreshold`, else 80% = 4 of 5). Every attempt is stored; retakes are unlimited and never remove a pass or add points (QA D2).
- Read the result from the response: the last item of `progress.phases.<id>.quiz.attempts` (`correct`, `total`, `score`, `passed`), plus `quiz.passed`, `quiz.passedAt` and `quiz.bestScore`. Show "Not passed yet" + Retry when `passed` is false (QA C14).

### 5.6 Response (same for all four)

```jsonc
{
  "progress": { /* ProgramProgress — see 5.9 */ },
  "completion": null
  // or, only on the event that finishes a phase:
  // "completion": { "phaseId": "p1", "pointsAwarded": 100, "nextPhaseId": "p2", "programComplete": false }
}
```

- Replace the local progress with `progress` after every event.
- `completion != null` → show the "Phase N complete / +100 points" sheet; Phase `nextPhaseId` is now unlocked (QA D1). It is returned once per phase, ever.
- `completion.programComplete == true` → show "Program complete" with the "not an accredited course" disclaimer (QA D4, F3).

### 5.7 Idempotency and retries
- Generate a new UUID `Idempotency-Key` for every event.
- Retrying the **same** event (timeout, no network): reuse the **same** key. The server returns the original result (header `Idempotent-Replayed: true`) and never counts it twice.
- `422 idempotency_key_reused` = same key, different body (app bug). `409 idempotency_in_progress` = first request still running → retry shortly.

### 5.8 Progress errors

| Code | HTTP | Meaning / what to do |
|---|---|---|
| `phase_locked` | 403 | Phase not unlocked yet → go to program overview |
| `unknown_phase` / `unknown_lesson` / `unknown_video` | 422 | Id not in this program → refresh content |
| `unknown_prompt` | 422 | Activity answer key isn't a prompt of this activity |
| `activity_empty` | 422 | `complete: true` with no answer |
| `quiz_incomplete` | 422 | Not every question answered |
| `invalid_answer` | 422 | Option index out of range |
| `program_not_found` | 404 | Unknown `programId` |

### 5.9 Read progress, and "is the program complete?"

| Method | Path | Returns |
|---|---|---|
| GET | `/me/programs/{programId}/progress` | `ProgramProgress` for the signed-in user |

**Program completed (true / false):**

```dart
final bool programCompleted = progress['programCompletedAt'] != null;
```

| Need | Field |
|---|---|
| Phase unlocked? (also the deep-link guard, QA B4) | `phases.<phaseId>.unlocked` |
| Phase completed? | `phases.<phaseId>.completedAt != null` (same as key present in `phaseCompleted`) |
| Counts for Learn / Watch / Activity / Quiz cards | `phases.<phaseId>.counts` → `{ lessons: {done,total}, videos: {done,total}, activity: bool, quiz: bool }` |
| Lesson read? | key present in `phases.<phaseId>.lessons` |
| Video resume / played % | `phases.<phaseId>.videos.<videoId>` → `position`, `watched`, `percent`, `completedAt` |
| Activity draft / complete | `phases.<phaseId>.activity` → `answers`, `complete`, `completedAt`, `updatedAt` (null if never saved) |
| Quiz result | `phases.<phaseId>.quiz` → `attempts[]`, `passed`, `passedAt`, `bestScore` |
| Program points | `points` |

```jsonc
{
  "programId": "mhfa",
  "contentVersion": "0.1.0-draft",
  "phases": {
    "p1": {
      "unlocked": true, "completedAt": null, "pointsAwarded": null,
      "counts": { "lessons": {"done": 1, "total": 3}, "videos": {"done": 0, "total": 3}, "activity": false, "quiz": false },
      "lessons":  { "p1l1": { "readAt": "2026-10-26T11:45:24.000Z" } },
      "videos":   { "p1v1": { "position": 41.5, "watched": "1111…", "watchedSeconds": 38, "duration": 72, "percent": 52, "completedAt": null } },
      "activity": { "answers": { "q1": { "text": "…", "chips": [] } }, "complete": true, "completedAt": "…", "updatedAt": "…" },
      "quiz":     { "attempts": [ { "answers": [1,0,1,1,1], "correct": 4, "total": 5, "score": 80, "passed": true, "submittedAt": "…" } ],
                    "passed": true, "passedAt": "…", "bestScore": 80 }
    }
  },
  "phaseCompleted": { "p1": "2026-10-26T11:50:00.000Z" },
  "points": 100,
  "programCompletedAt": null
}
```

> ⚠ This shape must still be matched to the app's `UserProgress.toJson()` (`user_progress.dart`). Send that file to the backend team; only `ProgressPresenter.php` changes on the server.

### 5.10 Rules the server enforces (the app mirrors them for UI only)

- Phase 1 is open at start; Phase N+1 unlocks only when Phase N is complete.
- Phase complete = all lessons read + all videos watched + activity complete + quiz passed (switchable via `phaseRequirements`).
- Points are awarded once per phase (`pointsAwarded` per phase, else 100). Retakes, revisits and restarts never add more.
- Program complete is set when the last phase (Phase 4) completes → 400 points total.

## 6. Points

| Method | Path | Returns |
|---|---|---|
| GET | `/me/points` | `{ total, awards: [{ programId, phaseId, points, awardedAt }] }` — read-only |

Use it for the Home and Profile points display.

## 7. Get help

`GET /support-resources?region=AU` (section 3). The Get Help screen must stay reachable even when signed out or mid-questionnaire, so cache this response (and keep the bundled asset as a fallback).

## 8. Therapist booking

| Method | Path | Body / query | Returns |
|---|---|---|---|
| GET | `/therapists` | — | `[{ id, name, bio, specialties, photoUrl, timezone, priceCents, currency, sessionMinutes }]` |
| GET | `/therapists/{therapistId}` | — | One therapist · `404 therapist_not_found` |
| GET | `/therapists/{therapistId}/slots` | `?from=&to=` ISO 8601 (default now → +14 days, max 31 days) | `{ therapistId, sessionMinutes, slots: [{ startsAt, endsAt }] }` (UTC) |
| POST | `/me/bookings` | `{ therapistId, startsAt, notes? }` + **`Idempotency-Key`** | `201 { booking, payment: { clientSecret, publishableKey } }` |
| GET | `/me/bookings` | `?scope=upcoming` or `?scope=past` | `[booking]` |
| GET | `/me/bookings/{bookingId}` | — | `booking` |
| POST | `/me/bookings/{bookingId}/cancel` | — | `booking` (safe to retry) |
| POST | `/me/bookings/{bookingId}/video-token` | — | `{ token, sessionName, userIdentity, role, expiresAt }` for `flutter_zoom_videosdk` |

`booking`: `{ id, status, cancelReason, startsAt, endsAt, holdExpiresAt, therapist: { id, name, photoUrl }, priceCents, currency, notes, payment: { status, amountCents, refundedCents } | null, canCancel, refundIfCancelledCents, joinOpensAt, createdAt }`

`status`: `pending` (slot held, awaiting payment) → `confirmed` → `completed` | `no_show`; or `cancelled` (`cancelReason`: `user`, `expired`, `replaced`, `payment_failed`, `therapist`).

- **Pay:** create the booking → show Stripe PaymentSheet with `clientSecret` + `publishableKey` → on success show "Confirming…" and poll `GET /me/bookings/{id}` every 2 s for up to 30 s until `status = confirmed`. Only the Stripe webhook confirms a booking; the app never does. The price is always set by the server.
- **Hold:** an unpaid booking holds the slot until `holdExpiresAt` (15 min), then becomes `cancelled` / `expired`. Booking the same slot again replaces the earlier hold.
- **Cancel:** show `canCancel` and `refundIfCancelledCents` before the user confirms (placeholder rule: full refund ≥ 24 h before start, else none).
- **Join call:** request a fresh token every time the user joins, between `joinOpensAt` (10 min before start) and `endsAt`. Never store it.
- **Errors:** `therapist_not_found` 404, `slot_unavailable` 409 (`field: startsAt` → refresh slots), `payment_unavailable` 502 (retry), `booking_not_cancellable` 409, `booking_not_confirmed` 409, `join_window_closed` 409, `video_unavailable` 503, `range_too_long` 422.
- Prices, refund rules and session length are placeholders until the client confirms them; the API shape will not change.

---

## 9. ⛔ Do not use in the mobile app — Web panel only

These endpoints are for the **therapist/admin web panel** (`mindease-web`). They are role-gated (`admin`, `therapist`), so a patient token gets `403`. **Do not call any of them from the mobile app**, even where the name looks similar to a mobile endpoint.

| Method | Path | Used by |
|---|---|---|
| GET | `/panel/me` | Web panel — signed-in therapist/admin |
| PUT | `/panel/me/password` | Web panel — change panel password |
| GET | `/panel/dashboard` | Web panel — dashboard counts |
| GET | `/panel/therapists` | Web panel — admin only |
| POST | `/panel/therapists` | Web panel — admin only (create therapist) |
| GET | `/panel/therapists/{therapistId}` | Web panel |
| PATCH | `/panel/therapists/{therapistId}` | Web panel |
| GET | `/panel/therapists/{therapistId}/availability` | Web panel — weekly hours |
| PUT | `/panel/therapists/{therapistId}/availability` | Web panel — weekly hours |
| POST | `/panel/therapists/{therapistId}/exceptions` | Web panel — time off |
| DELETE | `/panel/therapists/{therapistId}/exceptions/{exceptionId}` | Web panel — time off |
| GET | `/panel/bookings` | Web panel |
| GET | `/panel/bookings/{bookingId}` | Web panel |
| POST | `/panel/bookings/{bookingId}/cancel` | Web panel — therapist cancel (full refund) |
| POST | `/panel/bookings/{bookingId}/outcome` | Web panel — mark completed / no-show |
| POST | `/panel/bookings/{bookingId}/video-token` | Web panel — therapist (host) video token |
| POST | `/webhooks/stripe` | **Stripe servers only** — never called by any app |

Mobile equivalents: `/therapists*` (not `/panel/therapists*`), `/me/bookings*` (not `/panel/bookings*`), `/me/bookings/{id}/video-token` (not the panel one), `/me` (not `/panel/me`).

---

## 10. Quick map: screen → endpoint

| Screen / action | Endpoint | Dart repository |
|---|---|---|
| Splash session check | `GET /me` (+ `POST /auth/refresh` if expired) | `ApiAuthRepository` |
| Welcome → Sign Up / Sign In | `POST /auth/register`, `POST /auth/login` | `ApiAuthRepository` |
| Forgot password / reset link | `POST /auth/password-reset`, `…/confirm` | `ApiAuthRepository` |
| App start content | `GET /config`, `/questionnaires/onboarding`, `/programs`, `/programs/{id}`, `/support-resources` | API `ContentBundle` loader |
| Questionnaire Q1–Q9 (save per tap, resume) | `GET` / `PUT /me/assessment` | `ApiAssessmentRepository` |
| Results → Your Next Steps | `PUT /me/assessment` (`completedAt`, `nextStepsSeen`) | `ApiAssessmentRepository` |
| Home / Programs / Overview / Phase | `GET /me/programs/{id}/progress` + content | `ApiProgressRepository` |
| Lesson → "I've read this lesson" | `POST …/events` `lesson_read` | `ApiProgressRepository` |
| Video player | `POST …/events` `video_progress` | `ApiProgressRepository` |
| Activity → Save draft / Complete | `POST …/events` `activity_save` | `ApiProgressRepository` |
| Quiz → Submit | `POST …/events` `quiz_submit` | `ApiProgressRepository` |
| Phase / program complete sheet | `completion` in the events response | `ApiProgressRepository` |
| Is program completed? | `programCompletedAt != null` | `ApiProgressRepository` |
| Points (Home, Profile) | `GET /me/points` | — |
| Get help now | `GET /support-resources?region=AU` | API `ContentBundle` loader |
| Book a session | `/therapists`, `/therapists/{id}/slots`, `POST /me/bookings` | — |
| My appointments / cancel | `GET /me/bookings?scope=…`, `POST /me/bookings/{id}/cancel` | — |
| Join video session | `POST /me/bookings/{id}/video-token` | — |
| Sign out | `POST /auth/logout` | `ApiAuthRepository` |
| Delete account | `DELETE /me` | `ApiAuthRepository` |

## 11. Open points

1. `ProgramProgress` shape → match to `UserProgress.toJson()` once `user_progress.dart` is shared.
2. Real `assets/content/*.json` to replace the sample content on the server.
3. Reset link target confirmed as the `mindease://reset-password` deep link?
4. Email verification — not in the contract; waiting on the client.
5. Booking price (fixed or per therapist, GST), refund and reschedule rules — placeholders until the client confirms.
