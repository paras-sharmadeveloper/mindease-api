# MindEase API contract (v1, draft)

REST + JSON over HTTPS. The backend language is the team's choice (Spring Boot or Laravel). Host in an Australian region, because health-adjacent personal data falls under the Privacy Act 1988 (APP 8 cross-border rules).

Base URL: `https://api.<domain>/v1`. Auth: `Authorization: Bearer <access token>` on everything except `/auth/*`.
Timestamps: ISO 8601 UTC. Errors: `{ "error": { "code": "string", "message": "plain English", "field": "email|null" } }`.

## Auth

| Method | Path | Body | Returns |
|---|---|---|---|
| POST | `/auth/register` | `{ name, email, password }` | `201 { user, accessToken, refreshToken }`. `409` with `field: email` if taken |
| POST | `/auth/login` | `{ email, password }` | `200 { user, accessToken, refreshToken }`. `401` generic message |
| POST | `/auth/refresh` | `{ refreshToken }` | `200 { accessToken, refreshToken }` |
| POST | `/auth/password-reset` | `{ email }` | Always `202`, so the response never reveals whether an email exists |
| POST | `/auth/logout` | `{ refreshToken }` | `204` |
| DELETE | `/me` | — | `204`. Deletes the account and all data (store requirement) |

Password rules: at least 8 characters, hashed with Argon2id or bcrypt. Rate-limit login and reset.

`user`: `{ id, name, email, createdAt }`

## Content (cacheable, versioned)

| Method | Path | Returns |
|---|---|---|
| GET | `/config` | Same shape as `assets/content/app_config.json` |
| GET | `/questionnaires/onboarding` | Same shape as `onboarding_questionnaire.json` |
| GET | `/programs` | `[{ id, title, short, status, displayOrder, coverImage }]` |
| GET | `/programs/{programId}` | Same shape as `mhfa_program.json` (phases, lessons, videos, activity, quiz) |
| GET | `/support-resources?region=AU` | Same shape as `support_resources_au.json` |

Send `ETag` / `contentVersion` so the app can cache offline. **Quiz answers ship to the client** for instant feedback, which is acceptable for an educational quiz. The server still re-scores each submission (below).

## Assessment (questionnaire answers)

| Method | Path | Body | Returns |
|---|---|---|---|
| GET | `/me/assessment` | — | `{ answers, step, completedAt, nextStepsSeen, questionnaireVersion }` |
| PUT | `/me/assessment` | same shape | `200`. Saved on every answer so the user can resume |

These answers are sensitive. Encrypt at rest, exclude from analytics, and return them only to their owner.

## Progress

| Method | Path | Returns |
|---|---|---|
| GET | `/me/programs/{programId}/progress` | `ProgramProgress` (shape = `UserProgress.toJson()` in `user_progress.dart`) |
| POST | `/me/programs/{programId}/events` | `{ progress: ProgramProgress, completion: PhaseCompletion \| null }` |

The client sends **events**, never points or completion flags.

```jsonc
// header: Idempotency-Key: <uuid>  (replays return the same result)
{ "type": "lesson_read",     "phaseId": "p1", "lessonId": "p1l1" }
{ "type": "video_progress",  "phaseId": "p1", "videoId": "p1v1",
  "position": 41.5, "watched": "111110011..." }              // 1 char per second
{ "type": "activity_save",   "phaseId": "p1", "complete": true,
  "answers": { "q1": { "text": "...", "chips": [] } } }
{ "type": "quiz_submit",     "phaseId": "p1", "answers": [1,0,1,1,1] }
```

### Server rules (mirror `ProgressionService`)

1. Reject with `403 phase_locked` any event whose phase isn't unlocked (Phase 1 always open; N+1 needs N completed).
2. **video_progress:** merge the `watched` map with OR (never clear bits). Set `completedAt` when watched seconds / duration ≥ `videoCompletePercent`. Optionally cap growth by wall-clock time since the last event to deter tampering.
3. **activity_save:** at least one non-empty answer is needed for `complete: true`. Once complete, it stays complete; later saves only update answers.
4. **quiz_submit:** score server-side from stored correct answers. Pass when `round(correct/total*100) ≥ threshold` (phase override, else config). Store every attempt. Record the first pass time.
5. After each event, if every required item is done and the phase isn't complete:
   - set `phaseCompleted[phaseId]`
   - insert the points award with a **unique (user, program, phase)** constraint so it happens exactly once
   - set `programCompletedAt` after the last phase
   - return `completion`.
6. `PhaseCompletion`: `{ phaseId, pointsAwarded, nextPhaseId | null, programComplete }`.

## Points

| Method | Path | Returns |
|---|---|---|
| GET | `/me/points` | `{ total, awards: [{ programId, phaseId, points, awardedAt }] }` |

Points are only created by rule 5, with no other write path.
