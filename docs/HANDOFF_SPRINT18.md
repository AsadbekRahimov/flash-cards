# Передача работы — Sprint 18 «Практика (4 режима) + Баттл 1 на 1»

Пауза с 2026-10-30. Работа идёт в ветке **`claude/tender-allen-712v3m`**. В `master` Sprint 18 **ещё нет**, PR не создавался.

Этот файл — всё, что нужно, чтобы продолжить в новой сессии без прежнего контекста. Исходные задания, ревью, отчёты и пробы, на которые он ссылается, лежат рядом: `docs/handoff/sprint18/` (карта — §9).

---

## 0. Как продолжить

1. Открой новую сессию Claude Code на этом репозитории **в ветке `claude/tender-allen-712v3m`**.
   - Если сессия создала себе другую ветку, напиши первым сообщением: «Работай в ветке `claude/tender-allen-712v3m`».
   - Другой вариант — влить её в ветку сессии: `git fetch origin claude/tender-allen-712v3m && git merge origin/claude/tender-allen-712v3m`.
2. Первое сообщение, например:
   > Прочитай `docs/HANDOFF_SPRINT18.md` и продолжай Sprint 18 с §5 как координатор по `CLAUDE.md`. Отвечай по-русски.
3. Координатор идёт по §5 по порядку. Решения §4 уже приняты — их не пересматривать.

---

## 1. Цель спринта

Реализовать `docs/15_PRACTICE_GAMES_PLAN.md` (правила владельца) по техническому проекту `docs/16_PRACTICE_DESIGN.md`:
- четыре режима практики: «Найди пары», «Подбери сочетание», «Собери предложение», «Слушай и пиши» (диктант);
- «Баттл 1 на 1» между друзьями.

Правила владельца из docs/15 обязательны; в остальных расхождениях с docs/15 прав docs/16.

---

## 2. Что уже в ветке

История спринта — `git log --first-parent --oneline master..claude/tender-allen-712v3m`.

| Задача | Что сделано |
|---|---|
| 18.0 | Технический проект `docs/16_PRACTICE_DESIGN.md` (схема, API, машина состояний баттла, бюджеты, безопасность, нарезка задач) |
| 18.1 / 18.1-T | Общий модуль практики: сессия со снимком на сервере, opaque id элементов, идемпотентность по `request_id`, коды ошибок; оболочка TWA |
| 18.2–18.5 | Четыре режима; диктант с аудио (ffmpeg flite, очередь TTS) |
| 18.7a | Админка заданий (Filament), импорт, пилотный пакет — 90 заданий |
| 18.7b | Входы в практику (Today, Finished, Course), документация соло |
| 18.9a / 18.9b / 18.9t | Исправления по трём ревью соло: контент и админка, сервер, TWA |
| 18.6 / 18.6b | Ядро баттла (`BattleService`, `BattleClock`, `BattleReader`, `BattleJanitor`), HTTP-слой и ЛС-приглашение от бота |
| 18.7c | Документация баттла: docs/03, 04 (A.17, B.14), 05 §5a.5, 07, 16, USER/ADMIN/DEVOPS/VPS |
| 18.6-fix (`935359b`) | Все пункты ревью сервера баттла:<br>• `request_id` строки тайм-аута — случайный uuid v4;<br>• одна пара — один незавершённый матч (`battles.open_pair` unique, миграция `2026_10_30_100005`);<br>• мёртвое своё приглашение закрывается по пути;<br>• изолированный дворник;<br>• `accept` проверяет оба выключателя и доступ вызывающего;<br>• нет ЛС, если практика закрыта адресату;<br>• `accepted_at` с точностью колонки |
| 18.10t (`686b0b4`) | TWA баттла по ревью и под настоящий сервер:<br>• ответ принадлежит своему раунду (Б1), поздний повтор — не отказ (Б2);<br>• тайм-ауты JSON 8/10 с;<br>• полоса отсутствия соперника по серверу;<br>• реванш по `by_me` через опрос;<br>• встречные приглашения;<br>• лист выхода следит за статусом;<br>• пропавший клип → `audio-failed`;<br>• Pro → пейвол;<br>• `request_id_reused` в кодах ошибок и i18n |
| 18.11, часть 1 (`e710743`) | Пункт 1 из 6: текст отказа экранируется в уведомлении админки |

### Где код

**Сервер**
- `app/Domain/Practice/Services/`:
  - `PracticeSessionService`, `PracticeHubService`, `PracticeItemService`, `PracticeAudioService`, `PracticeMaterialService`, `PracticeSnapshotBuilder`, `PracticeImporter`, `PracticeAccess`, `PracticePilotService`;
  - `Modes/`, `Grading/` (грейдеры, `EnglishLexicon`, `InflectionGuard`, `TranslationVariants`);
  - `Battle/` (`BattleService`, `BattleClock`, `BattleReader`, `BattleJanitor`, `BattleFriends`, `BattleSettings`).
- Рядом: `DTO/`, `Enums/`, `Exceptions/`, `Support/`.
- Остальное:
  - контроллеры — `app/Http/Controllers/Api/Twa/{PracticeHub,PracticeSession,PracticeAudio,Battle}Controller.php`;
  - Requests и Resources — `app/Http/{Requests,Resources}/Twa/{Practice,Battle}*`;
  - ЛС — `app/Domain/Telegram/Services/BattleMessenger.php`;
  - команды — `app/Console/Commands/{SweepBattles,ClosePracticeSessions,ImportPracticeItems}.php`;
  - админка — `app/Filament/Resources/PracticeItemResource*`, `app/Filament/Pages/ImportPracticeItems.php`;
  - вход — `app/Domain/Learning/Services/PracticeEntryResolver.php`.
- Конфиг и данные: `config/practice.php`, `lang/{ru,uz}/{practice,battle}.php`, лексикон `resources/lexicon/en-common.txt`, демо-сид `database/seeders/PracticeDemoSeeder.php`.

**TWA** (`resources/twa/src/`)
- `stores/practice.ts`, `stores/battle*.ts`;
- `views/Practice*.vue`, `views/Battle*.vue`;
- `components/practice/*`, `components/battle/*`;
- `api/{types,client}.ts`, `i18n/{ru,uz,errors}.ts`.

**Тесты**
- `tests/Feature/Practice/*`, `tests/Unit/Practice/*`;
- TWA — `*.spec.ts` рядом с кодом.

**Документация**
- docs/15, docs/16 (в начале — таблица статуса задач);
- docs/03 (таблицы), docs/04 A.16 / A.17 / B.14 (API), docs/05 §5a (TWA), docs/07 (безопасность);
- `USER_GUIDE`, `ADMIN_GUIDE`, `DEVOPS_GUIDE`, `VPS_DEPLOY_GUIDE_RU`, `I18N_UZ_REVIEW`.

---

## 3. Последние проверки

Прогон на `e710743`, вся работа агентов слита:

| Проверка | Результат |
|---|---|
| `./vendor/bin/pint --test` | ✅ |
| `./vendor/bin/phpstan analyse --memory-limit=1G` (level 8) | 0 ошибок |
| `./vendor/bin/pest --parallel --processes=4` (SQLite) | **4565 passed / 47 skipped / 0 failed** |
| `npx vue-tsc --noEmit` | ✅ |
| `npx vitest run` | **2825 passed** (130 файлов) |
| `npx vite build` (в scratchpad) | ✅ |

**Не прогнано на этом коммите:** Pest на PostgreSQL 16 + Redis — гонки баттла и partial/unique-индексы на SQLite пропускаются.
- CI (`.github/workflows/ci.yml`) запускается на push в `claude/**` с `postgres:16` и `redis:7` — результат смотреть в GitHub → Actions.
- Как запустить локально — §7.

---

## 4. Решения координатора (приняты, не пересматривать)

**Пары**
- Ключи конфликта на доске всегда включают базовый перевод (`base|…`). Пример: mother и mum не попадают на одну доску.

**Снимок сессии**
- Размер считается в UTF-8, лимит 40 KB. Худший случай — баттл «предложение» ru + uz, 29 271 B.

**Версии контента**
- Целевые слова, урок и уровень принадлежат версии задания (миграция `2026_10_30_100010`).
- Строка задания и `practice_item_words` — проекция опубликованной версии, а если её нет — текущей.

**Коды ошибок и поля**
- `version_changed` — админка.
- `request_id_reused` — 409.
- Причина `items_locked`.
- `your_option_id` — в разборе соло-сочетания.

**Баттл**
- Присутствие: `presence_warn_seconds = 10` даёт `opponent_present` / `opponent_absent_since`. Матч кончается по грейсу 30 с.
- Итог `closed` — `{outcome: none, …}`.
- Встречное приглашение → 409 `battle_busy {battle_id}`. «Одна пара — один незавершённый матч» — факт БД: `battles.open_pair`.
- `request_id` строк тайм-аута — случайный uuid v4.
- Материал матча — вся тема (урок или курс) у обоих, без привязки к прогрессу.
- ЛС-приглашение может прийти не сразу из-за лимита бота; в «Друзьях» его видно сразу.

**Демо-сид**
- Сид озвучивает фразы сам через ffmpeg flite.
- Без ffmpeg задания TTS ставятся только при асинхронной очереди. При `sync` — предупреждение, аудио остаётся «В очереди».

**Диктант, «опечатка» — решение для 18.11, ещё не реализовано**
- Правило: `typo` ⇔ OSA = 1 ∧ длина ожидаемого ≥ 5 ∧ ни один токен не защищён ∧ `!EnglishLexicon::isWord(got)` ∧ `!InflectionGuard(s/d)`.
- `isWord` — деинфлексия с проверкой орфографии. Базы — только из `en-common.txt`; неправильные формы — в новом `en-irregular.txt`.
- Подробно — `docs/handoff/sprint18/prompts/18-11.md` §4.
- В споре правило владельца docs/15 §3.4 сильнее docs/16 §5.2.

---

## 5. Что осталось — по порядку

### 5.1 CI на PostgreSQL 16 + Redis (координатор)

1. Проверить результат CI для последнего push ветки.
2. Если CI красный: воспроизвести на PG (§7) и починить.
   - Гонки и схему баттла чинит `architect`.
   - Остальное — `coder`.
3. Особое внимание: `BattleConcurrencyTest` (новые гонки `open_pair`), `DatabaseSchemaTest` (миграция `100005`, CHECK `battles_open_pair_shape_check`), `BattleServiceTest`, `BattleContractTest`, `PracticeQueryBudgetTest`, `PracticeSchedulerTest`.

### 5.2 Задача 18.11 — остаток (`coder`)

**Задание** — `docs/handoff/sprint18/prompts/18-11.md`. Пункт 1 сделан (`cc75ade`), отдать исполнителю пункты 2–6:

| № | Что сделать |
|---|---|
| 2 | «Сгенерировать аудио заново» не трогает готовую (`ready`) опубликованную версию: в очередь — текущая с `force` и опубликованная, только если её аудио не `ready`. Поправить docs/16 и `ADMIN_GUIDE`. |
| 3 | Диктант: `no_words` вместо `no_items`, когда озвученных слов > 0, но меньше минимума. |
| 4 | Лексикон и правило «опечатки» — §4 выше, подробно в задании. Новый `resources/lexicon/en-irregular.txt`; хвосты r/n/y из `InflectionGuard` убрать; тесты `EnglishLexiconTest`, `DictationGraderTest`; docs/16 §5.2. |
| 5 (М4) | `course_title` и `lesson {stage, number, title} \| null`:<br>• в `active_session` хаба и в `details` 409 `practice_session_active` — из уже декодированного снимка, без новых запросов;<br>• TWA называет курс и урок незавершённой сессии;<br>• docs/04 A.16, docs/16 §7. |
| 6 | Мелочи ⚪1–⚪6:<br>• pending-`request_id` пар по ключу пары;<br>• 422 `unknown_element_id` — перечитать без тоста;<br>• текст `items_locked` на хабе курса;<br>• «Заданий пока мало: N из M»;<br>• docs про сидер без ffmpeg;<br>• индекс `practice_item_versions (lesson_id)` — миграция `…100011`. |

Плюс строка про 429 в docs/04 A.16.

**Входные материалы для исполнителя**
- Находки повторного ревью: `docs/handoff/sprint18/reviews/review-fixes.md`.
- Пробы ревьюера: `docs/handoff/sprint18/probes/18-11-probes.md`. Нужны для пункта 4: списки 40 описок (`LexiconTypoProbeTest`) и 25 «хвостов» (`TailSlipProbeTest`).
- Контекст лексикона и грейдера: `docs/handoff/sprint18/reports/18-9b.md`.

**Поправки к тексту задания**
- Пути вида `$S/...` и `.../scratchpad/...` указывают на scratchpad прошлой сессии — его больше нет. Нужное скопировано в `docs/handoff/sprint18/`.
- Порты PG, Redis и vite — любые свободные.
- Раздел «Параллельно» устарел.

### 5.3 Документация баттла (координатор, мелкая правка)

- docs/16, таблица статуса (строка 20): `18.6-T … | сделано, на моках API` → «сделано; под настоящий сервер — 18.10t».
- docs/05 §5a.5 сверить с экранами после 18.10t: полоса «соперник потерял связь», лист выхода, реванш (кто предложил), встречное приглашение, пейвол Pro, «Матч прерван» / «Матч не состоялся».

### 5.4 Повторное ревью баттла — только исправленные места (`reviewer`, рекомендуется)

| Что смотреть | Дифф | Исходное ревью |
|---|---|---|
| Сервер, 18.6-fix | `git diff 77d93fc 935359b` | `docs/handoff/sprint18/reviews/review-battle-be.md` |
| TWA, 18.10t | `git diff 935359b 686b0b4` | `docs/handoff/sprint18/reviews/review-battle-twa.md` |

- **Общие правила ревью:** `docs/handoff/sprint18/prompts/review-18-common.md`.
  - Ревьюер ничего не меняет.
  - Работает в замороженной копии: `git worktree add --detach <scratchpad>/review-battle-fix 686b0b4`, vendor — `cp -al`.
- **Проверить:**
  - каждая находка закрыта — есть путь воспроизведения и тест, который её держит;
  - нет регрессий;
  - гонки `open_pair` подтверждены на PG.
- **Формы JSON** — `docs/handoff/sprint18/reports/18-6b.md` и `tests/Feature/Practice/BattleContractTest.php`.
- **Не сделано в 18.10t:** снимки экранов баттла (обе темы, 390 и 320 px). Их закрывает e2e (§5.5).

### 5.5 Браузерный e2e на настоящем API (`coder`, задача 18.8e)

**Задание** — `docs/handoff/sprint18/prompts/18-8e.md`:
- скрипт `resources/twa/scripts/e2e/practice.mjs` и README к нему;
- стенд: `php artisan serve --port 8010`, отдельная SQLite, `PRACTICE_ACCESS=pilot`, демо-сид, подписанный initData, глобальный Playwright.

**Сценарии:**
- соло — четыре режима;
- баттл двумя контекстами браузера;
- перезагрузка посреди матча;
- фон (`visibilitychange`);
- сдача;
- `both_absent`;
- снимки.

**Поправки к заданию:**
- В ветке теперь также 18.6-fix, 18.10t и 18.11.
- Харнесса `visual-18-1t/shoot.cjs` больше нет. Темы передавать в hash: `tgWebAppThemeParams` — JSON Telegram (`bg_color`, `text_color`, `hint_color`, `link_color`, `button_color`, `button_text_color`, `secondary_bg_color`) для светлой и тёмной. Запросы к `https://telegram.org/**` — `route.fulfill` пустым скриптом.
- Разрешено добавить одну строку конфига `env('PRACTICE_BATTLE_PRESENCE_GRACE', 30)` — ускорение для e2e.

Найденные ошибки исполнитель описывает, координатор раздаёт исправления.

### 5.6 Финальная приёмка (координатор)

1. Прогоны (§7):
   - весь Pest на SQLite;
   - весь Pest на PG 16 + Redis, последовательно;
   - vitest, vue-tsc, сборка.
2. `PROGRESS.md`: заменить заглушку «Sprint 18 — в работе» полным разделом: что построено, решения §4, числа проверок, что осталось вне кода.
3. По желанию владельца удалить `docs/handoff/sprint18/` и этот файл.
4. Push в `claude/tender-allen-712v3m`. PR — только если владелец попросит.
5. Итоговый отчёт владельцу по-русски, с пунктами §8.

---

## 6. Как работает координатор в этом спринте

- **Цикл.** Цикл `CLAUDE.md`:
  - код и тесты пишет `coder`;
  - конкурентность, схему и алгоритмы — `architect`;
  - `reviewer` — один раз на фичу; повторно — только исправленные места.
- **Окружение агента.** Каждый агент работает в своём git worktree (Agent с `isolation: "worktree"`). Правила окружения — `docs/handoff/sprint18/prompts/18-env.md`:
  - первым делом `git merge --ff-only claude/tender-allen-712v3m`;
  - vendor — `cp -al` из главного чекаута, не symlink; `.env` скопировать; `node_modules` — `cp -al`;
  - сборка TWA — в scratchpad, не в `public/twa`;
  - `git add` — только конкретные пути;
  - коммитить часто.
- **Порты.** Каждому агенту свои: PG `55xxx`, Redis `6xxx`, vite `5xxx`.
- **Приёмка работы агента:**
  1. Сверить отчёт: проверки зелёные, объём соответствует.
  2. `git worktree remove --force <wt>`.
  3. Привести трейлеры коммитов агента к строкам атрибуции координатора: `FILTER_BRANCH_SQUELCH_WARNING=1 git filter-branch -f --msg-filter 'sed "s/^Co-Authored-By: .*/<строка координатора>/"' -- claude/tender-allen-712v3m..<ветка>`, затем удалить `refs/original/*`.
  4. `git merge --no-ff <ветка>`.
  5. Конфликты — сохранить обе стороны. Для `docs/I18N_UZ_REVIEW.md` — `python3 docs/handoff/sprint18/tools/keep_both.py <файл>`.
  6. pint, phpstan, весь pest (`--parallel --processes=4`); если менялся TWA — vitest и vue-tsc.
  7. Push.
- **Новые uz-строки** — в `docs/I18N_UZ_REVIEW.md` отдельным разделом в конце. `UzReviewDocTest` должен быть зелёным. uz — латиница, ASCII-апостроф, обращение «Siz».
- **Ловушка Pest.** `expect($x)->not->toContain('a', 'b')` проходит, если отсутствует ХОТЯ БЫ одна строка. Писать по одной строке на проверку.
- **Чекаут.** Не делать `cd` в worktree надолго — использовать `git -C <wt>`.

---

## 7. Окружение новой сессии

Контейнер новой сессии чистый: vendor, node_modules, `.env` и scratchpad прошлой сессии не сохраняются.

**Зависимости**
- Если их нет: `composer install`; `cd resources/twa && npm ci`.
- `.env` не в git. Если нет: `cp .env.example .env && php artisan key:generate`. Тесты берут настройки из `phpunit.xml` (SQLite в памяти).

**Проверки**
- PHP: `./vendor/bin/pint --test`, `./vendor/bin/phpstan analyse --memory-limit=1G`, `./vendor/bin/pest --parallel --processes=4`.
- TWA: `npx vue-tsc --noEmit`, `npx vitest run`, `npx vite build --outDir <scratchpad>/twa-build --emptyOutDir`.

**PostgreSQL 16 + Redis** — нужны для гонок и PG-only тестов:

```bash
eval "$(docs/handoff/sprint18/tools/pg.sh start final 55700)"   # экспортирует DB_CONNECTION=pgsql DB_HOST DB_PORT DB_DATABASE DB_USERNAME
redis-server --port 6700 --daemonize yes --save '' --appendonly no
export REDIS_HOST=127.0.0.1 REDIS_PORT=6700 CACHE_STORE=redis QUEUE_CONNECTION=sync SESSION_DRIVER=array
php artisan migrate --force && ./vendor/bin/pest            # последовательно, без --parallel
docs/handoff/sprint18/tools/pg.sh stop final; redis-cli -p 6700 shutdown nosave
```

- `pg.sh` нужен `/usr/lib/postgresql/16/bin`. От root postgres не запускается, поэтому скрипт работает через `unshare --user` под uid 1000.
- Если PostgreSQL в контейнере нет и поставить его нельзя — опираться на CI (§3).

**Прочее**
- ffmpeg с фильтром flite нужен демо-сиду для озвучки диктанта. Проверка: `ffmpeg -hide_banner -filters | grep flite`.
- Playwright и Chromium в облачном контейнере уже установлены. Подключение: `createRequire(import.meta.url)('playwright')`. `playwright install` не запускать.

---

## 8. Вне кода — для владельца

1. **Проверка носителем узбекского** — новые строки в `docs/I18N_UZ_REVIEW.md`, разделы «Практика и баттл 2026-10 · 18-*».
2. **Реальные устройства Telegram** (iOS, Android, Desktop) — практика и баттл: звук диктанта, вибрация, темы, сворачивание приложения посреди матча.
3. **TTS на проде** — воркер очереди и сервис озвучки фраз диктанта (`docs/DEVOPS_GUIDE.md`).
4. **Переменные окружения** (`config/practice.php`):
   - `PRACTICE_ACCESS` — `off` | `pilot` | `all`, по умолчанию `off`;
   - `PRACTICE_PLAN` — `all` | `pro`;
   - `PRACTICE_MODE_PAIRS|COLLOCATION|SENTENCE|DICTATION`;
   - `PRACTICE_BATTLE`.
5. **Лексикон** — просмотр владельцем контента: `resources/lexicon/en-common.txt`, после 18.11 — и `en-irregular.txt`. От лексикона зависит, что считается «опечаткой».
6. **Продуктовые заметки:**
   - материал баттла — вся тема (урок или курс) у обоих игроков, без учёта прогресса;
   - ЛС-приглашение приходит не мгновенно из-за лимита бота.

---

## 9. Что лежит в `docs/handoff/sprint18/`

| Файл | Зачем |
|---|---|
| `prompts/18-11.md` | Задание 18.11 (пункт 1 сделан, остальное — §5.2) |
| `prompts/18-8e.md` | Задание e2e (§5.5) |
| `prompts/18-env.md`, `prompts/env-pg.md` | Окружение агента в worktree, PG и Redis. `$S` — scratchpad прошлой сессии; заменить на текущий |
| `prompts/review-18-common.md` | Общие правила ревьюеров: формат, замороженная копия |
| `reviews/review-fixes.md` | Повторное ревью соло — источник 18.11 |
| `reviews/review-battle-be.md`, `reviews/review-battle-twa.md` | Ревью баттла, закрытые 18.6-fix и 18.10t; база для §5.4 |
| `reports/18-6b.md` | HTTP баттла: формы JSON, ответы на вопросы TWA |
| `reports/18-9b.md` | Исправления сервера соло: лексикон, грейдер, коды |
| `probes/18-11-probes.md` | Pest-пробы ревьюера к 18.11 |
| `tools/pg.sh` | Свой кластер PostgreSQL 16 для тестов |
| `tools/keep_both.py` | Разрешить конфликт, сохранив обе стороны (append-only документы) |
