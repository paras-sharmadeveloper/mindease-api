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

- Access token TTL: **30 min** (was 15). Refresh token TTL: **60 days**, **rotated on every refresh**.
- Always store the **new** refresh token from `/auth/refresh`. Reusing an old one revokes the whole session (stolen-token protection), so **run refresh calls one at a time** (single-flight). Don't fire two refreshes in parallel.
  - After a reuse, every token from that sign-in (including the newest one) returns `401 invalid_refresh_token`. Send the user to sign in again.
- **Added:** `POST /auth/password-reset/confirm { email, token, password }` → `204`, or `422 invalid_reset_token`. The reset email links to a **web page**, `https://api.<domain>/reset-password?token=…&email=…`, where the user sets the new password; the app needs no reset screen. (A `mindease://` deep link is still possible via `MINDEASE_RESET_URL` if the app wants to handle it.) Confirming signs out all devices.
- **Added:** `POST /auth/password-reset/verify { email, token }` → `204` if the link is still valid, or `422 invalid_reset_token` (`field: token`). It does **not** use up the token. Call it when the reset deep link opens: on `422` show "This link has expired" with a button to request a new one, instead of letting the user type a new password first. Shares the reset rate limit (5 per hour per email).
- **Added:** `GET /me` → `user` (handy for a splash session check).
- **Rate limits (bot protection):** `POST /auth/register` allows 5 sign-ups per minute and 20 per hour from one IP address. `POST /auth/login` also allows at most 30 attempts per hour for one email, from any IP address, on top of the existing per-minute limits. Over a limit, the API returns `429 rate_limited` with a `Retry-After` header (seconds). Show "Too many attempts. Please wait a moment and try again." and don't retry automatically.

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

**Profile photo:** add a `photo` file to the `POST /me` form-data (JPG, PNG or WebP, up to 8 MB; on iOS pick as JPEG, HEIC isn't accepted). The server crops it to a square, resizes it to 512×512 and strips metadata such as location; the response has the new `photoUrl`. To remove the photo, send `removePhoto: true` (`photoUrl` becomes `null`). Each upload gets a new URL (`https://api.mindease.me/profile_pics/<random>.jpg`, a public static file), so the image can be cached forever; load it without an auth header. Older photos keep their `/avatars/…` URL. Errors: `422 invalid_photo` / `validation_failed` (`field: photo`; a photo over 8 MB says "This photo is too large. Choose one under 8 MB."), `413 payload_too_large` (`field: photo`, whole request too big, nothing saved), `429` (more than 20 uploads an hour), `503 photo_unavailable`.

**Email change:** sending a new `email` doesn't change it yet. The server emails a confirmation link (60 minutes) to the new address and returns `pendingEmail: "new@…"`; `email` stays the old one, and the user keeps signing in with it. Show "We've sent a link to {pendingEmail}". When the link is opened, `email` becomes the new address and `pendingEmail` goes back to `null` (refresh with `GET /me`). Sending the current email again cancels the change. Errors: `409 email_taken` (`field: email`), `429 rate_limited` (more than 5 changes an hour).

## Content

- Responses are the app's JSON files verbatim. Send `If-None-Match: <ETag>` to get `304`. `X-Content-Version` header carries `contentVersion`.
- **The server now has the real content** (the app's `assets/content/*.json`), not the "SAMPLE / Placeholder" files. It goes live when the server runs `php artisan mindease:import-content`.
- **Videos (sample for now):** every video now has a **sample** `mediaUrl` (public MP4 test films from archive.org, 10+ minutes each) and a placeholder `thumbnailUrl` (PNG, 640×360, in the phase colour), so the real player and thumbnails can be built and tested. `app_config.previewVideosWithoutMedia` is now `false`. The video `duration` in the content (65–90 s) is still what counts for completion, so the video completes at 90% of that, not of the sample film's length. When the client delivers the 12 real videos, only the URLs change; the shape stays the same. Handle a `null` `mediaUrl` anyway (show the preview player).

### User status in a program: lessons, videos, activity (`GET /programs/{programId}`)

**New:** every lesson now has `is_read` (`true` / `false`) right after `intro`, for the signed-in user. It becomes `true` after `POST /me/programs/{programId}/lessons/{lessonId}/read`. The ETag is now per user and changes when a lesson is read, so a cached copy is refreshed (no stale `304`). Everything else in the response is unchanged.

```json
{ "id": "p1l1", "title": "…", "minutes": 4, "intro": "…", "is_read": true, "sections": [ … ] }
```

**New:** every video (`phases[].videos[]`) now has `is_video_watched` (`true` / `false`) as its last field. It becomes `true` once the video is complete through `POST /me/programs/{programId}/videos/{videoId}/progress` (90% of its seconds played, same rule as `progress`). Partly watched videos stay `false`. The per-user ETag also changes when a video is completed.

```json
{ "id": "p1v1", "title": "…", "duration": 70, "mediaUrl": null, "thumbnailUrl": null, "is_video_watched": true }
```

**New:** the activity (`phases[].activity`) now has `is_draft` and `is_completed` right after `instructions`, and every prompt has `answer` as its last field. These are set by `POST /me/programs/{programId}/activities/{activityId}`.

| State | `is_draft` | `is_completed` |
|---|---|---|
| Never saved | `false` | `false` |
| Saved with `complete: false` (not submitted) | `true` | `false` |
| Submitted (`complete: true`); stays like this | `false` | `true` |

`answer` is the user's saved answer for that prompt, `{ "text": "…", "chips": ["…"] }` (draft or submitted; `text` is `""` and `chips` is `[]` when not given), or `null` if the user hasn't answered that prompt. When the user reopens the activity, fill the screen from these. When saving again, send **all** answers (each save replaces the saved ones).

```json
"activity": {
  "id": "p2a", "title": "…", "purpose": "…", "instructions": "…",
  "is_draft": false, "is_completed": true,
  "prompts": [
    { "id": "q1", "q": "Where did you feel it in your body?", "chips": ["Head", "Jaw", "…"], "optional": true, "responseType": "chips_and_text",
      "answer": { "text": "Before meetings", "chips": ["Head", "Jaw"] } },
    { "id": "q2", "q": "…", "optional": true, "responseType": "text", "answer": null }
  ]
}
```

The answers are private (each user only gets their own). Don't log this response or send it to analytics.

**New:** the quiz (`phases[].quiz`) now has `is_completed` and `is_passed` right after its `id`, and every question has `user_answer` as its last field. These are set by `POST /me/programs/{programId}/quizzes/{quizId}/submit`.

| State | `is_completed` | `is_passed` |
|---|---|---|
| Never submitted | `false` | `false` |
| Submitted, never passed | `true` | `false` |
| Passed at least once (counts for the phase; stays true even after a failed retake) | `true` | `true` |

`user_answer` is the 0-based option index the user picked **in their latest attempt**, or `null` before the first submit. `answer` is still the correct option, so the app can show "your answer" against "correct answer" with `explain`.

```json
"quiz": {
  "id": "p1q", "is_completed": true, "is_passed": true, "title": "Phase 1 quiz", "passThreshold": null,
  "questions": [
    { "id": "p1q-1", "type": "mc", "q": "…", "options": ["…", "…", "…", "…"], "answer": 1, "explain": "…", "user_answer": 1 }
  ]
}
```

### Question types in a program (`GET /programs/{programId}`)

**Activity prompts** (`phases[].activity.prompts[]`) are free-text reflection questions, all optional. `responseType` says what to show:

| `responseType` | Show |
|---|---|
| `text` | A multi-line text box |
| `chips_and_text` | The `chips` as tappable tags (pick any) **and** a text box |

Save them as `answers: { "<prompt id>": { "text": "…", "chips": ["Head", "Jaw"] } }`. There is **no fill-in-the-blanks type**. If the UI shows "fill in the blank" for a `text` prompt, it's the same thing: send what the user wrote as `text`.

**Quiz questions** (`phases[].quiz.questions[]`): `type` is `mc` (multiple choice, usually 4 `options`) or `tf` (true/false, `options: ["True","False"]`). `answer` is the 0-based index of the correct option, and `explain` is shown after Submit.

## Onboarding questionnaire (`/me/assessment`)

**Fixed:** answers from earlier steps were lost because each `PUT` replaced the whole document. Now:

- `PUT /me/assessment` **merges**. Send only the current step: `{ "answers": { "stress": "high" }, "step": 3 }`. Every earlier answer is kept. Sending `null` for a key removes that answer. Sending the whole document still works.
- Fields you leave out keep their saved value. When the user finishes, send `completedAt` (ISO time). For a retake, send `"completedAt": null`.
- Values: single/mood questions take a string (`"low"`), multi questions take a list (`["stress","support"]`).

**New: `recommendation`** in the `GET` and `PUT` responses. It's `null` until something is answered. It drives the Results, "Your focus" and "Your plan" screens:

```jsonc
"recommendation": {
  "programId": "mhfa",
  "focusPhases": [                         // in program order; at least one
    { "phaseId": "p2", "number": 2, "title": "Check Yourself, Kindly", "color": "#…",
      "reasons": ["You want help managing stress", "Your stress has been high lately"] }
  ],
  "showSupport": true,          // show the support card (crisis lines)
  "suggestProfessional": true,  // gently suggest a GP / professional
  "dailyMinutes": "10",         // answer to "time"
  "learningStyle": "watch",     // answer to "style"
  "preferredKind": "watch",     // learn | watch | activity | null: show this category first ("Suggested for you")
  "focusAreas": [ { "key": "stress", "label": "Stress" } ]   // used to suggest therapists
}
```

The rules are the app's `recommendation_engine.dart`, run on the server, so the app no longer has to compute them. They are not a diagnosis; keep the "not a diagnosis" copy.

## Home screen (new): `GET /me/home`

One request for "Continue where you left off", "Your focus" and points:

```jsonc
{
  "program": { "id": "mhfa", "title": "…", "contentVersion": "0.1.0-draft" },
  "continue": {                       // null when the program is finished
    "phaseId": "p1", "phaseNumber": 1, "phaseTitle": "How's Your Head, Really?",
    "kind": "watch",                  // learn (lesson) | watch (video) | activity | quiz
    "itemId": "p1v2", "title": "…", "minutes": 2,
    "resumePosition": 30,             // videos only: seconds to resume from
    "percent": 45                     // how much of this phase is done
  },
  "focus": [ { "phaseId": "p2", "number": 2, "title": "…", "color": "#…", "reasons": ["…"],
               "unlocked": false, "completed": false, "done": 0, "total": 8, "percent": 0 } ],   // [] before the questionnaire
  "phases": [ { "phaseId": "p1", "number": 1, "title": "…", "color": "#…", "unlocked": true, "completed": false, "done": 3, "total": 8, "percent": 38 } ],
  "points": 100,
  "programCompletedAt": null,
  "showSupport": false,
  "questionnaireCompleted": true
}
```

How `continue` is picked: in the first unlocked phase that isn't complete, a video the user stopped part-way comes first. Then the user's preferred kind (from "How do you like to learn?"), then lessons → videos → activity, and the quiz last. Tapping the card opens that item (`kind` + `itemId`). Call `GET /me/home` again after any progress call.

## Program actions (new): one endpoint per action

These are easier than the generic `/events` endpoint. They need **no `Idempotency-Key`** (repeating them is harmless) and apply the same rules: locked phase → `403 phase_locked`, and the server scores quizzes and awards points once. Each returns `{ progress, completion }`. `completion` is set when that call finished the phase; show the completion sheet then.

| Action | Method + path | Body |
|---|---|---|
| `markLessonAsRead(lessonId)` | `POST /me/programs/{programId}/lessons/{lessonId}/read` | none |
| `videoWatched(videoId)` | `POST /me/programs/{programId}/videos/{videoId}/progress` | `{ "position": 41.5, "watched": "1111100…" }` |
| `submitActivity(activityId)` | `POST /me/programs/{programId}/activities/{activityId}` | `{ "answers": { "q1": { "text": "…", "chips": ["Head"] } }, "complete": true }` |
| `submitQuiz(quizId)` | `POST /me/programs/{programId}/quizzes/{quizId}/submit` | `{ "answers": { "p1q-1": 1, "p1q-2": 0, "p1q-3": 2, "p1q-4": 0, "p1q-5": 1 } }` |

- `programId` is `mhfa`. Item ids come from `GET /programs/mhfa`, for example lesson `p1l1`, video `p1v1`, activity `p1a`, quiz `p1q`.
- **Video:** `position` is the seconds into the video (used to resume). `watched` has one character per second of the video, `"1"` if that second was played. Send it every ~10 seconds and when the player closes. The video completes at 90% played; seeking ahead doesn't count.
- **Activity:** `complete: false` saves a draft. `true` submits it and needs at least one answer (`422 activity_empty`).
- **Quiz (changed):** send `answers` as an object: **key = question id** (`phases[].quiz.questions[].id`), **value = the 0-based index of the chosen option** in that question's `options` (for `tf`: True = `0`, False = `1`). Every question must be answered; key order doesn't matter. The old list form (`[1, 0, 2, 0, 1]`, in question order) still works. New errors: `422 unknown_question` (a key isn't a question of this quiz); `quiz_incomplete` and `invalid_answer` now name the question in `field` (`answers.p1q-3`). The result is the last item of `progress.phases.<phaseId>.quiz.attempts` (`correct`, `total`, `score`, `passed`). The pass mark is 80%.
- Unknown item id → `404 unknown_lesson` / `unknown_video` / `unknown_activity` / `unknown_quiz`.

## Progress events (generic endpoint, still supported)

- `Idempotency-Key` header is **optional for now** on `POST /me/programs/{id}/events` (and `POST /me/bookings`). Without it the request runs normally, with no replay protection. When sent: a UUID per event; reuse the same key when retrying the same event.
  - Replay → same body, header `Idempotent-Replayed: true`.
  - Same key with a different body → `422 idempotency_key_reused`.
  - Same key while the first is in flight → `409 idempotency_in_progress` (retry).
- `video_progress.watched`: send the full local map (`"1"` per played second). The server OR-merges it, so partial maps are fine too. The map is sized by the content's `duration`.
- `quiz_submit.answers`: an object keyed by question id with the 0-based option index (preferred), or a list in question order. All questions must be answered.
- `activity_save.answers` keys must be prompt ids of that phase's activity.
- Error codes here: `phase_locked` 403, `unknown_phase|lesson|video` 422, `unknown_prompt` 422, `activity_empty` 422, `quiz_incomplete` 422, `unknown_question` 422, `invalid_answer` 422.

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
| GET | `/focus-areas` | **New.** `[{ key, label }]`: the choices for "What would you like to focus on?" (the screen before the calendar) |
| GET | `/therapists?focus=stress,sleep` | `[{ id, name, bio, specialties, photoUrl, timezone, priceCents, currency, sessionMinutes, matchedFocus }]`, **best match first** (see below) |
| GET | `/therapists/{id}` | One therapist, same shape (without `matchedFocus`). `404 therapist_not_found` |
| GET | `/therapists/{id}/slots?from=&to=` | ISO 8601. Default: now → +14 days, at most 31 days. `{ therapistId, sessionMinutes, slots: [{ startsAt, endsAt }] }`, UTC |
| POST | `/me/bookings` | `Idempotency-Key` optional for now (recommended: it stops a retry from booking twice). Body below. `201 { booking, payment: { clientSecret, publishableKey, status } }` |
| GET | `/me/bookings?scope=upcoming\|past` | List of `booking` |
| GET | `/me/bookings/{id}` | `booking` |
| POST | `/me/bookings/{id}/cancel` | Returns `booking`. Safe to retry |
| POST | `/me/bookings/{id}/video-token` | `{ token, sessionName, userIdentity, role, expiresAt }` for `flutter_zoom_videosdk` |

**Rate limit:** `POST /me/bookings` allows 5 per minute and 20 per hour per user (each call holds a slot). Over the limit, the API returns `429 rate_limited` with `Retry-After`. Normal use, including retries with the same `Idempotency-Key`, stays well under it.

**⚠ `specialties` changed shape:** it's now `[{ "key": "anxiety", "label": "Anxiety" }]` (it used to be a list of strings, and it was always empty). Admins pick specialties from the fixed focus-area list in the web panel, so each therapist needs them set there. Show `label`.

**Therapists matched to the user:** the list is ordered by how many of the user's focus areas each therapist covers, then by name. `matchedFocus` lists the keys that matched, for a "Matches your focus" tag.
- Pass the focus the user picked on the booking screen as `?focus=anxiety,sleep`.
- Without `focus`, the onboarding questionnaire answers decide (`recommendation.focusAreas`).

**Book a session, matching the booking screen:**

```jsonc
POST /me/bookings
{
  "therapistId": "…",                         // from GET /therapists
  "startsAt": "2026-11-12T22:00:00.000Z",     // "dateTimeSlot": the chosen slot's startsAt from GET /therapists/{id}/slots
  "focus": ["anxiety", "sleep"],              // optional, up to 5 keys from GET /focus-areas
  "mobile": "0412 345 678",                   // optional, Australian mobile; saved to the profile too if it has none
  "notes": "…",                               // optional
  "paymentMethodId": "pm_…"                   // optional "paymentToken", see Payment flow
}
```

The booking (and `GET /me/bookings`) now also returns `focus: [{ key, label }]` and `mobile`. The therapist sees both in the web panel. Errors: `422` with `field: focus.0`, `mobile` or `paymentMethodId`, and `402 payment_declined`.

`booking`: `{ id, status, cancelReason, startsAt, endsAt, holdExpiresAt, therapist: { id, name, photoUrl }, priceCents, currency, notes, focus, mobile, payment: { status, amountCents, refundedCents } | null, canCancel, refundIfCancelledCents, joinOpensAt, createdAt }`

`status`: `pending` (slot held, awaiting payment) → `confirmed` → `completed` | `no_show`; or `cancelled` (`cancelReason`: `user`, `expired`, `replaced`, `payment_failed`, `therapist`).

**Payment flow.** Use either option:
- **A. Stripe PaymentSheet (recommended).** Don't send `paymentMethodId`. Present the PaymentSheet with `payment.clientSecret` and `payment.publishableKey`.
- **B. The app's own card form ("paymentToken").** Create the card with Stripe's SDK (`Stripe.instance.createPaymentMethod(...)` → `pm_…`) and send it as `paymentMethodId`. The server charges it straight away. `payment.status` will be `succeeded` / `processing` (go to step 2), or `requires_action` (3-D Secure: call `Stripe.instance.handleNextAction(payment.clientSecret)`, then go to step 2). A declined card returns `402 payment_declined` with Stripe's message, and the slot is released. Never send raw card numbers to our API.

1. `POST /me/bookings`, then pay with A or B.
2. When the sheet closes successfully, show "Confirming…" and poll `GET /me/bookings/{id}` every 2 seconds, for up to 30 seconds, until `status` is `confirmed`. The server confirms the booking only when Stripe notifies it; the app can never confirm a booking itself.
3. An unpaid booking holds the slot until `holdExpiresAt` (15 minutes), then becomes `cancelled` / `expired`. If the user books the same slot again (for example, after the app was closed mid-payment), the earlier hold is replaced.
4. The price is always set by the server. Any amount sent by the app is ignored.

**Cancellation:** `canCancel` and `refundIfCancelledCents` tell the app what to show before the user confirms. Current placeholder rule: a full refund when cancelled at least 24 hours before the start, otherwise no refund.

**Joining the call:** request a token from `joinOpensAt` (10 minutes before the start) until `endsAt`. Request a new token each time the user joins; do not store it.

**Errors:** `therapist_not_found` 404, `slot_unavailable` 409 (`field: startsAt`; refresh the slots), `payment_unavailable` 502 (retry), `booking_not_cancellable` 409, `booking_not_confirmed` 409, `join_window_closed` 409, `video_unavailable` 503, `range_too_long` 422.
