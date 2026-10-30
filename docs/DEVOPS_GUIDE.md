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
> Step-by-step guide in Russian for a fresh VPS (installing PHP, PostgreSQL, Redis, Nginx from scratch): `docs/VPS_DEPLOY_GUIDE_RU.md`.

## 0. Docker deployment

The production Dockerfile builds three reproducible stages: both Laravel/TWA frontend bundles, the PHP application with `vendor/`, and an nginx image containing the exact same `public/` tree. Production does not mount a persistent volume over `public/`.

Set `APP_DOMAIN`, `CERTBOT_EMAIL`, `APP_KEY` and the other required values in `.env`, then run:

```bash
chmod 600 .env
bash deploy.sh
```

Скрипт принимает SHA коммита: `bash deploy.sh <sha>` (или переменная `DEPLOY_SHA`). Он делает `git fetch origin`, `git checkout --detach <sha>` и **до сборки** проверяет, что `git rev-parse HEAD` равен запрошенному SHA, иначе завершается с кодом 1. CI передаёт `${{ github.sha }}`, поэтому выкатывается ровно тот коммит, который прошёл проверки, а не то, на что `master` указывает к моменту деплоя; job `deploy` идёт с `concurrency: production-deploy` (без отмены уже идущего деплоя). Деплой по SHA разрешён только вперёд (`git merge-base --is-ancestor HEAD <sha>`), назад — только с `--rollback`. Без SHA скрипт делает `git fetch origin && git checkout --detach origin/${DEPLOY_BRANCH:-master}` (не `git pull`, который ломается в detached HEAD после отката) и печатает предупреждение. Образы в registry не публикуются: собираются на сервере из этого SHA.

Дальше: сборка `app` и `nginx`, первичный сертификат Let's Encrypt при необходимости, миграции до переключения контейнеров, затем `php artisan courses:recount-words` (счётчики слов курсов, см. §14.2; не при `--rollback`, сбой только предупреждает), запуск стека и smoke-проверка: `/up`, затем ожидание `/health/ready` (до ~5 минут: нужны пульсы планировщика и воркеров), затем `/twa/index.html` и `/build/manifest.json`. Любой провал = `exit 1` с сообщением и подсказкой про откат. Сертификат обновляет сервис `certbot`; nginx периодически перечитывает сертификаты. Для CI нужен секрет `SSH_PRIVATE_KEY`.

Воркеры очереди в `docker-compose.prod.yml` разделены: `queue-high` (`--queue=high`) и `queue-default` (`--queue=default,low`), `scheduler` отдельно.

### Откат

Откатываем код на предыдущий проверенный SHA (смотрите `git log` или предыдущий успешный запуск Actions):

```bash
cd /var/www/lexiflow
bash deploy.sh --rollback <prev_sha>   # или ROLLBACK=1 bash deploy.sh <prev_sha>
```

Без `--rollback` обычный деплой по SHA идёт только вперёд: если `HEAD` не является предком указанного SHA (например, запуск Actions для старого коммита закончился позже нового), скрипт печатает «SHA ... older than the current HEAD: skipping» и завершается с кодом 0. Флаг `--rollback` пропускает эту проверку. Скрипт заново чекаутит коммит, пересобирает образы, перезапускает стек и прогоняет те же smoke-проверки. Миграции автоматически **не** откатываются, поэтому каждая миграция должна быть обратно совместимой со старым кодом (expand/contract): в релизе N только добавляем (столбцы nullable/с default, новые таблицы, индексы), в релизе N+1 или позже, когда старый код уже не нужен, удаляем/переименовываем. Миграции с `--skip-migrate` для отката не нужны, но и не мешают. Если откат невозможен из-за уже применённой несовместимой миграции — чините вперёд новой миграцией, а не `migrate:rollback` в продакшене.

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
TELEGRAM_BOT_USERNAME=YourBot   # without @; required: group chats get Mini App direct links t.me/<bot>?startapp=...
TELEGRAM_MINI_APP_SHORT_NAME=   # /newapp short name; empty = Main Mini App (BotFather -> Configure Mini App -> https://yourdomain.com/twa/)
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
        # Headers of the static SPA (Laravel SecurityHeaders does not run for files nginx serves itself).
        # CSP: scripts only from self + telegram.org (telegram-web-app.js), API on the same origin,
        # word audio from /storage/audio on the same origin (media-src), no external images/fonts.
        # frame-ancestors is deliberately NOT 'self': Telegram Web (web.telegram.org) embeds the Mini App
        # in an iframe, 'self' would blank it. Native clients use a WebView and ignore frame-ancestors.
        # add_header in a nested location replaces every inherited one, so each block repeats them all.
        gzip_static on;   # serve the *.gz files created by `npm run build` (precompress.mjs); others get on-the-fly gzip

        try_files $uri $uri/ /twa/index.html;

        add_header X-Content-Type-Options "nosniff" always;
        add_header Referrer-Policy "strict-origin-when-cross-origin" always;
        add_header Content-Security-Policy "default-src 'self'; script-src 'self' https://telegram.org; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; media-src 'self' blob: data:; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors https://web.telegram.org https://*.telegram.org" always;
        add_header Strict-Transport-Security "max-age=15552000; includeSubDomains" always;

        # index.html must always be re-fetched to pick up new asset hashes.
        location = /twa/index.html {
            add_header Cache-Control "no-cache, no-store, must-revalidate";
            add_header X-Content-Type-Options "nosniff" always;
            add_header Referrer-Policy "strict-origin-when-cross-origin" always;
            add_header Content-Security-Policy "default-src 'self'; script-src 'self' https://telegram.org; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; media-src 'self' blob: data:; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors https://web.telegram.org https://*.telegram.org" always;
            add_header Strict-Transport-Security "max-age=15552000; includeSubDomains" always;
        }
        # Hashed assets can be cached forever.
        location ~ ^/twa/assets/ {
            add_header Cache-Control "public, max-age=31536000, immutable";
            add_header X-Content-Type-Options "nosniff" always;
            add_header Referrer-Policy "strict-origin-when-cross-origin" always;
            add_header Content-Security-Policy "default-src 'self'; script-src 'self' https://telegram.org; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; media-src 'self' blob: data:; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors https://web.telegram.org https://*.telegram.org" always;
            add_header Strict-Transport-Security "max-age=15552000; includeSubDomains" always;
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
    location ~ ^/(?:admin(?:/|$)|org(?:/|$)|2fa(?:/|$)|livewire-) {
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
| `/admin`, `/org`, `/2fa`, `/livewire-*` | `gzip off` (BREACH: pages carry CSRF tokens and reflect input). The traffic is negligible. |
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
[program:lexiflow-worker-high]
process_name=%(program_name)s_%(process_num)02d
; Use the absolute path to the PHP binary (find yours with `which php`).
; Avoids issues when www-data has a minimal PATH.
command=/usr/bin/php /var/www/lexiflow/artisan queue:work redis --queue=high --tries=3 --timeout=120 --sleep=1 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/lexiflow/storage/logs/worker-high.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=3
user=www-data

[program:lexiflow-worker-default]
process_name=%(program_name)s_%(process_num)02d
command=/usr/bin/php /var/www/lexiflow/artisan queue:work redis --queue=default,low --tries=3 --timeout=120 --sleep=1 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/lexiflow/storage/logs/worker-default.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=3
user=www-data

[group:lexiflow-worker]
programs=lexiflow-worker-high,lexiflow-worker-default
```

Два пула: `high` (ответы бота, платежи) не стоит в очереди за bulk-задачами `default`/`low`. Группа `lexiflow-worker` сохраняет команды `supervisorctl ... lexiflow-worker:*`.

Pre-generated word audio (`PregenerateWordAudioJob`, see below) runs on the `low` queue, served by `lexiflow-worker-default` (`--queue=default,low`; in Docker the `queue-default` service). The job retries 5 times with backoff 30s / 2m / 10m / 30m.

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
- Serving: nginx `location ^~ /storage/audio/` (see `docker/nginx/*.conf`) reads `storage/app/public/audio` directly and denies dotfiles (`.tmp/`). In `docker-compose.prod.yml` the audio lives in its own named volume `app_audio`, mounted at `/var/www/html/storage/app/public/audio` in `app`, `queue-high`, `queue-default` and `scheduler` (read-write) and **only** that volume, read-only, in `nginx`: logs, sessions and other `storage/` contents are not visible to nginx. `php artisan storage:link` is not required for the audio, but the card `audio_url` is built from `APP_URL` + `/storage/...`, so `APP_URL` must be the public https origin.
- Deploying this volume layout onto an existing install (audio already generated into `app_storage`): the first `docker compose -f docker-compose.prod.yml up -d` creates an empty `app_audio`, so existing mp3 files are not served until you copy them over (or just re-run `audio:pregenerate`, which re-creates them; until then learners get the live `/api/twa/tts`). To keep them (the old files are in the `app_storage` volume under `app/public/audio/`, which the new mount shadows, so copy from a throw-away container; if `app_audio` does not exist yet, `docker volume create <project>_app_audio` first):

  ```bash
  docker compose -f docker-compose.prod.yml down            # stop (volumes are kept)
  docker run --rm -v <project>_app_storage:/old:ro -v <project>_app_audio:/new alpine \
      sh -c 'mkdir -p /new && cp -a /old/app/public/audio/. /new/ && chown -R 1000:1000 /new'   # UID:GID = build args UID/GID of the `app` user
  docker compose -f docker-compose.prod.yml up -d
  ```

  (`<project>` is the compose project name, see `docker volume ls`.) The old copy inside `app_storage` can be deleted afterwards (optional). An `app_audio` created by Docker from a fresh image is already owned by `app` (the image creates `storage/app/public/audio`); only a manually created volume needs the `chown`.
- Pre-generation is not triggered automatically (neither by import nor by publishing): run the command after importing or publishing a course.

### 10.2 Audio of the practice dictations (task 18.5)

The phrases of the practice mode "Listen and write" (docs/16 §10) are voiced by `GeneratePracticeAudioJob` (queue `low`, 5 attempts, backoff 30 s / 2 m / 10 m / 30 m) into **`storage/app/practice-audio/{sha1}.mp3`** — the disk `practice_audio` of `config/filesystems.php` (a file is written to `.tmp/` first and moved, so a reader never sees half of an mp3). Unlike `storage/app/public/audio` this directory is **private: nginx does NOT serve it** (no `location`, no `storage:link` entry, the disk has no `url`); the files leave the server only through the JWT-guarded endpoint `GET /api/twa/practice/sessions/{id}/audio/{element}`.

- Permissions: as for the rest of `storage/` (the queue worker writes, php-fpm reads — see `File Permissions Reference`; the directory is created by the application on first use, mode 755, files 644). Backup: it is inside the project directory, so `backup:run` includes it exactly like `storage/app/public`; in Docker it lives in the `app_storage` volume shared by `app`, `queue-*` and `scheduler`, and nginx (which mounts only `app_audio`) cannot see it — nothing to add to the compose file.
- A version whose voicing failed on the last attempt is `failed` with a short `audio_error` (`tts_service_failed`, `tts_service_not_configured`, ...) and waits for the admin action to voice it again; the same text, voice and `TTS_AUDIO_VERSION` give the same file name, so a repeated job does nothing. The `tts` service must be reachable from the queue worker, as for the word audio (§10.1).
- Lost files (a restore without this directory): the hub still counts the phrases (it does not look at the disk), a dictation session skips a phrase whose file is missing, and when fewer than three clips can play it is refused with "the recordings are being prepared" (422 `practice_not_enough_material`, reason `audio_pending`); voice the phrases again with the admin action.

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
- `reminders:dispatch` — every 15 minutes (personal reminders by the learner's local time; a learner is claimed by a conditional UPDATE, so overlapping runs are safe; `withoutOverlapping(10)` — the mutex expires after 10 minutes — and `runInBackground`). Its jobs go to the `default` queue, spread at `REMINDERS_SEND_RATE_PER_SECOND` (25/s) over at most 14 minutes. A run queues at most rate x 840 jobs (`capped` -> a warning line; the rest stays due for the next run). Every background Telegram send also takes a slot of `TELEGRAM_OUTBOUND_GLOBAL_PER_SECOND` (queued jobs release themselves when none is free; `assignments:refresh` waits up to 2 s per post and postpones the rest to the next run).
- `model:prune` — daily at 03:00 (`product_events` and `notifications_log`, 400 days by default).
- `training:close-expired` — every 15 minutes (expired personal / stale group training sessions).
- `practice:close-expired` — every 15 minutes (solo practice sessions past their `expires_at`, 24 h after the start, become `finished / expired` and release the learner's «one active session»; the event `practice.session_finished` is written once; a conditional UPDATE per session, so repeated or parallel runs are harmless; `withoutOverlapping`). A session that nobody closed is also closed by the learner's next request, so a late run costs nothing. `practice:battles-sweep` (every minute: expired invitations, matches that are on, `PRACTICE_ACCESS=off`) arrives with the server of the battle (task 18.6) and is described with it (18.7c).
- `subscriptions:expire` — hourly (time-based subscription transitions).
- `billing:expire-orders` — every 15 minutes (pending payment orders past `BILLING_ORDER_TTL_MINUTES` become `expired`).
- `subscriptions:remind-expiring` — daily at 06:00 UTC (renewal reminders 3 and 1 day before a Payme/Click period or a canceled Stars period ends; queues `RenewalReminderJob`, so a queue worker must run; organization owners are e-mailed — the `MAIL_*` settings must be real in production, `MAIL_MAILER=log` only logs them).
- `challenges:close-due` — every 15 minutes (friend challenges past `ends_at` are scored from `learner_daily_stats` and finished, unanswered pending ones expire; idempotent by a conditional UPDATE; `withoutOverlapping`).
- `organizations:sync-members` — hourly (repair path of the group -> organization membership hook: a learner in a group of an organization without a membership row gets one; idempotent).
- `assignments:refresh` — every 30 minutes (assignment snapshots and the group reminder 24 h before a deadline; one-time posts are claimed by conditional UPDATEs; `withoutOverlapping(20)` + `runInBackground`; posts go through the global Telegram send budget, so a queue worker must run).
- `leagues:close-week` — daily at 00:00 in `LEAGUES_TIMEZONE` (default `Asia/Tashkent`) with a safety run at 00:05 on the same mutex (`cron 0,5 0 * * *`; on Monday it closes the week that just ended: final standings group by group, promotions, result DMs in `SendLeagueResultsJob` batches through the global send budget — a queue worker must run; the 00:05 run and the other days are a no-op or catch up a failed / missed run; idempotent and resumable by `league_weeks.status` + `league_groups.closed_at`; `withoutOverlapping(30)` + `runInBackground`). The old one-member job `SendLeagueResultJob` is kept as a thin deprecated shim for ONE release: a payload of that class that is still queued while a release rolls out (the Monday mailing in flight) runs as before — one member through a slot of the send budget, at most one DM per member and week by the same `notifications_log` marker the batches use, so it never doubles a DM; nothing dispatches it any more. Remove `app/Jobs/SendLeagueResultJob.php` (and its test in `LeagueResultSenderTest`) in the release after the first one that ships the shim — an old payload dies by its own `retryUntil` (at most `LEAGUES_NOTIFY_TTL_HOURS`, 12 h) after it was queued. So there is no need to wait for the mailing queue to drain before a deploy.
- `referrals:recover` — every 30 minutes (re-queues `RewardReferralJob` for referrals left `activated` or half-rewarded after a lost job; looks only at rows younger than `REFERRALS_RECOVER_WINDOW_DAYS`; `withoutOverlapping(20)` + `runInBackground`).
- `billing:reconcile-stars` — daily at 04:30 (Telegram's Stars ledger of the last 2 days vs `payments`; read-only, logs `billing.reconcile_missing*`; exits at once while Stars is disabled).
- `backup:clean` — daily at 01:00.
- `backup:run` — daily at 01:30.
- `backup:monitor` — daily at 02:00.

---

## 12. Register the Telegram Webhook

```bash
cd /var/www/lexiflow
php artisan telegram:set-webhook
```

The command registers `allowed_updates = message, my_chat_member, chat_member, callback_query, pre_checkout_query`
(`IrazasyedTelegramClient::ALLOWED_UPDATES`). Re-run it after any deploy that adds an update type — task 2A.3
(Telegram Stars) added `pre_checkout_query`; without it Stars invoices cannot be paid.

To verify:
```bash
curl "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/getWebhookInfo"
```

Then set the bot menu: the command list behind "/" (Russian and Uzbek, private chats and groups) and the permanent
"LexiFlow" button that opens the Mini App:

```bash
php artisan telegram:setup-bot
```

The button points to `${TWA_BASE_URL}/twa/`. Telegram accepts only https URLs, so with any other `TWA_BASE_URL` the command
warns, skips the button and still sets the command lists. A Bot API error is printed and the exit code is non-zero. The command
is idempotent: re-run it after a deploy that changes the bot's commands or their descriptions (`lang/{ru,uz}/commands.php`).

---

## 13. Smoke Test

Run these checks after every deployment:

```bash
# 1. Laravel health check + dependency readiness (DB, Redis, scheduler, queue workers)
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
git checkout --detach origin/master   # не git pull: после отката HEAD detached

# Install new dependencies (if composer.json changed)
composer install --no-dev --optimize-autoloader --no-interaction

# Run new migrations
php artisan migrate --force

# Bring courses.words_count (the `total` of GET /courses) in line with the active words: idempotent and fast (§14.2)
php artisan courses:recount-words

# Rebuild frontend (if resources/twa/ changed)
# Use plain `npm ci` — vite/vue-tsc are devDependencies needed for the build.
cd resources/twa && npm ci && npm run build && cd ../..

# Re-cache
php artisan livewire:publish --assets --no-interaction
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Restart queue workers to pick up new code (both pools: lexiflow-worker-high / -default)
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

### 14.2 `courses:recount-words` — the word counters of the courses

`courses.words_count` is the number of ACTIVE words of a course and the `total` of the progress in `GET /courses` (the
catalog no longer counts the words of all courses on each open, docs/03 §6). Every path that changes words keeps it up
to date (import, the decks of learners, a word switched off in the admin panel); the command is the repair for a figure
that drifted anyway — a restored dump, hand-made SQL, a release that changes the rule — and `deploy.sh` runs it after the
migrations (not on `--rollback`: the older code has no such command; a failure only prints a warning with the command).

```bash
php artisan courses:recount-words
# course #5 (ielts-core): 480 -> 478
# Courses checked: 14, changed: 1; lesson caches dropped: 312
```

- **Every course**: platform, centers', and the decks of learners (they are courses), archived ones too. For each it counts the
  active words (`CoursePublisher::countActiveWords`); only a course whose figure differs is rewritten through
  `CoursePublisher::recount()` (so `updated_at` of the courses does not move on every deploy and the cached catalog is dropped
  only when something changed). The output lists up to 20 corrected courses (`from -> to`) and the totals.
- **Lesson caches**: the cached active-word count of every lesson (`lesson:{id}:word_count`) is dropped, so the next step of a
  lesson session counts afresh.
- **Idempotent**: the second run reports `changed: 0` and writes nothing. It is not in the scheduler; run it after a deploy that
  needs it, after restoring a database, or by hand when a catalog total looks wrong.
- **Safe on a live system**: a course that needs a correction is recounted in a short transaction that takes the course row first
  (the same lock the importer, the decks and the publication take), so a run that meets an import waits for its commit instead of
  overwriting the counter with a count that misses the import.

### 14.3 Deploying the practice (docs/16, tasks 18.x)

The practice ships **switched off**: `PRACTICE_ACCESS` defaults to `off`, so a deploy changes nothing for learners until you turn it on.

1. `php artisan migrate --force` runs the four forward migrations `2026_10_30_100001..100004` (`learners.practice_pilot_at`; `practice_items`, `practice_item_versions`, `practice_item_words`; `practice_sessions`, `practice_attempts`; `battles`, `battle_participants`, `battle_answers`) — new tables and one nullable column, no rewrite of existing data.
2. `php artisan config:cache`, then restart the queue workers (`supervisorctl restart lexiflow-worker:*`): the voicing job `GeneratePracticeAudioJob` runs on the `low` queue (the `default,low` worker, §10) and needs the new code.
3. The directory `storage/app/practice-audio` is created by the application on first use (private, §10.2); on a bare VPS it is in the `mkdir -p` list of the VPS guide §7.1. It is part of the backup (`backup:run`) next to `storage/app/public`.
4. `php artisan schedule:list` shows `practice:close-expired` (every 15 minutes). `/health/ready` needs no change.
5. Turn it on in steps (`docs/ADMIN_GUIDE.md`, «Practice tasks»): import the pilot package as drafts (`php artisan practice:import database/data/practice/pilot-a1-a2.json`), check and publish the tasks in the admin, wait until the column «Аудио» of the dictations says «Готово» (the `tts` service and a queue worker must be up), then `PRACTICE_ACCESS=pilot` + `php artisan config:cache`, put a few learners into the pilot, and later `all`.
6. Rollback: `PRACTICE_ACCESS=off` + `config:cache` hides the practice at once (the entries disappear from `/me`, the endpoints answer 403); the data stays. The migrations are reversible (`down()`), but do not roll them back on a live system.

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
| `queue` | backlog of the `high` (`TELEGRAM_QUEUE`) or `default` queue exceeds `READINESS_QUEUE_MAX_SIZE` (1000), **or** the worker heartbeat of `high` / `low` is missing or older than 5 minutes (`READINESS_QUEUE_HEARTBEAT_MAX_AGE`, seconds; not checked with the `sync`/`null` driver) |

Response: `200 {"status":"ok","checks":{"db":"ok",...}}` or `503 {"status":"fail","checks":{"db":"fail",...}}`.
Only `ok`/`fail` is returned; error details, hosts and versions are written to the application log
(`readiness.check_failed`), never to the response. The route is throttled (60 req/min per IP) and stateless.

The scheduler heartbeat is written by a scheduled callback (`ops:scheduler-heartbeat`, every minute, see
`routes/console.php`) into the cache. After the very first deploy the check turns green within a minute.

Worker heartbeat: every minute the scheduler dispatches `QueueHeartbeatJob` to the `high` and `low` queues (`operations.readiness.heartbeat_queues`; unique per queue, so a dead worker does not accumulate jobs). A worker that really consumes the queue writes `queue:heartbeat:{queue}` to the cache; a stale value means workers are stopped or the queue is stuck behind a backlog. The age of the oldest ready job is deliberately not measured: the heartbeat job sits at the tail of the queue, so a long backlog already shows up as a stale heartbeat, and reading Redis payloads would add driver-specific code.

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
| Numbers on the landing | not typed in the texts: `App\Domain\Site\Services\LandingPricing` reads them from config — the Pro price (`billing.paywall.mode`, `billing.prices`, enabled providers), Free's new words per day (`BILLING_FREE_NEW_WORDS_PER_DAY`; a config value of `null` = no daily limit, the page then says so) and the deck limits of both plans (`config/decks.php`). A changed value shows up within the 5 minutes of `Cache-Control` |
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

> **Status.** The ledger, checkout API, admin screens, the `billing:expire-orders` job (2A.1, 2A.2, 2A.7), the
> **Telegram Stars adapter** (2A.3: invoices, `pre_checkout_query`, `successful_payment` / `refunded_payment`,
> cancel / resume, admin refund, `billing:reconcile-stars`) and the **Payme adapter** (2A.4: checkout link, the
> JSON-RPC webhook with all six Merchant API methods, the `/billing/return` page) and the **Click adapter** (2A.5:
> checkout link, the Prepare / Complete webhooks, manual refund record) exist. Every provider stays off until its
> `*_ENABLED=true`.

### 17.1 Environment

| Variable | Notes |
|---|---|
| `BILLING_PAYWALL_MODE` | `coming_soon` → `purchase` once at least one provider is enabled **and** prices are set. With an empty catalog the API silently falls back to `coming_soon` |
| `BILLING_STARS_ENABLED` | `true` makes the Stars adapter sellable. No separate key or URL: Stars use the bot token and the bot webhook |
| `BILLING_STARS_MAX_SUBSCRIPTION_PRICE` | Telegram's cap for a Stars *subscription* price (10000); a month above it is not sold |
| `BILLING_STARS_SUBSCRIBE_WINDOW_DAYS` | A Stars *subscription* (month) is sold on top of a prepaid period only when that period ends within this many days (7); Payme / Click and the Stars year are not limited |
| `PAYME_ENABLED`, `PAYME_MERCHANT_ID` | merchant id from the Payme business cabinet |
| `PAYME_KEY` | **production** merchant key (secret) |
| `PAYME_TEST_KEY` | key of the **test** cabinet (secret); the webhook accepts both, so sandbox and prod differ by key. Leave empty in production once testing is done |
| `PAYME_ALLOW_TEST_KEY` | default `false`: with `APP_ENV=production` the test key is REFUSED (`-32504`) even if `PAYME_TEST_KEY` is set. Set `true` only for go-live checks on the production host, then switch it off |
| `PAYME_CHECKOUT_URL` | `https://checkout.paycom.uz` (sandbox: `https://checkout.test.paycom.uz`) |
| `PAYME_IP_ALLOWLIST` | comma-separated IPs/CIDRs of Payme's callers from the cabinet; empty = off (a caller outside the list gets 403 before authentication) |
| `PAYME_CANCEL_AFTER_PERFORM_DAYS` | days after a payment during which Payme may still cancel (= refund) it through `CancelTransaction`; empty = always. Beyond the window the webhook answers `-31007` and the refund has to be agreed with Payme support |
| `PAYME_FISCAL_IKPU`, `PAYME_FISCAL_PACKAGE_CODE`, `PAYME_FISCAL_VAT_PERCENT` | fiscal receipt data (the owner / accountant provides the IKPU code) |
| `CLICK_ENABLED`, `CLICK_MERCHANT_ID`, `CLICK_SERVICE_ID` | from the Click merchant cabinet (**Settings → Services**); `CLICK_SERVICE_ID` is also part of the webhook signature, so it must be the id of the service whose secret is in `CLICK_SECRET_KEY` |
| `CLICK_MERCHANT_USER_ID` | optional; when set it is appended to the checkout link as `merchant_user_id` |
| `CLICK_SECRET_KEY` | secret key of the service (secret); the only key the Prepare / Complete signature uses |
| `CLICK_CHECKOUT_URL` | `https://my.click.uz/services/pay` (the test flow uses the same page with the test service) |
| `CLICK_IP_ALLOWLIST` | as for Payme; the Click server addresses are published in the Click cabinet / docs |
| `CLICK_TRANSACTION_TIMEOUT_MS` | 1800000 (30 min). An open Click *Prepare* older than this is treated as dead: it no longer keeps an expired order payable or holds a learner's discount. Prepare → Complete normally takes seconds; raise it only if Click support confirms longer retries. Payme's equivalent is fixed at 12 h (Payme cancels its own transaction after that) |
| `BILLING_PRICE_*` | prices in minor units (UZS in tiyin = sum x 100; XTR whole Stars); `0` = not for sale in that currency (see `.env.example`) |
| `BILLING_ORG_YEAR_DISCOUNT_PERCENT` | discount of the centers' annual payment vs 12 months (default `17`; `0` = no discount); explicit `BILLING_PRICE_<TIER>_YEAR_UZS` overrides it |
| `BILLING_ORDER_TTL_MINUTES` | 1440; Payme / Click refuse an expired order |
| `BILLING_SUPPORT_CONTACT` | shown in the paywall and by `/paysupport` |
| `BILLING_INVOICE_REQUEST_EMAIL` | where the cabinet's "Request an invoice" e-mail goes (empty = `SITE_LEGAL_EMAIL`; both empty = the button is hidden) |
| `SITE_LEGAL_NAME`, `_INN`, `_ADDRESS`, `_BANK`, `_BANK_ACCOUNT`, `_MFO`, `_PHONE`, `_EMAIL` | seller requisites printed on a center's invoice (`config/site.php`, from the owner / accountant); empty fields are not printed |

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
| Telegram Stars | the existing `/telegram/webhook/{secret}` — **no new URL and nothing to register in a cabinet**; `allowed_updates` must include `pre_checkout_query`, which `php artisan telegram:set-webhook` now sets — re-run it once after deploying 2A.3 (check with `getWebhookInfo`) | done (2A.3) | `TELEGRAM_URL_SECRET` + `X-Telegram-Bot-Api-Secret-Token`; the `pre_checkout_query` is answered synchronously in that request |
| Payme | `https://<domain>/billing/webhook/payme` (one URL for all JSON-RPC methods: CheckPerformTransaction, CreateTransaction, PerformTransaction, CancelTransaction, CheckTransaction, GetStatement). In the Payme business cabinet: **Настройки кассы → Endpoint** = this URL, login `Paycom`, password = the cabinet's key (`PAYME_KEY`, test cabinet: `PAYME_TEST_KEY`). The return page `https://<domain>/billing/return` needs no registration (it is passed inside the checkout link) | done (2A.4) | `Authorization: Basic base64("Paycom:" + key)`, `hash_equals` with `PAYME_KEY` / `PAYME_TEST_KEY`; wrong key → HTTP 200 + `-32504` |
| Click Prepare | `https://<domain>/billing/webhook/click/prepare` — in the Click merchant cabinet, service settings: **Prepare URL** | done (2A.5) | form-encoded; `sign_string = md5(click_trans_id + service_id + CLICK_SECRET_KEY + merchant_trans_id + amount + action + sign_time)`, `hash_equals`; wrong → HTTP 200 + `"error":-1` |
| Click Complete | `https://<domain>/billing/webhook/click/complete` — **Complete URL** of the same service | done (2A.5) | same, with `merchant_prepare_id` after `merchant_trans_id` |

Click (like Payme) answers errors with HTTP 200 and a negative `error` in the body; the return page is passed inside
the checkout link (`return_url`) and needs no registration.

Payme answers errors with HTTP 200 and a JSON-RPC error (`-32504` for a wrong key) — a 200 in the access log is not a
sign of success; read the body or the payment screens. If the provider publishes caller IPs, put them in
`*_IP_ALLOWLIST` (behind a reverse proxy, CDN or load balancer make sure the real client IP reaches Laravel: configure trusted proxies in `bootstrap/app.php` — otherwise the allowlist and every per-IP throttle (`billing-webhook`, `twa-*`) see the proxy address and break).

### 17.3 Sandbox first

1. **Payme.** Create a test merchant in the Payme test cabinet (test.paycom.uz); put its id in `PAYME_MERCHANT_ID` and
   the test key in `PAYME_TEST_KEY`, set `PAYME_CHECKOUT_URL=https://checkout.test.paycom.uz`, `PAYME_ENABLED=true`,
   register the webhook URL (17.2) for the test cabinet, then run the cabinet's **Merchant API test** scenarios:
   CheckPerformTransaction (right / wrong amount, unknown order), CreateTransaction (new, repeated, second id on a
   busy order, expired `time`), PerformTransaction (once, twice, after timeout), CancelTransaction (before / after
   perform), CheckTransaction, GetStatement. Expected answers are listed in docs/04 D.1; the points where our reading
   of the protocol must be confirmed are in docs/13 §15 p. 2 (`-31052` for a busy order, the checkout-link format,
   the `detail` receipt shape). Then make one real test payment from `/pro` → Payme page → `/billing/return` shows
   "Оплата получена", **Payments** has a `succeeded` row, the subscription is `active`; cancel it from the test
   cabinet and check the `refund` row and the `expired` subscription. Only then switch to the production merchant id,
   `PAYME_KEY` and `https://checkout.paycom.uz`, and clear `PAYME_TEST_KEY`.
2. **Click.** In the Click merchant cabinet create / use the **test service** (its `service_id`, `merchant_id` and
   secret go to `CLICK_SERVICE_ID`, `CLICK_MERCHANT_ID`, `CLICK_SECRET_KEY`), register the Prepare / Complete URLs
   (17.2), set `CLICK_ENABLED=true`. Run the cabinet's test calls if it offers them, then a real test payment from
   `/pro` → Click page → `/billing/return` shows "Оплата получена", **Payments** has a `succeeded` row
   (`provider_event_id = <click_trans_id>:complete`), **Provider transactions** shows the row in state 2, the
   subscription is `active`. Then a **failed** payment (wrong OTP / cancel on the Click page): the transaction must
   end in state −1 while the order stays `pending` until its TTL (Click sends Complete with `error < 0`; another prepared transaction of the same order may still succeed — docs/13 «Реализация 2A.5», review 2A.8 B). Compare what you see with
   docs/13 §15 p. 3 (Complete signature with `merchant_prepare_id`, repeated Complete → `0`, `error < 0` behaviour,
   `amount` format) — each of those is a one-line switch in the code if Click's sandbox disagrees. Refunds: Click has
   no refund webhook — return the money in the Click cabinet and record it in **Payments → Refund** (ADMIN_GUIDE).
   Only then switch to the production service id / secret.
3. **Stars.** Run the whole flow with a real account on a **small price** (e.g. `BILLING_PRICE_PRO_MONTH_XTR=1` on a
   staging bot): `/pro` → invoice (subscription, 30 days) and a yearly one-off invoice, `pre_checkout_query` answered
   (try a wrong account: must be refused with a text), `successful_payment` → the bot replies "Pro until …" and
   **Payments** shows a `succeeded` row; cancel / resume the auto-renewal from `/pro`; a refund from the admin panel
   (**Payments → Refund**) and the `refunded_payment` echo; `php artisan billing:reconcile-stars` exits 0. Before
   enabling in production compare the raw `successful_payment` / `refunded_payment` payloads with
   docs/13 §15 (field names `subscription_expiration_date`, `is_recurring`, `is_first_recurring`): if Telegram's
   names differ, only `TelegramStarsProvider::parseSuccessfulPayment` / `parseRefundedPayment` change.
4. Production switch: real keys, real prices, `*_ENABLED=true`, `BILLING_PAYWALL_MODE=purchase`, `config:cache`,
   restart workers. Keep one provider enabled at first.

Full acceptance checklist of Phase 2 on staging and in the provider sandboxes (env, scheduler, Stars / Payme / Click, centers, restore / ZAP / k6 / Lighthouse): `docs/STAGING_PHASE2_CHECKLIST.md`.

### 17.4 After every deploy of billing code

1. `php artisan migrate --force` (new billing tables / columns), `config:cache`, `supervisorctl restart lexiflow-worker:*`.
2. `php artisan schedule:list` shows `billing:expire-orders` (every 15 min), `subscriptions:expire` (hourly),
   `billing:reconcile-stars` (daily 04:30 UTC) and `subscriptions:remind-expiring` (daily 06:00 UTC); the scheduler heartbeat on `/health/ready` is green.
   `php artisan telegram:set-webhook` was re-run once after 2A.3 (`allowed_updates` includes `pre_checkout_query`).
3. Admin panel (admin account): **Billing → Payments / Payment orders / Provider transactions** open; the two
   dashboard widgets ("Revenue, last 30 days", "Payment webhook errors, last 24 h") render. A **teacher** account must
   get 403 on `/admin/payments` and must not see the Billing group.
4. The checkout catalog (`GET /api/twa/billing/catalog` with a valid TWA token): `items` and `providers` list exactly the
   providers you enabled and nothing else.
5. Provider webhooks (Payme, Click): from the provider's cabinet send a test call; a wrong key /
   signature must be rejected (Payme body `-32504`, Click `-1`), a right one answered; make one sandbox payment end
   to end and see the `succeeded` row in **Payments**, the order `paid` and the subscription in **Subscriptions**.
   `curl -s -X POST https://<domain>/billing/webhook/payme -d '{}'` must return HTTP 200 with `"code":-32504`
   (no key) — a 404/419/5xx means the route, CSRF exclusion or nginx is wrong. Likewise
   `curl -s -X POST https://<domain>/billing/webhook/click/prepare -d 'action=0'` must return HTTP 200 with `"error":-8`
   (missing parameters) and a fully formed but unsigned form `"error":-1`.
6. After the first payments, look for error lines in `storage/logs/laravel.log` (`grep 'billing\.' storage/logs/laravel.log`):
   no `billing.amount_mismatch`, `billing.payment_orphan` or `billing.admin_refund_unrecorded` (see 17.5).
7. Check that no secret ended up in the logs: `grep -E "PAYME_|CLICK_SECRET|sign_string|Authorization: Basic" storage/logs/laravel.log` prints nothing.

### 17.5 What to monitor

| Signal | Meaning / action |
|---|---|
| Dashboard "Payment webhook errors, last 24 h" > 0 | a `failed` ledger row: money arrived but was not credited (wrong amount / currency, dead or paid order, orphan Stars renewal). Open **Payments → Status: failed**, decide: grant Pro by hand or refund |
| `billing.amount_mismatch` (error log) | a provider sent an amount different from the order: check `BILLING_PRICE_*` changes between checkout and payment; repeated = possible tampering |
| `billing.payment_orphan` (error log) | Stars payment whose order is gone (or from an unknown Telegram user); the learner paid without getting Pro until support acts: **Payments → Status: failed**, grant Pro by hand or refund |
| `billing.reconcile_missing` / `billing.reconcile_missing_refund` (error log, `billing:reconcile-stars` exit 1) | Telegram has a Stars transaction (payment / refund) that the ledger does not: a lost webhook update. Compare with **Payments**, then grant / record by hand |
| `billing.stars.pre_checkout_refused` (warning log) | an invoice was refused at pre-checkout (`reason`: foreign payer, expired / paid order, wrong amount, Stars disabled). A burst of `foreign` = someone passing invoice links around |
| `billing.stars.pre_checkout_failed` / `pre_checkout_unanswered` (error / warning) | the synchronous pre-checkout could not read the order (database) or Telegram did not take the answer; the payer sees "try again" |
| `billing.admin_refund_unrecorded` (error log) | an admin refund returned the money at the provider but the ledger write failed; fix the cause, then record it with "returned by hand" |
| `billing.admin_refund_provider_failed` (error log) | the provider refused / was down for an admin refund; nothing was changed |
| Pending orders keep growing | `billing:expire-orders` is not running: the scheduler is down (see 11) |
| 5xx on `/billing/webhook/*` | the provider retries and may cancel the transaction; page the on-call. Payme gets HTTP 500 + `-32400`, Click HTTP 500 + `-7`, only on an infrastructure failure (`billing.payme.rpc_failed` / `billing.click.webhook_failed` in the log) |
| `billing.payme.auth_failed` (warning log) | a call with a missing / wrong Basic key: after a key rotation check `PAYME_KEY` / `PAYME_TEST_KEY`; a burst from unknown IPs = someone probing the webhook (consider `PAYME_IP_ALLOWLIST`) |
| `billing.payme.perform_refused` (warning log) | Payme asked to perform a transaction whose order is no longer payable (closed by an operator / expired): the transaction stays open until Payme cancels it; nothing is credited |
| `billing.payme.refund_unrecorded` (error log) | Payme canceled a performed transaction but the ledger had no charge to refund (or it was already refunded by hand): the state is −2, compare **Payments** with the Payme cabinet |
| `billing.click.sign_failed` (warning log) | a Prepare / Complete with a wrong `sign_string` or a foreign `service_id`: after a key rotation check `CLICK_SECRET_KEY` / `CLICK_SERVICE_ID`; a burst from unknown IPs = someone probing the webhook (consider `CLICK_IP_ALLOWLIST`) |
| `billing.click.complete_refused` (warning log) | Click completed a payment whose order was no longer payable (expired / closed, or already paid by another Click transaction): a `failed` ledger row exists, Click got `-9` / `-4` and should reverse the charge; if the payer was charged, refund in the Click cabinet or grant Pro by hand |
| `billing.click.cancel_after_complete` (warning log) | Click reported `error < 0` for a transaction we had already credited: nothing was undone; compare with the Click cabinet, refund by hand if Click really reversed it |
| `billing.click.webhook_failed` (error log) | an infrastructure failure inside a Click call (HTTP 500 + `-7`, Click retries) |

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
| `READINESS_QUEUE_HEARTBEAT_MAX_AGE` | optional | `300` |
| `REDIS_QUEUE_RETRY_AFTER` | recommended | `180` (must be above the workers' `--timeout`, 120) |
| `LEARNERS_DEFAULT_TIMEZONE` / `LEARNERS_DEFAULT_REMINDER_TIME` / `LEARNERS_DEFAULT_DAILY_GOAL` | optional | `Asia/Tashkent` / `19:00` / `20` |
| `LEARNING_SESSION_TTL_HOURS` / `LEARNING_GROUP_SESSION_TTL_HOURS` | optional | `12` / `24` |
| `BILLING_CACHE_TTL` | optional | `300` (seconds, entitlement cache) |
| `BILLING_PAYWALL_MODE` | optional | `coming_soon` (default) / `purchase` (see 17) |
| `BILLING_STARS_ENABLED` / `PAYME_ENABLED` / `CLICK_ENABLED` | optional | `false` (a provider is sellable only when enabled; see 17) |
| `PAYME_MERCHANT_ID` / `PAYME_KEY` / `PAYME_TEST_KEY` | when Payme is on | secrets from the Payme cabinet (17.1) |
| `CLICK_MERCHANT_ID` / `CLICK_SERVICE_ID` / `CLICK_MERCHANT_USER_ID` / `CLICK_SECRET_KEY` | when Click is on | from the Click cabinet; the key is a secret (17.1) |
| `PAYME_IP_ALLOWLIST` / `CLICK_IP_ALLOWLIST` | optional | comma-separated IPs/CIDRs; empty = off |
| `CLICK_TRANSACTION_TIMEOUT_MS` | optional | `1800000` (30 min): an older open Click Prepare no longer holds an order / offer (17.1) |
| `BILLING_PRICE_*` / `BILLING_ORDER_TTL_MINUTES` | when selling | prices in minor units / `1440` (17.1) |
| `BILLING_ORG_YEAR_DISCOUNT_PERCENT` | optional | `17`; center **year** price = tariff month price x 12 x (1 - N %), rounded to whole sums. `BILLING_PRICE_{TUTOR,CENTER_S,CENTER_M}_YEAR_UZS` (tiyin) set an explicit year price instead (wins over the discount) |
| `REMINDERS_DUE_THRESHOLD` | optional | `1` |
| `REMINDERS_MIN_INTERVAL_HOURS` | optional | `2` (floor between two reminders, >= 1; the main rule is one per local day) |
| `REMINDERS_MAX_DELAY_MINUTES` | optional | `120` (>= 15) |
| `REMINDERS_BATCH_SIZE` / `REMINDERS_BACKFILL_LIMIT` | optional | `200` / `10000` |
| `REMINDERS_SEND_RATE_PER_SECOND` | optional | `25` (>= 1; Telegram allows about 30/s per bot) |
| `TELEGRAM_OUTBOUND_GLOBAL_PER_SECOND` | optional | `25` (bot-wide ceiling of background sends per second — reminders, assignment posts, renewal reminders, DMs of centers/offers, leaderboards; Bot API allows ~30/s per bot, the rest is for replies to updates; `0` = off). A job without a free slot is put back for 1-5 s; keep the Redis cache (the counter `telegram:outbound:global:{sec}` is shared by all workers) |
| `PRODUCT_EVENTS_RETENTION_DAYS` / `NOTIFICATIONS_LOG_RETENTION_DAYS` | optional | `400` / `400` |

### Phase 2 variables

All optional; the defaults are the production values (`.env.example` lists them). Changing a value needs `php artisan config:cache` + a worker restart (section 14).

| Variable | Default | Meaning |
|---|---|---|
| `ACHIEVEMENTS_ENABLED` | `true` | Achievements (badges, share cards). `false` = nothing new is awarded |
| `ORGANIZATIONS_INVITE_BURST_PER_DAY` | `50` | Joins through ONE organization invite code within 24 h above which a warning is logged (logged, not blocked) |
| `BILLING_POST_ORG_DISCOUNT_PCT` | `30` | Personal discount (%, clamped to 1-90) on the first Pro month offered to a learner who lost a seat in an organization; valid 14 days |
| `BILLING_ORG_YEAR_DISCOUNT_PERCENT` | `17` | Discount of a center's annual payment vs 12 months (`0` = none); an explicit `BILLING_PRICE_<TIER>_YEAR_UZS` wins |
| `FSRS_ROLLOUT_PERCENT` | `0` | Share (0-100) of NEW learners put into the FSRS bucket of the A/B at registration (`crc32(telegram_user_id) % 100 < N`, stored in `learners.scheduler`); `0` = everyone on SM-2. A later change never moves already registered learners |
| `FSRS_DESIRED_RETENTION` | `0.9` | FSRS target retention, strictly between 0 and 1 (higher = shorter intervals) |
| `FSRS_WEIGHTS` | built-in 19 weights | Comma-separated FSRS parameters; change only with fitted values |
| `LEAGUES_ENABLED` | `true` | Weekly leagues on/off |
| `LEAGUES_TIMEZONE` | `Asia/Tashkent` | Time zone of the week boundary (Monday 00:00 closing job + 00:05 safety run, `leagues:close-week`) |
| `LEAGUES_GROUP_CAPACITY` | `30` | Learners per league group; capped at 500 in `config/leagues.php` (the closing UPDATE of a group binds 4 parameters per member and PostgreSQL allows 65 535) |
| `LEAGUES_PROMOTE` / `LEAGUES_DEMOTE` | `5` / `5` | Top / bottom N of a group moving up / down a tier at the weekly close |
| `LEAGUES_POINTS_REVIEW` / `_CORRECT` / `_NEW_WORD` / `_GOAL_MET` | `1` / `1` / `2` / `10` | Points per review / correct answer / new word / daily goal met (computed from `learner_daily_stats`) |
| `LEAGUES_STANDINGS_CACHE_SECONDS` | `60` | Cache of the standings table |
| `LEAGUES_NOTIFY_TTL_HOURS` | `12` | A result DM that could not be sent within this time after the week was closed is dropped. One deadline for the whole mailing: a batch remainder handed to a new job keeps it |
| `LEAGUES_NOTIFY_BATCH_SIZE` | `100` | Members per result-DM batch job (`SendLeagueResultsJob`) |
| `LEAGUES_NOTIFY_BATCH_SECONDS` | `30` | Seconds one batch job may spend sending before handing the rest to a fresh job (keep well below the worker `--timeout`) |
| `LEARNERS_STREAK_FREEZE_MAX` | `2` | Streak freezes a Pro learner can hold (+1 per week) |
| `MOCK_EXAM_QUESTIONS` / `MOCK_EXAM_SECONDS` | `40` / `1200` | Questions and time limit of the IELTS/SAT vocabulary simulator |
| `MOCK_EXAM_PERCENTILE_DAYS` / `MOCK_EXAM_PERCENTILE_CACHE_TTL` | `90` / `3600` | Window (days) of other results for the percentile / its cache (seconds) |
| `REFERRALS_REWARD_DAYS` | `7` | Pro days each side gets |
| `REFERRALS_ACTIVATION_DAYS` / `_REVIEWS_PER_DAY` / `_WINDOW_DAYS` | `3` / `10` / `14` | Activation: this many days with at least N reviews within the window |
| `REFERRALS_MAX_REWARDS_PER_30D` / `REFERRALS_MAX_REWARDS_LIFETIME` | `10` / `50` | Rewards one inviter can collect per rolling 30 days / in total (beyond: the invited learner is still rewarded) |
| `REFERRALS_INVITEE_REWARDS_PER_30D` | `20` | Invited learners of one inviter that get a reward per rolling 30 days |
| `REFERRALS_FLAG_LINKS_PER_DAY` | `20` | Links of one inviter within 24 h at which the next one is flagged (reward waits for an admin) |
| `REFERRALS_RECOVER_WINDOW_DAYS` | `14` | `referrals:recover` looks only at referrals activated / rewarded within this many days |

### Practice variables (docs/16 §3.1)

The switches are read from `.env`; everything else of `config/practice.php` (sizes, limits, timings) is changed by a release, not by an environment variable. Changing a value needs `php artisan config:cache` + a worker restart (section 14).

| Variable | Default | Meaning |
|---|---|---|
| `PRACTICE_ACCESS` | `off` | Who may practise: `off` (nobody), `pilot` (learners with `learners.practice_pilot_at`, set by an admin), `all`. Any other value counts as `off` |
| `PRACTICE_PLAN` | `all` | `pro` — only Pro learners practise; a Free learner gets the paywall (403 `practice_pro_required`) |
| `PRACTICE_MODE_PAIRS` / `_COLLOCATION` / `_SENTENCE` / `_DICTATION` | `true` | Switches one mode off: no new session of it; sessions already started finish |
| `PRACTICE_BATTLE` | `true` | The switch of the 1-on-1 battle (new invitations); matches that are on finish |
| `TTS_SERVICE_URL`, `TTS_VOICE`, `TTS_AUDIO_VERSION` | see §10.1 | Voicing of the dictation phrases uses the same service and voice as the word audio (§10.2) |

Fixed in `config/practice.php`: solo session TTL 24 h (`practice.solo.session_ttl_hours`); 5 tasks per session, at least 3 (`solo.items`); pairs — up to 3 rounds of 4, rounds of 3 allowed (`solo.pairs`); dictation — at most 3 phrases, 2 swaps of a clip (`solo.dictation`); import ≤ 2 MB and ≤ 300 tasks (`import`); the disk of the phrase audio `practice_audio` (`config/filesystems.php`, root `storage/app/practice-audio`, no URL).
