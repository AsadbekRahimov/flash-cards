# Деплой R13: две поверхности, PostgreSQL под сервер, замер ёмкости

Пошаговая инструкция к ветке R13 (`PROGRESS.md`, строка R13). Что изменилось в стеке: контейнер `app-api` только для `/api/*`
без Filament (`docs/DEVOPS_GUIDE.md` §0 «Две поверхности приложения»), PostgreSQL прода с настройками под сервер
(§0 «PostgreSQL: настройки под сервер»), поле `surface` в `/health/ready`. Octane в этом релизе **нет** — это шаг 2 (§7).

## 1. Что нужно до деплоя

| | |
|---|---|
| Сервер | эталон — 4 vCPU / 8 ГБ, SSD. Меньше RAM → задать `PG_*` в `.env` (§2), иначе `deploy.sh` напишет `WARNING: shared_buffers … > 30 %` |
| Docker | Docker Engine + `docker compose` v2, `flock` (util-linux) |
| Образы | собираются на сервере `deploy.sh` (`lexiflow/app`, `lexiflow/nginx`, `lexiflow/pgbouncer`) |
| Порты | 80/443 наружу; 5432/6379/9000/8000 — только внутри compose-сети |
| CI | `master` зелёный: pint, phpstan level 8, Pest на PG 18 + Redis 8 |

## 2. `.env` на сервере — что проверить

Полный список — `docs/VPS_DEPLOY_GUIDE_RU.md` и `.env.example`. Для R13 важны эти строки:

```dotenv
APP_ENV=production
APP_DEBUG=false

# База: через pgbouncer, постоянные соединения php-fpm (R12d)
DB_HOST=pgbouncer
DB_PERSISTENT=true
DB_DISABLE_PREPARES=true

# APP_SURFACE в .env НЕ задавать: compose сам даёт app-api значение api, всем остальным — full.

# Пулы php-fpm на 4 vCPU (умолчания compose; менять только при другом числе ядер)
# PHP_FPM_MAX_CHILDREN=6          # app: панели, вебхуки, сайт
# PHP_FPM_API_MAX_CHILDREN=12     # app-api: ученики
# PHP_FPM_MAX_REQUESTS=500

# PostgreSQL (умолчания — 4 vCPU / 8 ГБ). Для 2 vCPU / 4 ГБ раскомментировать:
# PG_SHARED_BUFFERS=1GB
# PG_EFFECTIVE_CACHE_SIZE=2500MB
# PG_WORK_MEM=8MB
# PG_MAINTENANCE_WORK_MEM=128MB
# Для 8 vCPU / 16 ГБ: PG_SHARED_BUFFERS=4GB, PG_EFFECTIVE_CACHE_SIZE=11GB, PG_WORK_MEM=32MB.
# PG_SYNCHRONOUS_COMMIT=off  — только по решению владельца (DEVOPS_GUIDE §0): быстрее коммиты, при аварии теряются последние ~0,6 с.
```

Правило пулов: суммарно 2–4 воркера php-fpm на ядро, из них ученикам (`app-api`) — около двух третей.
Для 8 vCPU: `PHP_FPM_API_MAX_CHILDREN=24`, `PHP_FPM_MAX_CHILDREN=8`, `PGBOUNCER_POOL_SIZE=32`.

## 3. Деплой

**Из CI (штатный путь).** Merge ветки в `master` → workflow деплоя запускает на сервере `deploy.sh <sha>` с SHA, который прошёл
тесты. Пока секретов деплоя в CI нет, шаг пропускается с предупреждением (`PROGRESS.md`, решения 03.11.2026) — тогда вручную.

**Вручную на сервере:**

```bash
cd /opt/lexiflow            # каталог с клоном и .env (chmod 600 .env)
git fetch origin master
bash deploy.sh <sha>        # SHA зелёного коммита master; без SHA — tip origin/master с предупреждением
```

Что делает `deploy.sh` (по порядку, останавливается на первой ошибке, лог — в stdout):

1. Берёт лок `.deploy.lock`, проверяет том PostgreSQL (`docker/postgres/upgrade.sh --check`), делает checkout SHA.
2. Проверяет `.env`: пароли Redis не пустые и не `null`.
3. Собирает образы `app`, `nginx`, `pgbouncer` (`docker build` — 5–10 минут на первом деплое, дальше кэш).
4. Миграции через `pgsql_direct` (мимо pgbouncer); `--skip-migrate` их пропускает.
5. `docker compose up -d --remove-orphans` — пересоздаёт изменившиеся контейнеры. **В первом деплое R13 пересоздаётся
   `postgres`** (новый `command` и `shm_size`): 5–15 секунд база недоступна, запросы в полёте получают ошибки,
   `/health/ready` на это время — 503. Делать в тихий час. Дальше `postgres` не трогается, пока не меняется `PG_*`.
6. Проверки: `artisan about` в `app`; список маршрутов `app-api` через `docker/check-api-surface.php` (есть `api/twa/me`, нет
   `filament.*`/`admin*`); печать действующих настроек PostgreSQL и предупреждение при `shared_buffers` > 30 % RAM.
7. HTTPS-smoke через nginx: `/up`, `/api/twa/me` → 401 (значит, nginx дошёл до `app-api`), `/health/ready` (ждёт heartbeat'ы
   планировщика и очередей до 5 минут), `/twa/index.html`, `/build/manifest.json`.

Длительность штатного деплоя — 3–6 минут; простой приложения — только на пересоздание `app`/`app-api`/`nginx`
(секунды, nginx ждёт `service_healthy` обоих).

## 4. Проверка после деплоя

```bash
C="docker compose -f docker-compose.prod.yml --env-file .env"
$C ps                                                   # все healthy: app, app-api, nginx, postgres, pgbouncer, redis, redis-cache, queue-*, scheduler
$C exec app-api php -r 'var_dump($_SERVER["APP_SURFACE"]);'      # string(3) "api"
$C exec app-api sh -c 'php artisan route:list --json | php docker/check-api-surface.php'   # «api surface confirmed»
$C exec app-api php artisan about | grep -ci filament   # 0
$C exec app php artisan about | grep -ci filament       # > 0
curl -s https://<домен>/health/ready | jq .surface      # "full" — nginx шлёт /health/ready в app
curl -s -o /dev/null -w '%{http_code}\n' https://<домен>/api/twa/me   # 401 — ответил app-api
$C exec postgres sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SHOW shared_buffers" -c "SHOW jit"'
$C logs --since 10m app-api | grep -i "max_children"    # пусто; иначе поднять PHP_FPM_API_MAX_CHILDREN
docker stats --no-stream                                # app-api ≈ 12 × 40–60 МБ, postgres ≈ shared_buffers + немного
```

Откат: `bash deploy.sh --rollback <предыдущий-sha>` — старый compose без `app-api`, `--remove-orphans` удалит контейнер,
nginx старого коммита не знает `location /api/` и снова шлёт всё в `app`; `postgres` пересоздастся ещё раз со старым `command`.

## 5. Нагрузочный тест: сколько учеников держит этот сервер

Сценарий `tests/load/k6/twa-hot-paths.js` — один VU = один ученик Mini App: `/me`, `/today`, курсы, хаб практики, каталог,
тренировка (открыть, 8 карточек, завершить) — 16 запросов за ~31–65 с пауз, то есть **~0,52 запроса/с на ученика**.
Порог владельца: p95 < 300 мс на каждом горячем эндпоинте, ни одного 5xx, ни одного 429.

**Где гонять.** На staging (`docker-compose.prod.yml` с `APP_ENV=staging`) или на проде до открытия продаж. Токены
`load:tokens` выдаются только в `local`/`testing`/`staging`. Генератор k6 — с другой машины или с той же (`K6_NET=host`;
тогда k6 ест ~0,3–0,5 ядра, вычесть из результата).

```bash
# 1. Данные: курс опубликован; для реалистичных размеров таблиц — perf:seed (docs/20 §4)
$C exec app php artisan perf:seed --learners=200000 --words=100000 --members=400

# 2. Токены для 1 250 учеников (файл живой — удалить после прогона)
make load-tokens TARGET_VUS=1250 LOAD_ARTISAN="$C exec -T app php artisan"

# 3. Разведка: 50 учеников, 2 минуты удержания
make load-test BASE_URL=https://<домен> TARGET_VUS=50 HOLD=2m

# 4. Ступени: 200 → 300 → 400 → 600 (каждая — отдельный прогон, HOLD=5m); параллельно на сервере:
docker stats                                   # CPU app-api, app, postgres, redis, pgbouncer, nginx
$C exec pgbouncer psql -h 127.0.0.1 -U "$DB_USERNAME" pgbouncer -c 'SHOW POOLS'   # cl_waiting должен быть 0
curl -s https://<домен>/health/ready           # остаётся 200
make load-test BASE_URL=https://<домен> TARGET_VUS=300 HOLD=5m
```

**Как читать результат** (конец вывода k6 и `tests/load/k6/results/twa-hot-paths-summary.json`):

- блок `thresholds`: все строки с галочкой — ступень пройдена; `http_req_duration{endpoint:training_review}` p(95) обычно
  первым подходит к 300 мс;
- `server_errors = 0`, `rate_limited = 0`, `http_req_failed < 1 %`;
- **ёмкость** = наибольшая ступень, на которой все пороги зелёные при 5 минутах удержания. Между ступенями — бинарный поиск
  с шагом 50 VU;
- **CPU на запрос** = Σ CPU всех контейнеров (из `docker stats`, в долях ядра) ÷ `http_reqs` rate (req/s) из k6. R12 дал 28 мс
  при php-fpm с Filament; ожидание R13 — 17–20 мс. Прогноз на другое число ядер: `ёмкость ≈ 0,9 × ядра ÷ (CPU_на_запрос × 0,52)`.

Числа записать в `docs/19_REDIS_CACHE_PLAN.md` §8 новым блоком «R13» (ступени, p95 по эндпоинтам, CPU на запрос, RSS
воркеров) и в `PROGRESS.md`.

**Чего в этом тесте нет:** вебхук бота (`make load-test-webhook`, NFR-01) и панели — их грузят отдельно; практика и баттлы
в сценарии не представлены (другой профиль запросов, добавить при необходимости).

## 6. Что смотреть первые дни

- `docker compose logs app-api | grep max_children` — очередь к php-fpm → поднять `PHP_FPM_API_MAX_CHILDREN` (в пределах 2–4 на ядро);
- `SHOW POOLS` pgbouncer — `cl_waiting` > 0 постоянно → `PGBOUNCER_POOL_SIZE` (не выше 4 × vCPU);
- `docker compose logs postgres | grep duration` — запросы дольше 500 мс (`PG_LOG_MIN_DURATION_STATEMENT`) → индекс или кэш;
- `/health/ready` — `degraded` дольше 10 минут → `redis-cache` (DEVOPS_GUIDE §0 «Redis»);
- Sentry: ошибки на `app-api` с упоминанием Filament/Livewire — код, достижимый из TWA, трогает панели (не должно быть; тест
  `SurfaceTest` и ревью такого не нашли).

## 7. Шаг 2 — Octane для `app-api` (решение владельца)

Проект и аудит — `docs/21_OCTANE_READINESS.md`; ожидание — ещё ×1,5–2 к ёмкости `app-api` (CPU PHP на запрос ~11–14 мс вместо
~17–20 после R13), дальше упираемся в PostgreSQL и Redis на той же машине.

Нужна новая зависимость `laravel/octane` — по правилам проекта только с согласия владельца, и `composer.lock` обновляется
там, где есть composer и сеть (на сервере сборки это не делается). Порядок:

```bash
# на машине разработчика, в ветке claude/twa-capacity-r13 или новой от master
composer require laravel/octane --with-all-dependencies
php artisan octane:install --server=frankenphp     # бинарник frankenphp, если скачался в корень, НЕ коммитить
git add composer.json composer.lock config/octane.php
git commit -m "Add laravel/octane (FrankenPHP) for the app-api container"
git push
```

После этого выполняется `docs/21` §4: `config/trustedproxy.php` + `TRUSTED_PROXIES`, слушатели
`ShareRequestMemosWithSandbox` и `RollBackLeakedTransactions`, `permission.register_octane_reset_listener`, стадия образа
`app-octane` (`dunglas/frankenphp:1-php8.5-alpine`), `docker/octane-start.sh`, `docker/healthcheck.sh`, сниппет nginx
`proxy_pass http://app-api:8000`, `APP_SERVER=octane` для `app-api`, тесты изоляции воркера, и затем §5 этой инструкции заново
в обоих режимах на одном наборе данных. Откат — `APP_SERVER=fpm` и `docker compose up -d app-api nginx`, без сборки.
