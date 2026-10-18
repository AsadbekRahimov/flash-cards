# LexiFlow Pro — DevOps Deployment Guide

This guide covers deploying LexiFlow Pro on a **bare server** that already has:
- Linux (Debian/Ubuntu recommended)
- Git
- PostgreSQL 14+
- PHP 8.2+ (with extensions: `pdo_pgsql`, `redis`, `bcmath`, `intl`, `gd` or `imagick`)
- Nginx (or Caddy)
- Certbot (SSL certificates)
- Supervisor (process manager for queue workers)
- Cron (system cron for scheduler)
- Redis

> If you prefer Docker, see `docker-compose.prod.yml` and `deploy.sh` in the project root.

## 0. Docker deployment

The production Dockerfile builds three reproducible stages: both Laravel/TWA frontend bundles, the PHP application with `vendor/`, and an nginx image containing the exact same `public/` tree. Production does not mount a persistent volume over `public/`.

Set `APP_DOMAIN`, `CERTBOT_EMAIL`, `APP_KEY` and the other required values in `.env`, then run:

```bash
chmod 600 .env
bash deploy.sh
```

The script uses `git pull --ff-only`, builds `app` and `nginx`, obtains the initial Let's Encrypt certificate when needed, runs migrations before switching containers, and performs an HTTPS `/up` smoke check. Certificate renewal runs in the `certbot` service; nginx reloads certificates periodically. CI deployment requires the `SSH_PRIVATE_KEY` secret.

---

## 1. Create the Database

```bash
sudo -u postgres psql
```

```sql
CREATE USER lexiflow WITH PASSWORD 'your_strong_password';
CREATE DATABASE lexiflow OWNER lexiflow;
GRANT ALL PRIVILEGES ON DATABASE lexiflow TO lexiflow;
\q
```

---

## 2. Clone the Repository

```bash
cd /var/www
git clone https://github.com/AsadbekRahimov/flash-cards.git lexiflow
cd lexiflow
```

Set ownership so the web server and your deploy user can both write to `storage/`:
```bash
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

---

## 3. Install PHP Dependencies

```bash
composer install --no-dev --optimize-autoloader --no-interaction
```

---

## 4. Configure the Environment

```bash
cp .env.example .env
nano .env
```

Minimum required values:

```env
APP_NAME="LexiFlow Pro"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com

# Generate with: php artisan key:generate --show
APP_KEY=base64:GENERATED_KEY_HERE

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=lexiflow
DB_USERNAME=lexiflow
DB_PASSWORD=your_strong_password

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_DB=0
REDIS_CACHE_DB=1
REDIS_QUEUE_CONNECTION=queue
REDIS_QUEUE_DB=2
REDIS_SESSION_DB=3
REDIS_LOCK_DB=4
REDIS_QUEUE_BLOCK_FOR=5
REDIS_MAXMEMORY=256mb
REDIS_MAXMEMORY_POLICY=noeviction
CACHE_STORE=redis
SESSION_DRIVER=redis
SESSION_CONNECTION=session

QUEUE_CONNECTION=redis
TELEGRAM_QUEUE=high

TELEGRAM_BOT_TOKEN=your_bot_token_from_BotFather
TELEGRAM_URL_SECRET=    # openssl rand -hex 24
TELEGRAM_HEADER_SECRET= # openssl rand -hex 24
TELEGRAM_WEBHOOK_URL=https://yourdomain.com
TELEGRAM_IP_ALLOWLIST_ENABLED=true

TWA_JWT_SECRET=         # openssl rand -hex 32
TWA_BASE_URL=https://yourdomain.com

SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax

BACKUP_DISKS=local
BACKUP_NOTIFICATION_MAIL=admin@yourdomain.com
```

Generate secrets in one step:
```bash
php artisan key:generate
echo "URL secret:    $(openssl rand -hex 24)"
echo "Header secret: $(openssl rand -hex 24)"
echo "JWT secret:    $(openssl rand -hex 32)"
```

Set correct permissions:
```bash
chmod 600 .env
```

---

## 5. Run Migrations and Seed Demo Data

```bash
php artisan migrate --force
php artisan db:seed   # loads demo admin, teacher, group, 120 words, 5 students
```

> On subsequent deploys, run `php artisan migrate --force` only — **never re-seed in production**.

---

## 6. Build the Frontend (TWA SPA)

If Node.js 18+ is available on the server:
```bash
cd resources/twa
npm ci                  # install all deps — build needs vite/vue-tsc from devDependencies
npm run build           # outputs to public/twa/ (+ pre-gzipped *.gz siblings, see "Compression" below)
rm -rf node_modules     # optional: free disk space after build
cd ../..
```

> Do **not** use `npm ci --omit=dev` here — `vite` and `vue-tsc` (the build tools) live in `devDependencies` and the build will fail without them.

If Node.js is not on the server, build locally and upload the `public/twa/` directory via rsync or scp (include the `*.gz` files — nginx `gzip_static` serves them).

---

## 7. Cache Laravel Config, Routes and Views

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

To clear all caches (e.g. after a config change):
```bash
php artisan optimize:clear
```

---

## 8. Configure Nginx

Create `/etc/nginx/sites-available/lexiflow`:

```nginx
server {
    listen 80;
    server_name yourdomain.com;

    # Certbot challenge
    location /.well-known/acme-challenge/ {
        root /var/www/certbot;
    }

    location / {
        return 301 https://$host$request_uri;
    }
}

server {
    listen 443 ssl http2;
    server_name yourdomain.com;

    ssl_certificate     /etc/letsencrypt/live/yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/yourdomain.com/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_ciphers         HIGH:!aNULL:!MD5;

    add_header Strict-Transport-Security "max-age=15552000; includeSubDomains" always;

    root /var/www/lexiflow/public;
    index index.php;

    # Compression (see "Compression and BREACH" below)
    gzip on;
    gzip_vary on;
    gzip_comp_level 6;
    gzip_min_length 512;
    gzip_types
        application/javascript application/json application/manifest+json
        application/wasm application/xml font/otf font/ttf image/svg+xml
        text/css text/javascript text/plain text/xml;

    # TWA SPA: serve the compiled Vue app with SPA fallback
    location /twa/ {
        gzip_static on;   # use the *.gz files created by `npm run build`
        try_files $uri $uri/ /twa/index.html;
        location ~* /twa/assets/ {
            add_header Cache-Control "public, max-age=31536000, immutable";
        }
        location = /twa/index.html {
            add_header Cache-Control "no-cache, no-store, must-revalidate";
        }
    }

    # Admin panel, 2FA pages and Livewire (hash-prefixed routes such as
    # /livewire-<hash>/livewire.min.js). Handled by Laravel, never by a static
    # *.js location, and never compressed (BREACH). PHP-FPM is called directly:
    # `gzip off` inside a try_files location would be lost on the internal
    # redirect to `location ~ \.php$`.
    location ~ ^/(?:admin(?:/|$)|2fa(?:/|$)|livewire-) {
        gzip off;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        fastcgi_param DOCUMENT_URI /index.php;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        # Update the socket path to match your installed PHP version
        # (e.g. php8.3-fpm.sock or php8.4-fpm.sock). Verify with: ls /run/php/
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Enable and test:
```bash
ln -s /etc/nginx/sites-available/lexiflow /etc/nginx/sites-enabled/
nginx -t
systemctl reload nginx
```

### Compression and BREACH

The Docker configs (`docker/nginx/prod.conf`, `docker/nginx/default.conf`) and the example above compress traffic as follows:

| What | Behaviour |
|---|---|
| TWA static (`/twa/`) | `npm run build` runs `resources/twa/scripts/precompress.mjs` (built-in `node:zlib`, gzip level 9) and writes `file.gz` next to every `.js .css .html .svg .json .webmanifest .txt` of at least 512 bytes (only if smaller). nginx serves them with `gzip_static on`, no CPU per request. Files without `.gz` fall back to on-the-fly gzip. Run `npm run precompress --prefix resources/twa` to redo it by hand. |
| API / other dynamic responses | on-the-fly `gzip`, level 6, `gzip_min_length 512`; types: JS, JSON, `manifest+json`, XML, wasm, SVG, CSS, text, `font/ttf`, `font/otf`. |
| Not compressed | `woff2`, `mp3` (TTS audio, `/api/twa/tts`), `png/jpg/webp` — already compressed. |
| `/admin`, `/2fa`, `/livewire-*` | `gzip off` (BREACH: pages carry CSRF tokens and reflect input). The traffic is negligible. |
| Brotli | not enabled (no module in `nginx:alpine`); a possible later step. |

Why admin paths are separate `location` blocks with their own `fastcgi_pass`: `gzip off` inside `location /admin { try_files ... /index.php; }` does not help — `try_files` makes an internal redirect to `/index.php`, and the response is then produced by `location ~ \.php$`, where gzip is on. Therefore these paths go straight to PHP-FPM with `SCRIPT_FILENAME`/`SCRIPT_NAME`/`DOCUMENT_URI` pointing at `index.php` (the same values the internal redirect would give). If you change the fastcgi params in `location ~ \.php$`, mirror the change in that block. Filament's static files live in `/css/filament`, `/js/filament`, `/fonts/filament` (not under `/admin`) and stay compressed.

---

## 9. Obtain SSL Certificate

```bash
certbot certonly --webroot \
    -w /var/www/certbot \
    -d yourdomain.com \
    --email admin@yourdomain.com \
    --agree-tos --non-interactive
```

If port 80 is free (no other sites):
```bash
certbot --nginx -d yourdomain.com
```

Certbot auto-renews via its own systemd timer. Verify:
```bash
certbot renew --dry-run
```

---

## 10. Configure Supervisor (Queue Workers)

Create `/etc/supervisor/conf.d/lexiflow.conf`:

```ini
[program:lexiflow-worker]
process_name=%(program_name)s_%(process_num)02d
; Use the absolute path to the PHP binary (find yours with `which php`).
; Avoids issues when www-data has a minimal PATH.
command=/usr/bin/php /var/www/lexiflow/artisan queue:work redis --queue=high,default,low --tries=3 --timeout=120 --sleep=1 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/lexiflow/storage/logs/worker.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=3
user=www-data
```

For bigger classes, increase `numprocs` before adding app containers. With Redis queue and the current DB indexes, 2-3 workers are enough for roughly 300 active students/day unless Telegram API latency dominates.

Keep `REDIS_MAXMEMORY_POLICY=noeviction` while cache, sessions and queues share the same Redis instance. `allkeys-lru` is only safe after moving queues/sessions to a separate Redis instance, otherwise Redis can evict pending jobs under memory pressure.

Apply:
```bash
supervisorctl reread
supervisorctl update
supervisorctl start lexiflow-worker:*
supervisorctl status
```

---

## 11. Configure Cron (Laravel Scheduler)

```bash
crontab -e -u www-data
```

Add (using the absolute path to PHP — find yours with `which php`):
```cron
* * * * * cd /var/www/lexiflow && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

This runs every minute. Laravel's scheduler internally dispatches:
- `ops:scheduler-heartbeat` — every minute (liveness marker read by `/health/ready`).
- `exams:close-expired` — every minute (closes timed-out exam sessions).
- `repetitions:notify` — daily at 18:00 (review reminders).
- `backup:clean` — daily at 01:00.
- `backup:run` — daily at 01:30.
- `backup:monitor` — daily at 02:00.

---

## 12. Register the Telegram Webhook

```bash
cd /var/www/lexiflow
php artisan telegram:set-webhook
```

To verify:
```bash
curl "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/getWebhookInfo"
```

---

## 13. Smoke Test

Run these checks after every deployment:

```bash
# 1. Laravel health check + dependency readiness (DB, Redis, scheduler, queues)
curl -sf https://yourdomain.com/up && echo "OK"
curl -sf https://yourdomain.com/health/ready && echo "READY"

# 2. Admin panel accessible
curl -sI https://yourdomain.com/admin | grep "HTTP/"

# 3. Migrations applied
php artisan migrate:status

# 4. Queue workers running
supervisorctl status lexiflow-worker:*

# 5. Scheduler running (check logs)
cat storage/logs/laravel.log | grep "schedule"

# 6. Webhook registered
curl "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/getWebhookInfo" | python3 -m json.tool

# 7. Audit log clean
php artisan composer:audit 2>/dev/null || composer audit

# 8. Compression: pre-compressed TWA asset is served as gzip
ASSET=$(ls public/twa/assets/*.js | head -1 | xargs basename)
curl -sI -H 'Accept-Encoding: gzip' "https://yourdomain.com/twa/assets/$ASSET" | grep -i "content-encoding"   # -> gzip

# 9. Compression is OFF for the admin panel and 2FA (BREACH): expect no output
curl -sI -H 'Accept-Encoding: gzip' https://yourdomain.com/admin/login | grep -i "content-encoding"
curl -sI -H 'Accept-Encoding: gzip' https://yourdomain.com/2fa/challenge | grep -i "content-encoding"
```

---

## 14. Updating the Application

```bash
cd /var/www/lexiflow

# Pull latest code
git fetch origin
git pull origin master

# Install new dependencies (if composer.json changed)
composer install --no-dev --optimize-autoloader --no-interaction

# Run new migrations
php artisan migrate --force

# Rebuild frontend (if resources/twa/ changed)
# Use plain `npm ci` — vite/vue-tsc are devDependencies needed for the build.
cd resources/twa && npm ci && npm run build && cd ../..

# Re-cache
php artisan livewire:publish --assets --no-interaction
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Restart queue workers to pick up new code
supervisorctl restart lexiflow-worker:*

# Reload nginx
nginx -t && systemctl reload nginx
```

If Filament login loads but the browser reports `404` for
`/livewire-<hash>/livewire.min.js`, verify that the route exists and that
Nginx forwards it to Laravel:

```bash
php artisan optimize:clear
php artisan route:list --path=livewire
php artisan livewire:publish --assets --no-interaction
php artisan config:cache
php artisan route:cache
php artisan view:cache
nginx -t && systemctl reload nginx
```

---

## 15. Monitoring and Error Tracking

### 15.1 Error tracking (Sentry / GlitchTip)

LexiFlow uses `sentry/sentry-laravel`. The integration is wired in `bootstrap/app.php`
(`Integration::handles($exceptions)`) and configured in `config/sentry.php`.

```dotenv
SENTRY_LARAVEL_DSN=https://<key>@sentry.example.com/<project>   # empty = disabled
SENTRY_ENVIRONMENT=production                                    # empty = APP_ENV
SENTRY_TRACES_SAMPLE_RATE=0.1                                    # performance sampling, 0 to disable
SENTRY_SEND_DEFAULT_PII=false                                    # keep false
```

- **Empty DSN = nothing is sent.** Local development and the test-suite run with an empty DSN.
- **Self-hosted GlitchTip** speaks the Sentry protocol: just put the GlitchTip project DSN into
  `SENTRY_LARAVEL_DSN`, no code or config changes are needed.
- **Privacy.** `before_send` / `before_send_transaction` call
  `App\Domain\Operations\Support\SentryScrubber`, which strips from every event, breadcrumb and span:
  `Authorization` / `Cookie` headers and cookies, `init_data` request bodies, the Telegram bot token
  (also inside `https://api.telegram.org/bot<token>/...` URLs), `TELEGRAM_URL_SECRET` / `TELEGRAM_HEADER_SECRET` /
  `TWA_JWT_SECRET` values, the `/telegram/webhook/{secret}` URL segment and `secret=`/`token=` query params.
  Telegram update bodies additionally go through `UpdateSanitizer` (names, usernames and message texts are masked).
  The hooks are array callables, so `php artisan config:cache` works.
- Test the wiring after a deploy: `php artisan sentry:test` (needs a DSN) and check that the event arrives
  without credentials.

### 15.2 Readiness probe: `GET /health/ready`

Unlike the framework `/up` (process is alive), `/health/ready` checks dependencies and is meant for
an external uptime monitor (UptimeRobot / Better Stack / GlitchTip uptime) and for deploy smoke tests.

| Check | Fails when |
|---|---|
| `db` | `select 1` throws |
| `redis` | `PING` fails (only reported if cache or queue use the `redis` driver) |
| `scheduler` | no scheduler heartbeat for more than 3 minutes (`READINESS_HEARTBEAT_MAX_AGE`, seconds) |
| `queue` | backlog of the `high` (`TELEGRAM_QUEUE`) or `default` queue exceeds `READINESS_QUEUE_MAX_SIZE` (1000) |

Response: `200 {"status":"ok","checks":{"db":"ok",...}}` or `503 {"status":"fail","checks":{"db":"fail",...}}`.
Only `ok`/`fail` is returned; error details, hosts and versions are written to the application log
(`readiness.check_failed`), never to the response. The route is throttled (60 req/min per IP) and stateless.

The scheduler heartbeat is written by a scheduled callback (`ops:scheduler-heartbeat`, every minute, see
`routes/console.php`) into the cache. After the very first deploy the check turns green within a minute.

### 15.3 What to monitor and alert on

| Signal | Alert when |
|---|---|
| `GET /health/ready` (every 1 min) | 2 consecutive non-200 responses; the `checks` body tells which dependency failed |
| Sentry / GlitchTip issues | new issue in `production`; spike of a known issue |
| Failed jobs (`failed_jobs`, `php artisan queue:failed`) | any growth, especially `PostLeaderboardJob` |
| `backup:monitor` mail | any message (backup is missing or too old) |
| Certificate expiry | < 14 days |
| Disk usage on the DB / backup volume | > 80% |

Product events (`product_events` table, see `docs/03_DATABASE.md`) are for product analytics, not alerting.
`reminder.failed` is expected for students who blocked the bot; alert only on a sudden spike.

---

## File Permissions Reference

```bash
# After cloning or updating
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
chmod 600 .env
```

---

## Environment Variables Reference

| Variable | Required | Example |
|---|---|---|
| `APP_KEY` | ✅ | `base64:...` |
| `APP_URL` | ✅ | `https://yourdomain.com` |
| `DB_HOST` | ✅ | `127.0.0.1` |
| `DB_DATABASE` | ✅ | `lexiflow` |
| `DB_USERNAME` | ✅ | `lexiflow` |
| `DB_PASSWORD` | ✅ | strong password |
| `TELEGRAM_BOT_TOKEN` | ✅ | from @BotFather |
| `TELEGRAM_URL_SECRET` | ✅ | `openssl rand -hex 24` |
| `TELEGRAM_HEADER_SECRET` | ✅ | `openssl rand -hex 24` |
| `TELEGRAM_WEBHOOK_URL` | ✅ | `https://yourdomain.com` |
| `TWA_JWT_SECRET` | ✅ | `openssl rand -hex 32` |
| `TWA_BASE_URL` | ✅ | `https://yourdomain.com` |
| `REDIS_HOST` | ✅ | `127.0.0.1` |
| `REDIS_CACHE_DB` | recommended | `1` |
| `REDIS_QUEUE_DB` | recommended | `2` |
| `REDIS_SESSION_DB` | recommended | `3` |
| `REDIS_LOCK_DB` | recommended | `4` |
| `REDIS_MAXMEMORY_POLICY` | recommended | `noeviction` |
| `QUEUE_CONNECTION` | ✅ | `redis` |
| `CACHE_STORE` | ✅ | `redis` |
| `SESSION_DRIVER` | ✅ | `redis` |
| `TELEGRAM_IP_ALLOWLIST_ENABLED` | recommended | `true` |
| `BACKUP_NOTIFICATION_MAIL` | ✅ | `admin@yourdomain.com` |
| `BACKUP_DISKS` | ✅ | `local` or `local,s3` |
| `SESSION_ENCRYPT` | recommended | `true` |
| `SESSION_SECURE_COOKIE` | recommended | `true` |
| `SENTRY_LARAVEL_DSN` | recommended | Sentry / GlitchTip DSN (empty = disabled) |
| `SENTRY_ENVIRONMENT` | optional | `production` |
| `SENTRY_TRACES_SAMPLE_RATE` | optional | `0.1` |
| `SENTRY_SEND_DEFAULT_PII` | keep | `false` |
| `READINESS_HEARTBEAT_MAX_AGE` | optional | `180` (seconds) |
| `READINESS_QUEUE_MAX_SIZE` | optional | `1000` |
