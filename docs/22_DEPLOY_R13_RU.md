# Деплой R13: две поверхности, PostgreSQL под сервер, замер ёмкости

Пошаговая инструкция к ветке R13 (`PROGRESS.md`, строка R13). Что изменилось в стеке: контейнер `app-api` только для `/api/*`
без Filament (`docs/DEVOPS_GUIDE.md` §0 «Две поверхности приложения»), PostgreSQL прода с настройками под сервер
(§0 «PostgreSQL: настройки под сервер»), поле `surface` в `/health/ready`. Octane — шаг 2, включается отдельно (§7).

## 1. Что нужно до деплоя

> Новый сервер с нуля — `docs/24_VPS_DOCKER_RU.md` (система, Docker, DNS, `.env`, первый деплой, затем этот документ и docs/23).

| | |
|---|---|
| Сервер | эталон — 4 vCPU / 8 ГБ, SSD. Меньше RAM → задать `PG_*` в `.env` (§2), иначе `deploy.sh` напишет `WARNING: shared_buffers … > 30 %` |
| Docker | Docker Engine + `docker compose` v2, `flock` (util-linux) |
| Образы | собираются на сервере `deploy.sh` (`lexiflow/app`, `lexiflow/app-fpm`, `lexiflow/app-octane`, `lexiflow/nginx`, `lexiflow/pgbouncer`) |
| Порты | 80/443 наружу; 5432/6379/9000/8000 — только внутри compose-сети |
| CI | `master` зелёный: pint, phpstan level 8, Pest на PG 18 + Redis 8 |
| Cloudflare | по желанию (CDN для статики Mini App и аудио, WAF): настройка и переменные `CLOUDFLARE_PROXY` / `CLOUDFLARE_ONLY` — `docs/23_CLOUDFLARE_RU.md`. Нагрузочный тест §5 при этом гонять мимо Cloudflare (docs/23 §8) |

## 2. `.env` на сервере — что проверить

Полный список — `docs/VPS_DEPLOY_GUIDE_RU.md` и `.env.example`. Для R13 важны эти строки:

```dotenv
APP_ENV=production
APP_DEBUG=false

# База: через pgbouncer, постоянные соединения php-fpm (R12d)
DB_HOST=pgbouncer
DB_PERSISTENT=true
DB_DISABLE_PREPARES=true
# Redis: постоянные сокеты phpredis у app и app-api задаёт compose (REDIS_PERSISTENT=true); в .env не трогать.

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
cd /var/www/lexiflow        # каталог с клоном и .env (chmod 600 .env); тот же путь использует job deploy в CI
git fetch origin master
bash deploy.sh <sha>        # SHA зелёного коммита master; без SHA — tip origin/master с предупреждением
```

Что делает `deploy.sh` (по порядку, останавливается на первой ошибке, лог — в stdout):

1. Берёт лок `.deploy.lock`, проверяет том PostgreSQL (`docker/postgres/upgrade.sh --check`), делает checkout SHA.
2. Проверяет `.env`: пароли Redis не пустые и не `null`; печатает **предупреждения** (деплой не останавливают) о настройках прода,
   забытых по умолчанию: `TELEGRAM_IP_ALLOWLIST_ENABLED` не `true`, `PAYME_ALLOW_TEST_KEY=true`, `APP_DEBUG=true`, пустой
   `BACKUP_ARCHIVE_PASSWORD`, бэкапы только на `local`, `SENTRY_TRACES_SAMPLE_RATE` > 0,01 (чек-лист — docs/24 §10).
3. Собирает образы `app`, `app-api` (стадия `app-fpm`; под `APP_SERVER=octane` — ещё и `app-octane`), `nginx`, `pgbouncer`
   (`docker build` — 10–15 минут на первом деплое, дальше кэш).
4. Миграции через `pgsql_direct` (мимо pgbouncer); `--skip-migrate` их пропускает. Затем справочники (`ReferenceDataSeeder`),
   пересчёт слов курсов и **Telegram**: `docker/telegram-setup.sh` — `telegram:setup-bot` (меню команд ru/uz и кнопка Mini App) и
   `telegram:set-webhook`. Идемпотентно; без `TELEGRAM_BOT_TOKEN` / `TELEGRAM_WEBHOOK_URL` / `TELEGRAM_URL_SECRET` /
   `TELEGRAM_HEADER_SECRET` шаг пропускается с `WARNING`, ошибка Telegram API — тоже только `WARNING` (деплой идёт дальше). На
   откате (`--rollback`) шаг не выполняется.
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

Cloudflare включён (`docs/23_CLOUDFLARE_RU.md`): дополнительно `$C logs --tail 20 nginx` — в access-логе адреса учеников, а не Cloudflare (`104.x`, `172.64–71.x`); `curl -sI https://<домен>/twa/assets/<файл> | grep -i cf-cache-status` — `HIT` со второго запроса.

Откат: `bash deploy.sh --rollback <предыдущий-sha>` — старый compose без `app-api`, `--remove-orphans` удалит контейнер,
nginx старого коммита не знает `location /api/` и снова шлёт всё в `app`; `postgres` пересоздастся ещё раз со старым `command`.

## 5. Нагрузочный тест: сколько учеников держит этот сервер

Сценарий `tests/load/k6/twa-hot-paths.js` моделирует одного ученика на VU. Сначала параллельно читает `/today` и `/assignments`, затем профиль, курсы, хаб практики и каталог подписки.
Тренировка берёт первую карточку из ответа открытия, остальные из ответов оценки. При восьми карточках полная итерация делает 16 запросов за ~31–65 с пауз, до **~0,52 запроса/с на ученика**.
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

## 7. Шаг 2 — Octane для `app-api`: как включить

Реализовано (проект — `docs/21_OCTANE_READINESS.md`, режимы — `docs/DEVOPS_GUIDE.md` §0 «Режим контейнера `app-api`»).
`laravel/octane` уже в `composer.json`/`composer.lock`, ставить ничего не нужно. Ожидание — ещё ×1,5–2 к ёмкости `app-api`
(CPU PHP на запрос ~11–14 мс вместо ~17–20 после R13), дальше упираемся в PostgreSQL и Redis на той же машине.
По умолчанию (`APP_SERVER` не задан) ничего не меняется: php-fpm, те же образы.

**Сначала замер в php-fpm** (раздел 5), чтобы было с чем сравнивать. Затем:

1. `.env` на сервере:
   ```
   APP_SERVER=octane
   APP_API_DB_PERSISTENT=false       # Octane: один воркер — одно соединение; при true deploy.sh предупредит
   # необязательно: OCTANE_WORKERS=6 (4 vCPU), OCTANE_MAX_REQUESTS=500
   ```
   `TRUSTED_PROXIES` задавать не нужно: `app-api` по умолчанию доверяет одному адресу nginx (`NGINX_IPV4`, `172.28.0.10`), остальные
   контейнеры — никому. Подсеть (`172.28.0.0/16`) и `/16` в `TRUSTED_PROXIES` нельзя: соединения через docker-proxy (IPv6, hairpin)
   приходят с шлюза `172.28.0.1`, он внутри подсети (docs/07 §6). `*` тоже нельзя. Если подсеть занята на хосте — `COMPOSE_SUBNET`,
   `COMPOSE_IP_RANGE`, `NGINX_IPV4` вместе (`.env.example`).
2. `bash deploy.sh <sha>`. Он соберёт `app-octane` (первая сборка дольше: другой базовый образ, расширения собираются заново),
   пересоздаст `app-api` и nginx. Если подсеть существующей сети не равна `COMPOSE_SUBNET` (первый деплой R13, откат на коммит без `ipam`),
   `deploy.sh` пишет `compose network subnet changes: stopping the stack` и делает `docker compose down` (без `-v`, тома целы)
   перед `up` — стек стоит всё время деплоя (лучше в тихое время).
3. Проверки: в выводе деплоя `Octane server is running.`, в `docker compose exec nginx nginx -T | grep -A3 'upstream lexiflow_api_octane'` — `keepalive 32`, `config:show trustedproxy` содержит адрес nginx (проверяет `deploy.sh`, иначе STOP), smoke `POST /api/twa/auth` → 401/422 (429 — только
   при уже насыщенном лимитере), `/api/twa/me` → 401;
   `docker stats lexiflow-app-api` (память стоит, 6 воркеров ≈ 0,3–0,55 ГБ + Caddy), `make prod-logs-api` без ошибок;
   в TWA: тренировка, аудио практики и игры (потоковые ответы), оплата. `https://<домен>/admin/login` — assets по `https://`.
4. k6 в обоих режимах на одном стенде и одном наборе `perf:seed` (раздел 5, `make load-test`): CPU на запрос, p95 по
   эндпоинтам; числа — в `docs/19` §8. Расхождение с ожиданием больше 1,3 раза — повод вернуться к `docs/21` §3.1/§3.4.
5. **Откат:** в `.env` `APP_SERVER=fpm` (или удалить строку), затем
   `docker compose -f docker-compose.prod.yml --env-file .env up -d app-api nginx`. Сборки не нужно: образ `app-fpm` собирается
   при каждом деплое. `TRUSTED_PROXIES` и `NGINX_IPV4` можно оставить: под fpm доверие адресу nginx безвредно. Откат на коммит без `ipam` пересоздаст сеть (см. выше, `down`).

## 8. Coolify: сети, Traefik, проверка изоляции

Относится к `docker-compose.coolify.yml` (стек `lf-*` на Coolify; обычный VPS — `docker-compose.prod.yml`, там сеть своя и
`app`/`app-api` недоступны извне). Аудит 2026-11-10 §1 M3 и M4.

**Сети стека.**

| Сеть | Кто в ней | Зачем |
|---|---|---|
| `lf-internal` (мост, подсеть `LF_INTERNAL_SUBNET`, умолчание `10.254.77.0/24`, порты не публикуются) | все сервисы `lf-*` | nginx → php-fpm (`lf-app-private:9000`) и Octane (`lf-api-private:8000`) |
| `proxy` (внешняя, `LF_PROXY_NETWORK`, умолчание `coolify`) | **только `lf-nginx`** | Traefik доходит до nginx |
| `infra` (внешняя, `LF_INFRA_NETWORK`, умолчание `coolify`) | всё, кроме `lf-nginx`: миграция, `lf-app`, `lf-api`, очереди, планировщик | PostgreSQL / pgbouncer / Redis из ресурса server-infra |

Приватная сеть — **обычный мост, не `internal: true`**: флаг `internal` отрезает исходящий маршрут, а приложению нужен выход в интернет
(Telegram API, платёжные провайдеры, TTS, Sentry). Мост без `ports:` и так закрыт снаружи: с хоста и из интернета в него не
войти. `TRUSTED_PROXIES` у `lf-api` теперь — подсеть `lf-internal` (nginx — единственный сервис в ней, который ходит в Octane); чужой контейнер
общей сети из этой подсети не приходит, его `X-Forwarded-For` не принимается. Переопределить: `LF_TRUSTED_PROXIES` (адрес или короткий
список; `*` и `/0`–`/8` запрещены `App\Support\TrustedProxies`). nginx ходит к приложению по алиасам `lf-app-private` / `lf-api-private`,
которые есть **только** в `lf-internal`: обычное имя сервиса Docker DNS разрешил бы в адрес любой общей сети, и Octane увидел бы nginx с
адреса вне подсети.

**Полная изоляция требует шага на сервере** (в репозитории его не сделать): пока `LF_INFRA_NETWORK=coolify` (умолчание), БД и Redis
живут в той же сети, что и Traefik, поэтому `lf-app` / `lf-api` остаются в ней видны соседям (чужой FastCGI на `lf-app:9000` — исполнение
кода). Поддельный `X-Forwarded-For` в `lf-api` уже не пройдёт, а FastCGI — пройдёт. Чтобы закрыть и его:

```bash
docker network create lexiflow-infra
# в compose-ресурсе server-infra: подключить pgbouncer и redis(и redis-cache) к сети lexiflow-infra (external) и передеплоить его
# затем в переменных окружения ресурса LexiFlow в Coolify:
LF_INFRA_NETWORK=lexiflow-infra
```

Coolify может сам добавлять свои сети сервисам compose-ресурса («Connect To Predefined Network»): после первого деплоя проверьте.

**Проверка изоляции** (на сервере Coolify, после деплоя):

```bash
# 1) к proxy-сети подключён только nginx (а при LF_INFRA_NETWORK=lexiflow-infra - к `coolify` нет ни app, ни api):
docker network inspect coolify --format '{{range .Containers}}{{.Name}}{{"\n"}}{{end}}' | grep -i lf-
# 2) lf-app и lf-api видят приватную сеть и (если вынесли) infra, но не coolify:
for c in $(docker ps --format '{{.Names}}' | grep -E 'lf-(app|api)'); do echo "$c: $(docker inspect $c --format '{{range $k,$v := .NetworkSettings.Networks}}{{$k}} {{end}}')"; done
# 3) FastCGI соседа не достучится: из постороннего контейнера сети coolify
docker run --rm --network coolify busybox sh -c 'nc -zv -w2 lf-app 9000; nc -zv -w2 lf-api 8000'   # должно не соединиться
# 4) подсеть Octane доверяет: TRUSTED_PROXIES в контейнере
docker exec $(docker ps -qf name=lf-api) php artisan config:show trustedproxy
```

**M4: настоящий IP клиента зависит от Traefik** (настройки вне репозитория). Цепочка «клиент → (Cloudflare) → Traefik → `lf-nginx` →
`lf-api`»: nginx берёт адрес из `X-Forwarded-For`, но верит заголовку, только если TCP-сосед nginx — прокси (`TRUSTED_PROXY_CIDRS`, умолчание —
частные диапазоны Docker; сузить до подсети сети Traefik:
`docker network inspect coolify --format '{{(index .IPAM.Config 0).Subnet}}'` → переменная `LF_PROXY_CIDRS` в Coolify) или край Cloudflare
(`docker/nginx/snippets/realip-cloudflare.conf`); `lf-api` верит `X-Forwarded-For` от nginx, который кладёт туда уже **один** адрес `$remote_addr`.
Что должно быть в Traefik (`/data/coolify/proxy/docker-compose.yml`, `command:` точек входа `http` и `https`):

```yaml
- '--entrypoints.https.forwardedHeaders.trustedIPs=<диапазоны Cloudflare через запятую>'   # только если домен за Cloudflare
- '--entrypoints.http.forwardedHeaders.trustedIPs=<те же>'
# НЕ ставить --entrypoints.*.forwardedHeaders.insecure=true: Traefik тогда пропустит клиентский X-Forwarded-For как есть.
```

Без `trustedIPs` Traefik **заменяет** `X-Forwarded-For` адресом соседа по TCP: без Cloudflare это уже реальный клиент (хорошо), за Cloudflare —
адрес его края (все ученики на одном адресе: лимитеры и allowlist вебхуков работают на одного «клиента»). С `trustedIPs` = Cloudflare Traefik
сохраняет цепочку `клиент, край` — nginx снимает край (диапазоны Cloudflare) и получает клиента. Список — `https://www.cloudflare.com/ips`.

**Проверка цепочки командой** (с любой машины, не из Cloudflare-сети; лимит `twa-auth` — 60 в минуту на адрес): 70 запросов с **меняющимся**
`X-Forwarded-For` должны упереться в 429, потому что поддельный заголовок отбрасывается и все 70 приходят с одного настоящего адреса:

```bash
for i in $(seq 1 70); do
  curl -s -o /dev/null -w '%{http_code}\n' -X POST "https://<домен>/api/twa/auth" \
    -H 'Content-Type: application/json' -H "X-Forwarded-For: 203.0.113.$i" --data '{}'
done | sort | uniq -c
# ожидаем: ~60 x 401/422 и 10 x 429.  Все 70 без 429 = заголовку верят (Traefik `insecure` / нет trustedIPs-границы / nginx доверяет лишнему):
# каждый клиент выбирает себе адрес для лимитеров и allowlist - чинить до запуска.
```

Если за Cloudflare все ученики получают 429 одновременно (один адрес у всех) — наоборот, Traefik не доверяет Cloudflare: `trustedIPs` выше.

**nginx (оба конфига, `prod.conf` и `coolify.conf`).** `worker_connections` — 4096 (`Dockerfile`, стадия `nginx`: в образе 1024); зона
`limit_req` для публичных путей без входа (`/api/twa/auth`, `/telegram/webhook/`, `/billing/return`, лендинг и юридические страницы): 30 запросов в
секунду на адрес, burst 60 без задержки, сверх — 429 от самого nginx. Не считаются: `/up`, `/health/*`, ACME, ассеты, авторизованные части
(`/api/*` кроме auth, `/admin`, `/org`, `/livewire`, `/2fa`) и адреса Telegram (`149.154.160.0/20`, `91.108.4.0/22`: вебхук приходит с
нескольких адресов с растущей частотой).

**`php-fpm`.** Пул `app-api` в `docker-compose.prod.yml` — `pm = static` (`PHP_FPM_API_PM`, умолчание `static`; `dynamic` вернуть можно строкой в
`.env`): все воркеры уже подняты, нет задержки на spawn. Память занята сразу: `PHP_FPM_API_MAX_CHILDREN` × ~50 МБ. Пул `app` и Coolify-`lf-app` остаются `dynamic`.

