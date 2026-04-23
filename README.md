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
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

Открыть [http://localhost](http://localhost) — должна отобразиться стартовая страница Laravel.

На Linux/macOS установите `UID`/`GID` текущего пользователя в `.env`, чтобы не словить конфликт прав на `storage/`:

```bash
echo "UID=$(id -u)" >> .env
echo "GID=$(id -g)" >> .env
```

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
