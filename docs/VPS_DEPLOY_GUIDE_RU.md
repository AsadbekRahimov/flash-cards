# LexiFlow Pro — запуск на VPS с нуля (без Docker)

Пошаговое руководство: от чистого сервера до работающего бота, мини-приложения (TWA) и админки.
Ориентир — **Ubuntu 24.04 LTS**; на Debian 12 команды те же, кроме подключения репозитория PHP (см. §2).

> Вариант с Docker (`docker-compose.prod.yml` + `deploy.sh`) описан в `README.md` и `docs/DEVOPS_GUIDE.md` §0.
> Здесь — «голый» сервер: всё ставится пакетами. Подробности по отдельным темам (оплата, мониторинг,
> переменные Фазы 2) — в `docs/DEVOPS_GUIDE.md`; приёмка Фазы 2 — `docs/STAGING_PHASE2_CHECKLIST.md`.

---

## 0. Что понадобится заранее

| Что | Зачем |
|---|---|
| VPS: минимум **2 vCPU, 4 ГБ RAM, 40 ГБ SSD** (для старта хватит 2 ГБ RAM + swap) | PHP-FPM, PostgreSQL, Redis, очередь, сборка фронтенда |
| Домен (например `lexiflow.uz`) с **A-записью** на IP сервера | HTTPS обязателен: Telegram шлёт вебхуки и открывает мини-приложение только по HTTPS |
| Токен бота от **@BotFather** и его username | `TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME` |
| Доступ к репозиторию на GitHub (SSH-ключ или токен, если репозиторий приватный) | `git clone` |
| SMTP-почта (по желанию на старте) | письма владельцам центров, напоминания о продлении, бэкапы |

Стек, который мы поставим:

| Компонент | Версия | Примечание |
|---|---|---|
| PHP + PHP-FPM | **8.5** | версия CI и Docker-образов; в Ubuntu 24.04 штатно 8.3, поэтому ставим из `ppa:ondrej/php` (§2) |
| PostgreSQL | **18** | основная БД; из официального репозитория PGDG (§4), штатный в Ubuntu 24.04 — 16 |
| Redis | **8.10+** | два экземпляра: очереди/сессии/блокировки (6379) и чистый кэш (6380); из репозитория `packages.redis.io` (§5) |
| Nginx | из репозитория Ubuntu | веб-сервер |
| Node.js | **26 LTS** | только для сборки фронтенда (Vite) |
| Composer | 2 | зависимости PHP |
| Supervisor | из репозитория | воркеры очереди |
| Certbot | из репозитория | сертификат Let's Encrypt |

Во всех примерах ниже замените:
- `lexiflow.uz` → ваш домен;
- `admin@lexiflow.uz` → ваша почта;
- `СИЛЬНЫЙ_ПАРОЛЬ` → настоящий пароль (`openssl rand -base64 24`).

---

## 1. Первичная настройка сервера

Подключитесь под root:

```bash
ssh root@IP_СЕРВЕРА
```

### 1.1 Обновить систему, часовой пояс, базовые утилиты

```bash
apt update && apt -y upgrade
apt -y install curl wget git unzip zip ca-certificates gnupg lsb-release software-properties-common \
    ufw fail2ban htop nano acl
timedatectl set-timezone UTC      # сервер живёт в UTC; часовые пояса учеников приложение считает само
```

> Часовой пояс сервера — **UTC**. Расписание планировщика (например `subscriptions:remind-expiring` в 06:00 UTC)
> и `LEAGUES_TIMEZONE=Asia/Tashkent` для лиг рассчитаны именно на это.

### 1.2 Пользователь для деплоя

Работать под root постоянно не нужно. Создаём пользователя `deploy` и даём ему sudo:

```bash
adduser deploy
usermod -aG sudo deploy
usermod -aG www-data deploy          # чтобы deploy мог писать в storage/ вместе с php-fpm
mkdir -p /home/deploy/.ssh
cp ~/.ssh/authorized_keys /home/deploy/.ssh/
chown -R deploy:deploy /home/deploy/.ssh && chmod 700 /home/deploy/.ssh && chmod 600 /home/deploy/.ssh/authorized_keys
```

Проверьте в **новом** окне терминала, что `ssh deploy@IP_СЕРВЕРА` работает, и только потом закройте вход root по паролю:

```bash
nano /etc/ssh/sshd_config.d/99-hardening.conf
```

```text
PermitRootLogin no
PasswordAuthentication no
```

```bash
systemctl restart ssh
```

### 1.3 Файрвол

```bash
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw enable
ufw status
```

PostgreSQL (5432) и оба Redis (6379, 6380) **не открываем** наружу — они слушают только `127.0.0.1`.

### 1.4 Swap (если RAM ≤ 2–4 ГБ)

Сборка фронтенда и composer любят память:

```bash
fallocate -l 2G /swapfile
chmod 600 /swapfile
mkswap /swapfile && swapon /swapfile
echo '/swapfile none swap sw 0 0' >> /etc/fstab
```

Дальше все команды — от пользователя `deploy` через `sudo`, где нужно.

---

## 2. Установка PHP 8.5 и расширений

В штатном репозитории Ubuntu 24.04 только PHP 8.3 (в Debian 12 — 8.2), а проекту нужен 8.5. Подключите репозиторий
Ondřej Surý: в нём собраны актуальные версии PHP для Ubuntu (`ppa:ondrej/php`) и Debian (`packages.sury.org/php`).

**Ubuntu** (22.04 / 24.04):

```bash
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update
```

**Debian** (12 и новее):

```bash
sudo curl -sSLo /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb
sudo dpkg -i /tmp/debsuryorg-archive-keyring.deb
echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" \
    | sudo tee /etc/apt/sources.list.d/php.list
sudo apt update
```

Дальше одинаково для обоих дистрибутивов:

```bash
sudo apt -y install php8.5-fpm php8.5-cli php8.5-common \
    php8.5-pgsql php8.5-redis php8.5-bcmath php8.5-intl php8.5-mbstring \
    php8.5-xml php8.5-zip php8.5-gd php8.5-curl
```

> OPcache в PHP 8.5 встроен в ядро: отдельный пакет (`php8.5-opcache`) ставить и подключать не нужно.

Зачем расширения:
- `pgsql` — PostgreSQL;
- `redis` — кэш, очереди, сессии, глобальный лимит Telegram (Lua-скрипт);
- `bcmath`, `intl`, `mbstring` — деньги, локали ru/uz, строки;
- `gd` (с FreeType) — карточки достижений;
- `zip`, `xml`, `curl` — composer, импорт, HTTP-клиенты;
- `pcntl` встроен в `php8.5-cli` — нужен воркерам очереди.

Проверка:

```bash
php -v                                         # PHP 8.5.x
php -m | grep -E 'pgsql|redis|bcmath|intl|mbstring|gd|zip|pcntl|OPcache'
php -r 'var_dump(gd_info()["FreeType Support"]);'   # bool(true) — без этого не рисуются карточки достижений
```

### 2.1 Настройки PHP для продакшена

Создайте файл, который подхватят и FPM, и CLI:

```bash
sudo nano /etc/php/8.5/mods-available/lexiflow.ini
```

```ini
; Безопасность: аргументы вызовов (тексты чатов, initData, URL) не попадают в трассировки исключений
zend.exception_ignore_args = On
expose_php = Off

memory_limit = 256M
upload_max_filesize = 20M
post_max_size = 20M
max_execution_time = 60

opcache.enable = 1
opcache.memory_consumption = 192
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0   ; после каждого деплоя нужен reload php-fpm (см. §13)
```

```bash
sudo phpenmod lexiflow
```

> Первые две строки повторяют `docker/php/zz-lexiflow.ini` из репозитория. Остальное — разумные значения для старта.
> `opcache.validate_timestamps = 0` даёт прирост скорости, но после обновления кода обязательно
> `sudo systemctl reload php8.5-fpm`, иначе PHP продолжит выполнять старый код.

### 2.2 Пул PHP-FPM

```bash
sudo nano /etc/php/8.5/fpm/pool.d/www.conf
```

Проверьте/поменяйте:

```ini
user = www-data
group = www-data
listen = /run/php/php8.5-fpm.sock
listen.owner = www-data
listen.group = www-data

pm = dynamic
pm.max_children = 20        ; ~ (RAM под PHP) / 60 МБ; для 4 ГБ RAM — 20–30
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 6
pm.max_requests = 500
```

```bash
sudo systemctl restart php8.5-fpm
sudo systemctl enable php8.5-fpm
```

---

## 3. Установка Composer

```bash
cd /tmp
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm composer-setup.php
composer --version            # Composer version 2.x
```

---

## 4. Установка PostgreSQL 18

Штатный PostgreSQL в Ubuntu 24.04 — 16-й, поэтому 18-й ставим из официального репозитория PGDG (`apt.postgresql.org`);
шаги одинаковы для Ubuntu и Debian. Подключение репозитория:

```bash
sudo install -d /usr/share/postgresql-common/pgdg
sudo curl -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc --fail https://www.postgresql.org/media/keys/ACCC4CF8.asc
echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt $(lsb_release -cs)-pgdg main" \
    | sudo tee /etc/apt/sources.list.d/pgdg.list
sudo apt update
```

Установка:

```bash
sudo apt -y install postgresql-18       # контрибные модули входят в пакет; клиент (psql, pg_dump) 18-й версии ставится вместе с ним
sudo systemctl enable --now postgresql
psql --version                          # psql (PostgreSQL) 18.x
```

### 4.1 Пользователь и база

```bash
sudo -u postgres psql
```

```sql
CREATE USER lexiflow WITH PASSWORD 'СИЛЬНЫЙ_ПАРОЛЬ';
CREATE DATABASE lexiflow OWNER lexiflow ENCODING 'UTF8' TEMPLATE template0;
GRANT ALL PRIVILEGES ON DATABASE lexiflow TO lexiflow;
\q
```

Проверка подключения:

```bash
psql -h 127.0.0.1 -U lexiflow -d lexiflow -c 'select version();'
```

### 4.2 Доступ только с localhost

По умолчанию PostgreSQL в Ubuntu слушает только `localhost` — так и оставляем. Убедитесь:

```bash
sudo grep -E "^#?listen_addresses" /etc/postgresql/18/main/postgresql.conf   # 'localhost'
sudo ss -ltnp | grep 5432                                                     # 127.0.0.1:5432
```

### 4.3 Базовый тюнинг (для 4 ГБ RAM)

```bash
sudo nano /etc/postgresql/18/main/conf.d/lexiflow.conf
```

```ini
shared_buffers = 1GB
effective_cache_size = 3GB
work_mem = 16MB
maintenance_work_mem = 256MB
max_connections = 100
```

```bash
sudo systemctl restart postgresql
```

---

## 5. Установка Redis 8.10 — два экземпляра

В штатных репозиториях Ubuntu 24.04 и Debian 12 лежит Redis 7.0, а Docker-стек проекта закреплён на `redis:8.10-alpine`
(`docs/19_REDIS_CACHE_PLAN.md` §1), поэтому ставим 8.10 или новее из официального репозитория Redis. Экземпляра два
(`docs/19` §4): **первый** (6379) — очереди, сессии, блокировки, лимиты и маркеры, ничего из него не должно
вытесняться; **второй** (6380) — чистый кэш, который можно потерять в любой момент.

```bash
curl -fsSL https://packages.redis.io/gpg | sudo gpg --dearmor -o /usr/share/keyrings/redis-archive-keyring.gpg
sudo chmod 644 /usr/share/keyrings/redis-archive-keyring.gpg
echo "deb [signed-by=/usr/share/keyrings/redis-archive-keyring.gpg] https://packages.redis.io/deb $(lsb_release -cs) main" \
    | sudo tee /etc/apt/sources.list.d/redis.list
sudo apt update
sudo apt -y install redis-server
redis-server --version                  # Redis server v=8.10 или новее
sudo nano /etc/redis/redis.conf
```

**Первый экземпляр** (штатный `redis-server`, порт 6379). Найдите и поменяйте:

```text
bind 127.0.0.1 -::1
supervised systemd
maxmemory 256mb
maxmemory-policy noeviction
requirepass ПАРОЛЬ_1            # openssl rand -hex 32; в .env -> REDIS_PASSWORD
```

> **`noeviction` обязательно** для первого экземпляра: здесь очереди, сессии, блокировки, лимиты входа и защита 2FA.
> С политикой вытеснения Redis под нехваткой памяти мог бы выкинуть задание оплаты или сбросить лимит попыток.

```bash
sudo systemctl restart redis-server
sudo systemctl enable redis-server
REDISCLI_AUTH=ПАРОЛЬ_1 redis-cli ping            # PONG
```

**Второй экземпляр** (чистый кэш, порт 6380): свой конфиг и свой unit systemd. Без сохранения на диск — после
перезапуска он пуст, приложение пересчитает всё из базы; `volatile-lru` вытесняет ключи с TTL при нехватке памяти.

```bash
sudo tee /etc/redis/redis-cache.conf >/dev/null <<'EOF'
bind 127.0.0.1 -::1
port 6380
supervised systemd
daemonize no
pidfile /run/redis-cache/redis-cache.pid
logfile /var/log/redis/redis-cache.log
dir /var/lib/redis
maxmemory 256mb
maxmemory-policy volatile-lru
save ""
appendonly no
requirepass ПАРОЛЬ_2            # openssl rand -hex 32; в .env -> REDIS_LRU_PASSWORD
EOF
sudo chown redis:redis /etc/redis/redis-cache.conf && sudo chmod 640 /etc/redis/redis-cache.conf

sudo tee /etc/systemd/system/redis-cache.service >/dev/null <<'EOF'
[Unit]
Description=Redis (LexiFlow pure cache, volatile-lru, no persistence)
After=network.target

[Service]
Type=notify
User=redis
Group=redis
ExecStart=/usr/bin/redis-server /etc/redis/redis-cache.conf
# No ExecStop with the password: SIGTERM from systemd shuts Redis down cleanly, and there is nothing to save here.
TimeoutStopSec=10
Restart=always
RuntimeDirectory=redis-cache
RuntimeDirectoryMode=2755

[Install]
WantedBy=multi-user.target
EOF
sudo systemctl daemon-reload
sudo systemctl enable --now redis-cache
REDISCLI_AUTH=ПАРОЛЬ_2 redis-cli -p 6380 ping    # PONG
REDISCLI_AUTH=ПАРОЛЬ_2 redis-cli -p 6380 config get maxmemory-policy   # volatile-lru
```

Пароли — только hex-символы (`openssl rand -hex 32`). Мониторинг обоих экземпляров (`INFO`: `used_memory`,
`evicted_keys`, `keyspace_hits`/`keyspace_misses`) и что делать при росте памяти — `docs/DEVOPS_GUIDE.md` §0, «Redis».

---

## 6. Установка Nginx, Node.js, Supervisor, Certbot

```bash
sudo apt -y install nginx supervisor certbot python3-certbot-nginx
sudo systemctl enable --now nginx supervisor
```

Node.js 26 LTS из репозитория NodeSource (нужен только для сборки фронтенда; та же версия, что в CI и Docker-образах):

```bash
curl -fsSL https://deb.nodesource.com/setup_26.x | sudo -E bash -
sudo apt -y install nodejs
node -v      # v26.x
npm -v
```

> Если не хотите ставить Node.js на сервер — соберите фронтенд на своём компьютере и загрузите готовые
> каталоги `public/build/` и `public/twa/` (включая файлы `*.gz`) через `rsync`. См. §9.

---

## 7. Получение кода

```bash
sudo mkdir -p /var/www
sudo chown deploy:www-data /var/www
cd /var/www
git clone https://github.com/AsadbekRahimov/flash-cards.git lexiflow
cd lexiflow
git checkout master
```

> Приватный репозиторий: добавьте SSH-ключ сервера (`ssh-keygen -t ed25519`, публичный ключ — в
> GitHub → Settings → Deploy keys, только чтение) и клонируйте по `git@github.com:AsadbekRahimov/flash-cards.git`.

### 7.1 Права на каталоги

Код принадлежит `deploy`, а писать в `storage/` и `bootstrap/cache/` должен и `www-data` (php-fpm, воркеры, cron):

```bash
sudo chown -R deploy:www-data /var/www/lexiflow
sudo find /var/www/lexiflow -type d -exec chmod 755 {} \;
sudo find /var/www/lexiflow -type f -exec chmod 644 {} \;
sudo chmod -R ug+rwX storage bootstrap/cache
sudo setfacl -R  -m u:www-data:rwX -m u:deploy:rwX storage bootstrap/cache
sudo setfacl -dR -m u:www-data:rwX -m u:deploy:rwX storage bootstrap/cache
mkdir -p storage/app/public/audio storage/app/public/achievements storage/app/practice-audio
```

`storage/app/practice-audio` — озвучка фраз режима практики «Слушай и пиши» (`GeneratePracticeAudioJob`, очередь `low`). Каталог **приватный: nginx его НЕ раздаёт**
(в конфигурации из §12.3 для него нет `location`, и добавлять не нужно — файлы отдаёт только эндпоинт API с JWT). Права и ACL — те же, что у остального `storage/`
(воркер пишет, php-fpm читает); бэкап `backup:run` включает каталог так же, как `storage/app/public` (§18).

---

## 8. PHP-зависимости

```bash
cd /var/www/lexiflow
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist
```

---

## 9. Сборка фронтенда

В проекте **две** сборки: ассеты Laravel (`public/build`) и мини-приложение Telegram (`public/twa`).

```bash
cd /var/www/lexiflow
npm ci --no-audit --no-fund
npm run build                         # → public/build

cd resources/twa
npm ci --no-audit --no-fund           # именно без --omit=dev: vite и vue-tsc лежат в devDependencies
npm run build                         # → public/twa (+ предсжатые *.gz для nginx gzip_static)
cd ../..

rm -rf node_modules resources/twa/node_modules    # по желанию: освободить место
```

---

## 10. Настройка `.env`

```bash
cd /var/www/lexiflow
cp .env.example .env
chmod 640 .env
sudo chown deploy:www-data .env
php artisan key:generate
```

Сгенерируйте секреты:

```bash
echo "TELEGRAM_URL_SECRET=$(openssl rand -hex 24)"
echo "TELEGRAM_HEADER_SECRET=$(openssl rand -hex 24)"
echo "TWA_JWT_SECRET=$(openssl rand -hex 32)"
echo "BACKUP_ARCHIVE_PASSWORD=$(openssl rand -base64 48)"
```

Откройте `nano .env` и задайте **как минимум** следующее (остальные строки `.env.example` можно оставить по умолчанию):

```env
APP_NAME="LexiFlow Pro"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://lexiflow.uz
APP_DOMAIN=lexiflow.uz
APP_LOCALE=ru
APP_FALLBACK_LOCALE=ru
APP_MAINTENANCE_DRIVER=file

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=warning
LOG_DAILY_DAYS=14

# --- База ---
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=lexiflow
DB_USERNAME=lexiflow
DB_PASSWORD=СИЛЬНЫЙ_ПАРОЛЬ

# --- Redis: первый экземпляр (6379) — очереди, сессии, блокировки, лимиты ---
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=ПАРОЛЬ_1
REDIS_DB=0
REDIS_CACHE_DB=1
REDIS_QUEUE_CONNECTION=queue
REDIS_QUEUE_DB=2
REDIS_SESSION_DB=3
REDIS_LOCK_DB=4
REDIS_QUEUE_BLOCK_FOR=5
REDIS_MAXMEMORY=256mb
# --- Redis: второй экземпляр (6380) — чистый кэш (§5) ---
REDIS_LRU_HOST=127.0.0.1
REDIS_LRU_PORT=6380
REDIS_LRU_PASSWORD=ПАРОЛЬ_2
REDIS_LRU_DB=0
REDIS_CACHE_MAXMEMORY=256mb
CACHE_STORE=redis
CACHE_LIMITER_STORE=redis
CACHE_PURE_STORE=lru
SESSION_DRIVER=redis
SESSION_CONNECTION=session
QUEUE_CONNECTION=redis

SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax

# --- Telegram ---
TELEGRAM_BOT_TOKEN=123456:ABC...            # от @BotFather
TELEGRAM_BOT_USERNAME=LexiFlowBot           # без @; ОБЯЗАТЕЛЬНО: без него нет ссылок-приглашений, рефералки, «Поделиться» и кнопок мини-приложения в группах (§15)
TELEGRAM_MINI_APP_SHORT_NAME=               # short name из BotFather /newapp; пусто = Main Mini App бота (§15)
TELEGRAM_URL_SECRET=...                     # сгенерировано выше
TELEGRAM_HEADER_SECRET=...                  # сгенерировано выше
TELEGRAM_WEBHOOK_URL=https://lexiflow.uz
TELEGRAM_QUEUE=high
TELEGRAM_IP_ALLOWLIST_ENABLED=true          # принимать вебхуки только с IP Telegram

# --- Мини-приложение (TWA) ---
TWA_JWT_SECRET=...                          # сгенерировано выше
TWA_BASE_URL=https://lexiflow.uz

# --- Почта (на старте можно MAIL_MAILER=log — письма только пишутся в лог) ---
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="no-reply@lexiflow.uz"
MAIL_FROM_NAME="${APP_NAME}"

# --- Бэкапы ---
BACKUP_DISKS=local
BACKUP_ARCHIVE_PASSWORD=...                 # сгенерировано выше — храните отдельно от сервера!
BACKUP_NOTIFICATION_MAIL=admin@lexiflow.uz

# --- Мониторинг (по желанию) ---
SENTRY_LARAVEL_DSN=
SENTRY_ENVIRONMENT=production
```

Что ещё стоит заполнить, когда дойдёте до этого:
- **Юридические данные сайта** `SITE_LEGAL_*` и `BILLING_INVOICE_REQUEST_EMAIL` — публичные страницы оферты/политики.
- **Оплата** (Telegram Stars, Payme, Click) — `BILLING_*`, `PAYME_*`, `CLICK_*`: см. `docs/DEVOPS_GUIDE.md` §17.
  На старте оплату можно не включать; включать **только после проверки в песочницах** (`docs/STAGING_PHASE2_CHECKLIST.md`).
  `PAYME_ALLOW_TEST_KEY` в продакшене должен быть выключен.
- **Переменные Фазы 2** (лиги, рефералы, FSRS, достижения, симулятор, годовая скидка центров `BILLING_ORG_YEAR_DISCOUNT_PERCENT=17`) —
  у всех есть разумные значения по умолчанию; таблица — `docs/DEVOPS_GUIDE.md`, раздел «Phase 2 variables».
- **Озвучка слов** `TTS_SERVICE_URL` — адрес сервиса edge-tts. Если его нет, ученики слышат озвучку браузера (Web Speech);
  предгенерация аудио (`audio:pregenerate`) просто не будет работать. Можно настроить позже.
- **Практика** (четыре режима: пары, сочетание, предложение, диктант) — по умолчанию **выключена**: `PRACTICE_ACCESS=off`. Включайте ступенями, когда импортированы и проверены задания (`docs/ADMIN_GUIDE.md`, раздел «Practice tasks»):
  `PRACTICE_ACCESS=pilot` (только ученики с отметкой «пилот» в админке) → `all`. Остальные переключатели — `PRACTICE_PLAN=pro` (только Pro), `PRACTICE_MODE_PAIRS|COLLOCATION|SENTENCE|DICTATION=false` (выключить один режим),
  `PRACTICE_BATTLE=false`; таблица — `docs/DEVOPS_GUIDE.md`, «Practice variables». Диктанты озвучивает очередь `low` через тот же `TTS_SERVICE_URL`, что и слова: без него фразы не получат запись и диктант не откроется.
  «Баттл 1 на 1» включён по умолчанию (`PRACTICE_BATTLE=true`) и работает там, где открыта практика. Ему нужны: воркер очереди `default` (ЛС с приглашением другу), `TWA_BASE_URL` с публичным https-адресом (кнопка «Открыть баттл» ведёт на `${TWA_BASE_URL}/twa/battles/{id}`) и **cron раз в минуту** (§14):
  команда `practice:battles-sweep` каждую минуту закрывает просроченные приглашения и матчи, которые оба игрока бросили; без неё такие матчи и «занятость» учеников «зависают».
  После правки `.env` — `php artisan config:cache` и `supervisorctl restart lexiflow-worker:*` (§17).

### Значения на запуск (решения владельца 03.11.2026)

На старте продаём **только через Telegram Stars**, практика и баттлы открыты **всем** с лимитами Free. Добавьте в прод `.env`
(имена сверены с `config/billing.php`, `config/practice.php`, `config/site.php`, `config/telegram.php`):

```dotenv
# --- Оплата: только Telegram Stars ---
BILLING_PAYWALL_MODE=purchase               # по умолчанию coming_soon: без purchase ученик не увидит цену и не сможет купить
BILLING_STARS_ENABLED=true
PAYME_ENABLED=false                         # до договоров
CLICK_ENABLED=false                         # до договоров
BILLING_SUPPORT_CONTACT=@lexiflow_support   # @handle, e-mail или https://-ссылка

# --- Цены (утверждены; минимальные единицы: XTR — целые Stars, UZS — тийины = сумы x 100) ---
BILLING_PRICE_PRO_MONTH_XTR=150
BILLING_PRICE_PRO_YEAR_XTR=1300
BILLING_PRICE_PRO_MONTH_UZS=2900000
BILLING_PRICE_PRO_YEAR_UZS=24900000
BILLING_PRICE_TUTOR_MONTH_UZS=12900000
BILLING_PRICE_CENTER_S_MONTH_UZS=34900000
BILLING_PRICE_CENTER_M_MONTH_UZS=84900000

# --- Практика и баттлы: всем, лимиты Free G1 ---
PRACTICE_ACCESS=all                         # по умолчанию off
PRACTICE_PLAN=all
PRACTICE_BATTLE=true
PRACTICE_FREE_SESSIONS_PER_DAY=2            # сессий практики в день у Free; у Pro без лимита
PRACTICE_FREE_BATTLES_PER_DAY=1             # вызовов на баттл в день у Free; у Pro без лимита

# --- Безопасность (проверьте до открытия запуска) ---
APP_ENV=production
APP_DEBUG=false                             # true в проде раскрывает стек-трейсы и переменные окружения
SESSION_SECURE_COOKIE=true                  # по умолчанию не задан: cookie админки/кабинета должны идти только по HTTPS
TELEGRAM_IP_ALLOWLIST_ENABLED=true          # nginx - крайний узел, REMOTE_ADDR настоящий (DEVOPS_GUIDE §17.2); за CDN/балансировщиком сначала trusted proxies
PAYME_KEY=                                  # пусто, пока PAYME_ENABLED=false: ключи платёжных провайдеров не кладём в .env заранее
PAYME_TEST_KEY=
PAYME_ALLOW_TEST_KEY=false                  # тестовый ключ Payme в проде не принимается
CLICK_SECRET_KEY=
```

Что важно:
- **Только Stars.** Цены в сумах остаются в `.env`, но пока `PAYME_ENABLED=false` и `CLICK_ENABLED=false`, ученику предлагается лишь оплата Stars
  (Mini App, `/pro`, лендинг показывают цену только в Stars). Покупка Pro за сумы возвращает `provider_disabled`.
  В `/org` → «Биллинг» без Payme и Click онлайн-оплаты нет; владелец центра видит «Запросить счёт», если задан `BILLING_INVOICE_REQUEST_EMAIL` (или `SITE_LEGAL_EMAIL`).
- **`BILLING_SUPPORT_CONTACT` обязателен для продаж в Telegram.** Его показывает `/paysupport` (Telegram требует эту команду от бота, принимающего Stars),
  страница возврата из оплаты и лендинг. Без него ответ `/paysupport` не содержит контакта.
- **`TELEGRAM_BOT_USERNAME`** (без `@`) — ссылки на бота, `/pro` и кнопки лендинга, рефералки; без него лендинг не знает, куда вести ученика.
- **`SITE_LEGAL_NAME`, `SITE_LEGAL_INN`, `SITE_LEGAL_ADDRESS`, `SITE_LEGAL_BANK`, `SITE_LEGAL_BANK_ACCOUNT`, `SITE_LEGAL_MFO`, `SITE_LEGAL_PHONE`, `SITE_LEGAL_EMAIL`** —
  реквизиты продавца на публичных страницах оферты и политики. Заполните до открытия продаж.
- **`REDIS_PASSWORD` и `REDIS_CACHE_PASSWORD`** — при Docker-деплое `deploy.sh` останавливается (`STOP: ... is empty`), если любой из них пуст или равен `null`/`empty`.
  Сгенерируйте: `openssl rand -hex 32`. (В примере `.env` для установки без Docker выше второй экземпляр настраивается через `REDIS_LRU_PASSWORD`.)
- **Выключенный провайдер не отвечает на вебхуки** (Payme `-32504`, Click `-1`) даже с заданным ключом, но ключи всё равно держим пустыми до договора: так исключена случайная оплата при ошибке в `*_ENABLED`.
- Значения по умолчанию в `.env.example` и `config/*.php` остаются прежними (`coming_soon`, всё выключено, `PRACTICE_ACCESS=off`): запуск задаётся именно прод `.env`.
- После правки `.env` — `php artisan config:cache` и перезапуск воркеров (§17); при Docker-деплое — обычный `deploy.sh`.

> **`.env` никогда не коммитим** и не отдаём наружу (nginx ниже запрещает доступ к dot-файлам, а корень сайта — `public/`).

---

## 11. Миграции, роли, первый администратор

```bash
cd /var/www/lexiflow
php artisan migrate --force
php artisan db:seed --class=RolePermissionSeeder --force
```

> **Не запускайте `php artisan db:seed` без `--class` в продакшене** — он создаёт демо-данные
> (демо-админ `admin@local`, учителя, группы, учеников).

Создайте первого администратора:

```bash
php artisan tinker
```

```php
\App\Models\User::create([
    'name' => 'Admin',
    'email' => 'admin@lexiflow.uz',
    'password' => \Illuminate\Support\Facades\Hash::make('СИЛЬНЫЙ_ПАРОЛЬ_АДМИНА'),
    'is_active' => true,
])->assignRole('admin');
exit
```

После первого входа в `/admin` система попросит **настроить 2FA** (Google Authenticator и т.п.) — сохраните резервные коды.

### 11.1 Кэш конфигурации

```bash
php artisan storage:link              # безопасно; аудио и карточки nginx всё равно отдаёт напрямую
php artisan livewire:publish --assets --no-interaction
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

> После **любой** правки `.env` выполняйте `php artisan config:cache` (и перезапуск воркеров, §15) —
> иначе приложение продолжит видеть старые значения.

---

## 12. Nginx

### 12.1 Сначала HTTP — чтобы получить сертификат

```bash
sudo mkdir -p /var/www/certbot
sudo nano /etc/nginx/sites-available/lexiflow
```

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name lexiflow.uz;

    location /.well-known/acme-challenge/ {
        root /var/www/certbot;
    }

    location / {
        return 301 https://$host$request_uri;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/lexiflow /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

### 12.2 Сертификат Let's Encrypt

```bash
sudo certbot certonly --webroot -w /var/www/certbot \
    -d lexiflow.uz --email admin@lexiflow.uz --agree-tos --non-interactive
sudo certbot renew --dry-run          # проверка автопродления (systemd-таймер certbot)
```

Чтобы nginx подхватывал продлённый сертификат:

```bash
echo -e '#!/bin/sh\nsystemctl reload nginx' | sudo tee /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh
sudo chmod +x /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh
```

### 12.3 Полная конфигурация

Для `/twa/` nginx сам ставит `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin` и CSP
(Laravel `SecurityHeaders` не выполняется для файлов, которые отдаёт nginx). `frame-ancestors` намеренно **не** `'self'`:
Telegram Web (`web.telegram.org`) открывает мини-приложение в iframe, `'self'` его заблокирует. `add_header` во вложенном
`location` отменяет унаследованные заголовки, поэтому они повторены в каждом блоке. CSP разрешает скрипты только со своего
домена и `https://telegram.org` (`telegram-web-app.js`); если добавите внешние картинки/шрифты/аналитику — расширьте CSP.

Замените содержимое `/etc/nginx/sites-available/lexiflow`:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name lexiflow.uz;

    location /.well-known/acme-challenge/ {
        root /var/www/certbot;
    }

    location / {
        return 301 https://$host$request_uri;
    }
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
    server_name lexiflow.uz;

    ssl_certificate     /etc/letsencrypt/live/lexiflow.uz/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/lexiflow.uz/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_ciphers         HIGH:!aNULL:!MD5;

    add_header Strict-Transport-Security "max-age=15552000; includeSubDomains" always;

    root /var/www/lexiflow/public;
    index index.php;
    client_max_body_size 20m;

    # Сжатие (см. DEVOPS_GUIDE «Compression and BREACH»)
    gzip on;
    gzip_vary on;
    gzip_comp_level 6;
    gzip_min_length 512;
    gzip_types
        application/javascript application/json application/manifest+json
        application/wasm application/xml font/otf font/ttf image/svg+xml
        text/css text/javascript text/plain text/xml;

    # Мини-приложение Telegram (Vue SPA)
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

    # Предгенерированное аудио слов (только каталог audio/, скрытые .tmp запрещены)
    location ^~ /storage/audio/ {
        alias /var/www/lexiflow/storage/app/public/audio/;
        gzip off;
        add_header Cache-Control "public, max-age=2592000, immutable";
        location ~ /\. {
            deny all;
        }
    }

    # Админка, 2FA и Livewire — напрямую в PHP-FPM и БЕЗ сжатия (защита от BREACH)
    location ~ ^/(?:admin(?:/|$)|org(?:/|$)|2fa(?:/|$)|livewire-) {
        gzip off;
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        fastcgi_param DOCUMENT_URI /index.php;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 60s;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

> Сокет — `php8.5-fpm.sock` (проверьте `ls /run/php/`). Кабинет учебного центра `/org` — такая же Filament-панель
> с CSRF-токенами, как `/admin`, поэтому тоже без сжатия.

```bash
sudo nginx -t && sudo systemctl reload nginx
curl -I https://lexiflow.uz/up        # HTTP/2 200
```

---

## 13. Очередь: Supervisor

Без воркеров очереди **не работают**: ответы бота, напоминания, оплата, лиги, достижения, рассылки центров.

```bash
sudo nano /etc/supervisor/conf.d/lexiflow.conf
```

Два пула воркеров: `high` (ответы бота, платежи) никогда не ждёт за тяжёлыми задачами из `default`/`low`
(рассылки, пре-генерация аудио). Программы объединены в группу `lexiflow-worker`, поэтому
`supervisorctl restart lexiflow-worker:*` по-прежнему перезапускает всех.

```ini
[program:lexiflow-worker-high]
process_name=%(program_name)s_%(process_num)02d
command=/usr/bin/php /var/www/lexiflow/artisan queue:work redis --queue=high --tries=3 --timeout=120 --sleep=1 --max-time=3600
directory=/var/www/lexiflow
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/lexiflow/storage/logs/worker-high.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=3
stopwaitsecs=130
user=www-data

[program:lexiflow-worker-default]
process_name=%(program_name)s_%(process_num)02d
command=/usr/bin/php /var/www/lexiflow/artisan queue:work redis --queue=default,low --tries=3 --timeout=120 --sleep=1 --max-time=3600
directory=/var/www/lexiflow
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/lexiflow/storage/logs/worker-default.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=3
stopwaitsecs=130
user=www-data

[group:lexiflow-worker]
programs=lexiflow-worker-high,lexiflow-worker-default
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status          # lexiflow-worker:lexiflow-worker-high_00 и lexiflow-worker:lexiflow-worker-default_00/_01 RUNNING
```

> Обновляетесь со старой единой программой `lexiflow-worker`: замените её блок на два блока выше и группу,
> затем `reread` + `update` (старые процессы будут остановлены, новые запущены).

Очередь проверяется в `/health/ready` двумя способами: размер очередей `high`/`default` и «пульс воркеров» —
планировщик раз в минуту кладёт крошечное задание в очереди `high` и `low`, а воркер при выполнении пишет в кэш
`queue:heartbeat:{очередь}`. Пульс старше 5 минут (`READINESS_QUEUE_HEARTBEAT_MAX_AGE`) = `queue: fail`
(воркеры стоят или очередь забита). Возраст самой старой задачи не измеряется: пульс уже ловит эту ситуацию
(задание-пульс стоит в конце очереди), а чтение payload'ов из Redis усложняло бы проверку.

2–3 воркеров хватает примерно на 300 активных учеников в день; при росте увеличьте `numprocs` (сначала `lexiflow-worker-default`).

---

## 14. Планировщик: cron

```bash
sudo crontab -e -u www-data
```

```cron
* * * * * cd /var/www/lexiflow && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Laravel сам запускает все задачи по расписанию: напоминания ученикам, закрытие сессий (в том числе сессий практики — `practice:close-expired`, каждые 15 минут) и экзаменов, истечение
подписок и заказов, напоминания о продлении, закрытие недели лиг, вызовы друзей, задания центров, восстановление
рефералов, сверку Stars, бэкапы. Каждую минуту — `practice:battles-sweep`: дворник «Баттла 1 на 1» (закрывает просроченные приглашения, двигает матчи по времени, прекращает матчи, которые оба игрока бросили). Полный список с расписанием — `docs/DEVOPS_GUIDE.md` §11.

Проверка:

```bash
sudo -u www-data php /var/www/lexiflow/artisan schedule:list
```

В списке должны быть `practice:close-expired` (каждые 15 минут) и `practice:battles-sweep` (каждую минуту). Если баттл «завис» (ученику пишет «вы заняты другим баттлом» дольше нескольких минут), первым делом проверьте cron и `/health/ready`, затем один раз выполните
`sudo -u www-data php /var/www/lexiflow/artisan practice:battles-sweep` (безопасно и повторяемо; выводит `Battles swept: N`). Вручную правьте строки `battles` не нужно (`docs/DEVOPS_GUIDE.md` §14.3).

---

## 15. Вебхук Telegram и настройка бота

```bash
cd /var/www/lexiflow
php artisan telegram:set-webhook
curl -s "https://api.telegram.org/bot<ТОКЕН>/getWebhookInfo" | python3 -m json.tool
```

В ответе: `url` = ваш домен с секретным путём, `pending_update_count` близок к 0, `last_error_message` отсутствует.

> Команда регистрирует типы апдейтов `message, my_chat_member, chat_member, callback_query, pre_checkout_query`.
> Перезапускайте её после деплоев, которые добавляют новый тип (например, оплата Stars требует `pre_checkout_query`).

Меню бота — список команд по «/» (русский и узбекский) и постоянная кнопка «LexiFlow», открывающая мини-приложение:

```bash
php artisan telegram:setup-bot
```

Кнопка ведёт на `{TWA_BASE_URL}/twa/`; Telegram принимает только https, при другом адресе команда предупредит и пропустит кнопку
(список команд всё равно будет установлен). Команду безопасно повторять — запускайте и после деплоев, которые меняют команды бота.
В группах подсказки команд учителя (`/start_training`, `/start_exam`, `/close_exam`) видят только администраторы группы, остальные
участники — `/help`; учитель, не являющийся администратором группы, набирает команду вручную.

В **@BotFather**:
1. Кнопку меню с мини-приложением задавать здесь (`/setmenubutton`) не нужно: её ставит `telegram:setup-bot` (если кнопка уже
   настроена в BotFather, команда заменит её на «LexiFlow» → `https://lexiflow.uz/twa/`).
2. **Мини-приложение для групповых чатов (обязательно).** Telegram разрешает кнопку `web_app` только в личном чате с ботом,
   поэтому в группах (`/start_training`, `/start_exam`, задания) бот присылает обычную кнопку-ссылку
   `https://t.me/<бот>?startapp=…` (прямая ссылка на мини-приложение; нужный экран передаётся в `start_param`). Для неё:
   - `TELEGRAM_BOT_USERNAME` в `.env` (без `@`) — обязателен; без него кнопка в группе откроет голую страницу в браузере.
   - Настройте **Main Mini App**: `/mybots` → ваш бот → Bot Settings → Configure Mini App → Enable Mini App →
     Main App URL: `https://lexiflow.uz/twa/` (со слэшем на конце). Тогда ссылка `t.me/<бот>?startapp=…` работает как есть.
   - Либо создайте отдельное Mini App командой `/newapp` (URL `https://lexiflow.uz/twa/`), а его short name пропишите
     в `TELEGRAM_MINI_APP_SHORT_NAME` — ссылки станут `t.me/<бот>/<short_name>?startapp=…`.
   - После изменения `.env`: `php artisan config:cache`.
   Проверка: в тестовой группе выполните `/start_training 1 1` и нажмите кнопку — должно открыться мини-приложение на экране тренировки.
3. `/setdomain` — ваш домен (для Telegram Login, если понадобится).
4. Если бот работает в группах учебных центров — `/setjoingroups` → Enable; в группе бота делают **администратором**
   (иначе он не видит участников; приложение само пришлёт предупреждение в группу).

---

## 16. Проверка после запуска (smoke test)

```bash
cd /var/www/lexiflow

curl -sf https://lexiflow.uz/up && echo OK                    # приложение живо
curl -s  https://lexiflow.uz/health/ready                     # БД, Redis, планировщик, очередь
curl -sI https://lexiflow.uz/admin | head -1                  # админка отвечает
php artisan migrate:status | tail -5                          # все миграции Ran
sudo supervisorctl status                                     # воркеры RUNNING (high + default)
curl -sI https://lexiflow.uz/twa/index.html | head -5         # 200 + nosniff / CSP
curl -sf https://lexiflow.uz/build/manifest.json >/dev/null && echo OK
php artisan about --only=environment                          # production, debug OFF

# Сжатие: статика TWA отдаётся в gzip, админка — без сжатия (пустой вывод)
ASSET=$(ls public/twa/assets/*.js | head -1 | xargs basename)
curl -sI -H 'Accept-Encoding: gzip' "https://lexiflow.uz/twa/assets/$ASSET" | grep -i content-encoding
curl -sI -H 'Accept-Encoding: gzip' https://lexiflow.uz/admin/login | grep -i content-encoding
```

Ручная проверка:
1. Напишите боту `/start` — должен начаться онбординг (язык → цель → уровень → часовой пояс).
2. Откройте мини-приложение кнопкой меню — экран «Сегодня».
3. Войдите в `https://lexiflow.uz/admin`, настройте 2FA, импортируйте/опубликуйте курс.
4. `/health/ready` через ~2 минуты после запуска cron должен показывать живой планировщик.

> `/health/ready` вернёт «не готов», пока cron не отработал хотя бы раз (маркер планировщика) и пока воркеры не обработали
> первое задание-пульс (около минуты после запуска планировщика и воркеров).

---

## 17. Обновление приложения (каждый деплой)

Сохраните как `/var/www/lexiflow/update.sh` (или выполняйте вручную):

```bash
#!/usr/bin/env bash
set -euo pipefail
cd /var/www/lexiflow

php artisan down --retry=15 || true

git fetch origin
git checkout --detach origin/master   # не git pull: после отката (§17.1) HEAD в detached-состоянии

composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

npm ci --no-audit --no-fund && npm run build
(cd resources/twa && npm ci --no-audit --no-fund && npm run build)

php artisan migrate --force
# Счётчики слов курсов (words_count — это total в /courses); безопасно повторять. Сбой не должен оставить сайт в режиме обслуживания (set -e).
php artisan courses:recount-words || echo "WARN: courses:recount-words не отработал — запустите его вручную"

php artisan livewire:publish --assets --no-interaction
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

sudo systemctl reload php8.5-fpm                 # сбросить OPcache (validate_timestamps=0)
sudo supervisorctl restart lexiflow-worker:*     # воркеры держат старый код в памяти
php artisan telegram:set-webhook                 # безопасно повторять
# Меню команд и кнопка «LexiFlow»; безопасно повторять. Сбой не должен оставить сайт в режиме обслуживания (set -e).
php artisan telegram:setup-bot || echo "WARN: telegram:setup-bot не отработал — запустите его вручную"

php artisan up
curl -sf https://lexiflow.uz/up && echo "Deploy OK"
```

```bash
chmod +x update.sh
./update.sh
```

> Чтобы `deploy` мог перезапускать сервисы без пароля, добавьте через `sudo visudo -f /etc/sudoers.d/deploy`:
> `deploy ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.5-fpm, /usr/bin/supervisorctl restart lexiflow-worker\:*`

**`php artisan courses:recount-words` — счётчики слов курсов.** `courses.words_count` — число *активных* слов курса и `total` прогресса
в `GET /courses` (каталог больше не считает слова всех курсов при каждом открытии). Все пути, меняющие слова (импорт, колоды учеников,
выключение слова в админке), обновляют счётчик сами; команда — ремонт на случай расхождения: после восстановления из дампа, ручного SQL
или релиза, меняющего правило подсчёта. Её вызывает `update.sh` (и `deploy.sh` при Docker-деплое) сразу после миграций.

```bash
php artisan courses:recount-words
# course #5 (ielts-core): 480 -> 478
# Courses checked: 14, changed: 1; lesson caches dropped: 312
```

- Проверяет **все** курсы: платформенные, курсы центров, колоды учеников (это тоже курсы), архивные. Исправленные перечислены строками
  `было -> стало` (до 20), в конце — итог. Курс, у которого число верное, команда **не пишет** (`updated_at` курсов не двигается).
- Сбрасывает кэш числа слов каждого урока (`lesson:{id}:word_count`); следующий шаг урока посчитает заново.
- **Идемпотентна и быстра** (один COUNT на курс): повторный запуск покажет `changed: 0`. В планировщике её нет — запускайте после выкладки,
  восстановления БД или когда в каталоге виден неверный `total`.
- **Безопасна на живой системе:** исправляемый курс пересчитывается короткой транзакцией, которая берёт строку курса первой (так же, как
  импорт, колоды и публикация), поэтому запуск во время импорта дожидается его commit, а не затирает счётчик устаревшим числом.
- При Docker-деплое с флагом `--rollback` команда не вызывается (в старом коде её нет).

**Только вперёд:** после первого боевого релиза схема меняется только новыми миграциями.
Никогда не запускайте в продакшене `migrate:fresh`, `migrate:rollback` и `db:seed` без `--class`.

### 17.1 Откат

Откатываем **код**, а не схему БД: на предыдущий проверенный коммит.

```bash
cd /var/www/lexiflow
git fetch origin
git checkout --detach <prev_sha>     # SHA предыдущего успешного релиза (git log, вкладка Actions)
# затем ту же последовательность update.sh (composer/npm/кэши/воркеры)
```

Для Docker-деплоя: `bash deploy.sh --rollback <prev_sha>` (обычный `bash deploy.sh <sha>`, как у CI с `${{ github.sha }}`, идёт только вперёд и для SHA старее текущего `HEAD` пропускается с кодом 0; `--rollback` снимает это ограничение; скрипт проверяет, что
`HEAD == <prev_sha>`, пересобирает образы и повторяет smoke-проверки). Миграции при откате не откатываются, поэтому они
обязаны быть обратно совместимыми (expand/contract): сначала добавляем столбец/таблицу (старый код их не трогает),
и только в одном из следующих релизов — удаляем ставшее ненужным. Деструктивные миграции (drop/rename) вместе с кодом,
который перестал их использовать, в один релиз не кладём.

---

## 18. Бэкапы

В проекте подключён `spatie/laravel-backup`; планировщик сам запускает `backup:clean` (01:00), `backup:run` (01:30)
и `backup:monitor` (02:00). Архив шифруется паролем `BACKUP_ARCHIVE_PASSWORD`.

- Для `pg_dump` нужен клиент PostgreSQL — он уже стоит вместе с сервером (`postgresql-client-18`; `pg_dump --version` должен показать 18.x — клиент не должен быть старше сервера).
- `BACKUP_DISKS=local` хранит архивы **на этом же сервере** (`storage/app/...`) — этого недостаточно.
  Настройте внешнее хранилище (S3-совместимое: Backblaze, Wasabi, Selectel и т.п.) и добавьте его в `BACKUP_DISKS`,
  или копируйте архивы на другой сервер (`rsync` по cron).
- В архив попадают и загруженные файлы: `storage/app/public` (озвучка слов, карточки достижений) и `storage/app/practice-audio` (озвучка диктантов практики).
  Потерянную озвучку диктантов можно сгенерировать заново из админки, но восстановление из архива быстрее.
- **Проверьте восстановление** хотя бы раз: разверните архив на тестовой БД и запустите приложение на ней.

Ручной бэкап и проверка:

```bash
sudo -u www-data php artisan backup:run
sudo -u www-data php artisan backup:list
```

---

## 19. Логи и мониторинг

| Где | Что |
|---|---|
| `storage/logs/laravel-YYYY-MM-DD.log` | ошибки приложения (секреты в логах маскируются) |
| `storage/logs/worker.log` | вывод воркеров очереди |
| `/var/log/nginx/error.log`, `access.log` | веб-сервер |
| `/var/log/postgresql/` | база |
| `php artisan queue:failed` | упавшие задания (`queue:retry all` — повторить) |

Рекомендуется:
- подключить Sentry/GlitchTip (`SENTRY_LARAVEL_DSN`) — ошибки сразу приходят на почту;
- внешний пинг `https://lexiflow.uz/health/ready` (UptimeRobot и т.п.) раз в 1–5 минут;
- логротация nginx уже настроена пакетом; логи Laravel ротируются сами (`LOG_DAILY_DAYS=14`).

Что мониторить и на что ставить алерты — `docs/DEVOPS_GUIDE.md` §15.3 и §17.5 (платежи).

---

## 20. Частые проблемы

| Симптом | Причина / решение |
|---|---|
| `502 Bad Gateway` | php-fpm не запущен или неверный сокет: `systemctl status php8.5-fpm`, `ls /run/php/` и путь в nginx |
| `500` сразу после деплоя | права на `storage/`/`bootstrap/cache` (§7.1) или устаревший кэш: `php artisan optimize:clear && php artisan config:cache` |
| Бот молчит | вебхук: `getWebhookInfo` → `last_error_message`; воркеры: `supervisorctl status`; ошибки в `laravel-*.log` |
| Бот отвечает с задержкой минуты | воркеров мало или они упали; проверьте `worker.log`, увеличьте `numprocs` |
| Мини-приложение — белый экран | не собран `public/twa` (§9) или `TWA_BASE_URL`/домен в BotFather не совпадают с `APP_URL` |
| Админка грузится без стилей / 404 на `livewire-…js` | `php artisan livewire:publish --assets`, `route:cache`, проверьте блок `location ~ ^/(?:admin…|livewire-)` в nginx |
| Правка `.env` «не действует» | `php artisan config:cache` + `supervisorctl restart lexiflow-worker:*` |
| Новый код «не действует» | `opcache.validate_timestamps=0` → `systemctl reload php8.5-fpm` |
| Не рисуются карточки достижений | нет FreeType в GD: `php -r 'var_dump(gd_info()["FreeType Support"]);'` → переустановить `php8.5-gd` |
| `/health/ready` не готов | cron не настроен (§14) или нет воркеров (§13); Redis/PostgreSQL недоступны — `checks.redis` это первый экземпляр, `checks.redis_cache` второй (6380, §5) |
| Ошибка «MISCONF Redis…» / `OOM command not allowed` / задания пропадают | закончилась память первого Redis: увеличьте `maxmemory`, политика должна остаться `noeviction`; кэш живёт во втором экземпляре и первому не мешает |
| `NOAUTH Authentication required` в логах | пароль в `.env` (`REDIS_PASSWORD` / `REDIS_LRU_PASSWORD`) не совпадает с `requirepass` соответствующего экземпляра |

---

## Краткий чек-лист

- [ ] Сервер обновлён, UTC, пользователь `deploy`, SSH по ключу, ufw (22/80/443)
- [ ] PHP 8.5 (`ppa:ondrej/php` / `packages.sury.org`) + расширения (`pgsql redis bcmath intl mbstring gd zip xml curl`; OPcache встроен), FreeType = true
- [ ] PostgreSQL 18 (репозиторий PGDG): пользователь и база `lexiflow`, слушает только localhost
- [ ] Redis 8.10+ (`packages.redis.io`), два экземпляра: 6379 `noeviction` + `requirepass`, 6380 `volatile-lru`, `save ""`, `appendonly no` + `requirepass` (§5)
- [ ] Код в `/var/www/lexiflow`, права на `storage/` и `bootstrap/cache/`
- [ ] `composer install --no-dev`, собраны `public/build` и `public/twa`
- [ ] `.env` заполнен (production, debug=false, секреты, Telegram, TWA), `chmod 640`
- [ ] `migrate --force`, `RolePermissionSeeder`, создан admin, 2FA настроена
- [ ] `config/route/view/event:cache`
- [ ] Nginx + сертификат Let's Encrypt, автопродление проверено
- [ ] Supervisor: воркеры RUNNING
- [ ] Cron `schedule:run` для `www-data`
- [ ] Практика: `PRACTICE_ACCESS=off` пока нет проверенных заданий; каталог `storage/app/practice-audio` создан (§7.1), `schedule:list` показывает `practice:close-expired` и `practice:battles-sweep` (раз в минуту)
- [ ] `telegram:set-webhook`, `telegram:setup-bot` (меню команд по «/» и кнопка «LexiFlow» → `/twa/`)
- [ ] Smoke test (§16) пройден, бот отвечает на `/start`
- [ ] Бэкапы уходят во внешнее хранилище, восстановление проверено
- [ ] Перед включением оплаты — песочницы Payme/Click и тест Stars (`docs/STAGING_PHASE2_CHECKLIST.md`)
