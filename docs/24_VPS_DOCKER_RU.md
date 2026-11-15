# LexiFlow Pro — чистый VPS → Docker-стек → Octane → Cloudflare (сквозной порядок)

> Это **единственный путь, в котором работает всё из R13**: два контейнера `app`/`app-api`, тюнинг PostgreSQL через `PG_*`,
> Octane на FrankenPHP (`APP_SERVER=octane`), Cloudflare (`CLOUDFLARE_PROXY`). Руководство `VPS_DEPLOY_GUIDE_RU.md`
> («без Docker», всё пакетами) этих частей не содержит — его не использовать для нового сервера.
>
> Здесь — порядок действий и ссылки на подробности; сами подробности не дублируются:
> `README.md` «Production deployment», `DEVOPS_GUIDE.md` §0, `22_DEPLOY_R13_RU.md` (деплой, проверки, k6, Octane),
> `23_CLOUDFLARE_RU.md` (панель Cloudflare), `07_SECURITY.md` (что проверить до открытия).

Ориентир — Ubuntu 24.04 LTS, 4 vCPU / 8 ГБ / SSD (для 2 vCPU / 4 ГБ — задать `PG_*`, docs/22 §2). Время всего пути:
~1,5–2 часа чистого времени плюс ожидание DNS и первой сборки образов (10–15 минут).

Порядок обязателен: **сертификат — до Cloudflare, k6 в fpm — до Octane, Octane — до `CLOUDFLARE_ONLY`.**

---

## 0. До сервера

| Что | Где это потом нужно |
|---|---|
| Домен; доступ к DNS (на старте — у регистратора, после §7 — в Cloudflare) | `APP_DOMAIN`, сертификат |
| Токен бота от @BotFather, username бота, короткое имя Mini App (BotFather → Bot Settings → Menu Button / Web App) | `TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME`, `TELEGRAM_MINI_APP_SHORT_NAME` |
| SSH-ключ для сервера; доступ к репозиторию GitHub (deploy key только на чтение) | `git clone` |
| Аккаунт Cloudflare (бесплатный план достаточен) | §7 |
| Пароли и секреты: `openssl rand -hex 32` × 5, `openssl rand -base64 32` для `APP_KEY`, `openssl rand -base64 48` для `BACKUP_ARCHIVE_PASSWORD` | `.env` §3 |

---

## 1. Сервер: система, пользователь, файрвол, Docker

```bash
# от root
apt update && apt -y full-upgrade && apt -y install ca-certificates curl git ufw util-linux jq
timedatectl set-timezone Asia/Tashkent          # часовой пояс сервера (логи, cron хоста); приложение живёт в UTC (config/app.php), ученики — в своём timezone
adduser deploy && usermod -aG sudo deploy
mkdir -p /home/deploy/.ssh && cp ~/.ssh/authorized_keys /home/deploy/.ssh/ && chown -R deploy:deploy /home/deploy/.ssh

# файрвол: только SSH, HTTP (ACME и редирект), HTTPS. PostgreSQL, Redis, 9000 и 8000 наружу не публикуются (compose), ufw их тоже не открывает.
ufw default deny incoming && ufw default allow outgoing
ufw allow OpenSSH && ufw allow 80/tcp && ufw allow 443/tcp
ufw enable

# Docker Engine + compose v2 из репозитория Docker (не snap, не docker.io из Ubuntu)
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo $VERSION_CODENAME) stable" > /etc/apt/sources.list.d/docker.list
apt update && apt -y install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
usermod -aG docker deploy
docker compose version                           # v2.x
```

Swap при 4 ГБ RAM и меньше (сборка образов любит память): `fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile && echo '/swapfile none swap sw 0 0' >> /etc/fstab`.

Docker пишет свои правила в iptables **мимо ufw**, но только для опубликованных портов — в `docker-compose.prod.yml` это 80 и 443 у nginx, больше ничего. Проверить после §4: `ss -ltnp | grep -E ':(5432|6379|6380|9000|8000)\b'` — пусто.

Дальше всё — от пользователя `deploy` (`su - deploy`).

---

## 2. DNS на старте — серое облако

До Cloudflare или с Cloudflare, но **без прокси** (серое облако, DNS only): запись `A <домен> → IP сервера`. `AAAA` не создавать
(docs/23 §1: compose-сеть только IPv4). Проверить: `dig +short <домен>` = IP сервера. Первый сертификат Let's Encrypt
выпускается напрямую на сервер — это и есть причина серого облака на этом шаге.

---

## 3. Код и `.env`

```bash
sudo mkdir -p /var/www && sudo chown deploy:deploy /var/www
git clone <repo> /var/www/lexiflow && cd /var/www/lexiflow   # ровно этот путь ждёт job deploy в CI (ci.yml: cd /var/www/lexiflow)
cp .env.example .env && chmod 600 .env
```

Заполнить в `.env` (полные комментарии — в самом `.env.example`; значения запуска «только Stars, практика всем» — `VPS_DEPLOY_GUIDE_RU.md` §10
«Значения на запуск», они одинаковы для обоих путей):

```dotenv
APP_NAME="LexiFlow Pro"
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:<openssl rand -base64 32>
APP_URL=https://<домен>
APP_DOMAIN=<домен>
CERTBOT_EMAIL=<почта для Let's Encrypt>
APP_LOCALE=ru
SESSION_SECURE_COOKIE=true

# База — через pgbouncer (R12d). DB_HOST именно pgbouncer, миграции compose направит в postgres сам.
DB_CONNECTION=pgsql
DB_HOST=pgbouncer
DB_PORT=5432
DB_DATABASE=lexiflow
DB_USERNAME=lexiflow
DB_PASSWORD=<openssl rand -hex 32>
DB_PERSISTENT=true
DB_DISABLE_PREPARES=true

# Redis ×2 — оба пароля обязательны, deploy.sh остановится на пустом/null
REDIS_PASSWORD=<openssl rand -hex 32>
REDIS_CACHE_PASSWORD=<openssl rand -hex 32>
# REDIS_PERSISTENT не задавать: compose включает его для app и app-api сам (R13 шаг 3)

# Telegram
TELEGRAM_BOT_TOKEN=<от BotFather>
TELEGRAM_BOT_USERNAME=<без @>
TELEGRAM_MINI_APP_SHORT_NAME=<short name Mini App>
TELEGRAM_URL_SECRET=<openssl rand -hex 32>
TELEGRAM_HEADER_SECRET=<openssl rand -hex 32>
TELEGRAM_WEBHOOK_URL=https://<домен>            # telegram:set-webhook добавит /telegram/webhook/<URL_SECRET> сам
TELEGRAM_IP_ALLOWLIST_ENABLED=true

# Mini App
TWA_JWT_SECRET=<openssl rand -hex 32>
TWA_BASE_URL=https://<домен>/twa/

# Бэкапы
BACKUP_DISKS=local
BACKUP_ARCHIVE_PASSWORD=<openssl rand -base64 48>

# Биллинг и практика на запуск — блок из VPS_DEPLOY_GUIDE_RU.md §10 (BILLING_PAYWALL_MODE=purchase, BILLING_STARS_ENABLED=true,
# PAYME_ENABLED=false, CLICK_ENABLED=false, BILLING_SUPPORT_CONTACT, цены, PRACTICE_ACCESS=all ...), реквизиты SITE_LEGAL_*.

# R13: НЕ задавать APP_SURFACE, APP_SERVER (пока fpm), TRUSTED_PROXIES, CLOUDFLARE_* — умолчания compose верны на этом шаге.
# PG_* — только если сервер не 4 vCPU / 8 ГБ (docs/22 §2).
```

Проверка перед деплоем: `grep -E '^(APP_KEY|DB_PASSWORD|REDIS_PASSWORD|REDIS_CACHE_PASSWORD|TWA_JWT_SECRET|TWA_BASE_URL|TELEGRAM_BOT_TOKEN|TELEGRAM_URL_SECRET|TELEGRAM_HEADER_SECRET|TELEGRAM_WEBHOOK_URL|CERTBOT_EMAIL|APP_DOMAIN)=' .env` —
все двенадцать непустые. Почта (`MAIL_*`) и Sentry (`SENTRY_LARAVEL_DSN`) можно добавить позже: обычный `deploy.sh` применит.

---

## 4. Первый деплой

```bash
cd /var/www/lexiflow
git fetch origin master
bash deploy.sh $(git rev-parse origin/master)     # SHA зелёного коммита master
```

Что произойдёт (подробно — docs/22 §3): лок, checkout SHA, проверка паролей Redis, сборка образов `app`, `app-fpm`, `nginx`,
`pgbouncer` (первая сборка 10–15 минут), **первичный сертификат** (`certbot certonly --standalone` на порту 80 — поэтому серое
облако и свободный порт 80), миграции мимо pgbouncer, `up -d`, проверки (`about`, список маршрутов `app-api`, настройки
PostgreSQL), HTTPS-smoke (`/up`, `/api/twa/me` → 401, `/health/ready` — ждёт пульсы планировщика и очередей до 5 минут,
`/twa/index.html`, `/build/manifest.json`). Любая ошибка — `exit 1` с подсказкой. Предупреждения (не остановки) о забытых настройках прода — §10.

Сразу после:

```bash
C="docker compose -f docker-compose.prod.yml --env-file .env"
$C ps                                                      # все healthy
# роли и товары магазина уже созданы: deploy.sh запускает ReferenceDataSeeder после миграций; вручную (необязательно):
$C exec -T app php artisan db:seed --class=ReferenceDataSeeder --force
$C exec -T app php artisan tinker                          # первый администратор:
# >>> \App\Models\User::create(['name' => 'Admin', 'email' => 'admin@<домен>', 'password' => bcrypt('<пароль>')])->assignRole('admin');
# вебхук Telegram и меню команд / кнопка Mini App deploy.sh ставит сам (docker/telegram-setup.sh: telegram:setup-bot, telegram:set-webhook);
# без TELEGRAM_BOT_TOKEN / TELEGRAM_WEBHOOK_URL / секретов шаг пропускается с WARNING, ошибка Telegram API - тоже только WARNING. Вручную:
$C exec -T app sh docker/telegram-setup.sh                 # https://<домен>/telegram/webhook/<URL_SECRET>, секрет заголовка — HEADER_SECRET
```

Проверить руками: `https://<домен>/admin/login` → вход → настройка 2FA; в Telegram боту `/start`, кнопка Mini App открывается;
в Mini App — тренировка. Чек-лист — `README.md` «Smoke-test чек-лист», `docs/22` §4 (две поверхности), `07_SECURITY.md` перед открытием продаж.

---

## 5. Замер ёмкости в php-fpm (до Octane)

`docs/22` §5: `perf:seed`, токены `load:tokens` (на проде — только до открытия продаж или на staging с `APP_ENV=staging`),
`make load-test` с другой машины (или `K6_NET=host` с вычетом ~0,4 ядра). Записать: CPU на запрос, p95 по эндпоинтам,
`TARGET_VUS`, при которых p95 < 300 мс и нет 5xx. Без этого числа у Octane не будет точки сравнения.

Если Cloudflare уже включён (§7 раньше §5) — k6 гонять мимо него через `origin.<домен>` (docs/23 §8).

---

## 6. Octane для `app-api`

`docs/22` §7 целиком. Коротко:

```dotenv
APP_SERVER=octane
APP_API_DB_PERSISTENT=false
```

`bash deploy.sh <sha>` (соберёт `app-octane`, пересоздаст `app-api` и nginx; при смене подсети compose — `down` всего стека,
делать в тихий час). Проверки из docs/22 §7 п. 3: `Octane server is running.`, `config:show trustedproxy` содержит адрес nginx,
`nginx -T | grep -A3 'upstream lexiflow_api_octane'` → `keepalive 32`, smoke 401/422, `docker stats`, в Mini App — тренировка,
аудио практики и игры (потоковые ответы), оплата Stars. Затем k6 повторно (§5) — сравнить. Откат: `APP_SERVER=fpm` +
`$C up -d app-api nginx`, сборки не нужно.

---

## 7. Cloudflare

`docs/23` по порядку: §1 DNS (NS на Cloudflare, запись `A`, **без `AAAA`**, отдельная `origin.<домен>` с серым облаком),
§2 SSL/TLS **Full (strict)** — иначе Cloudflare → origin пойдёт по HTTP или без проверки сертификата, §3 Cache Rules для
`/twa/assets/*`, `/build/*`, `/storage/audio/*`, §4 Speed (Rocket Loader, Auto Minify, Mirage/Polish, Email Obfuscation — выключить), §5 Security (WAF Managed rules — включить;
правило Skip для челленджей по путям `/api/`, `/telegram/webhook/`, `/billing/webhook/`; Bot Fight Mode на Free плане по путям не
отключается — выключить целиком, если ученики или вебхуки получают 403; Browser Integrity Check — выключить).

Затем на сервере:

```dotenv
CLOUDFLARE_PROXY=true
CLOUDFLARE_ONLY=false
```

`$C up -d nginx` (docs/23 §6), **и только после этого** оранжевое облако на записях `<домен>`/`www`. Проверка docs/23 §9:
в access-логе nginx адреса учеников, а не `104.x/172.6x.x`; `cf-cache-status: HIT` на статике со второго запроса;
вебхук Telegram доходит (`TELEGRAM_IP_ALLOWLIST_ENABLED=true` остаётся: адрес восстановлен из `CF-Connecting-IP`).

Через день-два без ошибок — origin lock: `CLOUDFLARE_ONLY=true`, `$C up -d nginx` (docs/23 §7). Прямой доступ к серверу по
IP на 443 станет 403; `origin.<домен>` для k6 и аварийного доступа — через `--resolve` (docs/23 §8).

Что Cloudflare разгружает и на сколько: docs/23 §10 (статика Mini App, аудио, картинки — да; `/api/*` и вебхуки — нет,
они всегда доходят до сервера).

---

## 8. Каждый следующий деплой

```bash
cd /var/www/lexiflow && git fetch origin master && bash deploy.sh <sha-зелёного-коммита>
```

Откат — `bash deploy.sh --rollback <предыдущий-sha>` (DEVOPS_GUIDE §0 «Откат»). Правка `.env` без смены кода —
тот же `deploy.sh` (он пересоздаёт контейнеры с изменившимся окружением).

**Авто-деплой из CI** (DEVOPS_GUIDE §0): job `deploy` на каждом зелёном push в `master` заходит по SSH и выполняет
`cd /var/www/lexiflow && bash deploy.sh <sha>`. Включается тремя секретами окружения GitHub `LexiFlow`: `SSH_HOST`, `SSH_USER`,
`SSH_PRIVATE_KEY` — **все три или ни одного**: с одним-двумя job падает красным («Deploy secrets incomplete»), без всех трёх —
пропускается с предупреждением. Добавлять их только когда §1–§4 выполнены: с момента добавления каждый merge в `master`
уходит на сервер автоматически. `SSH_USER` — пользователь `deploy` из §1 (в группе `docker`, владелец `/var/www/lexiflow`), ключ —
отдельная пара только для CI (`ssh-keygen -t ed25519 -C ci-deploy`, публичный в `~deploy/.ssh/authorized_keys`, приватный — в секрет).

---

## 9. Чек-лист порядка

- [ ] §1 система, `deploy`, ufw 22/80/443, Docker из репозитория Docker
- [ ] §2 `A`-запись с серым облаком, `dig` отдаёт IP сервера
- [ ] §3 `.env` (двенадцать обязательных значений непустые, блок запуска из VPS_DEPLOY_GUIDE §10), `chmod 600`
- [ ] §4 `deploy.sh <sha>` зелёный (вебхук и меню бота он ставит сам — проверить, что нет `WARNING` про Telegram); роли, админ, 2FA; Mini App открывается
- [ ] §5 k6 в php-fpm — числа записаны (docs/19 §8)
- [ ] §6 `APP_SERVER=octane` + `APP_API_DB_PERSISTENT=false`, деплой, проверки, k6 повторно
- [ ] §7 Cloudflare: панель (Full strict, Cache Rules, без AAAA) → `CLOUDFLARE_PROXY=true` → оранжевое облако → проверка → позже `CLOUDFLARE_ONLY=true`
- [ ] §10 чек-лист запуска (allowlist Telegram, Payme, бэкапы, `APP_DEBUG`, хосты, Sentry)
- [ ] `07_SECURITY.md` «перед открытием продаж», бэкапы (`make backup` — архив есть), Sentry DSN

---

## 10. Чек-лист запуска: что выключено по умолчанию

Значения по умолчанию в `.env.example` рассчитаны на локальную разработку и **небезопасны** (или бесполезны) в проде. `deploy.sh` печатает
`WARNING` на каждое из них до сборки, но не останавливается — смотреть вывод деплоя. Проверить на сервере (`grep -E '^(NAME1|NAME2)=' .env`):

| Настройка | Значение в проде | Почему |
|---|---|---|
| `TELEGRAM_IP_ALLOWLIST_ENABLED` | `true` | вебхук принимает запросы только с адресов Telegram, а не с любого, кто узнал секрет URL и заголовка (аудит L3). Умолчание `false` нужно ngrok-у и локальной разработке |
| `PAYME_ALLOW_TEST_KEY` | `false` | иначе принимается тестовый ключ Payme |
| `PAYME_IP_ALLOWLIST`, `CLICK_IP_ALLOWLIST` | заданы, когда провайдер включён | иначе платёжные вебхуки открыты всем (проверяются подписью, но allowlist — второй рубеж) |
| `BACKUP_ARCHIVE_PASSWORD` | задан (`openssl rand -base64 48`) | архивы шифруются; пустой — бэкап падает или лежит открытым |
| `BACKUP_DISKS` | не только `local` (добавить `s3`) | бэкап на диске самого сервера пропадает вместе с сервером |
| `APP_DEBUG` | `false` | страницы ошибок раскрывают код, пути, окружение (`ReadinessProbe::checkConfig` сообщает об этом) |
| `APP_TRUSTED_HOSTS` | задан при нескольких доменах (основной + алиасы, www) | иначе Host подделывается: отравление ссылок (сброс пароля) |
| `SENTRY_TRACES_SAMPLE_RATE` | `0` (не больше `0.01`) | трейсы каждого запроса API стоят CPU на горячем пути (аудит H1) |
| `SENTRY_BREADCRUMBS_SQL_QUERIES_ENABLED` / `_CACHE_ENABLED` | `false` | то же |
| юридические страницы, режим пейвола | заполнены (`SITE_LEGAL_*`, `BILLING_PAYWALL_MODE`) | блокер запуска, аудит §4 |

`deploy.sh` проверяет из них: `TELEGRAM_IP_ALLOWLIST_ENABLED`, `PAYME_ALLOW_TEST_KEY`, `APP_DEBUG`, `BACKUP_ARCHIVE_PASSWORD`, `BACKUP_DISKS`,
`SENTRY_TRACES_SAMPLE_RATE`. Остальное — руками по этой таблице.

