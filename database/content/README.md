# database/content

**SAMPLE placeholder content** so the API runs end-to-end before the real files arrive.

Replace every file here with the Flutter app's `assets/content/*.json` (same file names), then run:

```bash
php artisan mindease:import-content
```

Shapes are passed through verbatim, so the API returns exactly what the app already parses.
The importer refuses to drop ids that users already have progress on (use `--force` to override).
