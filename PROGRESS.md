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

- [x] 14 миграций (+ patched users) — все из `docs/03_DATABASE.md` со всеми составными индексами и FK.
- [x] Partial index `word_repetitions_student_is_hard_idx WHERE is_hard = true` через `DB::statement`.
- [x] 14 Eloquent-моделей с `$fillable`, `casts`, relationships, generic-аннотациями для phpstan.
- [x] User: `HasRoles` (spatie), доп. поля `telegram_user_id`, `is_active`, `last_login_at`.
- [x] 14 factories.
- [x] 7 сидеров: `RolePermissionSeeder`, `DemoAdmin/Teacher/Group/Content/Students/Repetitions`.
- [x] `DemoContentSeeder`: 2 stages × 3 lessons × 20 words = 120 реальных EN→RU слов.
- [x] `DemoRepetitionsSeeder`: SM-2 состояния, часть слов due (next_review_at <= now()).
- [x] `tests/Feature/DatabaseSchemaTest.php` — таблицы, unique-constraints, каскады, partial+composite индексы, прогон `migrate:fresh --seed`.

**Pre-seed step (один раз):**
```
docker compose exec app php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider" --tag="migrations"
```

**DoD verify:**
```
make fresh        # migrate:fresh --seed
make test         # включает DatabaseSchemaTest
make analyse      # larastan level 8
```

**Tinker chain check:**
```php
$s = App\Models\Student::first();
$s->repetitions->first()->word->lesson->stage; // student → repetition → word → lesson → stage
```
