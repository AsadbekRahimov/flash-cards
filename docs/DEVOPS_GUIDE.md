# LexiFlow Pro — DevOps Deployment Guide

This guide covers deploying LexiFlow Pro on a **bare server** that already has:
- Linux (Debian/Ubuntu recommended)
- Git
- PostgreSQL 18
- PHP 8.5+ (with extensions: `pdo_pgsql`, `redis`, `bcmath`, `intl`, `gd` or `imagick`)
- Nginx (or Caddy)
- Certbot (SSL certificates)
- Supervisor (process manager for queue workers)
- Cron (system cron for scheduler)
- Redis

> If you prefer Docker, see `docker-compose.prod.yml` and `deploy.sh` in the project root.
> Step-by-step guide in Russian for a fresh VPS (installing PHP, PostgreSQL, Redis, Nginx from scratch): `docs/VPS_DEPLOY_GUIDE_RU.md`.

## 0. Docker deployment

The production Dockerfile builds four reproducible stages: both Laravel/TWA frontend bundles, the PHP application with `vendor/`, an nginx image containing the exact same `public/` tree, and `pgbouncer` (Alpine package, configured from the environment at start; see «pgbouncer и постоянные соединения» below). Production does not mount a persistent volume over `public/`.

Set `APP_DOMAIN`, `CERTBOT_EMAIL`, `APP_KEY` and the other required values in `.env`, then run:

```bash
chmod 600 .env
bash deploy.sh
```

Скрипт принимает SHA коммита: `bash deploy.sh <sha>` (или переменная `DEPLOY_SHA`). Он делает `git fetch origin`, `git checkout --detach <sha>` и **до сборки** проверяет, что `git rev-parse HEAD` равен запрошенному SHA, иначе завершается с кодом 1. CI передаёт `${{ github.sha }}`, поэтому выкатывается ровно тот коммит, который прошёл проверки, а не то, на что `master` указывает к моменту деплоя; job `deploy` идёт с `concurrency: production-deploy` (без отмены уже идущего деплоя). Деплой по SHA разрешён только вперёд (`git merge-base --is-ancestor HEAD <sha>`), назад — только с `--rollback`. Без SHA скрипт делает `git fetch origin && git checkout --detach origin/${DEPLOY_BRANCH:-master}` (не `git pull`, который ломается в detached HEAD после отката) и печатает предупреждение. Образы в registry не публикуются: собираются на сервере из этого SHA.

Дальше: сборка `app` и `nginx`, первичный сертификат Let's Encrypt при необходимости, миграции до переключения контейнеров, затем `php artisan courses:recount-words` (счётчики слов курсов, см. §14.2; не при `--rollback`, сбой только предупреждает), запуск стека и smoke-проверка: `/up`, затем ожидание `/health/ready` (до ~5 минут: нужны пульсы планировщика и воркеров), затем `/twa/index.html` и `/build/manifest.json`. Любой провал = `exit 1` с сообщением и подсказкой про откат. Сертификат обновляет сервис `certbot`; nginx периодически перечитывает сертификаты.

**Секреты деплоя из CI** — в окружении GitHub `LexiFlow`: `SSH_HOST`, `SSH_USER`, `SSH_PRIVATE_KEY`. Сервера пока нет (решение владельца 03.11.2026), поэтому:
- ни одного секрета нет — job `deploy` пропускается с предупреждением «Deploy skipped», CI на `master` остаётся зелёным;
- задана только часть — job падает с ошибкой «Deploy secrets incomplete»: это ошибка настройки, а не «сервера нет»;
- заданы все три — деплой идёт как описано выше.

Воркеры очереди в `docker-compose.prod.yml` разделены: `queue-high` (`--queue=high`), `queue-default` (`--queue=default`) и `queue-low` (`--queue=low`), `scheduler` отдельно. Воркер берёт очереди в строгом порядке приоритета, поэтому общий `default,low` голодает `low`, пока `default` не пуст: при рассылке напоминаний (`SendLearnerReminderJob`, лимит Telegram 25/с) поток `default` держится минутами, пульс `low` стареет больше 5 минут, и `/health/ready` падает по `queue`. Отсюда отдельный пул для `low` (долгая TTS-озвучка).

### Кэши и OPcache прод-образа

- **Кэши при старте.** `docker/entrypoint.sh` при `APP_ENV=production` на каждом старте контейнера собирает `config:cache`, `route:cache`, `view:cache`, **`filament:optimize`** (кэш компонентов панелей `/admin` и `/org` и Blade-иконок Filament, `docs/20` §1.12) и `event:cache`. В dev-контейнере (`APP_ENV=local`) ничего из этого не запускается: кэш компонентов скрыл бы новый ресурс или виджет. Новый ресурс в проде появляется вместе с новым контейнером: `filament:optimize` выполняется заново. Сбросить все кэши в dev — `make cache-clear` (`optimize:clear` и `filament:optimize-clear`).
- **OPcache.** `docker/php/opcache-prod.ini` копируется **только** в прод-образ (`Dockerfile`, стадия `app`; `Dockerfile.dev` его не получает): `opcache.enable=1`, `opcache.validate_timestamps=0`, `opcache.save_comments=1`, `memory_consumption=256`, `interned_strings_buffer=64`, `max_accelerated_files=32531`. Размер взят по `find vendor app -name '*.php' | wc -l` = 16 899 файлов (дерево разработки; в `--no-dev` образе меньше) плюс около 250 скомпилированных Blade-шаблонов; PHP округляет значение до простого числа из своего списка (16229, 32531, 65407), так что запас — примерно вдвое. Сборка образа падает, если `php -i` не показывает `opcache.validate_timestamps => Off`, `opcache.save_comments => On` и `opcache.enable => On`.
- **Почему `validate_timestamps=0` безопасен.** Код в образе неизменяем, а деплой (`deploy.sh`) собирает новый образ и пересоздаёт контейнеры `app`, `app-api`, `queue-high`, `queue-default`, `queue-low`, `scheduler`: новый процесс стартует с пустым кэшем, и PHP больше не сверяет время файлов. Файлы `bootstrap/cache/*.php` entrypoint пишет **до** `exec php-fpm`, поэтому php-fpm читает уже готовые. Единственное, что с этой настройкой не работает, — правка PHP-файла **внутри живого контейнера** (ручной `artisan config:cache`, патч в `vendor`): php-fpm продолжит исполнять прежний код, пока его не перезапустят (`docker compose -f docker-compose.prod.yml --env-file .env restart app app-api`). Так не делаем: любая правка идёт через коммит и деплой.
- **Пул php-fpm.** Образ `php:8.5-fpm-alpine` по умолчанию держит `pm.max_children = 5`: под нагрузкой в логе `server reached pm.max_children (5)`, запросы встают в очередь. Прод-образ кладёт `docker/php/zz-lexiflow-pool.conf` в `php-fpm.d/` (грузится после `zz-docker.conf`): `pm = dynamic`, значения берутся из окружения контейнера — `PHP_FPM_MAX_CHILDREN`, `PHP_FPM_START_SERVERS`, `PHP_FPM_MIN_SPARE_SERVERS`, `PHP_FPM_MAX_SPARE_SERVERS`, `PHP_FPM_MAX_REQUESTS`. Умолчания в `Dockerfile` (`ENV`): 16 / 4 / 2 / 6 / 500; сборка падает, если `php-fpm -tt` их не видит. В `docker-compose.prod.yml` два контейнера php-fpm делят пул (см. «Две поверхности приложения»): `app` — `PHP_FPM_*` с умолчаниями compose 6 / 2 / 1 / 3, `app-api` — `PHP_FPM_API_*` с умолчаниями 12 / 3 / 2 / 4 (ученики — большая часть запросов; панели, вебхуки и сайт — несколько человек и боты), `PHP_FPM_MAX_REQUESTS` общий. Переопределение — строка в `.env` хоста (compose интерполирует его) и `docker compose up -d app app-api`; пустое значение недопустимо (php-fpm не стартует) — не нужно, закомментируйте строку.
  - *Как подобрать `PHP_FPM_MAX_CHILDREN`.* Один воркер с этим приложением занимает примерно 40–60 МБ RSS (OPcache общий и в это не входит). Верхняя граница по памяти: `(RAM под php-fpm) / 50 МБ`, где «RAM под php-fpm» = RAM хоста минус PostgreSQL, Redis, воркеры очередей, nginx и запас ~20 %. Запросы почти целиком CPU-bound (БД и Redis на той же машине, внешних долгих вызовов в веб-пути нет), поэтому смысла выше 2–4 воркеров на ядро нет: больше процессов только делят то же время CPU. Берите меньшее из двух. Для 2 vCPU / 4 ГБ — 8–16, для 4 vCPU / 8 ГБ — 16–32 — это сумма по `app` + `app-api` (умолчания compose 6 + 12 = 18).
  - *Остальные значения.* `start_servers` ≈ четверть `max_children`, `min_spare` — половина `start_servers`, `max_spare` — не больше трети `max_children`; `max_requests=500` перезапускает воркер и ограничивает утечки памяти. Проверка: `docker compose exec app php-fpm -tt 2>&1 | grep pm` (и так же `app-api`); насыщение — строки `server reached pm.max_children` в `docker compose logs app` / `logs app-api`.
- **Хватает ли памяти.** Статус общего кэша FPM (`opcache_get_status()`: `memory_usage`, `interned_strings_usage`, `opcache_statistics.num_cached_keys` против `max_cached_keys`, `oom_restarts`, `hash_restarts`) виден только из запроса, обработанного самим FPM: у CLI свой кэш, и `docker compose exec app php -r …` покажет не то. Норма: свободно не меньше ~20 % `memory_consumption`, `oom_restarts = 0`, `hash_restarts = 0`, `num_cached_keys` заметно ниже `max_cached_keys`. Снять эти числа под реальным трафиком — задача C8 (`docs/19`); поднять `memory_consumption` / `max_accelerated_files` в `docker/php/opcache-prod.ini` и пересобрать образ, если запас меньше нормы.
- **Bare-сервер (без Docker).** Этот ini там не применяется. Если выставить `validate_timestamps=0` и на bare-сервере, после каждого деплоя нужен `systemctl reload php8.5-fpm` (сбрасывает кэш), иначе FPM продолжит исполнять старый код.

### Две поверхности приложения: `app` и `app-api`

Один образ `lexiflow/app`, два контейнера php-fpm; что из них запущено — переменная окружения `APP_SURFACE` (`App\Support\Surface`):

| | `app` (`APP_SURFACE=full`, умолчание образа) | `app-api` (`APP_SURFACE=api`) |
|---|---|---|
| Маршруты | всё: `/admin`, `/org`, сайт `/`, `/telegram/webhook/*`, `/billing/webhook/*`, `/billing/return`, `/2fa/*`, `/up`, `/health/ready`, `/api/*` | только `routes/api.php` (`/api/twa/*`), `/up` и `/health/ready` (`routes/health.php`); `routes/web.php` не загружается |
| Провайдеры проекта (`bootstrap/providers.php`) | `AppServiceProvider` + панели Filament (`FilamentDefaultsProvider`, `AdminPanelProvider`, `OrgPanelProvider`) | только `AppServiceProvider` |
| Пакеты с автообнаружением (`bootstrap/cache/packages.php`) | все | без `filament/*`, `livewire/livewire`, `blade-ui-kit/*`, `ryangjchandler/blade-capture-directive`, `kirschbaum-development/eloquent-power-joins`, `laravel/tinker`, `spatie/laravel-backup`, `spatie/laravel-signal-aware-command`, `pragmarx/google2fa-laravel`, `owen-it/laravel-auditing` (список — `Surface::API_EXCLUDED_PACKAGES`); остаются sentry, telegram-bot-sdk, carbon, termwind, spatie/laravel-permission |
| Кэши при старте (`docker/entrypoint.sh`) | `config`, `route`, `view`, `filament:optimize`, `event` | `config`, `route`, `event` — без `view:cache` (Blade при компиляции `<x-filament::…>` ищет класс компонента, без Filament это ошибка; API не рендерит свои Blade, страницы ошибок фреймворка компилируются при первом обращении) и без `filament:optimize` (команды нет) |
| Пул php-fpm (4 vCPU) | `PHP_FPM_*`: 6 / 2 / 1 / 3 | `PHP_FPM_API_*`: 12 / 3 / 2 / 4 |

Зачем. Из ~28 мс CPU запроса TWA под php-fpm около 20 мс — загрузка фреймворка, и заметная часть — регистрация Filament, Livewire и Blade-компонентов, которые ученику не нужны. На поверхности `api` их нет: ни классов в процессе, ни их маршрутов, ни `PublishNotifications` в глобальных middleware. Процесс, принимающий трафик учеников, не несёт Livewire и панелей вовсе. Поведение самого API одинаково на обеих поверхностях (тесты идут на `full`).

Как это устроено. `APP_SURFACE` читается из окружения процесса (`$_SERVER`/`getenv`), не из конфига: решение нужно до того, как конфиг существует, а `config:cache` заморозил бы его. В compose это `environment:` сервиса `app-api`; php-fpm отдаёт окружение воркерам (официальный образ ставит `clear_env = no`); `Dockerfile` задаёт `ENV APP_SURFACE=full`, поэтому воркеры очередей и планировщик — полные. Строка в `.env` тоже работает (все три вопроса — `bootstrap/providers.php`, файлы маршрутов, манифест пакетов — задаются после загрузки `.env`). Неизвестное значение — исключение при первом же `artisan` (контейнер не стартует), а не тихий полный процесс под видом api. Манифест пакетов один на образ (`package:discover` при `composer install`), поэтому фильтр — в рантайме: `App\Support\SurfacePackageManifest` подменяет `PackageManifest` в `bootstrap/app.php`. `OrgPolicyResolver::inOrgPanel()` на `api` отвечает `false`, не трогая фасад Filament.

nginx (`docker/nginx/prod.conf`): `location ^~ /api/ { fastcgi_pass app-api:9000; }` — префикс `^~` побеждает регулярные location, так что каждый `/api/*` (в том числе mp3 практики и игры `/api/twa/…/audio/…`; `audio/mpeg` не в `gzip_types`, сжатия нет, как и раньше) идёт в `app-api`; всё остальное — в `app`. nginx ждёт `service_healthy` обоих. Если `app-api` лежит — 502 только на `/api/*`, панели и вебхуки живут.

`deploy.sh` после `up` проверяет в контейнере `app-api`, что `route:list --json` содержит `api/twa/me` и не содержит `filament.`/`admin`, а в smoke — что `GET /api/twa/me` через nginx отвечает 401 (JSON Laravel из `app-api`).

Проверка руками:

```bash
docker compose -f docker-compose.prod.yml --env-file .env exec app-api php artisan about | grep -i -E 'filament|livewire'   # пусто
docker compose -f docker-compose.prod.yml --env-file .env exec app-api php artisan route:list | grep -c 'filament\.'      # 0
docker compose -f docker-compose.prod.yml --env-file .env exec app-api php artisan route:list | grep -E 'api/twa/me|health/ready'
docker compose -f docker-compose.prod.yml --env-file .env exec app     php artisan route:list | grep -c 'filament\.'      # > 0
curl -s -o /dev/null -w '%{http_code}\n' https://$APP_DOMAIN/api/twa/me   # 401
```

Octane: контейнер `app-api` может работать на FrankenPHP — «Режим контейнера `app-api`» ниже. Поверхность — свойство процесса, а не запроса; под Octane воркер стартует один раз с тем же окружением (`APP_SURFACE=api` из `environment:`/`ENV` образа).

### Режим контейнера `app-api`: `APP_SERVER=fpm|octane`

Одна переменная в `.env` (R13 шаг 2, `docs/21_OCTANE_READINESS.md`); умолчание и отсутствие переменной — `fpm`, всё как до Octane. Режим касается **только** `app-api`: `app` (панели, вебхуки), очереди и планировщик остаются на `lexiflow/app` (php-fpm / CLI).

| | `fpm` (умолчание) | `octane` |
|---|---|---|
| Стадия Dockerfile / образ `app-api` | `app-fpm` (алиас `app`, тот же слой) / `lexiflow/app-fpm:<tag>` | `app-octane` (`dunglas/frankenphp:1-php8.5-alpine`, ZTS) / `lexiflow/app-octane:<tag>` |
| Процесс | php-fpm, пул `PHP_FPM_API_*` | `docker/octane-start.sh` → `php artisan octane:frankenphp`, `OCTANE_WORKERS` (6 на 4 vCPU), `OCTANE_MAX_REQUESTS` (500), `OCTANE_LOG_LEVEL` (WARN) |
| nginx `/api/` | `docker/nginx/snippets/api-fpm.conf` (`fastcgi_pass app-api:9000`) | `snippets/api-octane.conf` (`proxy_pass http://app-api:8000`, `X-Forwarded-*`) |
| Healthcheck | `php-fpm -t` | `curl -fsS http://127.0.0.1:8000/up` |
| `TRUSTED_PROXIES` | пусто | подсеть compose-сети (`COMPOSE_SUBNET`, по умолчанию `172.28.0.0/16`) |

Переключение fpm → octane: в `.env` `APP_SERVER=octane` и `TRUSTED_PROXIES=<COMPOSE_SUBNET>`, затем `bash deploy.sh <sha>` (собирает `app-octane` и, на всякий случай, `app-fpm`). **Откат:** `APP_SERVER=fpm` (или удалить строку) и `docker compose -f docker-compose.prod.yml --env-file .env up -d app-api nginx` — без сборки: `deploy.sh` всегда собирает `app-fpm`; nginx при старте перечитывает сниппет по `APP_SERVER`. `TRUSTED_PROXIES` можно оставить: под fpm `REMOTE_ADDR` вне подсети, заголовки не учитываются.

Воркеры. `OCTANE_WORKERS=6` на 4 vCPU: запрос в основном CPU, но ждёт PostgreSQL/Redis, а рядом живут PostgreSQL, Redis ×2, pgbouncer, nginx и `app`; больше 2 на ядро не нужно. Память — ~50–90 МБ на воркер без Filament (6 воркеров ≈ 0,3–0,55 ГБ + процесс Caddy). `OCTANE_MAX_REQUESTS=500`: воркер пересоздаётся после N запросов — страховка от медленных утечек вендорных пакетов; рестарты видны в логе контейнера. Лимит запроса — `octane.max_execution_time`, `stop_grace_period: 30s` (по SIGTERM Octane дожидается запросов в полёте).

Что смотреть: `docker stats lexiflow-app-api` (RSS растёт не ступенями до рестарта по `max-requests`, а стоит), `docker compose ... logs app-api` (падения воркеров, `WARN`+), `docker compose ... exec app-api php artisan octane:status --server=frankenphp` (его же вызывает `deploy.sh`), `make prod-logs-api`, `make prod-shell-api`.

Доверенные прокси и подсеть. За nginx приложение видит адрес контейнера nginx, а не ученика; настоящий адрес — в `X-Forwarded-For`, который nginx **заменяет** (`$remote_addr`, клиентский заголовок не дописывается). Laravel верит этим заголовкам только от `TRUSTED_PROXIES` (`config/trustedproxy.php`) — подсети compose-сети, закреплённой в `docker-compose.prod.yml` (`networks.default.ipam`, `COMPOSE_SUBNET`). Значение `*` запрещено. Если подсеть пересекается с сетью хоста — поменять `COMPOSE_SUBNET` и `TRUSTED_PROXIES` вместе. При первом деплое с закреплённой подсетью compose пересоздаёт сеть: контейнеры перезапускаются, это короткий простой. Неверная настройка видна сразу: smoke `POST /api/twa/auth` в `deploy.sh` получает 429 (лимиты per IP схлопнулись в одно ведро). `OCTANE_HTTPS=true` задан в compose всегда (схема приложения — https, TLS на nginx).

Сборка. `app-octane` повторяет установку расширений стадии `app` (держать в согласии), код и `vendor/` берёт из `app` (composer один раз); `public/frankenphp-worker.php` кладётся на сборке; Caddyfile — стандартный стаб Octane (его `encode` не срабатывает: nginx шлёт `Accept-Encoding: ""`, сжимает только nginx — BREACH-исключения панелей сохраняются). Порт 8000 наружу не публикуется, Caddy-admin слушает `127.0.0.1:2019` внутри контейнера.

Что ещё НЕ поделено: `storage` — общий volume (логи, маркеры circuit breaker кэша, скомпилированные Blade); `bootstrap/cache` — свой у каждого контейнера (не volume), поэтому кэши маршрутов и провайдеров у них разные, и это правильно.

### pgbouncer и постоянные соединения (R12d, решение владельца 04.11.2026)

Репетиция R12b показала, что без постоянных соединений каждый HTTP-запрос открывает новое соединение с PostgreSQL: около 5,5 мс CPU в PHP (`Connector::createPdoConnection`) плюс около 15 мс CPU в PostgreSQL (fork backend, SCRAM, прогрев каталога). С `PDO::ATTR_PERSISTENT` CPU на запрос упал с 66 до 37 мс, p95 при 100 VU — с 514 до 107 мс, пропускная способность при 300 VU — с 42,6 до 67,2 req/s. Схема прод-стека теперь такая:

```
php-fpm (16 воркеров, по одному постоянному PDO-соединению на воркер)  ─┐
queue-high / queue-default / queue-low (по одному соединению)           ├─► pgbouncer:5432 (transaction mode) ─► postgres:5432
scheduler и его команды (короткие соединения)                           ─┘        PGBOUNCER_POOL_SIZE + RESERVE backend'ов
migrate (deploy.sh), pg_dump (backup:run)  ───────────────── pgsql_direct ──────────────────────────────────────► postgres:5432
```

**Переменные.** В `.env` прода: `DB_HOST=pgbouncer` (порт тот же, 5432) и `DB_PERSISTENT=true`; `DB_DISABLE_PREPARES=true` — умолчание. `DB_DIRECT_HOST=postgres` / `DB_DIRECT_PORT=5432` compose подставляет контейнерам приложения сам (`environment` сильнее `env_file`): это имена сервисов этого файла, а не секреты. Размеры пула — `PGBOUNCER_POOL_SIZE` (20), `PGBOUNCER_RESERVE_POOL_SIZE` (4), `PGBOUNCER_MAX_CLIENT_CONN` (200), `PGBOUNCER_QUERY_WAIT_TIMEOUT` (30 с) — тоже из `.env`, умолчания в `docker/pgbouncer/entrypoint.sh`.

**Что делает каждая часть.**
- `DB_PERSISTENT=true` (`config/database.php`, `PDO::ATTR_PERSISTENT`): воркер php-fpm держит соединение между запросами. Открытую транзакцию PHP откатывает сам в момент освобождения объекта PDO в конце запроса (`ext/pdo/pdo_dbh.c`, `pdo_dbh_free_storage`: спрашивает драйвер `in_transaction` — у pdo_pgsql это `PQtransactionStatus` — и шлёт `ROLLBACK`); тот же обработчик выполняется при необработанном исключении, fatal (memory limit) и `max_execution_time`, а убитый по `request_terminate_timeout` воркер закрывает сокет, и PostgreSQL сам откатывает его транзакцию. Мёртвый после рестарта handle `PDO::connect()` проверяет перед выдачей (`ext/pdo_pgsql/pgsql_driver.c`, `pdo_pgsql_check_liveness`: `PQconsumeInput` + `PQreset`), а внутри запроса потерю соединения вне транзакции Laravel чинит переподключением и повтором запроса (`Connection::tryAgainIfCausedByLostConnection`); внутри транзакции запрос падает с 500, следующий получает чистое соединение. В dev и тестах выключено (`phpunit.xml`: `DB_PERSISTENT=false`): тесты гонок делают `pcntl_fork()`, а дочерний процесс унаследовал бы чужой сокет. Тест семантики — `tests/Feature/Database/PersistentPgsqlConnectionTest.php` (только на PostgreSQL).
- `DB_DISABLE_PREPARES=true` (`Pdo\Pgsql::ATTR_DISABLE_PREPARES`): запрос уходит как безымянный statement (`PQexecParams`), значения — отдельными параметрами, как и раньше. Без этого pdo_pgsql на каждый запрос делает `PREPARE pdo_stmt_N` + `EXECUTE` + закрытие — лишний круг на запрос, который Laravel никогда не переиспользует, а с libpq < 17 закрытие идёт SQL-командой `DEALLOCATE`, которую pgbouncer в transaction mode отдаёт на чужое серверное соединение (ошибка, внутри транзакции — её отмена). Сборка `app` падает, если pdo_pgsql собран с libpq < 17; pgbouncer держит `max_prepared_statements = 200` на случай `DB_DISABLE_PREPARES=false`.
- `pgbouncer` (`pool_mode = transaction`, образ — стадия `pgbouncer` в `Dockerfile`: `alpine:3.24` + пакет `pgbouncer` ≥ 1.21, проверяется при сборке; CI собирает её в job `build`): серверное соединение занято клиентом только на время транзакции, вне её у постоянного клиентского соединения нет backend'а вообще. Аутентификация `scram-sha-256` в обе стороны по `DB_USERNAME`/`DB_PASSWORD` (`userlist.txt` пишется при старте, mode 0600, в образ не попадает). Сессионных возможностей приложение не использует: единственный advisory lock — транзакционный `pg_advisory_xact_lock` (`LeagueService`), ни `LISTEN/NOTIFY`, ни временных таблиц, ни курсоров `WITH HOLD`, ни `SET` без `LOCAL` в коде нет (поиск сделан в R12d; новое такое место — повод для `pgsql_direct`). Коннектор Laravel шлёт `set search_path to "public"` при каждом подключении и `client_encoding` в стартовом пакете: `client_encoding` pgbouncer отслеживает и переносит на серверное соединение сам, а `search_path` намеренно не отслеживается (`ignore_startup_parameters = ..., search_path`: в 1.26.0 отслеживание появилось, в 1.26.1 признаны ошибки) — у всех клиентов этого пула одно и то же значение, и `public` — единственная схема базы, так что команда безвредна, на какое бы серверное соединение ни попала.
- `pgsql_direct` (`config/database.php`): та же база мимо pgbouncer. `deploy.sh` запускает `migrate --force --database=pgsql_direct` (мигратор делает это соединение умолчанием на время каждой миграции, так что `Schema::`/`DB::` внутри миграций тоже идут напрямую; миграция может держать блокировки и работать минуты), `config/backup.php` отдаёт spatie `pg_dump` это же соединение (имя файла дампа — по имени базы, как раньше). **`DB_URL`:** если у `pgsql` задан `DB_URL`, URL перекрывает `DB_HOST=pgbouncer`; у `pgsql_direct` свой `DB_DIRECT_URL` (`DB_URL` он не читает), иначе миграции и `pg_dump` ушли бы туда же, куда приложение, вплоть до pgbouncer. Для прямого соединения при URL-конфигурации задайте `DB_DIRECT_URL`. `docker/postgres/upgrade.sh` ходит в контейнер `postgres` через сокет и приложение не трогает.
- `idle_in_transaction_session_timeout=60s` — флаг `-c` у `postgres` в `docker-compose.prod.yml`: сессия, которая минуту молчит с открытой транзакцией, закрывается, транзакция откатывается. Штатные пути так не простаивают (между запросами одного HTTP-запроса — микросекунды, `pg_dump` и миграции активны, а не idle); это страховка от утёкшей транзакции — зависший воркер, забытый `BEGIN` в psql оператора, — которая с pgbouncer ещё и заняла бы серверное соединение из пула навсегда. На сервере, а не в pgbouncer или в DSN, чтобы действовало для любого клиента, в пуле или напрямую, и в режиме без pgbouncer тоже.

**Бюджет соединений** (`max_connections` официального образа — 100, VPS-гайд §4.3 — тоже 100):

| Кто | Соединений с PostgreSQL |
|---|---|
| pgbouncer: `PGBOUNCER_POOL_SIZE` + `PGBOUNCER_RESERVE_POOL_SIZE` (`max_db_connections` = их сумма) | 20 + 4 = 24 |
| `migrate` во время деплоя, `pg_dump` ночью (через `pgsql_direct`) | 1–2 |
| psql оператора, `upgrade.sh` | 1–3 |
| `superuser_reserved_connections` PostgreSQL | 3 |
| **Итого** | **≈ 32 из 100** |

Клиентская сторона pgbouncer: `PGBOUNCER_MAX_CLIENT_CONN` ≥ Σ `PHP_FPM_MAX_CHILDREN` по контейнерам `app` (6) + `app-api` (12) + воркеры очередей (3) + `scheduler` с его командами (≈ 5) + healthcheck и консоль; 200 при умолчаниях 18 воркеров FPM — с большим запасом, клиентское соединение pgbouncer стоит ~2 КБ. Серверная сторона: `PGBOUNCER_POOL_SIZE` — это сколько транзакций PostgreSQL выполняет одновременно; для OLTP правило 2–4 × vCPU (4 vCPU → 8–16, умолчание 20 с запасом на ожидание диска); больше — не быстрее, а очередь перед PostgreSQL. При `PGBOUNCER_QUERY_WAIT_TIMEOUT` (30 с) ожидания свободного серверного соединения клиент получает ошибку (запрос — 500), а не висит. **Без pgbouncer** (`DB_HOST=postgres`, `DB_PERSISTENT=true`) формула другая: Σ `PHP_FPM_MAX_CHILDREN` + воркеры очередей + команды планировщика + запас < `max_connections`, потому что каждый воркер FPM держит backend даже простаивая: 16 + 3 + 5 + 10 = 34 — тоже укладывается в 100, но при росте `PHP_FPM_MAX_CHILDREN` или втором контейнере `app` считать заново.

**Как смотреть.** Консоль pgbouncer (пользователь `DB_USERNAME` — только `stats_users`: `SHOW`, без `SHUTDOWN`/`PAUSE`/`KILL`; администратор появляется, только если в `.env` заданы `PGBOUNCER_ADMIN_USER` и `PGBOUNCER_ADMIN_PASSWORD`, и заходить надо под ним):

```bash
docker compose -f docker-compose.prod.yml --env-file .env exec pgbouncer psql -h 127.0.0.1 -U lexiflow pgbouncer -c 'SHOW POOLS'   # cl_active/cl_waiting - клиенты, sv_active/sv_idle - backend'ы, maxwait - ожидание пула
docker compose -f docker-compose.prod.yml --env-file .env exec pgbouncer psql -h 127.0.0.1 -U lexiflow pgbouncer -c 'SHOW STATS'   # total_xact_count, avg_xact_time, avg_wait_time (мкс), total_wait_time
docker compose -f docker-compose.prod.yml --env-file .env exec pgbouncer psql -h 127.0.0.1 -U lexiflow pgbouncer -c 'SHOW SERVERS'
```

`psql` спросит пароль (`DB_PASSWORD`). Тревожные признаки: `cl_waiting` > 0 постоянно или `maxwait` в секундах — пул мал или запросы медленные (сначала `pg_stat_statements`/медленные запросы, потом `PGBOUNCER_POOL_SIZE`); `avg_wait_time` растёт; в `docker compose logs pgbouncer` строки `query_wait_timeout` или `server login failed`. В PostgreSQL: `select state, count(*) from pg_stat_activity where datname = 'lexiflow' group by 1` — `idle in transaction` старше секунд там быть не должно (срез идёт через 60 с).

**Рестарты.** PostgreSQL перезапустился — pgbouncer теряет серверные соединения, запросы, бывшие в полёте, падают (внутри транзакции — 500 без повтора), простаивавшие клиенты ничего не замечают; новые серверные соединения pgbouncer открывает через `server_login_retry = 2` с. pgbouncer перезапустился (`docker compose restart pgbouncer`, ~1 с) — каждый воркер FPM на следующем запросе находит свой handle мёртвым и пересоздаёт его сам (проверено на стенде R12d: 551 «запросов» подряд с двумя рестартами, 11 ошибок — ровно те, что пришлись на секунду недоступности, после них всё само восстановилось). `/health/ready` ходит в базу через то же соединение приложения, то есть через pgbouncer: лежит pgbouncer — проверка `db` даёт `fail` и `503`, что и нужно (§15.2).

**Как отключить pgbouncer.** В `.env` прода `DB_HOST=postgres` и обычный `deploy.sh` (или `docker compose up -d app queue-high queue-default queue-low scheduler`): приложение, воркеры и `pgsql_direct` идут в PostgreSQL напрямую, контейнер `pgbouncer` остаётся запущенным, но без клиентов. `DB_PERSISTENT=true` можно оставить — тогда действует бюджет «без pgbouncer» выше; `DB_PERSISTENT=false` возвращает соединение на запрос. Та же пара переменных — путь отката, если pgbouncer ведёт себя неожиданно.

### PostgreSQL: настройки под сервер

Официальный образ стартует со стоковыми настройками, рассчитанными на ноутбук: `shared_buffers` 128 МБ, `random_page_cost` 4, `jit` включён. `docker-compose.prod.yml` (сервис `postgres`) передаёт серверу флаги `-c` из переменных `PG_*` в `.env`; умолчания — под эталонный сервер 4 vCPU / 8 ГБ (тот же, на который считан «Пул php-fpm»). Переменная не задана — действует умолчание; `.env.example` содержит закомментированный блок со всеми.

| Параметр (`PG_*`) | Умолчание | Формула | Зачем |
|---|---|---|---|
| `shared_buffers` (`PG_SHARED_BUFFERS`) | 2GB | ~25 % RAM | собственный кэш страниц PostgreSQL: горячие карточки, прогресс, индексы |
| `effective_cache_size` (`PG_EFFECTIVE_CACHE_SIZE`) | 5GB | ~60 % RAM | подсказка планировщику (памяти не выделяет): `shared_buffers` + кэш страниц ОС; не 75 %, потому что рядом Redis ×2, php-fpm (18 воркеров), pgbouncer, nginx |
| `work_mem` (`PG_WORK_MEM`) | 16MB | ≤ 24 backend'ов × несколько узлов сортировки × `work_mem` << свободной RAM | сортировки и хеши без сброса на диск; лимит на узел плана, не на запрос |
| `maintenance_work_mem` (`PG_MAINTENANCE_WORK_MEM`) | 256MB | 3–6 % RAM | VACUUM, `CREATE INDEX` в миграциях |
| `random_page_cost` (`PG_RANDOM_PAGE_COST`) | 1.1 | 4 на HDD, 1.1 на SSD | на SSD индексный доступ почти так же дёшев, как последовательный; при 4 планировщик зря выбирает seq scan |
| `effective_io_concurrency` (`PG_EFFECTIVE_IO_CONCURRENCY`) | 200 | SSD/NVMe 200, HDD 2 | глубина упреждающего чтения bitmap-сканов |
| `jit` (`PG_JIT`) | off | — | запросы приложения короткие (OLTP): JIT тратит CPU на планирование и ничего не даёт |
| `max_wal_size` (`PG_MAX_WAL_SIZE`) | 2GB | — | реже принудительные checkpoint'ы при всплеске записи (`review`) |
| `synchronous_commit` (`PG_SYNCHRONOUS_COMMIT`) | on | — | см. ниже; `on` — подтверждённый коммит уже на диске |
| `log_min_duration_statement` (`PG_LOG_MIN_DURATION_STATEMENT`) | 500 | мс; -1 — выключить | запросы медленнее порога попадают в `docker compose logs postgres` |
| `idle_in_transaction_session_timeout` | 60s | не параметризуется | см. выше |
| `shm_size` (`PG_SHM_SIZE`) | 256m | — | Docker даёт `/dev/shm` 64 МБ, а параллельные воркеры PostgreSQL (`dynamic_shared_memory_type=posix`) падают с «could not resize shared memory segment» |

**Как проверить.** `deploy.sh` после запуска печатает основные значения (блок «PostgreSQL settings in effect», только информация, деплой не останавливает). Вручную:

```bash
docker compose -f docker-compose.prod.yml --env-file .env exec postgres sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SHOW shared_buffers" -c "SHOW work_mem" -c "SHOW jit"'
```

**Другой сервер.** 2 vCPU / 4 ГБ: `PG_SHARED_BUFFERS=1GB`, `PG_EFFECTIVE_CACHE_SIZE=2500MB`, `PG_WORK_MEM=8MB`, `PG_MAINTENANCE_WORK_MEM=128MB` (остальное без изменений). 8 vCPU / 16 ГБ: `PG_SHARED_BUFFERS=4GB`, `PG_EFFECTIVE_CACHE_SIZE=10GB`, `PG_WORK_MEM=32MB`, `PG_MAINTENANCE_WORK_MEM=512MB`, `PG_MAX_WAL_SIZE=4GB`, и вместе с ними растят `PGBOUNCER_POOL_SIZE` (правило 2–4 × vCPU, §0 «pgbouncer»). `work_mem` проверяйте арифметикой: `PGBOUNCER_POOL_SIZE` + reserve × `work_mem` × 2–3 узла плана должны с запасом укладываться в RAM, оставшуюся после `shared_buffers` и остальных контейнеров.

**`synchronous_commit=off` — решение владельца, по умолчанию не включено.** Коммит подтверждается клиенту до записи WAL на диск (запись — в фоне, примерно раз в 3 × `wal_writer_delay` = 0,6 с). Выигрыш — латентность записи: ответ `review` не ждёт fsync. Цена: при аварийном выключении сервера (питание, kernel panic; остановка или падение контейнера PostgreSQL сюда не относится — WAL сбрасывается на диск при завершении процесса) теряются последние ~0,6 с уже подтверждённых транзакций, то есть несколько ответов учеников; база при этом НЕ портится (в отличие от `fsync=off`, который включать нельзя), она просто откатывается на согласованное состояние чуть раньше. Платежи и Stars-транзакции тоже попадают под эту потерю, поэтому включать стоит, только приняв это явно: `PG_SYNCHRONOUS_COMMIT=off` в `.env`. Точечная альтернатива без глобальной потери — `SET LOCAL synchronous_commit = off` в конкретных транзакциях кода (сейчас не используется).

**Применение.** Флаги читаются при старте сервера: `docker compose -f docker-compose.prod.yml --env-file .env up -d postgres` пересоздаёт контейнер (изменился `command`/`shm_size`) — это несколько секунд простоя БД. pgbouncer переподключится сам (`server_login_retry = 2` с; `server_lifetime = 3600` с касается только возраста соединений, не рестарта), но запросы в полёте и всё, что придёт за эти секунды, получат ошибки соединения (500, `/health/ready` — 503), транзакция в полёте теряется. Делайте в тихий час. Обычный `deploy.sh` делает то же самое, если `command` в compose изменился.

### Откат

Откатываем код на предыдущий проверенный SHA (смотрите `git log` или предыдущий успешный запуск Actions):

```bash
cd /var/www/lexiflow
bash deploy.sh --rollback <prev_sha>   # или ROLLBACK=1 bash deploy.sh <prev_sha>
```

Без `--rollback` обычный деплой по SHA идёт только вперёд: если `HEAD` не является предком указанного SHA (например, запуск Actions для старого коммита закончился позже нового), скрипт печатает «SHA ... older than the current HEAD: skipping» и завершается с кодом 0. Флаг `--rollback` пропускает эту проверку. Скрипт заново чекаутит коммит, пересобирает образы, перезапускает стек и прогоняет те же smoke-проверки. Миграции автоматически **не** откатываются, поэтому каждая миграция должна быть обратно совместимой со старым кодом (expand/contract): в релизе N только добавляем (столбцы nullable/с default, новые таблицы, индексы), в релизе N+1 или позже, когда старый код уже не нужен, удаляем/переименовываем. Миграции с `--skip-migrate` для отката не нужны, но и не мешают. То же для JSON-данных: снимок практики пишет `lesson_title` строкой (для кода до R3c) рядом с картой `lesson_titles`; строку убрать в одном из следующих релизов (docs/16 §13). Если откат невозможен из-за уже применённой несовместимой миграции — чините вперёд новой миграцией, а не `migrate:rollback` в продакшене.

### Обновление PostgreSQL 16 → 18 (Docker)

**Когда.** Один раз — при первом деплое коммита, в котором `docker-compose.prod.yml` перешёл на `postgres:18-alpine`. Образы 18+ хранят кластер в `/var/lib/postgresql/<major>/docker` и монтируют один том в `/var/lib/postgresql` — в compose это том `postgresql` (без номера версии в имени: версия и так в подкаталоге, будущий переход на 19 пойдёт внутри того же тома). Данные 16 лежат в прежнем томе `lexiflow_pgdata` (монтировался в `/var/lib/postgresql/data`). Файлы кластера 16 сервер 18 не читает, поэтому данные переносятся dump/restore официальными образами `postgres:16-alpine` и `postgres:18-alpine` — без сторонних образов. `pg_upgrade` не используется: в одном официальном образе нет бинарников двух версий, а на alpine/musl он к тому же наследует старые индексы с их collation; dump/restore перестраивает индексы заново, база невелика (минуты), старый том остаётся нетронутым.

**Простой.** На время переноса остановлены все сервисы стека, кроме `postgres` и `redis`: `app`, `queue-high`, `queue-default`, `scheduler`, `nginx`, `certbot`. Для базы в сотни мегабайт это 1–3 минуты: на стенде 205 МБ / 500 тыс. строк восстановились за ~5 с, вся команда заняла около минуты (плюс скачивание `postgres:16-alpine`, если его нет локально). Очередь в Redis не теряется — воркеры доберут задания после запуска.

**Одна команда** (на сервере, из каталога проекта с уже сделанным чекаутом нового коммита):

```bash
cd /var/www/lexiflow
make prod-pg-upgrade        # = bash docker/postgres/upgrade.sh --prod
```

Если деплой из CI уже остановился на защите (см. ниже), чекаут нового коммита сделан: выполните команду и запустите деплой ещё раз — `bash deploy.sh <sha>` или перезапуск job в Actions.

**Что делает `docker/postgres/upgrade.sh`:**
1. читает `docker compose config`: имя проекта, образ `postgres`, том под `/var/lib/postgresql`, `POSTGRES_USER`/`POSTGRES_DB`; старый том находит по меткам compose (`<project>_pgdata`) — ничего не захардкожено, кроме прежнего ключа `pgdata`;
2. берёт блокировку `.deploy.lock`, общую с `deploy.sh` (см. «Защита в глубину»), и только под ней смотрит на тома и принимает решения (второй `make prod-pg-upgrade`, запущенный во время первого, дождётся его и увидит уже завершённый перенос — «Nothing to do»); проверяет свободное место (копия старого тома плюс восстановленная база — около двух размеров кластера 16; `PG_UPGRADE_SKIP_SPACE_CHECK=1` пропускает проверку с предупреждением); останавливает все сервисы, кроме `postgres` и `redis`, затем старый `postgres`; если кластер 18 уже существует (прерванный запуск, `--redo`), первой строкой его `pg_hba.conf` ставится шлагбаум (см. шаг 5) ещё здесь, одноразовым контейнером при остановленном сервере — чтобы любой старт этого кластера, пока перенос не сверен, отвергал сетевые подключения;
3. пишет в корень нового тома (compose создаёт его со своими метками) маркер `lexiflow-upgrade.in-progress` со списком остановленных сервисов — ещё **до** инициализации кластера; пока маркер есть, `deploy.sh` стек не запустит;
4. снимает отпечаток старого кластера — md5 файла `global/pg_control` (сервер 16 переписывает его при каждом запуске и каждой контрольной точке);
5. инициализирует кластер 18 на новом томе (пустая база из `.env`) и первой строкой `pg_hba.conf` ставит `host all all all reject` — «шлагбаум»: до конца сверки сервер 18 отвечает только по unix-сокету внутри контейнера, по сети его не получает ни приложение, ни `php artisan migrate`;
6. копирует старый том во временный `<project>_pgupgrade_src` (сам старый том смонтирован только для чтения и сервером не открывается) и запускает на копии `postgres:16-alpine` без сети — только unix-сокет;
7. `pg_dump` версии 18 → `pg_restore --single-transaction --exit-on-error`: база либо восстановлена целиком, либо остаётся пустой, поэтому повтор безопасен;
8. сверяет точные `count(*)` по всем таблицам, `last_value` всех последовательностей, списки индексов и ограничений; при расхождении останавливается и печатает diff;
9. `vacuumdb --analyze-in-stages`, пишет маркер `lexiflow-upgrade.done` с отпечатком старого кластера (md5 `pg_control`, system identifier, `NextXID`/`NextOID`, время контрольной точки из `pg_controldata` 16), сразу переписывает `in-progress` как обычное «ожидается старт сервисов» (`redo=0`) и только после этого убирает шлагбаум из `pg_hba.conf` (`pg_reload_conf()` и проверка настоящим TCP-подключением), удаляет временные контейнер и тома; маркеры всегда пишутся во временный файл рядом и переименовываются — наполовину записанного маркера никто не увидит;
10. запускает остановленные сервисы (список лежит в маркерах, поэтому повтор после сбоя на любом шаге — даже до инициализации — поднимет те же сервисы) и снимает маркер `in-progress`.

Повторный запуск после успеха — no-op («Nothing to do»). После сбоя на любом шаге — запустите ту же команду, она доведёт дело до конца: уже восстановленную базу сверит, а не восстановит заново; если сорвался только последний шаг (запуск сервисов), повтор запустит их и снимет маркер. В этом состоянии (`done` + `in-progress` с `redo=0`) `make prod-pg-check` отвечает `OK` — деплой поднимет весь стек сам, но маркер снимет только `make prod-pg-upgrade` (без флагов, даже если сорвался `--redo`: решение уже записано); пока маркер висит, guard контейнера `postgres` (слой 4 ниже) продолжает сверять отпечаток — такой маркер его не отключает. Старый том ни один шаг не изменяет и не удаляет. `make prod-pg-check` показывает состояние томов, ничего не меняя и не беря блокировку — им можно смотреть и на идущий перенос.

Все сценарии — данные 16 в старой раскладке, защитное монтирование, перенос, прерывание до и после инициализации кластера, откат на 16 (контейнер `postgres` нового compose отказывается стартовать на устаревшем снимке), `--redo`/`--keep-current`, прерванный `--redo` и выход из него через `--keep-current` (в том числе прерванный на полпути), шлагбаум, закрытый до маркера при ручном `up -d`, отказ `--redo` без `--force` и `--keep-current` при работающей 16, убитая 16, повторный `--redo` после прерванного восстановления, сбой на последнем шаге и висящий маркер, свежая установка, блокировка — прогоняются на настоящем Docker в CI: job `postgres-upgrade` (`docker/postgres/tests/run.sh`, только официальные образы, образ приложения не собирается, около десяти минут). Job `deploy` ждёт его: без зелёных сценариев деплой не уходит.

**Проверить результат:**

```bash
make prod-pg-check      # ==> OK: PostgreSQL 18 data is in place; the legacy PostgreSQL 16 volume lexiflow_pgdata is the rollback copy
docker compose -f docker-compose.prod.yml --env-file .env exec postgres cat /var/lib/postgresql/lexiflow-upgrade.done
# переменных .env в шелле оператора нет — берём их из окружения контейнера и из самого файла:
docker compose -f docker-compose.prod.yml --env-file .env exec postgres sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "select version()"'
curl -fsS "https://$(sed -n 's/^APP_DOMAIN=//p' .env)/health/ready"
```

Дальше — обычный деплой с его smoke-проверками. Первый `make backup` после апгрейда покажет, что `pg_dump` в образе приложения не старше сервера (нужен клиент PostgreSQL 18).

**Если задеплоить без апгрейда.** `deploy.sh` ещё до `build` и до любого `up` вызывает `bash docker/postgres/upgrade.sh --prod --check`. Если в старом томе есть кластер 16, а нового тома нет или в нём нет кластера, деплой завершается с кодом 1 и сообщением `STOP: the PostgreSQL 16 data in volume lexiflow_pgdata has not been moved to the 18+ layout ...` и командой апгрейда; работающий старый стек не трогается — образы не пересобраны, контейнеры не пересозданы. Та же проверка останавливает деплой: при незавершённом апгрейде (маркер `in-progress`); когда кластер 18 уже есть, а маркера `done` нет — прерванный перенос или кластер, созданный вручную: база там пустая или неполная (`STOP: … was never completed`); при несовпадении мажора кластера в томе с мажором образа; когда старый кластер 16 запускался после апгрейда (`STOP: … may be STALE`, см. «Откат и его цена»). Автоматического апгрейда в CI нет намеренно: это одноразовая операция с простоем, её запускает владелец и смотрит на вывод. Свежая установка без единого тома проверку проходит, стек инициализирует пустой кластер 18.

**Первый деплой после слияния и ручной деплой.** bash читает скрипт по мере выполнения, поэтому `git checkout` внутри `deploy.sh` не подменяет уже запущенный файл: старый `deploy.sh` доработал бы до конца без проверки выше. Поэтому шаг deploy в `.github/workflows/ci.yml` выполняет `deploy.sh` именно целевого коммита — `git show "$DEPLOY_SHA:deploy.sh" > /tmp/… && bash /tmp/… "$DEPLOY_SHA"`, а не `bash deploy.sh` из текущего чекаута; workflow берётся из нового коммита, так что это работает уже на первом деплое. При ручном деплое делайте так же:

```bash
cd /var/www/lexiflow && git fetch origin
tmp=$(mktemp) && git show "<sha>:deploy.sh" > "$tmp" && bash "$tmp" "<sha>"; rm -f "$tmp"
```

**Защита в глубину.** Четыре слоя.

1. *Защитное монтирование.* На случай, если кто-то всё же запустит старый `deploy.sh` из старого чекаута (`bash deploy.sh <sha>` без `git show`) или просто `docker compose -f docker-compose.prod.yml --env-file .env up -d`: compose монтирует старый том ещё и в контейнер `postgres` — только для чтения, в `/var/lib/postgresql/16/docker`, то есть туда, где entrypoint образов 18+ ищет неперенесённые кластеры. Пока в старом томе лежит кластер 16, а кластера 18 ещё нет, контейнер `postgres` отказывается инициализировать пустую базу и завершается (`docker compose logs postgres`: `Error: in 18+, these Docker images are configured to store database data … there appears to be PostgreSQL data in: /var/lib/postgresql/16/docker`), `app` без здорового `postgres` не стартует, старый `deploy.sh` падает на миграциях. Это простой, а не приложение на пустой БД. Выход тот же: `make prod-pg-upgrade` (скрипт сам инициализирует кластер 18 рядом с этим монтированием), затем деплой заново. Пустой или отсутствующий старый том монтированию не мешает — свежая установка работает как обычно.
2. *Шлагбаум в `pg_hba.conf`.* Монтирование не спасает, когда кластер 18 уже создан, а перенос не завершён (прерванный `make prod-pg-upgrade`): entrypoint видит `PG_VERSION` и просто стартует. Поэтому, пока данные не сверены, первой строкой `pg_hba.conf` кластера 18 стоит `host all all all reject`: ручной `up -d` поднимет `postgres`, но приложение получит `FATAL: pg_hba.conf rejects connection`, `php artisan migrate --force` не создаст пустую базу, а healthcheck, `compose exec psql` и сам скрипт работают через unix-сокет. Новому кластеру шлагбаум ставит `initdb`-шаг; существующему (прерванный запуск, `--redo`) скрипт вписывает его в `pg_hba.conf` одноразовым контейнером при остановленном сервере — **до** маркера `in-progress`, так что прерывание сразу после маркера (когда маркер `redo=1` пропускает старт через guard) всё равно оставляет кластер закрытым для сети. Шлагбаум снимается на шаге 9 последним — после маркеров `done` и `redo=0`: прерывание в любой точке оставляет либо закрытый шлагбаум рядом с маркером `done` (его открывает любой следующий запуск скрипта), либо уже открытый, но со сверенными и записанными данными; `make prod-pg-check` показывает закрытый шлагбаум как `network gate: CLOSED`. Если прерванный запуск оставил шлагбаум закрытым при уже сверенных данных (маркер `done` есть), `--check` отвечает `STOP: the network gate … is CLOSED` — иначе прод остался бы без БД при зелёной проверке; `make prod-pg-upgrade` в этом состоянии поднимает `postgres`, открывает шлагбаум и запускает остановленные сервисы: данные были сверены, когда писался `done`.
3. *Одна блокировка на деплой и перенос.* `deploy.sh` и `upgrade.sh` берут `flock` на файл `.deploy.lock` в каталоге проекта (в `.gitignore`): деплой не начнётся во время переноса, перенос — во время деплоя, два деплоя не пересекутся, кто бы их ни запустил. Второй процесс печатает, кто держит блокировку (`pid=… started=… command=…` — содержимое файла), ждёт до `DEPLOY_LOCK_WAIT` секунд (по умолчанию 300) и завершается с ошибкой. `--check` блокировку не берёт: внутри `deploy.sh` он идёт под блокировкой самого деплоя, а `make prod-pg-check` можно запускать когда угодно. Файл создаёт тот, кто запустил первым (root через `sudo` или пользователь деплоя из CI), с правами 666, чтобы второму он тоже открылся; если деплой падает с `cannot open the lock file .deploy.lock (owner root …)` — файл остался от старой версии скрипта, `sudo chmod 666 .deploy.lock`. Файл руками не удалять.
4. *Guard в entrypoint `postgres`.* Контейнер `postgres` в обоих compose стартует через обёртку `docker/postgres/entrypoint-guard.sh` (bind-mount только для чтения, `entrypoint: ["/bin/sh", …]`, явная `command: ["postgres"]`, дальше — официальный `docker-entrypoint.sh`). Если в маркере `done` записан отпечаток кластера 16, старый том всё ещё содержит `global/pg_control`, его md5 другой, а маркера `in-progress` с `redo=1` нет — контейнер пишет в лог `lexiflow-upgrade guard: refusing to start PostgreSQL 18 - its data may be STALE` с командами `--redo`/`--keep-current` и тем, как потом поднять стек (`LEXIFLOW_UPGRADE_CMD` и `LEXIFLOW_UP_CMD` из compose: в проде `bash docker/postgres/upgrade.sh --prod` и `bash deploy.sh <sha>`), и завершается с кодом 1 (в проде с `restart: unless-stopped` — перезапускается и отказывает снова, пока владелец не решит). Healthcheck не проходит, `app` ждёт здоровый `postgres`: простой, а не приложение на устаревшем снимке. Это ловит всё, что обходит `deploy.sh` и его `--check`: старый `deploy.sh` или `make deploy` из чекаута, где проверки ещё нет, `make prod-up`, ручной `up -d` после `STOP: … may be STALE`. Контейнеры самого `upgrade.sh` запускаются с `--entrypoint` и под guard не попадают; `--redo` пишет `in-progress` с `redo=1` до старта `postgres` (и закрывает шлагбаум ещё раньше), `--keep-current` переписывает отпечаток до того, как что-либо стартует, завершённый `--redo` переписывает отпечаток до того, как его маркер становится `redo=0`, — поэтому все старты скрипта проходят. Маркер с `redo=0` (сервисы не стартовали) проверку **не** снимает: иначе забытый маркер отключил бы guard навсегда.

Остаточный риск: ручной `up -d` в те несколько секунд, пока `initdb` создаёт кластер 18 (второй postmaster из другого контейнера), — повредить он может только новый пустой кластер, старый том скрипт не пишет никогда; сверка на шаге 8 такое заметит.

*Рабочее дерево при сбое деплоя.* Compose монтирует в контейнеры файлы рабочего дерева (guard в `postgres`, `docker/nginx/prod.conf` в `nginx`), и перезапуск контейнера перечитывает эти монтирования. Поэтому `deploy.sh`, остановившись **после** чекаута, но **до** того, как тронул стек (STOP проверки пустого старого тома при откате, падение сборки), возвращает дерево на прежний коммит — иначе откат на коммит без `docker/postgres/entrypoint-guard.sh` оставил бы дерево без файла, а работающий `postgres` 18 падал бы при каждом перезапуске (Docker создаёт на месте пропавшего источника каталог). Два исключения: после первого `up` дерево остаётся с контейнерами; после `STOP` от `upgrade.sh --check` чекаут нового коммита сохраняется — именно из него запускают апгрейд или `--redo`/`--keep-current`.

**Откат и его цена.** `bash deploy.sh --rollback <prev_sha>` на коммит с `postgres:16-alpine` возвращает старый compose: контейнер `postgres` пересоздаётся на томе `lexiflow_pgdata` **на запись**, данные 16 — в состоянии на момент апгрейда. В таком коммите нет `docker/postgres/upgrade.sh`; `deploy.sh` пишет `No docker/postgres/upgrade.sh in this commit (PostgreSQL 16 layout): skipping the data volume check` и предупреждает, что с этого момента копии расходятся. Всё, что записано в PostgreSQL 18 после апгрейда, на 16 не попадёт (обратного переноса нет). Том `lexiflow_postgresql` при откате не удаляется. Если старый том к этому моменту уже удалён (см. «Когда удалить старый том»), откат останавливается: `deploy.sh` того чекаута, из которого запущен откат, проверяет старым compose, что в томе есть `PG_VERSION`, и на пустом томе отвечает `STOP: the legacy PostgreSQL volume holds no cluster` — иначе 16 инициализировала бы пустой кластер, а миграции собрали бы на нём пустую базу.

Любой запуск 16 на старом томе — откат через `deploy.sh`, ручной `docker compose up` из старого чекаута, даже «посмотреть одним глазом» — меняет `global/pg_control`, и следующий деплой вперёд остановится: `--check` сравнит отпечаток из маркера `done` с текущим и ответит `STOP: … ran again after the upgrade … may be STALE`. Минуя `deploy.sh` — тоже нельзя: контейнер `postgres` нового compose при старте сверяет тот же отпечаток (guard в entrypoint, слой 4 выше) и не стартует 18 на устаревшем снимке; `docker compose -f docker-compose.prod.yml --env-file .env logs postgres` покажет причину и те же команды. Решение принимает владелец, слияния двух веток нет; кластер 16 при этом должен быть остановлен (после отката это контейнер `lexiflow-postgres` старого compose: `docker stop lexiflow-postgres`):

```bash
bash docker/postgres/upgrade.sh --prod                  # показать, что изменилось на 16: NextXID/NextOID (были ли транзакции) или только запуск
bash docker/postgres/upgrade.sh --prod --redo           # правда — на 16: база 18 переименовывается в lexiflow_stale_<время> (не удаляется), данные 16
                                                        # переносятся заново; то, что приложение записало в 18 после апгрейда и до отката, остаётся
                                                        # только в этой копии — изучить, затем `… exec postgres dropdb -U <user> lexiflow_stale_<время>`
bash docker/postgres/upgrade.sh --prod --redo --force   # то же, когда 16 только запускалась без записей или в старом томе другой (переинициализированный)
                                                        # кластер: без --force такой --redo отказывает, он заменил бы живую базу 18 устаревшими/пустыми данными
bash docker/postgres/upgrade.sh --prod --keep-current   # правда — на 18: данные 18 остаются, в маркер записывается новый отпечаток 16; то, что 16
                                                        # записала после апгрейда, остаётся только в старом томе
```

Затем деплой заново (после `--keep-current` контейнер `postgres`, остановленный guard'ом, в проде перезапустится сам; в dev — `docker compose up -d`). Отказы, которые защищают рабочую базу 18 от подмены: `--redo` на неизменившемся кластере 16 («nothing is stale»); `--redo`, когда 16 только запускалась и ничего не записала (NextXID/NextOID не сдвинулись), когда в старом томе другой, переинициализированный кластер (сменился system identifier — например, том удалили, а откат создал пустой) или когда 16 выключена нечисто (`Database cluster state` ≠ `shut down`: убита OOM или `docker rm -f`, и `pg_controldata` не может сказать, были ли записи после последней контрольной точки) — все три могли бы заменить живую базу 18 её же старым снимком или пустой базой, поэтому требуют `--redo --force` (копия 16 при этом сама доигрывает свой WAL); `--redo` и `--keep-current`, пока 16 работает (есть контейнер, держащий старый том на запись) — остановите её и повторите. Нечисто выключенная, но никем не запущенная 16 для `--keep-current` не помеха: кластер неподвижен, он записывается как есть с предупреждением; если 16 когда-нибудь запустить снова, отпечаток сменится и проверки остановят деплой ещё раз. Прерванный `--redo` оставляет маркер `in-progress` с `redo=1`, маркер `done` при этом сохраняется (история сверенного переноса): `--check` отвечает `STOP: a --redo … is in progress or was interrupted`, а дальше либо `--redo` доводит перенос (уже отложенная база и её имя в маркере не теряются: если восстановление успело завершиться, несверенная копия удаляется и восстанавливается заново, второй раз ничего не откладывается), либо `--keep-current` возвращает стек к данным 18 — открывает шлагбаум, поднимает остановленные сервисы, снимает маркер. Второе возможно, пока прерванный `--redo` не успел переименовать базу (`stale_db=` в маркере пишется перед переименованием): иначе скрипт откажет и скажет, как вернуть отложенную базу на место вручную (ничего не удаляется); если базу нельзя проверить (например, `psql` не подключается), скрипт тоже откажет, а не примет ошибку за ответ. Если `--redo` сорвался уже на запуске сервисов (данные сверены, `done` переписан), маркер становится `redo=0`: `--check` отвечает `OK`, а `make prod-pg-upgrade` без флагов поднимает сервисы и снимает маркер. Если старый том к этому времени удалён, `--redo`/`--keep-current` откажут (нечего копировать и сравнивать) — `--check` предлагает тот же запуск без флагов. Отложенные базы `lexiflow_stale_<время>` `make prod-pg-check` перечисляет строкой `databases set aside by --redo`. Тот же `--redo` — выход, если на пустой 18 успели прогнать миграции и сверка показала diff (раньше здесь предлагался `dropdb`).

*Redis.* Образ — точный тег `redis:8.10-alpine` (решение владельца 02.11.2026, `docs/19_REDIS_CACHE_PLAN.md` §1), не плавающий `redis:8-alpine`: образ воспроизводим, обновление делается осознанно. 8.10 пишет RDB формата 15 — том `redisdata`, записанный 8.10, на 8.4 уже не поднимется (приложение не запущено, откат на старые Redis не нужен). Тесты в CI (job `test`) идут на той же версии.

**Два экземпляра Redis** (`docs/19` §4; выполнено в C2, 02.11.2026):

| Сервис | Для чего | Политика | Диск | Laravel |
|---|---|---|---|---|
| `redis` | очереди, сессии, блокировки, лимиты (`RateLimiter`), маркеры «уже отправлено», защита 2FA, ключи версий entitlement, пульсы `/health/ready` | `noeviction` — зашито в compose, переменной нет: ничего отсюда не должно пропасть из-за нехватки памяти | AOF `everysec`, том `redisdata` | подключения `default`/`cache`/`queue`/`session`/`locks`; `CACHE_STORE=redis`, `CACHE_LIMITER_STORE=redis` |
| `redis-cache` | чистый кэш: каталог, таблицы лиг, агрегаты виджетов, снимки entitlement — всё, что пересчитывается из базы | `volatile-lru`: при нехватке памяти вытесняются ключи с TTL (у чистого кэша он есть всегда) | нет (`--appendonly no --save ""`): после рестарта пусто, это нормально | подключение `lru` (`REDIS_LRU_HOST/PORT/PASSWORD/DB`), хранилище `lru`, `CACHE_PURE_STORE=lru`; код берёт его только через `App\Domain\Operations\Support\PureCache::store()` |

- **Пароли.** В проде обязательны оба: `REDIS_PASSWORD` и `REDIS_CACHE_PASSWORD` (`openssl rand -hex 32`). `docker-compose.prod.yml` без них не стартует: `required variable REDIS_PASSWORD is missing a value: …`. Контейнерам приложения compose сам подставляет `REDIS_LRU_HOST=redis-cache`, `REDIS_LRU_PORT=6379` и `REDIS_LRU_PASSWORD=$REDIS_CACHE_PASSWORD` (`environment` сильнее `env_file`), так что в Docker строки `REDIS_LRU_*` из `.env` внутри контейнеров не действуют; на голом сервере их задают руками (§4, VPS-гайд §5). В деве пароль по желанию: пустое значение = без пароля; слова `null`, `(null)`, `empty`, `(empty)` в любом регистре (старые `.env`) оба контейнера тоже считают отсутствием пароля — как Laravel, чей `env()` читает их как PHP null или `''` и не шлёт AUTH; в проде `deploy.sh` с таким значением (в окружении shell или в `.env`) останавливается до сборки и `up`, а контейнер, запущенный мимо него, не стартует и пишет причину в свой лог (`docker compose logs redis`). Пароль только из hex-символов: он попадает в конфиг Redis строкой `requirepass …` без кавычек.
- **Где пароль виден.** `redis-server` получает его строкой конфига через stdin, а не аргументом, и `redis-cli` в healthcheck — через `REDISCLI_AUTH`: в `ps`, `/proc/*/cmdline` и `docker compose ps` пароля нет. Но он остаётся в окружении контейнера (`docker inspect lexiflow-redis` → `Config.Env`) и в `.env` на хосте — как `DB_PASSWORD`. Компромисс принят: иначе нужен отдельный файл конфига или Docker secrets ради секрета, который и так читается всеми, кто может выполнять `docker` на сервере (`docker` = root). Защита — права на `.env` (`chmod 600`) и круг людей с доступом к Docker.
- **Размер.** `REDIS_MAXMEMORY` (первый, 256 МБ) и `REDIS_CACHE_MAXMEMORY` (второй, 256 МБ; уточняется по замерам C8 в `docs/19` §8). Заполненный первый экземпляр при `noeviction` отвечает `OOM command not allowed` на запись: очередь и сессии встают — это сигнал увеличить `REDIS_MAXMEMORY` и искать, кто пишет без TTL, а не включать вытеснение.
- **Мониторинг.** Раз в минуту с обоих экземпляров (`docker compose exec redis sh -c 'REDISCLI_AUTH=$REDIS_PASSWORD redis-cli INFO'`, то же для `redis-cache`): `used_memory` и `maxmemory`, `evicted_keys`, `keyspace_hits` / `keyspace_misses` (hit ratio = hits / (hits + misses)), `rejected_connections`. Алерты: `used_memory` первого экземпляра растёт неделю подряд или выше 80 % `maxmemory` — ищем ключи без TTL (`redis-cli --bigkeys`, `SCAN` + `TTL`); `evicted_keys` второго растёт быстрее, чем пополняется кэш, или hit ratio ниже ~0.8 — поднимаем `REDIS_CACHE_MAXMEMORY` (`docker compose up -d redis-cache` применит); `evicted_keys` первого экземпляра всегда 0 — иначе политика не `noeviction`. `/health/ready` показывает оба экземпляра отдельно: `redis` и `redis_cache` (§15.2).
- **Деградация.** Хранилище чистого кэша `lru` — цепочка `failover` → `array` с размыкателем (`docs/19` §4.1): при недоступном или зависшем `redis-cache` первый неудачный вызов (отказ сразу, на зависшем — таймаут 0,25 с) размыкает цепь на 15 с, и все процессы хоста (маркер — файл `storage/framework/cache/circuits/lru_redis` на общем томе) считают из базы без попыток соединения; раз в окно один процесс пробует снова. В лог пишется `cache.failed_over` (warning) на каждое размыкание — одна строка на окно на весь хост, не на запрос — и `cache.recovered` (info), когда экземпляр снова отвечает. `/health/ready` отвечает `200` со `status: degraded` и `checks.redis_cache: degraded`; контейнеры приложения не ждут `redis-cache` (`depends_on: service_started`). Деплой этим не блокируется — smoke-проверка `deploy.sh` принимает `200`, но печатает `WARNING` с телом ответа, если в нём `degraded`. Пока кэш лежит, растёт нагрузка на PostgreSQL: алерт на `degraded` дольше 10 минут (§15.3). Вмешательство не нужно: после починки экземпляра цепь замкнётся сама не позже чем через 15 с (первая удачная проба). Если нужно немедленно — удалить маркер в любом контейнере приложения: `docker compose exec app rm -f storage/framework/cache/circuits/lru_redis`.
- **Голый сервер без Docker:** второй `redis-server` на `127.0.0.1:6380`, `docs/VPS_DEPLOY_GUIDE_RU.md` §5.

**Когда удалить старый том.** Когда 18 поработала достаточно (например, неделю), есть свежий бэкап и откат больше не нужен. Том держит контейнер `postgres` (защитное монтирование только для чтения — и остановленный контейнер тоже держит), поэтому `docker volume rm` сразу ответит «volume is in use»; сначала убрать контейнер:

```bash
cd /var/www/lexiflow
docker compose -f docker-compose.prod.yml --env-file .env rm -sf postgres   # остановить и удалить контейнер postgres (приложение переподключится через секунды)
docker volume rm lexiflow_pgdata                                           # точное имя печатает make prod-pg-check
docker compose -f docker-compose.prod.yml --env-file .env up -d            # postgres пересоздаётся; compose заведёт пустой lexiflow_pgdata — это безвредно
```

Скрипты этого не делают никогда. Пустой том entrypoint не смущает, `make prod-pg-check` отвечает `OK` уже без «rollback copy». Внимание: из-за защитного монтирования том `pgdata` объявлен в compose, поэтому `docker compose down -v` удалит и его (как и все остальные данные стека). Само монтирование уберём из compose в одном из следующих релизов.

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
REDIS_PASSWORD=<openssl rand -hex 32>
REDIS_DB=0
REDIS_CACHE_DB=1
REDIS_QUEUE_CONNECTION=queue
REDIS_QUEUE_DB=2
REDIS_SESSION_DB=3
REDIS_LOCK_DB=4
REDIS_QUEUE_BLOCK_FOR=5
REDIS_MAXMEMORY=256mb
# The second instance (pure cache, docs/19 §4): Docker - service redis-cache; bare server - 127.0.0.1:6380.
REDIS_LRU_HOST=127.0.0.1
REDIS_LRU_PORT=6380
REDIS_LRU_PASSWORD=<openssl rand -hex 32>
REDIS_LRU_DB=0
REDIS_CACHE_PASSWORD=<Docker only: what docker-compose.prod.yml gives redis-cache and the app containers as REDIS_LRU_PASSWORD>
REDIS_CACHE_MAXMEMORY=256mb
CACHE_STORE=redis
CACHE_LIMITER_STORE=redis
CACHE_PURE_STORE=lru
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

> **Index migrations with `CONCURRENTLY`** (`2026_10_28_000001`, `2026_10_28_200001`, `2026_10_28_300001`,
> `2026_11_02_100001`, `2026_11_02_200001`): `CREATE INDEX CONCURRENTLY` / `DROP INDEX CONCURRENTLY` wait for the
> transactions that may still use the table the old way — a build waits for every transaction of the **database** whose
> snapshot is older than its own, not only for those on that table. If `php artisan migrate --force` hangs during a deploy,
> find what blocks it instead of ending every idle session:
> `select pid, pg_blocking_pids(pid) as blocked_by, left(query, 60) from pg_stat_activity where query ilike '%concurrently%'`,
> then look at those pids (`select pid, state, xact_start, left(query, 80) from pg_stat_activity where pid = any('{…}')`)
> and end the stuck one — usually an `idle in transaction` session — with `select pg_terminate_backend(<pid>)`; the build
> then goes on by itself. Only an interrupted **migration** (Ctrl+C, a killed container, a lost connection) leaves an
> INVALID index behind; running the migration again drops it first and rebuilds.

---

## 6. Build the Frontend (TWA SPA)

If Node.js 26 LTS (22.12+ is enough) is available on the server:
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
php artisan filament:optimize   # components of /admin and /org + Blade icons (docs/20 §1.12); production only
php artisan event:cache
```

To clear all caches (e.g. after a config change):
```bash
php artisan optimize:clear
php artisan filament:optimize-clear
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

    server_tokens off;
    add_header Strict-Transport-Security "max-age=15552000; includeSubDomains" always;
    add_header X-Content-Type-Options "nosniff" always;   # only nginx sets HSTS/nosniff: PHP locations hide the copies from Laravel

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
        # add_header here drops the server-level ones: repeat HSTS and nosniff.
        add_header Strict-Transport-Security "max-age=15552000; includeSubDomains" always;
        add_header X-Content-Type-Options "nosniff" always;

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
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        fastcgi_hide_header Strict-Transport-Security;
        fastcgi_hide_header X-Content-Type-Options;
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
        # (php8.5-fpm.sock for PHP 8.5, php8.6-fpm.sock for 8.6, ...). Verify with: ls /run/php/
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        fastcgi_hide_header Strict-Transport-Security;
        fastcgi_hide_header X-Content-Type-Options;
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
command=/usr/bin/php /var/www/lexiflow/artisan queue:work redis --queue=default --tries=3 --timeout=120 --sleep=1 --max-time=3600
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

[program:lexiflow-worker-low]
process_name=%(program_name)s_%(process_num)02d
command=/usr/bin/php /var/www/lexiflow/artisan queue:work redis --queue=low --tries=3 --timeout=120 --sleep=1 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/lexiflow/storage/logs/worker-low.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=3
user=www-data

[group:lexiflow-worker]
programs=lexiflow-worker-high,lexiflow-worker-default,lexiflow-worker-low
```

Три пула: `high` (ответы бота, платежи) не стоит в очереди за bulk-задачами, а `low` (долгая TTS-озвучка) не голодает под непрерывным потоком `default` (воркер с `--queue=default,low` берёт `low` только когда `default` пуст). Группа `lexiflow-worker` сохраняет команды `supervisorctl ... lexiflow-worker:*`.

Pre-generated word audio (`PregenerateWordAudioJob`, see below) runs on the `low` queue, served by its own pool `lexiflow-worker-low` (`--queue=low`; in Docker the `queue-low` service). The job retries 5 times with backoff 30s / 2m / 10m / 30m.

For bigger classes, increase `numprocs` before adding app containers. With Redis queue and the current DB indexes, 2-3 workers are enough for roughly 300 active students/day unless Telegram API latency dominates.

The first Redis instance (queues, sessions, locks, limits) runs with `noeviction` and that is not configurable on purpose: an evicting policy there could drop pending jobs, locks or the login limits under memory pressure. The pure cache lives on the second instance (`redis-cache` / port 6380, `volatile-lru`), see §0 "Redis" and `docs/19` §4.

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

The phrases of the practice mode "Listen and write" (docs/16 §10) are voiced by `GeneratePracticeAudioJob` (queue `low`, 5 attempts, backoff 30 s / 2 m / 10 m / 30 m) into **`storage/app/practice-audio/{sha1}.mp3`** — the disk `practice_audio` of `config/filesystems.php` (a file is written to `.tmp/` first and moved, so a reader never sees half of an mp3). Unlike `storage/app/public/audio` this directory is **private: nginx does NOT serve it** (no `location`, no `storage:link` entry, the disk has no `url`); the files leave the server only through the JWT-guarded endpoints `GET /api/twa/practice/sessions/{id}/audio/{element}` and, for a battle, `GET /api/twa/practice/battles/{id}/audio/{element}` (the five clips of a dictation are loaded when the room is entered; a missing file voids the match — `docs/ADMIN_GUIDE.md`, *Battle 1-on-1*).

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
- `learners:purge-deleted` — daily at 03:30 (`withoutOverlapping`): learners soft-deleted more than `LEARNERS_PURGE_AFTER_DAYS` (30) days ago are deleted for good — personal and learning data go by FK cascade, payment records (`payment_orders`, `payments`, `provider_transactions`, `subscriptions`) stay whole with the payer snapshot (`payer_*`), see docs/03 §8 rule 2 and docs/07 §10. At most `LEARNERS_PURGE_MAX_PER_RUN` (500) learners per run, in batches of `LEARNERS_PURGE_BATCH` (50), one transaction per learner under a `Cache::lock`; a repeated or parallel run changes nothing. Prints `Learners purged: N` and logs `learners.purge_finished {purged, skipped, duration_ms}`; every deleted learner leaves an `audit_logs` row `learner.purged` (old id, `deleted_at`, no personal data). The Stars auto-renewal of a live subscription is canceled best-effort first (needs the bot token); a failure is logged (`learners.purge.stars_cancel_failed`) and does not stop the run.
- `training:close-expired` — every 15 minutes (expired personal / stale group training sessions).
- `practice:close-expired` — every 15 minutes (solo practice sessions past their `expires_at`, 24 h after the start, become `finished / expired` and release the learner's «one active session»; the event `practice.session_finished` is written once; a conditional UPDATE per session, so repeated or parallel runs are harmless; `withoutOverlapping`). A session that nobody closed is also closed by the learner's next request, so a late run costs nothing.
- `practice:battles-sweep` — every minute (`everyMinute()->withoutOverlapping()->runInBackground()`; the 1-on-1 battle, docs/16 §8.6, task 18.6). One run: (1) invitations past `invite_expires_at` (5 minutes) become `closed / invite_expired` — one conditional UPDATE per row, so an invitation accepted a moment earlier is left alone — and release both players' «one active battle»;
  (2) every match that is on (`room`, `countdown`, `playing`) is moved by the clock under `FOR UPDATE` of its own row, one transaction per match: a room past 2 minutes, a player silent for more than 30 s, the end of a round (deadline + 1.5 s grace) and of the review, the end of the match; (3) with `PRACTICE_ACCESS=off` the matches that are on are **voided** instead (`closed / voided`, `meta.voided_reason = practice_off`).
  Idempotent: a repeated or overlapping run changes nothing; it prints `Battles swept: N` (invitations closed + matches moved or voided). The command itself needs no queue worker (the DM that invites a friend does — §14.3). A request of either player moves the same match lazily, so a late run only delays things by a minute — but a match both players left is ended **only** by this command (about 1.5 minutes after they went),
  and without it the learners' «one active battle» stays taken until each of them looks at the match again. A scheduler that is down (`/health/ready` → `scheduler`) shows up first as matches stuck in `room` / `countdown` / `playing`.
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

# 2a. TWA API is served by the api container (401 JSON from Laravel, not 502/404), and that container has no panels
curl -s -o /dev/null -w '%{http_code}\n' https://yourdomain.com/api/twa/me        # -> 401
docker compose -f docker-compose.prod.yml --env-file .env exec app-api php artisan about | grep -i filament   # -> nothing

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
php artisan filament:optimize
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
php artisan filament:optimize   # optimize:clear dropped it
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
2. `php artisan config:cache`, then restart the queue workers (`supervisorctl restart lexiflow-worker:*`): the voicing job `GeneratePracticeAudioJob` runs on the `low` queue (the `low` worker, §10) and the DM that invites a friend to a battle (`SendBattleDmJob`) on the `default` queue; both need the new code.
3. The directory `storage/app/practice-audio` is created by the application on first use (private, §10.2); on a bare VPS it is in the `mkdir -p` list of the VPS guide §7.1. It is part of the backup (`backup:run`) next to `storage/app/public`.
4. `php artisan schedule:list` shows `practice:close-expired` (every 15 minutes) and `practice:battles-sweep` (every minute, §11). `/health/ready` needs no change: a dead scheduler already fails its `scheduler` check, and the battle sweeper rides on the same cron entry.
5. Turn it on in steps (`docs/ADMIN_GUIDE.md`, «Practice tasks»): import the pilot package as drafts (`php artisan practice:import database/data/practice/pilot-a1-a2.json`), check and publish the tasks in the admin, wait until the column «Аудио» of the dictations says «Готово» (the `tts` service and a queue worker must be up), then `PRACTICE_ACCESS=pilot` + `php artisan config:cache`, put a few learners into the pilot, and later `all`.
6. The 1-on-1 battle comes with the practice (`PRACTICE_BATTLE` defaults to `true`; it is reachable only where `PRACTICE_ACCESS` lets the learners in) and needs nothing else on the server except what steps 2–4 name: `TWA_BASE_URL` must be the public https address (the button of the invitation DM opens `${TWA_BASE_URL}/twa/battles/{id}`), the bot token must work (the DM), and the scheduler must run every minute.
   Smoke check with two test learners who are friends: invite → the DM arrives with «Открыть баттл» → accept → both press «Готов» → one round; then `php artisan practice:battles-sweep` run twice in a row ends with `Battles swept: 0`.
7. Rollback: `PRACTICE_ACCESS=off` + `config:cache` hides the practice at once (the entries disappear from `/me`, the endpoints answer 403) and within a minute the sweeper voids the battles that are on; the data stays. `PRACTICE_BATTLE=false` stops only new invitations and acceptances — matches that are on are played to the end. The migrations are reversible (`down()`), but do not roll them back on a live system.

   Stuck battles are visible in the database: a read-only query for matches that did not move although the scheduler should have moved them (a healthy scheduler leaves no rows; more than a few minutes of rows means `practice:battles-sweep` does not run):

   ```sql
   SELECT id, status, end_reason, invite_expires_at, room_expires_at, updated_at
   FROM battles
   WHERE (status = 'invited' AND invite_expires_at < now() - interval '2 minutes')
      OR (status IN ('room', 'countdown', 'playing') AND updated_at < now() - interval '5 minutes');
   ```

   Do not repair such rows by hand: run `php artisan practice:battles-sweep` (idempotent) and look at the scheduler. The `meta` keys and the columns are in `docs/03` §2.45–§2.47.

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
| `db` | `select 1` throws - on the application's own connection, i.e. through pgbouncer in the Docker stack (§0 «pgbouncer и постоянные соединения»): a pooler that is down or cannot reach PostgreSQL is a `fail` here, as it is for every request |
| `redis` | `PING` of the first instance fails (only reported if cache or queue use the `redis` driver) |
| `redis_cache` | `PING` of the second instance (the pure cache connection `lru`) fails → the value is `degraded`, not `fail`, as long as the pure store has a fallback: the chain goes on to the array store and the application serves from the database (`docs/19` §4.1). With a pure store on Redis alone (`CACHE_PURE_STORE=lru_redis`) every cache call would throw, so the value is `fail`. Only reported when `CACHE_PURE_STORE` is (a failover chain over) a Redis store on a connection of its own |
| `scheduler` | no scheduler heartbeat for more than 3 minutes (`READINESS_HEARTBEAT_MAX_AGE`, seconds) |
| `queue` | backlog of the `high` (`TELEGRAM_QUEUE`) or `default` queue exceeds `READINESS_QUEUE_MAX_SIZE` (1000), **or** the worker heartbeat of `high` / `low` is missing or older than 5 minutes (`READINESS_QUEUE_HEARTBEAT_MAX_AGE`, seconds; not checked with the `sync`/`null` driver) |

Every response also carries a top-level `surface` (`full` | `api`, `App\Support\Surface::current()`): which php-fpm container answered (`app` or `app-api`, §0 «Две поверхности приложения»).

Response: `200 {"status":"ok","checks":{"db":"ok",...}}`, `200 {"status":"degraded","checks":{...,"redis_cache":"degraded"}}` (a dependency the application works without is down; `deploy.sh` and uptime monitors accept it, a lasting `degraded` is an alert of its own in §15.3) or `503 {"status":"fail","checks":{"db":"fail",...}}`.
Only `ok`/`degraded`/`fail` is returned; error details, hosts and versions are written to the application log
(`readiness.check_failed`), never to the response. The route is throttled (60 req/min per IP) and stateless.

The scheduler heartbeat is written by a scheduled callback (`ops:scheduler-heartbeat`, every minute, see
`routes/console.php`) into the cache. After the very first deploy the check turns green within a minute.

Worker heartbeat: every minute the scheduler dispatches `QueueHeartbeatJob` to the `high` and `low` queues (`operations.readiness.heartbeat_queues`; unique per queue, so a dead worker does not accumulate jobs). A worker that really consumes the queue writes `queue:heartbeat:{queue}` to the cache; a stale value means workers are stopped or the queue is stuck behind a backlog. The age of the oldest ready job is deliberately not measured: the heartbeat job sits at the tail of the queue, so a long backlog already shows up as a stale heartbeat, and reading Redis payloads would add driver-specific code.

### 15.3 What to monitor and alert on

| Signal | Alert when |
|---|---|
| `GET /health/ready` (every 1 min) | 2 consecutive non-200 responses; the `checks` body tells which dependency failed (`redis` and `redis_cache` are separate) |
| `GET /health/ready` body `status: degraded` | longer than 10 minutes: the second Redis instance is down or hung and the application computes from the database (slower, more load on PostgreSQL) — `docker compose ps redis-cache` and its log; `cache.failed_over` in the application log says when it started (one line per 15 s window while it lasts), `cache.recovered` when it ended; `deploy.sh` prints a WARNING when the smoke check sees it |
| Redis `INFO` of both instances (every 1 min) | first instance: `used_memory` above 80 % of `maxmemory` or growing for days, `evicted_keys` > 0; second instance: `evicted_keys` growing fast or hit ratio `keyspace_hits / (hits + misses)` below ~0.8 — raise `REDIS_CACHE_MAXMEMORY` (§0 "Redis") |
| Sentry / GlitchTip issues | new issue in `production`; spike of a known issue |
| Failed jobs (`failed_jobs`, `php artisan queue:failed`) | any growth, especially `PostLeaderboardJob` |
| `backup:monitor` mail | any message (backup is missing or too old) |
| Certificate expiry | < 14 days |
| Disk usage on the DB / backup volume | > 80% |
| `billing.promo_rejected_burst` (warning log; `learner_id`, `rejections`, `last_reason`) | any occurrence outside a known broken client — one learner failed ≥ 5 promo redemptions in 10 min (`BILLING_PROMO_REJECTIONS_ALERT_*`): code guessing; consider blocking the learner |
| Battles that did not move (the query of §14.3, step 7: invitations expired for 2 minutes, matches that are on and not updated for 5) | rows for more than 10 minutes — `practice:battles-sweep` does not run (the scheduler is down; `/health/ready` should have failed first) |

Product events (`product_events` table, see `docs/03_DATABASE.md`) are for product analytics, not alerting.
`reminder.failed` is expected for learners who blocked the bot or never opened a DM with it (`reason`: `bot_blocked` / `not_started` / `user_deactivated` / `chat_not_found`); it sets `learners.dm_blocked_at` and the learner is not retried until they write to the bot. Alert only on a sudden spike. `reminders:dispatch` prints `claimed / queued / skipped stale / repaired / backfilled`; a steadily non-zero `skipped stale` means the scheduler or the queue lags by more than `REMINDERS_MAX_DELAY_MINUTES`.
`promo.rejected` is likewise expected (typos); the burst *log line* above, not the event, is the alert.
`telegram.battle_dm_forbidden` (warning: `learner_id`, `reason`) is expected too: the friend who was invited to a battle blocked the bot or never opened it; the message is not delivered, `learners.dm_blocked_at` is set, the invitation is still in the app. Alert only on a sudden spike. `battle.*` product events (`battle.finished`, `battle.closed` with `end_reason`) are analytics, not alerts; a growing share of `voided` means missing dictation audio (§10.2).

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

**Legal texts.** `offer` and `privacy` hold drafts (ru/uz) in `lang/{ru,uz}/legal.php`: `sections` is a list of `['title' => ..., 'text' => "Paragraph 1\n\nParagraph 2"]` (paragraphs split on blank lines; a plain string stays a placeholder section). Tokens `{contact}` (support contact, else `SITE_LEGAL_EMAIL`, else a pointer to `/paysupport`) and `{requisites}` (the filled `SITE_LEGAL_*` fields) are substituted by the view; the number and order of sections must be identical in `ru` and `uz` (`LocalizationTest`). Places the owner or the lawyer must fill are marked in square brackets in the texts (data storage country/provider, minimum age, mail provider, terms). While `SITE_LEGAL_DRAFT=true` (default) the pages show the "draft" notice and `noindex`; after the lawyer's approval set `SITE_LEGAL_DRAFT=false` and restart (the page cache key includes the flag): the notice disappears and the pages become indexable.

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
| `SITE_LEGAL_DRAFT` | `true` by default: the offer and privacy pages show the "draft" notice and are `noindex`; set `false` only after the lawyer has approved the texts (`config('site.legal.draft')`) |
| `SITE_LEGAL_NAME`, `_INN`, `_ADDRESS`, `_BANK`, `_BANK_ACCOUNT`, `_MFO`, `_PHONE`, `_EMAIL` | seller requisites printed on a center's invoice and in the last section of the offer (`config/site.php`, from the owner / accountant); empty fields are not printed |

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
| `REDIS_PASSWORD` | ✅ (production) | `openssl rand -hex 32`; empty in dev = no password (never `null`) |
| `REDIS_CACHE_DB` | recommended | `1` |
| `REDIS_QUEUE_DB` | recommended | `2` |
| `REDIS_SESSION_DB` | recommended | `3` |
| `REDIS_LOCK_DB` | recommended | `4` |
| `REDIS_MAXMEMORY` | recommended | `256mb` (first instance; its policy is `noeviction`, fixed) |
| `REDIS_LRU_HOST` / `REDIS_LRU_PORT` | ✅ (bare server) | second instance: `127.0.0.1` / `6380`; in Docker compose sets `redis-cache` / `6379` for the app containers itself |
| `REDIS_LRU_PASSWORD` | ✅ (bare server) | password of the second instance, `openssl rand -hex 32`; in Docker compose sets it from `REDIS_CACHE_PASSWORD` |
| `REDIS_CACHE_PASSWORD` | ✅ (Docker production) | what `docker-compose.prod.yml` gives `redis-cache` and the app containers |
| `REDIS_CACHE_MAXMEMORY` | recommended | `256mb` (second instance, `volatile-lru`) |
| `CACHE_LIMITER_STORE` | recommended | `redis` (the rate limiter never on the evicting store) |
| `CACHE_PURE_STORE` | recommended | `lru` (the pure cache, `PureCache::store()`) |
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
| `PRACTICE_PLAN` | `all` | `pro` — only Pro learners practise; a Free learner gets the paywall (403 `practice_pro_required`). With `all`, Free learners practise within the daily limits below |
| `PRACTICE_FREE_SESSIONS_PER_DAY` | `2` | Practice sessions a Free learner may open per local day (docs/18 G1); a retry of the mistakes is free, replacing the unfinished session counts. Pro (feature `practice.unlimited`, sponsored Pro included) has no limit. `0` — only Pro opens sessions; empty — no limit for Free. Refused: 403 `practice_limit_reached` |
| `PRACTICE_FREE_BATTLES_PER_DAY` | `1` | Battle calls a Free learner may send per local day: invitations and rematches they offer that wait for an answer or were played. A call that never started through no fault of the caller (declined, run out, withdrawn, a room that ran out, voided) is given back; accepting a friend's call is always free. `0` / empty as above |
| `PRACTICE_MODE_PAIRS` / `_COLLOCATION` / `_SENTENCE` / `_DICTATION` | `true` | Switches one mode off: no new session of it; sessions already started finish |
| `PRACTICE_BATTLE` | `true` | The switch of the 1-on-1 battle: `false` refuses new invitations and the acceptance of sent ones (409 `battle_disabled`) and hides the entries (`/me` → `practice.battle`); matches that are on finish. `PRACTICE_ACCESS=off` is stronger: the sweeper voids the matches that are on |
| `TTS_SERVICE_URL`, `TTS_VOICE`, `TTS_AUDIO_VERSION` | see §10.1 | Voicing of the dictation phrases uses the same service and voice as the word audio (§10.2) |

Fixed in `config/practice.php`: solo session TTL 24 h (`practice.solo.session_ttl_hours`); 5 tasks per session, at least 3 (`solo.items`); pairs — up to 3 rounds of 4, rounds of 3 allowed (`solo.pairs`); dictation — at most 3 phrases, 2 swaps of a clip (`solo.dictation`); import ≤ 2 MB and ≤ 300 tasks (`import`); the disk of the phrase audio `practice_audio` (`config/filesystems.php`, root `storage/app/practice-audio`, no URL).
The battle (`practice.battle.*`): 5 rounds; a round lasts 30 s (collocation), 45 s (pairs, sentence) or 60 s (dictation); a countdown of 3 s and a review of 4 s; an invitation lives 300 s, a room 120 s; the opponent is warned about after 10 s of silence (`presence_warn_seconds`), a player silent for 30 s loses (`presence_grace_seconds`); the presence is written at most once per 5 s (`heartbeat_write_seconds`);
an answer is taken up to 1500 ms after the deadline (`deadline_grace_ms`); a round is worth 100 points, a pair 25 (`points`); the list of finished matches holds 10 (`finished_list`). The limiters (`AppServiceProvider`): `twa-battle-invite` 5/min, `twa-battle-action` 60/min, `twa-battle-poll` 90/min (outside `twa-api`), `twa-practice-audio` 60/min (shared with the solo audio).


### Game "Caravan" variables (docs/18 §12.7)

Read from `.env`; everything else of `config/game.php` (run sizes, scoring, economy, league, class) is changed by a release.

| Variable | Default | Meaning |
|---|---|---|
| `GAME_ACCESS` | `off` | Who plays: `off` (nobody; every endpoint answers 403 `game_unavailable`), `pilot` (the learners of the practice pilot, `learners.practice_pilot_at` — one audience for both), `all`. Anything else counts as `off`. `GET /me` → `game.available`. The **class caravan** (`/start_caravan`, `/cancel_caravan`, the page «Караваны» in `/org`, the cards on the map and on «Сегодня») exists **only with `all`**: in `pilot` the game is seen by single learners, a caravan is for the whole group. After any change of `GAME_ACCESS`: `php artisan config:cache` and `php artisan telegram:setup-bot` (the command menu is built when the command runs: the caravan commands are in it only with `all`) |
| `GAME_FREE_RUNS_PER_DAY` | `1` | Free map runs (stops + bosses) a Free learner plays per local day; more cost a ticket. Pro (feature `game.unlimited_runs`) has no limit; the daily caravan is not limited. `0` — only Pro; empty — no limit for Free |
| `GAME_FREE_BOSS_STAGES` | `1` | The bosses of the first N cities of a course a Free learner may fight (Pro: `game.all_bosses`); past that 403 `game_pro_required`. Empty — all |
| `GAME_FREE_BOOSTERS_PER_WEEK` / `GAME_PRO_BOOSTERS_PER_WEEK` | `1` / `5` | Boosters a learner may use per local week (Pro: `game.boosters_plus`) |
| `GAME_TICKETS_PER_STREAK_WEEK` / `GAME_TICKETS_PER_REFERRAL` / `GAME_TICKETS_MAX` | `1` / `3` / `10` | Tickets: per 7 days of the streak, to the inviter per activated friend, the cap of the balance |
| `GAME_LEAGUE_DAILY_CAP` | `40` | The cap of the league points the game gives per local day |
| `GAME_CLASS_CENTERS_ONLY` | `true` | The caravan of a class only for the centers with a live subscription |

Turning the game on (the pilot checklist): `php artisan migrate --force` (the game tables and the index `game_runs (started_at, learner_id)`); put the pilot learners in (`learners.practice_pilot_at`, as for the practice); `GAME_ACCESS=pilot`; then **`php artisan config:cache` and `php artisan telegram:setup-bot`** (the bot menu is built when the command runs, not on every request); watch the widget «Караван — 7 дней»; later `GAME_ACCESS=all` and the same two commands again — only then the caravan of a class appears in the bot, in `/org` and in the Mini App. **Before switching `GAME_ACCESS` from `all` back to `pilot` or `off`, cancel the class caravans that are on their way** (`/cancel_caravan`, `/org` or `/admin`): afterwards the bot is silent and `/org` has no page, so a teacher cannot cancel them, and runs no longer count for them; they only finish on their own at the end of the week (the summary still goes to the chat).
