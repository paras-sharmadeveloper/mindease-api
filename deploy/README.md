# Deploy MindEase API to a VPS

Target: fresh **Ubuntu 24.04** VPS in an **Australian region** (Sydney/Melbourne). 2 GB RAM is enough to start.

## 1. Before you start
- DNS: `A` record `api.<domain>` → VPS IP
- Repo: `paras-sharmadeveloper/mindease-api` (Laravel app at repo root)

## 2. One command (as root)
```bash
ssh root@<VPS-IP>
curl -fsSLo server-setup.sh https://raw.githubusercontent.com/paras-sharmadeveloper/mindease-api/main/deploy/server-setup.sh
# (private repo? copy the file with scp instead)

DOMAIN=api.mindease.me \
REPO_URL=git@github.com:paras-sharmadeveloper/mindease-api.git \
ADMIN_EMAIL=parassharmadeveloper@gmail.com \
STAGING=1 \
bash server-setup.sh
```
First run prints a **deploy key** if the repo is private → add it in GitHub → Repo → Settings → Deploy keys (read-only) → run the same command again.

## 3. After setup
- Fill `MAIL_*`, `STRIPE_*`, `ZOOM_VIDEO_SDK_*` in `/var/www/mindease/.env` (SES Sydney), then `sudo -u deploy bash /var/www/mindease/deploy/deploy.sh --no-pull`
- **Back up `APP_KEY`** from `.env`. Without it, encrypted answers can't be read.
- Check: `https://api.mindease.me/up` → 200, `https://api.mindease.me/docs/api` and `/developers` (staging only)

## Webhooks to configure

**Stripe** (booking payments): Dashboard → Developers → Webhooks → endpoint `https://api.mindease.me/v1/webhooks/stripe`, events `payment_intent.succeeded`, `payment_intent.payment_failed`, `payment_intent.canceled`, `charge.refunded`. Put its signing secret in `STRIPE_WEBHOOK_SECRET`. Test and live mode each need their own endpoint and secret.

**Zoom Video SDK** (call tracking, optional but recommended): in the Video SDK account (Platform Studio / Marketplace → the Video SDK app → Features → Event Subscriptions) add a subscription:
- Endpoint URL: `https://api.mindease.me/v1/webhooks/zoom-video`
- Events (Video SDK → Session): **Session started**, **Session user joined**, **Session ended**
- Copy the subscription's **Secret Token** into `ZOOM_WEBHOOK_SECRET_TOKEN`, run `php artisan config:cache`, then click **Validate** (Zoom checks the URL; the API answers the challenge).

What it does: saves when the call started and ended, its length, and whether the client and the therapist joined (`video` on panel bookings). When **both** joined, the booking is marked `completed` when the call ends; otherwise the therapist marks completed / no-show in the panel. Requests are verified with `x-zm-signature` and refused if older than 5 minutes; every event is safe to receive twice. Nothing said in the call is stored, and recording stays off.

Video env keys: `ZOOM_VIDEO_SDK_KEY`, `ZOOM_VIDEO_SDK_SECRET` (SDK credentials, not API keys), `ZOOM_WEBHOOK_SECRET_TOKEN`, optional `MINDEASE_JOIN_OPENS_MINUTES_BEFORE` (default 10).

## 4. Every update
```bash
ssh deploy@<VPS-IP>
bash /var/www/mindease/deploy/deploy.sh
```

## Logs
- App: `/var/www/mindease/storage/logs/`
- Queue worker: `.../storage/logs/worker.log` · `sudo supervisorctl status`
- Nginx: `/var/log/nginx/mindease.*.log`

## Backups
Enable the provider's daily snapshots and add a nightly DB dump, e.g.:
```bash
# root crontab
0 2 * * * mysqldump mindease | gzip > /root/backups/mindease-$(date +\%F).sql.gz && find /root/backups -mtime +14 -delete
```
