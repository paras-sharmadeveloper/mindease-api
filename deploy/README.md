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
