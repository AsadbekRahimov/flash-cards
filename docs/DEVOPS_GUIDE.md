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
php artisan db:seed   # loads demo admin, teacher, 2 groups, 120 words, 5 learners (6 group memberships)
```

> On subsequent deploys, run `php artisan migrate --force` only — **never re-seed in production**.

> **Migration history (docs/12 §2.2).** Until the first production release the create-migrations are edited **in place**
> (the learner-first schema replaced `students` that way), so every non-production environment — local, CI, the demo
> stand — is rebuilt with `php artisan migrate:fresh --seed` **followed by `php artisan cache:clear`** (Redis keeps
> leaderboard keys of the old format); applying new code over an old database shows a
> `migrate:status` mismatch (the `create_students_table` row has no file on disk). From the first production release on,
> schema changes are **forward migrations only**.

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

    # Pre-generated word audio (task 1.2c, `php artisan audio:pregenerate`): content-addressed
    # mp3 files, never change -> long cache; no gzip. Only audio/ is exposed.
    location ^~ /storage/audio/ {
        alias /var/www/lexiflow/storage/app/public/audio/;
        gzip off;
        add_header Cache-Control "public, max-age=2592000, immutable";

        # `^~` skips the server-level dotfile rule: deny `.tmp/` (half-written files) explicitly.
        location ~ /\. {
            deny all;
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
| Not compressed | `woff2`, `mp3` (TTS audio, `/api/twa/tts` and `/storage/audio/`), `png/jpg/webp` — already compressed. |
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

Pre-generated word audio (`PregenerateWordAudioJob`, see below) runs on the `low` queue, which the `--queue=high,default,low` list above already includes (the Docker `queue` service uses the same list). The job retries 5 times with backoff 30s / 2m / 10m / 30m.

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

### 10.1 Pre-generated word audio (task 1.2c)

Words are voiced once by the edge-tts service (`TTS_SERVICE_URL`, `TTS_SERVICE_TIMEOUT`) instead of on every learner request:

```bash
php artisan audio:pregenerate <course-slug>          # queue jobs for active words without current audio
php artisan audio:pregenerate <course-slug> --force  # re-synthesize existing files (one TTS call per unique text)
```

- Files: `storage/app/public/audio/{sha1(text|voice|version)}.mp3` (public disk), `words.audio_path` holds the relative path. Same text in several words/courses = one file. Writes are atomic: the body goes to `audio/.tmp/{name}.{random}` and is renamed onto the final name; an empty or truncated file counts as "not generated" and is rebuilt. Changing `TTS_VOICE` or `TTS_AUDIO_VERSION` produces new file names on its own, so run the command **without** `--force` after that (`--force` is only for replacing the contents of files that already exist, e.g. a bad batch); old files stay on disk (delete `storage/app/public/audio` and re-run the command to clean up). Editing a word's English text in the admin panel clears its `audio_path` and queues a new job.
- The `tts` container must be reachable from the queue worker. If it is down, jobs retry and then land in `failed_jobs` (`php artisan queue:retry all` when it is back); the rest of the batch is not affected, learners just keep getting Web Speech / live `/api/twa/tts` (503 `tts_unavailable` when the TTS service is down or unreachable).
- Serving: nginx `location ^~ /storage/audio/` (see `docker/nginx/*.conf`) reads `storage/app/public/audio` directly and denies dotfiles (`.tmp/`). In `docker-compose.prod.yml` the audio lives in its own named volume `app_audio`, mounted at `/var/www/html/storage/app/public/audio` in `app`, `queue` and `scheduler` (read-write) and **only** that volume, read-only, in `nginx`: logs, sessions and other `storage/` contents are not visible to nginx. `php artisan storage:link` is not required for the audio, but the card `audio_url` is built from `APP_URL` + `/storage/...`, so `APP_URL` must be the public https origin.
- Deploying this volume layout onto an existing install (audio already generated into `app_storage`): the first `docker compose -f docker-compose.prod.yml up -d` creates an empty `app_audio`, so existing mp3 files are not served until you copy them over (or just re-run `audio:pregenerate`, which re-creates them; until then learners get the live `/api/twa/tts`). To keep them (the old files are in the `app_storage` volume under `app/public/audio/`, which the new mount shadows, so copy from a throw-away container; if `app_audio` does not exist yet, `docker volume create <project>_app_audio` first):

  ```bash
  docker compose -f docker-compose.prod.yml down            # stop (volumes are kept)
  docker run --rm -v <project>_app_storage:/old:ro -v <project>_app_audio:/new alpine \
      sh -c 'mkdir -p /new && cp -a /old/app/public/audio/. /new/ && chown -R 1000:1000 /new'   # UID:GID = build args UID/GID of the `app` user
  docker compose -f docker-compose.prod.yml up -d
  ```

  (`<project>` is the compose project name, see `docker volume ls`.) The old copy inside `app_storage` can be deleted afterwards (optional). An `app_audio` created by Docker from a fresh image is already owned by `app` (the image creates `storage/app/public/audio`); only a manually created volume needs the `chown`.
- Pre-generation is not triggered automatically (neither by import nor by publishing): run the command after importing or publishing a course.

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
- `reminders:dispatch` — every 15 minutes (personal reminders by the learner's local time; a learner is claimed by a conditional UPDATE, so overlapping runs are safe; `withoutOverlapping(10)` — the mutex expires after 10 minutes — and `runInBackground`). Its jobs go to the `default` queue, spread at `REMINDERS_SEND_RATE_PER_SECOND` (25/s) over at most 14 minutes.
- `model:prune` — daily at 03:00 (`product_events` and `notifications_log`, 400 days by default).
- `training:close-expired` — every 15 minutes (expired personal / stale group training sessions).
- `subscriptions:expire` — hourly (time-based subscription transitions).
- `billing:expire-orders` — every 15 minutes (pending payment orders past `BILLING_ORDER_TTL_MINUTES` become `expired`).
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

### 14.1 Deploying task 1.5b (personal reminders)

Reminders by the learner's local time replace the old daily `repetitions:notify`. Deploy it like this:

1. **Avoid the peak.** Most learners keep the default 19:00 Tashkent time = 14:00 UTC. Do not deploy (and do not restart workers) between about 13:30 and 14:30 UTC.
2. **Backfill right after the deploy.** `php artisan migrate --force`, restart the workers, then run `php artisan reminders:dispatch` once by hand: it gives `next_reminder_at` to every existing learner with a `reminder_time` (up to `REMINDERS_BACKFILL_LIMIT`, default 10000, per run — repeat the command until `backfilled` is 0 on a base larger than that). Without it the backfill happens on the next 15-minute runs, which is fine but invisible. Run it before 13:30 UTC or after 14:30 UTC: learners whose time has already passed today are not reminded late (`REMINDERS_MAX_DELAY_MINUTES`), their pointer just moves to tomorrow.
3. **Clean up the old queue.** The removed `SendReviewReminderJob` may still sit in the Redis queues or in `failed_jobs`: let the workers drain the queue (`php artisan queue:work --stop-when-empty`, or check `redis-cli -n 2 llen queues:default`) and then `php artisan queue:flush` to drop the old failed jobs. A queued old job would fail with a missing class, harmlessly, but would pollute `failed_jobs` alerts.
4. **Queue visibility timeout.** `REDIS_QUEUE_RETRY_AFTER` must be above the workers' `--timeout` (120 in `docker-compose.prod.yml` and in the supervisor unit); the default and `.env.example` now say 180. A lower value hands a long-running job to a second worker while the first is still running it.
5. After the first evening check `reminders:dispatch` output (`claimed / queued / skipped stale / repaired / backfilled`) and `failed_jobs`; a peak of N learners takes about N / 25 seconds to leave (at most 14 minutes).

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
  Telegram update bodies additionally go through `UpdateSanitizer` (names, usernames, message texts, group titles and phone numbers are masked).
  The scrubber also **drops all stack-frame arguments** (`Frame::getVars()`) of every exception and of the
  event stacktrace: sentry-php stores the raw call arguments there (e.g. the URL with the bot token passed to
  the HTTP client on a Telegram timeout, chat texts, `initData`), which cannot be whitelisted, so they are
  cleared entirely. JSON bodies of Livewire requests (`snapshot` / `updates`) get `password*`, `code`,
  `recovery_code` and `one_time_password` masked, also when double-encoded.
  The hooks are array callables, so `php artisan config:cache` works.
- **Sentry Logs.** `enable_logs` (`SENTRY_ENABLE_LOGS`) is `false` by default. Log records sent that way bypass
  `before_send`, so `before_send_log` is wired to `SentryScrubber::beforeSendLog` (body and attributes go through
  the same redactor). Do not remove that hook if you enable the option.
- **Defense in depth: `zend.exception_ignore_args = On`.** The app image installs `docker/php/zz-lexiflow.ini`
  (`zend.exception_ignore_args=On`, `expose_php=Off`), so PHP does not even put call arguments into exception
  traces. It does **not** touch exception *messages* (see 15.1a). On a bare-metal php-fpm (no Docker) set the same in
  `php.ini` / `conf.d` of the FPM pool and the CLI queue workers. sentry-php has no option to skip frame
  arguments, so this ini setting plus the scrubber are the two layers.
- Test the wiring after a deploy: `php artisan sentry:test` (needs a DSN) and check that the event arrives
  without credentials.

### 15.1a Secrets in logs and `failed_jobs`

Network errors of the Bot API client carry the full request URL in the exception **message**
(`cURL error 28: ... for https://api.telegram.org/bot<id>:<token>/sendMessage`; Guzzle masks only URL userinfo).
Without extra care it would land in `storage/logs/laravel.log`, stderr and `failed_jobs.exception`
(Laravel stores `(string) $exception`, including the `previous` chain). One redactor,
`App\Domain\Operations\Support\SecretRedactor`, is used at three places:

| Where | Mechanism |
|-------|-----------|
| `laravel.log` / `stderr` / syslog / errorlog / slack / papertrail | `RedactSecretsInLogs` tap on every channel in `config/logging.php` pushes `RedactingLogProcessor`: masks `message`, `context`, `extra` recursively; a `Throwable` in the context (`exception`) is replaced by its redacted text (message, `previous` chain, trace without call arguments). New channels must get the same `tap`. |
| `failed_jobs.exception` and any exception that leaves the Telegram client | `IrazasyedTelegramClient` wraps every SDK/Guzzle error into `TelegramApiException` with the redacted message and **without** `previous`, so `(string) $e` is clean. 403 stays `TelegramForbiddenException`. |
| Sentry events / transactions / logs | `SentryScrubber` (15.1). |

Masked: `bot<id>:<token>` in URLs and bare bot tokens, literal values of `TELEGRAM_BOT_TOKEN`,
`TELEGRAM_URL_SECRET`, `TELEGRAM_HEADER_SECRET`, `TWA_JWT_SECRET`, the `/telegram/webhook/{secret}` segment,
`Bearer ...`, `secret=`/`token=`/`init_data=` pairs, values under keys containing `token`, `secret`, `password`,
`authorization`, `cookie`, `signature`, `init_data` (context arrays only). Not covered: the Laravel "emergency" logger
(used only if a channel cannot be created) and third-party sinks that do not use the Laravel logger.
After a token rotation the old value is not in the config any more, but the URL pattern still masks it.

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
| `billing.promo_rejected_burst` (warning log; `learner_id`, `rejections`, `last_reason`) | any occurrence outside a known broken client — one learner failed ≥ 5 promo redemptions in 10 min (`BILLING_PROMO_REJECTIONS_ALERT_*`): code guessing; consider blocking the learner |

Product events (`product_events` table, see `docs/03_DATABASE.md`) are for product analytics, not alerting.
`reminder.failed` is expected for learners who blocked the bot or never opened a DM with it (`reason`: `bot_blocked` / `not_started` / `user_deactivated` / `chat_not_found`); it sets `learners.dm_blocked_at` and the learner is not retried until they write to the bot. Alert only on a sudden spike. `reminders:dispatch` prints `claimed / queued / skipped stale / repaired / backfilled`; a steadily non-zero `skipped stale` means the scheduler or the queue lags by more than `REMINDERS_MAX_DELAY_MINUTES`.
`promo.rejected` is likewise expected (typos); the burst *log line* above, not the event, is the alert.

---

## 16. Public site (landing, offer, privacy, security.txt)

| What | Where |
|---|---|
| Routes | `routes/web.php`: `/`, `/offer`, `/privacy` (language from `Accept-Language`, `ru` default, `Vary: Accept-Language`) and explicit `/{ru\|uz}`, `/{ru\|uz}/offer`, `/{ru\|uz}/privacy` (canonical URLs, used by the language switcher and `hreflang`) |
| Code | `App\Http\Controllers\PublicSiteController`, `App\Domain\Site\Services\SiteLinks`, `LocaleResolver::fromAcceptLanguage()` |
| Links and CSP | `canonical` / `hreflang` / internal links are built from `APP_URL` (a forged `Host` cannot change them — set `APP_URL` to the public https origin). The pages send `script-src 'none'` (`App\Http\Middleware\StaticPageCsp`); all other CSP directives equal the app-wide policy |
| Templates | `resources/views/site/` (`layout`, `landing`, `_legal` shared by `offer` and `privacy`) |
| Landing texts (ru/uz) | `lang/{ru,uz}/landing.php` |
| Legal texts (ru/uz) | `lang/{ru,uz}/legal.php` |
| Styles | `public/css/site.css` (static, no build step; dark theme via `prefers-color-scheme`) |
| Bot button | `https://t.me/<TELEGRAM_BOT_USERNAME>`; with an empty/invalid username the button is replaced by a disabled "coming soon" label |
| Support contact | `BILLING_SUPPORT_CONTACT` (-> `config('billing.paywall.contact')`): e-mail, `@telegram_handle`, `https://` URL or plain text. Empty -> the contact line is not rendered anywhere |
| security.txt | `public/.well-known/security.txt` (RFC 9116) |

The pages are stateless: the route group drops cookies, session and CSRF middleware, so responses carry no `Set-Cookie` and are sent with `Cache-Control: public, max-age=300`. Security headers and CSP come from the global `SecurityHeaders` middleware. nginx needs no changes: the pages go through `location /` (they contain no secrets, so they are *not* in the BREACH `gzip off` block) and `.well-known/security.txt` is a plain static file (`location ~ /\.(?!well-known).*` only denies other dot-paths).

**Replacing the legal text placeholders.** `offer` and `privacy` are stubs: a numbered list of section titles, each followed by "text will be provided by the owner", plus a "draft" notice and `noindex`. In `lang/{ru,uz}/legal.php`, `offer.sections` / `privacy.sections` is a list; replace a title string with `['title' => '...', 'text' => "Paragraph 1\n\nParagraph 2"]` to publish its text (paragraphs are split on blank lines; plain strings stay placeholders). Section order/count may differ from the stub but must stay identical in `ru` and `uz` (`LocalizationTest` checks key parity). Once all sections are real: remove `draft_notice` from the views (`resources/views/site/_legal.blade.php`) and drop `'noindex' => true` in `offer.blade.php` / `privacy.blade.php`.

**security.txt.** `Contact` is a placeholder (`security@example.com`): put a real monitored address there before launch. `Expires` must be renewed at least once a year (currently `2027-10-18`).

Smoke check: `curl -sI https://yourdomain.com/ | grep -i -e content-security -e set-cookie` (CSP present, no cookie) and `curl -s https://yourdomain.com/.well-known/security.txt`.

---

## 17. Payments (Telegram Stars, Payme, Click)

Reference: docs/13_PHASE2_DESIGN.md §4, §10; admin side: ADMIN_GUIDE "Billing". Payments are **off** by default
(`BILLING_PAYWALL_MODE=coming_soon`, every `*_ENABLED=false`): deploying the code changes nothing for learners until
you switch a provider on.

> **Status.** The ledger, checkout API, admin screens and the `billing:expire-orders` job exist (tasks 2A.1, 2A.2,
> 2A.7). The provider adapters and their webhook URLs are added by 2A.3 (Stars), 2A.4 (Payme) and 2A.5 (Click):
> every URL and check below marked **(2A.3)** / **(2A.4)** / **(2A.5)** works only after that task is deployed. The
> `PAYME_*` / `CLICK_*` / `BILLING_STARS_*` variables can be filled in beforehand.

### 17.1 Environment

| Variable | Notes |
|---|---|
| `BILLING_PAYWALL_MODE` | `coming_soon` → `purchase` once at least one provider is enabled **and** prices are set. With an empty catalog the API silently falls back to `coming_soon` |
| `BILLING_STARS_ENABLED` | `true` makes the Stars adapter sellable (2A.3). No separate key: Stars use the bot token |
| `BILLING_STARS_MAX_SUBSCRIPTION_PRICE` | Telegram's cap for a Stars *subscription* price (10000); a month above it is not sold |
| `PAYME_ENABLED`, `PAYME_MERCHANT_ID` | merchant id from the Payme business cabinet |
| `PAYME_KEY` | **production** merchant key (secret) |
| `PAYME_TEST_KEY` | key of the **test** cabinet (secret); the webhook accepts both, so sandbox and prod differ by key. Leave empty in production once testing is done |
| `PAYME_CHECKOUT_URL` | `https://checkout.paycom.uz` (sandbox: `https://checkout.test.paycom.uz`) |
| `PAYME_IP_ALLOWLIST` | comma-separated IPs/CIDRs of Payme's callers from the cabinet; empty = off |
| `PAYME_FISCAL_IKPU`, `PAYME_FISCAL_PACKAGE_CODE`, `PAYME_FISCAL_VAT_PERCENT` | fiscal receipt data (the owner / accountant provides the IKPU code) |
| `CLICK_ENABLED`, `CLICK_MERCHANT_ID`, `CLICK_SERVICE_ID`, `CLICK_MERCHANT_USER_ID` | from the Click merchant cabinet |
| `CLICK_SECRET_KEY` | secret key of the service (secret) |
| `CLICK_CHECKOUT_URL`, `CLICK_IP_ALLOWLIST` | as for Payme |
| `BILLING_PRICE_*` | prices in minor units (UZS in tiyin = sum x 100; XTR whole Stars); `0` = not for sale in that currency (see `.env.example`) |
| `BILLING_ORDER_TTL_MINUTES` | 1440; Payme / Click refuse an expired order |
| `BILLING_SUPPORT_CONTACT` | shown in the paywall and by `/paysupport` |

`PAYME_KEY`, `PAYME_TEST_KEY` and `CLICK_SECRET_KEY` are secrets: only in `.env` (never in git, chat or tickets),
`chmod 640`. The admin panel masks key-like fields of stored webhook payloads and the order token, and the log redactor
(`SecretRedactor`) masks `Authorization`, tokens and secrets in `laravel.log` / Sentry; still do not paste raw requests
anywhere. After editing `.env` run `php artisan config:cache` and restart the workers
(`supervisorctl restart lexiflow-worker:*`).

### 17.2 Webhook URLs (register in the provider's cabinet)

All are `POST`, publicly reachable over HTTPS, no session / CSRF, throttled to 120 req/min per IP, body up to 64 KB.
Nginx must pass them to Laravel like `/telegram/webhook`; do **not** put them behind HTTP auth or the admin IP filter.

| Provider | URL | Appears in | Authentication |
|---|---|---|---|
| Telegram Stars | the existing `/telegram/webhook/{secret}` — **no new URL**, but `allowed_updates` must include `pre_checkout_query`; re-run `php artisan telegram:set-webhook` after deploying 2A.3 | 2A.3 | `TELEGRAM_URL_SECRET` + `X-Telegram-Bot-Api-Secret-Token` |
| Payme | `https://<domain>/billing/webhook/payme` (one URL for all JSON-RPC methods) | 2A.4 | `Authorization: Basic base64("Paycom:" + key)`, `hash_equals` with `PAYME_KEY` / `PAYME_TEST_KEY` |
| Click Prepare | `https://<domain>/billing/webhook/click/prepare` | 2A.5 | md5 `sign_string` with `CLICK_SECRET_KEY` |
| Click Complete | `https://<domain>/billing/webhook/click/complete` | 2A.5 | same |

These URLs are the design of docs/13 §4 and may be corrected by 2A.3–2A.5 when they check the providers' documentation:
the task that implements a provider is the final word, and updates this table.

Payme answers errors with HTTP 200 and a JSON-RPC error (`-32504` for a wrong key) — a 200 in the access log is not a
sign of success; read the body or the payment screens. If the provider publishes caller IPs, put them in
`*_IP_ALLOWLIST` (behind a reverse proxy make sure the real client IP reaches Laravel: trusted proxies).

### 17.3 Sandbox first

1. **Payme.** Create a test merchant in the Payme test cabinet; put its id in `PAYME_MERCHANT_ID` and the test key in
   `PAYME_TEST_KEY`, set `PAYME_CHECKOUT_URL=https://checkout.test.paycom.uz`, register the webhook URL for the test
   cabinet, run the cabinet's check scenarios (CheckPerformTransaction, Create, Perform, Cancel before / after
   perform, CheckTransaction, GetStatement). Only then switch to the production merchant id, key and checkout URL.
2. **Click.** Use the test service (test `service_id`, `merchant_id`, secret) and the Click test flow; complete a
   payment and a cancel from the cabinet.
3. **Stars.** Run the whole flow with a real account on a **small price** (e.g. `BILLING_PRICE_PRO_MONTH_XTR=1` on a
   staging bot): invoice, payment, `successful_payment`, a refund from the admin panel (**Payments → Refund**).
4. Production switch: real keys, real prices, `*_ENABLED=true`, `BILLING_PAYWALL_MODE=purchase`, `config:cache`,
   restart workers. Keep one provider enabled at first.

### 17.4 After every deploy of billing code

1. `php artisan migrate --force` (new billing tables / columns), `config:cache`, `supervisorctl restart lexiflow-worker:*`.
2. `php artisan schedule:list` shows `billing:expire-orders` (every 15 min) and `subscriptions:expire` (hourly); the
   scheduler heartbeat on `/health/ready` is green.
3. Admin panel (admin account): **Billing → Payments / Payment orders / Provider transactions** open; the two
   dashboard widgets ("Revenue, last 30 days", "Payment webhook errors, last 24 h") render. A **teacher** account must
   get 403 on `/admin/payments` and must not see the Billing group.
4. The checkout catalog (`GET /api/twa/billing/catalog` with a valid TWA token): `items` and `providers` list exactly the
   providers you enabled and nothing else.
5. Provider webhooks **(2A.3–2A.5)**: from the provider's cabinet send a test call; a wrong key / signature must be
   rejected (Payme body `-32504`, Click `-1`), a right one answered; make one sandbox payment end to end and see the
   `succeeded` row in **Payments**, the order `paid` and the subscription in **Subscriptions**.
6. After the first payments, look for error lines in `storage/logs/laravel.log` (`grep 'billing\.' storage/logs/laravel.log`):
   no `billing.amount_mismatch`, `billing.payment_orphan` or `billing.admin_refund_unrecorded` (see 17.5).
7. Check that no secret ended up in the logs: `grep -E "PAYME_|CLICK_SECRET|sign_string|Authorization: Basic" storage/logs/laravel.log` prints nothing.

### 17.5 What to monitor

| Signal | Meaning / action |
|---|---|
| Dashboard "Payment webhook errors, last 24 h" > 0 | a `failed` ledger row: money arrived but was not credited (wrong amount / currency, dead or paid order, orphan Stars renewal). Open **Payments → Status: failed**, decide: grant Pro by hand or refund |
| `billing.amount_mismatch` (error log) | a provider sent an amount different from the order: check `BILLING_PRICE_*` changes between checkout and payment; repeated = possible tampering |
| `billing.payment_orphan` (error log) | Stars renewal whose order is gone; the learner paid without getting Pro until support acts |
| `billing.admin_refund_unrecorded` (error log) | an admin refund returned the money at the provider but the ledger write failed; fix the cause, then record it with "returned by hand" |
| `billing.admin_refund_provider_failed` (error log) | the provider refused / was down for an admin refund; nothing was changed |
| Pending orders keep growing | `billing:expire-orders` is not running: the scheduler is down (see 11) |
| 5xx on `/billing/webhook/*` (2A.4/2A.5) | the provider retries and may cancel the transaction; page the on-call |

Refunds, grants and revokes are in the `audit_logs` table (`payment.refunded`, `subscription.granted`,
`subscription.revoked`, with the actor, IP and reason).

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
| `TTS_VOICE` | optional | `en-US-AriaNeural` (voice of pre-generated audio) |
| `TTS_AUDIO_VERSION` | optional | `1` (bump to re-voice the library) |
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
| `REDIS_QUEUE_RETRY_AFTER` | recommended | `180` (must be above the workers' `--timeout`, 120) |
| `LEARNERS_DEFAULT_TIMEZONE` / `LEARNERS_DEFAULT_REMINDER_TIME` / `LEARNERS_DEFAULT_DAILY_GOAL` | optional | `Asia/Tashkent` / `19:00` / `20` |
| `LEARNING_SESSION_TTL_HOURS` / `LEARNING_GROUP_SESSION_TTL_HOURS` | optional | `12` / `24` |
| `BILLING_CACHE_TTL` | optional | `300` (seconds, entitlement cache) |
| `BILLING_PAYWALL_MODE` | optional | `coming_soon` (default) / `purchase` (see 17) |
| `BILLING_STARS_ENABLED` / `PAYME_ENABLED` / `CLICK_ENABLED` | optional | `false` (a provider is sellable only when enabled; see 17) |
| `PAYME_MERCHANT_ID` / `PAYME_KEY` / `PAYME_TEST_KEY` | when Payme is on | secrets from the Payme cabinet (17.1) |
| `CLICK_MERCHANT_ID` / `CLICK_SERVICE_ID` / `CLICK_MERCHANT_USER_ID` / `CLICK_SECRET_KEY` | when Click is on | from the Click cabinet; the key is a secret (17.1) |
| `PAYME_IP_ALLOWLIST` / `CLICK_IP_ALLOWLIST` | optional | comma-separated IPs/CIDRs; empty = off |
| `BILLING_PRICE_*` / `BILLING_ORDER_TTL_MINUTES` | when selling | prices in minor units / `1440` (17.1) |
| `REMINDERS_DUE_THRESHOLD` | optional | `1` |
| `REMINDERS_MIN_INTERVAL_HOURS` | optional | `2` (floor between two reminders, >= 1; the main rule is one per local day) |
| `REMINDERS_MAX_DELAY_MINUTES` | optional | `120` (>= 15) |
| `REMINDERS_BATCH_SIZE` / `REMINDERS_BACKFILL_LIMIT` | optional | `200` / `10000` |
| `REMINDERS_SEND_RATE_PER_SECOND` | optional | `25` (>= 1; Telegram allows about 30/s per bot) |
| `PRODUCT_EVENTS_RETENTION_DAYS` / `NOTIFICATIONS_LOG_RETENTION_DAYS` | optional | `400` / `400` |
