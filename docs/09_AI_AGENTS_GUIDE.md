# 09. AI Agents Guide — Как эффективно разрабатывать этот проект с AI

Этот документ — практический гайд: как кормить контекст AI-агентам, как нарезать задачи, какие промпты использовать и как ревьюить результат.

## 1. Общие принципы

1. **Контекст — это продукт.** AI-агент хорош ровно настолько, насколько хорош контекст. Эти 9 документов и есть твой контекст: давай агенту **только** те файлы, которые нужны для конкретной задачи, а не всё сразу.
2. **Одна задача — одна фича.** Не проси «сделай мне всё обучение». Проси «сделай `SpacedRepetitionEngine` со всеми unit-тестами».
3. **Тесты первыми.** Если агент пишет код — пусть одновременно пишет тесты. Тесты — твой контракт с его выводом.
4. **Ревьюй всегда.** AI не знает твою кодовую базу лучше тебя. Читай diff, не merge на автомате.
5. **Работай инкрементально.** Сгенерил сервис → запустил тесты → увидел красное → поправил → зелёное → коммит. Не накапливай 10 файлов без запуска.

## 2. Стек подходящих агентов

| Инструмент | Для чего хорош |
|-----------|----------------|
| Claude Code (CLI) | Крупные задачи — создание модуля целиком с тестами, рефакторинг |
| Claude в Chrome / VSCode | Точечные изменения в файлах, быстрый pair-programming |
| Claude Chat (web) | Обсуждение дизайна, ревью архитектуры, генерация промптов |
| Cursor / Copilot | Автодополнение внутри IDE при работе с конкретным файлом |

Личная рекомендация: **Claude Code** для спринтов 1–8 (с директорией `docs/` в контексте), Claude Chat — для обсуждения проектных решений.

## 3. Как структурировать проект для AI-агентов

В корне репозитория держи:

```
/
├── docs/                      # эти 10 файлов
├── CLAUDE.md                  # руководящий файл для AI
├── PROGRESS.md                # текущее состояние работы
├── .claude/                   # (опц) slash-commands для Claude Code
│   └── commands/
│       └── run-tests.md
└── app/, resources/, ...
```

### CLAUDE.md (пример)
Это файл, который Claude Code подхватывает автоматически. Что туда положить:

```markdown
# LexiFlow Pro — инструкции для AI-агента

## Коротко о проекте
Telegram-based платформа обучения английскому. Подробности в docs/.

## Правила кода
- PHP 8.2+, strict_types обязателен.
- Laravel 12, следуй Laravel best practices.
- Тесты: Pest (не PHPUnit синтаксис).
- Стиль: Laravel Pint (php-cs-fixer preset).
- Статический анализ: Larastan level 8 должен проходить.
- Бизнес-логика — в Services (`app/Domain/*/Services/`), НЕ в контроллерах и НЕ в моделях.
- Все новые маршруты API → Resource-классы для вывода.
- Миграции: всегда `down()` реализован.

## Что делать ДО кода
1. Прочитать соответствующий документ в docs/.
2. Проверить существующие модели и сервисы — не дублировать.
3. Спросить меня, если задача неоднозначна.

## Запрещено
- Коммитить .env.
- Использовать DB::raw() без bindings.
- Создавать fat-controllers или fat-models.
- Игнорировать failing tests.
- Добавлять зависимости без согласования.

## Команды проекта
- `make test` — все тесты
- `make analyse` — phpstan
- `make lint` — pint
- `make shell` — в контейнер

## Текущий спринт
См. docs/08_ROADMAP.md и PROGRESS.md.
```

## 4. Шаблоны промптов по спринтам

### 4.1 Sprint 1 — Foundation
```
Контекст: docs/02_ARCHITECTURE.md (§7), docs/07_SECURITY.md (§7).

Задача: Создать начальную структуру Laravel 12 проекта с Docker-окружением для локальной разработки.

Требования:
- docker-compose.yml с сервисами: app (PHP 8.2-FPM), nginx, postgres:16, redis:7.
- Dockerfile для app с расширениями: pdo_pgsql, redis, intl, bcmath, gd.
- Nginx конфиг, проксирующий PHP-FPM.
- Makefile с командами: up, down, shell, test, lint, analyse, migrate, fresh.
- .env.example со всеми переменными из docs/02_ARCHITECTURE.md §7.
- phpstan.neon на level 8 + larastan.
- pint.json с preset=laravel.
- GitHub Actions workflow с шагами: lint, analyse, test.
- README с командой запуска.

Definition of Done:
- `make up && make migrate` работает без ошибок.
- `make test` — зелёно (даже если тестов нет — 0 failures ok).
- `make analyse` — зелёно.

Не трогай: бизнес-логику, миграции для таблиц из docs/03_DATABASE.md (этим займёмся в следующем спринте).
```

### 4.2 Sprint 2 — Database & Models
```
Контекст: docs/03_DATABASE.md (целиком).

Задача: Создать все 16 миграций, Eloquent-модели, factories и seeders согласно документу.

Правила:
- Каждая миграция в отдельном файле, порядок из §3.
- Внешние ключи с явным onDelete.
- Индексы — все из §2.
- Модели в app/Domain/<Domain>/Models/, где домены: Content, Learning, Exam, Group, User.
- Каждая модель со strict_types, казтами ($casts), связями.
- Factories в database/factories/.
- Seeders из §4, объединены в DatabaseSeeder.

Definition of Done:
- `php artisan migrate:fresh --seed` работает.
- Unit-тест: проверяет, что для каждой модели есть factory и create() работает.
- `composer analyse` зелёно.

Задавай вопросы, если что-то в документе неоднозначно.
```

### 4.3 Sprint 6 — SM-2 Engine
```
Контекст: docs/06_SPACED_REPETITION.md (целиком), docs/03_DATABASE.md §2.8.

Задача: Реализовать SpacedRepetitionEngine и CardPicker с 100% test coverage на unit-уровне.

Что создать:
1. app/Domain/Learning/Services/SpacedRepetitionEngine.php (код-референс в §4 docs/06).
2. app/Domain/Learning/Services/CardPicker.php (код-референс в §5 docs/06).
3. tests/Unit/Domain/Learning/SpacedRepetitionEngineTest.php (Pest) — минимум кейсы:
   - Новая карточка, quality=5 → interval=1, repetitions=1, EF вырос.
   - Repetition=1, quality=4 → interval=6.
   - Repetition>=2, quality=3 → interval = previous * EF.
   - Quality<3 → сброс repetitions=0, interval=1, is_hard=true.
   - EF не опускается ниже 1.3.
   - Quality вне [0..5] → InvalidArgumentException.
4. tests/Unit/Domain/Learning/CardPickerTest.php:
   - Есть due card → возвращаем её с kind='due'.
   - Нет due, есть new слова в уроке → создаём WordRepetition и возвращаем с kind='new'.
   - Всё выучено и ничего не due → возвращаем null.
   - is_hard=true карты идут первыми в due.

Definition of Done:
- Все тесты зелёные.
- `composer analyse` (larastan level 8) зелёно.
- Код-стайл через Pint.
```

### 4.4 Sprint 8 — Exam Flow
Для таких больших спринтов разбивай на под-задачи:
```
Задача 1/4: ExamQuestionBuilder (генератор вопросов) + тесты.
Задача 2/4: API endpoints экзамена + feature-тесты.
Задача 3/4: Scheduler close-expired + PostLeaderboardJob.
Задача 4/4: Vue ExamView + ResultView.
```
Каждую — отдельным промптом. После каждой — commit + run tests.

## 5. Как ревьюить AI-код

Чек-лист на каждый сгенерированный файл:

- [ ] Код запускается, тесты зелёные.
- [ ] Нет утечек секретов в хардкоде.
- [ ] Нет `DB::raw()` с интерполяцией.
- [ ] Нет fat-моделей (логика бизнеса не в accessors/mutators).
- [ ] Контроллер тонкий: валидация → сервис → ресурс.
- [ ] Все внешние входы провалидированы (FormRequest или inline).
- [ ] Ошибки обрабатываются явно (не замалчиваются catch).
- [ ] Имена переменных/методов читаемые (не `$data1`, `handle2`).
- [ ] Пустые строки, отступы, комментарии — на русском где надо, на английском в коде.
- [ ] В миграциях есть `down()`.
- [ ] В моделях не потеряны casts (особенно `timestamp`, `jsonb`, `enum`).
- [ ] Чужих файлов не трогал без необходимости.

## 6. Red flags — когда срочно проверяй

- AI «придумал» метод Laravel, которого не существует → проверь в доке.
- AI использует `User::findOrFail($id)` там, где нужна авторизация → добавь policy.
- AI обрабатывает `initData` «упрощённо» (без HMAC) → **стоп**, это security-hole.
- AI предлагает хранить JWT в localStorage TWA → ок (нет альтернатив в TWA контексте).
- AI хочет добавить пакет, которого нет в docs/02 — спроси, зачем.

## 7. Работа с промптами в процессе (итерация)

Если первая попытка не зашла — не повторяй «сделай правильно», дай конкретику:
- Плохо: «Исправь».
- Хорошо: «Этот тест падает: `SpacedRepetitionEngineTest::it_keeps_ef_above_13`. Ожидали EF=1.30, получили EF=1.28. Проверь формулу — в документе docs/06 §3.1 строка `EF' = max(1.3, EF')`».

## 8. Когда не использовать AI

- Настройка production-секретов. Делай руками, генерируй через `openssl rand`.
- Финальная установка webhook в Telegram. Делай руками, чтобы не логировать токен в чат.
- Ревью security-чек-листа. Критические пункты (HMAC, policies) ревьюй ручками и не доверяй AI.
- Рефакторинг без тестов. Сначала покрой кодом, потом давай AI менять.

## 9. PROGRESS.md — шаблон

```markdown
# Progress

## Current Sprint: 5 — Bot Basics

### Completed
- [x] Webhook endpoint с двумя секретами
- [x] HandleTelegramUpdate job
- [x] GroupLock middleware
- [x] /start handler в ЛС

### In Progress
- [ ] TeacherOnly middleware (80%)
- [ ] my_chat_member handler

### Blocked
- [ ] /help команда — нужно определить формат в ЛС vs группе

### Notes / Decisions
- Решил использовать nutgram, не telegraph — удобнее middleware.
- Вопрос: сохранять все incoming updates в БД или только в лог? Решил: только лог, чтобы не раздувать БД.

## Next Sprint
См. docs/08_ROADMAP.md § Sprint 6.
```

## 10. Работа с документацией при изменениях

Документы в `docs/` — живые. Когда по ходу дела понимаешь, что требование нужно поменять:
1. Обнови соответствующий `docs/XX_*.md` **до** кода.
2. Коммит «docs: update PRD — revised notification threshold».
3. Потом правь код.

Это даёт AI-агенту актуальный контекст и сохраняет consistency.

## 11. Cheatsheet: готовые промпты

### Создать новый ресурс Filament
```
Контекст: docs/03_DATABASE.md §2.X (таблица), docs/07_SECURITY.md §8 (policies).

Создай Filament-ресурс для модели <Name>:
- Navigation group: '<Group>'.
- Form fields: только editable (из документа), остальные — в infolist.
- Table columns: id, <key fields>, created_at.
- Фильтры: <...>.
- Policy: admin — всё, teacher — только свои.
- Тест: FilamentResourceTest с can_view_list, can_create (only admin), cannot_create (teacher).
```

### Добавить endpoint
```
Контекст: docs/04_API_AND_BOT.md §<раздел>, docs/07_SECURITY.md §5.

Добавь endpoint <METHOD> <path>:
- FormRequest <Name>Request со всеми правилами из документа.
- Контроллер <Name>Controller с одним action.
- Policy-check через Gate::authorize или $this->authorize.
- Resource для ответа.
- Feature-тест: happy path + 4 ошибочных (auth, 403, 422, 404).
```

### Миграция +1 колонка
```
Добавь миграцию: <table> + колонка <name> (<type>, nullable, default X).
- Обновить модель: добавить в $fillable и $casts.
- Обновить factory, если нужно.
- Unit-тест: <модель>.<field> корректно сохраняется и читается.
```

## 12. Одно главное правило

> **Если AI-агент начинает «импровизировать» архитектуру — стоп, и дай ему конкретный документ или конкретный раздел как референс.**

Все ответы на «как это должно быть» — уже в этих 10 документах. AI — это хороший исполнитель с этим контекстом, не источник истины по проекту.

---

Удачи. Теперь — открывай `08_ROADMAP.md`, бери Sprint 1 и начинай.
