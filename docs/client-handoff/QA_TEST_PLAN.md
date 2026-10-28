# MindEase v0.1 — QA test plan

Run on one iOS and one Android device (or LambdaTest). Use `--dart-define=PREVIEW_SPEED=30` to make preview videos 30× faster. Record pass/fail per row in Azure DevOps.

## A. Auth + questionnaire

| # | Steps | Expected |
|---|---|---|
| A1 | Sign Up with empty fields | Inline errors for name, email, password |
| A2 | Password `short`, confirm mismatch | "Use at least 8 characters." and "Passwords don't match." |
| A3 | Sign up with valid details | "Account created" toast, questionnaire intro with first name |
| A4 | Start questionnaire | Progress bar, "1 of 9" |
| A5 | Back on Q1 | Returns to intro |
| A6 | Physical signs: tick two, then "None of these" | Only "None of these" stays ticked |
| A7 | Mood "Very low" / Stress "Overwhelming" / Support "No one right now" | Support card appears and links to Get Help. Progress is never blocked |
| A8 | Kill the app on Q5, reopen | Returns to Q5 with earlier answers kept |
| A9 | Skip every question | Results show Phase 1 "A great place for anyone to start" |
| A10 | Finish → Continue → Next Steps → "OK, let's get started" | Home with name, 0 points |
| A11 | Sign out, sign back in | Lands on Home, no questionnaire |
| A12 | Sign in with an unknown email | Form error, password cleared |
| A13 | Profile → Retake questionnaire | Previous answers pre-selected. Results update |

## B. Program navigation

| # | Steps | Expected |
|---|---|---|
| B1 | Programs tab | One MHFA card with ring, 4 phases, progress, Start Program |
| B2 | Overview | 4 phases. 2–4 locked with "Complete Phase N to unlock Phase N+1." |
| B3 | Tap a locked phase | Message only, no navigation |
| B4 | Deep link `/programs/mhfa/phase/p3` while locked | Redirects to overview |
| B5 | Open Phase 1 | Learn / Watch / Activity / Quiz with counts. "Up next" card |
| B6 | Learning style "Short videos" in questionnaire | Watch card shows "Suggested for you" |

## C. Content

| # | Steps | Expected |
|---|---|---|
| C1 | Open lesson, scroll | Reading bar fills. "Draft copy" tag shows |
| C2 | "I've read this lesson" | Completed state and timestamp. Learn count increments |
| C3 | Next / Previous / grid icon | Correct lesson. Grid returns to phase |
| C4 | Open video, wait for load | Spinner then play button. "Preview" note shows |
| C5 | Drag scrubber to the end without playing | Stays at 0% played and not watched |
| C6 | Play to 90% | "Video marked as watched" toast. Watch count increments |
| C7 | Leave mid-video, return | Position and played % restored |
| C8 | Activity: tap Complete with nothing filled | "Answer at least one question…" |
| C9 | Type, press back | Save / Discard / Keep editing sheet |
| C10 | Save draft, reopen | Text and chips restored, "Draft saved" pill |
| C11 | Complete, then edit and Save changes | Stays completed, changes kept |
| C12 | Quiz: Submit disabled until an option is picked | — |
| C13 | Submit a wrong answer | Correct option green, picked option red, explanation shown |
| C14 | Finish with 3/5 | 60%, "Not passed yet", Retry. Phase not complete |
| C15 | Leave mid-quiz | "Leave the quiz?" sheet. Attempt not saved |

## D. Progression + points

| # | Steps | Expected |
|---|---|---|
| D1 | Complete all Phase 1 items, pass quiz | "Phase 1 complete" sheet, +100 points, Phase 2 unlocked |
| D2 | Retake Phase 1 quiz and pass | No sheet, points stay 100, attempts = 2 |
| D3 | Kill and reopen app | Same points, phases and progress |
| D4 | Complete all 4 phases | "Program complete", 400 points, overview banner, disclaimer |
| D5 | Two accounts on one device | Progress never mixes |

## E. Accessibility + layout

| # | Check |
|---|---|
| E1 | TalkBack / VoiceOver read option state ("checked"), quiz result ("correct answer"), locked phases |
| E2 | Text size 200%: no clipped text on auth, questionnaire, phase, quiz |
| E3 | Small phone (iPhone SE) and large phone: no overflow stripes |
| E4 | Status is never shown by colour alone (icons + words on completed/locked) |

## F. Safety copy

| # | Check |
|---|---|
| F1 | Get Help lists 000, Lifeline, Beyond Blue, 13YARN, Kids Helpline. Numbers verified against official sites |
| F2 | Questionnaire intro and results say "not a diagnosis" |
| F3 | Program-complete sheet shows the "not an accredited course" disclaimer |
