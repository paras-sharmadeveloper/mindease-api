# MindEase — developer handoff

Prepared by Hyperion Tech, 26/10/2026. Version 0.1.

## 1. Scope of this handoff

| In scope (built) | Out of scope (not built) |
|---|---|
| Splash, Welcome, Sign Up, Sign In, Forgot Password | Choose Your Plan / payments |
| Onboarding questionnaire (9 questions) inside the auth flow | Therapist booking (tab shows empty state) |
| Results (focus phases) + Your Next Steps | Push notifications (bell shows a toast) |
| Home, Programs, Profile, Get Help, Bookings placeholder | Dark mode |
| MHFA Self-Reflection Program: overview, 4 phases, Learn, Watch, Activity, Quiz | Real backend, real auth, media hosting |
| Unlock rules, points once per phase, progress persistence | Admin / CMS / therapist portal |

Reference prototype (clickable, same flows): `docs/prototype/mindease-test-build.html` — open in a browser.
Reference screenshots of this Flutter build: `docs/screenshots/`.

## 2. User flow

```
Splash → Welcome → Sign Up ─┐
              └→ Sign In ───┤
                            ├─(questionnaire not done)→ Intro → Q1..Q9 → Results → Next Steps → Home
                            └─(done)→ Home
Home / Programs → Program overview → Phase N → Learn | Watch | Activity | Quiz
Phase complete → +points (once) → Phase N+1 unlocked → … → Program complete
```

Router guards live in `lib/core/router/app_router.dart` (`redirect`):

1. Signed out → only auth routes.
2. Signed in but questionnaire incomplete → `/onboarding` (Get Help is always reachable).
3. Any `/programs/:id/phase/:phaseId…` for a locked phase → program overview. Covers deep links and restored back stacks, not just hidden buttons.

## 3. Architecture

```
presentation (screens, widgets)
   ↓ ref.watch / ref.read
application (Riverpod Notifiers: AuthController, AssessmentController, ProgressController)
   ↓
domain (pure Dart: ProgressionService, RecommendationEngine, models)   ← unit tested
   ↓
data (repositories: Local* today, Api* next)
```

- **Content is data.** `ContentBundle` loads `assets/content/*.json` at startup. Swap `loadFromAssets` for an API loader without touching screens.
- **Rules in one place.** `ProgressionService` decides unlocks, counts, completion, quiz scoring and point awards. The backend must implement the same rules (see API contract) and be the source of truth.
- **Config, not constants.** Pass mark, points per phase, video completion % and phase requirements come from `app_config.json`. Per-phase overrides: `pointsAwarded`, `quiz.passThreshold`.
- **Per-user storage.** All keys are namespaced by user (`progress.<email>.<programId>`), so switching accounts never mixes data.

### Key files

| Concern | File |
|---|---|
| Unlocks, completion, points, quiz scoring | `lib/features/program/domain/progression_service.dart` |
| Progress model + JSON shape | `lib/features/program/domain/user_progress.dart` |
| Saving progress, firing completion events | `lib/features/program/application/progress_controller.dart` |
| Questionnaire → focus phases | `lib/features/onboarding/domain/recommendation_engine.dart` |
| Video "watched" rule (seconds actually played) | `VideoProgress.recordPlayback` in `user_progress.dart` |
| Routes + guards | `lib/core/router/app_router.dart` |
| Design tokens | `lib/core/theme/` |

## 4. Behaviour rules (must hold in production)

- **Phase 1** is open at start. Phase N+1 unlocks only when Phase N is complete.
- **Phase complete** requires: all 3 lessons read + all 3 videos watched + activity completed + quiz passed (configurable via `phaseRequirements`).
- **Lesson complete** happens only on "I've read this lesson". Opening it doesn't count.
- **Video complete** happens when ≥ `videoCompletePercent` (90%) of its seconds have actually been played. Seeking ahead never marks seconds. Tapping the card doesn't count.
- **Activity complete** needs at least one answered prompt. Every prompt is optional. Drafts save separately. Completed activities stay editable. Leaving with unsaved changes asks to save or discard.
- **Quiz** reveals the answer only after Submit. Scoring is `round(correct / total × 100) ≥ passThreshold` (80% = 4 of 5). Every attempt is stored. Retakes are unlimited and never remove a pass.
- **Points** are awarded once per phase, keyed by phase id. Retakes, revisits and restarts never add more.
- **Program complete** is set when Phase 4 completes.
- **Journal privacy:** activity responses are private user content. Never log them, never send them to analytics, and expose them only to their owner.

## 5. Questionnaire (auth module)

Defined in `assets/content/onboarding_questionnaire.json`. There are 9 questions: goals (multi), mood (5 faces), stress, sleep, physical signs (multi, "None of these" is exclusive), ease of talking, support network (multi, "No one right now" is exclusive), learning style, and daily time.

- Every question can be skipped. Answers save on each tap, so the user resumes on the same question after closing the app.
- **Inline support card** appears for mood = Very low, stress = Overwhelming, or support = No one right now. It links to Get Help and never blocks progress.
- **Results** map answers to focus phases with plain-language reasons (rules in `recommendation_engine.dart`, covered by tests). Learning style tags a category as "Suggested for you" in each phase.
- Copy states the questionnaire is **not a diagnosis**. Do not add scoring that implies clinical screening without clinical sign-off.
- Retake from Profile keeps the previous answers pre-selected.

## 6. Figma mapping

File: https://www.figma.com/design/5WDpQNzC7TiOf7e37oLt34/Gilgal-Mindease, page "Main – Light Mode" (freud UI kit, licence purchased).

| Screen | Figma node | Flutter |
|---|---|---|
| Splash | 20:7631 | `SplashScreen` |
| Sign In | 518:35492 | `SignInScreen` |
| Sign Up | 518:36309 | `SignUpScreen` |
| Forgot Password | 518:37754 (hidden in file) | `ForgotPasswordScreen` |
| Your Next Steps | 1699:41837 | `NextStepsScreen` |
| Home | 38:7297 | `HomeScreen` |
| Choose Your Plan | 2:14665 | **not built** |
| Therapist booking screens | section "Therapist Booking & Appointment" | **not built** (Bookings empty state only) |
| My Programs, Program detail, Courses, Course article, Program completed | unlabelled section 3 | `ProgramsScreen`, `ProgramOverviewScreen`, `PhaseDetailScreen`, `LessonScreen`, completion sheet |
| Profile Settings / OTP | section "Profile Settings & Help Center" | `ProfileScreen` (OTP not built) |

Questionnaire, Results, Phase detail, Watch, Activity and Quiz have **no Figma screens**. They follow the kit's components; ask design to add them to Figma.

Kit placeholders to keep out of the app: "Hi Shinomiya", "Dr. Hannibal Lector", "freud" layer names, $9.99/mo prices.

## 7. Next tasks for the dev team

1. **Backend + API** per `docs/API_CONTRACT.md`. Implement `ApiAuthRepository`, `ApiProgressRepository`, `ApiAssessmentRepository` and an API `ContentBundle` loader. Store tokens with `flutter_secure_storage`.
2. **Server-side enforcement** of unlocks and points, including idempotent event handling.
3. **Media.** Upload the 12 videos (60–90 s each), set `mediaUrl` and `thumbnailUrl`, and set `previewVideosWithoutMedia: false`.
4. **Content swap.** Replace the draft lesson, activity and quiz copy with the approved copy (`docs/CONTENT_GUIDE.md`), then remove the "Draft" tags in the lesson, activity and quiz screens.
5. **Crisis numbers.** Verify against the official sites, set `verifiedOn`, and add `url_launcher` for tap-to-call.
6. **Accessibility pass** with TalkBack and VoiceOver at 200% text scale.
7. **Store requirements:**
   - In-app account deletion (Apple 5.1.1(v)).
   - Privacy policy link.
   - Health-data consent copy. Data is sensitive under the Australian Privacy Act; host in an AU region.
8. **Analytics** if added: exclude activity responses and questionnaire answers entirely.

## 8. Verification done on this build

- `flutter analyze`: no issues (Flutter 3.47.7).
- `flutter test`: 29 passing. Coverage:
  - unlocks, completion, points awarded once, program completion, quiz pass/fail at 80%
  - seek-proof video completion
  - recommendation rules
  - content file integrity
  - widget flows: sign-up → questionnaire → results → next steps → home; locked phase tap; full Phase 1 quiz → completion sheet → retake with no extra points
- Web build rendered and screenshotted (`docs/screenshots/`).
- **Not done:** device builds on iOS/Android. No Android SDK or Xcode was available, so run `flutter run` on both before sprint review.
