# LexiFlow Pro

Telegram-based платформа обучения английскому языку. Laravel 12 + Filament 5 + PostgreSQL + Redis.
Подробности архитектуры — в [`docs/`](docs/).

## Tech stack

- **PHP** 8.3 / **Laravel** 12
- **Filament** 5 — админка
- **PostgreSQL** 16 / **Redis** 7
- **Telegram Bot SDK** (`irazasyed/telegram-bot-sdk`)
- **Pest** 3 — тесты, **Larastan** level 8 — статический анализ, **Pint** — code style
- **spatie/laravel-permission**, **owen-it/laravel-auditing**, **firebase/php-jwt**

## Локальный запуск (Docker)

Требуется Docker Desktop / Docker Engine с Compose v2.

```bash
cp .env.example .env
echo "UID=$(id -u)" >> .env && echo "GID=$(id -g)" >> .env  # macOS / Linux
docker compose build app
docker compose up -d
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

Открыть [http://localhost](http://localhost) — должна отобразиться стартовая страница Laravel.

> **Полный гайд** для macOS / Windows / WSL2 + Vite + ngrok (Telegram webhook) +
> рекомендации по работе с Claude Code в Docker — см.
> [`docs/LOCAL_DEV_DOCKER.md`](docs/LOCAL_DEV_DOCKER.md).

## Команды разработки (Makefile)

| Команда          | Что делает                                        |
|------------------|---------------------------------------------------|
| `make up`        | Поднять контейнеры                                |
| `make down`      | Остановить                                        |
| `make shell`     | Bash внутри `app`                                 |
| `make test`      | Pest                                              |
| `make lint`      | Pint (проверка)                                   |
| `make lint-fix`  | Pint (автофикс)                                   |
| `make analyse`   | PHPStan (Larastan level 8)                        |
| `make migrate`   | `php artisan migrate`                             |
| `make fresh`     | `php artisan migrate:fresh --seed`                |
| `make seed`      | `php artisan db:seed`                             |
| `make cache-clear` | `php artisan optimize:clear`                    |

## Production deployment

Полный набор артефактов для прода лежит в репозитории:

| Файл / таргет | Назначение |
|---------------|-----------|
| `docker-compose.prod.yml` | Prod-стек: app (php-fpm), queue, scheduler, nginx, certbot, postgres, redis |
| `docker/nginx/prod.conf` | Nginx с HTTPS + ACME webroot (подстановка `APP_DOMAIN` через `envsubst`) |
| `deploy.sh` / `make deploy` | Git pull → build → TWA build → migrate → cache → restart |
| `make backup` | Ручной запуск `artisan backup:run` (`spatie/laravel-backup`) |

### Первичный setup на сервере

```bash
# 1. Склонировать репозиторий и подготовить .env
git clone <repo> /opt/lexiflow && cd /opt/lexiflow
cp .env.example .env
# — отредактировать .env: APP_ENV=production, APP_DEBUG=false, APP_DOMAIN,
#   APP_KEY (php artisan key:generate), DB_PASSWORD, TELEGRAM_*, TWA_JWT_SECRET, BACKUP_DISKS
chmod 600 .env

# 2. Поднять стек БЕЗ https (certbot ещё не получил сертификат)
docker compose -f docker-compose.prod.yml --env-file .env up -d postgres redis app

# 3. Выпустить сертификат (standalone, пока nginx не стартовал)
docker run --rm -p 80:80 \
  -v lexiflow_letsencrypt:/etc/letsencrypt \
  -v lexiflow_certbot_www:/var/www/certbot \
  certbot/certbot certonly --standalone -d ${APP_DOMAIN} --agree-tos -m admin@${APP_DOMAIN} -n

# 4. Запустить весь стек
docker compose -f docker-compose.prod.yml --env-file .env up -d

# 5. Миграции + кэши + 2FA
docker compose -f docker-compose.prod.yml exec -T app php artisan migrate --force
docker compose -f docker-compose.prod.yml exec -T app php artisan db:seed --class=RolePermissionSeeder
docker compose -f docker-compose.prod.yml exec -T app php artisan config:cache
docker compose -f docker-compose.prod.yml exec -T app php artisan route:cache
docker compose -f docker-compose.prod.yml exec -T app php artisan view:cache

# 6. Создать первого admin и зайти в /admin/login — настроить 2FA
docker compose -f docker-compose.prod.yml exec -T app php artisan tinker
# >>> \App\Models\User::create([...])->assignRole('admin');

# 7. Webhook в Telegram
docker compose -f docker-compose.prod.yml exec -T app php artisan telegram:set-webhook
```

### Последующие deploy'и

```bash
git pull && ./deploy.sh
```

### Бэкапы

`spatie/laravel-backup` запускается по `Schedule` (см. `routes/console.php`):
- ежедневно в `01:30` — полный backup (DB dump + `base_path()` без `vendor` / `node_modules`),
- `01:00` — cleanup старых,
- `02:00` — health-check.

Диски задаются через `BACKUP_DISKS=local,s3`. Для offsite — настроить `AWS_*` в `.env`
(совместимо с S3 / DO Spaces / Yandex Object Storage через `AWS_ENDPOINT`).

Ручной запуск: `make backup`. Восстановление: `gunzip backup.sql.gz | psql -U lexiflow lexiflow`.

### Smoke-test чек-лист после deploy

- [ ] `curl -sI https://${APP_DOMAIN}/up` → `HTTP/2 200`
- [ ] `curl -sI https://${APP_DOMAIN}/admin/login` → `HTTP/2 200` + `Strict-Transport-Security` header
- [ ] Login в `/admin` как admin → редирект на `/2fa/setup`, QR виден.
- [ ] После setup редирект на `/2fa/recovery-codes`, затем `/admin` отдаёт dashboard.
- [ ] Logout → login → `/2fa/challenge` → TOTP → /admin.
- [ ] `composer audit` + `cd resources/twa && npm audit` — без critical/high.
- [ ] `docker compose -f docker-compose.prod.yml exec app php artisan backup:run --only-db` → видно zip в `storage/app/private/{APP_NAME}/`.
- [ ] Добавить бота в тестовую Telegram-группу → появляется запись в `telegram_groups` со статусом `pending`.
- [ ] `/help` в группе → бот отвечает.
- [ ] Браузерный Lighthouse по `/twa/` в Telegram Desktop → Performance ≥ 90.

## Documentation

| Документ | Описание |
|----------|----------|
| [`docs/01_PRD.md`](docs/01_PRD.md) | Product requirements |
| [`docs/02_ARCHITECTURE.md`](docs/02_ARCHITECTURE.md) | Архитектура |
| [`docs/03_DATABASE.md`](docs/03_DATABASE.md) | Схема БД |
| [`docs/04_API_AND_BOT.md`](docs/04_API_AND_BOT.md) | API и Telegram-бот |
| [`docs/05_TWA.md`](docs/05_TWA.md) | Telegram Web App |
| [`docs/06_SPACED_REPETITION.md`](docs/06_SPACED_REPETITION.md) | Алгоритм SM-2 |
| [`docs/07_SECURITY.md`](docs/07_SECURITY.md) | Безопасность |
| [`docs/08_ROADMAP.md`](docs/08_ROADMAP.md) | Roadmap |
| [`docs/09_AI_AGENTS_GUIDE.md`](docs/09_AI_AGENTS_GUIDE.md) | Guide для AI-агентов |

## Contributing

Правила — в [`CLAUDE.md`](CLAUDE.md). Каждый коммит должен проходить `make lint`, `make analyse`, `make test`.

## License

Proprietary — все права защищены.
