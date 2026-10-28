# Editing MindEase content

All copy and rules live in `assets/content/`. Developers don't need to touch screens to change content. Run `flutter test` after any edit, because `test/content_assets_test.dart` checks structure.

> All lesson, activity and quiz text in this build is **DRAFT** written for testing. Replace it with clinically approved copy before release, then remove the Draft tags (`DraftTag(...)` in `lesson`, `activity` and `quiz` screens).

## `mhfa_program.json`

```jsonc
{
  "id": "mhfa", "title": "...", "short": "...", "intro": "...",
  "learn": ["bullet", "..."], "disclaimer": "...",
  "contentVersion": "0.1.0-draft",           // bump on every content release
  "phases": [{
    "id": "p1", "number": 1, "title": "...", "description": "...",
    "objectives": ["..."], "color": "#9BB068",
    "pointsAwarded": null,                    // null = config default (100)
    "lessons": [{ "id": "p1l1", "title": "...", "minutes": 4, "intro": "...",
      "sections": [{ "h": "Heading", "p": ["paragraph"], "list": ["bullet"] }],
      "example": "...", "takeaway": "..." }],
    "videos": [{ "id": "p1v1", "title": "...", "description": "...", "duration": 72,
      "mediaUrl": null, "thumbnailUrl": null }],   // set real https URLs only
    "activity": { "id": "p1a", "title": "...", "purpose": "...", "instructions": "...",
      "prompts": [{ "id": "q1", "q": "...", "chips": ["optional", "tags"], "optional": true }] },
    "quiz": { "id": "p1q", "passThreshold": null,  // null = config default (80)
      "questions": [{ "id": "p1q-1", "type": "mc" | "tf", "q": "...",
        "options": ["A", "B", "C", "D"], "answer": 1, "explain": "..." }] }
  }]
}
```

Rules:
- Keep every `id` stable once live, because progress is stored against ids. If you change wording, keep the id.
- `answer` is the **0-based index** into `options`. A true/false question has `["True","False"]`.
- `duration` is in seconds and must match the real video file.
- Don't add statistics or treatment claims without a checked source.

## `onboarding_questionnaire.json`

`type`: `single`, `multi` or `mood`. `exclusive` (multi only) is the option that clears the others. Option `v` values are referenced in `recommendation_engine.dart`, so tell the devs if you rename them.

## `app_config.json`

| Key | Meaning |
|---|---|
| `passThresholdPercent` | Quiz pass mark (80) |
| `pointsPerPhase` | Points per completed phase (100) |
| `videoCompletePercent` | % of a video that must be played (90) |
| `phaseRequirements` | `{lessons, videos, activity, quiz}` true/false |
| `previewVideosWithoutMedia` | `true` for QA. **Set `false` for release** |

## `support_resources_au.json`

Each entry has a name, description and number. Verify every number on the official website and set `verifiedOn` (DD/MM/YYYY) before each release.
