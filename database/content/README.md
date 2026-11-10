# database/content

The app's content, copied from the Flutter app's `assets/content/*.json` (same file names). Import with:

```bash
php artisan mindease:import-content
```

Shapes are passed through verbatim, so the API returns exactly what the app parses.
The importer refuses to drop ids that users already have progress on (use `--force` to override).
⚠ On a server where admins edit content in the panel, importing overwrites their edits.

Still missing: video files. Every video has `mediaUrl: null` / `thumbnailUrl: null` until the client supplies them;
`previewVideosWithoutMedia: true` in `app_config.json` lets QA complete phases meanwhile. Set it to `false` for release.

Tests use their own sample copy in `tests/fixtures/content`, so editing these files never breaks the test suite.
