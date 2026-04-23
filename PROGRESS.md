# Progress Log

## Sprint 1 — Foundation ✅ (2026-04-23)

- [x] Laravel 12 + Filament 5 baseline.
- [x] Пакеты: `spatie/laravel-permission`, `irazasyed/telegram-bot-sdk`, `firebase/php-jwt`, `owen-it/laravel-auditing`, `larastan/larastan`, `pestphp/pest`.
- [x] PHPUnit заменён на Pest 3.
- [x] Docker (app/nginx/postgres-16/redis-7) + PHP 8.3-fpm-alpine.
- [x] phpstan.neon (level 8) + pint.json + Makefile.
- [x] .env.example синхронизирован с docs/02_ARCHITECTURE.md §7.
- [x] GitHub Actions CI: lint / analyse / pest.
- [x] README — локальный запуск через Docker.

## Sprint 2 — Database & Models ✅ (2026-04-23)

- [x] 14 миграций + partial-index + patched users.
- [x] 14 Eloquent-моделей с relationships и casts.
- [x] 14 factories + 7 seeders (120 EN→RU слов, SM-2 demo-состояния).
- [x] `DatabaseSchemaTest` (индексы, FK, cascade, `migrate:fresh --seed`).

## Sprint 3 — Filament Admin ✅ (2026-04-23)

- [x] `AdminPanelProvider`: brandName, 5 виджетов в dashboard, rate-limit 5/min (`AppServiceProvider`).
- [x] `User` реализует `FilamentUser::canAccessPanel` → только `admin` role + `is_active=true`.
- [x] Ресурсы: `UserResource` (CRUD + роли через spatie), `TelegramGroupResource` (view/edit + actions activate/deactivate), `StudentResource` (read-only + toggleActive), `StageResource` (CRUD + LessonsRelationManager), `LessonResource` (CRUD + WordsRelationManager).
- [x] 5 виджетов: `TotalStudentsWidget`, `ExamsLast30DaysWidget`, `ActivityChartWidget` (line, 30 дней), `TopStudentsTableWidget`, `HardestWordsTableWidget`.
- [x] `FilamentAdminAccessTest`: admin → 200, teacher → 403, неактивный admin → 403, guest → redirect to login.
- [ ] **2FA — отложено до Sprint 9** (Security & QA). TODO оставлен в `AdminPanelProvider`.

**DoD verify:**
```
make fresh                             # migrations + demo data
docker compose exec app php artisan serve   # или через nginx
# открыть http://localhost/admin
# логин: admin@local / password
```

Dashboard должен показать: Total students = 5, Exams last 30 days = 0 (ещё не стартовали), Activity chart = пустой, Top students = пусто (нет training_reviews в сиде), Hardest words = пусто.
