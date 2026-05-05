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
npm run build           # outputs to public/twa/
rm -rf node_modules     # optional: free disk space after build
cd ../..
```

> Do **not** use `npm ci --omit=dev` here — `vite` and `vue-tsc` (the build tools) live in `devDependencies` and the build will fail without them.

If Node.js is not on the server, build locally and upload the `public/twa/` directory via rsync or scp.

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

    # TWA SPA: serve the compiled Vue app with SPA fallback
    location /twa/ {
        try_files $uri $uri/ /twa/index.html;
        location ~* /twa/assets/ {
            add_header Cache-Control "public, max-age=31536000, immutable";
        }
        location = /twa/index.html {
            add_header Cache-Control "no-cache, no-store, must-revalidate";
        }
    }

    # Livewire uses hash-prefixed Laravel routes such as
    # /livewire-<hash>/livewire.min.js for Filament admin assets.
    # This must be handled by Laravel, not by a generic static *.js location.
    location ^~ /livewire- {
        try_files $uri $uri/ /index.php?$query_string;
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
- `exams:close-expired` — every minute (closes timed-out exam sessions).
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
# 1. Laravel health check
curl -sf https://yourdomain.com/up && echo "OK"

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
