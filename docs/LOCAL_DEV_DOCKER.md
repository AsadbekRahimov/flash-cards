# Локальный запуск через Docker — LexiFlow Pro

Полный гайд по разворачиванию проекта локально на **macOS** и **Windows**, плюс
работа с **ngrok** для приёма Telegram webhook'ов и **Claude Code** через
контейнер.

---

## 0. Что в репозитории есть для дев-окружения

| Файл                          | Назначение                                                                  |
|-------------------------------|-----------------------------------------------------------------------------|
| `Dockerfile`                  | Прод-образ: php-fpm 8.3, минимум расширений.                                |
| `Dockerfile.dev`              | **Дев-образ**: + Xdebug, Node.js 20, npm, vim, less, увеличенные лимиты.    |
| `docker-compose.yml`          | Базовый стек: `app`, `nginx`, `postgres`, `redis`.                          |
| `docker-compose.override.yml` | Дев-настройки: подмена на `Dockerfile.dev`, сервисы `vite` и `ngrok`.        |
| `docker-compose.prod.yml`     | Прод-стек, **override НЕ применяется** при явном `-f docker-compose.prod.yml`. |
| `Makefile`                    | Шорткаты: `make up`, `make shell`, `make test`, `make migrate`, …            |

> Compose v2 автоматически мерджит `docker-compose.yml` + `docker-compose.override.yml`
> при `docker compose up`. Никаких флагов не нужно.

---

## 1. Установка Docker

### 1.1 macOS

1. Поставь **Docker Desktop** для Apple Silicon или Intel:
   <https://www.docker.com/products/docker-desktop/>.
2. После установки в Settings → Resources выдели хотя бы **4 CPU / 6 GB RAM /
   2 GB swap** — Laravel + Postgres + Redis + Vite иначе будут тупить.
3. Settings → General → ✅ **Use Virtualization framework** + **VirtioFS** —
   ускоряет bind-mount на Apple Silicon в разы.
4. Проверь:
   ```bash
   docker --version          # Docker version 24+ ок
   docker compose version    # Docker Compose v2.x
   ```

### 1.2 Windows

1. Включи **WSL2** (PowerShell от админа):
   ```powershell
   wsl --install -d Ubuntu-22.04
   wsl --set-default-version 2
   ```
2. Поставь **Docker Desktop for Windows**:
   <https://www.docker.com/products/docker-desktop/>.
3. Settings → General → ✅ **Use the WSL 2 based engine**.
4. Settings → Resources → WSL Integration → ✅ для дистрибутива Ubuntu.
5. **ВАЖНО:** клонируй репозиторий **внутрь WSL** (например в
   `~/projects/lexiflow`), а **не** в `C:\Users\…`. Bind-mount из NTFS в
   контейнер работает в 5–20 раз медленнее.
   ```bash
   # внутри WSL:
   cd ~ && mkdir -p projects && cd projects
   git clone <repo-url> lexiflow && cd lexiflow
   ```
6. Открывать терминал — `wsl` или Windows Terminal → профиль Ubuntu.
   Все команды ниже запускаются из WSL.

### 1.3 Линукс (опционально)

```bash
# Engine + Compose v2
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker "$USER"   # перелогиниться после
```

---

## 2. Первый запуск

```bash
# 1. Конфиг
cp .env.example .env

# 2. macOS / Linux: подставить UID/GID, чтобы не словить root-owned файлы
echo "UID=$(id -u)" >> .env
echo "GID=$(id -g)" >> .env
# (Windows / WSL: обычно UID/GID=1000 уже подходят, шаг можно пропустить.)

# 3. Сборка дев-образа и старт стека
docker compose build app
docker compose up -d

# 4. Установка зависимостей и миграции
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

Открой <http://localhost> — должна показаться стартовая страница Laravel.
Filament-админка: <http://localhost/admin>.

### 2.1 Шорткаты через Makefile

```bash
make up              # docker compose up -d
make shell           # bash внутри app
make migrate         # php artisan migrate
make fresh           # migrate:fresh --seed
make test            # pest
make lint            # pint --test
make analyse         # phpstan level 8
make down            # стоп
```

### 2.2 Платформо-специфичные грабли

| Симптом                                          | Платформа        | Решение                                                                  |
|--------------------------------------------------|------------------|--------------------------------------------------------------------------|
| `Permission denied` на `storage/logs/laravel.log`| macOS / Linux    | `UID`/`GID` в `.env` не совпадают с пользователем хоста — пересобрать.    |
| Жуткий I/O (Vite ребилдит 30 сек)                | macOS            | Включить **VirtioFS** в Docker Desktop.                                  |
| То же                                            | Windows          | Перенести репозиторий в WSL FS, **не** держать в `/mnt/c/...`.            |
| `host.docker.internal` не резолвится             | Linux            | Уже добавлено в `extra_hosts` в `docker-compose.override.yml`.            |
| Контейнер `app` рестартует                        | любая            | `docker compose logs -f app` — обычно отсутствует `.env` или `APP_KEY`.  |
| Порт 80 занят (Apache / IIS / Skype)              | любая            | `APP_PORT=8080` в `.env`, открывать `http://localhost:8080`.              |

---

## 3. Vite dev-server (TWA frontend)

Vite запускается **отдельным контейнером** через профиль:

```bash
docker compose --profile vite up -d vite
```

HMR доступен на <http://localhost:5173>. В `resources/twa/` правишь Vue —
изменения подхватываются автоматически. Логи:

```bash
docker compose logs -f vite
```

Если порт 5173 занят — `VITE_PORT_HOST=5174` в `.env`.

---

## 4. ngrok — Telegram webhook на локалке

Telegram умеет слать webhook **только на публичный HTTPS**. ngrok делает
туннель `https://<random>.ngrok-free.app` → `nginx:80` в твоём docker-стеке.

### 4.1 Получи authtoken

1. Зарегистрируйся на <https://ngrok.com> (бесплатно).
2. Скопируй токен с <https://dashboard.ngrok.com/get-started/your-authtoken>.
3. Положи в `.env`:
   ```env
   NGROK_AUTHTOKEN=2abcDEF...твой_токен
   ```

### 4.2 Запусти туннель

```bash
docker compose --profile ngrok up -d ngrok

# Узнаём публичный URL:
docker compose logs ngrok | grep -o 'https://[a-z0-9-]*\.ngrok-free\.app' | tail -1
# или открой веб-морду ngrok'а:
open http://localhost:4040          # macOS
start http://localhost:4040         # Windows
```

На <http://localhost:4040> видны все приходящие запросы с телом — удобно
дебажить, что именно прислал Telegram.

### 4.3 Зарегистрируй webhook в Telegram

```bash
# .env: APP_URL — полный публичный URL от ngrok (с https://, без trailing slash)
# echo APP_URL=https://abc-123.ngrok-free.app >> .env  # обнови вручную
docker compose exec app php artisan config:clear
docker compose exec app php artisan telegram:set-webhook
```

Проверь, что Telegram принял webhook:
```bash
curl -s "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/getWebhookInfo" | jq
```

`url` в ответе должен совпадать с твоим ngrok-URL, `pending_update_count`
обнулится. Теперь шли `/start` боту в тестовой группе — запрос прилетит на
<http://localhost:4040> и в Laravel-логи.

### 4.4 Гарантированно тот же URL (платно)

Бесплатный ngrok меняет URL при каждом рестарте → каждый раз дёргать
`telegram:set-webhook`. Pro-аккаунт даёт зарезервированный домен:

```yaml
# в docker-compose.override.yml, секция ngrok → command:
command:
    - "http"
    - "--domain=lexiflow-dev.ngrok.app"
    - "nginx:80"
```

### 4.5 Снять webhook (важно перед деплоем!)

```bash
docker compose exec app php artisan telegram:remove-webhook
docker compose --profile ngrok stop ngrok
```

Иначе после рестарта ngrok даст новый URL, а бот будет долбиться в мёртвый.
Особенно критично, если параллельно работает прод — два webhook'а на одного
бота не уживаются (Telegram перезаписывает).

---

## 5. Xdebug

Xdebug встроен в `Dockerfile.dev`, но **выключен по умолчанию** (нулевой
оверхед на CPU). Включить точечно:

```bash
# Один сеанс:
docker compose exec -e XDEBUG_MODE=debug app php artisan tinker

# Постоянно — в .env:
XDEBUG_MODE=debug
docker compose up -d   # перечитает env
```

Клиент слушает порт **9003**, idekey `PHPSTORM`, host
`host.docker.internal` (на Linux добавлен в `extra_hosts`).

В PhpStorm: Settings → PHP → Servers → добавить сервер `lexiflow`, host
`localhost`, port `${APP_PORT}`, ✅ `Use path mappings`, мапить
`/var/www/html` → корень проекта.

Режим `coverage` нужен для `make test --coverage`:
```bash
docker compose exec -e XDEBUG_MODE=coverage app ./vendor/bin/pest --coverage
```

---

## 6. Claude Code + Docker — как работать правильно

Claude Code (CLI) запускается **на хосте**, а проект бежит в контейнере.
Главное правило: все команды, которые трогают PHP/composer/artisan/pest, идут
**через `docker compose exec`**, а не напрямую.

### 6.1 Конфиг для Claude — `.claude/settings.local.json`

В корне проекта уже лежит `.claude/`. Добавь сюда `settings.local.json` (он в
`.gitignore` по дефолту), чтобы Claude не дёргал разрешения на каждом шаге:

```json
{
  "permissions": {
    "allow": [
      "Bash(docker compose exec app *)",
      "Bash(docker compose exec -T app *)",
      "Bash(docker compose logs *)",
      "Bash(docker compose ps)",
      "Bash(docker compose up -d)",
      "Bash(docker compose down)",
      "Bash(docker compose build *)",
      "Bash(make *)"
    ]
  }
}
```

> Скилл `/fewer-permission-prompts` в Claude Code умеет сгенерить такой
> файл автоматически из истории сессии.

### 6.2 Что положить в `CLAUDE.md` (оно уже есть, но дополнить)

Добавь в `CLAUDE.md` секцию про окружение, чтобы агент не пытался запускать
`php` / `composer` / `pest` напрямую с хоста:

```markdown
## Окружение разработки
- Проект работает в Docker. PHP, Composer, Artisan, Pest, PHPStan, Pint —
  ВСЕ запускаются через `docker compose exec app …` или через Makefile.
- Никогда не вызывай `php artisan …` напрямую — на хосте PHP может вообще
  не быть установлен. Используй `docker compose exec app php artisan …`
  или `make migrate`/`make fresh`/`make shell`.
- Для тестов — `make test`, не `./vendor/bin/pest` с хоста.
- Перед `composer require` — обязательно спроси (правило в "Запрещено").
```

### 6.3 Полезные шаблоны промптов

**Создать миграцию + модель + сервис:**
```
Контекст: docs/03_DATABASE.md (таблица student_card_progress).

Задача: создать миграцию + модель StudentCardProgress + factory.

Все команды artisan — через `docker compose exec app php artisan ...`
(или `make ...`). Тесты гонять через `make test`. Перед коммитом —
`make lint && make analyse && make test`.
```

**Дебаг через ngrok:**
```
Запусти стек с ngrok-профилем (`docker compose --profile ngrok up -d`),
достань публичный URL из логов ngrok, обнови APP_URL в .env, вызови
`docker compose exec app php artisan telegram:set-webhook` и покажи
вывод `getWebhookInfo`.
```

**Прогнать пайплайн перед коммитом:**
```
Перед коммитом: `make lint`, `make analyse`, `make test`. Если что-то
красное — покажи диагностику и предложи фикс. Не коммить с failing
тестами (правило из CLAUDE.md).
```

### 6.4 Slash-команды в `.claude/commands/`

В проекте уже включён скилл `run-tests`. Удобные кастомные команды
для дев-докера — обычные `.md` файлы в `.claude/commands/`:

`.claude/commands/docker-fresh.md`:
```markdown
---
description: Пересобрать БД с нуля внутри Docker
---
Выполни: `make fresh` (внутри это `docker compose exec app php artisan migrate:fresh --seed`).
Покажи вывод. Если есть ошибка — диагностируй.
```

`.claude/commands/ngrok-url.md`:
```markdown
---
description: Достать актуальный публичный URL ngrok-туннеля
---
Сделай `curl -s http://localhost:4040/api/tunnels` и распарси JSON,
выведи `tunnels[0].public_url`. Если ngrok не запущен — подскажи команду
для старта.
```

### 6.5 Чек-лист сессии с Claude Code

- [ ] Стек поднят: `make up` (или `docker compose ps` — все healthy).
- [ ] `.claude/settings.local.json` разрешает `docker compose exec`.
- [ ] В `CLAUDE.md` явно сказано: «всё через docker compose exec».
- [ ] Если фича трогает Telegram — ngrok запущен и webhook обновлён.
- [ ] Перед коммитом: `make lint && make analyse && make test`.

### 6.6 Запускать ли Claude Code **внутри** контейнера?

Технически можно: `docker compose exec app bash`, потом `claude`. Но не
рекомендуется — Claude Code хочет писать в `~/.claude/`, держать сессии,
обновляться. Проще оставить CLI на хосте, а контейнер использовать только
для исполнения команд.

---

## 7. Полная пере-инициализация (если что-то поломалось)

```bash
docker compose down -v        # удалит volumes (БД, redis) — данные пропадут!
docker compose build --no-cache app
docker compose up -d
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

Если кеш Composer/npm в контейнере мешает — `docker compose exec app rm -rf
vendor node_modules` и заново.

---

## 8. Связанные документы

- [`README.md`](../README.md) — короткий quick-start и прод-деплой.
- [`docs/02_ARCHITECTURE.md`](./02_ARCHITECTURE.md) — компоненты системы.
- [`docs/04_API_AND_BOT.md`](./04_API_AND_BOT.md) — webhook и формат запросов.
- [`docs/07_SECURITY.md`](./07_SECURITY.md) — `TELEGRAM_HEADER_SECRET` и HMAC
  validation `initData`.
- [`docs/09_AI_AGENTS_GUIDE.md`](./09_AI_AGENTS_GUIDE.md) — общие правила работы
  с AI-агентами.
- [`CLAUDE.md`](../CLAUDE.md) — инструкции для Claude Code.
