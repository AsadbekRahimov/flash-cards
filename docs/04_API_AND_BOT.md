# 04. API & Telegram Bot — LexiFlow Pro

## Часть A — REST API для TWA

**Base URL:** `/api/twa`
**Auth:** JWT в `Authorization: Bearer <token>` (кроме `/auth`).
**Content-Type:** `application/json`.

**Реализация.** Контроллер только валидирует запрос и вызывает сервис (`TrainingFlowService`,
`ExamFlowService`, `LearnerStatsService`), ответ собирает Resource-класс из `app/Http/Resources/Twa/`
(ошибки — `ApiErrorResource`). Ответы идут **без обёртки `data`** — формы ниже закреплены
контрактными тестами `tests/Feature/TwaContractTest.php` (`assertExactJsonStructure`).

### A.1 `POST /api/twa/auth`
Аутентификация через Telegram `initData`. **Identity — только Telegram-пользователь**: первый вход создаёт `learner`
(`LearnerRegistrar`), повторные возвращают того же и обновляют имя/username/`last_seen_at`. Состоять в какой-либо группе
для входа не нужно; доступ к конкретной сессии проверяется на её маршрутах (A.3, A.4).

**Request:**
```json
{
  "init_data": "query_id=...&user=%7B...%7D&auth_date=...&hash=...",
  "context": { "type": "training", "session_id": 123 }
}
```

`context` необязателен и **не влияет на выбор learner'а**: он оставлен для совместимости клиента и аналитики
(`twa.authenticated`); группа сессии из `context` попадает в событие только если learner — её активный участник (контекст задаёт клиент и не доверяется). `acquisition_source` через `/auth` не пишется.
Несуществующая сессия в `context` не является ошибкой.

**Validation:**
- HMAC-SHA256 по алгоритму Telegram (см. `07_SECURITY.md`).
- `auth_date` не старше 24 часов.

**Response 200:**
```json
{
  "token": "eyJhbGciOi...",
  "expires_in": 900,
  "learner": {
    "id": 42,
    "first_name": "Alex",
    "locale": "ru",
    "onboarding_completed": false
  }
}
```

Токен — JWT **v2**: `{iss, sub: learner_id, v: 2, iat, exp}` (без `gid`), TTL 15 мин. Токен без `v = 2` (старый формат,
где `sub` был `students.id`) отклоняется с 401 `invalid_token`; клиент перелогинивается автоматически (`api/client.ts`).

**Errors:**
- 401 `invalid_init_data` — HMAC не сошёлся / `initData` просрочен.
- 401 `learner_blocked` — learner заблокирован платформой (`learners.blocked_at`).
- 422 — нет поля `init_data`.
- 429 `rate_limited` (`Retry-After`) — больше 60 запросов в минуту с одного IP либо больше 10 проверенных входов в минуту одного `telegram_user_id` (второй счётчик начинается только после проверки HMAC).

Ошибок `session_context_required` (409) и `student_not_found` (403) на `/auth` больше нет.

**Ошибки защищённых маршрутов (`TwaAuth`, любой запрос с токеном):** 401 `missing_token`, 401 `invalid_token`
(подпись, срок, `iss`, версия), 401 `learner_not_found` (learner удалён), 401 `learner_blocked`
(проверяется на каждом запросе — бан действует немедленно).

### A.2 `GET /api/twa/me`
Информация о learner'е.

**Response:**
```json
{
  "learner": { "id": 42, "first_name": "Alex", "username": "alex_dev", "locale": "ru", "onboarding_completed": true },
  "groups": [{ "id": 7, "title": "B1 English Evening" }],
  "stats": {
    "words_learned": 234,
    "words_due_today": 18
  },
  "entitlement": {
    "plan": "pro", "source": "subscription", "until": "2026-12-31T12:00:00+00:00", "new_words_per_day": null,
    "renewal": { "auto": true, "provider": "telegram_stars", "ends_at": "2026-12-31T12:00:00+00:00", "reminder_sent": false },
    "lapsed": null
  },
  "paywall": { "mode": "coming_soon", "contact": null },
  "settings": {
    "interface_locale": "ru", "timezone": "Asia/Tashkent", "reminder_time": "19:00", "daily_goal": 20,
    "goal": "general", "level": "A1", "timezone_change_available_at": null, "active_course": { "slug": "english-a1", "title": "Английский A1" },
    "options": {
      "locales": [{ "id": "ru", "label": "🇷🇺 Русский" }, { "id": "uz", "label": "🇺🇿 O'zbekcha" }],
      "timezones": [{ "id": "Asia/Tashkent", "label": "Ташкент (UTC+5)" }],
      "reminder_times": ["08:00", "13:00", "19:00", "21:00"],
      "daily_goals": [10, 20, 40]
    }
  },
  "practice": { "available": true, "pro_required": false, "modes": ["pairs", "collocation", "sentence", "dictation"], "battle": true }
}
```

- `groups` — группы, где learner состоит **сейчас** (активное членство в активной группе); пустой массив, если групп нет.
- `words_learned` — слова с хотя бы одним успешным повторением (`repetitions >= 1`).
- `words_due_today` — то же правило, что у напоминаний (`DueRepetitionCriteria`, docs/06 §6): карточки в
  `review` с `next_review_at <= now` плюс **брошенные** learning/relearning карточки, чей минутный шаг истёк
  больше `LEARNING_ABANDONED_AFTER_HOURS` (12 ч) назад. Свежие шаги внутри сессии и `new`-строки
  (карточка показана, не оценена) не считаются. Считается по всему прогрессу learner'а, а не по одной группе.
- `entitlement` — тариф learner'а сейчас (`EntitlementResolver::for`, docs/12 §5.1): `plan` — `free` | `pro`; `source` — `free` |
  `subscription` (личная подписка: ручная выдача, промокод) | `sponsor:organization:<id>` (Pro центра, к группе которого привязана
  группа learner'а); `until` — ISO 8601, `null` = конца нет (Free или бессрочная выдача); `new_words_per_day` — лимит новых слов
  в день (`20` у Free), `null` = безлимит. Значение из кэша (TTL 300 с), изменения подписки/членства сбрасывают его сразу.
- `entitlement.renewal` (2A.2, docs/13 §5) — **собственная** живая подписка learner'а (спонсорский Pro центра ему не продлевать), `null` — если её нет (Free, только спонсор):
  `auto` — автопродление работает сейчас (Telegram Stars, статус `active`); `provider` — `telegram_stars` | `payme` | `click` | `manual` | `promo` | `reward`;
  `ends_at` — до какого момента подписка действует (ISO 8601, `null` — бессрочно; для Stars `past_due` — конец grace); `reminder_sent` — напоминание о продлении за 3/1 день по текущему
  концу периода уже отправлено (маркеры `subscriptions.meta.renewal_reminders`, docs/13 §2.1). Не кэшируется (одна выборка подписки на запрос).
- `entitlement.lapsed` (2A.6) — «Pro закончился»: `{provider, ended_at}`, если learner сейчас на Free, а **своя** платная подписка истекла (`expired`) не раньше `billing.checkout.lapsed_banner_days` (14, `0` = выкл.) дней назад; иначе `null` — Pro действует (в т.ч. от центра), давно истекло, подписку завершили вручную (возврат/chargeback/админ — последняя запись `meta.history` = `ended`) или платных подписок не было. По нему TWA рисует баннер «Pro закончился — продлить» на «Сегодня» и в «Подписке» (закрывается локально, привязан к `ended_at`). Одна индексная выборка на запрос и только для Free.
- `paywall` — что показать при исчерпанной квоте (docs/12 §5.4): `mode` — `coming_soon` | `purchase`; `contact` — контакт
  поддержки для центров (`BILLING_SUPPORT_CONTACT`), **`null`, если не задан — строку с контактом тогда не показывать**.
  `purchase` отдаётся, только если `BILLING_PAYWALL_MODE=purchase` **и** есть что продавать (`GET /billing/catalog` → `items` не пуст: включён хотя бы один провайдер, обслуживающий learner'а, и задана цена);
  иначе режим понижается до `coming_soon` (`LearnerBillingService::paywall`; `EntitlementResolver::paywall()` отдаёт сырой режим конфига).
- `settings` — текущие настройки (A.2b) и варианты выбора для экрана «Настройки»: `reminder_time` — `HH:MM` в часовом поясе learner'а, `null` = напоминания выключены;
  `timezone_change_available_at` — ISO 8601 UTC, когда можно снова сменить часовой пояс (раз в 24 ч, A.2b), `null` = можно сейчас (TWA блокирует выбор пояса до этого момента);
  `active_course` — `null`, если курса нет или он стал недоступен; `options.timezones[].label` и `active_course.title` — на языке learner'а (город + текущее смещение UTC,
  например «Ташкент (UTC+5)»); текущий пояс/время, которых нет в списках (выставлены через API), добавляются в конец списка. `options.locales[].label` — всегда на самом языке.
- `practice` (docs/16 §3.3, A.16) — доступ к практике; блок **всегда** есть в ответе (`PracticeAccess::describe`, без запросов к БД сверх кэша тарифа). `available` — входы в практику
  можно показывать: практика открыта ученику (`PRACTICE_ACCESS` = `all`, либо `pilot` и ученик в пилоте) **или** это Free-ученик при `PRACTICE_PLAN=pro` (вход виден с чипом Pro); при `false` TWA входов не рисует, а
  эндпоинты практики отвечают 403 `practice_unavailable`. `pro_required` — Free-ученик при `PRACTICE_PLAN=pro`: нажатие на вход открывает пейвол `practice`, сервер отвечает 403 `practice_pro_required`.
  `modes` — включённые режимы (`PRACTICE_MODE_*`), `battle` — переключатель баттла (`PRACTICE_BATTLE`). Блок есть и в других ответах формы `/me` (`PATCH /me/settings`, `POST /billing/subscription/cancel|resume`, `POST /promo/redeem`) — их собирает тот же `MePayloadBuilder`.
- Цель дня и streak — в `GET /today` (A.2a), не в `/me`.

### A.2a `GET /api/twa/today`
Экран «Сегодня» (docs/12 §7.3): всё, что нужно главному экрану learner'а, за один запрос. `TodayService::summary`. Не «чистое чтение»: `StreakService::current()` может выполнить одиночный `UPDATE learners` (самопочинка отставшей проекции streak, docs/12 §7.4), поэтому метод нельзя вызывать внутри транзакции, держащей `training_sessions` / `word_repetitions` / `learner_daily_stats` (порядок блокировок docs/12 §3.1; важно для напоминаний 1.5b).

**Response:**
```json
{
  "due_count": 23,
  "new_words_remaining": null,
  "goal": { "target": 20, "done": 8, "met": false },
  "streak": { "current": 5, "best": 12, "met_today": false, "at_risk": true, "freezes": 1, "frozen_yesterday": false },
  "active_course": {
    "id": 3, "slug": "english-a1", "title": "English A1",
    "progress": { "learned": 120, "total": 900 },
    "next_lesson": { "id": 41, "stage": 2, "lesson": 4, "title": "Food" }
  },
  "open_session_id": 918,
  "entitlement": { "plan": "free", "source": "free", "until": null },
  "onboarding_completed": true,
  "timezone": "Asia/Tashkent"
}
```

- `due_count` — то же правило, что `words_due_today` в `/me` (`DueRepetitionCriteria`, A.2): слова, выведенные из курса (`is_active = false`), не считаются.
- `new_words_remaining` — сколько новых слов осталось по квоте Free на сегодня; `null` = безлимит. Считает `NewWordQuota::remaining` (1.4b): лимит плана (`config/billing.php`, Free = 20) минус число новых карточек, выданных learner'у в **личных** сессиях за **локальный** день (`word_repetitions.created_at` в окне `LearnerClock::limitWindowUtc` по `learners.timezone` с переносом дня при смене пояса, `introduced_mode <> 'group_lesson'`), не меньше 0; слова групповых уроков не считаются (docs/12 §5.3). Только чтение, без блокировок. `null` — Pro (личный и спонсорский) без лимита.
- `goal` — цель дня: `target` = `learners.daily_goal` (оценки), `done` = `learner_daily_stats.reviews_count` за **локальный** день learner'а (`learners.timezone`, граница — локальная полночь, не UTC), `met` — цель достигнута (остаётся `true`, когда `done` растёт дальше).
- `streak` — `current` серия дней с выполненной целью (0, если пропущен день — обнуляется при чтении), `best` рекорд, `met_today` сегодня уже засчитан, `at_risk` = `current > 0 && !met_today` (серия оборвётся, если не выполнить цель до локальной полуночи). Правила — docs/12 §7.4. **Заморозки (2C.2, docs/06 §6a):** `freezes` — сколько заморозок можно потратить (Pro; у Free всегда `0`, даже если в базе что-то осталось от прошлого Pro), `frozen_yesterday` — вчерашний день пропущен и удержан заморозкой. Сам запрос у Pro **пополняет** инвентарь (+1 за локальную неделю, до 2, `StreakFreezeService::refill`) и, если пропущен ровно вчерашний день, **тратит** заморозку (`current` сохраняется, `at_risk = true`, событие `streak.frozen`). Free видит тизер «заморозки — в Pro» (paywall reason `streak_freeze` в TWA).
- `active_course` — активный курс learner'а или `null` (курса нет или он уже недоступен — не `published` либо `private`, единое правило `CourseCatalogService::isAvailable`, — тогда экран предлагает выбрать курс). `title` на языке learner'а (fallback `ru`); `progress` — активные слова курса: `learned` (`repetitions >= 1`) из `total`. `next_lesson` — первый урок курса с ещё не показанными активными словами (источник новых слов `daily_review`), `null`, если показано всё; `id` — для `POST /training/sessions {mode: "lesson", lesson_id}`, `title` может быть `null`.
- `open_session_id` — открытая и **неистёкшая** `daily_review` learner'а (продолжить); `null` — нет такой (просроченная, но не закрытая планировщиком, не считается).
- `entitlement` — как в `/me` (A.2), без `new_words_per_day`.
- `onboarding_completed` — `onboarding_state = done`. Экран работает и без онбординга (learner мог прийти из группы), но может подсказать завершить настройку в боте.
- `timezone` — IANA-пояс learner'а, в котором считаются локальный день, `goal` и `streak` (`learners.timezone`; именно тот, что берёт `LearnerClock`: пустое или неизвестное значение заменяется поясом по умолчанию `config('learners.default_timezone')`, затем UTC — поле всегда непустая строка). Берётся из уже загруженного learner'а и `LearnerClock`, лишних запросов к БД нет. По нему TWA датирует шапку «Главной» («вторник, 28 октября»; `formatDate(…, timeZone)`), а не по поясу устройства: `/me` экран «Сегодня» не запрашивает (docs/14 §6.1). После смены пояса в настройках (A.2b) поле меняется сразу.

Кнопка «Повторить» — `POST /training/sessions {"mode": "daily_review", "source": "self"}` (A.3). Ошибки: 401 (как у всех маршрутов TWA).

### A.2b `PATCH /api/twa/me/settings`
Частичное изменение настроек (docs/12 §3.8); `LearnerSettingsService::update` — то же место, через которое идёт `/settings` в боте. Меняются только переданные поля,
лишние поля игнорируются, пустое тело — no-op. Ответ — тот же JSON, что у `GET /me` (A.2), уже с новыми настройками (подписи в новом языке).

| Поле | Правило |
|---|---|
| `interface_locale` | `ru` \| `uz` |
| `timezone` | строка, id из `DateTimeZone::listIdentifiers()` (IANA, регистр важен, любой — не только из списка выбора) |
| `reminder_time` | `HH:MM`, минуты кратны 15 (`00/15/30/45`); `null` — выключить напоминания |
| `daily_goal` | целое (не строка) из `config('learners.daily_goal_options')`: 10 / 20 / 40 |
| `goal`, `level` | как в онбординге: `general`/`ielts`/`sat`/`school`; `A1`..`B2` |
| `exercises` | объект `{reverse, audio, spelling, gap_fill}` со строгими булевыми (2D.2, docs/13 §8.2); **частичный** — меняются только переданные ключи; другие ключи / не-булевы → 422. Хранится в `learners.meta.exercises`; flash выключить нельзя. В ответе `settings.exercises` — всегда все четыре ключа (по умолчанию `true`). Выключенный тип селектор не выдаёт; `spelling`/`gap_fill` у Free и так не выдаются (Pro-features) — настройка хранится и действует после покупки |

Невалидное значение — `422` (стандартный ответ валидации Laravel), ничего не меняется. Активный курс меняется отдельно — `POST /me/course` (A.5a).
**Часовой пояс меняется не чаще раза в 24 ч** (docs/12 §5.3): новое значение, отличное от текущего, раньше срока → `422` в общем конверте ошибок
`{"error": {"code": "timezone_change_too_soon", "message": "…; next change at <ISO-8601 UTC>."}}`, и **ничего** из запроса не применяется (в том числе другие поля). Та же зона
повторно — не смена и не считается. Первая установка в онбординге интервал не запускает; но если пояс уже менялся через настройки (или перенос дня ещё активен), шаг пояса онбординга идёт по тем же правилам (24 ч, перенос дня; отказ — тот же текст `timezone_too_soon`, шаг остаётся). Смена пояса **переносит** текущий локальный день в новый пояс: окно квоты новых слов
сохраняет начало старого дня и длится до первой полуночи нового пояса не раньше конца старого (`learners.meta.local_day_carry`), поэтому `new_words_remaining` после смены не вырастает,
а следующая квота приходит в полночь нового пояса (один «длинный» день — до двух суток, на DST-переходе чуть больше).
Побочные эффекты: при передаче `timezone` или `reminder_time` пересчитывается `learners.next_reminder_at` (`LearnerClock::nextOccurrenceUtc`; `NULL`, пока напоминания выключены);
смена `interface_locale` действует сразу (перевод карточки в `/next`, каталог, бот); если `daily_goal` понижен до уже набранного сегодня `reviews_count` и больше —
`goal_met_at` ставится сразу (`DailyStatsService::reconcileGoal`, отдельной транзакцией после коммита `learners`; streak засчитывается после commit, событие `daily_goal.met`);
уже выполненная цель не отзывается при повышении. Событие `settings.changed {fields}` (список реально изменённых полей через запятую; без изменений — без события).
Rate limit — общий `twa-api`.

**Отмена смены пояса — `POST /api/twa/me/settings/timezone/revert`** (2R.7, без тела). В течение 10 минут после принятой смены `timezone` возвращает прежний пояс и прежние `timezone_changed_at` / `local_day_carry` (при смене они сохраняются в `learners.meta.timezone_change_undo` = `{timezone, changed_at, previous_changed_at, previous_day_carry}`): штрафа 24 ч нет, окно квоты новых слов — ровно такое, каким было бы без смены. Ответ — как у `GET /me`. Позже 10 минут, без недавней смены или повторно (снимок расходуется; новая смена записывает свой) — `422 timezone_revert_unavailable`. Блок `settings` в `GET /me` / ответах отдаёт `timezone_undo_available_until` (ISO-8601 UTC или `null`). Заморозки серии не затрагиваются (`streak_freezes_refilled_on` привязан к неделе и только растёт). Бот: под «Сохранено.» после смены пояса в `/settings` — кнопка «Отменить» (`set1:tzundo`).

### A.3 Training mode

#### `POST /api/twa/training/sessions`
Learner открывает **личную** сессию (docs/12 §7.6): урок целиком (`lesson`) или ежедневное повторение по всем урокам (`daily_review`).
Идемпотентно: пока есть открытая неистёкшая сессия того же (learner, урок) / (learner, `daily_review`), возвращается она же
(одна на пару, в том числе при гонке двух вкладок); просроченная, но ещё не закрытая планировщиком — закрывается
(`done_reason = expired`) и заменяется новой. Для `daily_review` `course_id` сессии обновляется до текущего активного курса.

**Request:**
```json
{
  "mode": "lesson",          // lesson | daily_review (group_lesson открывает только учитель из бота)
  "lesson_id": 321,          // обязателен для lesson (integer ≥ 1), запрещён для daily_review
  "source": "picker"         // optional: self (по умолчанию) | picker | reminder | onboarding; записывается только в НОВУЮ сессию
}
```

**Response (200, всегда — и для новой, и для уже открытой сессии):** ровно как у `/start` ниже (`session_id`, `mode`, `lesson`,
`total_words`, `card`, `progress`, `done_reason`). Клиент после ответа переходит на экран тренировки по `session_id`
(экран снова вызывает `/start`, это идемпотентно: первая карточка та же).

**Ошибки:** 422 валидации Laravel (`mode` вне `lesson`/`daily_review`, `lesson_id` не integer, `source` вне списка) →
422 `lesson_mismatch` (`lesson` без `lesson_id` или `daily_review` с ним) → 404 `lesson_not_found` (урока нет **или** его курс
не `published` — статус курса не раскрывается) → 429 (лимит `twa-session-create`, 10 запросов/мин на learner'а).
(`invalid_mode` из сервиса при HTTP недостижим — режим отсекает валидация; код оставлен в списке для клиента.)

Побочный эффект: событие `training.session_opened` `{training_session_id, mode, source, reused}` (`reused = true`, если вернулась уже открытая сессия).

#### `POST /api/twa/training/sessions/{session_id}/finish`
Learner завершает **свою личную** сессию (`status = closed`, `ended_at`, `done_reason = finished`) и получает итог по ней.
Идемпотентно: повтор отвечает 200 с теми же числами и не меняет `ended_at`/`done_reason` (в том числе у сессии, уже закрытой
планировщиком с `expired`). Порядок проверок: 404 `session_not_found` → 403 (`forbidden` — чужая личная сессия; `not_a_member` / `group_inactive` — групповая сессия, к которой нет доступа) → 403 `forbidden` для
**любой** групповой сессии (её закрывает учитель/планировщик; сервис при прямом вызове отвечает `not_personal`). Закрытая
сессия — не 410: здесь 410 нет.

**Response:**
```json
{
  "reviewed": 14, "correct": 11, "new_words": 4,
  "goal": { "target": 20, "done": 14, "met": false },
  "streak": { "current": 5, "best": 12, "met_today": false, "at_risk": true, "freezes": 1, "frozen_yesterday": false },
  "practice_entry": { "course": "english-a1", "lesson_id": 41 }
}
```
`reviewed` — принятые оценки в сессии, `correct` — из них с `quality >= 3`, `new_words` — слова, у которых строка
`word_repetitions` создана не раньше начала сессии. `goal` и `streak` — состояние **дня** learner'а на момент ответа (те же
поля и правила, что в `GET /today`, A.2a): `goal.done` считает все оценки локального дня, а не только этой сессии. Повторный
`finish` в тот же день отдаёт те же `goal`/`streak`; в следующем локальном дне они уже будут новыми (числа сессии — прежними).

`practice_entry` (docs/16 §11.1, A.16) — куда ведёт кнопка «Практика урока» / «Практика курса» на экране итога; ключ **есть всегда**, значение `{course, lesson_id}` или `null`.
`course` — slug курса (адрес хаба `GET /practice/hub?course=…`), `lesson_id` — урок занятия (`mode = lesson`) либо `null` у ежедневного занятия (`daily_review`) с курсом (`training_sessions.course_id` —
снимок основного курса). `null` — когда кнопки не должно быть: ежедневное занятие без курса; групповая сессия (её закрывает учитель, ученик получил бы 403); практика закрыта ученику
(`PRACTICE_ACCESS=off` или ученик не в пилоте); курс или урок хаб не откроет — курс не опубликован либо приватный курс центра (урок, открытый по заданию центра). Free-ученик при `PRACTICE_PLAN=pro`
запись получает (хаб покажет карточки с чипом Pro, как кнопка на экране курса). **Материал не проверяется**: курс или урок без достаточного числа слов и заданий хаб объясняет сам («Мало материала» + «Учить урок»),
а считать четыре режима на каждый `finish` дороже одного запроса. Цена записи — один запрос (урок с курсом одним JOIN'ом, либо курс ежедневного занятия); `Learning\Services\PracticeEntryResolver` (в домене Learning: код практики не импортирует модели обучения, `PracticeIsolationTest`; практика лишь отвечает, открыта ли она), закреплено `PracticeEntryTest` и `TwaContractTest`.

Незавершённые сессии закрывает команда `training:close-expired` (планировщик, каждые 15 минут, `withoutOverlapping`): личные — по
`expires_at` (`now + learning.session_ttl_hours`, 12 ч, при открытии), групповые — старше `group_session_ttl_hours`; обе с
`done_reason = expired`, `ended_at` = момент закрытия.

После `finish` (и после закрытия планировщиком) `/start`, `/next`, `/review` этой сессии отвечают 410 `session_closed`; то же — для личной сессии с `expires_at <= now`, которую планировщик ещё не закрыл. Открытая групповая сессия старше половины `group_session_ttl_hours` при повторном `/start_training` или открытии по ссылке напоминания закрывается (`expired`) и заменяется новой.

#### `POST /api/twa/training/sessions/{session_id}/start`
Learner присоединяется к открытой тренировке. Доступ — по членству (docs/12 §4.4): групповая сессия доступна **активному
участнику активной группы**, личная (`mode <> group_lesson`) — только её владельцу. Порядок проверок: 404 `session_not_found` →
403 (`not_a_member` — нет активного членства, в т.ч. вышел/исключён; `group_inactive` — участник группы, которая не активирована;
`forbidden` — чужая личная сессия) → 410 `session_closed` (`status <> 'open'`).

Прогресс общий: у learner'а в двух группах одна строка `word_repetitions` на слово, поэтому оценка в тренировке группы A
видна в тренировке группы B.

**Response:**
```json
{
  "session_id": 123,
  "mode": "group_lesson",    // group_lesson | lesson | daily_review
  "lesson": { "stage": 4, "lesson": 8, "title": "Resilience" } | null,   // null для daily_review
  "total_words": 25,         // lesson/group_lesson: активные слова урока; daily_review: learners.daily_goal
  "card": { "word_id": 1001, "word": "Resilient", "translation": "Устойчивый", "translation_fallback": false, "example": null, "transcription": null, "audio_url": null, "card_kind": "new", "serve_token": null, "prompt": { "type": "flash", "front": "Resilient", "masked_example": null, "options": null, "answer_length": null } } | null,
  "progress": { "done": 0, "total": 25 },
  "done_reason": null,       // см. ниже; не null ровно когда card = null
  "quota": { "new_words_remaining": 19 }   // см. «Квота новых слов» ниже; null = безлимит (Pro)
}
```

Для `prompt.type` = `spelling` / `gap_fill` карточка не содержит ответа: `word` = `""`, `example`, `transcription`, `audio_url` = `null` (правильный ответ — в `graded.expected` ответа `/review`).

`card` / `progress` / `done_reason` / `quota` — первая карточка, прогресс, причина «карточек нет» и квота в том же формате, что у `/next`.
Если у урока сессии нет (удалён) — 404 `lesson_not_found`.

#### `GET /api/twa/training/sessions/{session_id}/next`

После `TWA_MAX_CARDS_PER_SESSION` принятых review в рамках одной сессии возвращает `card: null`.
Следующая карточка. Возвращает либо `due` (для повторения), либо `new` (новое слово из урока).

**Response:**
```json
{
  "card": {
    "word_id": 1001,
    "word": "Resilient",
    "translation": "Устойчивый",
    "translation_fallback": false, // true — перевод на русском, потому что на язык сессии его ещё нет (клиент показывает пометку)
    "example": "She is a resilient girl.",
    "transcription": "rɪˈzɪl.i.ənt",
    "audio_url": "https://…/storage/audio/3f….mp3", // предсгенерированный mp3 слова (1.2c / 2R.4) или null → клиент идёт в Web Speech / GET /tts
    "card_kind": "due",     // due | new | learning (минутные learning steps, см. docs/06 §4)
    "serve_token": null,    // string для карточки, выданной досрочно (learn-ahead) — вернуть в POST /review
    "prompt": {             // упражнение этой выдачи (2D.2, docs/13 §2.5, docs/06 §4.3)
      "type": "reverse",    // flash | reverse | audio | spelling | gap_fill
      "front": "Устойчивый",// flash: слово; reverse/spelling/gap_fill: перевод; audio: "" (слово только на слух)
      "masked_example": null, // gap_fill: пример с формой слова, заменённой на "____"
      "options": ["Brittle", "Resilient", "Fragile", "Tender"], // reverse: 4 английских слова; audio: 4 перевода; иначе null
      "answer_length": null // spelling/gap_fill: длина ожидаемого текста
    }
  },
  "progress": { "done": 7, "total": 25 },
  "done_reason": null,       // null, пока есть card; иначе причина, см. ниже
  "quota": { "new_words_remaining": 12 }   // null = безлимит (Pro)
}
```

**`progress`:** в уроке — `done` = слова урока, дошедшие до `repetitions >= 1`, `total` = активные слова урока; в `daily_review` —
`done` = оценки в этой сессии, `total` = `learners.daily_goal`. В `daily_review` `done` может превысить `total`
(цель перевыполнена) — клиент обрезает полосу прогресса до `total`.

**`done_reason`** (`card: null` ⇒ ровно одно из значений; при наличии карточки — `null`):

| Значение | Когда |
|---|---|
| `lesson_complete` | scope `lesson`: все слова урока пройдены, ничего не due и не в learning steps |
| `nothing_due` | scope `daily_review`: ничего не due, а новых слов в активном курсе нет (или курс не выбран) |
| `new_quota_exhausted` | новые слова ещё есть, но дневной лимит новых карточек исчерпан, а due и learning-карточек нет (план Free, 1.4b); клиент показывает paywall |
| `session_cap` | принято `TWA_MAX_CARDS_PER_SESSION` оценок в этой сессии |

Клиент показывает экран «готово» с текстом по `done_reason`; для `new_quota_exhausted` — ещё и `PaywallSheet` (ниже).

**Квота новых слов (`quota`, 1.4b; docs/12 §5.3).** `quota.new_words_remaining: int|null` есть в ответах `/start`, `/next`, `/review` и `POST /training/sessions`
(в том числе когда `card = null`): сколько новых карточек learner ещё получит **сегодня** (локальный день, `learners.timezone`) **после** этого шага — только что выданная
новая карточка уже учтена. `null` — безлимит (Pro, личный или спонсорский центром, **а также любая групповая сессия `group_lesson`**). Free: `20` (`BILLING_FREE_NEW_WORDS_PER_DAY`)
минус число строк `word_repetitions` learner'а, созданных в этот день в **личных** сессиях (`lesson`, `daily_review`; `introduced_mode <> 'group_lesson'`). Групповой урок, который
ведёт учитель, квотой не ограничивается и её не расходует: Free-ученик видит все слова урока, а введённые там слова личную квоту не уменьшают (docs/12 §5.3). Повторения (due,
learning, learn-ahead) квотой не ограничиваются: после исчерпания `/next` по-прежнему отдаёт due-карточки, а `done_reason = new_quota_exhausted` приходит, только когда больше нечего показать.
`null` — не признак тарифа (Free в групповой сессии тоже получает `null`): план — только `entitlement` из `/me`. Число — для отображения (читается без блокировки, вне транзакции выдачи); сам лимит применяет сервер при выдаче, под блокировкой строки learner'а. Смена часового пояса квоту
**не обновляет**: день переносится в новый пояс (A.2b), следующая квота — в полночь нового пояса не раньше конца старого дня.

**`PaywallSheet`** (режим `paywall.mode = coming_soon`, данные — `GET /me`: `entitlement.new_words_per_day`, `paywall.contact`): заголовок «Pro скоро», описание Free (N новых слов в день,
повторения без лимита), встроенный ввод промокода (`POST /promo/redeem`, A.7) и — только при непустом `paywall.contact` — строка «Учитесь в центре? …» со ссылкой
(`@handle` → `https://t.me/handle`, e-mail → `mailto:`, `http(s)` URL — как есть, иное — простой текст; никакого `v-html`). Показывается на экране «готово» при `done_reason = new_quota_exhausted`
и по кнопке «Узнать о Pro» на экране «Сегодня» у плана Free. После успешного промокода обновляются `/me` и `/today`. Событие `paywall.shown {reason: new_quota_exhausted, source: server}`
пишет **сервер** (`TrainingFlowService::nextStep`) при первом за окно квоты (`LearnerClock::limitWindowUtc`, а не за календарный день: после смены пояса окно длиннее суток) ответе с `done_reason = new_quota_exhausted` (дедупликация `Cache::add`, ключ — начало окна, TTL 3 суток);
клиентского канала продуктовых событий нет, так что «упёрся в лимит» и есть «увидел paywall» (docs/12 §5.4).

**`PaywallSheet`, режим `purchase`** (2A.2, `paywall.mode = purchase` в `/me`): заголовок «LexiFlow Pro», переключатель периода (месяц/год) из `GET /billing/catalog`, цена на период и кнопки способов
оплаты — «⭐ Stars» (`checkout.kind = invoice_link` → `Telegram.WebApp.openInvoice(url, cb)`), «Payme», «Click» (`kind = redirect` → `Telegram.WebApp.openLink(url)`); цены в тийинах делятся на 100 на клиенте, перечёркнутая
`list_prices` и бейдж скидки — при открытом offer. Кнопки — только для провайдеров из `providers` с ценой в их валюте. Если автопродление Stars уже работает (`subscription.auto_renew`), покупка скрыта (сначала отключить
его в `/billing`) — иначе `checkout_conflict`. После `openInvoice` со статусом `paid` и после возврата с Payme/Click (окно снова видимо) TWA опрашивает `GET /billing/orders/{token}` не чаще раза в 3 с (до 2 мин):
`paid` → обновление `/me` и `/today`, текст «Pro до <дата>»; `expired`/`canceled`/`failed` — сообщение об ошибке. Промокод остаётся. Если сервер вернул `coming_soon` — шторка как в Фазе 1. Экран **`/billing`** (`BillingView`,
ссылка из «Настроек»): план и срок, автопродление, кнопки «Отключить» / «Включить автопродление» (`/billing/subscription/cancel|resume`) и «Купить / Продлить» (шторка выше). История платежей — задача 2A.7.
Личную сессию на экране «готово» клиент закрывает `POST /finish` (best effort); групповую не закрывает никогда.

**Язык перевода** (`translation`): групповая сессия — язык группы (`telegram_groups.meta.locale`, по умолчанию `ru`),
личная сессия — язык интерфейса learner'а (`interface_locale`). Если перевода на этот язык нет, отдаётся русский
(`word_translations.ru` → `words.translation`) и `translation_fallback = true`; для русского языка флаг всегда `false`
(docs/12 §1.7, docs/03 §2.21).

**Упражнения (`card.prompt`, 2D.2).** `prompt.type` решает экран и способ оценки: `flash` (слово → перевод, learner оценивает сам),
`reverse` (перевод → 4 английских слова) и `audio` (слово на слух → 4 перевода) **оцениваются на клиенте** — правильный ответ есть в `card.word` /
`card.translation`, клиент шлёт `quality` (TWA: верно → 4, неверно → 2); `spelling` (перевод → ввод слова) и `gap_fill` (пример с пропуском → ввод формы)
**оцениваются сервером** — клиент шлёт `answer`. Тип выбирает `ExerciseSelector` **детерминированно** по (state, repetitions, word_id, план, настройки,
режим сессии) — docs/06 §4.3; два подряд `/next` для одной выдачи дают одинаковый `prompt` (варианты и их порядок — от хэша). `spelling`/`gap_fill` — только
Pro (`exercise.spelling`, `exercise.gap_fill`) и только с `repetitions ≥ 3` на каждом третьем проходе; Free и `group_lesson` их не получают никогда
(`group_lesson` — только `flash`/`reverse`). `reverse`/`audio` без трёх дистракторов (крошечная колода) приходят как `flash`. `audio_url` — то же, что в `prompt`
(в `prompt` не дублируется): предсгенерированный mp3 (`words.audio_path` совпадает с текущим хэшем текста), иначе `null`.

При отсутствии карточек — `{ "card": null, "progress": { ... } }` и клиент показывает экран «готово».
Пока в уроке есть карточки в learning steps, сервер отдаёт их досрочно (learn-ahead), а не `null`.
Такая карточка приходит с одноразовым `serve_token` (docs/06 §4–5.2): клиент хранит его вместе с
карточкой и отправляет в `POST /review`. Для due/new карточек `serve_token = null`.

#### `POST /api/twa/training/sessions/{session_id}/review`
Оценка карточки.

**Request** — ровно одно из `quality` / `answer` (2D.2):
```json
{
  "word_id": 1001,
  "quality": 2,             // flash/reverse/audio: 0..5; TWA шлёт 2=Трудно (Again), 4=Средне (Good), 5=Легко (Easy); 3 = Hard-pass
  "answer": "resiliant",    // spelling/gap_fill: текст learner'а (≤ 200 символов; пустой/null = «не знаю», оценивается как ошибка); quality при этом запрещён
  "time_spent_ms": 4300,
  "serve_token": null       // optional, <= 64 символов: card.serve_token выданной карточки (обязателен для learn-ahead)
}
```
Ключ `answer` в теле (даже `null`) переключает запрос в режим ответа: `quality` тогда `prohibited`, без `answer` — `quality` `required` (422 валидации).
Сервер **пересчитывает** упражнение карточки по тем же входам, что и `/next` (ничего не хранит, docs/13 §8.2), и для `spelling`/`gap_fill` ставит `quality`
сам (`SpellingGrader`, docs/06 §4.3): точное совпадение → 5, в допуске → 4, иначе 1. В `training_reviews` пишутся `exercise` (всегда) и `answer_text`
(обрезка до 100 символов; `''` — пустой ответ, `null` — оценка клиентом).

**Response:**
```json
{
  "next_review_at": "2026-04-22T14:00:00Z",
  "new_interval_days": 3,    // 0, пока карточка в минутных learning steps
  "easiness_factor": 2.45,
  "graded": { "quality": 4, "correct": true, "expected": "resilient" } | null, // вердикт сервера по answer; null при quality
  "card": { ... } | null,    // следующая карточка (как в /next)
  "progress": { "done": 7, "total": 25 },
  "done_reason": null,
  "quota": { "new_words_remaining": 12 }
}
```

Ошибки: 409 `card_not_started` (карточка не выдавалась), 409 `card_not_due` (повторный submit:
карточка ещё не due, а `serve_token` отсутствует, устарел или чужой — docs/06 §5.2; клиенту следует
перезапросить `GET /next`), 422 `out_of_scope` (слово вне scope сессии: в `group_lesson`/`lesson` — слово другого урока,
в любом режиме — неактивное слово `words.is_active = false`; бывш. `wrong_lesson`), 410 `session_closed`.
Упражнения (422, после проверок выше, ничего не записано): `quality_not_accepted` — карточка `spelling`/`gap_fill`, нужен `answer`;
`answer_not_accepted` — карточка `flash`/`reverse`/`audio`, нужен `quality`; `exercise_pro_required` — `answer` от плана без `exercise.spelling`/`exercise.gap_fill`
(**paywall-reason**: у Free письменных упражнений нет). TWA на все три перезапрашивает `GET /next` (сервер отдаст карточку с актуальным упражнением).

### A.4 Exam mode

**Персональный порядок (anti-cheat).** Набор вопросов строится один раз при `/start_exam`
(`ExamQuestionBuilder`) и хранится в `exam_sessions.config.questions` в *каноническом* виде
(`word_id, word, correct_translation, options[]`, без `correct_index`). При выдаче
`ExamQuestionPersonalizer` применяет к нему детерминированную перестановку **для конкретного learner'а**:
порядок вопросов и порядок вариантов у каждого свои, seed = `HMAC-SHA256(config.order_secret, session_id|learner_id)` (случайный секрет сессии, не отдаётся в API; для legacy-сессий без него — `app.key`) —
участник не может вычислить чужой порядок. Следствия:

- `question_index` везде — позиция в **личной** последовательности learner'а; повторный
  `GET /question?index=i` возвращает тот же `word_id` и тот же порядок `options`, таймер не перезапускается.
- Правильный индекс считается сервером для этого learner'а и при выдаче, и при `answer`; подсказка
  «3 — B» из чата у соседа не сработает (у него другой вопрос под №3 и другой порядок вариантов).
- `options` содержит правильный перевод и до 3 **реальных** дистракторов. Слои кандидатов (только активные слова):
  другие уроки того же stage → другие stage'ы **своего** курса → другие слова этого же урока → **чужие** курсы
  (только `published` + `public` + платформенные; draft/review/archived/unlisted/private и курсы организаций
  не используются, а слой подгружается лишь если в своём курсе меньше нужного числа уникальных кандидатов
  **на языке ответа**). Язык ответа обходится по всем слоям первым, другой язык — последняя мера
  (см. ниже); внутри слоя предпочтительна та же `part_of_speech`; без дубликатов с учётом регистра/пробелов.
  Заглушек нет; при бедной библиотеке вариантов может быть меньше 4 (минимум 2).
- Тексты вопросов — переводы на язык **группы** (`exam_sessions.config.locale`, фиксируется при `/start_exam`) с fallback
  на `ru`. Чтобы язык не выдавал ответ (кириллица рядом с латиницей), дистракторы того же языка, что и верный ответ,
  берутся первыми; другой язык — только если их не хватает. Флага fallback у экзаменационных вопросов нет.
  Если для слова нет ни одного реального дистрактора — `/start_exam` отвечает учителю ошибкой, сессия не создаётся.
- Сессии, созданные до этого режима (нет `config.question_order`), доигрываются с общим порядком как раньше.

**Доступ к экзамену** (все маршруты `/exam/sessions/{id}/*`): активное членство в активной группе сессии (`ExamSessionPolicy`).
Ошибки: 403 `not_a_member` (бывш. `forbidden`), 403 `group_inactive`, 410 `session_closed` (join/question/answer после конца), 409 `exam_still_open` (`/result` до закрытия).
Один learner в двух группах участвует в экзаменах каждой группы независимо (ответы и результаты привязаны к `(exam_session_id, learner_id)`).

#### `POST /api/twa/exam/sessions/{session_id}/join`
**Response:**
```json
{
  "session_id": 55,
  "ends_at": "2026-04-19T19:10:00Z",
  "total_questions": 10,
  "seconds_per_question": 10,
  "question": { "question_index": 0, "word_id": 1001, "word": "Resilient", "options": ["..."], "seconds_left": 10 }
}
```

`question` — первый вопрос **личной** последовательности learner'а (формат как у `/question`);
`null`, если в сессии нет вопросов. Повторный `join` идемпотентен: серверный таймер вопроса не перезапускается.

#### `GET /api/twa/exam/sessions/{session_id}/question?index=0`
**Response:**
```json
{
  "question_index": 0,
  "word_id": 1001,
  "word": "Resilient",
  "options": [
    "Устойчивый",
    "Хрупкий",
    "Смелый",
    "Тихий"
  ],
  "seconds_left": 10
}
```

#### `POST /api/twa/exam/sessions/{session_id}/answer`
**Request:**
```json
{
  "question_index": 0,
  "word_id": 1001,
  "selected_option_index": 0,
  "time_spent_ms": 3200
}
```

**Response:**
```json
{
  "is_correct": true,
  "correct_option": 2,
  "score_earned": 15,
  "total_score": 45,
  "has_next": true,
  "next_question": null
}
```

`next_question` всегда `null`: следующий вопрос запрашивается после показа фидбэка, чтобы его серверный
таймер стартовал в момент показа.

`selected_option_index` и `correct_option` — индексы в `options` **этого** learner'а (его личный порядок).
`correct_option` возвращается только после фиксации ответа (для подсветки в TWA). Ошибки: 404 `question_not_found`,
422 `word_mismatch` (`word_id` не соответствует вопросу под этим индексом у данного learner'а),
409 `question_not_served` / `already_answered`, 410 `session_closed`.

#### `GET /api/twa/exam/sessions/{session_id}/result`
Доступно только после закрытия сессии.
```json
{
  "learner_score": 95,
  "rank": 3,
  "total_participants": 12,
  "leaderboard": [
    { "rank": 1, "name": "Alex", "score": 120 },
    { "rank": 2, "name": "Maria", "score": 110 }
  ]
}
```

### A.4a Пробный тест IELTS / SAT — персональный mock (docs/13 §8.4, Фаза 2 / 2D.4, Pro)

Сессия `exam_sessions` с `mode = personal_mock`, `learner_id`, `course_id` (группы, урока и учителя нет). Строится
`MockExamService::open` из **случайных активных слов курса** (seed = id сессии: один и тот же набор у одной сессии,
разный у разных), по умолчанию 40 вопросов за 20 минут (`config('learning.mock_exam')`). Играется на тех же
маршрутах `/exam/sessions/{id}/*`, что и групповой экзамен (персональный порядок, `ExamQuestionPersonalizer`), но
**таймер один на весь тест**: `seconds_per_question` в `join` равен всему лимиту, клиент не показывает
покарточный отсчёт. Доступ — **только владелец** (`ExamSessionPolicy`: иначе 403 `forbidden`).

#### `POST /api/twa/courses/{slug}/mock-exam`
Открывает mock курса или возвращает уже открытый (у learner'а не больше одного открытого mock'а — любого курса).
`throttle:twa-session-create`.

**Response 201** (новый) / **200** (возвращён открытый, `resumed: true`):
```json
{
  "session_id": 91, "mode": "personal_mock", "course_id": 5,
  "started_at": "2026-10-20T10:00:00+00:00", "ends_at": "2026-10-20T10:20:00+00:00",
  "total_questions": 40, "seconds": 1200, "resumed": false
}
```

Ошибки: 403 `exam_simulator_pro_required` (Free — TWA показывает paywall `reason: exam_simulator`), 404 `course_not_found`
(нет / не опубликован / недоступен learner'у), 422 `course_not_exam` (`exam_tag ∉ {ielts, sat}`), 422 `not_enough_words`
(< 4 активных слов), 422 `not_enough_distractors`. Просроченный открытый mock при новом `open` финализируется и заменяется.

#### `GET /api/twa/exam/sessions/{session_id}/result` для `personal_mock`
Сервер **сам закрывает** mock при этом запросе, когда отвечены все вопросы или вышло время (а брошенные — `exams:close-expired`);
пока тест идёт и вопросы остались — 409 `exam_still_open`, как у группового. После `ends_at` `answer`/`question`
отвечают 410 `session_closed`.
```json
{
  "mode": "personal_mock", "correct": 30, "total": 40, "answered": 38,
  "percent": 75, "percentile": 82, "attempts": 9, "course_id": 5
}
```
`percent` — верных от **всех** вопросов (неотвеченный = неверный); `percentile` — доля (0..100) закрытых mock'ов
**этого курса за 90 дней** с процентом ≤ моего, включая мой (одинокая попытка → 100); `attempts` — размер этой выборки.
Распределение по курсу кэшируется на 1 час и сбрасывается при каждой финализации mock'а курса. Mock без единого ответа
закрывается без строки `exam_results` и в чужие выборки не попадает. Форма ответа для группового экзамена не изменилась:
клиент различает по `mode`.

События: `exam.mock_started {exam_session_id, course_id, exam_tag, questions}`,
`exam.mock_finished {exam_session_id, course_id, percent, percentile, answered, reason: finished | expired}` (один раз).

### A.5 TTS fallback
`GET /api/twa/tts?word=resilient&voice=en-US-AriaNeural`
Возвращает `audio/mpeg`. Cache-friendly: `Cache-Control: public, max-age=2592000`.
Если для `word` (+`voice`, по умолчанию `TTS_VOICE`) есть предгенерированный файл (`audio:pregenerate`, задача 1.2c), он отдаётся с диска без обращения к edge-tts; иначе — запрос в сервис.

**`card.audio_url`** (`/training/.../start|next|review`): публичный URL предгенерированного mp3 (`APP_URL/storage/audio/{sha1}.mp3`, раздаёт nginx) или `null`, если у слова нет `audio_path`. TWA при `audio_url` проигрывает файл, при `null`/ошибке — Web Speech, затем `GET /tts`.

### A.5a Каталог курсов (docs/12 §1.7, §3.2)

Все три маршрута — под `TwaAuth` и `throttle:twa-api`. Название и описание отдаются на `learners.interface_locale`
с fallback на `ru` (пустое описание — `null`). Видимость: список показывает курсы `status = published` и
`visibility = public`; по slug открывается и выбирается любой `published` курс, кроме `private` (`unlisted` — «по ссылке»).
Всё остальное (draft / review / archived / private / неизвестный slug) отвечает **одинаково**: `404 course_not_found`
— существование не раскрывается.

**Прогресс** — по слову learner'а, не по группе: `done` = активные слова курса (`words.is_active = true`), у которых есть
строка `word_repetitions` этого learner'а с `repetitions >= 1` (то же правило, что `stats.words_learned`); `total` = все
активные слова. Неактивные слова не считаются ни в `done`, ни в `total`. Считается живым агрегатным запросом, не кэшируется.

#### `GET /api/twa/courses`
```json
{
  "courses": [
    {
      "slug": "english-a1", "title": "Английский A1", "description": "Первые 500 слов", "level": "A1", "exam_tag": "general",
      "words_count": 500, "is_featured": true, "is_active": true,
      "progress": { "done": 120, "total": 500 }
    }
  ]
}
```
Порядок — `position`, затем `id`. `is_active` — это `learners.active_course_id`. Активный курс learner'а остаётся в списке,
даже если он `unlisted` (выбран по ссылке), пока курс доступен. Список курсов без прогресса кэшируется на 10 минут
(по локали) и сбрасывается при публикации/архивации/правке курса и `CoursePublisher::recount`.

#### `GET /api/twa/courses/{slug}`
```json
{
  "slug": "english-a1", "title": "Английский A1", "description": null, "level": "A1", "exam_tag": "general",
  "words_count": 500, "is_active": true, "progress": { "done": 120, "total": 500 },
  "stages": [
    {
      "number": 1, "title": "Образование", "progress": { "done": 40, "total": 100 },
      "lessons": [ { "id": 11, "number": 1, "title": "Greetings", "progress": { "done": 20, "total": 25 } } ]
    },
    { "number": 2, "title": null, "progress": { "done": 0, "total": 80 }, "lessons": [] }
  ]
}
```
Модули и уроки — в порядке изучения `(stage.number, lesson.number)`; `lessons[].title` может быть `null`. **`stages[].title` — `null`,
если у модуля нет собственного названия** (docs/03 §2.5): клиент подписывает его по `number` на языке читателя («Модуль 2» /
«2-modul»; TWA — ключ `course.module`), а чип «Модуль N» рядом с названием показывает только у модуля с названием. Сервер
не подставляет подпись сам — ни «Stage 2», ни пустую строку. Для колоды learner'а `title` всегда локализованное «Моя колода» / «Mening lug'atim».

#### `POST /api/twa/me/course`
Request: `{ "slug": "english-a1" }` (обязателен, строка ≤ 64 → иначе 422). Ставит `learners.active_course_id`
(идемпотентно). Ответ — строка курса в формате элемента `GET /courses` (`is_active = true`, актуальный `progress`).
Недоступный курс — `404 course_not_found`, активный курс не меняется.

### A.9 `GET /api/twa/assignments` — задания центра (docs/13 §1.4, Фаза 2 / 2B.4)
Блок «Задания центра» на экране «Сегодня». Серверная сторона — `AssignmentService::forLearner` (общий лимит `twa-api`). Возвращает **активные** задания групп, где learner — активный участник (и организация активна), не больше `organizations.assignments.twa_limit` (20), ближайший срок первым; прогресс — **только самого learner'а**, пересчитывается в момент запроса (по его `word_repetitions`, без ожидания `assignments:refresh`). Чужие группы, закрытые задания, прогресс одногруппников не отдаются. Нет заданий — `{"assignments": []}`.
```json
{ "assignments": [
  { "id": 7, "title": "Food", "group": "Group A", "lesson_id": 41, "stage": 2, "lesson": 4,
    "due_at": "2026-10-25T13:00:00+00:00", "overdue": false,
    "progress_pct": 40, "completed": false, "completed_at": null, "late": false }
] }
```
`title` — название задания (по умолчанию название урока, иначе «Урок N»); `lesson_id` — то, что принимает `POST /training/sessions {mode: "lesson", lesson_id, source: "assignment"}` (`source = assignment` теперь допустим; тренировка идёт обычным путём, без отдельного режима); `overdue` — срок прошёл, а задание ещё открыто (опоздавшие выполнения учитываются до закрытия); `late` — выполнено после `due_at`. `progress_pct` = доля активных слов урока с `word_repetitions.repetitions >= 1`; `completed` — достигнут порог задания (`completion_threshold`).

**Ссылка из группы.** Объявление в чате — одно сообщение с `web_app`-кнопкой `/twa/?from=assignment&lesson=<id>`: TWA убирает метку из URL и открывает сессию урока (`source = assignment`); доступ к уроку перепроверяет сервер обычными правилами. Ошибка открытия остаётся на экране «Сегодня».

### A.10 Свои колоды learner'а (docs/13 §8.1, Фаза 2 / 2D.1)

Все маршруты — под `TwaAuth` и `throttle:twa-api`; изменяющие — ещё `throttle:twa-decks` (30/мин на learner'а), импорт слов —
`throttle:twa-deck-import` (10/мин на learner'а). Колода — курс с `owner_type = learner` (docs/03 §2.20); маршруты работают только с
**собственными** колодами: чужая, архивная и неизвестная колода отвечают одинаково — `404 deck_not_found`.
Выбор колоды активным курсом — существующий `POST /me/course {slug}` (A.5a): владелец может выбрать свою колоду, остальные получают
`404 course_not_found`. Активная колода попадает в `GET /courses` (как и любой доступный активный курс). Колода участвует в
`daily_review` и в квоте Free (20 новых слов/день) как обычный курс.

Лимиты — `config/decks.php`, по плану из `EntitlementResolver`: Free 1 колода × 50 слов, Pro 20 × 2000 (`limits.decks`, `limits.deck_words`).
Считаются неархивные колоды и **активные** слова колоды. Превышение — `422` с кодом-paywall (`deck_limit_reached` — колод,
`deck_words_limit_reached` — слов); импорт принимается целиком или не принимается вовсе. Клиент по этим кодам показывает `PaywallSheet`.

| Метод | Путь | Тело | Ответ |
|-------|------|------|-------|
| GET | `/decks` | — | `{ "decks": [Deck…], "limits": {decks, deck_words, used_decks} }` |
| POST | `/decks` | `{ "title": "string 1..60" }` | `201` `Deck` |
| GET | `/decks/{slug}` | — | `DeckDetail` (`Deck` + `words`) |
| PATCH | `/decks/{slug}` | `{ "title" }` | `Deck` |
| DELETE | `/decks/{slug}` | — | `204`, колода архивируется, прогресс сохраняется |
| POST | `/decks/{slug}/words` | `{ "text": "...", "format": "list"\|"csv" }` | `DeckImport` |
| DELETE | `/decks/{slug}/words/{word}` | — | `204`, слово деактивируется (`is_active = false`) |

```json
// Deck
{ "slug": "deck-7-k3x9", "title": "Мои слова", "words_count": 12, "is_active": false, "progress": { "done": 3, "total": 12 } }
// DeckDetail = Deck + words (только активные, по порядку добавления)
{ "...": "...", "words": [ { "id": 5, "word": "apple", "translation": "яблоко", "translation_uz": null, "example": null } ] }
// DeckImport
{ "added": 10, "reactivated": 0, "duplicates": 2, "rejected": [ { "line": 4, "reason": "junk" } ], "deck": { "...Deck..." } }
```

**Ввод слов** (`DeckImportParser`, ≤ 256 КБ и ≤ 500 непустых строк за запрос; всё, что дальше, — `rejected: over_limit`):
- `format = list` — строка на слово: `word — перевод`, `word - перевод` (дефис с пробелами), `word;перевод`, `word<TAB>перевод`, `word = перевод`; делится по первому разделителю.
  Перевод попадает в `words.translation` и в `word_translations` (ru; для learner'а с локалью uz — ещё и uz).
- `format = csv` — `word,translation_ru,translation_uz,example` (заголовок необязателен; разделитель `,` `;` или TAB определяется по первой строке; кавычки RFC 4180).
- Нормализация: NFC, `strip_tags`, управляющие символы удаляются, пробелы схлопываются; слово ≤ 100 символов, перевод ≤ 500, пример ≤ 300.
- Отсев (`rejected[].reason`): `empty_translation`, `junk` (слово без букв, недопустимые символы, ссылки), `too_long`, `duplicate` (повтор слова в тексте без учёта регистра), `over_limit`.
  Слово, которое уже есть в колоде и активно, считается `duplicates`; деактивированное — `reactivated` (перевод обновляется).
- Размер текста > 256 КБ — `422` валидации (`errors.text`); ни одной годной строки — `422 deck_import_empty`.

### A.6 Общие правила
- Все даты в ISO 8601 UTC.
- Rate limit: 60 запросов/минуту на learner'а (кроме `/tts` — 30/мин, `/promo/redeem` — A.7, `POST /billing/checkout` — A.8, `POST /training/sessions` — 10/мин, `/decks*` — A.10, `DELETE /me/organizations/{id}` — A.11, практика — A.16, баттл — A.17). `TwaAuth` выполняется **до** `throttle:*`
  (`bootstrap/app.php`, `prependToPriorityList`), поэтому ключ лимитера — `learner_id`, а не IP.
- При любой 4xx/5xx (кроме 422 валидации Laravel) ответ — `ApiErrorResource`:
```json
{ "error": { "code": "string", "message": "string" } }
```
- **`error.details`** (с практики, docs/16 §7.6) — необязательный объект с полями, которые нужны клиенту для экрана; он лежит **внутри** `error` и появляется только у кодов, у которых описан, и только когда не пуст:
```json
{ "error": { "code": "practice_session_active", "message": "There is an unfinished practice session.",
             "details": { "session_id": 77, "mode": "pairs", "course": "english-a1", "lesson_id": 41, "progress": { "done": 1, "total": 3 } } } }
```
  Остальные ошибки не меняются: ключа `details` в них нет (не пустой объект, а именно нет). Сообщение `message` — английское и не показывается ученику: TWA выбирает текст по `code` (и `details`). Контракт закреплён тестами
  `PracticeContractTest` и `BattleContractTest` (форма на проводе, ключи `details` каждого кода); в TWA — `ApiClientError.details`. Коды практики — A.16, баттла — A.17.

### A.7 `POST /api/twa/promo/redeem`
Активация промокода (docs/12 §5.5). Rate limit `twa-promo`: **5/мин на learner'а и 60/мин на IP** (429) — защита от подбора кодов.

**Request:** `{ "code": "welcome-30" }` — `required|string|max:64`; регистр, пробелы и дефисы не важны
(`PromoCodeService::normalize`). Нарушение валидации — 422 в стандартном формате Laravel (`message` + `errors.code`).

**Response 200:** тот же JSON, что у `GET /me` (A.2), с уже новым `entitlement`:
```json
{ "learner": {…}, "groups": […], "stats": {…},
  "entitlement": { "plan": "pro", "source": "subscription", "until": "2026-11-17T12:00:00+00:00", "new_words_per_day": null },
  "paywall": { "mode": "coming_soon", "contact": null } }
```
Нет живой личной подписки → создаётся подписка `provider=promo` на `duration_days`; есть живая личная с конечной датой →
продлевается на месте (`until = max(until, now) + duration_days`). Спонсорский Pro центра не мешает.

**Ошибки** (`ApiErrorResource`: `{"error": {"code", "message"}}`; клиент показывает свой текст по `code`, `message` — английский):

| HTTP | `error.code` | Когда |
|------|--------------|-------|
| 404 | `promo_not_found` | код неизвестен, пуст или деактивирован (не различаем намеренно) |
| 410 | `promo_expired` | прошёл `expires_at` |
| 409 | `promo_exhausted` | исчерпан `max_redemptions` |
| 409 | `promo_already_redeemed` | этот learner уже активировал этот код |
| 409 | `promo_not_applicable` | у learner'а бессрочная личная подписка — код ничего не добавит и не тратится |

Событие `promo.redeemed` пишется сервисом; запись в `audit_logs` не делается (действие самого learner'а). Каждый отказ (любой код ошибки выше) — событие `promo.rejected {reason, promo_code_id|null, code_length}` без самого кода; серия отказов одного learner'а — предупреждение в логе (docs/12 §8). Неизвестный ключ плана в данных — `plan_unknown` (422, `PlanException`).

### A.8 `/api/twa/billing/*` — покупка и автопродление (docs/13 §5, Фаза 2 / 2A.2)
Только для собственной подписки learner'а (центры платят через кабинет `/org`, docs/13 §6.5; их период `month`/`year` и цена года — `OrgTiers::price`, docs/13 «Реализация 2B.9», в этом API их нет). Серверная сторона — `LearnerBillingService` поверх `CheckoutService` / `SubscriptionService` (сумма и валюта выбираются
сервером по `config('billing.prices')` и открытому offer, клиент их не присылает). Ошибки — `ApiErrorResource`, клиент показывает свой текст по `error.code`.

| Метод | Путь | Что делает |
|---|---|---|
| GET | `/billing/catalog` | что можно купить сейчас |
| POST | `/billing/checkout` | создаёт заказ `pending` и платёжную ссылку; **throttle `twa-checkout`: 10/мин на learner'а и 120/мин на IP** (429) |
| GET | `/billing/orders/{token}` | статус заказа (только владельцу) — TWA опрашивает после оплаты |
| POST | `/billing/subscription/cancel` | отключает автопродление Stars (сначала у провайдера, вне транзакции), подписка → `canceled`, Pro до конца периода |
| POST | `/billing/subscription/resume` | включает обратно (`canceled` → `active`, пока период не закончился) |

**`GET /billing/catalog`** → `200`:
```json
{
  "items": [
    { "plan": "pro", "tier": null, "period": "month", "prices": { "XTR": 150, "UZS": 2900000 }, "list_prices": { "XTR": 150, "UZS": 2900000 } },
    { "plan": "pro", "tier": null, "period": "year",  "prices": { "XTR": 1300, "UZS": 24900000 }, "list_prices": { "XTR": 1300, "UZS": 24900000 } }
  ],
  "providers": ["telegram_stars", "payme", "click"],
  "offer": { "kind": "post_org_discount", "discount_pct": 30, "period": "month", "expires_at": "2026-11-02T12:00:00+00:00" },
  "subscription": { "status": "active", "provider": "telegram_stars", "plan": "pro", "ends_at": "2026-11-18T12:00:00+00:00", "auto_renew": true, "can_cancel": true, "can_resume": false, "perpetual": false }
}
```
Суммы — **минорные единицы**: `XTR` — целые Stars, `UZS` — тийины (делить на 100). `prices` — после скидки offer, `list_prices` — до. В `items` попадают только периоды, у которых есть цена хотя бы в одной валюте
включённого провайдера; `prices` содержит только такие валюты; `items: []` — продавать нечего (paywall остаётся `coming_soon`). `providers` — включённые (`billing.providers.<key>.enabled`) и обслуживающие learner'а.
`offer` — открытый offer (`null` — нет; `period` — период, на который он действует, `null` — любой; цены в `items` уже со скидкой для подходящих периодов, `list_prices` — прайс; offer создаётся при потере seated-места в центре, 2B.7, и применяется к заказу автоматически), `subscription` — собственная live-подписка (`null` — нет): `ends_at` — конец действия (ISO 8601, `null` — бессрочно), `auto_renew` — автопродление идёт,
`can_cancel` / `can_resume` — какие из кнопок «Отключить» / «Включить» показывать (`auto_renew` / `can_cancel` — только для подписки Stars `month`; разовый годовой счёт Stars их не даёт, 2A.8); `perpetual` — Pro без даты окончания (ручной грант): кнопки покупки скрыты, checkout отвечает `already_perpetual` (2A.8). (В docs/13 §5 массив назван `plans`; фактическое имя — `items`.)

**`POST /billing/checkout`** `{ "plan": "pro", "period": "month", "provider": "telegram_stars" }` — `plan` ∈ `Plan`, `period` ∈ `config('billing.periods')`, `provider` ∈ `telegram_stars|payme|click` (иное — `422` валидации Laravel). → `201`:
```json
{ "order": { "id": 17, "token": "q3…32 символа", "status": "pending", "amount": 150, "currency": "XTR", "expires_at": "2026-10-20T12:00:00+00:00" },
  "checkout": { "url": "https://t.me/$AbCd…", "kind": "invoice_link" } }
```
`checkout.kind`: `invoice_link` (Stars — открыть `Telegram.WebApp.openInvoice(url)`) | `redirect` (Payme/Click — `openLink(url)`). Заказ живёт `BILLING_ORDER_TTL_MINUTES` (24 ч); просроченные закрывает `billing:expire-orders` (каждые 15 мин).
Событие `checkout.started`. Если провайдер не смог выдать ссылку — заказ `failed`, ответ `502 provider_unavailable`.

**`GET /billing/orders/{token}`** → `200 { "status": "pending|paid|canceled|expired|failed", "paid_at": "…"|null, "reason": "replaced"|null, "replaced_by": "<token>"|null, "subscription": { …как в catalog.subscription… }|null }`;
`subscription` заполнена у `paid`. `reason = replaced` — заказ `canceled` самой логикой offer (скидка перенесена на более новый заказ или уже использована): это **не ошибка**, TWA показывает «Заказ заменён новым» и переходит к `replaced_by` — **токену** (не id) заказа-преемника, если он известен и принадлежит тому же learner'у, иначе `null`. У прочих заказов `reason` и `replaced_by` — `null`. Чужой, неизвестный или неправильный `token` — `404 order_not_found` (чужой заказ неотличим от несуществующего).

**`POST /billing/subscription/cancel|resume`** (без тела; общий лимит `twa-api`) → `200`, тот же JSON, что у `GET /me` (A.2), с новым `entitlement.renewal`.

| HTTP | `error.code` | Когда |
|---|---|---|
| 409 | `price_not_configured` | нет цены на (план, период, валюта) или Stars-подписка дороже потолка Telegram; план Free |
| 409 | `provider_disabled` | провайдер не подключён / выключен (`billing.providers.<key>.enabled`) |
| 409 | `checkout_conflict` | живая Stars-подписка с автопродлением, выбран другой провайдер (сначала `cancel`) |
| 409 | `offer_not_applicable` | скидка не подходит (клиент её не передаёт — сервер берёт лучший открытый offer сам) |
| 409 | `offer_busy` | заказ со скидкой сейчас оплачивается (у провайдера открыта транзакция) или параллельный checkout переносит ту же скидку; заказы не менялись, повторить через минуту (бот и TWA показывают «Предыдущая оплата со скидкой ещё обрабатывается — попробуйте через минуту») |
| 422 | `provider_not_supported` | провайдер не обслуживает этого плательщика/валюту |
| 422 | `invalid_period`, `invalid_tier` | период вне `billing.periods` (при валидном теле не встречается) |
| 429 | `too_many_pending` | у learner'а уже 3 неоплаченных заказа |
| 429 | (throttle) | `twa-checkout` |
| 409 | `not_cancelable` | нет live-подписки, подписка не `active` или её провайдер не продлевает (Payme/Click/выдача) |
| 409 | `not_resumable` | подписка не `canceled`, период закончился или провайдер не продлевает |
| 409 | `provider_capability`, `provider_not_registered` | у провайдера нет такой операции / адаптер не подключён |
| 502 | `provider_unavailable` | провайдер не ответил; в нашей БД ничего не изменено (при checkout — заказ `failed`) |
| 404 | `order_not_found` | `GET /billing/orders/{token}` |

### A.11 `/api/twa/me/organizations` — мои центры и «Покинуть центр» (docs/13 §6.2, Фаза 2 / 2B.2)
Серверная сторона — `LearnerOrganizationsService` поверх `OrganizationMembershipService` (docs/13 §2.3). Видны только **активные** членства самого learner'а; чужое или завершённое — 404. Ошибки — `ApiErrorResource`.

**`GET /me/organizations`** → `200` (общий лимит `twa-api`):
```json
{ "organizations": [
  { "id": 17, "name": "Bright Center", "seat_state": "seated", "joined_via": "invite", "joined_at": "2026-10-19T12:00:00+00:00", "can_leave": true }
] }
```
`id` — `organization_members.id` (идентификатор для `DELETE`); `seat_state` — `seated` (Pro от центра) / `waitlisted` (мест нет, обычный Free); `joined_via` — `invite` / `group` / `admin`; `can_leave` — `false` для `group` (членство вернётся при повторном входе в чат центра: клиент показывает пояснение «вы в центре через группу»). Сортировка — новые первыми.

**`DELETE /me/organizations/{id}`** (`id` — число; лимит `twa-organizations`: 10/мин на learner'а) → `200`, тот же JSON, что у `GET /me/organizations`, уже без этого центра. `OrganizationMembershipService::leave(status = 'left')`: место освобождается, самый ранний `waitlisted` получает его в той же транзакции.

| HTTP | `error.code` | Когда |
|---|---|---|
| 404 | `membership_not_found` | нет активного членства с таким `id` у этого learner'а (чужое, уже завершённое, несуществующее — не различаем) |
| 409 | `membership_via_group` | `joined_via = group`: покинуть можно только выходом из групп центра (`onGroupLeft`) или удалением администратором |
| 429 | (throttle) | `twa-organizations` |

События `org.member_left {status, was_seated, joined_via}` пишет сервис членств; отдельного аудита нет (действие самого learner'а).

### A.12 `GET /api/twa/referrals` — «Пригласить друга» (docs/13 §1.5, §7.1, Фаза 2 / 2C.1)
`ReferralService::overview` (docs/13 §2.4). Код learner'а генерируется при первом вызове (`codeFor`, условный UPDATE `WHERE referral_code IS NULL` — параллельные первые вызовы получают один код) и дальше не меняется. Общий лимит `twa-api`; `ReferralsResource`.

```json
{
  "code": "K7PM3Q2A",
  "link": "https://t.me/LexiFlowBot?start=ref_K7PM3Q2A",
  "reward_days": 7,
  "activation": { "days": 3, "reviews_per_day": 10, "window_days": 14 },
  "counts": { "invited": 3, "activated": 2, "rewarded": 1 },
  "referrals": [
    { "first_name": "Dilnoza", "status": "rewarded", "linked_at": "2026-10-20T10:00:00+00:00" }
  ]
}
```
`link` — `null`, пока `TELEGRAM_BOT_USERNAME` не задан (клиент показывает код и подсказку `/start ref_<code>`). `counts.invited` — все не-`rejected` рефералы, `activated` — `activated` + `rewarded`, `rewarded` — `rewarded`. `referrals` — до 50 последних не-`rejected` (новые первыми): **только** `first_name` приглашённого (может быть `null`), `status` (`pending` / `activated` / `rewarded`) и `linked_at`; никаких id, username, прогресса. TWA: экран `/referrals` («Пригласить друга» с «Сегодня»), кнопка «Поделиться» → `useTelegram().shareUrl` (`openTelegramLink('https://t.me/share/url?…')`), «Скопировать ссылку» → clipboard.

### A.13 `GET /api/twa/league` — лиги (docs/13 §1.5, §7.3, Фаза 2 / 2C.3)
`LeagueService::overview` (docs/13 §2.4). Только чтение: в группу learner попадает автоматически при первой оценке локального дня (после commit транзакции оценки), если не отключил лиги в настройках. Общий лимит `twa-api`; `LeagueResource`.

```json
{
  "enabled": true,
  "opted_out": false,
  "week": { "starts_on": "2026-10-19", "ends_on": "2026-10-25", "closes_at": "2026-10-25T19:00:00+00:00" },
  "rules": { "capacity": 30, "promote": 5, "demote": 5, "tiers": ["bronze", "silver", "gold", "platinum", "diamond"] },
  "membership": {
    "tier": 1, "tier_key": "silver", "rank": 3, "points": 41, "members_count": 28,
    "zone": "safe", "points_to_promote": 10, "points_to_safety": null,
    "standings": [
      { "rank": 1, "name": "Dilnoza", "points": 90, "is_me": false },
      { "rank": 3, "name": "Aziz", "points": 41, "is_me": true }
    ]
  },
  "last_week": { "starts_on": "2026-10-12", "tier": 0, "tier_key": "bronze", "result": "promoted", "rank": 2, "points": 120 }
}
```
`week` — текущая неделя в `LEAGUES_TIMEZONE` (`closes_at` — UTC-момент ближайшего понедельника 00:00). `membership` — `null`, пока learner не размещён на этой неделе (нет оценок, opt-out, лиги выключены). `zone`: `promotion` (топ-`promote`), `demotion` (низ-`demote`, только если tier > 0), иначе `safe`; `points_to_promote` — сколько очков не хватает до зоны повышения (`null` — уже там или высший tier), `points_to_safety` — сколько не хватает, чтобы выйти из зоны понижения (`null` — не в ней). `standings` — вся группа (≤ 30), по убыванию очков, ничьи — по более раннему размещению: **только** `name` (имя, может быть `null`), `points`, `rank`, `is_me`; никаких id, username, Telegram-данных. `last_week` — последняя закрытая неделя learner'а (или `null`). Очки считаются из `learner_daily_stats` (кэш 60 с на группу), поэтому отстают от оценок не больше чем на минуту.

`GET /today` += `league: {tier, tier_key, rank, points, members_count} | null` — строка «Лига» на «Сегодня». `PATCH /me/settings` += `leagues_opt_out: bool` (только JSON-boolean; `settings.leagues_opt_out` в ответе) — `true` = не размещать (текущее членство остаётся до конца недели; возврат действует с первой оценки следующего локального дня).

**Итоги недели** — ЛС `leagues.result.{promoted|stayed|demoted}` (`lang/*/leagues.php`) с web_app-кнопкой «Открыть лигу» → `/twa/league`; очередь `SendLeagueResultsJob` — пачка до `LEAGUES_NOTIFY_BATCH_SIZE` участников, слот глобального лимита исходящих B.7/2R.3 на каждое сообщение (пропускаемые участники — opt-out, закрытый ЛС, уже отправлено — слот не тратят), остаток после `LEAGUES_NOTIFY_BATCH_SECONDS` или без слота уходит новым job'ом (`tries = 0`, `maxExceptions = 3`, `retryUntil` — единый дедлайн рассылки: закрытие недели + `LEAGUES_NOTIFY_TTL_HOURS`, у остатка он тот же), не больше одного сообщения на learner'а и закрытую неделю (`notifications_log.type = league_result` пишется до вызова Telegram); 403 → `dm_blocked_at`.

### A.14 Друзья и вызовы (docs/13 §1.5, §7.5, Фаза 2 / 2C.5)
`FriendshipService` / `ChallengeService`. Чужой id (пара или вызов, в которых вы не участник) неотличим от несуществующего: **404** `friendship_not_found` / `challenge_not_found`. Запросы дружбы создаются не здесь, а ссылкой `f_<code>` в боте (B.3). Записи — `throttle:twa-friends` (30/мин на learner'а).

**`GET /api/twa/friends`** (и ответ `POST /friends/{id}/accept|decline|remove` — обновлённый список; `id` = `friendships.id`):
```json
{
  "invite_link": "https://t.me/LexiFlowBot?start=f_K7PM3Q2A",
  "limit": 100,
  "friends":  [{ "id": 11, "first_name": "Dilnoza", "streak": 5, "words_learned": 120, "since": "2026-10-20T10:00:00+00:00" }],
  "incoming": [{ "id": 21, "first_name": "Bek", "requested_at": "2026-10-22T10:00:00+00:00" }],
  "outgoing": [{ "id": 31, "first_name": "Sam", "requested_at": "2026-10-22T11:00:00+00:00" }]
}
```
`invite_link` — `null`, пока не задан `TELEGRAM_BOT_USERNAME`. О другом человеке уходят только имя, `streak` (хранимая серия друга; 0, если последний удержанный день старше «вчера» по поясу друга — без записи в чужой профиль) и `words_learned` (слова с `repetitions ≥ 1`; только у принятых друзей и **до 10 минут давности** — кэш `stats:words_learned:{id}`, в БД считаются лишь друзья без записи в кэше, одним запросом); ни id, ни username, ни пояс.
- `POST /friends/{id}/accept` — принять входящий (идемпотентно; лимит `friends.max` проверяется у обеих сторон: **409** `friend_limit` / `friend_limit_other`; приглашавший сам принять не может: **403** `not_recipient`); `POST …/decline` — отклонить входящий (строка удаляется); `POST …/remove` — удалить из друзей / отозвать свой запрос / снять свою блокировку (открытые вызовы пары → `canceled`).

**`GET /api/twa/challenges`** → `{ "days": 7, "open": [Challenge], "finished": [Challenge] }` (`open` — `pending`/`active`, `finished` — последние 20). `Challenge` (то же — ответ остальных вызовов):
```json
{ "id": 5, "status": "active", "metric": "reviews", "days": 7, "role": "challenger", "friend_name": "Dilnoza", "friendship_id": 11,
  "starts_on": "2026-10-23", "ends_on": "2026-10-29", "created_at": "2026-10-22T10:00:00+00:00",
  "my_score": 14, "their_score": 9, "result": null }
```
`role` — `challenger`/`opponent`; `my_score`/`their_score` — живые у `active` (один запрос к `learner_daily_stats` на весь список: строки обеих сторон за `min(starts_on)..max(ends_on)`, суммы по периоду каждого вызова считаются в PHP), итоговые у `finished`, иначе `null`; `result` — `won`/`lost`/`draw` у `finished`; `friendship_id` — `null`, если дружбы уже нет.
- `POST /api/twa/challenges` `{friendship_id, metric: reviews|new_words}` → **201** `Challenge` (`pending`); друг адресуется id дружбы, не learner'а. Ошибки: **403** `not_friends`, **409** `challenge_exists` (один открытый вызов на пару), **422** (валидация), **404** чужая дружба.
- `POST /challenges/{id}/accept` — принять (только адресат: **403** `not_opponent`; `active` повторно — без изменений; закрытый/просроченный — **409** `challenge_closed`); `POST /challenges/{id}/decline` — отклонить.

### A.15 Достижения (docs/13 §1.5, §7.4, Фаза 2 / 2C.4)
`AchievementService::overview` / `markShared`; ответы — `AchievementsResource`. Общий лимит `twa-api`.

**`GET /api/twa/achievements`** — весь каталог в порядке показа (и ответ `POST …/shared` — обновлённый список):
```json
{
  "unlocked_count": 2, "total": 9,
  "share_link": "https://t.me/LexiFlowBot?start=ref_K7PM3Q2A",
  "items": [
    { "key": "first_session", "title": "Первый шаг", "description": "Первая оценённая карточка",
      "unlocked_at": "2026-10-21T05:00:00+00:00", "shared_at": null, "progress": null,
      "share_text": "Я получил(а) достижение «Первый шаг» в LexiFlow! Учим английский в Telegram." },
    { "key": "words_100", "title": "100 слов", "description": "Выучено 100 слов",
      "unlocked_at": null, "shared_at": null, "progress": { "current": 40, "target": 100 }, "share_text": null }
  ]
}
```
`title` / `description` / `share_text` — на языке запроса (`lang/{ru,uz}/achievements.php`). `progress` — только у счётных ключей (`streak_*` по лучшей серии, `words_*` по словам с `repetitions ≥ 1`), `current` не больше `target`; у разовых — `null`. `share_text` — только у полученных. `share_link` — `t.me/<bot>?start=ref_<code>` (тот же код, что у рефералки); `null`, пока не задан `TELEGRAM_BOT_USERNAME` — клиент прячет кнопку «Поделиться».
- `POST /api/twa/achievements/{key}/shared` — learner открыл диалог «Поделиться» (`useTelegram().shareUrl`): ставит `shared_at` один раз (+ событие `achievement.shared`), повтор — без изменений. **404** `achievement_not_found` — ключ неизвестен, не получен или чужой.

### A.16 Практика — четыре режима (docs/16 §7, §10; задачи 18.1–18.5)
Самостоятельная практика закрепляет **уже введённые** ученику слова: «Найди пары» (`pairs`), «Подбери сочетание» (`collocation`), «Собери предложение» (`sentence`), «Слушай и пиши» (`dictation`). Она **не пишет**
`word_repetitions`, `training_*`, цель дня, серию, лиги, задания центра и достижения (docs/16 §0, `PracticeIsolationTest`). Контроллеры `PracticeHubController`, `PracticeSessionController`, `PracticeAudioController` тонкие
(валидация → сервис → Resource): `PracticeHubService`, `PracticeSessionService`, `PracticeAudioService`; ответы — `PracticeHubResource`, `PracticeSessionResource`, `PracticeAnswerResource`; ошибки — `ApiErrorResource` (A.6, с `details`).
Формы закреплены `PracticeContractTest` (`assertExactJsonStructure`, запретные ключи снимка на любой глубине). Даты — ISO 8601 UTC **с миллисекундами** (`2026-10-30T10:00:05.321Z`); `server_now` — в каждом ответе сессии.

**Доступ** проверяется на каждом эндпоинте раньше всего (`PracticeAccess::state`): `PRACTICE_ACCESS=off` или `pilot` без ученика в пилоте → **403** `practice_unavailable` (один код: наличие пилота не раскрываем); `PRACTICE_PLAN=pro` и ученик не на Pro → **403** `practice_pro_required`
(TWA открывает пейвол `practice`). Выключатель режима `PRACTICE_MODE_*` проверяется **только при создании** сессии (**409** `practice_mode_disabled`) и показывается в хабе как `disabled`: открытые сессии доигрываются.
Чужая сессия неотличима от несуществующей: **404** `practice_session_not_found`.

**Лимиты** (ключ — `learner_id`, `TwaAuth` раньше `throttle`): все маршруты, кроме аудио, под `twa-api` (60/мин); дополнительно `POST /practice/sessions` — `twa-practice-open` **10/мин**; `…/answer` и `…/replace` — `twa-practice-answer` **90/мин**
(быстрый ученик сопоставляет ~40 пар в минуту); аудио — `twa-practice-audio` **60/мин** и **вне** `twa-api` (клипы грузятся пачкой и не должны съедать общий бюджет). 429 — `rate_limited` (A.6).

#### `GET /api/twa/practice/hub?course={slug}[&lesson_id={id}]`
Хаб курса или урока: четыре режима с состоянием, незавершённая сессия, сводка баттла. Бюджет — 9 запросов к БД (`PracticeQueryBudgetTest`).
```json
{ "course": { "slug": "english-a1", "title": "Английский A1" },
  "lesson": { "id": 41, "stage": 2, "number": 4, "title": "Еда" },
  "modes": [
    { "mode": "pairs",       "state": "available",  "available": 14, "required": 3, "reason": null,       "estimate_seconds": 120 },
    { "mode": "collocation", "state": "not_enough", "available": 1,  "required": 3, "reason": "no_items", "estimate_seconds": 90 },
    { "mode": "sentence",    "state": "disabled",   "available": 0,  "required": 3, "reason": null,       "estimate_seconds": 150 },
    { "mode": "dictation",   "state": "available",  "available": 7,  "required": 3, "reason": null,       "estimate_seconds": 180 }
  ],
  "active_session": { "id": 77, "mode": "pairs", "course": "english-a1", "lesson_id": 41, "progress": { "done": 1, "total": 3 } },
  "battle": { "enabled": true, "active_battle_id": null, "incoming_count": 1 } }
```
- `lesson` — `null` без `lesson_id` (хаб курса); `stage` и `number` — номера модуля и урока, `title` — название (у безымянного урока `null`; у колоды ученика — по `DeckTitles`). Тексты на языке ученика.
- `modes[]` всегда четыре, в порядке `pairs`, `collocation`, `sentence`, `dictation`. `state`: `available` (можно играть), `not_enough` (материала мало: числа `available` / `required` и `reason`), `disabled` (режим выключен
  `PRACTICE_MODE_*`), `pro_required` (Free при `PRACTICE_PLAN=pro`: числа не считаются). У `disabled` и `pro_required` `available = 0`, `reason = null`. `required` — минимум: у пар 3 слова в раунде, у остальных 3 задания (`practice.solo.items.min`).
  `reason`: `no_words` (введённых слов меньше минимума), `ambiguous` (слов хватает, но после исключения неоднозначных переводов — нет), `no_items` (опубликованных заданий мало или их целевые слова не введены), `audio_pending` (заданий хватает, но озвучка не готова),
  `no_audio_words` (диктант слов: нет слов с готовой озвучкой). `estimate_seconds` — «≈ N мин» на карточке (120 / 90 / 150 / 180).
- `active_session` — единственная незавершённая сессия ученика **в любом курсе** (`null`, если нет; просроченная закрывается и не возвращается): карточка «Продолжить» и лист «Незавершённая практика».
- `battle` — сводка баттла: `enabled` (`PRACTICE_BATTLE`), `active_battle_id` (свой матч, который не закончен), `incoming_count` (приглашения мне, срок которых не истёк). Один запрос к `battles`; API баттла — A.17.
- Ошибки: **403** `practice_unavailable`; **404** `course_not_found` (курс не опубликован, приватный чужой или нет такого), `lesson_not_found`; **422** `lesson_not_in_course`, валидация (`course` обязателен, ≤ 64 символов).

#### `POST /api/twa/practice/sessions` — открыть сольную сессию (лимитер `twa-practice-open`)
```json
{ "mode": "pairs", "course": "english-a1", "lesson_id": 41, "source": "hub", "replace_active": false, "retry_of": null }
```
`mode` — один из четырёх; `course` — slug (≤ 64); `lesson_id` — целое ≥ 1 или `null` (практика курса); `source` — `hub` (по умолчанию) | `after_training` (хаб открыт с итога занятия: `?from=finished`) | `retry` | `deeplink` — откуда открыта, для аналитики;
`replace_active` — закрыть незавершённую сессию (`finish_reason = replaced`); `retry_of` — id **завершённой** своей сессии: «Повторить ошибки» (режим, курс и урок берутся из неё, присланные игнорируются, `source = retry`).
Ответ **201** — сессия (форма ниже, как `GET`); в `current` — первый элемент. Что в сессии: пары — до 3 раундов по 4 слова (минимум один раунд из 3); сочетание, предложение — 5 заданий (минимум 3); диктант — 5 заданий (минимум 3): до 3 фраз, остальное — слова с готовой озвучкой (слов не хватает — добираются фразами), сначала слова, потом фразы.
Раскладка и ожидаемые ответы создаются на сервере один раз и хранятся в снимке (`practice_sessions.snapshot`, docs/03 §2.43): перезагрузка отдаёт ту же раскладку, правка контента на идущую сессию не влияет.
- Одна активная сессия на ученика (unique `active_learner_id`): вторая открывается только с `replace_active` — иначе **409** `practice_session_active` (`details` — незавершённая сессия: `{session_id, mode, course, lesson_id, progress}`).
  Две вкладки, открывшие одну и ту же практику одновременно, получают **одну** сессию (проигравший гонку читает победителя); другую практику — 409.
- Ошибки: **403** `practice_unavailable` / `practice_pro_required`; **409** `practice_mode_disabled` (`{mode}`), `practice_session_active`, `retry_not_available` (в исходной сессии нечего повторять или материала для повтора мало); **404** `course_not_found`, `lesson_not_found`,
  `practice_session_not_found` (чужая или несуществующая `retry_of`); **422** `lesson_not_in_course`, `practice_not_enough_material`, валидация.
- **422 `practice_not_enough_material`** — `details`: `{ "mode": "pairs", "available": 2, "required": 3, "reason": "no_words", "course": "english-a1", "lesson_id": 41 }` (те же `reason`, что в хабе). TWA по нему объясняет причину и предлагает «Учить урок».
- Побочный эффект: событие `practice.session_started` `{session_id, mode, course_id, lesson_id, source, total, retry}`; отказ 422 — `practice.not_enough_material` (кроме повтора ошибок).

#### `GET /api/twa/practice/sessions/{id}` — сессия
```json
{ "id": 77, "status": "active", "mode": "sentence", "source": "hub", "retry_of": null,
  "course": { "slug": "english-a1", "title": "Английский A1" },
  "lesson": { "id": 41, "stage": 2, "number": 4, "title": "Еда" },
  "progress": { "done": 2, "total": 5 }, "counters": { "correct_first": 1, "hints": 0, "mismatches": 0 },
  "expires_at": "2026-10-31T10:00:00.000Z", "server_now": "2026-10-30T10:00:05.321Z",
  "current": { "id": "eK7qM2x9Ab", "kind": "sentence", "prompt": "Моя мама — учитель.",
               "fragments": [ { "id": "fQ1xT8aLmN", "text": "teacher" }, { "id": "fW2pB4cDeR", "text": "My" } ] },
  "summary": null }
```
`status` — `active` | `finished`; `lesson` — `null` у практики курса; `progress.done` — завершённые элементы (у пар — раунды); `counters` — для шапки; `expires_at` — `started_at + 24 ч` (`practice.solo.session_ttl_hours`). Восстановление экрана — этим же запросом: раскладка та же.
`current` — **белый список** (`PracticeModeHandler::project` + повторная очистка Resource'ом от ключей снимка); id элементов, фрагментов, вариантов и карточек пар — случайные 10-символьные (`e…`, `f…`, `o…`, `l…`, `r…`), порядок — перестановка от случайного seed'а, который не хранится. Формы `current` по `kind`:
- `pairs_round` — `{ id, kind, round, rounds, left: [{id, text}], right: [{id, text}], matched: [[leftId, rightId], …], mismatches }`; `right.text` — перевод на языке ученика (при отсутствии — русский); правильные соответствия **не** отдаются;
- `collocation` — `{ id, kind, text: "Let's ___ a meeting on Monday.", options: [{id, text} ×3] }`;
- `sentence` — как в примере выше (`prompt` — смысл на языке ученика, `fragments` — перемешанные);
- `dictation` — `{ id, kind, source: "phrase" | "word", audio_url: "/api/twa/practice/sessions/77/audio/eD4rS6yUiO", words: 3, replacements_left: 2 }` — **текста нет**; `words` — число слов (для пустых плашек).
Завершённая сессия: `current: null`, `summary` (ниже). `GET` завершённой — **200** с итогами (не 410: итоги читаемы всегда); просроченная активная закрывается при обращении (`finish_reason = expired`) и тоже отвечает 200.
Ошибки: **404** `practice_session_not_found`; **403** по доступу.

`summary` (итог, `SummaryView`):
```json
{ "total": 5, "answered": 5, "correct_first": 3, "typos": 1, "hints": 1, "mismatches": null,
  "duration_ms": 93000, "finish_reason": "completed", "retry_available": true,
  "mistakes": [ { "element_id": "eD4rS6yUiO", "kind": "dictation", "label": "See you tomorrow", "your_answer": "see you tomorow", "verdict": "typo" } ] }
```
`correct_first` — верно с первой попытки (`typo` не считается), `hints` — «Не знаю». У пар `correct_first` — слова, сопоставленные верно с первой попытки, `mismatches` — число неверных сопоставлений (у остальных режимов `null`), `mistakes[]` — слова, в которых была ошибка
(`label: "mother — мама"`, `your_answer: null`, `verdict: "wrong"`). `finish_reason` — `completed` | `expired` | `replaced`; `retry_available` — можно «Повторить ошибки» (есть ошибки и материала хватает); `duration_ms` — серверное `finished_at − started_at`.

#### `POST /api/twa/practice/sessions/{id}/answer` — ответ (лимитеры `twa-api` + `twa-practice-answer`)
```json
{ "request_id": "9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d", "element_id": "eK7qM2x9Ab", "time_spent_ms": 8200,
  "answer": { "order": ["fW2pB4cDeR", "fQ1xT8aLmN"] } }
```
`request_id` — **uuid**, обязателен: ключ повтора. `element_id` — id текущего элемента (≤ 16). `time_spent_ms` — 0…600000, телеметрия, на вердикт не влияет. `answer` по режиму:
пары — `{left_id, right_id}` (одно сопоставление; проверяется сразу); сочетание — `{option_id}` или `{skip: true}`; предложение — `{order: [id всех фрагментов]}` (≤ 8) или `{skip: true}`; диктант — `{text}` (≤ 200 символов; пустой текст = «Не знаю») или `{skip: true}`.
Тело запроса ≤ 4 КБ. Ответ **200**:
```json
{ "verdict": "typo", "first_attempt": true,
  "reveal": { "expected": "See you tomorrow", "your_text": "see you tomorow",
              "diff": [ { "expected": "see", "got": "see", "ok": true }, { "expected": "tomorrow", "got": "tomorow", "ok": false } ],
              "translation": "До завтра", "explanation": null },
  "pair": null,
  "session": { "...": "сессия, как GET: следующий элемент в current или summary" } }
```
- `verdict` — `correct` | `typo` (только диктант: «Есть опечатка», в `correct_first` не засчитывается) | `wrong` | `skipped`. `first_attempt` — первая ли это попытка по элементу.
- `reveal` — разбор **после** ответа, по виду элемента: `collocation` — `{correct_option_ids, full_text, translation, explanation}`; `sentence` — `{correct_text, alternatives: [...], your_text, explanation}`; `dictation` — `{expected, your_text, diff, translation, explanation}`
  (`diff` — пословное сравнение при равном числе слов, иначе `null`; при «Не знаю» `your_text = null`). У пар `reveal` всегда `null`, правильная пара при ошибке **не** раскрывается.
- Пары: `pair: { left_id, right_id }` — какое сопоставление проверено, `verdict` — `correct` | `wrong` (первая ошибка по слову фиксируется, даже если потом оно найдено), `round_done: bool` — раунд завершён (**ключ есть только у пар**).
- **Повтор того же `request_id`** возвращает прежние `verdict`/`reveal` (из журнала `practice_attempts`) и **текущее** состояние сессии — даже если она уже закончилась (потерянный ответ на последний элемент не превращается в 410).
- Порядок проверок под блокировкой сессии: повтор → закрыта (410) → неизвестный элемент (422) → элемент уже отвечен (409) → не текущий (409) → форма ответа (422). Ошибки:
  **410** `practice_session_closed` (`{finish_reason}`; просроченная сессия закрывается этим запросом); **409** `element_not_current` (`{current}` — id элемента, который отвечать сейчас; TWA перечитывает сессию), `answer_already_accepted` (`{verdict}`), `pair_already_matched` (`{left_id}`; также для уже занятой правой карточки);
  **422** `invalid_answer_shape` (`{expected}`: `order_of_all_fragments` | `option_id` | `left_right` | `text_or_skip` | `bijection`), `unknown_element_id` (неизвестный элемент, фрагмент, вариант или карточка), валидация; **404**.
- Побочный эффект при завершении сессии: событие `practice.session_finished` `{session_id, mode, total, answered, correct_first, hints, mismatches, reason, duration_ms}` (после commit, один раз).

#### `POST /api/twa/practice/sessions/{id}/replace` — замена диктанта без штрафа (лимитер `twa-practice-answer`)
`{ "request_id": "…uuid…", "element_id": "eD4rS6yUiO", "reason": "audio_unavailable" }` → **200** сессия (как `GET`): диктант, чья запись не загрузилась, заменён следующим кандидатом из пула снимка (фраза — фразой, иначе словом); если запасных нет, элемент
убирается и `progress.total` уменьшается (минимум 1). Заменённый элемент не входит в `total` и `answered`. Не больше `practice.solo.dictation.max_replacements` (2) на сессию. Ошибки: **409** `replace_not_allowed` (`{reason: not_dictation | not_current | limit}`), **410**, **404**.
Повтор `request_id` второй раз не заменяет: ответ — текущая сессия. Событие `practice.audio_failed` `{session_id, source, reason, verified}` (после commit).

#### `GET /api/twa/practice/sessions/{id}/audio/{element}` — запись диктанта (лимитер `twa-practice-audio`, вне `twa-api`)
Тело — `audio/mpeg`, заголовки `Cache-Control: private, no-store`, `Content-Length`, `X-Content-Type-Options: nosniff`. Файл читается с диска элемента снимка (`practice_audio` — фразы; `public` — предгенерированные слова, `audio:pregenerate`) по **непрозрачному id элемента**;
ни пути, ни текста в API нет. TWA грузит клип запросом с `Authorization` в `blob:` и играет по нажатию (Web Speech для диктанта запрещён: нужен текст на клиенте). Доступ — только владельцу; завершённая сессия отдаёт записи до `expires_at` (повторное прослушивание в разборе).
Ошибки: **404** `practice_session_not_found` — чужая сессия, неизвестный элемент или элемент без записи (неотличимо), **503** `practice_audio_unavailable` — файла нет (TWA предлагает «Повторить загрузку» и «Заменить задание»; событие `practice.audio_failed`).

#### Коды ошибок практики (сводно)
| Код | HTTP | `details` |
|---|---|---|
| `practice_unavailable` | 403 | — |
| `practice_pro_required` | 403 | — (пейвол `practice`) |
| `practice_mode_disabled` | 409 | `{mode}` |
| `practice_not_enough_material` | 422 | `{mode, available, required, reason, course, lesson_id}` |
| `practice_session_active` | 409 | `{session_id, mode, course, lesson_id, progress}` |
| `practice_session_not_found` | 404 | — |
| `practice_session_closed` | 410 | `{finish_reason}` |
| `element_not_current` | 409 | `{current}` |
| `answer_already_accepted` | 409 | `{verdict}` (в баттле ещё `points`) |
| `pair_already_matched` | 409 | `{left_id}` |
| `invalid_answer_shape` | 422 | `{expected}` |
| `unknown_element_id` | 422 | — |
| `replace_not_allowed` | 409 | `{reason}` |
| `retry_not_available` | 409 | — |
| `practice_audio_unavailable` | 503 | — |
| `course_not_found` / `lesson_not_found` | 404 | — |
| `lesson_not_in_course` | 422 | — |

TWA: каждый код — в `KNOWN_ERROR_CODES` и `error.code.<код>` (ru/uz); клиент разбирает `details` у `practice_session_active`, `element_not_current`, `answer_already_accepted`.

### A.17 Баттл 1 на 1 — API (docs/16 §7.3–§7.4, §8; задачи 18.6, 18.6b)
Матч двух учеников, которые дружат: один формат из четырёх (`pairs`, `collocation`, `sentence`, `dictation`), 5 одинаковых раундов, очки только баттла (на обучение, серию, лиги, задания центра и достижения они не влияют — `PracticeIsolationTest`, A.16).
`BattleController` тонкий: FormRequest → `BattleService` (действия) или `BattleReader` (чтения) → Resource; аудио отдаёт `PracticeAudioController::battle`; ошибки — `ApiErrorResource` с `details` внутри `error` (A.6). Формы закреплены
`BattleContractTest` (`assertExactJsonStructure` по каждому статусу, запретные ключи снимка на любой глубине, `poll` ≤ 600 байт); доступ — `BattleHttpRulesTest`, лимитеры — `BattleRateLimitTest`, аудио — `BattleAudioTest`, матч двух игроков по HTTP целиком — `BattleHttpMatchTest`.
Правила матча, часы и присутствие — docs/16 §8; таблицы — docs/03 §2.45–§2.47; экраны TWA — docs/05 §5a.5; ЛС-приглашение — B.14.

**Общие правила**
- Доступ к практике проверяется раньше всего (A.16): `PRACTICE_ACCESS=off` или пилот без ученика → **403** `practice_unavailable`; `PRACTICE_PLAN=pro` и ученик не на Pro → **403** `practice_pro_required`. Выключатель `PRACTICE_BATTLE=false` проверяют только создание
  (`POST /battles`, `options`) и принятие (`accept`) → **409** `battle_disabled`; матч, который идёт, доигрывается. `PRACTICE_ACCESS=off` аннулирует и идущие матчи — дворник `practice:battles-sweep` (`closed / voided`) не позже чем через минуту.
- Матч видят только его участники. Чужой и несуществующий id неотличимы: **404** `battle_not_found` (тело одно и то же; запрос чужого не пишет даже присутствие). `{id}` — целое до 18 цифр, иначе это 404 роутера (не 500).
- Даты — ISO 8601 UTC с миллисекундами (`2026-10-30T10:00:05.321Z`). Часы матча — только серверные; `server_now` есть в каждом ответе матча, раунда и опроса. Клиент считает смещение `server_now − Date.now()` и тикает от него; клиентское время на результат не влияет.
- Тексты (название курса, задание, разбор) — на языке **смотрящего**: у каждого игрока своя сторона снимка (`battle_participants.locale`, язык при приглашении). О сопернике известно только имя `opponent.first_name` (может быть `null`).
- Ошибки — конверт A.6 `{"error": {"code", "message"[, "details"]}}`, коды — таблица ниже. Ошибки валидации Laravel (422 `{message, errors}`) и 429 лимитера (`Too Many Attempts.` + `Retry-After`) идут вне конверта; TWA различает их по статусу.
- Чтение матча не «чистое»: `GET /battles/{id}`, `…/poll` и `…/round` каждый раз пишут присутствие игрока (условный UPDATE, не чаще раза в 5 с) и, если назрел переход по времени, выполняют его под блокировкой строки — кто первый посмотрел, тот и двигает матч. `GET /battles` присутствие не пишет,
  но тоже доводит собственный матч до «сейчас» (просроченное приглашение закрывается). Опрос, пока ничего не назрело, блокировок не берёт и укладывается в 4 запроса к БД (бюджеты — docs/16 §13).

**Маршруты и лимитеры** (ключ лимитера — `learner_id`; `TwaAuth` стоит раньше `throttle`)

| Метод и путь | Лимитеры | Ответ |
|---|---|---|
| `GET /practice/battles/options?friendship_id=&course=[&lesson_id=]` | `twa-api` 60/мин | `BattleOptionsResource` |
| `GET /practice/battles` | `twa-api` | `BattleListResource` |
| `POST /practice/battles` | `twa-api` + `twa-practice-open` 10/мин + `twa-battle-invite` **5/мин** | **201** `BattleResource` |
| `GET /practice/battles/{id}` | `twa-api` | `BattleResource` |
| `GET /practice/battles/{id}/round?no=` | `twa-api` | `BattleRoundResource` |
| `GET /practice/battles/{id}/poll` | только `twa-battle-poll` **90/мин** (вне `twa-api`) | `BattlePollResource` |
| `POST /practice/battles/{id}/accept` · `decline` · `cancel` · `ready` · `surrender` | `twa-api` + `twa-battle-action` **60/мин** | `BattleResource` |
| `POST /practice/battles/{id}/answer` | то же | `BattleAnswerResource` |
| `POST /practice/battles/{id}/audio-failed` | то же | `BattleAudioFailedResource` |
| `GET /practice/battles/{id}/audio/{element}` | только `twa-practice-audio` **60/мин** (вне `twa-api`) | `audio/mpeg` |

- `POST /battles` считает и отказанные попытки (409, 422): каждое приглашение — снимок, строки и ЛС другу. `twa-practice-open` общий с соло (A.16).
- Опрос раз в 2 с — 30 запросов в минуту (две вкладки — 60). Внутри `twa-api` (60) он съел бы бюджет раундов и ответов, поэтому у него свой бакет; это не «без лимита»: цикл в клиенте или скрипт упрутся в 90/мин, а не в БД.
  `BattleRateLimitTest`: 30 опросов и 60 других запросов за минуту проходят, 61-й другой запрос — 429, а опрос идёт дальше (и наоборот: 91-й опрос — 429, остальной API жив). `twa-battle-action` — один бакет на все семь действий.
- Аудио — пять клипов подряд при входе в комнату диктанта: свой бакет, чтобы предзагрузка не тратила общий бюджет.

**Статусы матча** (полная машина состояний — docs/16 §8.1; переходы по времени происходят лениво при любом обращении участника и по минутному дворнику)

| `status` | Как наступает | Что можно сделать | Дальше |
|---|---|---|---|
| `invited` | `POST /battles`; живёт до `invite_expires_at` (+300 с) | адресат: `accept`, `decline`; инициатор: `cancel` | `room`; `closed`: `declined`, `canceled`, `invite_expired` |
| `room` | `accept`; живёт до `room_expires_at` (+120 с) | оба: `ready`; любой: `cancel`; диктант: загрузить `audio_preload` | `countdown` (оба готовы); `closed`: `canceled` (выход, либо участник молчит > 30 с — `meta.left`), `room_expired`, `voided` (нет записи) |
| `countdown` | второй `ready`; `starts_at` = +3 с, раунд 1 уже с дедлайном `starts_at + round_seconds` | `cancel`, `surrender` | `playing` в `starts_at` |
| `playing` | раунд: `phase = answering` (до дедлайна + грейс либо пока не ответят оба) → `reveal` (4 с) → следующий раунд | `answer`, `surrender` | после `reveal` 5-го раунда `finished/completed`; `finished`: `surrender`, `absent`; `closed`: `both_absent`, `voided` |
| `finished` | есть результат: победа, поражение, ничья | реванш (`POST /battles {rematch_of}`) | — |
| `closed` | матч не состоялся или прерван без победителя | реванш | — |

Терминальные статусы (`finished`, `closed`) не меняются; любое действие над ними — **409** `battle_closed` (читать можно всегда, `GET` — 200). `cancel` приглашения — только инициатор; в `room` и `countdown` — любой участник; в `playing` выйти можно только `surrender`
(`cancel` → 409 `battle_wrong_state`). Сдача — поражение сдавшегося, победа соперника, очки остаются как есть (даже если сдавшийся вёл). Тихий выход (тишина дольше `presence_grace_seconds`) в `room`/`countdown` отменяет матч, в `playing` — техническое поражение молчащего.

#### `BattleResource` — один вид матча для всех статусов
Отдают `GET /battles/{id}`, 201 приглашения, ответ каждого действия (`accept`, `decline`, `cancel`, `ready`, `surrender`) и поле `battle` ответа `audio-failed`. Все ключи есть всегда, отсутствующее — `null`; порядок ключей как в таблице.

| Ключ | Тип | Смысл |
|---|---|---|
| `id`, `status`, `mode` | int, string, string | `status` — таблица выше; `mode` — `pairs` · `collocation` · `sentence` · `dictation` |
| `end_reason` | string \| null | только у терминального: `completed`, `surrender`, `absent` (у `finished`); `declined`, `invite_expired`, `room_expired`, `canceled`, `both_absent`, `voided` (у `closed`) |
| `role` | string | моя сторона: `challenger` (пригласил) · `opponent` (приглашён) |
| `course` | `{slug, title}` | название на моём языке |
| `lesson` | `{id, stage, number, title}` \| null | `null` — матч на весь курс; `stage`/`number` — номера модуля и урока, `title` может быть `null` |
| `opponent` | `{first_name}` | соперник |
| `rounds_total`, `round_seconds`, `countdown_seconds`, `reveal_seconds` | int | 5; время раунда по формату (`collocation` 30 · `pairs` 45 · `sentence` 45 · `dictation` 60 с); 3 с; 4 с |
| `presence_grace_seconds`, `presence_warn_seconds`, `deadline_grace_ms` | int | 30 с; 10 с; 1500 мс (ниже) |
| `invite_expires_at`, `room_expires_at`, `starts_at` | ISO \| null | конец приглашения; конец комнаты (`null` до принятия); конец отсчёта (`null` до старта) |
| `version`, `server_now` | int, ISO | `version` растёт при каждом изменении, видимом игрокам (принятие, готовность, ответ, переход), присутствие его не двигает |
| `ready` | `{me, opponent}` | кто нажал «Готов» |
| `opponent_present`, `opponent_absent_since` | bool, ISO \| null | соперник молчит (таблица ниже) |
| `round` | `{no, phase, deadline_at, phase_ends_at, my_answered, opponent_answered}` \| null | раунд, в котором матч; только в `countdown` и `playing` |
| `scores` | `{me, opponent}` | сумма очков **разрешённых** раундов (0…500) |
| `result` | `{outcome, end_reason, rounds[]}` \| null | у любого терминального матча, у `closed` тоже |
| `rematch` | `{battle_id, status, by_me}` \| null | только у терминального матча: самый поздний реванш этого матча |
| `friendship_id` | int \| null | дружба на момент приглашения |
| `audio_preload` | list<string> \| null | только диктант в `room` и `countdown` |

`round.phase`: `answering` · `reveal` · `null` (отсчёт: раунд 1 уже назван и имеет дедлайн, но фазы ещё нет). `deadline_at` — конец приёма ответов; `phase_ends_at` — конец показа разбора (в `answering` `null`); `my_answered`/`opponent_answered` — кто ответил (самого ответа соперника нет до разбора).
`result.outcome` — с моей стороны: `won` · `lost` · `draw` · `none` (`closed`: матч без результата). `result.rounds` — **только разрешённые раунды** (`meta.rounds_played`): `{no, me: {verdict, points}, opponent: {verdict, points}}`; `verdict` — `correct` · `typo` · `wrong` · `skipped` · `timeout`.
Раунд, оборванный сдачей или отсутствием, в итог не входит, его очки не засчитаны.

**Поля, которые клиент читает сверх проекта docs/16 §7.4** (решения координатора, 18.6/18.6b)

| Поле | Что значит и как использовать |
|---|---|
| `rematch.by_me` | Реванш предложил я (`true`) или соперник (`false`). Лишний `GET` реванша не нужен: `status: invited` и `by_me: false` — предложение мне, «Принять» открывает `/battles/{battle_id}`. Есть в `BattleResource` и в `poll` терминального матча |
| `opponent_present`, `opponent_absent_since` | Живой матч (`room`, `countdown`, `playing`): соперник молчит дольше `presence_warn_seconds` (10 с) → `opponent_present = false`, а `opponent_absent_since` — момент его последнего присутствия. У `invited` и терминальных статусов — `true` / `null` |
| `presence_grace_seconds` | Матч кончается, когда от `opponent_absent_since` прошло столько секунд (в `playing` — победа остающегося, в `room`/`countdown` — отмена); момент замечает ближайший опрос игрока или дворник. «Осталось» на экране: `opponent_absent_since + presence_grace_seconds − server_now`; после перезагрузки число то же. Предупреждение (10 с) и конец (30 с) — разные пороги, конец решает только 30. Правило одно для обоих: молчащим можете оказаться и вы |
| `deadline_grace_ms` | Ответ, ушедший у клиента в 0:00 и дошедший позже, принимается ещё столько мс после `round.deadline_at`. Клиент не угадывает грейс, а читает его отсюда |
| `round` в `countdown` | `{no: 1, phase: null, deadline_at: starts_at + round_seconds, phase_ends_at: null, my_answered: false, opponent_answered: false}`; `GET …/round?no=1` в отсчёте уже отдаёт задание (`phase: null`, `reveal: null`) — экран может нарисоваться до старта. Ответ на раунд в отсчёте — 409 `round_not_open {round: 1, phase: null}`: после него перечитайте опрос |
| `audio_preload` | Диктант, `room` и `countdown`: пять путей клипов. Совпадают **строка в строку** с `round.element.audio_url` раундов 1…5 (с префиксом `/api/twa`), поэтому blob, загруженный в комнате, играет в раунде. «Готов» ждёт загрузки всех пяти; `null` у других форматов и статусов |
| `friendship_id` | Дружба на момент приглашения (`null`, если запись дружбы удалена). Для реванша не нужен: `rematch_of` обходится без него |

#### Примеры по статусам
Один матч целиком, с моей стороны (Bek, `challenger`; соперник Dilnoza, `uz`). Матч 15, `sentence`, курс `english-a1`, урок 41 («Модуль 2 · Урок 4 · Еда»), дружба 11.

**`invited`** — ответ `POST /battles` (**201**); `GET` у того, кого пригласили, то же с `role: "opponent"`, названием курса на его языке и `opponent.first_name` инициатора:
```json
{ "id": 15, "status": "invited", "end_reason": null, "mode": "sentence", "role": "challenger",
  "course": { "slug": "english-a1", "title": "Английский A1" },
  "lesson": { "id": 41, "stage": 2, "number": 4, "title": "Еда" },
  "opponent": { "first_name": "Dilnoza" },
  "rounds_total": 5, "round_seconds": 45, "countdown_seconds": 3, "reveal_seconds": 4,
  "presence_grace_seconds": 30, "presence_warn_seconds": 10, "deadline_grace_ms": 1500,
  "invite_expires_at": "2026-10-30T10:05:00.000Z", "room_expires_at": null, "starts_at": null,
  "version": 0, "server_now": "2026-10-30T10:00:00.120Z",
  "ready": { "me": false, "opponent": false }, "opponent_present": true, "opponent_absent_since": null,
  "round": null, "scores": { "me": 0, "opponent": 0 }, "result": null, "rematch": null,
  "friendship_id": 11, "audio_preload": null }
```

Дальше для каждого статуса показаны только ключи, которые отличаются от `invited`: набор и порядок ключей у `BattleResource` всегда те же (`BattleContractTest` проверяет точную структуру каждого статуса).

**`room`** — после `accept` (10:00:20) и моего «Готов»:
```json
{ "status": "room", "version": 2, "server_now": "2026-10-30T10:00:35.050Z",
  "room_expires_at": "2026-10-30T10:02:20.000Z", "ready": { "me": true, "opponent": false } }
```
У диктанта в `room` (и в `countdown`) `audio_preload` — пять клипов, остальные ключи те же (`round_seconds: 60`):
```json
"audio_preload": [ "/api/twa/practice/battles/15/audio/eD4rS6yUiOa", "/api/twa/practice/battles/15/audio/ejIzGfbYA0Z", "…" ]
```

**`countdown`** — второй «Готов» (10:00:41): `starts_at` на 3 с впереди, раунд 1 без фазы, но с дедлайном:
```json
{ "status": "countdown", "version": 3, "server_now": "2026-10-30T10:00:41.200Z",
  "starts_at": "2026-10-30T10:00:44.000Z", "ready": { "me": true, "opponent": true },
  "round": { "no": 1, "phase": null, "deadline_at": "2026-10-30T10:01:29.000Z", "phase_ends_at": null, "my_answered": false, "opponent_answered": false } }
```

**`playing` / `answering`** — я ответил в 10:00:52, соперник ещё нет (счёт не двигается, пока раунд не разрешён; если бы ответил соперник, а не я, было бы `my_answered: false`, `opponent_answered: true`):
```json
{ "status": "playing", "version": 5, "server_now": "2026-10-30T10:00:53.000Z",
  "round": { "no": 1, "phase": "answering", "deadline_at": "2026-10-30T10:01:29.000Z", "phase_ends_at": null, "my_answered": true, "opponent_answered": false },
  "scores": { "me": 0, "opponent": 0 } }
```

**`playing` / `reveal`** — соперник ответил в 10:00:57, раунд разрешён: счёт обновлён, пауза 4 с до следующего раунда (`phase_ends_at`):
```json
{ "status": "playing", "version": 6, "server_now": "2026-10-30T10:00:57.410Z",
  "round": { "no": 1, "phase": "reveal", "deadline_at": "2026-10-30T10:01:29.000Z", "phase_ends_at": "2026-10-30T10:01:01.410Z", "my_answered": true, "opponent_answered": true },
  "scores": { "me": 100, "opponent": 0 } }
```

**`finished`** — после 5 раундов (раунд 2 — оба не успели, `timeout`); у соперника то же зеркально (`role: "opponent"`, `scores`/`rounds` меняются местами, `outcome: "lost"`):
```json
{ "status": "finished", "end_reason": "completed", "version": 18, "server_now": "2026-10-30T10:03:41.000Z",
  "round": null, "audio_preload": null, "opponent_present": true, "opponent_absent_since": null,
  "scores": { "me": 400, "opponent": 300 },
  "result": { "outcome": "won", "end_reason": "completed", "rounds": [
    { "no": 1, "me": { "verdict": "correct", "points": 100 }, "opponent": { "verdict": "wrong",   "points": 0 } },
    { "no": 2, "me": { "verdict": "timeout", "points": 0 },   "opponent": { "verdict": "timeout", "points": 0 } },
    { "no": 3, "me": { "verdict": "correct", "points": 100 }, "opponent": { "verdict": "correct", "points": 100 } },
    { "no": 4, "me": { "verdict": "correct", "points": 100 }, "opponent": { "verdict": "correct", "points": 100 } },
    { "no": 5, "me": { "verdict": "correct", "points": 100 }, "opponent": { "verdict": "correct", "points": 100 } } ] },
  "rematch": null }
```
Ничья — `outcome: "draw"` (`winner_learner_id = NULL`), сдача — `end_reason: "surrender"` (у сдавшегося `outcome: "lost"`), потеря связи — `end_reason: "absent"`.

**`closed`** — приглашение отклонено; `result` есть и здесь, без раундов:
```json
{ "status": "closed", "end_reason": "declined", "version": 1, "round": null, "scores": { "me": 0, "opponent": 0 },
  "result": { "outcome": "none", "end_reason": "declined", "rounds": [] }, "rematch": null }
```
Матч, закрытый после сыгранных раундов (`both_absent` — оба пропали), несёт в `result.rounds` только разрешённые раунды, а `scores` — их сумму; у `voided` и `canceled` раундов нет.

#### `GET /api/twa/practice/battles/{id}/poll` — опрос (вне `twa-api`)
Раз в 2 с, пока матч живой и экран на виду; это и **heartbeat** игрока. Не больше 600 байт (на деле до 404 в самом тяжёлом состоянии) — только то, что меняется. Ключи одни и те же для всех статусов:
```json
{ "id": 15, "status": "playing", "version": 5, "server_now": "2026-10-30T10:00:53.000Z",
  "ready": { "me": true, "opponent": true }, "opponent_present": true, "opponent_absent_since": null,
  "round": { "no": 1, "phase": "answering", "deadline_at": "2026-10-30T10:01:29.000Z", "phase_ends_at": null, "my_answered": true, "opponent_answered": false },
  "scores": { "me": 0, "opponent": 0 }, "end_reason": null, "rematch": null }
```
Раунд разрешён (следующий опрос после ответа соперника): `version` +1 (6), `round.phase = "reveal"`, `phase_ends_at` заполнен, `opponent_answered: true`, **счёт сдвинулся** (`"scores": {"me": 100, "opponent": 0}`) — до этого момента очки раунда не видны ни у кого.
`rematch` в опросе есть только у терминального матча (у живого — `null` без запроса): `{ "battle_id": 16, "status": "invited", "by_me": false }`. Клиент перечитывает `GET /battles/{id}` при смене `status` и `GET …/round?no=` при смене `round.no` или `round.phase`.

#### `GET /api/twa/practice/battles/{id}/round?no=2` — раунд
`no` — 1…99; раунд, который ещё не начался, → **409** `round_not_open {round, phase}` (в отсчёте доступен раунд 1). `element` — задание в проекции режима на моём языке (формы как `current` в A.16, без ответов и текста диктанта); `reveal` — `null`, пока раунд не разрешён
(ответили оба либо вышел дедлайн + грейс) — до этого ни правильного ответа, ни ответа соперника на устройстве нет. `phase`/`deadline_at`/`phase_ends_at` — часы **этого** раунда: у текущего они живые, у прошедшего `phase = "reveal"`, а часов нет.

Раунд идёт — правильного ответа нет (`reveal: null`); тот же запрос у соперника отдаёт то же задание на его языке (`prompt: "Gap 6."`):
```json
{ "no": 1, "phase": "answering", "deadline_at": "2026-10-30T10:01:29.000Z", "phase_ends_at": null, "server_now": "2026-10-30T10:00:50.300Z",
  "element": { "id": "epzXBzLqc0T", "kind": "sentence", "prompt": "Предложение 6.",
               "fragments": [ { "id": "fq9dacye0Ft", "text": "we" }, { "id": "fAJmerOMAu0", "text": "play" }, { "id": "f0zDbJwf5C6", "text": "today" }, { "id": "f6KAra9XgkU", "text": "game6" } ] },
  "reveal": null }
```
Раунд разрешён — тот же запрос отдаёт разбор (`translation` и `explanation` — на моём языке, `null`, если у формата их нет):
```json
{ "no": 1, "phase": "reveal", "deadline_at": "2026-10-30T10:01:29.000Z", "phase_ends_at": "2026-10-30T10:01:01.410Z", "server_now": "2026-10-30T10:00:57.600Z",
  "element": { "...": "как выше" },
  "reveal": { "correct": { "correct_text": "We play game6 today.", "alternatives": [] },
              "me":       { "answer": { "order": [ "fq9dacye0Ft", "fAJmerOMAu0", "f6KAra9XgkU", "f0zDbJwf5C6" ] }, "verdict": "correct", "points": 100 },
              "opponent": { "answer": { "order": [ "f0zDbJwf5C6", "f6KAra9XgkU", "fAJmerOMAu0", "fq9dacye0Ft" ] }, "verdict": "wrong", "points": 0 },
              "explanation": "Порядок 6", "translation": "Предложение 6." } }
```
`reveal.me.answer` / `opponent.answer` — принятый ответ в форме, в которой его присылают (`null` у `timeout`). Разбор раунда доступен и позже — тем же запросом, но без часов (`deadline_at: null`, `phase_ends_at: null`), а у тайм-аута `me`/`opponent` = `{answer: null, verdict: "timeout", points: 0}`.

| Формат | `element.kind` и поля | Ответ (`answer` в `POST …/answer`) | `reveal.correct` |
|---|---|---|---|
| `pairs` | `pairs_round`: `round`, `rounds` (5), `left[{id,text}]` ×4, `right[{id,text}]` ×4 (перевод на моём языке, порядок свой), `matched: []`, `mismatches: 0` | **весь набор сразу**: `{ "pairs": { "<left_id>": "<right_id>", … } }` — все 4 слова, без повторов правой карточки | `{ "pairs": { "<left_id>": "<right_id>", … } }` |
| `collocation` | `collocation`: `text` (с `___`), `options[{id,text}]` ×3 | `{ "option_id": "…" }` или `{ "skip": true }` | `{ "correct_option_ids": ["…"], "full_text": "…" }` |
| `sentence` | `sentence`: `prompt`, `fragments[{id,text}]` | `{ "order": ["<id фрагмента>", …] }` — все фрагменты ровно по разу, или `{ "skip": true }` | `{ "correct_text": "…", "alternatives": ["…"] }` |
| `dictation` | `dictation`: `source`, `audio_url`, `words`, `replacements_left: 0` (в баттле клип не заменяют) — **текста нет** | `{ "text": "…" }` (≤ 200 символов) или `{ "skip": true }` | `{ "expected": "…" }` |

Очки раунда: `correct` — `practice.battle.points.round` (100); `wrong`, `skipped`, `timeout` и `typo` (диктант с опечаткой: пометка в разборе) — 0. Пары: 25 за каждое верное соответствие, `correct` только при 4 из 4, иначе `wrong` с частичными очками (50 за две пары).
Скорость очков не прибавляет. Выигрывает набравший больше за 5 раундов; равный счёт — ничья.

#### `GET /api/twa/practice/battles/options?friendship_id=11&course=english-a1[&lesson_id=41]`
Что можно сыграть с этим другом на этом материале. `friendship_id` — id из `GET /friends`, `course` — slug (≤ 64), `lesson_id` — урок курса. Курс — только опубликованный и не колода ученика.
```json
{ "friend": { "first_name": "Dilnoza" },
  "course": { "slug": "english-a1", "title": "Английский A1" },
  "lesson": { "id": 41, "stage": 2, "number": 4, "title": "Еда" },
  "modes": [
    { "mode": "pairs",       "state": "available",  "available": 24, "required": 20, "reason": null,       "round_seconds": 45 },
    { "mode": "collocation", "state": "not_enough", "available": 3,  "required": 5,  "reason": "no_items", "round_seconds": 30 },
    { "mode": "sentence",    "state": "disabled",   "available": 6,  "required": 5,  "reason": null,       "round_seconds": 45 },
    { "mode": "dictation",   "state": "available",  "available": 6,  "required": 5,  "reason": null,       "round_seconds": 60 } ],
  "friend_access": "available" }
```
- `modes[]` — всегда четыре, в порядке `pairs`, `collocation`, `sentence`, `dictation`. `state`: `available` · `not_enough` (числа и `reason` — как в хабе A.16: `no_words`, `ambiguous`, `no_items`, `audio_pending`, `no_audio_words`) · `disabled` (`PRACTICE_MODE_*`; числа и `reason` при этом настоящие — материал считается и для выключенного формата, в отличие от хаба A.16, где у `disabled` `available = 0`).
  Состояния `pro_required` у баттла нет: Free при `PRACTICE_PLAN=pro` получает 403 на весь запрос.
  Материал считается по языкам **обоих** игроков: пары — 5 раундов × 4 слова = 20 слов, однозначных в каждом из двух языков; остальные — 5 заданий (диктант — фразы с готовой записью). Числа `available` — то, что нашлось.
- `friend_access`: `available` · `no_access` (друг не в пилоте / не Pro при `PRACTICE_PLAN=pro`) — это не ошибка, экран объясняет и не даёт пригласить.
- Ошибки: **404** `friendship_not_found` (чужая пара, друг удалён или заблокирован), **403** `not_friends` (пара не `accepted`), **404** `course_not_found`, `lesson_not_found`, **422** `lesson_not_in_course`, **409** `battle_disabled`, **403** доступ, **422** валидация.

#### `GET /api/twa/practice/battles` — список
```json
{ "active": { "id": 15, "status": "room", "mode": "sentence", "role": "challenger",
              "course": { "slug": "english-a1", "title": "Английский A1" }, "lesson": { "id": 41, "stage": 2, "number": 4, "title": "Еда" }, "opponent": { "first_name": "Dilnoza" } },
  "incoming": [ { "id": 18, "mode": "pairs", "from": { "first_name": "Aziza" },
                  "course": { "slug": "english-a1", "title": "Английский A1" }, "lesson": null,
                  "invite_expires_at": "2026-10-30T10:05:00.000Z", "rematch": false } ],
  "finished": [ { "id": 9, "mode": "sentence", "opponent": { "first_name": "Dilnoza" }, "outcome": "won",
                 "scores": { "me": 400, "opponent": 300 }, "finished_at": "2026-10-30T10:01:10.000Z" } ] }
```
- `active` — матч, которым я занят (`battle_participants.active_learner_id`): моё приглашение, которое не просрочено, комната, отсчёт, игра; `null` — никакого. Компактно: **ровно** `id, status, mode, role, course, lesson, opponent` (остальное — `GET /battles/{id}`).
- `incoming` — приглашения мне со сроком в будущем, новые сверху; `rematch: true` — это реванш. `finished` — последние 10 (`finished_list`) терминальных матчей с моим участием, новые сверху; `outcome` — `won` · `lost` · `draw` · `none` (закрытые: отказ, истёкшее приглашение, отмена…).

#### `POST /api/twa/practice/battles` — пригласить друга (201)
```json
{ "friendship_id": 11, "mode": "sentence", "course": "english-a1", "lesson_id": 41 }
```
`friendship_id`, `mode`, `course` обязательны, `lesson_id` — целое ≥ 1 или `null` (весь курс). Ответ **201** `BattleResource` (`status: invited`); другу уходит ЛС (B.14) и событие `battle.invited`. Снимок на 5 раундов строится сразу, на языках обоих игроков.
- **Реванш:** `{ "rematch_of": 15 }` — мой терминальный матч (`finished` или `closed`). Друг — соперник того матча (пара всё ещё `accepted`, иначе **403** `not_friends`), формат, курс и урок — оттуда, снимок новый (при ровно 5 заданиях те же задания в другом порядке).
  Остальные поля тела при `rematch_of` **не читаются и не валидируются** (TWA шлёт `mode`, `course`, `lesson_id`, `friendship_id` заодно — это не 422, даже если там мусор). `rematch_of` чужого матча → **404** `battle_not_found`; матча, который ещё идёт → **409** `battle_wrong_state {status}`.
- **Занятость.** Один живой матч на ученика (unique `battle_participants.active_learner_id`): второе приглашение → **409** `battle_busy {battle_id}` — id моего матча. Встречное приглашение (друг **уже пригласил меня**, оно открыто) тоже **409** `battle_busy`, но с id **его** приглашения: открой его и прими
  (оба нажали «Реванш» с разницей в секунды — второй попадает сюда). Проверка до транзакции, без блокировок: приглашения, пересёкшиеся в ту же миллисекунду, пройдут оба — тогда каждый занят своим, а принять чужое можно, только отозвав своё.
- Порядок проверок и ошибки: доступ (403) → `battle_disabled` (409) → [реванш: 404/409] → `practice_mode_disabled` (409 `{mode}`) → пара (404 `friendship_not_found`, 403 `not_friends`) → `battle_friend_no_access` (409) → встречное `battle_busy` → курс (404 `course_not_found`) → урок (404 `lesson_not_found`, 422 `lesson_not_in_course`) → материал
  (422 `battle_material_unavailable` с `details` как у `practice_not_enough_material`, событие `practice.not_enough_material {battle: true}`) → запись (нарушение unique → `battle_busy`). Валидация Laravel — 422 до всего этого.

#### Действия: `accept` · `decline` · `cancel` · `ready` · `surrender`
`POST /practice/battles/{id}/<действие>`, без тела, кроме `surrender` (`{ "confirm": true }`; без него **422** — выход из игры не делается случайным запросом). Ответ **200** `BattleResource` — матч таким, каким его видит действующий.

| Действие | Кто | Что делает | Отказы |
|---|---|---|---|
| `accept` | приглашённый | `invited` → `room`; оба «присутствуют» с этой секунды; занимает приглашённого (`active_learner_id`). Для матча, который уже идёт, — 200 без изменений (двойное нажатие) | 403 `battle_not_recipient`; 409 `battle_closed` (просрочено — `invite_expired`; дружба кончилась — `canceled`); 409 `battle_busy {battle_id}` (я занят другим); 409 `battle_disabled` |
| `decline` | приглашённый | `invited` → `closed/declined` | 403 `battle_not_recipient`; 409 `battle_wrong_state` (`room`, `countdown`, `playing`), `battle_closed` |
| `cancel` | инициатор — приглашение; любой — комнату и отсчёт | → `closed/canceled` (`meta.left` — кто вышел) | 403 `battle_not_challenger`; 409 `battle_wrong_state` (`playing` — сдайтесь), `battle_closed` |
| `ready` | оба игрока в `room` | отмечает готовность; оба → `countdown`. Диктант: сервер проверяет 5 файлов, нет — матч сразу `closed/voided` (в ответе 200, статус виден). В `countdown`/`playing` — 200 без изменений | 409 `battle_wrong_state` (`invited`), `battle_closed` |
| `surrender` | любой в `countdown`, `playing` | → `finished/surrender`, победа соперника | 422 (`confirm`); 409 `battle_wrong_state` (`invited`, `room`), `battle_closed` |

Любое действие в первую очередь доводит матч до «сейчас» (просроченное приглашение закрывается, назревший раунд разрешается) — отказ, возникший **после** такого перехода, не откатывает его.
Присутствие (`last_seen_at`) обновляют `accept` (у обоих игроков), `ready`, `answer`, `surrender`, `audio-failed` и чтения `GET /battles/{id}`, `…/poll`, `…/round`; `decline`, `cancel` и список — нет. События: `battle.accepted` — при принятии, `battle.started` — когда оба готовы, `battle.closed` / `battle.finished` — при конце.

#### `POST /api/twa/practice/battles/{id}/answer` — ответ раунда
```json
{ "request_id": "9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d", "round": 1, "answer": { "order": ["fq9dacye0Ft", "fAJmerOMAu0", "f6KAra9XgkU", "f0zDbJwf5C6"] } }
```
`request_id` — **uuid** (регистр не важен), ключ повтора; `round` — 1…99, номер раунда, на который отвечают; `answer` — по формату (таблица выше). Пределы: `text` ≤ 200 символов, `order` ≤ 8 id по ≤ 16 символов, `pairs` ≤ 4 пары, тело `answer` ≤ 4 КБ. Ответ **200**:
```json
{ "verdict": "correct", "points": 100, "accepted_at": "2026-10-30T10:00:52.200Z", "round_resolved": false,
  "poll": { "id": 15, "status": "playing", "version": 5, "server_now": "2026-10-30T10:00:52.200Z",
            "ready": { "me": true, "opponent": true }, "opponent_present": true, "opponent_absent_since": null,
            "round": { "no": 1, "phase": "answering", "deadline_at": "2026-10-30T10:01:29.000Z", "phase_ends_at": null, "my_answered": true, "opponent_answered": false },
            "scores": { "me": 0, "opponent": 0 }, "end_reason": null, "rematch": null } }
```
- `verdict`/`points` — **мои**: разбор и ответ соперника здесь не возвращаются, они приходят в `GET …/round` после разрешения раунда. `round_resolved: true` — мой ответ оказался вторым и раунд разрешён (в `poll` уже `phase = "reveal"` и новый счёт). `poll` — состояние матча после ответа (отдельный опрос не нужен).
- **Один окончательный ответ на раунд** (unique `(battle_id, round, learner_id)`); пары — весь набор сразу. Повтор с **тем же `request_id`** возвращает прежний результат (тот же `verdict`, `points`, `accepted_at`) — даже если раунд уже закрыт, начался следующий или матч закончился: потерянный ответ нельзя «потерять» повтором,
  а тело повтора не сравнивается (ответ первого запроса). Новый `request_id` на тот же раунд — 409 `answer_already_accepted {verdict, points}` (первый ответ остаётся).
- Порядок проверок под блокировкой строки матча: присутствие автора → ленивые переходы → повтор `request_id` (200) → терминальный (409 `battle_closed`) → отсчёт (409 `round_not_open {round, phase: null}`) → не `playing` (409 `battle_wrong_state`) → раунд позже текущего (409 `round_not_open`) → раунд раньше текущего, пауза разбора или дедлайн + грейс прошли (409 `round_closed`) →
  второй ответ (409 `answer_already_accepted`) → форма (422 `invalid_answer_shape {expected}`, `unknown_element_id`). Форма пар в баттле — `bijection`, предложения — `order_of_all_fragments`, сочетания — `option_id`, диктанта — `text_or_skip`.
- Тайм-аут: если игрок не ответил до `deadline_at + deadline_grace_ms`, ближайший обратившийся (опрос любого игрока, действие, дворник) разрешает раунд: пишет игроку строку `timeout` (0 очков, `request_id` — детерминированный uuid v5) и начинает `reveal`. Отвечать после этого поздно — 409 `round_closed`.

#### `POST /api/twa/practice/battles/{id}/audio-failed` — клип диктанта не играет
```json
{ "request_id": "3d5f0a8e-77b1-4a43-9c2e-6d1d9f5b2a10", "element_id": "ejIzGfbYA0Z" }
```
Сервер сам проверяет файл элемента. Ответ **200** `{ "verified": true | false, "battle": { "...BattleResource..." } }`:
- `verified: true` — файла нет (или он пуст): матч `closed/voided` для обоих (`end_reason: "voided"`, `result.outcome: "none"`), очки не засчитаны;
- `verified: false` — файл на месте, беда локальная, матч идёт дальше (игрок отвечает без звука или получает 0 за тайм-аут). Разрешён в `room`, `countdown`, `playing`; повтор с тем же `request_id` отвечает первым отчётом (идемпотентно, одно событие `practice.audio_failed`).
- Ошибки: 409 `battle_wrong_state` (`invited`), `battle_closed` (терминальный; повтор того же `request_id` — 200), 422 `unknown_element_id` (элемент не из этого матча или без записи).

#### `GET /api/twa/practice/battles/{id}/audio/{element}` — клип диктанта (вне `twa-api`)
Тело — `audio/mpeg`, заголовки `Cache-Control: private, no-store`, `Content-Length`, `X-Content-Type-Options: nosniff`; путь на диске и текст в API не выходят. TWA берёт URL из `audio_preload` или `round.element.audio_url` и грузит клип с `Authorization` в `blob:`.
- Клип отдаётся участнику, если раунд этого элемента **уже начался** (номер раунда ≤ `current_round`; матч, который закончился, помнит свой последний раунд — все пять), либо матч в `room` / `countdown` (предзагрузка всех пяти: «Готов» без неё нельзя, а звук текст не раскрывает).
  Клип будущего раунда, чужой матч, неизвестный элемент и элемент без записи — один и тот же **404** `battle_not_found`. Файла нет → **503** `practice_audio_unavailable` и больше ничего (матч не трогается, событие не пишется): отчёт, который аннулирует матч, — `audio-failed`.

#### Коды ошибок баттла (сводно)
Коды практики общие с A.16 (`practice_unavailable`, `practice_pro_required`, `practice_mode_disabled`, `answer_already_accepted`, `invalid_answer_shape`, `unknown_element_id`, `practice_audio_unavailable`, `course_not_found`, `lesson_not_found`, `lesson_not_in_course`); дружбы — A.14. TWA: каждый код в `KNOWN_ERROR_CODES` и `error.code.<код>`.

| Код | HTTP | `details` | Где и когда |
|---|---|---|---|
| `practice_unavailable` | 403 | — | любой маршрут: практика закрыта (`off`, не в пилоте) |
| `practice_pro_required` | 403 | — | любой маршрут: Free при `PRACTICE_PLAN=pro` (пейвол `practice`) |
| `battle_disabled` | 409 | — | `options`, `POST /battles`, `accept`: `PRACTICE_BATTLE=false` |
| `practice_mode_disabled` | 409 | `{mode}` | `POST /battles`: формат выключен (`PRACTICE_MODE_*`) |
| `battle_busy` | 409 | `{battle_id}` | `POST /battles`: я занят своим матчем (id) либо друг уже пригласил меня (id **его** приглашения); `accept`: я занят другим матчем. Называет только матчи, где спрашивающий — участник. `battle_id` бывает `null`, если матч, державший ученика, успел закрыться |
| `battle_friend_no_access` | 409 | — | `POST /battles`: у друга нет доступа к практике |
| `battle_material_unavailable` | 422 | `{mode, available, required, reason, course, lesson_id}` | `POST /battles`: материала на 5 раундов нет в языках обоих |
| `friendship_not_found` | 404 | — | `options`, `POST /battles`: не моя пара, друг удалён или заблокирован |
| `not_friends` | 403 | — | пара не `accepted`; реванш после удаления из друзей |
| `battle_not_found` | 404 | — | любой `{id}`: нет такого или чужой (неотличимо); `rematch_of` чужого матча; аудио: клип раунда, который не начался, неизвестный элемент или элемент без записи |
| `battle_not_recipient` | 403 | — | `accept`/`decline` не приглашённым |
| `battle_not_challenger` | 403 | — | `cancel` приглашения не инициатором |
| `battle_closed` | 409 | `{end_reason}` | действие над терминальным матчем (в т. ч. просроченным приглашением — `invite_expired`) или ответ с **новым** `request_id` после конца |
| `battle_wrong_state` | 409 | `{status}` | действие не по статусу (см. таблицу действий); `rematch_of` матча, который ещё идёт |
| `round_not_open` | 409 | `{round, phase}` | `answer` на раунд позже текущего или в отсчёте (`phase: null`); `GET …/round` раунда, который не начался |
| `round_closed` | 409 | `{round, phase}` | `answer` на прошедший раунд, во время `reveal` или после `deadline_at` + `deadline_grace_ms` |
| `answer_already_accepted` | 409 | `{verdict, points}` | другой (новый `request_id`) ответ на тот же раунд, пока он идёт |
| `invalid_answer_shape` | 422 | `{expected}` | форма ответа не по формату: `order_of_all_fragments`, `option_id`, `text_or_skip`, `bijection` |
| `unknown_element_id` | 422 | — | вариант / фрагмент / карточка не из раунда; `audio-failed`: элемент без записи или чужой |
| `practice_audio_unavailable` | 503 | — | `GET …/audio/{element}`: файла нет |

#### Как ходит клиент (TWA)
1. Вход — список (`GET /battles`: «идёт баттл», приглашения мне) либо ссылка из ЛС `/twa/battles/{id}` → `GET /battles/{id}`. Приглашение друга — экран настройки: `options` на каждый выбор курса/урока → `POST /battles`.
2. Пока статус живой (`invited`, `room`, `countdown`, `playing`) — `GET …/poll` раз в 2 с, только на экране (в фоне стоп, при возврате опрос сразу); сбой (сеть, 5xx, 429) удваивает паузу до 10 с. Смена `status` → `GET /battles/{id}`; смена `round.no`/`round.phase` → `GET …/round?no=`.
   На терминальном матче опрос продолжается недолго (раз в 5 с до 2 минут) ради `rematch` — это дешевле `GET /battles/{id}` и вне `twa-api`.
3. Таймеры рисуются от серверных меток (`invite_expires_at`, `room_expires_at`, `starts_at`, `round.deadline_at`, `round.phase_ends_at`) по смещению `server_now`; 0:00 не останавливает ввод — ответ уйдёт, решит сервер (грейс). Экран просыпается ровно к моменту перехода, не дожидаясь опроса.
4. Ответ: новый `request_id` на попытку; при потере ответа (сеть, 5xx, 429) — тот же `request_id` (до 3 раз, затем кнопка «Повторить»). Определённый отказ (`round_closed`, `round_not_open`, `battle_closed`) после неопределённого сбоя значит «ответ мог пройти»: показывать правду будут опрос и разбор.
5. Диктант: в `room` загрузить все `audio_preload` в blob до «Готов»; окончательный 503 → `POST …/audio-failed`; `verified: true` — матч аннулирован, показать итог, `verified: false` — повторить загрузку.
6. Присутствие: `opponent_present = false` → полоса «соперник потерял связь» с остатком `opponent_absent_since + presence_grace_seconds − server_now`. Свой уход в фон дольше 30 с — это техническое поражение: после возврата матч уже может быть `finished/absent`.
7. После конца: итог из `result`; реванш — `POST /battles {rematch_of}`; предложение соперника — `rematch.by_me = false` и `status = invited`. На 409 `battle_busy` с чужим приглашением — открыть его (`GET /battles/{battle_id}`) и принять.

**События аналитики** (`product_events`, после commit, актёр — инициатор матча; в props только числа и коды): `battle.invited` `{battle_id, mode, course_id, lesson_id, rematch}`; `battle.accepted` `{battle_id}`; `battle.started` `{battle_id, mode}`;
`battle.finished` `{battle_id, end_reason, result (challenger|opponent|draw), rounds_played, duration_ms}`; `battle.closed` `{battle_id, end_reason}`; `practice.not_enough_material` (с `battle: true`) и `practice.audio_failed` `{battle_id, source (word|phrase), reason (file_missing|client_report), verified}`. Событие пишется один раз, после commit той транзакции, которая изменила состояние матча, — не опросом и не повтором запроса.

## Часть B — Telegram Bot

### B.1 Webhook
**URL:** `POST /telegram/webhook/{secret}`
- `{secret}` генерируется при развёртывании и совпадает с `TELEGRAM_WEBHOOK_SECRET`.
- Дополнительно проверяем заголовок `X-Telegram-Bot-Api-Secret-Token` (Telegram сам его шлёт, если указать при `setWebhook`).
- Контроллер кладёт `Update` в очередь `high` и мгновенно отвечает 200. Исключение — `pre_checkout_query` (оплата Stars): отвечается **синхронно** в том же запросе (B.11), в очередь не попадает.
- `allowed_updates` при `setWebhook` (`IrazasyedTelegramClient::ALLOWED_UPDATES`): `message`, `my_chat_member`, `chat_member`, `callback_query`, `pre_checkout_query`; `successful_payment` и `refunded_payment` приходят внутри `message`. После добавления типа апдейта — повторить `php artisan telegram:set-webhook`.

```php
// app/Http/Controllers/TelegramWebhookController.php (скелет)
public function __invoke(Request $request, string $secret)
{
    abort_unless(hash_equals(config('telegram.webhook_secret'), $secret), 404);
    abort_unless(hash_equals(
        config('telegram.webhook_secret'),
        $request->header('X-Telegram-Bot-Api-Secret-Token', '')
    ), 403);

    HandleTelegramUpdate::dispatch($request->all())->onQueue('high');
    return response()->json(['ok' => true]);
}
```

### B.2 Middleware

1. **GroupLock** — проверяет `chat_id` в белом списке.
2. **TeacherOnly** — применяется к командам `/start_training`, `/start_exam`, `/close_exam`.
3. **IgnorePrivateChats** — для команд, которые имеют смысл только в группе.

### B.3 Команды и хендлеры

| Команда | Контекст | Роль | Что делает |
|---------|----------|------|-----------|
| `/start [payload]` | ЛС | любая | **Учитель** (есть в `users.telegram_user_id`) — «Привет, учитель X», как раньше; в онбординг не попадает, learner для него не создаётся. **Остальные — learner**: `LearnerRegistrar::fromTelegramUser` (создаёт/восстанавливает learner'а, `last_seen_at`; сбрасывает `dm_blocked_at`), затем — заблокированному (`blocked_at`) «доступ ограничен» и больше ничего (атрибуция не пишется); иначе атрибуция payload (`attributeAcquisition`: first-touch, только если `acquisition_source` ещё пуст; свой `ref_` и `ref_` на несуществующего/удалённого/заблокированного learner'а игнорируются; `ref_<code>` для уже существовавшего learner'а даёт `referrals.status = rejected {existing_learner}`, для нового — `pending`, docs/13 §7.1), затем: `onboarding_state = done` → «Сегодня» (как `/today`) и, если payload — `c_<slug>` доступного курса (опубликован, не private), ещё одно сообщение с web_app-кнопкой «Открыть курс» → `/twa/courses/<slug>` (активный курс **не меняется**; недоступный курс — без сообщения); иначе онбординг из 5 шагов (см. ниже). **`org_<код>`** (инвайт центра, docs/13 §6.2): код проверяется сразу (`OrganizationMembershipService::previewInvite`, без записи). Неизвестный / отключённый / истёкший / исчерпанный код или приостановленный центр — сообщение на языке learner'а (`organizations.errors.*`) и обычный онбординг / «Сегодня» без запоминания кода; learner, уже состоящий в центре, получает «Вы уже в центре». Годный код: в онбординге — запоминается в `learners.meta.pending_org_invite` (повторный `/start org_…` его перезаписывает), после последнего шага (после сообщения «Готово!») приходит подтверждение «Вступить в центр «X»?» с кнопками `org1:join:<код>` / `org1:skip`; `onboarding_state = done` — подтверждение приходит сразу вместо «Сегодня». Членство создаётся **только** по `org1:join` (`joinByInvite`, идемпотентно; ЛС «Pro активен» / «мест пока нет» отправляет слушатель `OrganizationMemberJoined`), `org1:skip` ничего не создаёт. |
| `/today` | ЛС | learner | Минимальная сводка: «к повторению N слов» (`DueRepetitionCriteria`) или «повторять нечего» + web_app-кнопка «Открыть LexiFlow» → `/twa/?from=today`. Полноценный экран «Сегодня» — `GET /api/twa/today` (docs/12 §7.3). В группе бот молчит, учителю не отвечает. |
| `/settings` | ЛС | learner | Меню «Язык / Цель / Уровень / Часовой пояс / Напоминания»; выбор пункта показывает варианты того же шага, ответ сохраняется (`OnboardingService::applySetting`, те же белые списки) и подтверждается «Сохранено.» на языке learner'а. Часовой пояс — не чаще раза в 24 ч (A.2b): раньше срока бот отвечает `onboarding.settings.timezone_too_soon` с временем следующей возможной смены по текущему поясу learner'а, клавиатура остаётся, ничего не меняется. Тому, кто онбординг не закончил, показывается текущий шаг онбординга. Если у learner'а есть активные членства в центрах — в меню ещё кнопка «Мои центры» (`org1:list`): список центров с состоянием места (Pro активен / в очереди) и кнопка «Покинуть» (подтверждение `org1:leave:<id>` → `org1:leaveyes:<id>`) только для членств `joined_via = invite|admin`; членство `group` вернётся при повторном входе в чат центра, поэтому для него — пояснение «вы в центре через группу». |
| любое другое текстовое сообщение | ЛС | не учитель | `LearnerDmHandler` (последний в списке хендлеров; служебные сообщения без `text` — `pinned_message`, `new_chat_title` и т.п. — игнорирует): регистрирует/оживляет learner'а, сбрасывает `dm_blocked_at`; во время онбординга повторяет текущий шаг (`new` — начинает онбординг), после — краткое меню с кнопкой TWA. Учителю не отвечает (как раньше). |
| `/start_training [stage] [lesson]` | группа | teacher | Создаёт `training_session`, постит сообщение с WebApp-кнопкой. Номера stage/lesson ищутся **в курсе группы** (`telegram_groups.course_id`, иначе курс `default`); без аргументов — список уроков этого курса. |
| `/start_exam [stage] [lesson] [minutes]` | группа | teacher | Создаёт `exam_session`, планирует закрытие. Номера — в курсе группы, как выше. |
| `/close_exam` | группа | teacher | Досрочно закрывает открытый экзамен. |
| `/stats` | ЛС | teacher | Отчёт по его группам за 7 дней. |
| `/promo КОД` | ЛС | любой learner | Активирует промокод (`PromoCodeService::redeem`, как A.7). `/promo` без кода — подсказка. Learner создаётся/обновляется по самому человеку (`LearnerRegistrar::fromTelegramUser`). Ответ — на языке learner'а: «Pro активен до дд.мм.гггг» (в поясе learner'а) / «без ограничения по сроку», либо текст ошибки по `errorCode` (`lang/{ru,uz}/bot.php`, `bot.promo.errors.*`). Не более 5 попыток в минуту на learner'а (иначе «слишком много попыток»); заблокированному — «доступ ограничен». В **активной группе** бот отвечает «откройте личный чат» и код не обрабатывает (код в группе виден всем), в неактивной — молчит (Group Lock). |
| `/pro` | ЛС | любой learner | Тариф и оплата (docs/13 §5, 2A.2). Текст: «Free: N новых слов в день» / «LexiFlow Pro до дд.мм.гггг» (в поясе learner'а), состояние автопродления Stars. При `paywall.mode = purchase` и непустом каталоге — цены периодов и по inline-кнопке на (период × провайдер): `pro1:buy:<period>:<telegram_stars\|payme\|click>`; **заказ создаётся только по нажатию** (меню не расходует лимит 3 неоплаченных заказов); пока автопродление Stars работает, кнопок покупки нет (сначала «Отключить автопродление»). Иначе — «Pro скоро откроется», подсказка `/promo` и `BILLING_SUPPORT_CONTACT`. Кнопки `pro1:cancel` / `pro1:resume` — отключить/включить автопродление Stars (`LearnerBillingService`). Только в личном чате; в группе и у учителя — молчание, заблокированному learner'у — «доступ ограничен». |
| `/invite` | ЛС | любой learner | «Пригласить друга» (docs/13 §7.1, 2C.1): ссылка `https://t.me/<bot>?start=ref_<referral_code>` (код генерируется лениво `ReferralService::codeFor`; пока `TELEGRAM_BOT_USERNAME` пуст — только код), правило («друг позанимается 3 дня от 10 карточек — оба получите 7 дней Pro», числа из `config/referrals.php`), счётчики «пришли / занимаются / награждены» и inline-кнопка «Поделиться ссылкой» (`https://t.me/share/url?url=…&text=…`). Язык learner'а (`referrals.*`). Заблокированному — `referrals.blocked`; учителю и в группе — ничего. |
| `/paysupport` | ЛС, активная группа | любой | Требование Telegram для оплаты Stars: контакт платёжной поддержки (`BILLING_SUPPORT_CONTACT`; «не указан», если пуст), ссылка на публичную оферту (правила возврата) и подсказка про `/pro`. Язык: learner'а, иначе Telegram-язык отправителя / язык группы. В неактивной группе — молчание (Group Lock). |
| `/start f_<code>` | ЛС | любой learner | Приглашение в друзья (docs/13 §7.5, 2C.5), **с согласием открывшего**: ссылка ничего не пишет, бот спрашивает «Отправить X запрос в друзья?» + кнопки (`fr1:snd:<code>` / `fr1:no:<code>`); по «Отправить» создаётся `request(открывший → владелец)`, владельцу уходит ЛС с кнопками принять/отклонить (`fr1:acc:<id>` / `fr1:dec:<id>`), запрос виден и во «Входящих» TWA. Уже друзья / уже отправлено / блок — короткое сообщение; если владелец сам уже запрашивал открывшего — показывается этот входящий запрос. **`ref_<code>` дружбу не предлагает** (существующий learner: реферал `rejected {existing_learner}` + «Сегодня»). Не прошедшему онбординг ничего не записывается. |
| `/help` | любой | любая | Справка простым текстом (без HTML), `bot.help.*`. В ЛС **сначала ученик** — «Как заниматься»: кнопка «LexiFlow» слева от поля ввода и `/today`, `/settings`, `/invite`, `/pro` и `/promo`, `/paysupport`; затем «Для учителей»: бот в группе администратором, `/start_training`, `/start_exam`, `/close_exam`, `/stats`. В группе — команды учителя. |

**Меню команд и кнопка Mini App: `telegram:setup-bot`** (docs/14 §7). Бот сам не регистрирует свои команды, поэтому без этого шага
по «/» Telegram ничего не подсказывает, а в личном чате нет постоянной кнопки приложения. Команда вызывает Bot API (контракт
`TelegramClient::setMyCommands` / `setChatMenuButton`, набор данных — `BotMenuBuilder`):

- `setMyCommands` для трёх scope — `all_private_chats` (`today`, `settings`, `invite`, `pro`, `promo`, `help`), `all_group_chats`
  (только `help`) и `all_chat_administrators` (`start_training`, `start_exam`, `close_exam`, `help`) — по два вызова на каждый
  scope: без `language_code` (язык по умолчанию, ru; его видят все, у кого нет списка на своём языке) и с `language_code = uz`.
  Описания — `lang/{ru,uz}/commands.php` (`commands.private.*`, `commands.group.*`; 1–256 знаков). `/start`, `/stats` и
  `/paysupport` в меню не входят.
- **Почему команды учителя — только у администраторов группы.** Меню «/» в группе видят все участники, а `/start_training`,
  `/start_exam` и `/close_exam` ученику отвечают «аккаунт не привязан к учителю» (ответ уходит в общий чат). Telegram берёт для
  человека первый найденный список (для администратора `all_chat_administrators` раньше `all_group_chats`), поэтому
  администратору приходит только список администраторов, и `help` повторён в обоих списках. Учитель, не являющийся
  администратором группы, видит в меню один `/help`; команды он набирает вручную — они работают, потому что права учителя
  проверяет хендлер, а не меню.
- `setChatMenuButton` без `chat_id` — кнопка по умолчанию для всех личных чатов:
  `{"type":"web_app","text":"LexiFlow","web_app":{"url":"{TWA_BASE_URL}/twa/"}}`. Именно «LexiFlow» названа кнопка в `/help`.
  Telegram принимает `web_app` только с https: при другом `TWA_BASE_URL` (в том числе пустом) команда печатает предупреждение,
  пропускает кнопку и всё равно ставит списки команд (код выхода 0).
- Ошибка Bot API — строка `… not set: <причина>` и код выхода `FAILURE`; остальные вызовы всё равно выполняются.

Команда идемпотентна: запускается после `telegram:set-webhook` при первичной настройке и повторяется после деплоя, который меняет
набор команд или их описания (README «Первичный setup на сервере», docs/VPS_DEPLOY_GUIDE_RU.md §15, docs/DEVOPS_GUIDE.md §12).

#### Онбординг и `/settings`: шаги и `callback_data`

Шаги по порядку: `locale` (🇷🇺/🇺🇿, каждый язык своим названием) → `goal` (`general`/`ielts`/`sat`/`school`) → `level` (`A1`/`A2`/`B1`/`B2`/`unknown`→`A1`) → `timezone` (список из `config('learners.timezone_options')`, по умолчанию Ташкент и соседи; значение — IANA-id, ещё раз проверяется по `DateTimeZone::listIdentifiers`) → `reminder` (`0800`/`1300`/`1900`/`2100`/`off` → `reminder_time` или `NULL`). `learners.onboarding_state` — текущий шаг (`new` → `locale` … `reminder` → `done`). Дефолты тем, кто их не меняет: цель 20 карточек в день (`daily_goal`), напоминание 19:00, `Asia/Tashkent`.

| `callback_data` | Смысл |
|---|---|
| `onb1:<step>:<value>` | ответ на шаг онбординга |
| `set1:<step>` | `/settings`: показать варианты шага |
| `set1:<step>:<value>` | `/settings`: сохранить значение |
| `org1:join:<код>` / `org1:skip` | подтверждение вступления в центр по `org_<код>` / отказ (код — `[A-Za-z0-9_-]{1,32}`) |
| `org1:list` | `/settings` → «Мои центры» |
| `org1:leave:<id>` / `org1:leaveyes:<id>` / `org1:leaveno` | «Покинуть центр»: подтверждение / выполнить / отмена (`<id>` — `organization_members.id`, только своё активное членство) |

**Кнопки `/pro` (`ProCallbackHandler`):** `pro1:buy:<period>:<provider>`, `pro1:cancel`, `pro1:resume`. Формат — строгий regex; `period`/`provider` проверяет `CheckoutService` (сумму кнопка не несёт — её считает сервер). Принимаются только из личного чата отправителя
(кнопка из группы игнорируется), не чаще 10 нажатий в минуту на learner'а (`RateLimiter` `bot-pro:<learner_id>`). Успешная покупка — сообщение «Счёт создан: …» с URL-кнопкой «Оплатить» (`checkout.url`), отказ — текст по `error.code`
(`lang/{ru,uz}/billing.php`, `billing.pro.errors.*`; незнакомый код — `generic`). Тексты `/pro`, `/paysupport` — `lang/{ru,uz}/billing.php`.

Префикс несёт версию формата (`onb1`, `set1`): при смене раскладки старые кнопки перестают совпадать и молча игнорируются. Подписи нет — `callback_data` считается недоверенным: формат проверяется строгим regex, `<step>` — из списка шагов, `<value>` — по белому списку шага. `OnboardingService::apply` принимает **только ответ на текущий шаг**, под блокировкой строки learner'а: повторное нажатие старой кнопки, двойной тап и параллельные доставки отклоняются без изменений; на любой callback бот сразу отвечает `answerCallbackQuery` без текста — через `CallbackAcknowledger`: отказ Telegram («query is too old», повтор job) логируется (`Log::warning`, без PII) и работу хендлера не прерывает; это же относится к `tstart:`. Кнопки принимаются только из личного чата отправителя (`message.chat.id = from.id`), ответы идут на `from.id`; нажатие из группы, чужого чата или учителем (у него нет learner'а) ничего не создаёт и не отправляет. Исключение из «молчаливого» stale: если шаг устаревшей кнопки `onb1` — ровно предыдущий перед текущим состоянием learner'а (ответ сохранён, а следующий шаг не дошёл до Telegram и job `HandleTelegramUpdate` повторяется, tries = 3), бот **отправляет текущий шаг ещё раз**; двойной тап может дать дубль вопроса — это допустимо. После принятого ответа у сообщения снимается клавиатура (`editMessageReplyMarkup`; ошибка Telegram при этом не мешает потоку) и приходит следующий шаг; после последнего — сообщение с web_app-кнопкой «Начать первую тренировку» → `/twa/?from=onboarding`. При установке или смене часового пояса и времени напоминания (онбординг и `/settings`) `next_reminder_at` сразу пересчитывается: `LearnerClock::nextOccurrenceUtc(learner, reminder_time, now())` с новыми значениями (Ташкент 19:00 → 14:00 UTC; «без напоминаний» → `NULL`); завершение онбординга тоже выставляет его. Дальше его ведёт планировщик напоминаний (docs/12 §7.5). `OnboardingService::applySetting` сам проверяет, что онбординг завершён. События: `onboarding.started {source_kind}` (вид **принятой** атрибуции learner'а — `ref`/`src`/`course`/`org`/`group`; `direct`, если её нет: payload отвергнут — невалидный, самореферал, неизвестный/заблокированный реферер — или не было), `onboarding.step_completed {step}`, `onboarding.completed {goal, level, locale}`, `course.selected {course_id, via: onboarding|deeplink}`.

**Deep-link параметры** (`StartPayload::parse`, ≤ 64 символов `[A-Za-z0-9_-]`, иное — как «без параметра»): `f_<code>` (`[A-Z0-9]{6,12}` — реферальный код приглашающего в друзья; `acquisition_source = 'friend'`, реферала нет); `ref_<code>` (`[A-Z0-9]{6,12}` — `learners.referral_code` пригласившего; числовой `ref_<id>` Фазы 1 отменён, docs/13 §7.1) → `acquisition_source = 'ref'` + `referrer_learner_id`, а для learner'а, **созданного этим `/start`**, ещё и строка `referrals` (`ReferralService::link`, см. A.12); `src_<канал>` (`[a-z0-9_]{1,32}`) → `src:<канал>`; `c_<slug>` → `course:<slug>`, а курс (если опубликован и не private) становится `active_course_id` по окончании онбординга; `org_<код>` → `org` (инвайт центра; подтверждение вступления — см. `/start` выше). Атрибуция — first-touch: пишется, пока `acquisition_source` пуст (в том числе learner'у, созданному раньше через `/auth`); заполненное не перезаписывается. Курс без `c_`-ссылки — `CourseCatalogService::recommend(goal, level)` (опубликованные публичные курсы с `exam_tag = goal`: сначала нужный уровень, затем featured), если подходящего нет — курс по умолчанию. Если и курс по умолчанию недоступен (не опубликован / private), `active_course_id` остаётся `NULL`, а онбординг заканчивается сообщением без названия курса.

**Лимит входящих** (docs/12 §8): не более `telegram.inbound_rate_limit.max_messages` (30, env `TELEGRAM_INBOUND_MAX_PER_MINUTE`) сообщений и нажатий кнопок в минуту от одного `from.id` (`RateLimiter` `bot-in:<id>` в `TelegramDispatcher` — до хендлеров). Сверх лимита апдейт молча отбрасывается: ни ответа, ни записи в БД (в т.ч. `last_seen_at`); на callback отправляется только пустой `answerCallbackQuery` (best effort). Апдейты без `from` (channel posts) и платёжные сообщения (`successful_payment`, `refunded_payment` — B.11) лимитом не затрагиваются. Счётчик `/promo` (`bot-promo:`) отдельный.

### B.4 Формат сообщения с WebApp-кнопкой

```php
$telegram->sendWebAppButton(
    chatId: $group->chat_id,
    text: "📚 Тренировка: Урок {$lesson->number}\nНажмите кнопку, чтобы начать.",
    buttonText: '🎯 Открыть тренировку',
    url: "{$baseUrl}/twa/training/{$session->id}",
);
```

URL TWA: `https://lexiflow.example.com/twa/training/123` (для экзамена — `/twa/exam/{id}`).

### B.4a Язык сообщений бота (i18n)

Все тексты бота, напоминаний и лидерборда лежат в `lang/{ru,uz}/*.php` (`bot`, `commands`, `errors`, `training`, `exam`, `reminders`, `stats`, `twofactor`, `onboarding`) и выводятся через `__()` / `trans_choice()` с **явным** параметром `$locale` — глобальная локаль процесса не меняется (воркеры очередей долгоживущие). Поддерживаемые языки и дефолт — `config/localization.php` (`ru`, `uz`; дефолт и fallback `ru`). Выбор языка — `App\Domain\Localization\Services\LocaleResolver`:

| Кому сообщение | Откуда язык |
|----------------|-------------|
| Сообщение в группу (ответы на команды, кнопка WebApp, лидерборд) | `telegram_groups.meta['locale']` (`ru`/`uz`), если не задан или не поддерживается — дефолт (`ru`) |
| Learner'у в ЛС (онбординг, `/today`, `/settings`, меню, напоминание о повторении) | `learners.interface_locale` (`ru`/`uz`); если значение не поддерживается — по `learners.language_code`: `uz*` → `uz`, иначе `ru` |
| Учителю в ЛС (`/start` для привязанного учителя, `/stats`) | дефолт (`ru`) |
| Человеку в ЛС до выбора языка в онбординге (`/start`, `/help`) и неопознанному в `/stats` | `language_code` из апдейта по тому же правилу (`uz*` → `uz`, иначе `ru`) |

Числительные: ru — `trans_choice` с формами «слово | слова | слов» (1 / 2–4 / 5+, с учётом 11–14, 21, 22…); uz — без согласования («5 ta so'z»). HTML-сообщения (лидерборд) по-прежнему экранируют пользовательские данные (`htmlspecialchars`) — переводы содержат только статичную разметку. Язык группы задаётся в Filament (**Telegram Groups → Edit → Bot language**) и сохраняется при повторном добавлении бота в группу. Не переводятся: `message` в JSON-ответах TWA API (клиент локализует по `error.code`), логи, имена команд.

Новая строка бота = ключ в `lang/ru/*.php` **и** `lang/uz/*.php` (тест `LocalizationTest` сверяет наборы ключей и плейсхолдеров и запрещает кириллицу в PHP-коде `app/Domain/Telegram` и `app/Jobs`); каждая новая узбекская строка попадает и в `docs/I18N_UZ_REVIEW.md` (тест `UzReviewDocTest`).

Термины в текстах для учеников и учителей — словарь docs/14 §8: этап курса — «модуль» (uz «modul»), урок — «урок» (uz «dars»); порядок в uz — `:stage-modul, :lesson-dars` (`1-modul, 2-dars`). Слов `Stage`, `Lesson`, «Этап», «bosqich» в этих текстах нет.

### B.5 События бота (не команды)

- **`my_chat_member`** — бот добавлен/удалён из группы.
  - Added → создаём запись `telegram_groups` со статусом `pending`, уведомляем админов. Затем `GroupBotAccessChecker` спрашивает `getChatMember` про самого бота; если статус не `administrator`/`creator`, в группу (на языке группы) уходит `bot.group.bot_not_admin` — бот без прав админа не видит сообщения и состав участников. Регистрацию это не блокирует; ошибка вызова — warning в лог (без токена).
  - Removed → `status = 'disabled'`, лочим операции.
- **`message` в группе от `new_chat_members`** — в `new_chat_members` есть `id` бота или новых студентов.
  - Для каждого человека (не бота): `LearnerRegistrar::fromTelegramUser` (upsert `learners` по `telegram_user_id`, `acquisition_source = 'group'` у нового) и `GroupMembershipService::join` (`group_members.status = 'active'`; повторное вступление после выхода возвращает `active`). Событие `group.member_joined {via: group}`.
- **`chat_member`** — изменился статус участника группы (боту нужны права админа, `allowed_updates` уже включает `chat_member`).
  - `member` / `administrator` / `creator` / `restricted(is_member)` → `join` (событие `group.member_joined {via: chat_member}`).
  - `left` → `group_members.status = 'left'`, `kicked` → `'removed'` (+ `left_at`, событие `group.member_left`). Learner и его прогресс остаются; новый learner ради выхода не создаётся.
- **`message` в группе от `left_chat_member`** — служебное сообщение о выходе/исключении; приходит и когда бот **не админ** группы (тогда `chat_member` не приходит). Тот же `ChatMemberHandler`: `from.id` = `left_chat_member.id` → `left`, иначе (убрал другой) → `removed`; по `message.date`; бот и неизвестный learner игнорируются, повтор — no-op.
- **Callback query** — пока не используется (весь UX через WebApp), но хендлер на будущее.

### B.5a Персональные напоминания (`reminders:dispatch`)

Напоминание приходит в ЛС в **локальное** время learner'а (`learners.reminder_time` в `learners.timezone`, выбранные в онбординге / `/settings`; дефолт 19:00 Ташкент). Механика — docs/12 §7.5, docs/06 §6:

- `reminders:dispatch` каждые 15 минут: `ReminderDispatcher` выбирает `next_reminder_at <= now AND dm_blocked_at IS NULL AND blocked_at IS NULL AND deleted_at IS NULL`, для каждого делает условный `UPDATE … SET next_reminder_at = <следующее наступление по LearnerClock> WHERE id = ? AND next_reminder_at = <прочитанное>` и только при `affected = 1` ставит `SendLearnerReminderJob(learnerId, scheduledFor)`. Учитывается и learner с незавершённым онбордингом (у него дефолтные пояс и 19:00). Запоздавшие на > `REMINDERS_MAX_DELAY_MINUTES` (120) клеймы не отправляются — указатель всё равно уходит на следующее наступление. Backfill: у кого `reminder_time` есть, а `next_reminder_at` пуст, получают его тут же (до `REMINDERS_BACKFILL_LIMIT` = 10000 за запуск); job'ы идут в очередь `default` с разносом (`REMINDERS_SEND_RATE_PER_SECOND` = 25/с, задержка не больше 14 мин); новым learner'ам `LearnerRegistrar` ставит его при создании.
- Текст решает `ReminderContentResolver` в job: `due_count` (`DueRepetitionCriteria`, то же число, что на экране «Сегодня») `>= REMINDERS_DUE_THRESHOLD` (1) → `reminders.review` (trans_choice); иначе серия под угрозой (`StreakService::current().atRisk`) → `reminders.streak_at_risk` (`:count` = дней серии); иначе молчание. Кнопка `reminders.open_button` — web_app → `/twa/?from=reminder`: экран «Сегодня» сразу открывает `daily_review` с `source: 'reminder'`.
- Идемпотентность: клейм указателя (двойной запуск планировщика ничего не дублирует) + `notifications_log` (`review_reminder` / `streak_reminder`) под `Cache::lock` — не больше одного напоминания любого вида за **локальный день** learner'а и минимум `REMINDERS_MIN_INTERVAL_HOURS` (2) между любыми двумя. Строка лога пишется до вызова Telegram: после двусмысленной ошибки повтор job ничего не шлёт (не больше одного раза).
- Telegram 403 (`bot_blocked` / `not_started` / `user_deactivated`) или 400 `chat not found` (`chat_not_found`) → строка лога удаляется, `dm_blocked_at = now`, событие `reminder.failed {reason, kind}`, без повторов; learner выпадает из выборки. `my_chat_member` в личном чате: `kicked` → `dm_blocked_at = now`, `member` → `NULL` (learner не создаётся). **Любой** входящий `message` или `callback_query` из приватного чата (`/help`, `/stats`, `/promo`, кнопки, текст) снимает `dm_blocked_at` — `TelegramDispatcher` делает это до хендлеров одним условным UPDATE по `telegram_user_id`, без создания learner'а. Вход в TWA (`/auth`) флаг **не** снимает: открытие приложения не доказывает, что ЛС с ботом открыт.
- События: `reminder.sent {kind, due_count}`, `reminder.failed {reason, kind}`.

### B.6 Событие `update` от WebApp (опционально)

Если TWA вызывает `Telegram.WebApp.sendData(...)`, то в чат приходит апдейт типа `web_app_data` (только в приватных чатах). В нашем дизайне студент общается с API напрямую, а не через `sendData` — это упрощает масштабирование. Но оставляем возможность добавить лёгкие «отправки» (например, «поделиться результатом экзамена»).

### B.7 Rate limiting отправки сообщений
- Не более 20 сообщений в минуту в одну группу.
- Реализация через Redis-based sliding window в `TelegramGateway::sendMessage()`.
- Если лимит достигнут — кладём в очередь `default` с задержкой.

### B.8 Пример хендлера обработки обновлений

```php
// app/Telegram/Handlers/StartTrainingHandler.php
public function __invoke(Nutgram $bot, ?int $stage = null, ?int $lesson = null): void
{
    $group = $this->groupRepo->findActiveByChatId($bot->chatId());
    if (!$group) return; // GroupLock уже отсёк, но на всякий

    $teacher = $this->userRepo->findByTelegramId($bot->userId());
    if (!$group->isTaughtBy($teacher)) {
        $bot->sendMessage('Только учитель этой группы может запускать тренировку.');
        return;
    }

    // если параметры не переданы — показать выбор
    if ($stage === null || $lesson === null) {
        $this->showLessonPicker($bot, $group);
        return;
    }

    $session = $this->trainingService->open($group, $stage, $lesson, $teacher);

    $bot->sendMessage(
        text: "📚 Тренировка по Уроку {$lesson} запущена!",
        reply_markup: $this->buildWebAppButton($session),
    );
}
```

### B.9 Закрытие экзамена (scheduler)

```php
// app/Console/Commands/CloseExpiredExams.php
public function handle(ExamSessionService $service): int
{
    $expired = ExamSession::query()
        ->where('status', 'open')
        ->where('ends_at', '<=', now())
        ->lockForUpdate()
        ->get();

    foreach ($expired as $session) {
        $service->close($session);
        PostLeaderboardJob::dispatch($session->id)->onQueue('default');
    }

    return self::SUCCESS;
}
```

Регистрируем в `app/Console/Kernel.php`:
```php
$schedule->command('exams:close-expired')->everyMinute()->withoutOverlapping();
```

### B.10 Отправка лидерборда в группу

```php
// app/Jobs/PostLeaderboardJob.php — скелет
public function handle(TelegramGateway $tg): void
{
    $session = ExamSession::with('results.learner', 'group')->findOrFail($this->sessionId);
    $text = $this->formatLeaderboard($session);
    $tg->sendMessage($session->group->chat_id, $text, parseMode: 'HTML');
}

private function formatLeaderboard(ExamSession $session): string
{
    $rows = $session->results->sortBy('rank')->map(function (ExamResult $r) {
        $medal = match ($r->rank) { 1 => '🥇', 2 => '🥈', 3 => '🥉', default => "{$r->rank}." };
        return "{$medal} {$r->learner->first_name} — <b>{$r->total_score}</b>";
    })->implode("\n");

    // Заголовок — из lang/{ru,uz}/exam.php по языку группы (см. B.4a); имена экранируются.
    return __('exam.leaderboard.title', ['lesson' => $session->lesson->number], $locale)."\n\n{$rows}";
}
```

### B.11 Оплата Telegram Stars: `pre_checkout_query`, `successful_payment`, `refunded_payment` (docs/13 §4.1, 2A.3)

| Апдейт | Где обрабатывается | Что делает |
|---|---|---|
| `pre_checkout_query` | `WebhookController` → `PreCheckoutResponder` → `StarsPreCheckoutGuard` — **синхронно**, ≤ 10 с, без очереди | Один SELECT: заказ по `invoice_payload` (= `payment_orders.token`) вместе с learner'ом. `ok=true`, если заказ Stars, `pending`, не истёк, `from.id` = `learners.telegram_user_id` владельца, learner не заблокирован/удалён, `total_amount`/`currency` = заказ, провайдер включён. Иначе `answerPreCheckoutQuery(ok=false, error_message)` с текстом `billing.stars.precheckout.<reason>` (`order_not_found`, `foreign`, `blocked`, `order_closed`, `order_expired`, `amount_mismatch`, `provider_disabled`) на языке learner'а (чужому плательщику — на его Telegram-языке, без деталей заказа). Сбой проверки → `ok=false` + `unavailable`; HTTP-ответ всегда 200. Ничего не пишет. |
| `message.successful_payment` | `SuccessfulPaymentHandler` — первый в списке хендлеров, ЛС, Group Lock не применяется | `TelegramStarsProvider::parseSuccessfulPayment` → `PaymentApplier::applyCharge`: `telegram_payment_charge_id` — ключ идемпотентности и charge id, `invoice_payload` — заказ, `is_recurring && !is_first_recurring` — продление, `subscription_expiration_date` → `current_period_ends_at`. Ответ: `billing.stars.paid` / `renewed` / `paid_forever` («Pro до дд.мм.гггг» в поясе learner'а) + web_app-кнопка «Открыть LexiFlow» → `/twa/?from=payment`. Повтор апдейта — молча (один платёж). Заказ не найден → `recordOrphan` (`payments.status=failed`, алерт `billing.payment_orphan`); истёкший/оплаченный заказ или другая сумма → `failed`-строка; во всех этих случаях learner'у — `billing.stars.paid_unresolved` (поддержка, `/paysupport`), job не повторяется. Падение БД/Telegram — retry job'а. |
| `message.refunded_payment` | `RefundedPaymentHandler` — второй | `parseRefundedPayment` → `PaymentApplier::applyRefund` с `provider_event_id = refund:<charge id>`: строка `refund`, списание → `refunded`, подписка `expired`, если возвращён текущий период. Ответ — `billing.stars.refunded` («Возврат N ⭐ выполнен…»). Повтор и эхо админского возврата (`already_refunded`) — молча; неизвестный charge id — `Log::error('billing.stars.refund_unknown_charge')`. |

Исходящие вызовы (вне транзакций БД): `createInvoiceLink` (`CheckoutService::create`, после commit заказа), `editUserStarSubscription(user_id, charge_id первого списания = subscriptions.meta.stars.charge_id, is_canceled)` (`/pro` → `pro1:cancel|resume`, `POST /billing/subscription/cancel|resume` — до изменения подписки; отказ Telegram → `provider_unavailable`, подписка не меняется), `refundStarPayment(user_id, charge_id)` (Filament **Payments → Refund**), `getStarTransactions` (`billing:reconcile-stars`, ежедневно: расхождения с `payments` → `billing.reconcile_missing`, `billing.reconcile_missing_refund`). Тексты — `lang/{ru,uz}/billing.php`, ключи `billing.stars.*`.

### B.12 Напоминания о продлении (docs/13 §4.4, 2A.6)

`subscriptions:remind-expiring` (ежедневно 06:00 UTC) → `SubscriptionService::remindExpiring()` → событие `SubscriptionRenewalDue` → слушатель `QueueRenewalReminder` ставит `RenewalReminderJob` (очередь `default`, tries 3; n-е задание суток откладывается на `⌊n / learning.reminders.send_rate_per_second⌋` с). Напоминают за 3 и 1 день (`billing.checkout.renewal_reminder_days`) о конце периода Payme/Click и `canceled` Stars; `manual/promo/reward` и Stars `active` (автопродление) — никогда. `RenewalReminderSender`: подписка перепроверяется (конец периода тот же, ещё live, платный провайдер), иначе задание молчит.
- Learner: ЛС с web_app-кнопкой «Продлить Pro» → `/twa/billing?from=renewal`; `notifications_log.type = renewal_reminder`, `payload.key = "<subscription>:<N>@<конец периода>"`; не пишем при `blocked_at`/`dm_blocked_at`; 403 → `dm_blocked_at` и запись лога удаляется.
- Организация: письмо (`SubscriptionRenewalDueMail`, ru/uz) каждому активному владельцу (`organization_users.role = owner`, `users.is_active`) + ЛС с кнопкой-ссылкой на `/org` тем, у кого есть `users.telegram_user_id` (нет связи — только письмо); язык — `interface_locale` учётки-learner'а владельца, иначе `organizations.locale`, иначе дефолт. Приостановленная организация не получает напоминаний.
- Не более одного раза на (получатель, подписка, N, конец периода): маркер пишется до отправки (лог для learner'а, ключ кэша для владельца и e-mail); неоднозначная ошибка Bot API — молчим при повторе; сбой почтового транспорта снимает маркер письма и задание повторяется; сбой одного получателя не мешает остальным.

### B.13 Друзья и вызовы: кнопки и ЛС (docs/13 §7.5, 2C.5)
`callback_data` (префикс `fr1`, строгий regex `^fr1:(acc|dec|cacc|cdec|snd|no):[A-Z0-9]{1,18}$` + проверка аргумента: id — цифры, `snd`/`no` — реферальный код `[A-Z0-9]{6,12}`, id проверяется сервисом среди **своих** пар/вызовов — чужой id = «приглашение больше не действует»): `fr1:snd:<code>` / `fr1:no:<code>` — отправить запрос владельцу ссылки `f_<code>` / «не сейчас»; `fr1:acc:<friendship_id>` / `fr1:dec:<friendship_id>` — принять/отклонить запрос в друзья (адресату); `fr1:cacc:<challenge_id>` / `fr1:cdec:<challenge_id>` — принять/отклонить вызов. Кнопки работают только в личном чате самого нажавшего, для learner'а с завершённым онбордингом.

Фоновые ЛС (`SendFriendDmJob`, `ThrottleTelegramOutbound`, `tries = 0` + `maxExceptions = 3`, `retryUntil` +6 ч; состояние перечитывается — неактуальное не объявляется; `blocked_at`/`dm_blocked_at` — молчим; 403 → `dm_blocked_at`): «X хочет добавить вас в друзья» с кнопками (адресату запроса), «X принял приглашение» (отправителю запроса), «X бросает вам вызов» с кнопками (адресату), «X принял вызов» (бросившему), итог вызова — **два отдельных задания** (по одному на сторону): победа / поражение / ничья со счётом, на языке получателя. Повтор job'а после фактической доставки не дублирует ЛС: маркер в `notifications_log` (`type = friend_dm`, ключ `вид:id:сторона`) пишется до вызова Telegram.

### B.14 Баттл: ЛС с приглашением (docs/16 §8.7; задача 18.6b)
Практика (A.16) в боте ничего не меняет: у неё нет команд и ЛС. У баттла одно сообщение бота — **приглашение другу**; принять, отклонить и играть можно только в приложении (у бота нет команд и `callback_data` для баттла).
Итог матча, отказ и истечение приглашения в ЛС не приходят: оба игрока в приложении.

**Путь.** `BattleService::invite` (после commit, в том числе у реванша) → `SendBattleDmJob::send(BattleDmKind::Invited, battle_id)` (`dispatch()->afterCommit()`, очередь `default`; нужен рабочий воркер) → `BattleMessenger::notify` → `TelegramClient::sendWebAppButton`.
Job — по образцу `SendFriendDmJob`: middleware `ThrottleTelegramOutbound` (общий бюджет исходящих B.7), `tries = 0`, `maxExceptions = 3`, `backoff = 60`, `retryUntil` +1 час (у приглашения срок 5 минут, позже слать нечего).

**Когда молчим.** Мессенджер перечитывает состояние и ничего не шлёт, если матча нет, он уже не `invited` (отклонён, отозван, принят) или `invite_expires_at` прошёл, если адресата нет, он заблокирован (`blocked_at`) или его ЛС закрыт (`dm_blocked_at`), или инициатор пропал.
Просроченное, но ещё не закрытое дворником приглашение тоже не объявляется.

**Текст** (`lang/{ru,uz}/battle.php`, язык адресата — `LocaleResolver::forLearner`; новые uz-строки — `docs/I18N_UZ_REVIEW.md`, раздел «18-6b»):

| Язык | Сообщение | Кнопка |
|---|---|---|
| ru | `Dilnoza зовёт вас на баттл: Собери предложение, Английский A1 · Модуль 2 · Урок 4. Приглашение действует 5 минут.` | «Открыть баттл» |
| uz | `Bek sizni bellashuvga chaqiryapti: Gap tuzing, Ingliz tili A1 · 2-modul · 4-dars. Taklif 5 daqiqa amal qiladi.` | «Bellashuvni ochish» |

Состав: `battle.dm.invited` = «:name зовёт вас на баттл: :format, :place. :validity». `:format` — название формата (`battle.mode.*`: «Найди пары», «Подбери сочетание», «Собери предложение», «Слушай и пиши»; uz: «Juftlarni toping», «So'z birikmasini tanlang», «Gap tuzing», «Eshiting va yozing»);
`:place` — курс на языке адресата, для матча на урок — «курс · Модуль N · Урок M» (`battle.dm.lesson`; у урока без модуля — «Урок M»), для матча на весь курс — только курс; `:validity` — срок из `practice.battle.invite_ttl_seconds` словами (`trans_choice`: «1 минуту», «2 минуты», «5 минут»; число не вписано в текст). Без имени инициатора — «Ваш друг» / «Do'stingiz» (`battle.anonymous`).

**Кнопка.** Одна, `web_app` (ЛС — приватный чат, `web_app` допустим; `MiniAppLinks::forPath` не нужен), URL `{twa.base_url}/twa/battles/{battle_id}` (конечные `/` у `twa.base_url` отбрасываются) — SPA-маршрут `/battles/:id`, nginx отдаёт `index.html`. Нажатие открывает приложение на этом приглашении: «Принять» / «Отклонить».

**Идемпотентность.** До вызова Telegram пишется строка `notifications_log`: `learner_id` адресата, `type = battle_dm`, `payload = {"key": "invited:<battle_id>"}`. Повторный запуск job (ретрай после падения воркера, дубль) находит маркер и молча выходит — одно приглашение, одно сообщение.
**Отказы Telegram.** 403 или 400 `chat not found` (`TelegramForbiddenException`: бот заблокирован, диалог не начат, аккаунт удалён) → маркер **удаляется** (ничего не доставлено), у адресата `learners.dm_blocked_at = now()` (`DmLearnerResolver::markBlocked`, как у напоминаний), в лог — `telegram.battle_dm_forbidden` (warning: `learner_id`, `reason`), job завершается без повторов.
Любой другой сбой — исключение (ретрай, не больше 3 сбоев) **при оставленном маркере**: сообщение могло уйти, повтор не продублирует его. `dm_blocked_at` снимается, когда человек сам пишет боту (B.5a).
Кнопка не `immediate`: если у чата исчерпан лимит бота в секунду, `sendMessage` откладывает сообщение в `SendTelegramMessageJob` (очередь `high`), и 403 там только логируется (`telegram.deferred_message_forbidden`) — `dm_blocked_at` не ставится, а доставка может прийти после срока приглашения (приложение всё равно покажет его закрытым).
Приглашение видно и без ЛС: оно в `incoming` списка баттлов (A.17), в «Друзьях» и в хабе практики.

## Часть D — Вебхуки платёжных провайдеров (docs/13 §4)

Общее: без сессии/cookies/CSRF (как `/telegram/webhook`), `throttle:billing-webhook` 120/мин per IP, необязательный allowlist IP `BillingIpAllowlist:<provider>` (`PAYME_IP_ALLOWLIST` / `CLICK_IP_ALLOWLIST`, CSV, пусто = выключен; чужой IP → 403 до аутентификации), тело ≤ 64 КБ. Сумма заказа — только серверная (`payment_orders.amount`); провайдер знает лишь `token` заказа.

### D.1 Payme — `POST /billing/webhook/payme` (JSON-RPC 2.0, задача 2A.4)

Один URL для всех методов Merchant API. Аутентификация — `Authorization: Basic base64("Paycom:" + ключ)`, `hash_equals` с `PAYME_KEY` и, если задан, `PAYME_TEST_KEY` (песочница и прод различаются ключом). Ответ **всегда** `{jsonrpc: "2.0", id: <из запроса>, result | error}`; `error = {code, message: {ru, uz, en}, data?}`. HTTP 200 на любую протокольную ошибку; HTTP 500 + `-32400` только при сбое инфраструктуры (Payme повторит).

| Код | Когда |
|---|---|
| `-32504` | нет/неверный Basic (тело не разбирается) |
| `-32700` / `-32600` / `-32601` | не JSON / неверная форма `params` или тело > 64 КБ / неизвестный метод (`SetFiscalData`, `ChangePassword`) |
| `-31001` | `amount` ≠ `payment_orders.amount` (или не целое) |
| `-31003` | транзакция с таким `id` не найдена (Perform / Cancel / Check) |
| `-31007` | `CancelTransaction` после perform вне окна `PAYME_CANCEL_AFTER_PERFORM_DAYS` |
| `-31008` | состояние не позволяет: perform отменённой/просроченной (12 ч → state −1, reason 4), повторный Create с тем же `id` в state ≠ 1, Create с `time` старше 12 ч, заказ умер после Create |
| `-31050` / `-31051` / `-31052` (`data: order_id`) | заказ не найден / не `pending`, истёк, не Payme / другая открытая транзакция уже держит заказ |

| Метод | Делает | `result` |
|---|---|---|
| `CheckPerformTransaction {amount, account.order_id}` | заказ по `token`, проверки выше; просроченная чужая открытая транзакция закрывается (reason 4) | `{allow: true, detail?}` — `detail` (`receipt_type: 0, items[]` с ИКПУ) только при `PAYME_FISCAL_IKPU` |
| `CreateTransaction {id, time, amount, account}` | `ProviderTransactionService::open` под блокировкой заказа → state 1; повтор с тем же `id` → тот же ответ | `{create_time: <time>, transaction: "<наш id>", state: 1}` |
| `PerformTransaction {id}` | заказ FOR UPDATE → транзакция FOR UPDATE → `PaymentApplier::applyCharge` (`provider_event_id = <id>:perform`) → state 2; повтор → тот же `perform_time` | `{transaction, perform_time, state: 2}` |
| `CancelTransaction {id, reason}` | state 1 → −1 (заказ **остаётся `pending`**, можно платить снова); state 2 → `applyRefund` (`<id>:cancel`, подписка `expired`, если возвращён текущий период) → −2; повтор → сохранённый `cancel_time` | `{transaction, cancel_time, state}` |
| `CheckTransaction {id}` | только чтение | `{create_time, perform_time, cancel_time, transaction, state, reason}` (0 / `null`, пока события нет) |
| `GetStatement {from, to}` | все Payme-транзакции с `time ∈ [from, to]` | `{transactions: [{id, time, amount, account: {order_id}, create_time, perform_time, cancel_time, transaction, state, reason, receivers: null}]}` |

Checkout-ссылка (`PaymeProvider::createCheckout`, `checkout.kind = redirect`): `{PAYME_CHECKOUT_URL}/base64("m=<merchant>;ac.order_id=<token>;a=<тийины>;l=<ru|uz>;c=<APP_URL>/billing/return?order=<token>&lang=<l>")`. Страница возврата **`GET /billing/return?order=<token>&lang=<ru|uz>`** — публичная статическая страница статуса заказа (`paid` / `pending` / `expired` / `canceled` / неизвестно) с кнопкой в бот; `throttle:billing-return` 30/мин, `Cache-Control: no-store`, `noindex`, язык — `lang` → локаль заказа → `Accept-Language`. Деньги зачитываются только через `PerformTransaction`; возврат из кабинета Payme приходит как `CancelTransaction` по выполненной транзакции. Фикстуры запросов — `tests/Fixtures/billing/payme/`.

### D.2 Click — `POST /billing/webhook/click/prepare`, `/complete` (задача 2A.5)

Два form-encoded (`application/x-www-form-urlencoded`) вызова Click; читается только тело формы (JSON-тело / query-string → `-8`). Поля: `click_trans_id`, `service_id`, `click_paydoc_id`, `merchant_trans_id` (= `payment_orders.token`), `merchant_prepare_id` (только Complete), `amount` (UZS строкой, «29000.00»), `action` (0 / 1), `error`, `error_note`, `sign_time` (`YYYY-MM-DD HH:mm:ss`), `sign_string`. Подпись: Prepare `md5(click_trans_id + service_id + SECRET_KEY + merchant_trans_id + amount + action + sign_time)`, Complete `md5(click_trans_id + service_id + SECRET_KEY + merchant_trans_id + merchant_prepare_id + amount + action + sign_time)`; digest считается с **нашими** `CLICK_SERVICE_ID` и `CLICK_SECRET_KEY` и сравнивается `hash_equals` (чужой `service_id` → `-1`). Ответ **всегда** HTTP 200 JSON `{click_trans_id, merchant_trans_id, merchant_prepare_id | merchant_confirm_id, error, error_note}`; HTTP 500 + `-7` только при сбое инфраструктуры (Click повторит).

| `error` | Когда |
|---|---|
| `0` | успех |
| `-1` | `sign_string` не сходится / `service_id` не наш / `sign_string` не md5 |
| `-2` | `amount` ≠ `payment_orders.amount` (Prepare) или ≠ prepared (Complete), либо не десятичная строка с ≤ 2 знаками |
| `-3` | `action` не того эндпоинта |
| `-4` | заказ уже `paid` (новый Prepare; Complete второй транзакции после оплаты первой; `error<0` по выполненной транзакции) |
| `-5` | `merchant_trans_id` не найден или заказ другого провайдера |
| `-6` | Complete без Prepare: неизвестный `click_trans_id`, чужой `merchant_prepare_id` или `merchant_trans_id` не того заказа |
| `-7` | не смогли записать (HTTP 500) |
| `-8` | нет/битый обязательный параметр, тело > 64 КБ |
| `-9` | транзакция отменена: Complete с `error<0`, заказ `expired`/`canceled`/`failed` или за `expires_at`, Prepare/Complete по отменённой транзакции |

| Вызов | Что делает | Ответ |
|---|---|---|
| Prepare (`action=0`) | подпись → заказ по токену → `paid`/закрыт/сумма → `ProviderTransactionService::open(click, click_trans_id, exclusive: false)` state 1; повтор того же `click_trans_id` → та же строка; другой `click_trans_id` на тот же заказ — допустим | `merchant_prepare_id = provider_transactions.id` |
| Complete (`action=1`, `error=0`) | заказ FOR UPDATE → транзакция FOR UPDATE → `PaymentApplier::applyCharge` (`provider_event_id = <click_trans_id>:complete`, `provider_charge_id = click_trans_id`) → state 2; повтор → тот же ответ (`error: 0`, константа `REPEATED_COMPLETE_ERROR`) | `merchant_confirm_id = payments.id` |
| Complete (`error<0`) | state −1 (`cancel_reason = error`), заказ `pending` → `canceled`; повтор → −9; по выполненной → −4, ничего не откатывается | `error: -9` |

Checkout-ссылка (`ClickProvider::createCheckout`, `checkout.kind = redirect`): `{CLICK_CHECKOUT_URL}?service_id=&merchant_id=&amount=<UZS, два знака>&transaction_param=<token>&return_url=<APP_URL>/billing/return?order=<token>&lang=<ru|uz>[&merchant_user_id=]`. Страница возврата — та же, что у Payme (D.1). Уведомлений о возвратах у Click нет: возврат делается в кабинете Click и отмечается админом (**Payments → Refund**, «returned by hand», `provider_event_id = admin-refund:<payment id>`). Фикстуры форм — `tests/Fixtures/billing/click/`.

## Часть C — Примеры полного цикла

### C.1 Happy path тренировки (E2E)
1. Teacher в группе: `/start_training 4 8`.
2. Bot → `HandleTelegramUpdate` (queue) → `StartTrainingHandler` → создаёт `training_sessions` id=123.
3. Bot отправляет сообщение с WebApp-кнопкой на URL `.../twa/training/123`.
4. Learner нажимает кнопку → открывается TWA.
5. TWA: `POST /api/twa/auth` с `initData`.
6. TWA: `POST /api/twa/training/sessions/123/start`.
7. Цикл: `GET .../next` → показать карточку → `POST .../review` с `quality`.
8. Teacher в группе: `/close_training` (или тренировка просто продолжается — MVP не требует формального закрытия).

### C.2 Happy path экзамена
1. Teacher: `/start_exam 4 8 10`.
2. Создана `exam_sessions` со `ends_at = now() + 10 min`.
3. Сообщение с WebApp-кнопкой.
4. Learner через TWA: `join`, `question`, `answer` × N.
5. Scheduler через минуту после `ends_at` закрывает сессию, считает `exam_results`, диспатчит `PostLeaderboardJob`.
6. Бот постит лидерборд в группу.
