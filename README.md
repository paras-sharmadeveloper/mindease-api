# MindEase API

Laravel 13 backend for the MindEase Flutter app. Implements `docs/client-handoff/API_CONTRACT.md` (v1).

## Run locally

Requires PHP 8.3+ (8.4 recommended), Composer 2, and SQLite or MySQL.

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite          # or set DB_* for MySQL in .env
php artisan migrate --seed              # imports database/content/*.json + demo user (local only)
php artisan serve                       # http://localhost:8000/v1
php artisan queue:work                  # separate terminal: sends password-reset mail
```

- API docs: http://localhost:8000/docs/api
- Demo login: `demo@mindease.test` / `password123`
- Tests: `php artisan test`

### MySQL instead of SQLite

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=mindease
DB_USERNAME=root
DB_PASSWORD=
```

## Content

`database/content/` holds **sample** files. Replace them with the app's `assets/content/*.json`, then run:

```bash
php artisan mindease:import-content
```

## Production checklist

- Host in an **Australian region** (Privacy Act 1988 / APP 8), e.g. AWS ap-southeast-2, with an encrypted DB.
- `APP_ENV=production`, `APP_DEBUG=false`, HTTPS only.
- `HASH_DRIVER=argon2id` set **before** the first user is created.
- Back up `APP_KEY`. Questionnaire and journal answers are encrypted with it; losing it makes them unreadable. Rotate with `APP_PREVIOUS_KEYS`.
- Mail via SES (Sydney) for password reset. Run a queue worker and the scheduler (`schedule:run` every minute).
- Never add request-body logging, Telescope or analytics that capture `answers`.
