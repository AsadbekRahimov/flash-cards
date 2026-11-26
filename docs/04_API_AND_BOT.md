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
  },
  "account_restored": null,
  "client_timing": { "sample_percent": 10 }
}
```

- `account_restored` (R11b) — `null` почти всегда (и тогда лишних запросов нет). Если ученик удалил аккаунт, вернулся в течение 30 дней (этот самый `/auth`
  оживляет строку, docs/03 §8 п. 2) и ему ещё не сказали об этом — `{"pro_can_resume": bool}`: автопродление Pro, снятое при удалении, можно включить снова
  (то же `can_resume`, что в сводке подписки). Поле приходит при каждом `/auth`, пока не вызван A.2d (или бот не показал то же в `/start`); Mini App показывает лист
  один раз за сессию (docs/05).
- `client_timing.sample_percent` (0..100) — доля открытий, которые сообщают свои временные метки (A.21); из конфига
  (`CLIENT_TIMING_SAMPLE_PERCENT`), без запроса к БД. `0` — клиент ничего не шлёт. Нет поля (старый сервер) — тоже ничего.

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
      "reminder_times": ["08:00", "13:00", "19:00", "21:00"],
      "daily_goals": [10, 20, 40],
      "daily_goal_max": null
    }
  },
  "practice": { "available": true, "pro_required": false, "modes": ["pairs", "collocation", "sentence", "dictation"], "battle": true, "pro_promise": "both",
                "limits": { "sessions": { "limit": 3, "used": 1, "remaining": 2, "resets_at": "2026-10-30T19:00:00.000Z" }, "battles": { "limit": 1, "used": 0, "remaining": 1, "resets_at": "2026-10-30T19:00:00.000Z" } } },
  "game": { "available": true, "pro_promise": "full", "coins": 35, "tickets": 2,
            "limits": { "runs": { "limit": 1, "used": 1, "remaining": 0, "resets_at": "2026-10-30T19:00:00.000Z" }, "tickets": { "balance": 2, "max": 10 },
                        "boosters": { "limit": 1, "used": 0, "remaining": 1, "resets_at": "2026-11-01T19:00:00.000Z" }, "boss_stages": 1 } }
}
```

- `settings.options.daily_goal_max` (25.11.2026, docs/34 §2б.3) — наибольшая цель дня, которую разрешает тариф (`Entitlement::$dailyGoalMax`): у Free `10`, у Pro (личного, пробного, от центра) — `null` (без лимита). Список `daily_goals` остаётся полным; значения больше `daily_goal_max` Mini App показывает с замком и по нажатию открывает пейвол с причиной `daily_goal`. Если цель ученика больше лимита (Pro только что кончился), `GET /me`, `GET /today` и старт дневного раунда сами снижают её (`DailyGoalLimiter::enforce`, docs/06 §4.2), а при возвращении Pro возвращают прежнюю.
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
  `timezone` — текущий пояс learner'а (только чтение: с 2026-11-16 выбора пояса нет, новые learner'ы получают `learners.default_timezone` = `Asia/Tashkent`); `timezone_change_available_at` / `timezone_undo_available_until` остаются в ответе для совместимости (экран настроек их больше не использует);
  `active_course` — `null`, если курса нет или он стал недоступен; `active_course.title` — на языке learner'а; текущее время напоминания, которого нет в списке (выставлено через API), добавляется в конец списка. Списка `options.timezones` в ответе больше нет (2026-11-16). `options.locales[].label` — всегда на самом языке.
- `practice` (docs/16 §3.3, A.16) — доступ к практике; блок **всегда** есть в ответе (`PracticeAccess::describe`, без запросов к БД сверх кэша тарифа). `available` — входы в практику
  можно показывать: практика открыта ученику (`PRACTICE_ACCESS` = `all`, либо `pilot` и ученик в пилоте) **или** это Free-ученик при `PRACTICE_PLAN=pro` (вход виден с чипом Pro); при `false` TWA входов не рисует, а
  эндпоинты практики отвечают 403 `practice_unavailable`. `pro_required` — Free-ученик при `PRACTICE_PLAN=pro`: нажатие на вход открывает пейвол `practice`, сервер отвечает 403 `practice_pro_required`.
  `modes` — включённые режимы (`PRACTICE_MODE_*`), `battle` — переключатель баттла (`PRACTICE_BATTLE`). Блок есть и в других ответах формы `/me` (`PATCH /me/settings`, `POST /billing/subscription/cancel|resume`, `POST /promo/redeem`) — их собирает тот же `MePayloadBuilder`.
  `pro_promise` (docs/18 §3) — что о практике может сказать **этому ученику** список «что даёт Pro»: `both` — «Практика и баттлы без дневного лимита», `solo` — «Практика без дневного лимита», `none` — строки нет. Решает сервер, одно правило `ProPromises` на лендинг, счёт Stars и TWA; TWA ничего не выводит из `available` / `battle` (пока `/me` не пришёл — `none`). Правило: практика читающему не открыта (`available = false`) → `none`; иначе при `PRACTICE_PLAN=pro` (Free практики не имеет вовсе, Pro даёт её целиком) — `both`, при выключенных баттлах `solo`; иначе пока у Free есть лимит, который снимает Pro: баттлы включены и у Free есть лимит баттлов → `both`; есть лимит сессий → `solo`; иначе `none` (пустое значение `PRACTICE_FREE_*` — лимита нет, `0` — лимит есть). Чтение конфига, без запросов к БД.
  `limits` (G1, docs/16 §3.2–§3.3, docs/18 §3) — лимиты Free на сегодня: `sessions` — открытия сессий практики (один повтор ошибок сессии, открытой сегодня, не считается; второй повтор той же сессии, повтор повтора и повтор вчерашней сессии считаются; замена активной считается), `battles` — вызовы на баттл (приглашения и реванши, которые предложил ученик; принять вызов можно всегда). У каждого `limit`, `used`, `remaining`, `resets_at` (конец локального дня ученика, ISO 8601 UTC с миллисекундами; то же окно, что у квоты новых слов, с переносом дня после смены пояса).
  `null` — лимитов нет: Pro (фича `practice.unlimited`, в том числе спонсорский Pro центра), лимиты не заданы (пустые `PRACTICE_FREE_*`), либо практика ученику закрыта (`available = false` или `pro_required = true`). Вид без лимита — `null` внутри блока. Лимит `0` — вид только для Pro (`remaining: 0`). TWA показывает остаток и пейвол `practice_limit` при `remaining = 0`.
  Цена: у Free с открытой практикой `/me` стоит **на один SQL больше** (оба счётчика одним запросом); у Pro и при закрытой практике — ничего.
- `game` (docs/18 §12.7–§12.8, G2.1) — доступ к игре «Караван»; блок **всегда** есть в ответе и в других ответах формы `/me` (`MePayloadBuilder` → `GameAccess::describe`). `available` — входы в игру можно показывать (по умолчанию игра открыта всем с 2026-11-17): `GAME_ACCESS=all`, либо `pilot` и ученик в пилоте (флаг пилота практики `learners.practice_pilot_at` — одна аудитория); иначе `false`, TWA входов не рисует, а эндпоинты игры отвечают 403 `game_unavailable` (один код для «выключено» и «не в пилоте»). Режима «только Pro» у игры нет: бесплатная часть — продуктовое решение, лимиты Free — `limits`.
  `limits` (G2.8, docs/18 §12.7.2) — `null`, пока игра закрыта ученику; иначе объект: `runs` — забеги по карте (`stop` + `boss`) за локальный день (`limit`, `used`, `remaining`, `resets_at` — конец окна `LearnerClock::limitWindowUtc`, то же, что у практики), `null` у Pro (`game.unlimited_runs`) и при пустом `GAME_FREE_RUNS_PER_DAY`; `tickets` — `{balance, max}` (всегда; билеты копятся и у Pro); `boosters` — использования бустеров за локальную неделю с понедельника (`resets_at` — следующий понедельник; лимит плана: Free `GAME_FREE_BOOSTERS_PER_WEEK`, Pro `GAME_PRO_BOOSTERS_PER_WEEK`), `null` при пустом лимите; `boss_stages` — число первых городов, чьи боссы открыты Free, `null` у Pro (`game.all_bosses`) и при пустом лимите. Сверх дневного лимита забег стоит билет (`tickets.balance − 1`); без билета сервер отвечает 403 `game_limit_reached` (A.18), TWA показывает пейвол `game`. Ключи блока `game` в этом порядке: `available`, `limits`, `pro_promise`, `coins`, `tickets`. `pro_promise` — `full` | `none`: что о игре может сказать этому ученику список «что даёт Pro» («Караван без лимита забегов и все боссы»); `full`, пока игра открыта читающему (`available`) и у Free есть лимит, который снимает Pro (`GAME_FREE_RUNS_PER_DAY`, `GAME_FREE_BOSS_STAGES` или `GAME_FREE_BOOSTERS_PER_WEEK` не пусто; `0` — лимит есть), иначе `none` (`ProPromises::game`; TWA ничего не выводит сам, пока `/me` не пришёл — `none`).
  `coins` — баланс монет (`game_wallets.balance`), `tickets` — билеты (`game_profiles.tickets`); `0`, пока игра закрыта или ученик не играл (строки создаются лениво). Цена: пока игра открыта, `/me` стоит **на один SQL больше** (балансы и счётчики лимитов одним запросом, `GameLimits::state`); при закрытой игре — ничего.
- Цель дня и streak — в `GET /today` (A.2a), не в `/me`.

### A.2a `GET /api/twa/today`
Экран «Сегодня» (docs/12 §7.3): всё, что нужно главному экрану learner'а, за один запрос. `TodayService::summary`. Не «чистое чтение»: `StreakService::current()` может выполнить одиночный `UPDATE learners` (самопочинка отставшей проекции streak, docs/12 §7.4), поэтому метод нельзя вызывать внутри транзакции, держащей `training_sessions` / `word_repetitions` / `learner_daily_stats` (порядок блокировок docs/12 §3.1; важно для напоминаний 1.5b).

**Response:**
```json
{
  "due_count": 23,
  "new_words_remaining": null,
  "goal": { "target": 20, "done": 8, "met": false },
  "streak": { "current": 5, "best": 12, "met_today": false, "at_risk": true, "freezes": 1, "frozen_yesterday": false, "held_by": null },
  "active_course": {
    "id": 3, "slug": "english-a1", "title": "English A1", "section": "courses",
    "progress": { "learned": 120, "total": 900 },
    "next_lesson": { "id": 41, "stage": 2, "lesson": 4, "title": "Food", "words_left": 5 },
    "readiness": {
      "level_label": "B1", "percent": 31,
      "words": { "total": 1000, "learned": 310, "mastered": 120, "in_progress": 120, "not_started": 570 },
      "quiz": { "enabled": true, "week_start": "2026-11-16", "this_week": { "correct": 8, "total": 12, "percent": 66 } }
    }
  },
  "open_session_id": 918,
  "entitlement": { "plan": "free", "source": "free", "until": null, "lapsed": null },
  "onboarding_completed": true,
  "timezone": "Asia/Tashkent",
  "game": { "available": true, "daily_available": true, "active_run": null, "class_caravan": null },
  "practice": { "available": true, "pro_required": false, "modes": ["pairs", "collocation", "sentence", "dictation"], "battle": false, "pro_promise": "solo" },
  "hard_round": { "available": true, "reason": null, "words": 10, "open_session_id": null, "resets_at": null },
  "assignments_possible": false,
  "plan": {
    "step": 2, "done": false,
    "lesson": { "done": true, "lesson_id": 40, "next_lesson_id": 41, "words_left": 5 },
    "training": { "lesson_id": 40, "course": "english-a1", "title": "Напитки", "words": 5, "done": false, "open_session_id": null },
    "game": { "lesson_id": 40, "course": "english-a1", "mode": "pairs_timed", "played": false }
  }
}
```

- `due_count` — то же правило, что `words_due_today` в `/me` (`DueRepetitionCriteria`, A.2): слова, выведенные из курса (`is_active = false`), не считаются.
- `practice` **(с 17.11.2026)** — доступность практики ученику для карточки игр на «Сегодня»: те же поля, что `/me.practice` (docs/16 §3.3) **без** `limits` — `available`, `pro_required`, `modes`, `battle`, `pro_promise`; считается из конфига и уже разрешённого тарифа, запросов не добавляет (бюджет `/today` прежний). Клиенту больше не нужен `/me` ради этого признака; дневные лимиты Free по-прежнему только в `/me.practice.limits`.
- `open_session_id` — открытая `daily_review`-сессия, которую есть смысл продолжать: сессия, чей раунд уже пройден до конца (`completed_at`, A.3), сюда не попадает — «Начать занятие» откроет новый раунд.
- `new_words_remaining` — сколько новых слов осталось по квоте Free на сегодня; `null` = безлимит. Считает `NewWordQuota::remaining` (1.4b): лимит плана (`config/billing.php`, Free = 20) минус число новых карточек, выданных learner'у в **личных** сессиях за **локальный** день (`word_repetitions.created_at` в окне `LearnerClock::limitWindowUtc` по `learners.timezone` с переносом дня при смене пояса, `introduced_mode <> 'group_lesson'`), не меньше 0; слова групповых уроков не считаются (docs/12 §5.3). Только чтение, без блокировок. `null` — Pro (личный и спонсорский) без лимита.
- `goal` — цель дня: `target` = `learners.daily_goal` (оценки), `done` = `learner_daily_stats.reviews_count` за **локальный** день learner'а (`learners.timezone`, граница — локальная полночь, не UTC), `met` — цель достигнута (остаётся `true`, когда `done` растёт дальше).
- `streak` — `current` серия дней с выполненной целью **или завершённым забегом «Каравана»** (0, если пропущен день — обнуляется при чтении), `best` рекорд, `met_today` сегодняшний день уже удержан и засчитан (целью или забегом), `at_risk` = `current > 0 && !met_today` (серия оборвётся, если до локальной полуночи не выполнить цель и не завершить забег). **`held_by` (G2.10, docs/18 §12.6)** — чем удержан сегодняшний день: `goal` (цель; и когда был ещё и забег), `game` (только забег — Mini App пишет «Серия удержана забегом»), `freeze` (заморозка), `null` (не удержан; также пока `met_today = false` — поле согласовано с ним). Правила — docs/12 §7.4. **Заморозки (2C.2, docs/06 §6a):** `freezes` — сколько заморозок можно потратить (Pro; у Free всегда `0`, даже если в базе что-то осталось от прошлого Pro), `frozen_yesterday` — вчерашний день пропущен и удержан заморозкой. Сам запрос у Pro **пополняет** инвентарь (+1 за локальную неделю, до 2, `StreakFreezeService::refill`) и, если пропущен ровно вчерашний день, **тратит** заморозку (`current` сохраняется, `at_risk = true`, событие `streak.frozen`). Free видит тизер «заморозки — в Pro» (paywall reason `streak_freeze` в TWA).
- `active_course` — активный курс learner'а или `null` (курса нет или он уже недоступен — не `published` либо `private`, единое правило `CourseCatalogService::isAvailable`, — тогда экран предлагает выбрать курс). `title` на языке learner'а (fallback `ru`); `progress` — активные слова курса: `learned` (`repetitions >= 1`) из `total`. `next_lesson` — первый урок курса с ещё не показанными активными словами (источник новых слов `daily_review`), `null`, если показано всё; `id` — для `POST /training/sessions {mode: "lesson", lesson_id}`, `title` может быть `null`.
- `active_course.readiness` **(с 18.11.2026, docs/13 §8.5)** — готовность к уровню курса: `percent` = **пол** от `100 · learned / total`, где `learned` — активные слова курса в стадии «известно» или выше (`state = review` и `interval_days >= learning.stages.known_from_interval_days`, docs/06 §13.3), `mastered` — из них от порога «закреплено», `in_progress` = встреченные − `learned`, `not_started` = `total` − встреченные (не меньше 0). `level_label` — `courses.meta.level_label`, иначе `courses.level` (может быть `null` — клиент пишет «слов курса»). Это оценка словаря, а не прогноз экзамена: поля ничего не обещают. `quiz` — тест недели: `{enabled: false}`, если `READINESS_QUIZ_ENABLED=false`; иначе `week_start` (понедельник ISO-недели в поясе learner'а) и `this_week` — **зачётный** результат этой недели `{correct, total, percent}` или `null` (ещё не пройден). `readiness: null` при `READINESS_ENABLED=false`. **Бюджет запросов `/today`: 7 → 8** — готовность считается двумя суммами в уже существующем запросе строк learner'а по курсу (`CourseCatalogService::lessonsStarted`), 8-й — одно индексное чтение `level_quiz_results` (его нет при выключенных фиче/тесте); закреплено `TwaTodayMeQueryBudgetTest`. Кэша готовности нет.
- `open_session_id` — открытая и **неистёкшая** `daily_review` learner'а (продолжить); `null` — нет такой (просроченная, но не закрытая планировщиком, не считается).
- `entitlement` — `plan`, `source`, `until` и `lapsed` с теми же правилами, что в `/me` (A.2). Баннер «Pro закончился» использует этот ответ, поэтому отдельный запрос профиля при открытии «Сегодня» не нужен.
- `onboarding_completed` — `onboarding_state = done`. Экран работает и без онбординга (learner мог прийти из группы), но может подсказать завершить настройку в боте.
- `timezone` — IANA-пояс learner'а, в котором считаются локальный день, `goal` и `streak` (`learners.timezone`; именно тот, что берёт `LearnerClock`: пустое или неизвестное значение заменяется поясом по умолчанию `config('learners.default_timezone')`, затем UTC — поле всегда непустая строка). Берётся из уже загруженного learner'а и `LearnerClock`, лишних запросов к БД нет. По нему TWA датирует шапку «Главной» («вторник, 28 октября»; `formatDate(…, timeZone)`), а не по поясу устройства: `/me` экран «Сегодня» не запрашивает (docs/14 §6.1). После смены пояса в настройках (A.2b) поле меняется сразу.
- `game` (G2.3) — вход «Караван» на «Сегодня» (вторичное действие): `available` — игра открыта этому ученику (`GameAccess`: `off` → `false`, `pilot` → только участникам пилота, `all` → `true`; читает конфиг и поле ученика, без запросов; клиент решает по нему, рисовать ли вход, без запроса `/me`); `daily_available` — ежедневный караван можно начать (тот же `DailyCaravanService::state`, что у `daily.available` карты: не сыгран сегодня, нет идущего забега, `due_count > 0`); `active_run` — идущий и не просроченный забег `{id, kind, progress {answered, total}}` или `null`. Блок есть всегда; при закрытой игре (`GAME_ACCESS=off`, вне пилота) это `{available: false, daily_available: false, active_run: null, class_caravan: null}` **без запросов к таблицам игры**, при открытой — два запроса: к `game_runs` (`due_count` берётся из уже посчитанного) и карточка каравана класса `class_caravan` (G2.9b; `{id, stars_total, target_stars, ends_at}` или `null`; `null` при закрытой игре).

- `hard_round` **(с 18.11.2026, docs/06 §14)** — карточка «Раунд трудных слов»; ключ **есть всегда**, `null` только при `LEARNING_HARD_ROUND_ENABLED=false`. `available` — раунд можно открыть или продолжить;
  `reason` (когда `available = false`): `not_enough` (трудных слов ближайших дней — срок не позже `now + LEARNING_HARD_ROUND_DUE_WITHIN_DAYS` (2) дней — меньше `LEARNING_HARD_ROUND_MIN_WORDS`, 6) · `played_today` (в локальном дне уже был раунд; `resets_at` — ISO-8601 UTC с миллисекундами, конец окна дня) ·
  `no_objective_exercise` (недоступны и «Выбери перевод», и «Выбери слово»: выключены конфигом или учеником) · `disabled` не приходит (вместо блока `null`); `words` — размер раунда, который откроется (0 при `not_enough`; у живого раунда — размер его набора);
  `open_session_id` — живая сегодняшняя `hard_review`-сессия: клиент показывает «Продолжить» и идёт на её экран, иначе — «Разобрать» (`POST /training/sessions {mode: hard_review}`, A.3). Стоимость: кандидаты (по индексу `word_repetitions_hard_idx`) — `count(*)` в общем операторе скаляров экрана (`docs/19` §5.2 K10), не свой оператор; «сыграно сегодня» и живая hard-сессия читаются в том же операторе, что `open_session_id` ежедневной.
  Бюджет всего `GET /today` после K10: у Free с курсом 5 операторов при закрытой игре, 6 при `GAME_ACCESS=all`; таблица — `docs/19` §5.2.
- `assignments_possible` **(с 19.11.2026, аудит 18.11.2026 «Рабочая спецификация первого входа»)** — может ли `GET /assignments` (A.9) вернуть хоть что-то: `false` — **точно нет**, `true` — «возможно». Условие — у ученика есть активное членство (`group_members.status = active`) в группе, привязанной к центру (`telegram_groups.organization_id IS NOT NULL`), **при любом статусе группы и центра**. Это надмножество выборки `AssignmentService::forLearner` (активное членство + группа всё ещё привязана к центру задания + центр активен + задание активно; статус группы список не проверяет — задание видно и после удаления бота из чата), поэтому `false` никогда не прячет задание; `true` при приостановленном центре или без активных заданий — норма. Значение — из снимка «Сегодня» (`twa:today:v2`, docs/19 K10): на тёплом запросе **ни одного оператора**, на холодном — ещё один подзапрос `select 1 … limit 1` в общем операторе скаляров (число операторов прежнее). Версию снимка поднимает каждая запись, которая может превратить «нет» в «да»: вступление (`GroupMembershipService::join` — сообщение о входе, `chat_member`), привязка группы к центру (`TelegramGroupObserver`), а также выход/удаление/бан, отвязка, смена статуса и удаление группы. TWA при `false` не запрашивает `/assignments` и не держит экран загрузки; при `true` и при отсутствии поля (старый сервер) — прежний путь (docs/05, «Сегодня»).

- `active_course.section` **(с 26.11.2026, docs/35 §3.3 a)** — раздел каталога курса, как `section` в `GET /courses`: `phrases` (курс фраз, `exam_tag = phrases`) или `courses` (всё остальное); экран «Сегодня» открывает экраны фраз. Считается из строки курса, уже прочитанной (кэш строки курса), операторов не добавляет.
- `active_course.next_lesson.words_left` **(с 25.11.2026)** — сколько активных слов этого урока ученику ещё ни разу не показывалось («5 новых слов» на карточке урока); квота Free дня может выдать меньше (`new_words_remaining`). Считается из того же запроса прогресса по урокам, операторов не добавляет.
- `plan` **(с 25.11.2026, docs/31 §5б, макет `docs/design/2026-11-25-redesign`)** — план дня «урок → тренировка → игра» (`Learners\Services\DayPlanService`, DTO `DayPlan`). «Сегодня» — **локальный день** ученика (`LearnerClock::dayBoundsUtc`, тот же, что у `goal` и `streak`; граница — местная полночь). Ключ есть всегда.
  - **Урок дня** — урок последней личной сессии `lesson`, пройденной **до конца** сегодня (`completed_at` в локальном дне: сервер ответил `card: null`, в том числе на исчерпании квоты Free), в которой ученик **встретил новые слова сегодня** (строки `word_repetitions` урока созданы сегодня и уже оценены). Брошенный посреди урок («Завершить» раньше) — не пройден. Повторно пройденный старый урок без новых слов — не урок дня.
  - `step` — что делать сейчас: `1` — урок (урока дня нет, есть `next_lesson`), `2` — закрепление урока дня (`lesson_review`, A.3), `3` — игра на его словах; `null` — предложить нечего (цепочка выполнена, или урока дня нет и курс пройден / курса нет). `done` — цепочка дня выполнена.
  - `lesson` — `done` (урок дня есть), `lesson_id` (урок дня или `null`), `next_lesson_id` / `words_left` — то же, что `active_course.next_lesson.id` / `.words_left` (`null` без следующего урока).
  - `training` — `null`, пока урока дня нет или при `LEARNING_LESSON_REVIEW_ENABLED=false`; иначе `{lesson_id, course, title, words, done, open_session_id}`: `course` — slug курса урока дня (не обязательно активного: задание центра, курс сменён после урока; `null` — только если урока уже нет); `title` — название урока на языке ученика, если урок из активного курса (иначе `null` — клиент пишет «Урок»); `words` — сколько слов спросит закрепление (встреченные сегодня, не больше 20); `done` — закрепление этого урока сегодня пройдено до конца; `open_session_id` — живое незаконченное закрепление этого урока («Продолжить»). Кнопка — `POST /training/sessions {"mode": "lesson_review", "lesson_id", "source": "plan"}`.
  - `game` — `null`, пока урока дня нет или игра ученику недоступна (практика закрыта, за Pro или `pairs_timed` выключен); иначе `{lesson_id, course, mode: "pairs_timed", played}`: `course` — тот же slug курса урока, что у `training` (хаб практики открывается в нём: `/practice/{course}?lesson=…`, а не в активном курсе); `played` — сегодня была практика (любой режим) на этом уроке хотя бы с одним ответом. Кнопка — `POST /practice/sessions {"mode": "pairs_timed", "course", "lesson_id", "source": "deeplink"}` (A.16: короткий урок добирается выученными словами курса). Лимит Free практики (3 в день) действует: открытие может дать 403 `practice_limit_reached`.
  - **Стоимость — 0 операторов:** сессии дня (уроки, пройденные сегодня, и закрепления, начатые сегодня) читаются в том же операторе, что `open_session_id` и раунды трудных слов; слова урока дня и «играл ли» — три подзапроса в общем операторе скаляров (`plan_words`, `plan_game`, `plan_course` — slug курса урока по первичным ключам); `title` и `next_lesson` — из уже прочитанного блока курса. Бюджет `TwaTodayMeQueryBudgetTest` прежний; закреплено `DayPlanTest`.
  - Бот (напоминание после урока, §5б G1): `DayPlanService::forLearner($learner)->offersGame()` — урок дня есть, игра доступна и сегодня не сыграна (2–3 оператора).
- `score_today` **(с 26.11.2026, docs/32 §8.2, S12)** — «Сегодня N из 100» очков за слова: **ключа нет** при `LEAGUES_SCORING_MODE=off`/`shadow` и пока оба зачёта рейтингового дня на v1 (`live` до `starts_on`). Иначе
  `{points, cap, category, new: [использовано, лимит], review: [использовано, лимит], remaining, day_ends_at, month}`: недельный зачёт рейтингового дня (день — по Ташкенту, не локальный день ученика), `cap` — потолок категории (Free 100, Pro 150), `remaining = cap − points` (не меньше 0), `day_ends_at` — полночь рейтингового дня по зоне лиг (ISO-8601 UTC: «рейтинговый день закончится в 22:00»), `month` — месячный зачёт той же формы без `day_ends_at`, **только** если он отличается от недельного (другая категория или сумма), иначе `null`. Нет начислений сегодня — нули с категорией, уже зафиксированной первым начислением недели / месяца (`learner_score_periods`), иначе категорией текущего тарифа.
  **Стоимость — 0 операторов:** строка `learner_score_days` (unique `(learner_id, rating_day)`, одной строкой-конкатенацией), периоды ученика (PK `learner_score_periods`) и версии строк недели и месяца (unique `starts_on`) — пять подзапросов в общем операторе скаляров (`ScoreTodayService::addScalars`); вне `live` подзапросов нет. `TwaTodayMeQueryBudgetTest` прежний; равенство числа операторов в `off` и `live` закреплено `LeagueApiV2Test`.

Кнопка «Повторить» — `POST /training/sessions {"mode": "daily_review", "source": "self"}` (A.3). Ошибки: 401 (как у всех маршрутов TWA).

### A.2c `POST /api/twa/me/delete` — «Удалить аккаунт»

Тело `{"confirm": true}` (`DeleteAccountRequest`: без подтверждения — `422`, ничего не меняется). Мягкое удаление ученика
(`LearnerDeletionService::delete`, решение владельца 03.11.2026): `learners.deleted_at`, аудит `learner.deleted {by: self}` (без PII), у Stars сразу
снимается автопродление (best-effort; оплаченный период и платежи не трогаются). Ответ `200` (`AccountDeletedResource`):
`{deleted: true, restorable_until}` — ISO-8601, `deleted_at + learners.purge.after_days` (30). Токен перестаёт работать сразу: любой следующий запрос с ним —
`401 learner_not_found` (`TwaAuth`), повторное удаление — тоже. Вернуться до `restorable_until` можно через `/start` в боте или новый запуск Mini App. Для восстановления через `/auth` подписанный Telegram `auth_date` должен быть позже `deleted_at`, чтобы фоновое обновление токена из уже открытого приложения не отменило удаление. Вернувшемуся один раз покажут «Аккаунт восстановлен» (`/auth.account_restored`, A.2d).
Когда срок восстановления истекает, `learners:purge-deleted` стирает данные окончательно (docs/03 §8 п. 2). Ограничение — `throttle:twa-api`.

### A.2d `POST /api/twa/me/account-restored/seen` — «аккаунт восстановлен» показан

Без тела. Всегда `204 No Content`, идемпотентно (`AccountRestoreNotice::consume`: ключ `learners.meta.restored_notice_at` снимается под блокировкой строки; повтор или уже
показанное ботом — тоже `204`). После вызова `/auth` отдаёт `account_restored: null`, а `/start` в боте молчит. Ограничение — `throttle:twa-api`.

### A.2b `PATCH /api/twa/me/settings`
Частичное изменение настроек (docs/12 §3.8); `LearnerSettingsService::update` — то же место, через которое идёт `/settings` в боте. Меняются только переданные поля,
лишние поля игнорируются, пустое тело — no-op. Ответ — тот же JSON, что у `GET /me` (A.2), уже с новыми настройками (подписи в новом языке).

| Поле | Правило |
|---|---|
| `interface_locale` | `ru` \| `uz` |
| ~~`timezone`~~ | **убрано 2026-11-16 (решение владельца):** правила нет, поле игнорируется — `FormRequest::validated()` отдаёт только поля с правилом, поэтому старый закешированный клиент, приславший `timezone`, получает `200` (остальные поля сохраняются), а пояс не меняется |
| `reminder_time` | `HH:MM`, минуты кратны 15 (`00/15/30/45`); `null` — выключить напоминания |
| `daily_goal` | целое (не строка) из `config('learners.daily_goal_options')`: 10 / 20 / 40. Значение не из списка — `422`. Значение больше лимита тарифа (`settings.options.daily_goal_max`, у Free 10) — **`403 daily_goal_pro_required`** `{"error": {"code": "daily_goal_pro_required", "message": "…", "details": {"max": 10}}}`, ничего из запроса не применяется (формат — как у `practice_limit_reached`; Mini App открывает пейвол `daily_goal`). Любой принятый `daily_goal` (даже равный текущему) стирает запомненную до снижения цель (`learners.daily_goal_before_limit`): ручной выбор важнее автоматики, при покупке Pro цель не вернётся |
| `goal`, `level` | как в онбординге: цель — одна из **включённых** (`config('learners.goals')`, env `LEARNERS_GOALS`, по умолчанию только `general`; выключенная цель → 422, сохранённая у learner'а не меняется), `A1`..`B2` |
| `exercises` | объект `{meaning, reverse, audio, spelling, gap_fill}` со строгими булевыми (2D.2, docs/13 §8.2; `meaning` — docs/06 §12.1); **частичный** — меняются только переданные ключи; другие ключи / не-булевы → 422. Хранится в `learners.meta.exercises`; flash выключить нельзя. В ответе `settings.exercises` — всегда все пять ключей (по умолчанию `true`). Выключенный тип селектор не выдаёт; `spelling`/`gap_fill` у Free и так не выдаются (Pro-features) — настройка хранится и действует после покупки |

| `region_code` *(25.11.2026, docs/35 R4)* | код из `config/regions.php` (`UZ-TK` … `UZ-XO`, `other`) или `null` («не указывать»). Правила — `LearnerRegionService`: первый выбор — сразу; другой регион — не раньше чем через 30 дней после прошлого выбора, иначе **`422 region_change_too_soon`** `{"details": {"available_at": "<ISO-8601 UTC>"}}` и ничего из запроса не применяется; сброс в `null` — всегда, но не обнуляет срок (следующий выбор после сброса ждёт те же 30 дней). Событие `region.set {from, to, via}` |
| `region_via` | необязательно: `settings` (по умолчанию) \| `prompt` — откуда выбран регион (карточка на вкладке «Рейтинг»); только для события, не настройка |
| `pro_emoji` *(25.11.2026, docs/31 §5а E1)* | **код** эмодзи из `config/pro_emoji.php` (набор и порядок — `settings.options.pro_emojis`) или `null` (снять). Другой код / сам символ / текст — `422`. Код без действующего Pro — **`403 pro_emoji_pro_required`**, ничего из запроса не применяется (Mini App открывает пейвол); `null` можно всегда |

Невалидное значение — `422` (стандартный ответ валидации Laravel), ничего не меняется. Активный курс меняется отдельно — `POST /me/course` (A.5a).

**Регион и эмодзи в ответе (`GET /me` и все ответы его формы).** `settings` += `region_code`, `region_change_available_at` (ISO-8601 UTC, когда можно выбрать другой регион; `null` — сейчас), `region_undo_available_until` (до какого момента можно отменить последнюю смену; `null` — нельзя), `region_prompt` (bool: показывать карточку «Укажите регион» — региона нет и «Не сейчас» не нажимали 30 дней), `pro_emoji_choice` (сохранённый код, есть ли Pro или нет — Free видит выбор как плюс Pro); `settings.options` += `regions` (`[{code, name}]`: 14 регионов Узбекистана в порядке языка — сначала город Ташкент, — затем `other`), `pro_emojis` (`[{code, emoji}]`), `pro_emoji_locked` (bool: выбор закрыт до Pro). `learner` += `pro_emoji` — **сам символ** выбранного эмодзи, только пока у ученика действующий Pro, иначе `null`.

**Отмена смены региона — `POST /api/twa/me/settings/region/revert`** (без тела). В течение 10 минут после принятой смены (и после сброса) возвращает прежний регион и прежний `region_changed_at`; ответ — как у `GET /me`. Позже, без смены или повторно — `422 region_revert_unavailable`. Бот: под «Сохранено: ….» (до 26.11.2026 — «Регион: …. Сохранено.»; область, решение владельца) в `/settings` — кнопка «↩️ Отменить» (`set1:rgundo`).

**«Не сейчас» карточки региона — `POST /api/twa/me/region-prompt/dismiss`** (без тела): `204`, идемпотентно; карточка скрыта 30 дней (`learners.meta.region_prompt_dismissed_at`).
**Часовой пояс (механика осталась в `LearnerSettingsService`, но клиента, который её вызывает, больше нет — 2026-11-16):** смена не чаще раза в 24 ч (docs/12 §5.3): новое значение, отличное от текущего, раньше срока → `TimezoneChangeTooSoonException` (в прежнем HTTP-виде — `422` в общем конверте ошибок
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
  "mode": "lesson",          // lesson | daily_review | hard_review | lesson_review (group_lesson открывает только учитель из бота)
  "lesson_id": 321,          // обязателен для lesson и lesson_review (integer ≥ 1), запрещён для daily_review и hard_review
  "source": "picker"         // optional: self (по умолчанию) | picker | reminder | reminder_quiz | onboarding | assignment | finish (кнопка на итоге занятия) | plan (план дня на «Сегодня»); записывается только в НОВУЮ сессию
}
```

**Response (200, всегда — и для новой, и для уже открытой сессии):** ровно как у `/start` ниже (`session_id`, `mode`, `started_at`, `lesson`,
`total_words`, `card`, `progress`, `done_reason`). Клиент после ответа переходит на экран тренировки по `session_id`
(экран снова вызывает `/start`, это идемпотентно: первая карточка та же).

**Какая сессия возвращается (с 17.11.2026, docs/06 §13.2).** Открытая `daily_review`-сессия возвращается как есть, пока в ней есть карточки
и она начата в текущем локальном дне ученика. Сессия, чей раунд **пройден до конца** (сервер уже отвечал `card: null` после её оценок —
`training_sessions.completed_at`), либо начатая **до локального «сегодня»**, не возвращается: она закрывается здесь же (`finished` с теми же
побочными эффектами, что у `/finish` — событие `training.session_finished`, проверка реферала; незаконченная вчерашняя — `expired`, как закрыл бы
планировщик) и открывается новая. Поэтому «Начать занятие» после закрытого на вердикте Mini App всегда даёт новый раунд, а `/finish` старой сессии
остаётся идемпотентным (200 с её итогом, без второго события). Урочная сессия (`lesson`) возвращается как есть в любом случае.

**Ошибки:** 422 валидации Laravel (`mode` вне `lesson`/`daily_review`/`hard_review`/`lesson_review`, `lesson_id` не integer, `source` вне списка) →
422 `lesson_mismatch` (`lesson` без `lesson_id` или `daily_review` с ним) → 404 `lesson_not_found` (урока нет **или** его курс
не `published` — статус курса не раскрывается) → 429 (лимит `twa-session-create`, 10 запросов/мин на learner'а).
(`invalid_mode` из сервиса при HTTP недостижим — режим отсекает валидация; код оставлен в списке для клиента.)

Побочный эффект: событие `training.session_opened` `{training_session_id, mode, source, reused}` (`reused = true`, если вернулась уже открытая сессия).

**`mode: hard_review` — раунд трудных слов (с 18.11.2026, docs/06 §14).** Открывает `HardRoundService::open`: набор 6-10 слов фиксируется при открытии (`training_sessions.meta.hard_words`), слова притягиваются к «сейчас»
(`next_review_at`, без `is_hard`); не чаще `LEARNING_HARD_ROUND_PER_DAY` (1) раза в локальный день. Ответ 200 — как у `/start`: `mode: "hard_review"`, `lesson: null`, `total_words` = размер набора, `card` (только объективные
упражнения, **никогда** `flash`, кроме редкого понижения для слова без трёх вариантов), `progress: {done: побеждено, total: размер набора}`, `quota: {new_words_remaining: null}` (новых слов нет, квота Free не тратится).
Идемпотентно для живой сегодняшней сессии (две вкладки — одна сессия). Ошибки сверх общих: **409 `hard_round_unavailable`** с `error.details {reason: disabled | not_enough | played_today | no_objective_exercise, resets_at}`
(`resets_at` только при `played_today`, иначе `null`); 422 `lesson_mismatch`, если передан `lesson_id` (урок не проверяется на существование); 429 `twa-session-create`. Событие отказа — `hard_round.refused {reason, source}`.

**`mode: lesson_review` — закрепление урока (с 25.11.2026, docs/06 §15; шаг 2 плана дня, A.2a `plan`).** Открывает `LessonReviewService::open`. Слова — слова урока `lesson_id`,
встреченные учеником **сегодня** (локальный день; строка `word_repetitions` создана сегодня и уже оценена), не больше 20, набор фиксируется при открытии (`meta.lesson_words`).
Каждое слово спрашивается, пока не будет верного ответа, но не больше 3 раз; ошибочное возвращается после остальных. Только `meaning` / `reverse` / `audio` (с файлом), `flash` —
лишь как запасной для слова без трёх вариантов. **Режим ничего не меняет вне себя:** расписание (`word_repetitions`), `training_reviews`, `learner_daily_stats` —
поэтому нет баллов лиги, цели дня, серии, достижений; квота Free не тратится. Ответ 200 — как у `/start`: `mode: "lesson_review"`, `lesson` — заголовок урока,
`total_words` = размер набора, `progress: {done: слова с верным ответом, total: размер набора}`, `quota: {new_words_remaining: null}`, `card.card_kind` — по состоянию строки (обычно `due`),
`card.serve_token` — **всегда строка**: одноразовый токен показа, клиент возвращает его в `/review` (как у learn-ahead). Повторное открытие того же урока возвращает живую сессию (та же карточка и токен);
пройденная до конца закрывается (`finished`) и открывается новая — режим можно проходить сколько угодно раз, он ничего не начисляет; открытая сессия другого урока / вчерашняя закрывается `expired` (одна открытая на ученика).
Ошибки сверх общих: **422 `lesson_mismatch`** без `lesson_id`; **404 `lesson_not_found`** — урока нет или он ученику недоступен (то же правило, что у `lesson`); **409 `lesson_review_unavailable`**
с `error.details {reason}`: `no_new_words` (сегодня ни одного слова этого урока не встречено — урок не пройден сегодня или пройден давно), `no_objective_exercise` (недоступны и «Выберите перевод», и «Выберите слово»),
`disabled` (`LEARNING_LESSON_REVIEW_ENABLED=false`). `/next`, `/review`, `/finish` — общие маршруты; отличия: `/review` — 409 `card_not_due`, если `serve_token` не того показа (повтор запроса, вторая вкладка),
422 `out_of_scope` — слово не из набора; ответ `/review` — `next_review_at` / `new_interval_days` / `easiness_factor` строки **без изменений** (расписание не двигалось), `graded` и `revealed` — как у `meaning`/`reverse`.
`done_reason = lesson_review_complete` — все слова набора отвечены верно или исчерпали 3 показа. Итог `/finish`: `reviewed` — ответы, `correct` — верные, `new_words: 0`, `stages` нули,
**`league_points: 0`**, `hard_round_offer: null`, `practice_entry` — урок (кнопка игры, шаг 3), `lesson_review: {"total": 5, "won": 4, "first_try": 3}` (слова набора, отвеченные верно; верно с первого показа).
Событие `training.session_finished` — как у других режимов (`mode: "lesson_review"`).

#### `POST /api/twa/training/sessions/{session_id}/finish`
Learner завершает **свою личную** сессию (`status = closed`, `ended_at`, `done_reason = finished`) и получает итог по ней.
Идемпотентно: повтор отвечает 200 с теми же числами и не меняет `ended_at`/`done_reason` (в том числе у сессии, уже закрытой
планировщиком с `expired`). Порядок проверок: 404 `session_not_found` → 403 (`forbidden` — чужая личная сессия; `not_a_member` / `group_inactive` — групповая сессия, к которой нет доступа) → 403 `forbidden` для
**любой** групповой сессии (её закрывает учитель/планировщик; сервис при прямом вызове отвечает `not_personal`). Закрытая
сессия — не 410: здесь 410 нет.

**Response:**
```json
{
  "started_at": "2026-11-17T05:00:00+00:00",   // серверное время начала сессии, ISO-8601 UTC (с 17.11.2026): по нему экран итога решает, что получено «в этой сессии»
  "reviewed": 14, "correct": 11, "new_words": 4,
  "stages": { "graduated": 3, "promoted": 2, "mastered": 1 },
  "goal": { "target": 20, "done": 14, "met": false },
  "streak": { "current": 5, "best": 12, "met_today": false, "at_risk": true, "freezes": 1, "frozen_yesterday": false, "held_by": null },
  "practice_entry": { "course": "english-a1", "lesson_id": 41 },
  "league_points": 35,
  "hard_round": null,
  "corrected": null,
  "hard_round_offer": { "available": true, "reason": null, "words": 10, "open_session_id": null, "resets_at": null }
}
```
**С 19.11.2026 (docs/06 §14, аудит 18.11 §3):** `corrected` — одна исправленная ошибка на итоге раунда трудных слов, иначе `null` (ключ есть всегда; старые клиенты его не читают):
`{"word_id": 41, "word": "borrow", "translation": "занимать", "mistaken_at": "2026-11-13T07:30:00+00:00", "answer": "borow"}`. Слово сессии `hard_review`, чья ПЕРВАЯ оценка в сессии —
объективное упражнение (не `flash`, не `pretest`) с `quality >= 3`, а в более ранней сессии (id оценки меньше первой оценки этой сессии) была объективная оценка `< 3`; из таких — с самой поздней прошлой ошибкой.
`translation` — на языке ученика (`WordTranslationResolver`, запасной — ru), `mistaken_at` — время той ошибки (ISO-8601 UTC), `answer` — что ученик тогда ВВЁЛ (только `spelling` / `gap_fill`; не пустое и не само слово), иначе `null`.
Стоимость — +1 оператор только у `/finish` сессии `hard_review` (`CorrectedMistakeFinder`; перевод тёплый — карточка выдавалась в этом раунде); у остальных режимов оператора нет.
**С 25.11.2026 (docs/06 §15):** `lesson_review` — `{"total", "won", "first_try"}` у сессии `lesson_review`, `null` у остальных режимов (ключ есть всегда); у `lesson_review` `league_points` всегда `0`.
**С 18.11.2026 (docs/06 §14):** `hard_round` — `{"total": 10, "won": 8}` у сессии `hard_review` (`won` — слова набора, чья ПОСЛЕДНЯЯ оценка в сессии ≥ 3), `null` у остальных режимов; `hard_round_offer` — приглашение на раунд трудных слов
на итоге ежедневного занятия / урока: тот же блок, что `hard_round` в `GET /today` (A.2a), `null` у самого раунда трудных слов и при `LEARNING_HARD_ROUND_ENABLED=false`. Стоимость `/finish` — +2 оператора (сессии окна дня и
кандидаты). Событие `training.session_finished` у `hard_review` получает `hard_won`, `hard_total`; `practice_entry` у него `null`.
`league_points` — баллы лиг / месячного рейтинга, которые принесло занятие (A.19, docs/13 §7.6): `reviewed×1 + correct×1 + new_words×2` + 10, если цель дня была достигнута во время этой сессии; считается по числам ответа, запросов не добавляет.
**С 26.11.2026 (docs/32 §8.2, S12): `score_session`** — очки за слова занятия; **ключа нет** вне `live`, у `lesson_review`, у сессии без ответов и пока оба зачёта дня на v1. Иначе `{earned, week_added, month_added, week_rating_delta, capped_off, today}`: `earned` — очки начислений сессии по правилам (новое 2, повтор 3, длинный 5) до лимитов («За занятие +70»), `week_added` / `month_added` — что реально получили недельный и месячный зачёты (`score_credits.week_points` / `month_points`, аннулированные не считаются), `week_rating_delta` — на сколько выросла сумма лучших дней недели (docs/32 §3.5: лучшие дни сейчас минус лучшие дни без очков сессии — «в недельный рейтинг +40»), `capped_off` = `earned − week_added` (при неделе v1 — `− month_added`): срезано лимитами дня, `today` — рейтинговый день после занятия в форме `GET /today.score_today` (`null`, если сегодня начислений нет). В неделе v2 `league_points` = `week_added`. Финиш ничего не начисляет: повтор отдаёт те же числа. Стоимость — **+2 оператора только в `live`** у сессии с ответами: начисления сессии (`score_credits` по unique `(learner_id, rating_day, lexeme_id)` за дни сессии, фильтр `source_id`, группировка по дню и виду) и дни текущей недели (`learner_score_days`, ≤ 7 строк по unique); в `off` — 0.
**С 17.11.2026 (docs/06 §13.3):** `+ "stages": { "graduated": 5, "promoted": 2, "mastered": 1 }` — рост слов за сессию: `graduated` — новые слова
сессии, вышедшие из минутных шагов (ступень `learning`); `promoted` — слова, встреченные раньше, чья ступень выросла (`new → learning → known → mastered`,
пороги 7 и 21 день интервала); `mastered` — слова, ставшие выученными в этой сессии. Считается тем же одним оператором, что `reviewed`/`correct`/`new_words`.
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
`done_reason = expired`, `ended_at` = момент закрытия. **Исключение (с 17.11.2026):** личная сессия, пройденная до конца, но без `/finish`
(`completed_at` стоит — сервер отвечал `card: null` после её оценок), закрывается планировщиком как `finished` — в отчёте «Удержание» она
не «брошена»; событий при этом нет (итог ученику не показывался).

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
  "started_at": "2026-11-17T05:00:00+00:00",   // серверное время начала сессии, ISO-8601 UTC (с 17.11.2026); то же в итоге /finish
  "lesson": { "stage": 4, "lesson": 8, "title": "Resilience" } | null,   // null для daily_review
  "total_words": 25,         // lesson/group_lesson: активные слова урока; daily_review: размер раунда (= progress.total, docs/06 §13.2; без спринта — learners.daily_goal)
  "card": { "word_id": 1001, "word": "Resilient", "translation": "Устойчивый", "translation_fallback": false, "example": null, "example_translation": null, "example_audio_url": null, "transcription": null, "audio_url": null, "card_kind": "new", "serve_token": null, "prompt": { "type": "flash", "front": "Resilient", "masked_example": null, "options": null, "answer_length": null } } | null,
  "progress": { "done": 0, "total": 25 },
  "done_reason": null,       // см. ниже; не null ровно когда card = null
  "can_continue": false,     // true только с done_reason = sprint_complete: «Ещё раунд» (docs/06 §13.2)
  "quota": { "new_words_remaining": 9 }    // см. «Квота новых слов» ниже; null = безлимит (Pro)
}
```

Для `prompt.type` = `meaning` / `reverse` / `audio` карточка не содержит того, что выдаёт правильный вариант: `reverse` — `word` = `""`, `example`, `example_translation`, `example_audio_url`, `transcription`, `audio_url` = `null`; `meaning` и `audio` — `translation` = `""`, `example`, `example_translation`, `example_audio_url` = `null` (у `meaning` слово, транскрипция и `audio_url` на месте — это вопрос); у всех `hook`, `note`, `confused_with` = `null`. Всё это приходит в `revealed` ответа `/review` (ниже).

Для `prompt.type` = `spelling` / `gap_fill` карточка не содержит ответа: `word` = `""`, `example`, `example_translation`, `example_audio_url`, `transcription`, `audio_url` = `null` (перевод предложения с пропуском тоже выдал бы ответ; правильный ответ — в `graded.expected` ответа `/review`).

**Фраза** (docs/35 §3.3, docs/06 §16; выпуск 7): `card.phrase` = `{ "kind": "idiom", "register": "informal", "variant": "both", "literal": "кусок торта" | null }`
у карточки с `part_of_speech = phrase`, `null` у слова. `kind` — `formula`/`fixed`/`idiom`/`phrasal_verb`/`proverb`, `register` —
`neutral`/`informal`/`formal`/`slang`/`rude`, `variant` — `both`/`uk`/`us`; `literal` («Буквально: …») — на языке карточки (иначе ru) и `null`, пока
карточка прячет ответ (meaning / reverse / audio / pretest / gap_fill) — тогда он приходит в `revealed.phrase`. **С 26.11.2026** ответ `/review` фразы на `gap_fill` и `pretest` тоже несёт `revealed` (у слова на них `revealed` по-прежнему `null`): `word`, `translation`, `transcription`, `audio_url`, `note`, `phrase` (с `literal`); `correct` — у `pretest` перевод (правильный вариант), у `gap_fill` вся фраза; `example`, `example_translation`, `example_audio_url`, `hook`, `confused_with` — `null`. Из уже прочитанных строки и перевода — операторов не добавляет. `gap_fill` фразы: `prompt.front` —
перевод (смысловой эквивалент), `prompt.masked_example` — сама фраза с пропуском ключевого слова (`"Easy come, easy ____"`), `answer_length` —
длина этого слова; `spelling` фразе не выдаётся.

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
    "example_translation": "Она стойкая девочка.", // перевод ИМЕННО этого примера (якорь — word_translations.example_translation; ротируемый — word_examples.translations[locale]) на язык сессии, при его отсутствии — на ru (как перевод слова); null, если перевода нет или пример скрыт
    "example_audio_url": "https://…/audio/9c….mp3", // mp3 ИМЕННО этого примера (docs/26 §9.11): озвучен только якорь (words.example_audio_path); null — пример ротируемый, файл не актуален, своя колода или пример скрыт. Без Web Speech: null → кнопки нет
    "transcription": "rɪˈzɪl.i.ənt",
    "audio_url": "https://…/storage/audio/3f….mp3", // предсгенерированный mp3 слова (1.2c / 2R.4) или null → клиент идёт в Web Speech / GET /tts
    "card_kind": "due",     // due | new | learning (минутные learning steps, см. docs/06 §4)
    "serve_token": null,    // string для карточки, выданной досрочно (learn-ahead) — вернуть в POST /review
    "prompt": {             // упражнение этой выдачи (2D.2, docs/13 §2.5, docs/06 §4.3)
      "type": "reverse",    // flash | meaning | reverse | audio | spelling | gap_fill | pretest
      "front": "Устойчивый",// flash/meaning/pretest: слово; reverse/spelling/gap_fill: перевод; audio: "" (слово только на слух)
      "masked_example": null, // gap_fill: пример с формой слова, заменённой на "____"
      "options": ["Brittle", "Resilient", "Fragile", "Tender"], // reverse: 4 английских слова; meaning/audio/pretest: 4 перевода; иначе null
      "answer_length": null // spelling/gap_fill: длина ожидаемого текста
    }
  },
  "progress": { "done": 7, "total": 25 },
  "done_reason": null,       // null, пока есть card; иначе причина, см. ниже
  "can_continue": false,     // раунд окончен, но есть что дать ещё раунду (только с sprint_complete)
  "quota": { "new_words_remaining": 12 }   // null = безлимит (Pro)
}
```

**`progress`:** в уроке — `done` = слова урока, дошедшие до `repetitions >= 1`, `total` = активные слова урока; в `daily_review`
(с 17.11.2026, docs/06 §13.2) — `done` = оценки в этой сессии, `total` = оценка размера **раунда**: `done` + оставшиеся дневные
повторения раунда (до потолка) + оставшиеся новые слова раунда × шаги обучения + оценки, которые ещё должны карточки в минутных
шагах. `total` никогда не меньше `done` (при `card = null` равен ему); провал добавляет повтор, «уже знал» убирает шаг — полоса
не «убегает», клиенту обрезать нечего. Дневная цель здесь не показывается — она на «Сегодня» и в итоге `/finish`.
При `LEARNING_SPRINT_ENABLED=false` — как раньше: `total` = `learners.daily_goal`, `done` может его превысить.

**`done_reason`** (`card: null` ⇒ ровно одно из значений; при наличии карточки — `null`):

| Значение | Когда |
|---|---|
| `lesson_complete` | scope `lesson`: все слова урока пройдены, ничего не due и не в learning steps |
| `nothing_due` | scope `daily_review`: ничего не due, а новых слов в активном курсе нет (или курс не выбран) |
| `new_quota_exhausted` | новые слова ещё есть, но дневной лимит новых карточек исчерпан, а due и learning-карточек нет (план Free, 1.4b); клиент показывает paywall |
| `session_cap` | принято `TWA_MAX_CARDS_PER_SESSION` оценок в этой сессии |
| `sprint_complete` | scope `daily_review` (с 17.11.2026, docs/06 §13.2): раунд окончен — выбраны `LEARNING_SPRINT_DUE_CARDS` (15) дневных повторений и/или `LEARNING_SPRINT_NEW_WORDS` (6) новых слов с их шагами, а для следующего раунда ещё есть карточки (due сверх потолка или невыданные слова в пределах дневной квоты). Всегда с `can_continue = true` |
| `hard_round_complete` | scope `hard_review` (с 18.11.2026, docs/06 §14): все слова набора выданы, повторять нечего; `can_continue = false`, `progress.done` — побеждённые слова (последняя оценка слова в сессии ≥ 3) |
| `lesson_review_complete` | `lesson_review` (с 25.11.2026, docs/06 §15): каждое слово набора отвечено верно или исчерпало 3 показа; `progress.done` — слова с верным ответом |

`can_continue: bool` — есть всегда; `true` только вместе с `sprint_complete`. Экран итога при этом показывает «Ещё раунд»: клиент
делает `POST /finish` текущей сессии и `POST /training/sessions {mode: daily_review}`. Шаг с `card: null` после оценок сессии сам
помечает её пройденной (`completed_at`), поэтому `POST /training/sessions` откроет новый раунд и без `/finish` (A.3 выше) — `/finish`
клиент всё равно вызывает сразу при получении `card: null`, чтобы получить итог и событие. Free, исчерпавший дневные новые слова, получает
`new_quota_exhausted` (`can_continue = false`) — его клиент отличает от «спринт окончен»; `nothing_due` — ни повторений, ни новых слов (на сегодня всё).

Клиент показывает экран «готово» с текстом по `done_reason`; для `new_quota_exhausted` — ещё и `PaywallSheet` (ниже).

**Квота новых слов (`quota`, 1.4b; docs/12 §5.3).** `quota.new_words_remaining: int|null` есть в ответах `/start`, `/next`, `/review` и `POST /training/sessions`
(в том числе когда `card = null`): сколько новых карточек learner ещё получит **сегодня** (локальный день, `learners.timezone`) **после** этого шага — только что выданная
новая карточка уже учтена. `null` — безлимит (Pro, личный или спонсорский центром, **а также любая групповая сессия `group_lesson`**). Free: `10` (`BILLING_FREE_NEW_WORDS_PER_DAY`)
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
(`group_lesson` — только `flash`/`meaning`/`reverse`). Карточки с вариантами без трёх дистракторов (крошечная колода) приходят как `flash`. `audio_url` — то же, что в `prompt`
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
Необязательное `exercise` (`prompt.type` показанной карточки): если оно не совпадает с типом, который сервер пересчитал сейчас (план сменился
Pro → Free посреди занятия), — 409 `exercise_mismatch`, ничего не записано, клиент перезапрашивает `GET /next`. Без `exercise` проверки нет (старый клиент).
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
  "revealed": { "word", "translation", "correct", "example", "example_translation", "example_audio_url", "transcription", "audio_url", "hook", "note", "confused_with", "phrase" } | null, // экран meaning/reverse/audio после ответа (`correct` — правильный вариант как в options); иначе null
  "card": { ... } | null,    // следующая карточка (как в /next)
  "progress": { "done": 7, "total": 25 },
  "done_reason": null,
  "can_continue": false,
  "quota": { "new_words_remaining": 12 },
  "score": { "kind", "reason", "base", "week", "month", "today", "today_month" } | null // очки за слова v2 (ниже); null при off / shadow
}
```

**`score` — очки за слова v2 (docs/32 §8.1, 26.11.2026).** `ReviewResultResource.score` = `WordScoreOutcome::toApi()`, лишних запросов нет.
`null`, пока `LEAGUES_SCORING_MODE` = `off` или `shadow`, а в `live` — до `LEAGUES_SCORING_V2_STARTS_ON` (оба зачёта дня ещё по старым
правилам): контракт старых клиентов не меняется. Ответ `lesson_review` (docs/06 §15) — всегда `null`. Иначе:
`{kind: new|review|long|null, reason, base, week, month, today, today_month}` — `reason` из `credited`, `capped` (начисление записано,
лимиты дали 0), `already_today`, `closed`, `early`, `same_cycle`, `not_due`, `introductory` (первое знакомство `flash`, попытка дня
открыта), `no_catalog` (у слова нет лексемы: **слово своей колоды ученика** — колода вне каталога, docs/32 §12.12, 27.11.2026 —
`{kind: null, reason: no_catalog, base: 0, week: 0, month: 0, today: null, today_month: null}`, никаких «+N»); `base` — очки вида по правилам (2 / 3 / 5, 0 без начисления); `week` / `month` — сколько реально добавлено в
недельный и месячный зачёт (с лимитами и остатком до потолка); `today` — недельный зачёт рейтингового дня после ответа
`{points, cap, category: free|pro, new: [used, limit], review: [used, limit]}` (`null`, если начисления не было); `today_month` — то же
для месяца, только когда он отличается от недели (другая категория или сумма), иначе `null`. Mini App пока поле не показывает (S13).

Ошибки: 409 `card_not_started` (карточка не выдавалась), 409 `card_not_due` (повторный submit:
карточка ещё не due, а `serve_token` отсутствует, устарел или чужой — docs/06 §5.2; клиенту следует
перезапросить `GET /next`), 422 `out_of_scope` (слово вне scope сессии: в `group_lesson`/`lesson` — слово другого урока,
в любом режиме — неактивное слово `words.is_active = false`; бывш. `wrong_lesson`), 410 `session_closed`.
Упражнения (422, после проверок выше, ничего не записано): `quality_not_accepted` — карточка `meaning`/`spelling`/`gap_fill`/`pretest`, нужен `answer`
(а также `reverse`/`audio` после выключения `learning.objective_grading.accept_legacy_quality`, docs/06 §12.2);
`answer_not_accepted` — карточка `flash`, нужен `quality`; `exercise_pro_required` — `answer` от плана без `exercise.spelling`/`exercise.gap_fill`
(**paywall-reason**: у Free письменных упражнений нет). TWA на все три перезапрашивает `GET /next` (сервер отдаст карточку с актуальным упражнением).

**Методика (docs/06 §12, 11.11.2026; только добавленные поля.)** `reverse`/`audio` присылают выбранный вариант как `answer`, сервер
оценивает (4/2) и отвечает `graded` как для ввода; `quality` от старого клиента пока принимается и нормализуется до 4/2. Тип
`prompt.type = meaning` (ступень 1 лестницы, docs/06 §12.1; выключается `LEARNING_LADDER_MEANING_ENABLED=false` и переключателем
`exercises.meaning`): `front` — слово, `options` — 4 перевода на языке сессии, `translation` и `example` в карточке пустые, `word`,
`transcription`, `audio_url` на месте; ответ — `answer` (вариант), верно → `quality 4`, иначе `2`, `quality` от клиента → 422
`quality_not_accepted` всегда; после ответа — `revealed` как у `audio`. Неизвестный клиенту `prompt.type` TWA показывает как экран
«Обновите приложение» (ответ в карточке скрыт, как `flash` показать нельзя). Новый тип
`prompt.type = pretest` (за флагом): `front` — слово, `options` — 4 перевода, `translation`/`example`/`example_translation`/`example_audio_url` в карточке пустые; `answer` — вариант
или `''` («не знаю»); верно → `quality 5`, иначе `2`. В `card` добавлены: `is_leech` (трудное слово, `lapses ≥ 4`), `is_hard`, `hook`
(авторский крючок на языке карточки; есть только у новых/проваленных/трудных), `note` («моя подсказка» ученика), `confused_with`
(`{word, translation|null}` или `null`); у `spelling`/`gap_fill`/`pretest` эти три — `null`. `example` — пример этой выдачи (после выпуска
карточки ротация по `word_examples`).

**Ранняя лестница (docs/06 §13.1, 17.11.2026; контракт полей не менялся).** Карточка `card_kind = new` — всегда `prompt.type = flash`
(или `pretest` за флагом). Карточки `card_kind = learning` (минутные шаги, в т. ч. learn-ahead с `serve_token`) и первые дневные
повторения приходят с `prompt.type` = `meaning` / `reverse` / `audio` (и `flash` как запасной), зрелые — `reverse` / `audio` / `flash`,
ступень ввода — `gap_fill` / `spelling`; одно слово не приходит дважды подряд в одном формате. Клиент ничего не хранит: он показывает
экран по `prompt.type` и отвечает `answer` / `quality` по тем же правилам, что выше, — для `learning`-карточки с `meaning` это `answer`
+ `serve_token`. Объективный ответ двигает шаги обучения так же, как самооценка (верно → шаг вперёд / выпуск, неверно → шаг 0).

`PUT /api/twa/words/{word}/note` `{note: string|null}` → `{word_id, note}` — своя подсказка к слову (простой текст, до 200 символов, сервер
чистит и режет); 404 `card_not_started`, если слово ученику не выдавалось; `throttle:twa-word-notes` 30/мин.
`PATCH /me/settings {retention_mode: calm|normal|intense}`; в `settings` — `scheduler` (`sm2`/`fsrs`), `retention_mode`, `options.retention_modes`.

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

**Личные сессии (`isLearnerOwned()`: `personal_mock` и `level_quiz`)** видит только владелец: `ExamSessionPolicy::denial` → 403 `forbidden` для чужого; `ExamAnswerService` берёт строку такой сессии под `FOR UPDATE`, а не `FOR SHARE`. Тест недели — A.20.
Один learner в двух группах участвует в экзаменах каждой группы независимо (ответы и результаты привязаны к `(exam_session_id, learner_id)`).
При записи ответа группового экзамена PostgreSQL берёт `FOR SHARE` на строке сессии и `FOR UPDATE` на попытке ученика.
Разные ученики могут отвечать одновременно. Закрытие сессии ждёт принятых ответов, а таблица результатов записывается пакетами.

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

### A.5 Озвучка слова на карточке
`GET /api/twa/tts` **удалён** (docs/26 §9.9): он требовал заголовок `Authorization`, а TWA звала его через `new Audio(url)`, который заголовков не шлёт, — каждый вызов получал 401. Синтеза и поиска файла по тексту по запросу ученика нет.

**`card.audio_url`** (`/training/.../start|next|review`): публичный URL предгенерированного mp3 — `APP_URL/storage/audio/{sha1}.mp3` (диск `public`, раздаёт nginx) или `AUDIO_PUBLIC_URL/audio/{sha1}.mp3` (R2) — или `null`, если у слова нет актуального `audio_path`. TWA при `audio_url` проигрывает файл в `<audio>`, при `null`/ошибке — Web Speech, без него — тишина.

**`card.example_audio_url`, `revealed.example_audio_url`** (docs/26 §9.11): такой же публичный URL mp3 примера, который показан в `example` рядом. Озвучивается только якорный пример (`words.example`): адрес есть, если `words.example_audio_path` — файл ровно этого текста (без запроса к бакету); `null` для ротируемого примера, примера, который правили и задание ещё не отработало, пути другого голоса/версии, своей колоды ученика, а также когда пример скрыт (вместе с `example`). TWA показывает кнопку озвучки примера только при непустом адресе и играет его `new Audio(url)` по нажатию, без автопроигрывания; Web Speech для примера не используется.

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
      "slug": "english-a1", "title": "Английский A1", "description": "Первые 500 слов", "level": "A1", "exam_tag": "general", "section": "courses",
      "words_count": 500, "is_featured": true, "is_active": true,
      "progress": { "done": 120, "total": 500 }
    }
  ]
}
```
Порядок — `position`, затем `id`. `is_active` — это `learners.active_course_id`. `section` (и в списке, и в `GET /courses/{slug}`) —
раздел каталога: `phrases` у курса фраз (`exam_tag = phrases`, docs/35 §3.3 a), `courses` у остальных; курс фраз онбординг не рекомендует. Активный курс learner'а остаётся в списке,
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
Модули и уроки — в порядке изучения `(stage.number, lesson.number)`; `stages[].title` и `lessons[].title` — на языке ученика
(`title_uz` для uz, если задано, иначе `title`/ru — docs/03 §2.5, `DeckTitles`); `lessons[].title` может быть `null`. **`stages[].title` — `null`,
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
`title` — название задания: своё, если учитель его задал, иначе название урока на языке ученика (`ru` или `uz`, если у урока есть узбекское), иначе «Урок N»; `lesson_id` — то, что принимает `POST /training/sessions {mode: "lesson", lesson_id, source: "assignment"}` (`source = assignment` теперь допустим; тренировка идёт обычным путём, без отдельного режима); `overdue` — срок прошёл, а задание ещё открыто (опоздавшие выполнения учитываются до закрытия); `late` — выполнено после `due_at`. `progress_pct` = доля активных слов урока с `word_repetitions.repetitions >= 1`; `completed` — достигнут порог задания (`completion_threshold`).

**Когда TWA его запрашивает (с 19.11.2026).** Если последний `/today` на этом устройстве сказал `assignments_possible: true` — параллельно с `/today`, как раньше; иначе (первое открытие, или было `false`) — после ответа `/today`, и только при `true` или без поля (старый сервер). При `false` запроса нет вовсе. Сбой запроса — не пустой список: у участника группы «Сегодня» показывает строку «Не удалось загрузить задания центра» с кнопкой «Повторить»; личная тренировка доступна.

**Ссылка из группы.** Объявление в чате — одно сообщение с `web_app`-кнопкой `/twa/?from=assignment&lesson=<id>`: TWA убирает метку из URL и открывает сессию урока (`source = assignment`); доступ к уроку перепроверяет сервер обычными правилами. Ошибка открытия остаётся на экране «Сегодня».

### A.10 Свои колоды learner'а (docs/13 §8.1, Фаза 2 / 2D.1)

Все маршруты — под `TwaAuth` и `throttle:twa-api`; изменяющие — ещё `throttle:twa-decks` (30/мин на learner'а), импорт слов —
`throttle:twa-deck-import` (10/мин на learner'а). Колода — курс с `owner_type = learner` (docs/03 §2.20); маршруты работают только с
**собственными** колодами: чужая, архивная и неизвестная колода отвечают одинаково — `404 deck_not_found`.
Выбор колоды активным курсом — существующий `POST /me/course {slug}` (A.5a): владелец может выбрать свою колоду, остальные получают
`404 course_not_found`. Активная колода попадает в `GET /courses` (как и любой доступный активный курс). Колода участвует в
`daily_review` и в квоте Free (10 новых слов/день) как обычный курс.

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
| POST | `/billing/offer-viewed` | предложение Pro показано (первый шаг воронки Pro, docs/13 §5.1); `204`; **throttle `twa-offer-viewed`: 20/мин на learner'а** (429) |
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

**`POST /billing/checkout`** `{ "plan": "pro", "period": "month", "provider": "telegram_stars", "placement": "paywall_quota" }` — `plan` ∈ `Plan`, `period` ∈ `config('billing.periods')`, `provider` ∈ `telegram_stars|payme|click`, `placement` — необязательное место, откуда открыт лист покупки (`OfferPlacement`, см. `POST /billing/offer-viewed`; нет поля или `null` — `null` в событии; на заказ не влияет) (иное — `422` валидации Laravel). → `201`:
```json
{ "order": { "id": 17, "token": "q3…32 символа", "status": "pending", "amount": 150, "currency": "XTR", "expires_at": "2026-10-20T12:00:00+00:00" },
  "checkout": { "url": "https://t.me/$AbCd…", "kind": "invoice_link" } }
```
`checkout.kind`: `invoice_link` (Stars — открыть `Telegram.WebApp.openInvoice(url)`) | `redirect` (Payme/Click — `openLink(url)`). Заказ живёт `BILLING_ORDER_TTL_MINUTES` (24 ч); просроченные закрывает `billing:expire-orders` (каждые 15 мин).
Событие `checkout.started` (с `locale` — язык интерфейса ученика на момент начала — и `placement`). Если провайдер не смог выдать ссылку — заказ `failed`, ответ `502 provider_unavailable`.

**`POST /billing/offer-viewed`** `{ "placement": "paywall_quota" }` → `204` без тела. Клиент шлёт, когда предложение Pro стало видно (fire-and-forget: не ждёт ответа, ошибки глотает), и только при `paywall.mode = purchase` в `/me`. `placement` ∈ `OfferPlacement` (`App\Domain\Billing\Enums\OfferPlacement`, зеркало — `resources/twa/src/composables/offerViewed.ts`): `paywall_quota | paywall_info | paywall_decks | paywall_streak_freeze | paywall_exam_simulator | paywall_practice | paywall_practice_limit | paywall_game` (лист покупки `PaywallSheet` по своей причине; **план docs/34 P4/P7:** `paywall_daily_goal` — цель дня больше лимита Free, `paywall_trial_ended` — разовый пейвол после конца пробного Pro), `billing_view` (экран «Подписка» с кнопкой «Купить / Продлить»), `lapsed_banner` («Pro закончился» с кнопкой продления), `finished_pro_tip` (совет о Pro на экране итога), `finished_trial_ending` (карточка «пробный период кончается»); иное — `422`. Пишет `billing.offer_viewed {placement, locale}` не чаще раза на (ученик, место, локальный день ученика): маркер `billing:offer_viewed:v1:<learner>:<placement>:<Y-m-d>` (`Cache::add`, невытесняемое хранилище, TTL 48 ч). Повтор того же дня — `204` без записи и **без SQL сверх аутентификации** (1 оператор: ученик); новый — плюс один INSERT после ответа. Пока продавать нечего (`paywall.mode` опущен до `coming_soon`, как в `/me`) — `204` без записи и без маркера.

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
  "activation": { "days": 1, "reviews_per_day": 10, "window_days": 14 },
  "milestone_days": 7,
  "counts": { "invited": 5, "activated": 4, "counted": 4 },
  "milestones": [
    { "friends": 3, "days": 7, "reached_at": "2026-11-10T09:00:00+00:00", "rewarded": true },
    { "friends": 6, "days": 7, "reached_at": null, "rewarded": false }
  ],
  "next": { "friends": 6, "remaining": 2 },
  "referrals": [
    { "first_name": "Dilnoza", "status": "rewarded", "linked_at": "2026-10-20T10:00:00+00:00" }
  ]
}
```
**Схема вех (2C.9, docs/13 §7.1):** приглашённый ничего не получает по рефералу (у каждого нового learner'а — пробный Pro), пригласивший получает `milestone_days` дней Pro при каждой вехе `milestones[].friends` засчитанных друзей. `link` — `null`, пока `TELEGRAM_BOT_USERNAME` не задан (клиент показывает код и подсказку `/start ref_<code>`). `activation` — правило «друг засчитан»: прошёл онбординг и `days` локальных дней с ≥ `reviews_per_day` оценок в окне `window_days` от привязки. `counts.invited` — все не-`rejected` рефералы, `activated` — `activated` + `rewarded` (начали заниматься), `counted` — `rewarded` (засчитаны к вехам; отстаёт от `activated` только на время job'а или пока реферал под флагом). `milestones` — все настроенные вехи по возрастанию: `reached_at` — когда достигнута (`null` — ещё нет), `rewarded` — награда выдана (или закрыта без Pro: бессрочная подписка). `next` — первая недостигнутая веха и сколько друзей до неё; `null` после последней. `referrals` — до 50 последних не-`rejected` (новые первыми): **только** `first_name` приглашённого (может быть `null`), `status` (`pending` / `activated` / `rewarded`) и `linked_at`; никаких id, username, прогресса. Удалены (2C.9): `reward_days`, `counts.rewarded`, `counts.capped`. TWA: экран `/referrals` («Пригласить друга» с «Сегодня»), кнопка «Поделиться» → `useTelegram().shareUrl` (`openTelegramLink('https://t.me/share/url?…')`), «Скопировать ссылку» → clipboard.

Пробный Pro в `/me` (2C.9): `entitlement.renewal = {auto: false, provider: "trial", ends_at, reminder_sent}` пока пробный живёт; после конца — `entitlement.lapsed = {provider: "trial", ended_at}` в окне баннера. Отдельного поля нет: клиент различает по `provider`.

**ЛС о вехе (бот, 2C.9).** После выдачи награды `ReferralService` поднимает `ReferralMilestoneReached(learnerId, milestone, days, subscriptionId, skipped)`; слушатель `SendReferralMilestoneMessage` (очередь, после commit, бюджет исходящих `ThrottleTelegramOutbound`) через `ReferralMilestoneSender` шлёт пригласившему «🎉 3 ваших друга начали заниматься — вам +7 дней Pro (до дд.мм.гггг). До следующей награды: ещё 3 друга.» (после последней вехи — без второй строки; дата — конец подписки в поясе ученика; язык — `interface_locale`). `skipped !== null` (бессрочная подписка, удалён, заблокирован), заблокированный ученик и закрытый ЛС (`dm_blocked_at`) — без сообщения; 403 закрывает ЛС и снимает маркер. Идемпотентность — строка `notifications_log` типа `referral_milestone` с `payload.key = milestone:<N>`, пишется до вызова Telegram под `Cache::lock`: повтор события или job'а второго сообщения не даёт.

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

**Эмодзи Pro (с 26.11.2026, docs/31 §5а E2):** у каждой строки `standings` — `pro_emoji` (символ эмодзи участника, пока у него действующий Pro, иначе `null`). Один запрос на группу (`ProEmojiService::visibleForMany`), кэш `league:emoji:v1:group:{группа}` 60 с (как таблица): из кэша 0 операторов; смена эмодзи участником сбрасывает ключ его группы сразу, истёкший Pro виден не дольше 60 с.

**Неделя на очках v2 (с 26.11.2026, docs/32 §8.3, S12).** Только у недели с `league_weeks.rules_version = 2` (у недели v1 этих ключей **нет**): верхний `rules_version: 2` и рядом с ним `best_days` (сколько лучших дней недели идёт в зачёт — из правил версии недели, `leagues.scoring.versions.{N}.best_days`, сейчас 5; без нового оператора; Mini App берёт число отсюда, а не из своей константы); в `membership` — `category` (`free` | `pro`, таблица группы), `rules_version`, `next_gap` (сколько очков гарантированно обгоняют ближайшее строго лучшее место: `P' − P + 1`; `null` у лидера); в строках `standings` — `long_words` и `active_days` (признаки ничьей по лучшим дням); `rank` — конкурентный (1, 2, 2, 4), `zone` — по рангу (все, кто делит проходное место, повышаются). Неделю другой категории не показываем: недельная таблица — это группа. Бюджет `GET /league` v2: таблица группы 1 оператор на промахе (`LeagueV2BudgetTest`), эмодзи +1 на промахе; из кэша — ни одного.

`GET /today` += `league: {tier, tier_key, rank, points, members_count} | null` — строка «Лига» на «Сегодня». `PATCH /me/settings` += `leagues_opt_out: bool` (только JSON-boolean; `settings.leagues_opt_out` в ответе) — `true` = не размещать (текущее членство остаётся до конца недели; возврат действует с первой оценки следующего локального дня).

**ЛС победителю месяца (бот, §7.6 docs/13).** После подтверждения итогов администратором (`LeagueMonthConfirmed`, после commit) слушатель `SendMonthWinnerMessages` (очередь, бюджет исходящих `ThrottleTelegramOutbound`) через `MonthWinnerSender` шлёт каждому из 1–3 победителей (в месяце v2 — каждой таблицы, все разделившие призовое место) **фото** — картинку `MonthWinnerCardBuilder` («1 место», «в рейтинге за ноябрь 2026 — 1204 балла», метка «Рейтинг месяца[ · Free/Pro]», без имени; docs/36 A5, docs/13 §7.4), файлом через `sendPhotoMessage(PhotoSource::file)` — с подписью; если картинки нет (ошибка рисовальщика, диск карточек не локальный) — прежним текстом, ошибка в `report()`. Подпись: «🏆 Поздравляем! Вы заняли 1-е место в рейтинге за ноябрь 2026 — 1204 балла. Ваш приз: <приз места на языке ученика>. Мы свяжемся с вами в Telegram, чтобы вручить его.»; если призов нет — без фраз о призе. Язык — `interface_locale`. Заблокированный, удалённый ученик и закрытый ЛС (`dm_blocked_at`) — молча; 403 закрывает ЛС и снимает маркер. Идемпотентность — строка `notifications_log` типа `league_month_won` (`payload.key = month:<id>`), пишется до вызова Telegram под `Cache::lock`: повтор события или job'а второго сообщения не даёт. Остальным ученикам рассылки нет (итоги — в приложении, `last_month`).

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
`friends[].pro_emoji` **(с 26.11.2026, docs/31 §5а)** — эмодзи Pro друга, пока у него действующий Pro, иначе `null`; ключ есть всегда; один запрос на весь список (у входящих/исходящих запросов эмодзи нет). `friends[].camel_asset` (G2.6) — `asset` надетого верблюда друга (`string`) или `null`; ключ есть, **только когда игра открыта смотрящему** (`GAME_ACCESS`, docs/18 §12.7.4), при закрытой игре его нет и таблиц `game_*` запрос не трогает; когда открыта — один запрос на всех друзей.
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
`title` / `description` / `share_text` — на языке запроса (`lang/{ru,uz}/achievements.php`). `progress` — только у счётных ключей (`streak_*` по лучшей серии, `words_*` по словам с `repetitions ≥ 1`, `long_recall_25` — по лексемам с длинным повтором `learner_lexemes.first_long_on`, считаются только до 25), `current` не больше `target`; у разовых — `null`. `long_recall_25` («Долгая память», docs/32 §8.4) открывается на 25-й лексеме с длинным повтором (интервал ≥ 7 суток, засчитанный очками за слова v2); пока `LEAGUES_SCORING_MODE=off`, признак не пишется и ключ в списке остаётся закрытым. `share_text` — только у полученных. `share_link` — `t.me/<bot>?start=ref_<code>` (тот же код, что у рефералки); `null`, пока не задан `TELEGRAM_BOT_USERNAME` — клиент прячет кнопку «Поделиться».
- `POST /api/twa/achievements/{key}/shared` — learner открыл диалог «Поделиться» (`useTelegram().shareUrl`): ставит `shared_at` один раз (+ событие `achievement.shared`), повтор — без изменений. **404** `achievement_not_found` — ключ неизвестен, не получен или чужой.

### A.16 Практика — четыре режима и «Пары на время» (docs/16 §7, §10; задачи 18.1–18.5)
Самостоятельная практика закрепляет **уже введённые** ученику слова: «Найди пары» (`pairs`), «Подбери сочетание» (`collocation`), «Собери предложение» (`sentence`), «Слушай и пиши» (`dictation`). Она **не пишет**
`word_repetitions`, `training_*`, цель дня, серию, лиги, задания центра и достижения (docs/16 §0, `PracticeIsolationTest`). Контроллеры `PracticeHubController`, `PracticeSessionController`, `PracticeAudioController` тонкие
(валидация → сервис → Resource): `PracticeHubService`, `PracticeSessionService`, `PracticeAudioService`; ответы — `PracticeHubResource`, `PracticeSessionResource`, `PracticeAnswerResource`; ошибки — `ApiErrorResource` (A.6, с `details`).
Формы закреплены `PracticeContractTest` (`assertExactJsonStructure`, запретные ключи снимка на любой глубине). Даты — ISO 8601 UTC **с миллисекундами** (`2026-10-30T10:00:05.321Z`); `server_now` — в каждом ответе сессии.

**Доступ** проверяется на каждом эндпоинте раньше всего (`PracticeAccess::state`): `PRACTICE_ACCESS=off` или `pilot` без ученика в пилоте → **403** `practice_unavailable` (один код: наличие пилота не раскрываем); `PRACTICE_PLAN=pro` и ученик не на Pro → **403** `practice_pro_required`
(TWA открывает пейвол `practice`). Выключатель режима `PRACTICE_MODE_*` проверяется **только при создании** сессии (**409** `practice_mode_disabled`) и показывается в хабе как `disabled`: открытые сессии доигрываются.
Чужая сессия неотличима от несуществующей: **404** `practice_session_not_found`.

**Лимиты Free** (G1, docs/16 §3.2, docs/18 §3; `PracticeLimits`). При `PRACTICE_PLAN=all` Free практикует в пределах локального дня: `PRACTICE_FREE_SESSIONS_PER_DAY` (3) открытий сессий и `PRACTICE_FREE_BATTLES_PER_DAY` (1) вызовов на баттл (A.17); Pro (фича `practice.unlimited`, в т. ч. спонсорский) — без лимита; `0` — только Pro; пустое значение — без лимита. Замена активной сессии считается. Повтор ошибок (`retry_of`): бесплатен **один** повтор на исходную сессию, открытую сегодня; второй повтор того же источника, повтор повтора и повтор вчерашней сессии считаются как сессия (при лимите 0 — только Pro); проверка «повтора ещё не было» — под тем же замком ученика.
Отказ — **403** `practice_limit_reached` с `details` `{kind: "sessions" | "battles", limit, used, resets_at}` и событие `practice.limit_reached {kind, limit}`; `message` — текст на языке ученика (`lang/*/practice.php`), TWA ведёт по коду: пейвол `practice_limit` с остатком. Порядок: доступ → выключатель режима → **лимит** → курс, урок, активная сессия, материал (ученик у лимита получает 403 и при неизвестном курсе, и при незавершённой сессии — её можно доиграть из `hub.active_session`).
Решает БД: подсчёт — в транзакции создания под `FOR NO KEY UPDATE` строки ученика (две вкладки за последнее место — одна сессия, `PracticeLimitsConcurrencyTest`). Остаток — в `GET /me` → `practice.limits` (A.2), в хабе (`limits`) и в ответе 201 открытия (значение после открытия). Цена: один SQL на блок (оба счётчика), на открытие у Free — 4 запроса сверх прежних; у Pro — ничего.

**Лимиты** (ключ — `learner_id`, `TwaAuth` раньше `throttle`): все маршруты, кроме аудио, под `twa-api` (60/мин); дополнительно `POST /practice/sessions` — `twa-practice-open` **10/мин**; `…/answer` и `…/replace` — `twa-practice-answer` **90/мин**
(быстрый ученик сопоставляет ~40 пар в минуту); аудио — `twa-practice-audio` **60/мин** и **вне** `twa-api` (клипы грузятся пачкой и не должны съедать общий бюджет).
Отказ лимитера — 429 **вне** конверта A.6 (middleware `throttle:` отвечает сам, как в A.17): `{"message": "Too Many Attempts."}` и заголовок `Retry-After` (секунды до сброса), без `error` и без кода (у запроса, который просит не JSON, — например, у аудио с `Accept: audio/mpeg` — вместо тела страница HTML; статус и `Retry-After` те же).
TWA различает отказ по статусу, а не по коду: `api/client.ts` отдаёт любой 429 как `rate_limited`; форму закрепляет `PracticeRateLimitTest`.

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
  "active_session": { "id": 77, "mode": "pairs", "course": "english-a1", "course_title": "Английский A1", "lesson_id": 41,
                      "lesson": { "stage": 2, "number": 4, "title": "Еда" }, "progress": { "done": 1, "total": 3 } },
  "battle": { "enabled": true, "active_battle_id": null, "incoming_count": 1 },
  "limits": { "sessions": { "limit": 3, "used": 1, "remaining": 2, "resets_at": "2026-10-30T19:00:00.000Z" }, "battles": { "limit": 1, "used": 0, "remaining": 1, "resets_at": "2026-10-30T19:00:00.000Z" } } }
```
- `lesson` — `null` без `lesson_id` (хаб курса); `stage` и `number` — номера модуля и урока, `title` — название (у безымянного урока `null`; у колоды ученика — по `DeckTitles`). Тексты на языке ученика.
- `limits` (G1) — лимиты Free на сегодня, как `practice.limits` в `/me` (A.2): `null` у Pro, за пейволом (`state: pro_required`) и когда лимиты не заданы. Один SQL (оба счётчика) — бюджет хаба Free с лимитами 10, без них 9.
- Числа `available` в хабе курса (без `lesson_id`) при `state = available` — «не меньше» (слова считаются по выборке до `practice.count_sample`); при `not_enough` — точные.
- `modes[]` всегда четыре, в порядке `pairs`, `collocation`, `sentence`, `dictation`. `state`: `available` (можно играть), `not_enough` (материала мало: числа `available` / `required` и `reason`), `disabled` (режим выключен
  `PRACTICE_MODE_*`), `pro_required` (Free при `PRACTICE_PLAN=pro`: числа не считаются). У `disabled` и `pro_required` `available = 0`, `reason = null`. `required` — минимум: у пар 3 слова в раунде, у остальных 3 задания (`practice.solo.items.min`).
  `reason`: `no_words` (введённых слов меньше минимума; у диктанта — и когда озвученные слова есть, но вместе с фразами их меньше минимума: ещё одно выученное слово откроет режим), `ambiguous` (слов хватает, но после исключения неоднозначных переводов — нет), `no_items` (опубликованных заданий типа мало, и «запертые» их не дополнят — обещать «они появятся» нельзя), `items_locked` (задания есть, но не хватает из-за невведённых целевых слов: с ними хватило бы — «выучите слова урока»; у баттла не бывает),
  `audio_pending` (заданий хватает, но озвучка не готова), `no_audio_words` (диктант слов: нет слов с готовой озвучкой). `estimate_seconds` — «≈ N мин» на карточке (120 / 90 / 150 / 180).
- `active_session` — единственная незавершённая сессия ученика **в любом курсе** (`null`, если нет; просроченная закрывается и не возвращается): карточка «Продолжить» и лист «Незавершённая практика». Место сессии названо и id, и **словами**, чтобы
  «Продолжить» могла сказать, какая это сессия, и для курса или урока, которых экран не видел: `course_title` — название курса на языке сессии (языке ученика при открытии; нет названия — как у `course.title` сессии: любой язык снимка, иначе slug),
  `lesson` — `{stage, number, title}` (модуль, номер в нём, название; часть, которой нет у урока, — `null`) либо `null` у практики курса и у снимка без названий. Названия лежат в снимке, который сервер и так разбирает ради slug курса: **ни одного запроса к БД сверх прежних**
  (бюджет хаба тот же, `PracticeQueryBudgetTest`). TWA называет место коротко («Модуль 2 · Урок 4 · Еда» или курс), когда это место самого хаба, и с курсом впереди («Английский A1 · Модуль 2 · Урок 4 · Еда») — когда нет.
- `battle` — сводка баттла: `enabled` (`PRACTICE_BATTLE`), `active_battle_id` (свой матч, который не закончен), `incoming_count` (приглашения мне, срок которых не истёк). Один запрос к `battles`; API баттла — A.17.
- Ошибки: **403** `practice_unavailable`; **404** `course_not_found` (курс не опубликован, приватный чужой или нет такого), `lesson_not_found`; **422** `lesson_not_in_course`, валидация (`course` обязателен, ≤ 64 символов).

#### `POST /api/twa/practice/sessions` — открыть сольную сессию (лимитер `twa-practice-open`)
```json
{ "mode": "pairs", "course": "english-a1", "lesson_id": 41, "source": "hub", "replace_active": false, "retry_of": null }
```
`mode` — один из четырёх; `course` — slug (≤ 64); `lesson_id` — целое ≥ 1 или `null` (практика курса); `source` — `hub` (по умолчанию) | `after_training` (хаб открыт с итога занятия: `?from=finished`) | `retry` | `deeplink` — откуда открыта, для аналитики;
`replace_active` — закрыть незавершённую сессию (`finish_reason = replaced`); `retry_of` — id **завершённой** своей сессии: «Повторить ошибки» (режим, курс и урок берутся из неё, присланные игнорируются, `source = retry`).
**`source: retry` и `retry_of` приходят только вместе**: одно без другого — **422** (валидация Laravel), чтобы повтор нельзя было выдать за обычное открытие и наоборот.
Ответ **201** — сессия (форма ниже, как `GET`) плюс ключ `limits` (`PracticeSessionOpenedResource`): лимиты Free на сегодня **после** этого открытия в форме `practice.limits` из `/me` (A.2), `null` у Pro; у `GET` и у `session` ответа на вопрос ключа нет. В `current` — первый элемент. Что в сессии: пары — до 3 раундов по 4 слова (минимум один раунд из 3); сочетание, предложение — 5 заданий (минимум 3); диктант — 5 заданий (минимум 3): до 3 фраз, остальное — слова с готовой озвучкой (слов не хватает — добираются фразами), сначала слова, потом фразы.
Раскладка и ожидаемые ответы создаются на сервере один раз и хранятся в снимке (`practice_sessions.snapshot`, docs/03 §2.43): перезагрузка отдаёт ту же раскладку, правка контента на идущую сессию не влияет.
- Одна активная сессия на ученика (unique `active_learner_id`): вторая открывается только с `replace_active` — иначе **409** `practice_session_active` (`details` — незавершённая сессия, те же поля, что у `active_session` хаба, с `session_id` вместо `id`:
  `{session_id, mode, course, course_title, lesson_id, lesson, progress}`).
  Две вкладки, открывшие одну и ту же практику одновременно, получают **одну** сессию (проигравший гонку читает победителя); другую практику — 409.
- Порядок работы: незавершённая живая сессия без `replace_active` даёт 409 **до** сборки снимка (ни чтений материала, ни диска, ни события `practice.not_enough_material`); с `replace_active` снимок строится первым — 422 «мало материала» не закрывает идущую сессию, старая закрывается только вместе с созданием новой.
- Ошибки: **403** `practice_unavailable` / `practice_pro_required` / `practice_limit_reached` (`{kind: "sessions", limit, used, resets_at}` — лимит дня Free исчерпан; один повтор ошибок на исходную сессию дня бесплатен, остальные повторы считаются; проверяется после выключателя режима и **до** курса, урока, активной сессии и материала); **409** `practice_mode_disabled` (`{mode}`), `practice_session_active`, `retry_not_available` (в исходной сессии нечего повторять, или все задания-ошибки сняты с публикации / потеряли озвучку или слова; кнопку `summary.retry_available` скрывает то же решение, плюс выключенный режим и закрытый доступ — их открытие повтора отдаёт обычными `practice_mode_disabled` и 403); **404** `course_not_found`, `lesson_not_found`,
  `practice_session_not_found` (чужая или несуществующая `retry_of`); **422** `lesson_not_in_course`, `practice_not_enough_material`, валидация.
- **422 `practice_not_enough_material`** — `details`: `{ "mode": "pairs", "available": 2, "required": 3, "reason": "no_words", "course": "english-a1", "lesson_id": 41 }` (те же `reason`, что в хабе). TWA по нему объясняет причину и предлагает «Учить урок».
- Побочный эффект: событие `practice.session_started` `{session_id, mode, course_id, lesson_id, source, total, retry}`; отказ 422 — `practice.not_enough_material` (кроме повтора ошибок); отказ 403 `practice_limit_reached` — `practice.limit_reached {kind, limit}`.

#### «Пары на время» (`pairs_timed`, docs/16 §5.5a) — отличия контракта
- **Хаб:** строка `{mode: "pairs_timed", state, available, required: 8, reason, estimate_seconds: 60, record: {score, achieved_at} | null}`; ключ `record` есть только у неё. Баттл режим не предлагает (`POST /battles` с ним — 422, `GET /battles/options` его не перечисляет).
- **Урок короче `min_words` (с 25.11.2026, шаг 3 плана дня, docs/31 §5б):** при `lesson_id` и нехватке слов урока доска добирается **выученными** словами того же курса вне урока (`word_repetitions.state = review`) до `min_words`, округлённого до целых раундов (8); выбор — по зерну сессии (детерминирован в её снимке, у новой сессии другой); **все** слова урока остаются. Урок без единого встреченного слова не добирается; если выученных слов курса не хватает — прежний 422 `practice_not_enough_material` (`no_words`). Хаб урока (`GET /practice/hub?lesson_id`) по-прежнему считает только слова урока — его бюджет операторов не растёт; игра плана дня открывается напрямую (`source: "deeplink"`).
- **`POST /practice/sessions`** `{mode: "pairs_timed", course, lesson_id?, source}`: 201, `timed: {seconds: 60, deadline_at: null, started: false, score: 0, penalty_ms: 2000}`, **`current: null`** (доска первого раунда не отдаётся до `start`, так же в `GET` сессии, пока `timed.started = false`; после `POST …/start` — `pairs_round` с `round`, `rounds: 10`), `progress {done: 0, total: 10}`. Лимит Free как у любой сессии. Выключен `PRACTICE_MODE_PAIRS_TIMED` → 409 `practice_mode_disabled`.
- **`POST /practice/sessions/{id}/start`** (`twa-api` + `twa-practice-answer`, без тела) → 200 `PracticeSessionResource` с `timed.deadline_at` (серверный, `start + 60 с`), `timed.started: true`. Идемпотентен: повтор — тот же `deadline_at`. Ошибки: 404, 410 `practice_session_closed`, 409 `practice_not_timed`.
- **`POST …/answer`:** как у пар (`{left_id, right_id}`); ответ до `start` — 409 `practice_not_started`; после `deadline_at + 1,5 с` — 409 `practice_time_over` `details {score}` (сессия закрыта `time_over`); неверный → `penalty_ms: 2000` в теле и `session.timed.deadline_at` на 2 с раньше (счёт не меняется), верный → `penalty_ms: 0`, `session.timed.score + 1`. Повтор `request_id` не штрафует второй раз. `score` / `deadline_at` в теле игнорируются.
- **`GET /practice/sessions/{id}`:** ключ `timed` — `{seconds, deadline_at | null, started, score, penalty_ms}` у `pairs_timed`, `null` у остальных. Осталось секунд = `deadline_at − server_now` (оба из одного ответа). После `deadline_at + 1,5 с` `GET` лениво закрывает игру и отдаёт `summary`.
- **`summary`** (`pairs_timed`): обычные ключи (`total: 10`, `answered`, `correct_first`, `typos: 0`, `hints: 0`, `mismatches`, `duration_ms`, `finish_reason: time_over | completed | …`, `retry_available: false`, `mistakes: []`) + `score`, `best_score`, `is_record`. «Повторить ошибки» нет: `retry_of` → 409 `retry_not_available`.

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
(`label: "mother — мама"`, `your_answer: null`, `verdict: "wrong"`). `finish_reason` — `completed` | `expired` | `replaced`; `duration_ms` — серверное `finished_at − started_at`. `retry_available` — можно «Повторить ошибки»: то же решение, что принимает открытие повтора, и считается оно при каждом чтении итога (не хранится) —
есть что повторять (пары: ≥ 3 слов, остальные режимы: ≥ 1 слова или задания), практика доступна ученику, режим включён и хотя бы одно задание-ошибка ещё играбельно (опубликовано, целевые слова активны и введены, у диктанта озвучка готова). Чего решение не видит (слово раунда пар, которого не стало; два перевода, ставших неоднозначными), то открытие находит при сборке и отвечает 409 `retry_not_available`.
Цена: у завершённой сессии с ошибками-заданиями `GET` стоит 3 запроса (один — о заданиях), у пар 2.

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
- `reveal` — разбор **после** ответа, по виду элемента: `collocation` — `{correct_option_ids, full_text, translation, explanation, your_option_id}` (`your_option_id` — `string | null`: id варианта, который выбрал ученик, `null` при «Не знаю»; экран отмечает им выбор среди трёх); `sentence` — `{correct_text, alternatives: [...], your_text, explanation}`; `dictation` — `{expected, your_text, diff, translation, explanation}`
  (`diff` — пословное сравнение при равном числе слов, иначе `null`; при «Не знаю» `your_text = null`). У пар `reveal` всегда `null`, правильная пара при ошибке **не** раскрывается.
- Пары: `pair: { left_id, right_id }` — какое сопоставление проверено, `verdict` — `correct` | `wrong` (первая ошибка по слову фиксируется, даже если потом оно найдено), `round_done: bool` — раунд завершён (**ключ есть только у пар**).
- **Повтор того же `request_id`** возвращает прежние `verdict`/`reveal` (из журнала `practice_attempts`) и **текущее** состояние сессии — даже если она уже закончилась (потерянный ответ на последний элемент не превращается в 410).
  `request_id` принадлежит одному виду запроса: если он уже принят как **замена** диктанта, ответ с ним — **409** `request_id_reused` (без `details`); новый запрос — новый uuid, повторяется только тот, чей ответ потерян.
- **«Форма слова» `word_form`** (docs/16 §5.6): `current` — `{id, kind: "word_form", text, base}`; ответ `{text}` (≤ 200) или `{skip: true}`; `verdict` — `correct` | `wrong` | `skipped` (опечаток нет); `reveal` — `{expected, alternatives, your_text, same_as_base, full_text, translation, explanation}`.
- **«Найди ошибку» `error_hunt`** (docs/16 §5.7) — два запроса на задание, у каждого свой `request_id`: шаг `locate` `{token_id}`, затем шаг `fix` `{option_id}`; `{skip: true}` — на любом шаге. `current` — `{id, kind: "error_hunt", step: "locate" | "fix", tokens: [{id, text}], located: null | {token_id, ok, error_token_id}, options: null | [{id, text} ×3]}` (варианты — только после принятого `locate`). В теле ответа есть **`element_done: bool`** (ключ только у этого режима): после `locate` — `false`, `verdict` — вердикт поиска, `reveal` — `{step: "locate", located_ok, your_token_id, error_token_id}`, `session.current` — шаг `fix`; после `fix` или `skip` — `true`, `verdict` `correct` только при верных обоих шагах, `reveal` — `{step: "fix", located_ok, fixed_ok, your_token_id, error_token_id, your_option_id, correct_option_ids, wrong_text, full_text, translation, explanation}`. Ответ не на тот шаг — **409** `step_not_current` (`{step: "locate" | "fix"}`, ожидаемый шаг; TWA перечитывает сессию). `correct_first` растёт только на окончательном шаге.
- Порядок проверок под блокировкой сессии: повтор → закрыта (410) → неизвестный элемент (422) → элемент уже отвечен (409) → не текущий (409) → форма ответа (422). Ошибки:
  **410** `practice_session_closed` (`{finish_reason}`; просроченная сессия закрывается этим запросом); **409** `element_not_current` (`{current}` — id элемента, который отвечать сейчас; TWA перечитывает сессию), `answer_already_accepted` (`{verdict}`), `pair_already_matched` (`{left_id}`; также для уже занятой правой карточки);
  **422** `invalid_answer_shape` (`{expected}`: `order_of_all_fragments` | `option_id` | `left_right` | `text_or_skip` | `bijection` | `token_id`), `unknown_element_id` (неизвестный элемент, фрагмент, вариант, токен или карточка; TWA перечитывает сессию и идёт за ней без сообщения — элемента на экране уже нет), валидация; **404**.
- Побочный эффект при завершении сессии: событие `practice.session_finished` `{session_id, mode, total, answered, correct_first, hints, mismatches, reason, duration_ms}` (после commit, один раз).

#### `POST /api/twa/practice/sessions/{id}/replace` — замена диктанта без штрафа (лимитер `twa-practice-answer`)
`{ "request_id": "…uuid…", "element_id": "eD4rS6yUiO", "reason": "audio_unavailable" }` → **200** сессия (как `GET`): диктант, чья запись не загрузилась, заменён следующим кандидатом из пула снимка (фраза — фразой, иначе словом); если запасных нет, элемент
убирается и `progress.total` уменьшается (минимум 1). Заменённый элемент не входит в `total` и `answered`. Не больше `practice.solo.dictation.max_replacements` (2) на сессию. Ошибки: **409** `replace_not_allowed` (`{reason: not_dictation | not_current | limit}`), **410**, **404**.
Повтор `request_id` второй раз не заменяет: ответ — текущая сессия; `request_id`, уже принятый как ответ, — **409** `request_id_reused`. Запасные кандидаты проверяются на диске **до** блокировки сессии (под ней диск не читается). Событие `practice.audio_failed` `{session_id, source, reason, verified}` (после commit).

#### `GET /api/twa/practice/sessions/{id}/audio/{element}` — запись диктанта (лимитер `twa-practice-audio`, вне `twa-api`)
Тело — `audio/mpeg`, заголовки `Cache-Control: private, no-store`, `Content-Length`, `X-Content-Type-Options: nosniff`. Файл читается с диска элемента снимка (`practice_audio` — фразы; `public` — предгенерированные слова, `audio:pregenerate`) по **непрозрачному id элемента**;
ни пути, ни текста в API нет. TWA грузит клип запросом с `Authorization` в `blob:` и играет по нажатию (Web Speech для диктанта запрещён: нужен текст на клиенте). Доступ — только владельцу; завершённая сессия отдаёт записи до `expires_at` (повторное прослушивание в разборе).
**Слово на R2** (`TTS_AUDIO_DISK=audio` и задан `AUDIO_PUBLIC_URL`, docs/26 §9.9): вместо тела — **200** `application/json` `{ "url": "https://audio…/audio/{sha1}.mp3" }` с `Cache-Control: private, no-store` (не 302: `fetch` пошёл бы за редиректом на чужой домен с `Authorization` и требовал бы CORS на бакете). TWA играет этот адрес в `<audio>` (CSP `media-src`), без `fetch` и без токена; бакет сервер не опрашивает, поэтому для слова на R2 503 не бывает: «нет звука» видит клиент (docs/26 §9.9).
Ошибки: **404** `practice_session_not_found` — чужая сессия, неизвестный элемент или элемент без записи (неотличимо), **503** `practice_audio_unavailable` — файла нет (TWA предлагает «Повторить загрузку» и «Заменить задание»; событие `practice.audio_failed` с `reason: file_missing` — не чаще раза в 10 минут на пару «сессия — элемент»).

#### Коды ошибок практики (сводно)
| Код | HTTP | `details` |
|---|---|---|
| `practice_unavailable` | 403 | — |
| `practice_pro_required` | 403 | — (пейвол `practice`) |
| `practice_limit_reached` | 403 | `{kind, limit, used, resets_at}` — `kind` `sessions` \| `battles`; лимит дня Free (G1), пейвол `practice_limit` с остатком |
| `practice_mode_disabled` | 409 | `{mode}` |
| `practice_not_enough_material` | 422 | `{mode, available, required, reason, course, lesson_id}` |
| `practice_session_active` | 409 | `{session_id, mode, course, course_title, lesson_id, lesson, progress}` (`lesson` — `{stage, number, title}` или `null`) |
| `practice_session_not_found` | 404 | — |
| `practice_session_closed` | 410 | `{finish_reason}` |
| `element_not_current` | 409 | `{current}` |
| `answer_already_accepted` | 409 | `{verdict}` (в баттле ещё `points`) |
| `pair_already_matched` | 409 | `{left_id}` |
| `step_not_current` | 409 | `{step}` |
| `invalid_answer_shape` | 422 | `{expected}` |
| `unknown_element_id` | 422 | — |
| `replace_not_allowed` | 409 | `{reason}` |
| `request_id_reused` | 409 | — (`request_id` уже принят как запрос другого вида: ответ ≠ замена) |
| `retry_not_available` | 409 | — |
| `practice_audio_unavailable` | 503 | — |
| `course_not_found` / `lesson_not_found` | 404 | — |
| `lesson_not_in_course` | 422 | — |

TWA: каждый код — в `KNOWN_ERROR_CODES` и `error.code.<код>` (ru/uz; новый в 18.9b — `request_id_reused`, в G1 — `practice_limit_reached`); клиент разбирает `details` у `practice_session_active`, `element_not_current`, `answer_already_accepted`, `practice_limit_reached`.

**Запросы к БД** (`PracticeQueryBudgetTest`, `DictationSessionTest`, `PracticeRetryTest`; `TwaAuth` с его запросом `learners` включён): хаб ≤ 9; открытие ≤ 12, открытие повтора ≤ 13; `GET` сессии ≤ 3 (завершённая с ошибками-заданиями — 3, пары — 2); ответ ≤ 6, последний ответ сессии (с итогом) ≤ 7; замена диктанта ≤ 7; аудио ≤ 2. Снимок сессии — не больше 40 КБ в UTF-8 (`Snapshot::MAX_BYTES`), ответ сессии — не больше 8 КБ.

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
- Чтение матча не «чистое»: `GET /battles/{id}`, `…/poll` и `…/round`, если назрел переход по времени, выполняют его под блокировкой строки — кто первый посмотрел, тот и двигает матч, — и затем (18.12a: **после** перехода и только у живого матча) пишут присутствие игрока (условный UPDATE, не чаще раза в 5 с). Переход считается по прежним отметкам: вернувшийся после `presence_grace_seconds` не «присутствует» к моменту решения — его собственный запрос заканчивает матч против него (`finished/absent`) или, если пропали оба, закрывает без победителя (`closed/both_absent`). `GET /battles` присутствие не пишет,
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
(`cancel` → 409 `battle_wrong_state`). Сдача — поражение сдавшегося, победа соперника, очки остаются как есть (даже если сдавшийся вёл). Тихий выход (тишина дольше `presence_grace_seconds`) в `room`/`countdown` отменяет матч, в `playing` — техническое поражение молчащего; молчали оба — `closed/both_absent` без победителя. Решают часы, а не порядок запросов (18.12a): исход один и тот же, замечает ли его опрос соперника, дворник или первый запрос самого вернувшегося.

#### `BattleResource` — один вид матча для всех статусов
Отдают `GET /battles/{id}`, 201 приглашения, ответ каждого действия (`accept`, `decline`, `cancel`, `ready`, `surrender`) и поле `battle` ответа `audio-failed`. Все ключи есть всегда, отсутствующее — `null`; порядок ключей как в таблице.

| Ключ | Тип | Смысл |
|---|---|---|
| `id`, `status`, `mode` | int, string, string | `status` — таблица выше; `mode` — `pairs` · `collocation` · `sentence` · `dictation` |
| `end_reason` | string \| null | только у терминального: `completed`, `surrender`, `absent` (у `finished`); `declined`, `invite_expired`, `room_expired`, `canceled`, `both_absent`, `voided` (у `closed`) |
| `role` | string | моя сторона: `challenger` (пригласил) · `opponent` (приглашён) |
| `course` | `{slug, title}` | название на моём языке |
| `lesson` | `{id, stage, number, title}` \| null | `null` — матч на весь курс; `stage`/`number` — номера модуля и урока, `title` может быть `null` |
| `opponent` | `{first_name, pro_emoji}` | соперник; `pro_emoji` (с 26.11.2026, docs/31 §5а) — его эмодзи Pro, пока Pro действует, иначе `null` (+1 запрос к виду; то же поле — у `opponent` / `from` списка `GET /battles` одним запросом на список и у `friend` в `GET /battles/options`) |
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
| `presence_grace_seconds` | Матч кончается, когда от `opponent_absent_since` прошло столько секунд (в `playing` — победа остающегося, в `room`/`countdown` — отмена); момент замечает ближайший запрос любого игрока — в том числе самого молчавшего, когда он возвращается, — или дворник (18.12a: часы раньше отметки присутствия). «Осталось» на экране: `opponent_absent_since + presence_grace_seconds − server_now`; после перезагрузки число то же. Предупреждение (10 с) и конец (30 с) — разные пороги, конец решает только 30. Правило одно для обоих: молчащим можете оказаться и вы. Значение не ниже 15 с (`heartbeat_write_seconds` + самая долгая пауза опроса, `BattleSettings::presenceGraceFloorSeconds`): меньшее из окружения поднимается до границы, экран читает действующее |
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
`friendship_id`, `mode`, `course` обязательны, `lesson_id` — целое ≥ 1 или `null` (весь курс). Ответ **201** `BattleInvitedResource` — `BattleResource` (`status: invited`) плюс ключ `limits`: лимиты Free на сегодня **после** этого вызова в форме `practice.limits` из `/me` (A.2), `null` у Pro (у остальных ответов баттла ключа нет); другу уходит ЛС (B.14) и событие `battle.invited`. Снимок на 5 раундов строится сразу, на языках обоих игроков.
- **Лимит вызовов Free** (G1, A.16, docs/18 §3): `PRACTICE_FREE_BATTLES_PER_DAY` (1) вызовов в локальный день — считаются вызовы ученика (`challenger_id`), которые **ждут ответа или идут** (`invited`, `room`, `countdown`, `playing`) и **сыгранные** (`finished`; `closed/both_absent`); **реванш — тому, кто его предлагает**. Вызов, который не начался не по вине вызвавшего, **возвращается**: `end_reason` `declined` / `invite_expired` / `canceled` / `room_expired` / `voided` не считаются, просроченное и ещё не закрытое приглашение — тоже (следующий вызов закрывает его по пути). `accept` всегда бесплатен и не считается (Pro-друг приводит Free-друзей). Отказ — **403** `practice_limit_reached` `{kind: "battles", limit, used, resets_at}` и событие `practice.limit_reached`; решает подсчёт под `FOR NO KEY UPDATE` строки инициатора в транзакции приглашения (два приглашения в один миг → одно 201, другое 403 по лимиту, не `battle_busy`).
- **Реванш:** `{ "rematch_of": 15 }` — мой терминальный матч (`finished` или `closed`). Друг — соперник того матча (пара всё ещё `accepted`, иначе **403** `not_friends`), формат, курс и урок — оттуда, снимок новый (при ровно 5 заданиях те же задания в другом порядке).
  Остальные поля тела при `rematch_of` **не читаются и не валидируются** (TWA шлёт `mode`, `course`, `lesson_id`, `friendship_id` заодно — это не 422, даже если там мусор). `rematch_of` чужого матча → **404** `battle_not_found`; матча, который ещё идёт → **409** `battle_wrong_state {status}`.
- **Занятость.** Один живой матч на ученика (unique `battle_participants.active_learner_id`): второе приглашение → **409** `battle_busy {battle_id}` — id моего матча. Встречное приглашение (друг **уже пригласил меня**, оно открыто) тоже **409** `battle_busy`, но с id **его** приглашения: открой его и прими
  (оба нажали «Реванш» с разницей в секунды — второй попадает сюда). Встречные приглашения атомарны: незавершённый матч пары держит ключ `battles.open_pair` (unique, docs/03), поэтому из двух приглашений одной пары, пересёкшихся даже в одну миллисекунду, второе получает тот же **409** `battle_busy` с id первого — без строки, события и ЛС (18.6-fix). Своё просроченное, ещё не выметенное приглашение занятым не делает: сервер закрывает его по пути (`closed/invite_expired`, событие) и повторяет действие один раз.
- Порядок проверок и ошибки: доступ (403) → `battle_disabled` (409) → [реванш: 404/409] → `practice_mode_disabled` (409 `{mode}`) → пара (404 `friendship_not_found`, 403 `not_friends`) → встречное `battle_busy` (409, id **его** открытого приглашения — до лимита: ученика у лимита ведут в приглашение друга, строки это не создаёт) → `practice_limit_reached` (403, G1 — до курса и материала) → `battle_friend_no_access` (409) → курс (404 `course_not_found`) → урок (404 `lesson_not_found`, 422 `lesson_not_in_course`) → материал
  (422 `battle_material_unavailable` с `details` как у `practice_not_enough_material`, событие `practice.not_enough_material {battle: true}`) → запись (нарушение unique `active_learner_id` → `battle_busy` с id моего матча, `open_pair` → с id матча пары). Поэтому при встречном приглашении и нехватке материала ответ — 422, а не 409. Валидация Laravel — 422 до всего этого.

#### Действия: `accept` · `decline` · `cancel` · `ready` · `surrender`
`POST /practice/battles/{id}/<действие>`, без тела, кроме `surrender` (`{ "confirm": true }`; без него **422** — выход из игры не делается случайным запросом). Ответ **200** `BattleResource` — матч таким, каким его видит действующий.

| Действие | Кто | Что делает | Отказы |
|---|---|---|---|
| `accept` | приглашённый | `invited` → `room`; оба «присутствуют» с этой секунды; занимает приглашённого (`active_learner_id`). Для матча, который уже идёт, — 200 без изменений (двойное нажатие). Своё просроченное приглашение, которое держало «занятым», закрывается по пути, и принятие повторяется один раз | 403 `battle_not_recipient`; 409 `battle_closed` (просрочено — `invite_expired`; дружба кончилась — `canceled`); 409 `battle_disabled`; 409 `practice_mode_disabled {mode}` (формат выключили после приглашения — оно остаётся `invited` до истечения); 409 `battle_friend_no_access` (у пригласившего больше нет практики); 409 `battle_busy {battle_id}` (я занят другим) |
| `decline` | приглашённый | `invited` → `closed/declined` | 403 `battle_not_recipient`; 409 `battle_wrong_state` (`room`, `countdown`, `playing`), `battle_closed` |
| `cancel` | инициатор — приглашение; любой — комнату и отсчёт | → `closed/canceled` (`meta.left` — кто вышел) | 403 `battle_not_challenger`; 409 `battle_wrong_state` (`playing` — сдайтесь), `battle_closed` |
| `ready` | оба игрока в `room` | отмечает готовность; оба → `countdown`. Диктант: сервер проверяет 5 файлов, нет — матч сразу `closed/voided` (в ответе 200, статус виден). В `countdown`/`playing` — 200 без изменений | 409 `battle_wrong_state` (`invited`), `battle_closed` |
| `surrender` | любой в `countdown`, `playing` | → `finished/surrender`, победа соперника | 422 (`confirm`); 409 `battle_wrong_state` (`invited`, `room`), `battle_closed` |

Любое действие в первую очередь доводит матч до «сейчас» (просроченное приглашение закрывается, назревший раунд разрешается) — отказ, возникший **после** такого перехода, не откатывает его.
Присутствие (`last_seen_at`) обновляют `accept` (у обоих игроков), `ready`, `answer`, `surrender`, `audio-failed` и чтения `GET /battles/{id}`, `…/poll`, `…/round`; `decline`, `cancel` и список — нет. Отметка пишется **после** ленивых переходов и только если матч ещё живой (18.12a): действие или чтение игрока, чей grace истёк, его не спасает — матч заканчивается этим же запросом (`finished/absent` или `closed/both_absent`), а в `room`/`countdown` отменяется (`closed/canceled`, `meta.left` — он). События: `battle.accepted` — при принятии, `battle.started` — когда оба готовы, `battle.closed` / `battle.finished` — при конце.

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
- Порядок проверок под блокировкой строки матча: ленивые переходы (по прежним отметкам присутствия) → присутствие автора (если матч ещё живой; 18.12a) → повтор `request_id` (200) → терминальный (409 `battle_closed`) → отсчёт (409 `round_not_open {round, phase: null}`) → не `playing` (409 `battle_wrong_state`) → раунд позже текущего (409 `round_not_open`) → раунд раньше текущего, пауза разбора или дедлайн + грейс прошли (409 `round_closed`) →
  второй ответ (409 `answer_already_accepted`) → форма (422 `invalid_answer_shape {expected}`, `unknown_element_id`). Форма пар в баттле — `bijection`, предложения — `order_of_all_fragments`, сочетания — `option_id`, диктанта — `text_or_skip`.
- Тайм-аут: если игрок не ответил до `deadline_at + deadline_grace_ms`, ближайший обратившийся (опрос любого игрока, действие, дворник) разрешает раунд: пишет игроку строку `timeout` (0 очков, `request_id` — случайный uuid v4, который клиент не может предугадать и занять заранее; docs/16 §2.7) и начинает `reveal`. Отвечать после этого поздно — 409 `round_closed`.

#### `POST /api/twa/practice/battles/{id}/audio-failed` — клип диктанта не играет
```json
{ "request_id": "3d5f0a8e-77b1-4a43-9c2e-6d1d9f5b2a10", "element_id": "ejIzGfbYA0Z" }
```
Сервер сам проверяет файл элемента. Ответ **200** `{ "verified": true | false, "battle": { "...BattleResource..." } }`:
- `verified: true` — файла нет (или он пуст): матч `closed/voided` для обоих (`end_reason: "voided"`, `result.outcome: "none"`), очки не засчитаны;
- `verified: false` — файл на месте, беда локальная, матч идёт дальше (игрок отвечает без звука или получает 0 за тайм-аут). Разрешён в `room`, `countdown`, `playing`; повтор с тем же `request_id` отвечает первым отчётом (идемпотентно, одно событие `practice.audio_failed`).
- Ошибки: 409 `battle_wrong_state` (`invited`), `battle_closed` (терминальный; повтор того же `request_id` — 200), 422 `unknown_element_id` (элемент не из этого матча или без записи).

#### `GET /api/twa/practice/battles/{id}/audio/{element}` — клип диктанта (вне `twa-api`)
Тело — `audio/mpeg`, заголовки `Cache-Control: private, no-store`, `Content-Length`, `X-Content-Type-Options: nosniff`; путь на диске и текст в API не выходят. TWA берёт URL из `audio_preload` или `round.element.audio_url` и грузит клип с `Authorization` в `blob:`. Слово на R2 — **200** `{ "url" }` вместо тела, как у клипа сессии (docs/26 §9.9); список `audio_preload` и `audio_url` раундов остаются путями API. Клип, который устройство не смогло загрузить с R2, TWA сообщает тем же `audio-failed`: сервер сам проверяет файл в бакете (HEAD) и аннулирует матч, если его нет.
- Клип отдаётся участнику, если раунд этого элемента **уже начался** (номер раунда ≤ `current_round`; матч, который закончился, помнит свой последний раунд — все пять), либо матч в `room` / `countdown` (предзагрузка всех пяти: «Готов» без неё нельзя, а звук текст не раскрывает).
  Клип будущего раунда, чужой матч, неизвестный элемент и элемент без записи — один и тот же **404** `battle_not_found`. Файла нет → **503** `practice_audio_unavailable` и больше ничего (матч не трогается, событие не пишется): отчёт, который аннулирует матч, — `audio-failed`.

#### Коды ошибок баттла (сводно)
Коды практики общие с A.16 (`practice_unavailable`, `practice_pro_required`, `practice_mode_disabled`, `answer_already_accepted`, `invalid_answer_shape`, `unknown_element_id`, `practice_audio_unavailable`, `course_not_found`, `lesson_not_found`, `lesson_not_in_course`); дружбы — A.14. TWA: каждый код в `KNOWN_ERROR_CODES` и `error.code.<код>`.

| Код | HTTP | `details` | Где и когда |
|---|---|---|---|
| `practice_unavailable` | 403 | — | любой маршрут: практика закрыта (`off`, не в пилоте) |
| `practice_pro_required` | 403 | — | любой маршрут: Free при `PRACTICE_PLAN=pro` (пейвол `practice`) |
| `practice_limit_reached` | 403 | `{kind: "battles", limit, used, resets_at}` | `POST /battles` (приглашение и реванш): Free исчерпал вызовы дня (G1) — ожидающий или сыгранный вызов; отклонённый / просроченный / отозванный / с истёкшей комнатой / аннулированный возвращается; `accept` не ограничен |
| `battle_disabled` | 409 | — | `options`, `POST /battles`, `accept`: `PRACTICE_BATTLE=false` |
| `practice_mode_disabled` | 409 | `{mode}` | `POST /battles`: формат выключен (`PRACTICE_MODE_*`); `accept`: формат выключили после приглашения — оно остаётся `invited` до истечения |
| `battle_busy` | 409 | `{battle_id}` | `POST /battles`: я занят своим матчем (id) либо друг уже пригласил меня (id **его** приглашения); `accept`: я занят другим матчем. Называет только матчи, где спрашивающий — участник. `battle_id` бывает `null`, если матч, державший ученика, успел закрыться |
| `battle_friend_no_access` | 409 | — | `POST /battles`: у друга нет доступа к практике; `accept`: у пригласившего больше нет доступа к практике |
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
6. Присутствие: `opponent_present = false` → полоса «соперник потерял связь» с остатком `opponent_absent_since + presence_grace_seconds − server_now`. Свой уход в фон дольше 30 с — это техническое поражение: первый же запрос после возврата заканчивает матч `finished/absent` (или `closed/both_absent`, если пропали оба) — дворника ждать не нужно, и «успеть первым» не помогает (18.12a).
7. После конца: итог из `result`; реванш — `POST /battles {rematch_of}`; предложение соперника — `rematch.by_me = false` и `status = invited`. На 409 `battle_busy` с чужим приглашением — открыть его (`GET /battles/{battle_id}`) и принять.

**События аналитики** (`product_events`, после commit, актёр — инициатор матча; в props только числа и коды): `battle.invited` `{battle_id, mode, course_id, lesson_id, rematch}`; `battle.accepted` `{battle_id}`; `battle.started` `{battle_id, mode}`;
`battle.finished` `{battle_id, end_reason, result (challenger|opponent|draw), rounds_played, duration_ms}`; `battle.closed` `{battle_id, end_reason}`; `practice.not_enough_material` (с `battle: true`), `practice.audio_failed` `{battle_id, source (word|phrase), reason (file_missing|client_report), verified}` и `practice.limit_reached` `{kind: "battles", limit}` (отказ приглашения по лимиту Free, актёр — инициатор). Событие пишется один раз, после commit той транзакции, которая изменила состояние матча, — не опросом и не повтором запроса.

### A.18 Игра «Караван» — карта (docs/18 §12.8–§12.9; задача G2.1m)

Остальные маршруты игры (`/game/runs`, кошелёк, лавка, профиль, класс) добавляют свои задачи в этот же раздел. Все маршруты — `/api/twa/game/*`, `TwaAuth` + `SetTwaLocale`, `throttle:twa-api`.

**`GET /api/twa/game/map?course={slug}`** — карта курса: город = модуль (`stages`), стоянка = урок с активными словами (`lessons`). Своей таблицы «городов и стоянок» нет.

- `course` обязателен (TWA подставляет активный курс сам). Недоступный ученику или несуществующий курс — **404** `course_not_found`.
- Игра выключена или ученик вне пилота — **403** `game_unavailable` (один код, без подсказки).
- Названия: город — `lang/{ru,uz}/game.php` по номеру модуля (`CityNames`), «Город N» после 24-го; `title` города и стоянки — название модуля / урока на языке ученика (`DeckTitles`, `null` — без названия, клиент подставляет «Модуль N»).

```json
{
  "course": {"slug": "english-a1", "title": "Английский A1"},
  "cities": [{
    "stage_id": 3, "number": 1, "city": "Самарканд", "title": "Еда", "unlocked": true,
    "stops": [{"lesson_id": 11, "number": 1, "title": "Фрукты", "unlocked": true, "stars": 2, "best_score": 120, "runs": 3}],
    "boss": {"state": "not_ready", "defeated_at": null, "introduced": 5, "required": 8, "cleared_stops": 1, "required_stops": 3}
  }],
  "daily": {"available": true, "done_today": false, "due_count": 14, "resets_at": "2026-11-06T19:00:00.000Z"},
  "me": {"stops_cleared": 4, "cities_cleared": 1, "coins": 35, "tickets": 2},
  "friends": [{"friendship_id": 11, "name": "Dilnoza", "city_number": 2, "stops_cleared": 7, "camel_asset": null}],
  "active_run": {"id": 9, "kind": "stop", "progress": {"answered": 3, "total": 9}},
  "class_caravan": { "id": 7, "stars_total": 9, "target_stars": 18, "ends_at": "2026-11-12T10:00:00.000Z" },  // или null
  "limits": {"runs": {"limit": 1, "used": 0, "remaining": 1, "resets_at": "2026-11-05T19:00:00.000Z"}, "tickets": {"balance": 0, "max": 10}, "boosters": {"limit": 1, "used": 0, "remaining": 1, "resets_at": "2026-11-08T19:00:00.000Z"}, "boss_stages": 1}
}
```

- **Стоянка `unlocked`** ⇔ у ученика есть строка `word_repetitions` по активному слову урока (урок начат в карточках; состояние `new` тоже считается). Не хранится, выводится одним запросом. `stars` / `best_score` / `runs` — из `game_stop_progress` (0, пока забегов не было). Уроки без активных слов на карту не попадают.
- **Город `unlocked`** ⇔ открыта хотя бы одна его стоянка.
- **`boss.state`**: `defeated` (есть `game_city_progress.boss_defeated_at`; остаётся и после конца Pro) · `locked_by_plan` (Free, `stages.number > game.free_limits.boss_stages`, нет фичи `game.all_bosses`; пустой лимит — без ограничения, `0` — только Pro) · `ready` (введено слов города `introduced >= required` и очищено — ≥ 1 звезда — не меньше `required_stops` стоянок) · `not_ready`. `required` = `boss.min_words` (8); `required_stops` = `min(boss.min_cleared_stops (3), число стоянок города)`. Бой с боссом — G2.4.
- **`daily`**: `due_count` — слова к повторению ученика (то же определение, что у `/today`); `done_today` — караван дня уже сыгран (завершён любой причиной: второй в тот же день невозможен); `available` ⇔ не сыгран, нет идущего забега и `due_count > 0`; `resets_at` — начало следующего локального дня ученика.
- **`me`**: из `game_profiles` и `game_wallets` (нули, пока строк нет).
- **`friends[]`**: принятые друзья с прогрессом по этому курсу (`game_stop_progress`, ≥ 1 звезда), по убыванию `stops_cleared`, не больше `game.map.friends_max` (20); `city_number` — самый дальний город, где друг очистил стоянку; `camel_asset` — `null` до G2.6. Прогресс друзей может отставать до 5 минут (кэш `game:friends`, docs/19 §2.2).
- **`active_run`**: идущий и не просроченный забег ученика (`progress {answered, total}`) или `null`.
- **`class_caravan`** (G2.9b) — карточка идущего каравана класса `{id, stars_total, target_stars, ends_at}` или `null` (одно чтение: тот же караван, в который попадают забеги ученика — самый ранний `starts_at`); тот же блок `class_caravan` в `/today.game`. **`limits`** (G2.5b ч.2) — тот же блок, что `/me.game.limits` (`GameLimits::describe`, один запрос; форма — A.2): забеги дня, билеты, бустеры недели, `boss_stages`. Карта открыта только ученику, которому игра доступна, поэтому здесь это всегда объект; тот же блок приходит и в 201 `POST /game/runs` (ключ `limits` рядом с полями забега) — клиент видит остаток дня и недели сразу после старта.

#### Забег (G2.2b + G2.2c)

Все пять маршрутов — `throttle:twa-api` плюс свой лимитер по ученику; аудио — вне `twa-api`. Игра выключена / ученик вне пилота — **403** `game_unavailable`; чужой и несуществующий забег — **404** `game_run_not_found`; закрытый (`finished`, не принявший ответ / подсказку) — **410** `game_run_closed {finish_reason}`. Ошибки — `ApiErrorResource` с `details` (коды — docs/18 §12.8). Даты — ISO 8601 UTC с мс.

| Маршрут | Лимитер | Тело → ответ |
|---|---|---|
| `POST /game/runs` (201) | `twa-game-start` 10/мин | `{kind: "stop", course, lesson_id, replace_active?}` → забег. `{kind: "daily", replace_active?}` (G2.3) → ежедневный караван на due-словах ученика (курс — активный курс ученика, как у `/today`; `course` и `lesson_id` не нужны и игнорируются). `{kind: "boss", course, stage_id, replace_active?}` (G2.4) → бой с боссом города (12 самых трудных слов ученика в городе, набор фиксируется в снимке; бой без фляг; забег дня / билет стоит так же, как стоянка). `{kind: "class_boss", class_caravan_id, replace_active?}` (G2.9a) → бой с боссом класса на словах, зафиксированных в караване при его старте (`course` и `lesson_id` не нужны); один бой на участника (`class_boss_key`; бой, закрытый без единого ответа, попыткой не считается и ключ отдаёт — G2.13, docs/18 §12.11); забега дня и билета **не стоит**. Ошибки босса класса: 404 `class_caravan_not_found` (чужой, отменённый или истёкший караван), 409 `class_boss_locked` (цель каравана ещё не взята), 409 `class_boss_done` (участник уже бился — бой с хотя бы одним ответом; проигравшая гонку вкладка получает идущий бой победителя, G2.13), 422 `game_not_enough_material {reason: no_words}`; без `class_caravan_id` — 422 валидации. Ошибки босса: 404 `course_not_found` / `stage_not_found`, 422 `stage_not_in_course` / `game_not_enough_material {reason: no_words}`, 409 `boss_not_ready {introduced, required, cleared_stops, required_stops}` (тот же расчёт, что `boss.state` на карте; у побеждённого босса готовность не проверяется — повторный бой всегда доступен). Ошибки: 404 `course_not_found` / `lesson_not_found`, 422 `lesson_not_in_course` / `game_not_enough_material {reason: no_words}`, 409 `stop_locked` / `game_run_active {run_id, kind, progress}` / `game_daily_done {resets_at}` (караван этого локального дня уже начат — любым исходом: один в день, факт БД `daily_key`; проигравшая гонку вкладка получает тот же 409). У каравана дня лимитов Free нет, билет не тратится; **403 `game_limit_reached {kind: "runs", limit, used, tickets: 0, resets_at}`** (G2.8) — Free сыграл забеги дня и билетов нет: пейвол `game`, лид по `kind`; с билетом забег стартует и стоит билет (`free_slot = ticket` в событии `game.run_started`, `tickets` в `/me` на один меньше). Активный забег той же стоянки (вторая вкладка) возвращается как есть и лимитом не проверяется. Босс города дальше `GAME_FREE_BOSS_STAGES` у Free — 403 `game_pro_required {kind: "boss", stage_number}` (G2.4) |
| `GET /game/runs/{id}` | `twa-api` | забег |
| `POST /game/runs/{id}/answer` | `twa-game-answer` 120/мин | `{request_id (uuid), element_id, answer, time_spent_ms?}` → `{verdict, points, reveal, flask_lost, combo, event_applied, run}` (для раунда пар добавляются `pair`, `round_done`). Очки за слова v2 (docs/32 §3.6, §8.1): в `live` у словесных задач (`translate_fwd`, `translate_back`, `listen`, `spell`, `gap`) добавляется блок `score` той же формы, что в `POST /training/…/review` (A.3), — он пишется в тело попытки, повтор `request_id` отдаёт тот же блок; при `off` / `shadow` ключа `score` нет (тело прежнее) |
| `POST /game/runs/{id}/hint` | `twa-game-answer` | `{request_id, element_id}` → `{hint: {options_removed[]} \| {prefix}, coins: {balance}, run}`; 409 `hint_not_available`, `coins_insufficient {balance, price}` |
| `POST /game/runs/{id}/booster` | `twa-game-answer` | `{request_id (uuid), item_key, element_id?}` → забег (`GameRunResource`, как `GET`). Бустер из инвентаря ученика: `hint` (подсказка текущего элемента в форме вида; `element_id` необязателен, по умолчанию текущий; результат в `current.element` — убранные варианты / `prefix`), `extra_flask` (+1 фляга, `flasks_max` растёт до `game.run.flasks_max_with_booster`), `second_chance` (первая ошибка забега не стоит фляги). Ошибки: 404 `item_not_found` (нет такого предмета или он не бустер); 409 `booster_not_owned` (`qty = 0`), `booster_limit_reached {limit, used, resets_at, upsell}` (`upsell: true` — у ученика нет `game.boosters_plus`, Pro снял бы лимит: только тогда TWA показывает пейвол; Pro на своём лимите — `false`, G2.13), `booster_already_used` (этот вид уже применён к элементу / в забеге), `booster_not_applicable` (`extra_flask` / `second_chance` в забеге без фляг — босс), `hint_not_available` (у вида или у элемента подсказка уже есть), `element_not_current {current}`, `answer_already_accepted {verdict}`, `request_id_reused`; 410 `game_run_closed` |
| `POST /game/runs/{id}/abandon` | `twa-api` | `{confirm: true}` → забег `finished/abandoned` |
| `GET /game/runs/{id}/audio/{element}` | `twa-practice-audio` (вне `twa-api`) | поток `audio/mpeg`, `Cache-Control: private, no-store` (слово на R2 — **200** `{ "url" }`, docs/26 §9.9); элемент дальше текущего, без файла или чужой — **404**; файла нет на диске — **503** `practice_audio_unavailable` |

`answer.*` — как в практике: `option_id` (виды с вариантами), `text` (`spell`, `gap`, `dictation`), `order` (`sentence`), `left_id` + `right_id` (`pairs_round`), `skip: true` (пропуск — не знаю); форму проверяет вид: 422 `invalid_answer_shape`, `unknown_option_id`, `unknown_element_id`.

Забег (`GET`, `POST /runs`, `run` в ответах, `abandon`):

```json
{
  "id": 9, "kind": "stop", "status": "active", "finish_reason": null,
  "course": {"slug": "english-a1"}, "lesson_id": 11, "stage_id": null, "class_caravan_id": null,
  "city": "Самарканд", "stage_number": 1, "lesson_number": 1, "lesson_title": "Фрукты", "scope_widened": false,
  "total": 9, "position": 3, "flasks": 3, "flasks_max": 3, "combo": 2, "score": 40,
  "current": {"element": {"id": "e3k9", "kind": "translate_fwd", "prompt": "apple", "audio": true, "audio_url": "/api/twa/game/runs/9/audio/e3k9", "options": [{"id": "o1a2", "text": "яблоко"}]}, "event": "bazaar"},
  "boosters": {"available": [{"key": "booster_hint", "effect": "hint", "qty": 2}], "used": [{"key": "booster_extra_flask", "effect": "extra_flask", "element_id": null}]},
  "hint": {"price": 5, "available": true},
  "summary": null,
  "server_now": "2026-11-05T10:00:00.000Z"
}
```

- `course {slug}` и `lesson_id` (G2.7b): куда вернуться с итога — курс забега (`snapshot.course_slug`; для `daily` это курс записи, активный курс ученика) и урок стоянки (`game_runs.lesson_id`; у `daily` и боссов — `null`). По ним «Ещё раз» и «На карту» собирают адрес без query. `course` — `null`, только если снимок не помнит slug. `stage_id` (G2.13) — город боя **только у `boss`** (отметка «поделился» после перезагрузки или «Продолжить» с «Сегодня»), `class_caravan_id` — **только у `class_boss`** (возврат на экран класса после боя); у остальных видов оба `null`.
- `current` — `null`, когда забег закончен; `current.element` — проекция вида (без правильных ответов, `word_id`, `audio_path` и прочих ключей снимка; запретные ключи ловит `GameContractTest`); `audio_url` (маршрут аудио забега) есть у `listen` и `dictation`; `event` — `sandstorm` / `oasis` / `bazaar` / `null`. `phrase: true` **(с 26.11.2026, docs/35 §3.3 e)** — у задания из фразы (`translate_fwd` / `translate_back` / `listen` / `gap`): экран раскладывает фразу, не угадывая по длине текста; только признак, ответа не раскрывает. У слова и у заданий забегов, начатых до признака, ключа нет (= слово). Пишется в снимок задания при сборке (`WordTaskHandler`), операторов не добавляет.
- `boosters` (G2.5b ч.2): `available[] {key, effect, qty}` — бустеры инвентаря ученика с `qty > 0` (только пока забег идёт; у закончившегося `[]`), `used[] {key, effect, element_id}` — применённые в этом забеге (`element_id` — только у `hint`, иначе `null`; хранятся в `state`, без запроса). `summary` — `null`, пока забег идёт; потом `{stars, score, coins, league_points, srs_pulled, mistakes[] {element_id, kind, label, your_answer, verdict, hinted}, streak_held, new_stars, boss}`. G2.13: `mistakes[].hinted` — строка в списке из-за подсказки (базар или бустер: верный ответ после подсказки — ошибка для SRS, docs/18 §12.4), `verdict` при этом честный (`correct`) — TWA показывает чип «подсказка», не красный; `streak_held` — **это** завершение удержало день серии (финишер поставил `game_held_at`, цель день ещё не держала; `false` у второго забега дня, дня с целью, брошенного и просроченного забега); `new_stars` — звёзд сверх прежнего лучшего результата стоянки (0 у босса, каравана дня и босса класса). `summary.boss` — `null`, кроме забегов `boss` / `class_boss`; у них `{accuracy (0..100, round(100 × correct / total)), won (accuracy ≥ 80 %, `typo` не считается), first (эта победа — первая над боссом города; у `class_boss` всегда false)}`; `stars` босса — 3 за победу, иначе 0.
- Повтор того же `request_id` (ответ и подсказка) возвращает тот же `verdict` / `hint` и актуальный `run`; другой `request_id` на уже отвеченный элемент — 409 `answer_already_accepted {verdict}`; тот же `request_id` с другим элементом — 409 `request_id_reused`; не текущий элемент — 409 `element_not_current {current}`.
- Просроченный забег (`expires_at`) закрывается лениво любым обращением (`finished/expired`) и командой `game:close-expired` (каждые 15 минут, `withoutOverlapping`).
- События: `game.run_started` пишет `RunService` при старте (после commit); `game.run_finished` и награды — G2.5a; `game.limit_reached {kind, limit}` при каждом отказе лимита (вежливом и под замком), `game.ticket_spent {run_id}` при старте по билету (G2.8).
- Бустер (G2.5b ч.2): под замком забега и с идемпотентностью по `request_id` (повтор — тот же забег, второго списания нет). Порядок проверок: предмет (404) → `booster_not_owned` → один бустер вида на элемент / на забег → лимит недели (`GameLimits::assertBoosterAllowed`, 409 `booster_limit_reached`) → атомарный расход `qty` → запись `game_booster_uses` (`week_start`) и попытки `kind = booster`. Лимит недели считается по использованиям, а не по покупкам. Подсказка бустером не списывает монет; подсказка базара (`POST …/hint`) по-прежнему только за монеты. Событие `game.booster_used {run_id, effect}` после commit.
- `summary.boss` (G2.4, G2.7b) — у боя с боссом: `{accuracy, won, first, share}`; `share {link, text, title}` — пара «Поделиться» карточки города и её заголовок (`title` — «Я дошёл до Самарканда», `text` — «…в игре «Караван» — LexiFlow!…»: пригласительная ссылка ученика и тексты на его языке — **те же**, что в ЛС-карточке `GameCardSender`, одно правило), только у первой победы (`won && first`), иначе `null` (и пока у бота нет username — ссылки нет). TWA открывает `shareUrl(link, text)` и ставит отметку ручкой ниже.
- **`POST /api/twa/game/cities/{stage}/shared`** (G2.7b, `twa-api`; по образцу `POST /achievements/{key}/shared`) — ученик открыл диалог «Поделиться» карточкой города: ставит `game_city_progress.shared_at` один раз (+ событие `game.city_shared {stage_id}`), повтор ничего не меняет и отвечает тем же. Ответ `200 {stage_id, shared_at}`. **404** `stage_not_found` — город не побеждён этим учеником (нет строки, бой проигран, чужой город, нет такого модуля: одним кодом), **403** `game_unavailable` — игра закрыта.
- Лимит дня (G2.8, docs/18 §12.7.2): считаются забеги `stop` / `boss`, начатые в окне локального дня, кроме брошенных или просроченных **без единого ответа** (место возвращается); активный забег место занимает, пока не закрыт — кроме просроченного без ответа, которого планировщик ещё не закрыл: `/me.game.limits` и `limits` карты считают его закрытым (места нет, билет возвращён), как считает его старт (G2.13). Pro с `game.unlimited_runs` не считается и билетов не тратит.

#### Кошелёк, лавка, профиль (G2.5b, часть 1)

Четыре группы маршрутов в `/api/twa/game/*`: `TwaAuth` + `SetTwaLocale` + `throttle:twa-api`. Игра выключена / ученик вне пилота — **403** `game_unavailable`. Бустеры **в забеге** (`POST /game/runs/{id}/booster`, `boosters` у забега) — часть 2, см. «Забег» выше: здесь бустер покупается и лежит в инвентаре.

| Маршрут | Тело → ответ |
|---|---|
| `GET /game/wallet` | `{balance, earned_total, spent_total, tickets {balance, max}, ledger[20] {amount, kind, created_at, title}}` |
| `GET /game/shop` | `{items[] {key, kind, slot, effect, price, availability, owned, qty, equipped, locked_reason, title, description, asset}, balance}` |
| `POST /game/shop/{key}/buy` | `{request_id (uuid)}` → тот же `wallet`, что у `GET /game/wallet` (уже после покупки). Проводка `shop:{learner}:{request_id}`; повтор `request_id` — те же 200 и состояние, второго списания нет |
| `GET /game/class` | `{caravans: [{id, group_title, course {slug, title}, status: active \| finished, starts_at, ends_at, stars_total, target_stars, runs_total, boss {unlocked, defeated, my_attempt: won \| lost \| null}}]}` — караваны активных групп, где ученик активный участник, новые первыми, до 10; короткие строки без таблицы участников (G2.9b). Лимитер `twa-api`. 403 `game_unavailable` |
| `GET /game/class/{id}` | те же поля + `members [{name, stars, runs, best_score, boss_won, is_me}]` (по `stars DESC, runs DESC`, только имя — без id, username и telegram id; кэш таблицы 60 с у идущего), `my {stars, runs, best_score, boss_won} \| null`, `summary {target_reached, boss_defeated, finished_at} \| null` (у завершённого). `my_attempt` — по забегу `class_boss_key`: `won` / `lost` после завершения боя (победа — ≥ `boss.required_accuracy`), `null` пока боя не было или он идёт. Чужой, отменённый, несуществующий — 404 `class_caravan_not_found`; 403 `game_unavailable` |
| `GET /game/profile` | `{equipped {slot: {key, asset}}, stops_cleared, cities_cleared, coins, tickets}` (`equipped` — объект, пустой `{}`, пока ничего не надето) |
| `POST /game/profile/equip` | `{slot: camel \| carpet \| tent \| frame, item_key: string \| null}` → `profile`; `null` снимает предмет слота |

- `items[]` — опубликованные предметы (`status = published`) по `sort`; `title` / `description` — на языке ученика (запасной — ru), `asset` — имя из белого списка ассетов Mini App (`App\Domain\Game\Support\GameAssets`), не URL. `owned` — есть в инвентаре **или** это предмет по умолчанию (косметика `availability = base`, `price = 0`: достаётся каждому, запись в инвентарь появляется при первом надевании, `source = default`); `qty` — число бустеров (у косметики 1/0); `equipped` — надет ли сейчас. `locked_reason`: `pro` (`exclusive` / `seasonal` без фичи `game.all_bosses`, ещё не куплен), `achievement` (предмет за достижение, не получен), иначе `null`. Каталог кэшируется (`game:shop`, docs/19 §2.2); `owned`, `qty`, `equipped`, `balance` - никогда.
- Ошибки покупки: **404** `item_not_found` (нет такого опубликованного предмета) · **409** `item_not_purchasable {reason: achievement \| not_for_sale \| pro}` · **409** `item_already_owned` (косметика уже есть; бустеры копятся, `qty + 1`) · **409** `coins_insufficient {balance, price}` · **409** `request_id_reused` (тот же `request_id` у покупки другого предмета) · 422 валидации. Покупка за реальные деньги и обмен монет невозможны.
- Ошибки экипировки: **404** `item_not_found` — ключа нет, предмета нет в инвентаре ученика, предмет не косметика или он не этого слота (все четыре случая выглядят одинаково).
- `ledger[].title` — по виду проводки (`lang/{ru,uz}/game.php`, `ledger.*`); `tickets.max` = `game.tickets.max`.
- События (после commit, `product_events`): `game.item_bought {item_key, kind, price}` · `game.coins_spent {amount, kind = item_purchase, ref_type = game_item, ref_id, balance}` (только при настоящем списании, не при повторе) · `game.cosmetic_equipped {slot, item_key | null}`.

### A.19 Месячный рейтинг с призами (docs/13 §7.6; решение владельца 2026-11-16)
`MonthlyRatingService`. Только чтение; в рейтинг ученик попадает своими занятиями (те же баллы, что в недельных лигах, сумма по локальным датам календарного месяца `LEAGUES_TIMEZONE`, день — не больше `LEAGUES_MONTHLY_DAILY_CAP` баллов). Общий лимит `twa-api`; `MonthlyRatingResource` / `MonthlyRatingMeResource`.

**`GET /api/twa/league/month`** — экран рейтинга:
```json
{
  "enabled": true,
  "opted_out": false,
  "month": { "starts_on": "2026-11-01", "ends_on": "2026-11-30", "closes_at": "2026-11-30T19:00:00+00:00", "days_left": 15, "status": "open", "participants": 1234 },
  "rules": { "daily_cap": 300, "min_active_days": 10, "min_account_days": 14, "places": 3, "top": 50 },
  "prizes": [ { "place": 1, "title": "Telegram Premium на 3 месяца" }, { "place": 2, "title": "…" }, { "place": 3, "title": "…" } ],  // пример; без настроенных призов (умолчание) — []
  "top": [
    { "rank": 1, "name": "Dilnoza", "points": 3120, "active_days": 16, "is_me": false },
    { "rank": 12, "name": "Aziz", "points": 820, "active_days": 9, "is_me": true }
  ],
  "me": { "rank": 12, "points": 820, "active_days": 9, "participant": true, "eligible": false, "missing": { "active_days": 1, "account_days": 0 }, "not_counted_reason": null },
  "last_month": {
    "starts_on": "2026-10-01", "ends_on": "2026-10-31", "status": "confirmed",
    "winners": [ { "place": 1, "name": "Dilnoza", "points": 4200 }, { "place": 2, "name": null, "points": 3900 } ],
    "me": { "rank": 57, "points": 820 }
  }
}
```
`prizes` — только места с заданным названием (`league_months.prizes` месяца или `LEAGUES_MONTHLY_PRIZE_*`; по умолчанию пусто → `[]`, блок призов в приложении скрыт). `enabled` — `LEAGUES_ENABLED && LEAGUES_MONTHLY_ENABLED`; при `false` остальное пустое (`top: []`, `me: null`, `prizes: []`, `month.status: null`, `last_month: null`). `month.closes_at` — UTC-момент 1-го числа следующего месяца 00:00 по зоне лиг; `days_left` — календарных дней до конца **включая сегодня** (1 в последний день, 0 после); `status` — `open | closing | pending_review | confirmed`; `participants` — на момент последнего пересчёта. `prizes` — на языке интерфейса (uz → ru, если нет), пустой список = блок призов не показывать. `top` — первые `rules.top` участников по `points ↓, active_days ↓, старший аккаунт`: **только** `name` (имя, может быть `null`), `points`, `active_days`, `rank`, `is_me`; никаких id, username, Telegram-данных. `me` — **своя строка пересчитана этим же запросом** (остальные — планировщиком, отставание ≤ 10 мин); `null`, если в месяце не было ни одного занятия; `rank: null` у не-участника (`participant: false` — отключил лиги `leagues_opt_out` или заблокирован; онбординг в боте с 25.11.2026 не требуется, docs/34 §3); `not_counted_reason` — почему нет места: `opted_out` | `blocked` (блокировка важнее отказа), `null` у участника; `eligible` — право на приз, `missing` — сколько активных дней / дней возраста аккаунта не хватает (0 — хватает). `last_month` — предыдущий месяц или `null`: `winners` заполнен **только** у `confirmed` (до подтверждения — «итоги проверяются», `[]`), `me` — моё финальное место, если участвовал (есть у `pending_review` и `confirmed`).

**Эмодзи Pro (с 26.11.2026, docs/31 §5а):** у строк `top` — `pro_emoji` (символ, пока у ученика действующий Pro, иначе `null`); едет в кэше топа (`league:month:top:*`): промах +1 оператор, из кэша 0; смена эмодзи сбрасывает топ месяца сразу, истёкший Pro виден не дольше TTL (120 с). У победителей прошлого месяца эмодзи нет.

**Месяц на очках v2 (с 26.11.2026, docs/32 §8.3, S12).** `GET /league/month?category=free|pro&after=…`. У месяца с `rules_version = 2` ответ сохраняет все ключи v1 и добавляет (у месяца v1 их **нет**):
- `rules_version: 2`; `category` — показанная таблица: `?category=` или своя; `my_category` — своя (категория строки месяца, без неё — текущего тарифа: пробный Pro и место в центре — Pro); `read_only` — показана чужая таблица (Free ↔ Pro): места ученика в ней нет, `neighbours: null`;
- `month.participants`, `prizes` (3 места Free / 3 Pro) и `top` — **показанной** таблицы; в строках `top` + `long_words`, `rank` конкурентный (1, 2, 2, 4);
- `me` (всегда своя строка) + `category`, `long_words`, `next_gap` (очки до ближайшего строго лучшего места, `null` у лидера, вне таблицы и при показе чужой таблицы);
- `neighbours: {above: [BoardRow], below: [BoardRow]} | null` — по 2 участника выше и ниже своей строки в своей таблице; `BoardRow = {rank, name, points, long_words, active_days, is_me, pro_emoji}`;
- `next_cursor` — `after` страницы после топа (`null`, если топ — вся таблица); `last_month` месяца v2: `winners` — победители **показанной** категории, `winners_by_category: {free: [...], pro: [...]}`, `me` + `category`.

`?after=<next_cursor>` отвечает **только страницей** (`LeagueBoardResource`): `{rules_version: 2, category, read_only, rows: [BoardRow], next_cursor}` (до 50 строк, keyset по `points ↓, long_words ↓, active_days ↓, learner_id ↑` — совместные места одинаковы на разных страницах, порядок стабилен). Курсор привязан к таблице (`m{месяц}{f|p}.{base64url}`): битый, другой категории или месяца и любой `after` месяца v1 → **422 `invalid_cursor`**; неверная `category` → 422 валидации.

Стоимость (`LeagueApiV2Test`, `LeagueV2BudgetTest`): из кэша — как v1 (своё место `league:month:rank:v1:*` и соседи с отрывом `league:month:near:v1:*` кэшируются на 60 с по своему кортежу — своё начисление видно сразу); промах — топ 1 + эмодзи 1, место 1, соседи и отрыв 6 + эмодзи 1; страница — доска 3 + эмодзи 1.

**Таблица области (с 27.11.2026, docs/35 §2.4, R6–R7; решение владельца docs/31 §5г — открыть можно любую область).** Только у месяца v2: `?scope=all|region` (по умолчанию `all`) и `&region=<код>` (только вместе с `scope=region`; код из `config/regions.php`, иначе 422 валидации). `scope=region` без `region` — своя область: область, в которой зафиксирован месяц ученика (первое начисление месяца; пустая заполняется первой выбранной областью), без строки месяца — текущая область. Ответ месяца v2 получает:
- `scope: "all" | "region"`; `region: {code, name, participants, shown, min_participants} | null` — показанная таблица области (`null` при `scope=all`). Таблица видна от `min_participants` (`LEAGUES_REGIONS_MIN_PARTICIPANTS`, 5): при 4 — `shown: false`, `top: []`, `next_cursor: null`, `neighbours: null` (экран: «В вашей области пока 4 участника»), при 5 — видна;
- в таблице области: `top` и `month.participants` — этой области, `prizes: []` (призов в таблицах области нет), `read_only` — не своя категория или не своя область (тогда `neighbours: null`), соседи — внутри области;
- `me.region: {code, name, participants, shown, min_participants, rank} | null` — **при любом `scope`**: место в области своего месяца (`rank: null`, пока таблица скрыта или ученик не участник; `null` — у месяца нет области);
- `?after=` страницы области — курсор `m{месяц}{f|p}~{код}.{…}`; курсор другой области / всей категории / другого месяца → 422 `invalid_cursor`; страница области несёт `scope: "region"`, `region`.

Ошибки: 422 `region_not_set` — `scope=region` без `region` у ученика без области; 422 `region_scope_unavailable` — область у месяца v1 (режим `off`) или при выключенном рейтинге. Чужая область области ученика не раскрывает: регион другого ученика нигде не выводится. Скрытые (`leagues_opt_out`), заблокированные и удалённые не попадают ни в таблицы, ни в соседей (`participant = false`). Стоимость (`AllTimeRatingBudgetTest`): `GET /league/month` с `me.region` — 15 / 4 операторов (холодный / тёплый; +2 к S12 на промахе — число участников и место в области, оба в кэше), `?scope=region` — 14 / 4.

**`GET /api/twa/league/month/me`** — лёгкий запрос экрана итога занятия (после `POST /finish`): пересчитывает только строку ученика и отвечает
```json
{ "enabled": true, "month": { "starts_on": "2026-11-01", "ends_on": "2026-11-30", "days_left": 15 }, "me": { "rank": 12, "points": 855, "active_days": 9, "participant": true, "eligible": false, "missing": { "active_days": 1, "account_days": 0 }, "not_counted_reason": null } }
```
(`me` — как выше). Экран итога показывает «+N баллов» из `POST /finish.league_points` (A.3) и этой строкой — «в рейтинге месяца: 855 баллов, 12-е место».

**`POST /training/sessions/{id}/finish`** += `league_points: int` — баллы занятия по той же формуле (`reviewed×1 + correct×1 + new_words×2`, плюс бонус цели дня 10, если `goal_met_at` попал в интервал этой сессии); без дополнительных запросов. В месячную сумму день входит с потолком, поэтому «+N» может быть больше прироста места.

**События** (`product_events`): `league.month_closed {month_id, starts_on, participants, candidates}` · `league.month_confirmed {month_id, starts_on, winners}` (user) · `league.month_candidate_disqualified / _reinstated {month_id, candidate_id, rank}` (user, learner) · `league.month_prize_delivered {month_id, candidate_id, place}` (user, learner). Событие Laravel `App\Domain\Engagement\Events\LeagueMonthConfirmed {monthId, startsOn, winners[{learner_id, place, points}]}` — после commit подтверждения; ЛС победителям вешает бот (слушатель; тексты `leagues.month.*`). Массовой рассылки итогов нет — итоги в `last_month`.

### A.19a «За всё время» (docs/32 §5, §8.3, §12.11, S10; таблицы области — docs/35 §2.4)
`Scoring\AllTimeRatingService`, `AllTimeRatingResource` (форма `LeagueBoardResource`), лимит `twa-api`. Только чтение. Считается **только из очков v2** (решение владельца В1): сумма месячного зачёта рейтинговых дней по категории месяца — 800 + 600 в месяцах Free и 950 в месяце Pro дают 1 400 в таблице Free, 950 в Pro и личную сумму 2 350.

**`GET /api/twa/league/all-time?category=free|pro&scope=all|region&region=<код>&after=…`**
```json
{
  "enabled": true, "period": "all_time", "rules_version": 2, "counted_since": "2027-02-01",
  "category": "free", "my_category": "free", "read_only": false, "opted_out": false,
  "scope": "all", "region": null, "participants": 38623,
  "top": [ { "rank": 1, "name": "Dilnoza", "points": 7420, "long_words": 210, "active_days": 61, "is_me": false, "pro_emoji": null } ],
  "next_cursor": "af.eyJwIjo…",
  "neighbours": { "above": [BoardRow, BoardRow], "below": [BoardRow, BoardRow] },
  "me": { "category": "free", "rank": 340, "points": 1400, "long_words": 12, "active_days": 31, "participant": true, "not_counted_reason": null,
          "first_day": "2027-02-03", "last_day": "2027-03-06", "next_gap": 8,
          "region": { "code": "UZ-TK", "name": "город Ташкент", "participants": 13502, "shown": true, "min_participants": 5, "rank": 112 } },
  "my_total": { "points": 2350, "free": 1400, "pro": 950 }
}
```
- `enabled: false` (режим очков не `live`: `off`, `shadow`; лиги выключены) — ответ только `{"enabled": false, "period": "all_time"}`; ничего не пишется и не читается.
- `category` — показанная таблица (`?category=` или своя); `my_category` — своя: категория текущего месяца ученика, без неё — текущего тарифа. `read_only` — у ученика нет места в показанной таблице (нет очков в этой категории, не участник, другая область); тогда `neighbours: null`.
- `me` — строка ученика **показанной** категории (`null` — очков в ней нет); `rank` — конкурентное место в категории (`null` у не-участника, `not_counted_reason`: `opted_out` | `blocked`); `next_gap` — очки до ближайшего строго лучшего места **показанной таблицы** (`null` у лидера и без места в ней); `region` — место в **текущей** области ученика (`null` без области; `rank: null`, пока таблица скрыта).
- `my_total` — личная сумма по обеим категориям (видна владельцу и при отказе от лиг); `free` / `pro` — `null`, если в категории очков нет.
- `scope=region` — таблица области (своя — **текущая** область ученика, «люди области»; `region=<код>` — любая, только чтение): `region: {code, name, participants, shown, min_participants}`, порог 5 как у месяца (при 4 — `top: []`, `next_cursor: null`, `neighbours: null`).
- `?after=<next_cursor>` — только страница (`LeagueBoardResource` + `period: "all_time"`, `scope`, `region`); курсор `a{f|p}[~{код}].{…}` привязан к категории и области: курсор месяца, другой категории или области, битый → 422 `invalid_cursor`.
- Ошибки: 422 `region_not_set` (своя область не выбрана), 422 валидации (`category`, `scope`, `region` вне списка, `region` без `scope=region`).

**Свежесть.** Таблица — последний пересчёт `learner_score_totals` (плановый раз в 10 минут + сброс топа; другие отстают ≤ 10 мин и TTL топа 120 с). **Своя строка свежая всегда**: один оператор читает свои строки вместе с моментом последнего изменения своих дней (сегодня и вчера); день новее строки — пересчёт ученика (1 оператор) и повторное чтение. Исправление дня, отказ от лиг, блокировка, удаление и смена области пересчитывают строку сами.

**Стоимость** (`AllTimeRatingBudgetTest`, таблица на 11 и 61 участника — не растёт): `GET /league/all-time` — 13 холодный / 2 тёплый (auth + свои строки), `?scope=region` — 12 / 2, `?after=` — 7. Планы — docs/32 §12.11.

### A.20 Готовность к уровню и тест недели (docs/13 §8.5; 18.11.2026)
Блок `active_course.readiness` на «Сегодня» — A.2a. Здесь — полный экран и тест. Включатели: `READINESS_ENABLED` (всё), `READINESS_QUIZ_ENABLED` (только тест); `config/readiness.php`. Доступно всем тарифам. Для личной колоды (`owner_type = learner`) экран не имеет смысла — клиент не показывает вход.

#### `GET /api/twa/courses/{slug}/readiness?source=today|profile|course`
`LevelReadinessService::screen`, `ReadinessResource`. Лимитер `twa-api`. Пишет событие `readiness.viewed` (`source` вне списка → `unknown`).

```json
{
  "course": { "id": 3, "slug": "english-b1", "title": "Английский B1", "level": "B1", "level_label": "B1" },
  "percent": 31,
  "words": { "total": 1000, "learned": 310, "mastered": 120, "known": 190, "in_progress": 120, "not_started": 570 },
  "thresholds": { "known_days": 7, "mastered_days": 21 },
  "modules": [ { "number": 1, "title": "Личность и биография", "learned": 38, "total": 50 } ],
  "quiz": {
    "enabled": true, "questions": 12, "seconds": 600, "week_start": "2026-11-16",
    "this_week": { "correct": 8, "total": 12, "percent": 66, "finished_at": "2026-11-17T09:12:00+00:00" },
    "history": [ { "week_start": "2026-09-07", "correct": 5, "total": 12, "percent": 41 } ],
    "best_percent": 75
  }
}
```
`percent` — пол (формула в A.2a), `known = learned − mastered`. `modules` — по модулям курса: `learned` из `total` активных слов. `quiz` = `{"enabled": false}` при выключенном тесте (запроса к `level_quiz_results` нет). `this_week` — зачётный результат текущей недели или `null`; `history` — **зачётные** результаты за `READINESS_QUIZ_HISTORY_WEEKS` недель (текущая включена) по возрастанию `week_start`, пропущенные недели в списке отсутствуют; `best_percent` — максимум по `history` или `null`. Ошибки: 404 `course_not_found` (нет / не опубликован / недоступен), 404 `readiness_disabled`. Запросов при тёплом дереве курса: auth + курс + строки learner'а + `level_quiz_results` = 4 (бюджет ≤ 5).

#### `POST /api/twa/courses/{slug}/level-quiz`
`LevelQuizService::open`, `throttle:twa-session-create`. Открывает тест недели или возвращает уже открытый (не больше одного открытого теста на learner'а): **201** новый / **200** открытый (`resumed: true`).
```json
{ "session_id": 91, "mode": "level_quiz", "course_id": 3, "week_start": "2026-11-16", "scored": true,
  "started_at": "…", "ends_at": "…", "total_questions": 12, "seconds": 600, "resumed": false }
```
Ошибки: 404 `course_not_found`, 404 `level_quiz_disabled`, 422 `not_enough_words` (в курсе меньше 4 активных слов), 422 `not_enough_distractors`. `config` сессии (ключ ответов, `order_secret`) наружу не отдаётся. Вопросов `min(READINESS_QUIZ_QUESTIONS, активных слов)`: около 2/3 — из слов, которые ученик уже встречал (есть строка `word_repetitions`), остальные — из не встреченных; нехватка одной группы добирается из другой. Варианты ответов — `ExamQuestionBuilder::forWords` (в том числе фильтр общих смыслов `TranslationParts`).

**Неделя и зачёт.** `week_start` — понедельник ISO-недели **в поясе learner'а** на момент открытия (хранится в `config.week_start`: тест, начатый в воскресенье 23:58, а законченный в понедельник, идёт в прошлую неделю). **Зачётный** (`scored: true`) — первый завершённый тест learner'а по курсу за неделю; дальше в ту же неделю — тренировочные (`scored: false`): результат сохраняется (`scored = false`), но в `this_week` / `history` не попадает. Тест без единого ответа результата не пишет — неделя остаётся доступной для зачёта. Зачётность держит и БД (partial unique `level_quiz_results_one_scored_per_week`).

**Игра** — те же маршруты, что у группового экзамена и mock'а (A.4): `POST /exam/sessions/{id}/join`, `GET …/question?index=`, `POST …/answer` без изменений; сервер проверяет ответ, клиент не получает верный вариант до ответа; таймер один на весь тест (`seconds_per_question` = `seconds` = 600); повторный ответ — 409 `already_answered`, после `ends_at` — 410 `session_closed`, чужая сессия — 403 `forbidden`.

#### `GET /api/twa/exam/sessions/{id}/result` для `level_quiz`
Сервер сам закрывает тест при запросе (все вопросы отвечены или время вышло), иначе 409 `exam_still_open`. Закрывают и `LevelQuizService::open` (просроченный), и `exams:close-expired` (брошенный).
```json
{ "mode": "level_quiz", "correct": 8, "total": 12, "answered": 11, "percent": 66, "scored": true,
  "week_start": "2026-11-16", "course_id": 3, "course_slug": "english-b1" }
```
`percent` — пол от `100 · correct / total` (неотвеченный вопрос — неверный). `scored: false` — тренировочный тест или тест без ответов. Тест **не пишет** в `word_repetitions`, `training_*`, `learner_daily_stats` и не даёт очков рейтинга (docs/13 §8.5).

**События:** `readiness.viewed {course_id, percent, learned, total, source}` · `level_quiz.started {exam_session_id, course_id, week_start, scored, questions, met, unmet}` · `level_quiz.finished {exam_session_id, course_id, week_start, scored, correct, total, percent, answered, reason, too_few_answers}` (`reason` — `finished | expired`, ровно один раз; `too_few_answers` — ответов меньше `READINESS_QUIZ_MIN_ANSWERED_RATIO` от вопросов, попытка не зачтена; `/result` тогда `scored: false`).

### A.21 `POST /api/twa/client-timing` — временные метки открытия (аудит 18.11.2026, «Как увидеть выигрыш»)

Сколько ученик ждёт первую кнопку «Сегодня», сколько времени ему нужно, чтобы понять экран, когда сервер подтвердил первый ответ и когда
зазвучало первое слово. Метки собирает Mini App, **выборочно**: доля открытий — `client_timing.sample_percent` из ответа `/auth` (A.1;
по умолчанию 10 %, `0` — выключено). Открытие в выборке, если `crc32("client_timing:<open_id>") % 100 < доли` — то же правило проверяет
сервер (`ClientTimingRecorder::sampled`, `ExperimentBucket`), открытие вне выборки он не записывает.

**Request** (лимитеры `twa-api` + `twa-client-timing`: **6/мин на learner'а**, 429):
```json
{ "open_id": "6f1c…-uuid", "platform": "android", "cold": true, "entry": "home",
  "auth_ready": 310, "today_received": 640, "button_ready": 702, "first_tap": 2150, "first_answer_ack": 4890, "first_audio": 3010 }
```
- `open_id` — uuid, случайный на каждое открытие (не устройство и не человек); `platform` — `Telegram.WebApp.platform`: `android | ios |
  tdesktop | macos | web | weba | webk | unknown`, всё иное сервер (и клиент) записывает как `other`; строка до 32 символов;
  `cold` — первое открытие в этом webview (нет маркера сессии, не перезагрузка) / `false` — возврат; `entry` — `home | deep_link | other`
  (`deep_link` — `start_param` ссылки из группы или кнопка напоминания / объявления `?from=…`).
- Метки — целые миллисекунды от начала открытия (navigation start страницы, `performance.timeOrigin`), каждая необязательна, `0..600000`:
  `auth_ready` (ответ `/auth`), `today_received` (ответ `/today`), `button_ready` (главная кнопка «Сегодня» нарисована и доступна:
  «Выбрать курс» стартовой карточки, «Начать / Продолжить занятие», урок задания центра), `first_tap` (первое нажатие на неё),
  `first_answer_ack` (сервер принял первый ответ), `first_audio` (первое слово начало звучать).
- Любой другой ключ — **422** (в том числе `learner_id`: пишется только за learner'а токена); иное значение `entry`, не-uuid, метка вне
  границ, дробная или `null` — 422.

**Response 204** без тела — всегда (вне выборки, повтор, доля `0`). Пишет `client.timing` (docs/12 §7.7) **не больше раза на `open_id`**:
маркер `analytics:client_timing:v1:<open_id>` (`Cache::add`, невытесняемое хранилище, TTL 24 ч). Props — ровно `open_id, platform, cold,
entry, locale` (язык интерфейса из строки learner'а), присланные метки и `class` — `assignments` снимка «Сегодня» (`TodayProfileCache`:
активный участник группы центра) **если снимок есть в кэше**, иначе поле опущено. Стоимость пути запроса — **без SQL сверх
аутентификации** (два чтения кэша и `add`), INSERT события — после ответа (`ProductEventBuffer`).

**Клиент** (`composables/useTimingMarks.ts`): начало — первая строка `main.ts`; одна отправка на открытие — сразу после
`first_answer_ack` или при `visibilitychange → hidden` / `pagehide` с тем, что есть; `fetch` с `keepalive` и заголовком `Authorization`
(`sendBeacon` его не несёт); без повторов и без обновления токена, ошибки глотаются, на запросы экранов не влияет.

**Отчёт:** `php artisan analytics:client-timing --days=7` (docs/ADMIN_GUIDE.md «Скорость открытия»).

**Что НЕ собирается:** имя, username, Telegram id в props, user agent, модель устройства, версия ОС, IP, размер экрана, тексты, адреса
страниц. Ученик связан с событием только через `learner_id` строки, как у любого продуктового события.

### A.22 «Поделиться» через бота: `POST /api/twa/share/prepare`, `POST /api/twa/share/events` (docs/36 §1в, H3/H4/H7)

Сообщение «через бота» с фото-карточкой и кнопкой «🚀 Начать изучение» → `https://t.me/<bot>?start=ref_<код>` (для `friend_invite` —
«🤝 Добавить в друзья» → `?start=f_<код>`). Сервер готовит его методом Bot API `savePreparedInlineMessage`, Mini App передаёт `id` в
`WebApp.shareMessage(id)` (Mini Apps 8.0+; метод `@twa-dev/sdk` 8.x, с 26.11.2026 — без сырого события WebView, docs/36 §1а). Ядро — `ShareMessageBuilder` и `SharePreparer` (`app/Domain/Engagement/Services/`).

**`POST /share/prepare`** (лимитеры `twa-api` + `twa-share`: **20/мин и 100/сутки на learner'а**, ответы из кэша тоже считаются; 429)
```json
{ "kind": "achievement", "subject": "streak_7" }
```
- `kind` — `achievement` (subject — ключ открытого достижения) · `game_city` (subject — `stage_id` города, босс которого побеждён) ·
  `invite` и `friend_invite` (subject **запрещён**). `league_week`, `month_place` — позже (docs/36), сейчас 422 как неизвестный вид.
- Других полей сервер не читает: текст, фото, адрес и Telegram id берутся **только** с сервера. `user_id` в Bot API — `telegram_user_id`
  ученика из JWT. Подпись — `lang/{ru,uz}/share.php` на языке ученика (`interface_locale`), без имени ученика и без `parse_mode`.
  Фото — публичный HTTPS-адрес карточки (`/storage/share-cards/…jpg`); без него (диск не публичный, `APP_URL` не https, карточка не
  нарисовалась) — `InlineQueryResultArticle` с тем же текстом и кнопкой. Сообщение можно отправить в личные чаты, группы и каналы, не ботам.

**200** — `{ "id": "…", "expires_at": "2026-11-25T12:00:00+00:00" }` (срок — `expiration_date` Telegram). Повторное нажатие в пределах
срока минус 5 минут (но не дольше часа) отвечает тем же `id` без вызова Bot API: кэш `share:prep:v1:{learner}:{kind}:{subject|-}:{locale}:{версия
содержимого}` (чистый кэш, docs/19 §2.2; версия — тексты, ссылка, шаблон и настройки карточки, без отрисовки); смена текста, ссылки или
настройки карточки меняет ключ. Проверки предмета (своё достижение, открытая игра) выполняются на каждое нажатие, в том числе из кэша. Два одновременных нажатия — один вызов Bot API (блокировка на ключ, второй
ждёт до 3 с). Событие `share.prepared {kind, subject, via: mini_app, cached, photo}`.

| Код | `error.code` | Когда | Что делает клиент |
|---|---|---|---|
| 401 | `unauthorized` / `token_expired` / `learner_blocked` … | как у всего TWA (A.6); заблокированный ученик отсекается здесь | как везде (обновить токен / экран блокировки) |
| 403 | `share_unavailable` | «поделиться» выключено (`SHARE_ENABLED=false`), у бота нет имени, игра закрыта для `game_city` | запасной путь: `shareUrl(link, text)` (нынешний `t.me/share/url`) |
| 404 | `share_subject_not_found` | достижения / города нет, не открыто / не побеждён, **или чужое** — одним кодом и одним текстом | запасной путь |
| 422 | ошибки валидации | неизвестный / будущий `kind`, нет / лишний / кривой `subject` | ошибка клиента: запасной путь, в лог |
| 429 | — | лимит `twa-share` (`Retry-After`) | запасной путь, повторять не нужно |
| 503 | `share_failed`, `details.retry_after?` | Bot API отказал, не ответил, ответил 429; бот на паузе после 429 (сразу, без вызова; `Retry-After`); параллельный запрос держит блокировку дольше 3 с | запасной путь |

Во всех отказах клиент открывает нынешний `t.me/share/url` с прежними `share_link` / `share_text` (поля ресурсов остаются). Отметка
«поделился» (`/achievements/{key}/shared`, `/game/cities/{stage}/shared`) — после `shareMessage` → `sent`, в запасном пути — как сейчас.

**`POST /share/events`** (лимитеры `twa-api` + `twa-share-event`: 30/мин на learner'а) — клиентская аналитика, **204** без тела:
```json
{ "event": "sent", "kind": "invite" }
{ "event": "fallback", "kind": "achievement", "reason": "old_client" }
```
`event`: `sent` → `share.sent {kind}` (колбэк `shareMessage` вернул `true`); `fallback` → `share.fallback {kind, reason}`, `reason` —
`old_client` (нет `shareMessage`, Mini Apps < 8.0) или `error` (prepare отказал или `shareMessage` не удался); у `sent` `reason` запрещён.
`kind` — те же виды. Иное — 422, ничего не пишется. Клиент шлёт без ожидания, ошибки глотает.

### A.23 Кубок друзей в группе (docs/33 §7.2; ядро — K4/K5, маршруты — K7 ✅ 25.11.2026)

Все маршруты — `/api/twa`, за `TwaAuth` и `twa-api`, ответы — Resource-классы (`app/Http/Resources/Twa/Cup/*`), ошибки — конверт
`ApiErrorResource` `{error: {code, message, details?}}` с кодами `CupException`. Личность — только `learner` из JWT; членство в чате —
свежий `getChatMember` на сервере (`CupMembershipGate`); код кубка в ссылке, `start_param`, время, очки, вердикт и состав заданий
клиента — не факты (лишние поля тела игнорируются). Порядок проверок и «двери» — `CupMiniApp`; один запрос находит кубок, его группу и
своё участие (`CupReader::locate`).

**Выключатель.** `CUP_ACCESS=off` — **все** маршруты ниже отвечают одинаково `403 {"error":{"code":"cup_unavailable","message":"The cup
is not available in this chat."}}` до чтения БД (middleware `EnsureCupAccess`), есть кубок, попытка, код или нет. `pilot` — маршруты
открыты, кубок группы без `meta.cup_pilot` отвечает 403 `cup_unavailable` **только тому, кто прошёл дверь членства** (участнику или
члену чата); чужой получает 403 `cup_not_member`.

**Двери.**
- **По числовому id** (`/cups/{id}…`) — чтения (`GET /cups/{id}`, `GET …/rounds/today`), **вступление** и действия админа чата — одна
  дверь `assertCanView(…, opaque: true)` (ревью выпуска 5, docs/33 §11.7 п. 2, §11.8):
  - **не-участнику** (по базе) и отсутствующему кубку — **один и тот же** 403 `cup_not_member` (тело байт в байт) на **любой** отказ:
    не член, пауза (бот удалён — Telegram даже не спрашивается), бот не может проверить, Telegram недоступен, проверка занята. Открывает
    кубок только однозначный ответ Telegram «член чата»; причины (409 / 503 / 429) не-участник видит только по коду ссылки;
  - **участник** (по базе, любого статуса) получает причины: 409 `cup_paused` / `cup_unverifiable {bot_not_admin}`, 503
    `cup_unverifiable {telegram_unavailable}`, 429 `cup_check_busy`;
  - участник `active` проходит без Telegram; участник `left` (вышел или исключён — в открытом или завершённом кубке) — только со свежей
    проверкой «член чата», иначе тот же 403, что посторонний (таблица с именами и очками не видна исключённому, docs/33 §11.2 п. 7);
  - участник, **сам вышедший из кубка** (`POST …/leave`, `withdrawn_at`, docs/33 §11.12), — тот же 403 `cup_not_member` **без** вызова
    Telegram на всех маршрутах кубка (и по коду ссылки), даже если он член чата: вернуться в этот кубок нельзя.
  Статус кубка (`cup_not_open`, 410) при вступлении видит только прошедший дверь. Ответ двери переиспользуется самим вступлением
  (троттлинг ядра, 10 с): один `getChatMember` на запрос.
- **Имя** — только участник `active`, и он должен быть членом чата **сейчас**: свежая проверка (`admitToRename`, как старт раунда, но без
  24 ч по сбою Telegram); `left` — 403 `cup_not_member` без вызова Telegram; «не член» — 403 и участник становится `left`; причины сбоя
  — 409 / 503 / 429 (он участник). **Старт раунда** — только участник (`left` проверяется заново в ядре). Не-участник получает 403
  `cup_not_member` до того, как ядро посмотрит на статус кубка.
- **По коду ссылки** (`/cups/by-code/{code}`, 22 символа base64url, иное — 404 маршрутизатора) — `opaque: false`: код — подсказка, какой
  кубок открыть, пропуск — только ответ Telegram об **этом** ученике. Пересланная ссылка чужому — 403 `cup_not_member`, тот же ответ,
  что у несуществующего кода, без названия группы.
- **Попытка** — чужая и несуществующая — один 404 `cup_round_not_found` на всех трёх маршрутах попытки.

| Метод и путь | Лимитер (сверх `twa-api`) | Ядро | Ответы |
|---|---|---|---|
| `GET /cups` | — | `CupReader::mine()` | 200 `{cups: [CupFields + me]}`: открытые, затем 10 последних завершённых/отменённых; кубков, из которых ученик вышел сам (`…/leave`), в списке нет |
| `GET /cups/by-code/{code}` | `twa-cup-read` 30/мин | `assertCanView(opaque: false)` | 200 `CupInviteResource`; 403 `cup_not_member` (и «нет кубка»); 409 `cup_paused {reason}` / `cup_unverifiable {reason: bot_not_admin}`; 503 `cup_unverifiable {reason: telegram_unavailable}`; 429 `cup_check_busy`; 403 `cup_unavailable` (пилот). Событие `cup.invite_viewed {cup_id, joined, status}` — раз в час на ученика и кубок |
| `GET /cups/{id}` | `twa-cup-read` | `assertCanView(opaque: true)` + `CupStandings::table()` + эмодзи Pro + `today()` + дни | 200 `CupResource`; 403 `cup_not_member` / `cup_unavailable` |
| `GET /cups/{id}/rounds/today` | `twa-cup-read` | `CupRoundService::today()` | 200 `{state: not_started / active / done / not_available, day, attempt_id, points, expires_at, server_now}`; члену чата без участия — `not_available` |
| `POST /cups/{id}/join {display_name?}` | `twa-cup-join` 10/мин | дверь id + `CupParticipationService::join()` | 201 новый / 200 уже участник (вышедший снова `active`, счёт прежний) `CupParticipantResource`; 403 `cup_not_member` (и любой сбой проверки не-участнику); участнику и прошедшему дверь — 409 `cup_not_open` (черновик), `cup_paused`, `cup_unverifiable {bot_not_admin}`, 503, 429; 410 `cup_closed`; 422 `cup_display_name_invalid {reason}` (имя проверяется до членства) |
| `PATCH /cups/{id}/me {display_name}` | `twa-cup-join` | `rename()` (свежая проверка членства) | 200 `CupParticipantResource`; 403 `cup_not_member` (не участник, `left`, не член чата сейчас); 409 `cup_rename_limit` (3 смены), `cup_paused`, `cup_unverifiable {bot_not_admin}`; 410; 422 `cup_display_name_invalid {reason}`; 503; 429 |
| `POST /cups/{id}/leave` (docs/33 §11.12, 27.11.2026) | `twa-cup-join` | дверь по базе без Telegram (`CupReader::locate`) + `CupParticipationService::leave()` | 200 `CupLeaveResource` (и на повтор — двойное нажатие, то же тело); 403 `cup_not_member` (нет кубка, посторонний, член чата без участия — то же тело, что у всех маршрутов кубка); 403 `cup_unavailable` (пилот); 410 `cup_closed` (кубок завершён / отменён). Можно и `left`-участнику (вышел из чата), и организатору. Событие `cup.left {cup_id, participant_id, status, duels_closed}` |
| `POST /cups/{id}/rounds/today` | `twa-cup-start` 10/мин | `startToday()` | 201 новая / 200 уже открытая попытка `CupRoundResource`; 403 `cup_not_member` (не участник; вышедший из чата → `left`); 409 `cup_round_done {points}`, `cup_not_started`, `cup_not_open`, `cup_round_unavailable`, `cup_paused {reason}`, `cup_unverifiable {bot_not_admin}`; 410 `cup_closed`; 503; 429 |
| `GET /cup-rounds/{attempt}` | — | `find()` | 200 `CupRoundResource` (та же попытка, исходный `expires_at`; просроченная закрывается `expired`); 404 `cup_round_not_found` |
| `POST /cup-rounds/{attempt}/answer {request_id, element_id, answer}` | `twa-cup-answer` 90/мин | `answer()` | 200 `CupRoundAnswerResource`; повтор `request_id` — то же тело, `replayed: true`; 409 `answer_already_accepted {verdict}`, `element_not_current {current}`, `cup_round_expired {points}`, `cup_round_done {points}`; 410 `cup_closed`; 422 форма (`answer` — только `option_id` / `text` ≤ 200 / `skip`), `invalid_answer_shape`, `unknown_element_id`, `unknown_option_id`; 404 |
| `GET /cup-rounds/{attempt}/audio/{element}` | `twa-practice-audio` (вне `twa-api`) | `find()` + `audioOf()` | клип `listen`-задания своей попытки: текущего или уже отвеченного (следующие слова заранее не выдаются); слово на R2 — 200 `{url}`; локальный файл — поток `audio/mpeg`, `Cache-Control: private, no-store`; иначе 404 `cup_round_not_found`; нет файла — 503 `practice_audio_unavailable` |
| `GET /cups/setup/{code}` | `twa-cup-admin` 10/мин | `CupAdminCheck::assertChatAdmin()` | 200 `CupSetupResource`; 403 `cup_not_chat_admin` (и «нет кода»); 409 `cup_paused`; 503 |
| `POST /cups/setup/{code}/publish {level?, locale?}` | `twa-cup-admin` | `publish()` | 200 `CupSetupResource` (повтор — тот же кубок; уже опубликованный показывается только админу чата); 403 `cup_not_chat_admin`; 409 `cup_bot_not_admin`, `cup_paused`; 410 `cup_closed`; 422 `cup_level_invalid`, `cup_material_unavailable`; 503 |
| `PATCH /cups/{id}/settings {level?, locale?, summaries_enabled?, summary_minute?}` | `twa-cup-admin` | дверь id + `updateSettings()` | 200 `CupSetupResource`; 403 `cup_not_member` / `cup_not_chat_admin`; 409 `cup_already_published` (уровень/язык после публикации); 410; 422 (`summary_minute` 0…1439, `cup_level_invalid`, `cup_material_unavailable`) |
| `POST /cups/{id}/cancel` | `twa-cup-admin` | дверь id + `cancelByChatAdmin()` | 200 `CupSetupResource` (`status: canceled`, `cancel_reason: chat_admin`; завершённый — как есть); 403 `cup_not_member` / `cup_not_chat_admin` |

**Формы ответов** (точно закреплены `tests/Feature/Cup/CupContractTest.php`; время — ISO 8601 UTC с миллисекундами, `…T19:00:00.000Z`;
границы дней — полночь `timezone`; наружу — только `participant_id`, никогда `learner_id` / Telegram id / `public_code` / `seed_secret`):

- **`CupFields`** (общая часть): `{id, status, group_title, level, days, counted_days, round_tasks, round_minutes, timezone, starts_at,
  ends_at, day (текущий день идущего кубка или null), summaries_enabled, summary_time ("20:00"), paused (null | bot_removed | blocked),
  next_day_at}`. `next_day_at` — когда откроется следующий раунд (ISO 8601 UTC; решает сервер — полночь `timezone` с учётом перевода
  часов, `CupClock::nextRoundAt`, без запроса): до старта — день 1, пока идёт — следующая полночь, в последний день, в черновике и в
  закрытом кубке — null.
- **`rules`**: `{points_per_answer: 2, typo_points: 0, max_day_points, counted_days, days, max_total}`.
- **`CupInviteResource`**: `CupFields + {participants_count, rules, me: {joined, participant_id, status, display_name (своё имя в кубке
  или имя для вступления — из Telegram), left}}`.
- **`CupParticipantResource`**: `{participant_id, cup_id, display_name, status, first_day, renames_left}`.
- **`CupLeaveResource`** (`POST /cups/{id}/leave`): `{participant_id, cup_id, status: "left", left: true, left_at}` (`left_at` — когда
  вышел из кубка). Экран после ответа уходит в список кубков.
- **`GET /cups`**: `{cups: [CupFields + {me: {participant_id, display_name, status, total, days_played, final_rank, today: {state, day}} |
  null}]}`. `me.today.state` — раунд сегодняшнего дня по правилам `CupRoundService::today()`: `not_started` / `active` / `done` /
  `not_available` (кубок не идёт, дня нет, участник вступил позже); читается **в том же одном операторе** списка (два скалярных
  подзапроса: сегодняшний день кубка по unique `(cup_id, day)` и попытка участника по unique `(cup_day_id, participant_id)`).
- **`CupResource`** (`GET /cups/{id}`): `{cup: CupFields + {rules}, standings: [{participant_id, rank, display_name, pro_emoji (эмодзи
  Pro, docs/31 §5а, или null), total, round_points, days_played, left, me}], me: null | {participant_id, display_name, status, first_day,
  renames_left, total, final_rank, today: {state, day, attempt_id, points, expires_at}, days: [{day, points, round_points, duel_points,
  played, counted, available}] (все дни кубка; `counted` — день в зачёте лучших `counted_days`), duel: Availability | null (можно ли вызвать
  на дуэль сегодня, A.23d; null — дуэли выключены)}, can_manage, server_now}`. `can_manage` —
  только подсказка для ссылки «Настройки» (ученик предложил или опубликовал кубок); каждое действие админа спрашивает Telegram заново.
- **`CupRoundResource`**: `{attempt_id, cup_id, participant_id, day, status (active | finished), finish_reason (null | completed | expired),
  total, answered, correct, points, max_points, position, started_at, expires_at, server_now, current, answers: [{element_id, verdict,
  points}], review, next_day_at}` (`next_day_at` — конец дня попытки = открытие раунда следующего дня, null в последний день; из строки
  дня, без запроса). `current` — проекция задания игры с позицией: `translate_fwd {position, id, kind, prompt, audio, options}`,
  `translate_back {position, id, kind, prompt, options}`, `listen {position, id, kind, audio, options, audio_url}`, `spell {position, id,
  kind, prompt, answer_length}`, `gap {position, id, kind, masked, translation}`; `options: [{id, text}]` — в своём порядке у каждого
  участника. **Ни в `current`, ни где-либо до ответа нет** `correct_option_id`, `expected`, `correct_text`, `full_text`, `word_id`, `word`,
  `lexeme_id`, `audio_path`, `audio_disk`. `review` — null, пока попытка идёт; после — `[{element_id, kind, verdict, points, answer,
  reveal}]` (правильные ответы; у `listen` ещё `audio_url`).
- **`CupRoundAnswerResource`**: `{verdict, points (0 или 2; опечатка — 0), reveal (правильный ответ только что отвеченного задания),
  finished, total_points, replayed, round: CupRoundResource}`.
- **`CupSetupResource`**: `CupFields + {locale, summary_minute, cancel_reason, options: {levels, locales}, rules}`.
- **422 `cup_display_name_invalid`** — `details: {reason}` (`DisplayName::validate()`, docs/33 §11.8 п. 4): `empty` (пусто после
  очистки невидимых символов), `too_long` (больше 32 графем или 64 кодовых точек), `marks` (больше 2 комбинирующих знаков подряд), `link`
  (`://`, `www.`, точка между буквой/цифрой и буквой — `site.com`, `t.me/x`, `J.R`), `mention` (`@` в начале слова), `command` (`/` в
  начале слова), `tag` (`#` в начале слова с буквой, `$` перед латинской буквой). Длина — в графемах (эмодзи-семья — один символ).
  Имя очищается до проверки: NFC, без управляющих / bidi (U+202A–U+202E, U+2066–U+2069) / невидимых символов, пробелы схлопнуты;
  ZWJ остаётся только внутри эмодзи.

**Операторы SQL на маршрут** (PostgreSQL и SQLite одинаково; `CupHttpQueryBudgetTest`, ядро — `CupQueryBudgetTest`): HTTP-слой добавляет
к ядру ученика из JWT (1) и кубок с группой и своим участием (1), таблица — эмодзи Pro (1 холодный, 0 тёплый); события продукта
пишутся после ответа одним INSERT. `GET /cups/{id}` — 7 холодный / 5 тёплый (не растёт с участниками и эмодзи); `GET /cups/by-code` — 3
(с событием) / 2; вступление — 6; `PATCH /me` — 6 (+ запись свежей проверки членства); старт раунда — 11 (ядро 9), возобновление — 6; `GET /cup-rounds/{id}` — 2;
`GET …/rounds/today` — 4; ответ неверный / верный — 5 / 10; `GET /cups` — 2 при любом числе кубков (с `me.today`); выход из кубка —
8 с публичным вызовом для правки / 7 без (ученик, дверь, кубок `FOR KEY SHARE`, открытые вызовы одним `UPDATE … RETURNING`, [правка
outbox], строка участника `FOR UPDATE`, UPDATE, событие), повтор — 2; все обращения — по PK / unique / частичным индексам дуэлей
(`CupLeaveTest`).

#### A.23d Дуэли кубка (ядро D1 ✅ 26.11.2026, HTTP и бот D2 ✅ 26.11.2026; решения — docs/33 §11.9, §11.10)

Все маршруты — `/api/twa/cups/{id}/duels…`, за `TwaAuth`, `twa-api`, `EnsureCupAccess` и `EnsureCupDuels`; ответы — Resource-классы
(`CupDuelResource`, `CupDuelListResource`), ошибки — конверт `ApiErrorResource` с кодами `CupException` / `BattleException` /
`PracticeException`. Тонкий контроллер `Api\Twa\Cup\CupDuelController` → `Cup\Services\CupDuelMiniApp` (дверь) → ядро `CupDuelService` /
чтения `CupDuelReader`.

**Порядок ответов (одинаковые тела — байт в байт):**
1. `CUP_ACCESS=off` → 403 `cup_unavailable` (до БД).
2. Дуэли выключены (`CUP_DUELS=false`, или `PRACTICE_BATTLE=false`, или `PRACTICE_ACCESS=off`) → **409 `cup_duel_disabled`** на всех
   маршрутах до чтения БД — есть кубок, дуэль, участие или нет.
3. **Дверь кубка** — один оператор (`CupReader::locate` + скаляры маршрута), **без Telegram**: нет кубка, посторонний (в т. ч. член чата
   без участия), участник `left` → один 403 `cup_not_member`. Пилот без флага группы → 403 `cup_unavailable` (только прошедшему дверь).
4. **Дуэль** (маршруты `/{duel}`): нет такой, дуэль другого кубка, адресный вызов другому → один 404 `cup_duel_not_found`.
5. Ядро: свежая проверка членства того, кто действует (вызов, принятие), и все правила — причины видит участник.

| Метод и путь | Лимитер (сверх `twa-api`) | Ответ | Ошибки (сверх п. 1–4) |
|---|---|---|---|
| `GET /cups/{id}/duels` | `twa-cup-read` 30/мин | 200 `CupDuelListResource` | — |
| `GET /cups/{id}/duels/{duel}` | `twa-cup-duel-poll` 40/мин (`CUP_DUEL_POLLS_PER_MINUTE`) | 200 `CupDuelResource` | 404: дуэль не видна (видна игрокам; публичная и открытая всем — любому участнику) |
| `POST /cups/{id}/duels {opponent_participant_id?, public, mode = pairs, kind?, request_id}` | `twa-cup-duel` 20/мин (`CUP_DUEL_ACTIONS_PER_MINUTE`) | 201 новый / 200 тот же `request_id` — `CupDuelResource` | `kind` (docs/33 §11.12, 27.11.2026): `rated` \| `friendly`; нет поля — как раньше (зачётная, если оба слота свободны, иначе товарищеская). `friendly` — никогда не зачётная (`rated: false` сразу), `rated` при **своей** сыгранной зачётной дуэли сегодня — 409 `cup_duel_rating_changed {rated: false}`, вызов не создаётся (сыгранная адресатом — товарищеское предложение, как раньше). 403 `cup_duel_unavailable` (соперника нельзя вызвать — без причины; и когда вызвавший или адресат вышел из кубка / чата, пока вызов создавался — docs/33 §11.12.2 п. 8), `practice_limit_reached` (товарищеская, Free); 409 `cup_duel_already_open {duel_id}`, `cup_duel_limit {scope: daily \| pair \| public_participant \| public_chat, limit}`, `cup_paused {reason}`, `cup_unverifiable {bot_not_admin}`, `cup_not_started`, `cup_not_open`, `cup_round_unavailable`, `practice_mode_disabled`; 410 `cup_closed`; 422 `cup_duel_self`, `cup_duel_mode_invalid`, форма (`public` обязателен, `request_id` — UUID, `mode` — значение `PracticeMode`, `kind` — `rated` / `friendly`); 503 `cup_unverifiable {telegram_unavailable}`; 429 `cup_check_busy` |
| `POST /cups/{id}/duels/{duel}/accept {expect_rated}` | `twa-cup-duel` | 201 принят сейчас / 200 принят раньше — `CupDuelResource` с `battle_id` | `expect_rated` = `rated_for_me` прочитанной дуэли (не `rated_offered`!). 409 `cup_duel_rating_changed {rated}` (вид изменился после чтения: показать новый статус, принять ещё раз с `rated` из ответа — экран хранит его отдельно от опроса, пока статус дуэли тот же), `cup_duel_closed {status, reason}`, `cup_duel_own`, `battle_busy {battle_id}` (мой матч), `cup_duel_opponent_busy`, `cup_paused`, `battle_disabled`, `practice_mode_disabled`; 403 `cup_duel_unavailable`, `cup_not_member` (принимающий вышел из кубка / чата — и после двери, docs/33 §11.12.2 п. 8; вызвавший вышел — 409 `cup_duel_closed {canceled, left}`); 422 `battle_material_unavailable`, форма (`expect_rated` обязателен); 503 / 429 — проверка членства |
| `POST /cups/{id}/duels/{duel}/decline` | `twa-cup-duel` | 200 `CupDuelResource` (`declined`; повтор — то же) | 403 `cup_duel_not_recipient` (свой вызов или открытый всем); 409 `cup_duel_closed {status, reason}` |
| `POST /cups/{id}/duels/{duel}/cancel` | `twa-cup-duel` | 200 `CupDuelResource` (`canceled`, `end_reason: challenger`; повтор — то же) | 404 (не мой вызов); 409 `cup_duel_closed` |

**Формы** (точно закреплены `tests/Feature/Cup/CupDuelHttpTest.php`; время — ISO 8601 UTC с миллисекундами; наружу — только
`participant_id`, никогда `learner_id` / Telegram id / `request_id` / `public_code`):

- **`CupDuelResource`**: `{id, status (open | accepted | declined | expired | canceled | done | void), end_reason (null | declined | expired |
  cup_over | challenger | cup_closed | left | challenger_limit | material | no_answers | battle_gone | battle_voided), rated_offered (что
  предложил вызвавший — пишется один раз при вызове, от смотрящего не зависит), rated_for_me (вид дуэли **для смотрящего**: открытый вызов —
  зачётная, если ни вызвавший, ни тот, кто примет (адресат, а у открытого всем — сам смотрящий), не сыграл зачётную дуэль сегодняшнего дня
  кубка; это и есть `expect_rated` его принятия; иначе — `rated` / `rated_offered`; с 26.11.2026, docs/33 §11.11; товарищеская по выбору —
  всегда `false`), rated (вид, решённый окончательно: null — решит принятие; `false` уже у открытого вызова, если вызвавший выбрал
  `kind = friendly` (docs/33 §11.12); иначе — что решило принятие),
  public, mode, day, accepted_day, expires_at, battle_id (null до принятия), role (challenger | opponent | null — смотрит тот, кто может
  принять открытый вызов), challenger: {participant_id, display_name, pro_emoji}, opponent: {participant_id, display_name, pro_emoji} | null
  (открытый вызов), points: {challenger, opponent} | null (до `done`), winner_participant_id, server_now}`. Имена — имена участников в
  кубке, эмодзи — из кэша эмодзи таблицы (`CupProEmoji::forCup`).
- **`CupDuelListResource`** (`GET …/duels`): `{incoming: [CupDuelResource] (открытые вызовы мне), open: [CupDuelResource] (открытые всем, не
  мои), mine: CupDuelResource | null (мой открытый вызов), today: [CupDuelResource] (мои дуэли сегодняшнего дня кубка, уже не открытые —
  принятые, сыгранные, сорванные, отклонённые, истёкшие, отменённые; новые сверху), availability: Availability, server_now}`. Вызов, чьё
  время вышло (уборщик ещё не прошёл), не показывается нигде.
- **`Availability`** — и в `availability` списка, и в **`GET /cups/{id}` → `me.duel`** (`null`, пока дуэли выключены): `{day (сегодняшний
  день идущего кубка или null), can_challenge (кубок идёт, не на паузе, я `active`, нет моего открытого вызова, вызовы дня не кончились),
  rated_available (моя зачётная дуэль сегодня не сыграна), challenges_left (из `cup.duel.daily_challenges` = 20), open_duel_id (мой
  открытый вызов — показать его вместо нового)}`. Это подсказка экрана: ядро решает заново при вызове.
- Открытые всем вызовы ученика, заблокировавшего меня или заблокированного мной (`friendships`), в `open` не попадают (тот же один
  оператор). Матч дуэли (`battle_id`) играется по обычным маршрутам `/practice/battles/{id}…` **без проверки доступа к практике**
  (`PRACTICE_ACCESS=pilot`, `practice.plan=pro`): его игрокам дверь открывает участие в кубке (docs/33 §11.11 п. 2); остальные
  проверки баттла (игрок этого матча, блокировка ученика) — как у любого матча. Выключатель практики целиком (`PRACTICE_ACCESS=off`) —
  по-прежнему 409 `cup_duel_disabled`.

**Переход в баттл (экран ожидания).** Комната матча дуэли живёт `presence_grace_seconds` (30 с) от принятия.
- Принявший: ответ `accept` (201) уже содержит `battle_id` — экран сразу ведёт в существующий `/battles/{battle_id}` (`BattleView`,
  `GET /practice/battles/{id}` и опрос `…/poll`), как после принятия обычного вызова на баттл.
- Вызвавший: после `POST …/duels` экран ожидания опрашивает `GET /cups/{id}/duels/{duel}` раз в 3 с (лимит 40/мин) до `status ≠ open`:
  `accepted` → сразу `/battles/{battle_id}`; `declined` / `expired` / `canceled` → экран итога вызова. Параллельно ему приходит ЛС «Вызов
  принят» с кнопкой в матч (`TwaScreen::Battle`) — если экран закрыт.
- Ссылка из чата `TwaScreen::CupDuel` → `/cups/c/{code}/duels/{duel}`: экран сначала открывает кубок по коду (`GET /cups/by-code/{code}` →
  `id`), затем `GET /cups/{id}/duels/{duel}`.

**Операторы SQL** (`CupDuelHttpQueryBudgetTest`; PostgreSQL = SQLite; тёплый кэш эмодзи, холодный — +1): HTTP-слой добавляет к ядру
ученика JWT (1) и дверь (1) — `GET …/duels` 3 при любом числе дуэлей (дверь со скалярами доступности + **один** оператор списка),
`GET …/duels/{duel}` 3, вызов личный / публичный / повтор — 10 / 11 / 4 (ядро 8 / 9 / 2) при любом `kind` (слоты — в том же операторе
лимитов), принятие — 22 (ядро 21, два события — один INSERT после ответа; товарищеская по выбору — ядро 19: слоты не читаются и не пишутся), повтор принятия — 6, отказ публичного — 9, отмена личного — 8. `GET /cups/{id}` — те же 5 тёплый / 7 холодный:
доступность дуэли — скалярные подзапросы в том же операторе двери.

**Бот (B.16).** Публичный вызов — строка outbox `duel:{id}`: новое сообщение в чат (тема `card_thread_id`) с url-кнопкой «⚔️ Принять вызов»
→ `TwaScreen::CupDuel`; вызов, сорвавшийся до отправки (отклонён, истёк, отменён, void), **не публикуется** (`skipped/fell_through`).
Исход — строка `duel:{id}:end`: **правка** того же сообщения (`editMessageText`, без кнопок), никогда не новое; «message is not modified» —
успех; исходная строка ещё `pending` / `sending` → правка ждёт 20 с (`pending`); исходная `skipped` / `unknown` / `failed` → `skipped`. Текст —
`CupDuelRenderer::message()` по состоянию дуэли на момент отправки. 403 → `skipped`, группа `disabled` (`GroupBotRemoval`); 429 → `pending`
(пауза только этого чата); таймаут → `unknown`; «message to edit not found» → `skipped`. ЛС — `SendCupDuelDmJob` → `CupDuelMessenger`:
личный (не публичный) адресный вызов — адресату (кнопка в вызов), принятие — вызвавшему (кнопка в матч, пока он в комнате); правила
отправителей docs/34 §4в п. 8, маркер `notifications_log` `cup_duel_dm`, ключ `{kind}:{duel}`.

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
| `/start [payload]` | ЛС | любая | **Учитель** (есть в `users.telegram_user_id`) — «Привет, учитель X», как раньше; в онбординг не попадает, learner для него не создаётся. **Остальные — learner**: `LearnerRegistrar::fromTelegramUser` (создаёт/восстанавливает learner'а, `last_seen_at`; сбрасывает `dm_blocked_at`), затем — заблокированному (`blocked_at`) «доступ ограничен» и больше ничего (атрибуция не пишется); иначе атрибуция payload (`attributeAcquisition`: first-touch, только если `acquisition_source` ещё пуст; свой `ref_` и `ref_` на несуществующего/удалённого/заблокированного learner'а игнорируются; `ref_<code>` для уже существовавшего learner'а даёт `referrals.status = rejected {existing_learner}`, для нового — `pending`, docs/13 §7.1), затем: `onboarding_state = done` → «Сегодня» (как `/today`) и, если payload — `c_<slug>` доступного курса (опубликован, не private), ещё одно сообщение с web_app-кнопкой «Открыть курс» → `/twa/courses/<slug>` (активный курс **не меняется**; недоступный курс — без сообщения); иначе онбординг (по умолчанию 3 шага: язык → уровень → время напоминания; шаг цели появляется при двух и более целях в `LEARNERS_GOALS`, шага часового пояса нет — docs/12 §7.1, «Изменение 2026-11-16»). **`org_<код>`** (инвайт центра, docs/13 §6.2): код проверяется сразу (`OrganizationMembershipService::previewInvite`, без записи). Неизвестный / отключённый / истёкший / исчерпанный код или приостановленный центр — сообщение на языке learner'а (`organizations.errors.*`) и обычный онбординг / «Сегодня» без запоминания кода; learner, уже состоящий в центре, получает «Вы уже в центре». Годный код: в онбординге — запоминается в `learners.meta.pending_org_invite` (повторный `/start org_…` его перезаписывает), после последнего шага (после сообщения «Готово!») приходит подтверждение «Вступить в центр «X»?» с кнопками `org1:join:<код>` / `org1:skip`; `onboarding_state = done` — подтверждение приходит сразу вместо «Сегодня». Членство создаётся **только** по `org1:join` (`joinByInvite`, идемпотентно; ЛС «Pro активен» / «мест пока нет» отправляет слушатель `OrganizationMemberJoined`), `org1:skip` ничего не создаёт. |
| `/today` | ЛС | learner | Минимальная сводка: «к повторению N слов» (`DueRepetitionCriteria`) или «повторять нечего» + web_app-кнопка «Открыть LexiFlow» → `/twa/?from=today`. Полноценный экран «Сегодня» — `GET /api/twa/today` (docs/12 §7.3). В группе бот молчит, учителю не отвечает. |
| `/language` | ЛС | learner | Смена языка в одно действие: сразу клавиатура выбора языка под двуязычным заголовком «Tilni tanlang · Выберите язык» (`onboarding.settings.language_title` на uz и на ru, склеены через « · »; человек, не читающий текущий язык, находит команду по слову «Til» / «Язык» в меню). Кнопки — те же `set1:locale:<ru\|uz>`, что у шага «Язык» в `/settings`: сохранение идёт общим путём `OnboardingService::applySetting`, подтверждение «Сохранено.» приходит уже на новом языке. Тому, кто онбординг не закончил, — как у `/settings`: текущий шаг онбординга. Учителю — подсказка про его команды, заблокированному — «доступ ограничен», в группе — молчание. Есть в меню `telegram:setup-bot` (`commands.private.language`: «Язык / Til», рядом с `/settings`) и в `/help`. |
| `/settings` | ЛС | learner | Меню «Язык / [Цель] / Уровень / Напоминания» (пункта «Часовой пояс» нет с 2026-11-16; «Цель» — только при двух и более включённых целях, `LEARNERS_GOALS`; старые кнопки `set1:timezone…` / `set1:goal…` выключенных пунктов игнорируются); выбор пункта показывает варианты того же шага, ответ сохраняется (`OnboardingService::applySetting`, те же белые списки) и подтверждается «Сохранено.» на языке learner'а. Тому, кто онбординг не закончил, показывается текущий шаг онбординга. Если у learner'а есть активные членства в центрах — в меню ещё кнопка «Мои центры» (`org1:list`): список центров с состоянием места (Pro активен / в очереди) и кнопка «Покинуть» (подтверждение `org1:leave:<id>` → `org1:leaveyes:<id>`) только для членств `joined_via = invite|admin`; членство `group` вернётся при повторном входе в чат центра, поэтому для него — пояснение «вы в центре через группу». |
| любое другое текстовое сообщение | ЛС | не учитель | `LearnerDmHandler` (последний в списке хендлеров; служебные сообщения без `text` — `pinned_message`, `new_chat_title` и т.п. — игнорирует): регистрирует/оживляет learner'а, сбрасывает `dm_blocked_at`; во время онбординга повторяет текущий шаг (`new` — начинает онбординг), после — краткое меню с кнопкой TWA. Учителю не отвечает (как раньше). |
| `/start_training [stage] [lesson]` | группа | teacher | Создаёт `training_session`, постит сообщение с WebApp-кнопкой. Номера stage/lesson ищутся **в курсе группы** (`telegram_groups.course_id`, иначе курс `default`); без аргументов — список уроков этого курса. |
| `/start_exam [stage] [lesson] [minutes]` | группа | teacher | Создаёт `exam_session`, планирует закрытие. Номера — в курсе группы, как выше. |
| `/close_exam` | группа | teacher | Досрочно закрывает открытый экзамен. |
| `/start_caravan` | группа | teacher (или admin платформы) | Караван класса (G2.9b, docs/18 §12.11): `ClassCaravanService::start`, в чат — «Караван отправился» с кнопкой на `/twa/game/class/{id}` (прямая ссылка Mini App — в группе web_app-кнопка невозможна). Отказы — текстом по причине (`not_teacher`, `group_inactive`, `centers_only` — `game.class.*`); уже идёт — «до какого числа»; если Telegram отверг сообщение, караван отменяется и учитель просит повторить. Команды есть только при `GAME_ACCESS=all`: при `off` и `pilot` бот молчит и не читает таблицы `game_*`. Причина отмены при отказе Telegram — `meta.canceled_reason = not_delivered`. |
| `/cancel_caravan` | группа | teacher (или admin платформы) | Отменяет идущий караван группы (`activeOfGroup` + `cancel`), в чат — «Караван отменён»; итогов нет. Нет идущего — «у группы нет каравана». «Отменён» уходит, только если караван отменил именно этот вызов (не после закрытия планировщиком, не второй раз). Тот же режим `GAME_ACCESS` (только `all`). |
| `/stats` | ЛС | teacher | Отчёт по его группам за 7 дней. |
| `/promo КОД` | ЛС | любой learner | Активирует промокод (`PromoCodeService::redeem`, как A.7). `/promo` без кода — подсказка. Learner создаётся/обновляется по самому человеку (`LearnerRegistrar::fromTelegramUser`). Ответ — на языке learner'а: «Pro активен до дд.мм.гггг» (в поясе learner'а) / «без ограничения по сроку», либо текст ошибки по `errorCode` (`lang/{ru,uz}/bot.php`, `bot.promo.errors.*`). Не более 5 попыток в минуту на learner'а (иначе «слишком много попыток»); заблокированному — «доступ ограничен». В **активной группе** бот отвечает «откройте личный чат» и код не обрабатывает (код в группе виден всем), в неактивной — молчит (Group Lock). |
| `/pro` | ЛС | любой learner | Тариф и оплата (docs/13 §5, 2A.2). Текст: «Free: N новых слов в день» / «LexiFlow Pro до дд.мм.гггг» (в поясе learner'а), состояние автопродления Stars. При `paywall.mode = purchase` и непустом каталоге — цены периодов и по inline-кнопке на (период × провайдер): `pro1:buy:<period>:<telegram_stars\|payme\|click>`; **заказ создаётся только по нажатию** (меню не расходует лимит 3 неоплаченных заказов); пока автопродление Stars работает, кнопок покупки нет (сначала «Отключить автопродление»). Иначе — «Pro скоро откроется», подсказка `/promo` и `BILLING_SUPPORT_CONTACT`. Кнопки `pro1:cancel` / `pro1:resume` — отключить/включить автопродление Stars (`LearnerBillingService`). Ученику на Free в конце текста — одна строка «Пригласите друзей — получите Pro бесплатно: /invite» (`referrals.hint`; нет вех в конфиге — строки нет). Только в личном чате; в группе и у учителя — молчание, заблокированному learner'у — «доступ ограничен». |
| `/invite` | ЛС | любой learner | «Пригласить друга» (docs/13 §7.1, 2C.1): ссылка `https://t.me/<bot>?start=ref_<referral_code>` (код генерируется лениво `ReferralService::codeFor`; пока `TELEGRAM_BOT_USERNAME` пуст — только код), правило вех (2C.9) в двух строках — «Друг засчитывается, когда пройдёт настройку и оценит 10 карточек за день. За 3 и 6 засчитанных друзей вы получите по +7 дней Pro» (числа и склонения из `config/referrals.php`, `referrals.invite.rule`; награды «вам и другу» больше нет — у друга свой пробный Pro), прогресс «Засчитано друзей: 2 из 3 — до +7 дней Pro осталось 1.» (`referrals.invite.next`; после последней вехи — `done`, пока награда оформляется — `owed`; строка «Пришли по вашей ссылке: N» — только при N > 0) и inline-кнопка «Поделиться ссылкой» (`https://t.me/share/url?url=…&text=…`; в тексте — «первые 7 дней Pro бесплатно», пока включён пробный). Язык learner'а (`referrals.*`). Заблокированному — `referrals.blocked`; учителю и в группе — ничего. |
| `/paysupport` | ЛС, активная группа | любой | Требование Telegram для оплаты Stars: контакт платёжной поддержки (`BILLING_SUPPORT_CONTACT`; «не указан», если пуст), ссылка на публичную оферту (правила возврата) и подсказка про `/pro`. Язык: learner'а, иначе Telegram-язык отправителя / язык группы. В неактивной группе — молчание (Group Lock). |
| `/start f_<code>` | ЛС | любой learner | Приглашение в друзья (docs/13 §7.5, 2C.5), **с согласием открывшего**: ссылка ничего не пишет, бот спрашивает «Отправить X запрос в друзья?» + кнопки (`fr1:snd:<code>` / `fr1:no:<code>`); по «Отправить» создаётся `request(открывший → владелец)`, владельцу уходит ЛС с кнопками принять/отклонить (`fr1:acc:<id>` / `fr1:dec:<id>`), запрос виден и во «Входящих» TWA. Уже друзья / уже отправлено / блок — короткое сообщение; если владелец сам уже запрашивал открывшего — показывается этот входящий запрос. **`ref_<code>` дружбу не предлагает** (существующий learner: реферал `rejected {existing_learner}` + «Сегодня»). Не прошедшему онбординг ничего не записывается. |
| `/delete_account` | ЛС | learner | «Удалить аккаунт»: сообщение-подтверждение с inline-кнопками `del1:yes` («Да, удалить») и `del1:no` («Отмена»); сама команда ничего не удаляет. `yes` — `LearnerDeletionService::delete` (как A.2c, аудит `by: self`), кнопки убираются, ответ «Аккаунт удалён… отправьте /start, и аккаунт вернётся»; `no` — «аккаунт не удалён». Ученик ищется без восстановления: уже удалённому бот отвечает «аккаунта нет… /start», повторное `yes` ничего не делает. Заблокированный ученик удалить аккаунт не может (иначе бан исчез бы вместе с ним) — «доступ ограничен». Только в личном чате: в активной группе — подсказка «откройте личный чат», в неактивной — молчание; кнопка из сообщения не в личном чате игнорируется. Тот же вопрос задаёт кнопка «Удалить аккаунт» в `/settings` (`del1:ask`). Тексты — `bot.delete_account.*`; команда есть в меню `telegram:setup-bot` (`commands.private.delete_account`) и в `/help`. |
| `/help` | любой | любая | Справка простым текстом (без HTML), `bot.help.*`. В ЛС **сначала ученик** — «Как заниматься»: кнопка «LexiFlow» слева от поля ввода и `/today`, `/settings`, `/language`, «Пригласите друзей — получите Pro бесплатно: /invite», `/pro` и `/promo`, `/paysupport`, `/delete_account`; затем «Для учителей»: бот в группе администратором, `/start_training`, `/start_exam`, `/close_exam`, при `GAME_ACCESS=all` — `/start_caravan`, `/cancel_caravan` (`bot.help.private_caravan`), `/stats`. В группе — команды учителя (строки каравана — `bot.help.group_caravan`, тоже только при `all`). |

**Меню команд и кнопка Mini App: `telegram:setup-bot`** (docs/14 §7). Бот сам не регистрирует свои команды, поэтому без этого шага
по «/» Telegram ничего не подсказывает, а в личном чате нет постоянной кнопки приложения. Команда вызывает Bot API (контракт
`TelegramClient::setMyCommands` / `setChatMenuButton`, набор данных — `BotMenuBuilder`):

- `setMyCommands` для трёх scope — `all_private_chats` (`today`, `settings`, `language`, `invite`, `pro`, `promo`, `delete_account`, `help`), `all_group_chats`
  (только `help`) и `all_chat_administrators` (`start_training`, `start_exam`, `close_exam`, только при `GAME_ACCESS=all` ещё `start_caravan`, `cancel_caravan`, затем `help`) — по два вызова на каждый
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

**Сообщения «через бота»: `savePreparedInlineMessage` и `answerInlineQuery`** (docs/36 §1в, задача H2; формат сверен с
core.telegram.org/bots/api 25.11.2026). Методы контракта `TelegramClient`, вызов — сырой `post()` SDK (как `setChatMenuButton`):

- `savePreparedInlineMessage(userId, result, allowUserChats, allowBotChats, allowGroupChats, allowChannelChats): PreparedInlineMessage`
  — сообщение для `WebApp.shareMessage` (Bot API 8.0). `result` — **один** `InlineQueryResult` (`InlineQueryResultPhoto` с JPEG
  `photo_url` и `thumbnail_url` до 5 МБ, подпись до 1024 знаков, `reply_markup`; или `InlineQueryResultArticle` с
  `input_message_content`), уходит JSON-строкой; флаги `allow_*_chats` отправляются только со значением `true` (по умолчанию у
  Telegram — `false`). Ответ — DTO `PreparedInlineMessage {id, expirationDate}` (Unix-время из `expiration_date`; срок жизни берётся
  из ответа, не из конфига). Ответ «ok» без `id`/`expiration_date` — `TelegramApiException` как сбой транспорта (`isApiResponse() = false`).
- `answerInlineQuery(inlineQueryId, results, cacheTime = 300, isPersonal = false)` — ответ на `inline_query`: до 50 результатов
  (больше — `InvalidArgumentException` до вызова Telegram), пустой список — допустимый ответ «ничего»; `results` — JSON-массив,
  `is_personal` — только при `true`.
- Ошибки — как у всех методов (`IrazasyedTelegramClient::call()`): 429 → `TelegramRateLimitedException` (`retry_after`, пауза общего
  исходящего бюджета), прочие отказы и сбои — `TelegramApiException` без токена. `FakeTelegramClient`: `$preparedMessages`,
  `$inlineAnswers`, `$preparedExpirationDate`, отказы `$failInline` (+ `$failInlineRetryAfter`, `$failInlineAsTransport`).
- `inline_query` в `ALLOWED_UPDATES` пока **не** добавлен — это задача H5 (вместе с `InlineQueryHandler` и флагом `share.inline_enabled`).

**Отправка с номером сообщения и правка подписи: `sendPhotoMessage`, `sendTextMessage`, `editMessageCaption`** (docs/35 §1.6,
задача O1; формат сверен с core.telegram.org/bots/api 25.11.2026 — `sendPhoto`, `editMessageCaption`, `PhotoSize`, «Sending files»).
Нужны опросам (docs/35 §1.5): номер сообщения — для правки подписи после ответа, `file_id` — чтобы картинка опроса уходила
повторно без загрузки. Отправляют **сразу**, в очередь не откладывают (как `sendGroupMessage`): слот общего бюджета берёт
вызывающее задание. Старые `sendPhoto` и `sendMessage` не менялись.

- `sendPhotoMessage(chatId, PhotoSource, caption = null, replyMarkup = null): SentMessage` — подпись простым текстом (без
  `parse_mode`, у Telegram 0–1024 знака). `PhotoSource::file($path)` — локальный файл, `multipart/form-data` (поле `photo` с именем
  файла; у Telegram до 10 МБ, сумма сторон ≤ 10 000); нечитаемый файл — `TelegramApiException` до вызова Telegram.
  `PhotoSource::fileId($id)` — `file_id` этого бота, обычное поле формы, ничего не загружается. `PhotoSource::url($url)` — http(s),
  Telegram скачивает сам (до 5 МБ). Вызов — сырой `post('sendPhoto', …)`: метод SDK `sendPhoto()` угадывает вид по строке.
- `sendTextMessage(chatId, text, replyMarkup = null): SentMessage` — простой текст; это тот же вызов, что `sendGroupMessage` без темы
  (отдельной реализации нет), ответ — `SentMessage` без фото, чтобы отправитель опроса получал один тип результата.
- `SentMessage {messageId, photoFileId}`: `photoFileId` — `file_id` **наибольшего** `PhotoSize` из `Message.photo` (по площади, при
  равенстве — по `file_size`; порядок массива не важен), `null` у текста. `file_id` принадлежит только этому боту, у одной картинки
  их может быть несколько — годится любой. Ответ «ok» без `message_id` — `TelegramApiException` как сбой транспорта
  (`isApiResponse() = false`: сообщение могло уйти, повторять нельзя).
- `editMessageCaption(chatId, messageId, caption, replyMarkup = null)` — новая подпись и клавиатура; `null` — клавиатура убирается
  (`{"inline_keyboard":[]}`, как у `editMessageText`). Отказ «message is not modified» — `TelegramApiException::isMessageNotModified()`;
  403 при правке — обычный `TelegramApiException` (правка никогда не говорит «ученик недоступен»).
- Ошибки — по B.7a: 403 и 400 `chat not found` у отправок → `TelegramForbiddenException`; 429 → `TelegramRateLimitedException`
  (`retryAfter`, пауза бюджета); группа стала супергруппой — отправка один раз повторяется в новом чате (файл загружается заново),
  правка подписи не повторяется. `FakeTelegramClient`: `$photoMessages` (вид источника, ответный `file_id`: тот же при отправке по
  `file_id`, иначе `fake-photo-<n>`), `$textMessages`, `$captionEdits`; общий счётчик `$nextMessageId`; отказы отправок —
  `$failSends` (+ `$failSendsRetryAfter`, `$failSendsForbidden`, `$failSendsAsTransport`), отказы правки — `$failCaptionEdits`
  («not modified»), `$failCaptionEditsWith` (другой 400).

#### Онбординг и `/settings`: шаги и `callback_data`

Шаги по порядку: `locale` (🇷🇺/🇺🇿, каждый язык своим названием) → `goal` (`general`/`ielts`/`sat`/`school`) → `level` (`A1`/`A2`/`B1`/`B2`/`C1`/`C2`/`unknown`→`A1`) → `timezone` (список из `config('learners.timezone_options')`, по умолчанию Ташкент и соседи; значение — IANA-id, ещё раз проверяется по `DateTimeZone::listIdentifiers`) → `reminder` (`0800`/`1300`/`1900`/`2100`/`off` → `reminder_time` или `NULL`). `learners.onboarding_state` — текущий шаг (`new` → `locale` … `reminder` → `done`). Дефолты тем, кто их не меняет: цель 20 карточек в день (`daily_goal`), напоминание 19:00, `Asia/Tashkent`.

| `callback_data` | Смысл |
|---|---|
| `onb1:<step>:<value>` | ответ на шаг онбординга. Кнопки шагов, которых больше нет (`onb1:timezone:<zone>`; `onb1:goal:<g>` при одной цели) из старого сообщения: callback отвечается; застрявшего на этом шаге переводит дальше (`timezone` → `reminder`, `goal` → `level`, цель = `general`), остальным — ничего |
| `set1:<step>` | `/settings`: показать варианты шага |
| `set1:<step>:<value>` | `/settings`: сохранить значение (`/language` показывает кнопки `set1:locale:<код>` сразу) |
| `org1:join:<код>` / `org1:skip` | подтверждение вступления в центр по `org_<код>` / отказ (код — `[A-Za-z0-9_-]{1,32}`) |
| `org1:list` | `/settings` → «Мои центры» |
| `org1:leave:<id>` / `org1:leaveyes:<id>` / `org1:leaveno` | «Покинуть центр»: подтверждение / выполнить / отмена (`<id>` — `organization_members.id`, только своё активное членство) |

**Кнопки `/pro` (`ProCallbackHandler`):** `pro1:buy:<period>:<provider>`, `pro1:cancel`, `pro1:resume`. Формат — строгий regex; `period`/`provider` проверяет `CheckoutService` (сумму кнопка не несёт — её считает сервер). Принимаются только из личного чата отправителя
(кнопка из группы игнорируется), не чаще 10 нажатий в минуту на learner'а (`RateLimiter` `bot-pro:<learner_id>`). Успешная покупка — сообщение «Счёт создан: …» с URL-кнопкой «Оплатить» (`checkout.url`), отказ — текст по `error.code`
(`lang/{ru,uz}/billing.php`, `billing.pro.errors.*`; незнакомый код — `generic`). Тексты `/pro`, `/paysupport` — `lang/{ru,uz}/billing.php`.

Префикс несёт версию формата (`onb1`, `set1`): при смене раскладки старые кнопки перестают совпадать и молча игнорируются. Подписи нет — `callback_data` считается недоверенным: формат проверяется строгим regex, `<step>` — из списка шагов, `<value>` — по белому списку шага. `OnboardingService::apply` принимает **только ответ на текущий шаг**, под блокировкой строки learner'а: повторное нажатие старой кнопки, двойной тап и параллельные доставки отклоняются без изменений; на любой callback бот сразу отвечает `answerCallbackQuery` без текста — через `CallbackAcknowledger`: отказ Telegram («query is too old», повтор job) логируется (`Log::warning`, без PII) и работу хендлера не прерывает; это же относится к `tstart:`. Кнопки принимаются только из личного чата отправителя (`message.chat.id = from.id`), ответы идут на `from.id`; нажатие из группы, чужого чата или учителем (у него нет learner'а) ничего не создаёт и не отправляет. Исключение из «молчаливого» stale: если шаг устаревшей кнопки `onb1` — ровно предыдущий перед текущим состоянием learner'а (ответ сохранён, а следующий шаг не дошёл до Telegram и job `HandleTelegramUpdate` повторяется, tries = 3), бот **отправляет текущий шаг ещё раз**; двойной тап может дать дубль вопроса — это допустимо. После принятого ответа у сообщения снимается клавиатура (`editMessageReplyMarkup`; ошибка Telegram при этом не мешает потоку) и приходит следующий шаг; после последнего — сообщение с web_app-кнопкой «Начать первую тренировку» → `/twa/?from=onboarding` (с подсказкой «Пригласите друзей — получите Pro бесплатно: /invite»). Прямо перед ним новичку, которому выдан пробный Pro, приходит одно короткое сообщение «🎁 Вам открыт Pro на 14 дней — бесплатно, до дд.мм.гггг. Пригласите 3 друзей — получите ещё 7 дней: /invite» (`TrialWelcomeMessenger`; числа — `billing.trial.days`, `referrals.milestones`, `referrals.milestone_days`): к этому моменту язык уже выбран, вопрос онбординга и его кнопки не разрываются, а кнопка «Начать» остаётся последним сообщением. Одноразово: `ProTrialService::consumeNotice` — кто взял первым, бот или Mini App, тот и сказал (открывший Mini App до конца онбординга в боте этого сообщения не получит; отправка, не дошедшая до Telegram, повтора не имеет). Ученику, завершившему онбординг в Mini App, оно приходит на его первом `/start`. При установке или смене часового пояса и времени напоминания (онбординг и `/settings`) `next_reminder_at` сразу пересчитывается: `LearnerClock::nextOccurrenceUtc(learner, reminder_time, now())` с новыми значениями (Ташкент 19:00 → 14:00 UTC; «без напоминаний» → `NULL`); завершение онбординга тоже выставляет его. Дальше его ведёт планировщик напоминаний (docs/12 §7.5). `OnboardingService::applySetting` сам проверяет, что онбординг завершён. События: `onboarding.started {source_kind}` (вид **принятой** атрибуции learner'а — `ref`/`src`/`course`/`org`/`group`; `direct`, если её нет: payload отвергнут — невалидный, самореферал, неизвестный/заблокированный реферер — или не было), `onboarding.step_completed {step}`, `onboarding.completed {goal, level, locale}`, `course.selected {course_id, via: onboarding|deeplink}`.

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

### B.4b Кнопки под сообщениями бота (docs/36 §3, 25.11.2026)

**Правило.** У каждого сообщения ученику, после которого логично действие, есть **ровно одна главная кнопка**; вторая — только если это выбор (Принять / Отклонить) или отказ от рассылки (docs/34 «Не присылать предложения»). Служебные сообщения (возврат Stars, удаление аккаунта, ошибки, «запрос отправлен») — без кнопки.

**Как строится.** Экран Mini App — только из enum `App\Domain\Telegram\Support\TwaScreen` (`/`, `/courses`, `/courses/{slug}`, `/league`, `/league/month`, `/friends`, `/battles/{id}`, `/training/{id}`, `/exam/{id}`, `/game/class/{id}`, `/referrals`, `/billing`, `/achievements`). Адрес и клавиатуру строит `App\Domain\Telegram\Services\BotButtons`:
- `twaUrl(TwaScreen, params, query)` — единственное место, где читается `twa.base_url` (кроме `MiniAppLinks`, `telegram:setup-bot`, `BotMenuBuilder`); тест-страж `tests/Unit/TwaBaseUrlGuardTest.php`;
- `open(text, screen, params, query)` — кнопка `web_app`; `callback(text, data)` — `callback_data` ≤ 64 байт (длиннее — `InvalidArgumentException`); `url(text, url)`;
- `keyboard(chatId, rows)` / `single(chatId, button)` — `reply_markup`; вне личного чата `web_app` превращается в URL `t.me/<bot>?startapp=<base64 пути>`, без имени бота — в обычный адрес (как `sendWebAppButton`).
- `from=<вид сообщения>` передаётся в `query` — аналитика видит, какое сообщение привело в приложение.

Тексты кнопок — `lang/{ru,uz}/buttons.php` (одна формулировка — одно место). Старые ключи кнопок (`reminders.open_button`, `leagues.open_button` и др.) переводятся на `buttons.*` постепенно, когда трогается их отправитель.

| Сообщение (ключ lang) | Отправитель | Кнопка | Куда |
|---|---|---|---|
| Напоминания `reminders.review / streak_at_risk / inactive / inactive_due / new_words` | `ReminderSender` | `buttons.start_lesson` «▶️ Начать занятие» | `/?from=reminder` |
| `reminders.never_started` | `ReminderSender` | `buttons.first_lesson` «▶️ Первое занятие» | `/?from=reminder` |
| `reminders.choose_course` | `ReminderSender` | `buttons.choose_course` «📚 Выбрать курс» | `/courses?from=reminder` |
| Напоминание следующего дня про очки `reminders.score_due` (docs/31 §5б) | `ReminderSender` | `buttons.review_words` «🔁 Повторить слова» | `/?from=reminder` |
| Напоминание-вопрос `reminders.quiz.*` | `ReminderQuizMessenger` | cb варианты ответа + «Открыть Muhrla» | `/?from=reminder` (после ответа `reminder_quiz`) |
| Итог недели `leagues.result.*` | `LeagueResultSender` | `leagues.open_button` | `/league` |
| Победа в месяце: фото `leagues.month.card.*` + подпись `leagues.month.won / won_plain` | `MonthWinnerSender` | `buttons.month_rating` «🏆 Рейтинг месяца» | `/league/month?from=month_won` |
| Награда за друзей `referrals.milestone.reached + next` | `ReferralMilestoneSender` | `buttons.invite_more` «👥 Пригласить ещё» | `/referrals?from=milestone` |
| Запрос в друзья `friends.incoming.text` | `FriendsMessenger` | cb Принять / Отклонить | — |
| Друг принял запрос `friends.dm.friend_accepted` | `FriendsMessenger` | `buttons.challenge_friend` «⚔️ Бросить вызов» | `/friends?friend=<friendship_id>&from=friend_accepted` |
| «Вы теперь друзья» `friends.accepted` (ответ на «Принять» / встречный запрос) | `FriendsMessenger` | `buttons.friends` «👥 Друзья» | `/friends?from=friends` |
| Вызов предложен `friends.dm.challenge_proposed` | `FriendsMessenger` | cb Принять / Отклонить | — |
| Вызов принят `friends.dm.challenge_accepted` и ответ принявшему `friends.challenge.accepted` | `FriendsMessenger` | `buttons.open_challenge` «🎯 Открыть вызов» | `/friends?challenge=<id>&from=challenge_accepted` |
| Вызов завершён `friends.dm.challenge_won / draw` | `FriendsMessenger` | `buttons.new_challenge` «🎯 Новый вызов» | `/friends?friend=<friendship_id>&from=challenge_finished` (дружбы больше нет — без кнопки) |
| Вызов проигран `friends.dm.challenge_lost` | `FriendsMessenger` | `buttons.rematch` «🔁 Реванш» | то же |
| Приглашение в баттл `battle.dm.invited` | `BattleMessenger` | «Открыть баттл» | `/battles/{id}` |
| Пробный Pro `billing.trial.welcome` | `TrialWelcomeMessenger` | в онбординге — нет (следом финал с кнопкой); на `/start` — `buttons.start_lesson` | `/?from=trial_welcome` |
| Продление / конец пробного `billing.renewal.*` | `RenewalReminderSender` | «Продлить» | `/billing?from=renewal` |
| Личная скидка `billing.offer_dm.*` | `OfferMessenger` | cb покупки | — |
| Оплата прошла `billing.stars.paid*` | `SuccessfulPaymentHandler` | `buttons.start_lesson` | `/?from=paid` |
| Возврат Stars `billing.stars.refunded` | `RefundedPaymentHandler` | нет (служебное) | — |
| Промокод применён `bot.promo.success_*` | `PromoCommandHandler` | `buttons.start_lesson` | `/?from=promo` |
| Аккаунт восстановлен `bot.account.restored*` | `StartCommandHandler` | `buttons.open_app` «▶️ Открыть приложение» | `/?from=restored` |
| Онбординг: конец `onboarding.done.*`, `/today`, центр, предложение дружбы по ссылке | `OnboardingMessenger`, `TodayScreen`, `OrganizationMessenger`, `FriendsMessenger::offer` | без изменений | — |
| Групповые (урок, экзамен, «Караван», итоги, задания) | `GroupSessionAnnouncer`, `ExamLauncher`, `ClassCaravanMessenger`, `PostLeaderboardJob`, `AssignmentService` | url `startapp` | без изменений |

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
- **`message` с `migrate_to_chat_id` / `migrate_from_chat_id`** — группа стала супергруппой и получила новый `chat_id` (docs/33 §4.6; служебные сообщения бот получает всегда, режим приватности не мешает). `ChatMigrationHandler` — первый в списке хендлеров сообщений, работает при любом статусе группы и не отбрасывается входящим лимитом флуда. `GroupChatMigrator::migrate(old, new)`: под одной транзакцией `FOR UPDATE` обеих строк (`chat_id IN (old, new)`, по `id`) → строка группы получает новый `chat_id` (`meta.type = supergroup`, `meta.migrated_from_chat_id`); организация, учителя, участники, сессии и караваны висят на `telegram_group_id` и идут за ней сами. Кто пришёл первым (служебное сообщение в старом или в новом чате, или отказ отправки, B.7a), тот переносит; остальные видят перенос и ничего не делают. Если строка нового чата уже есть (её создал `my_chat_member` супергруппы): пустая (`pending`, без центра, учителей, сессий, караванов, заданий, приглашений) — её участники и события переносятся на старую строку, сама она удаляется; «живая» — ничего не сливается, в лог `telegram.group_migration_conflict` (разбирает админ). Вставка нового `chat_id` между нашей блокировкой и UPDATE (уникальный индекс) → шаг повторяется один раз и видит обе строки.

### B.5a Персональные напоминания (`reminders:dispatch`)

Напоминание приходит в ЛС в **локальное** время learner'а (`learners.reminder_time` в `learners.timezone`; время выбирается в онбординге / `/settings`, пояс — `learners.default_timezone` = Asia/Tashkent при создании, выбора пояса нет; дефолт 19:00 Ташкент). Механика — docs/12 §7.5, docs/06 §6:

- `reminders:dispatch` каждые 15 минут: `ReminderDispatcher` выбирает `next_reminder_at <= now AND dm_blocked_at IS NULL AND blocked_at IS NULL AND deleted_at IS NULL`, для каждого делает условный `UPDATE … SET next_reminder_at = <следующее наступление по LearnerClock> WHERE id = ? AND next_reminder_at = <прочитанное>` и только при `affected = 1` ставит `SendLearnerReminderJob(learnerId, scheduledFor)`. Учитывается и learner с незавершённым онбордингом (у него дефолтные пояс и 19:00). Запоздавшие на > `REMINDERS_MAX_DELAY_MINUTES` (120) клеймы не отправляются — указатель всё равно уходит на следующее наступление. Backfill: у кого `reminder_time` есть, а `next_reminder_at` пуст, получают его тут же (до `REMINDERS_BACKFILL_LIMIT` = 10000 за запуск); job'ы идут в очередь `default` с разносом (`REMINDERS_SEND_RATE_PER_SECOND` = 25/с, задержка не больше 14 мин); новым learner'ам `LearnerRegistrar` ставит его при создании.
- Текст решает `ReminderContentResolver` в job: `due_count` (`DueRepetitionCriteria`, то же число, что на экране «Сегодня») `>= REMINDERS_DUE_THRESHOLD` (1) → `reminders.review` (trans_choice); иначе серия под угрозой (`StreakService::current().atRisk`) → `reminders.streak_at_risk` (`:count` = дней серии); иначе молчание. Кнопка `reminders.open_button` — web_app → `/twa/?from=reminder`: экран «Сегодня» сразу открывает `daily_review` с `source: 'reminder'`.
- Идемпотентность: клейм указателя (двойной запуск планировщика ничего не дублирует) + `notifications_log` (`review_reminder` / `streak_reminder` / `inactive_reminder` / `never_started_reminder` / `new_words_reminder` / `choose_course_reminder`) под `Cache::lock` — не больше одного напоминания любого вида за **локальный день** learner'а и минимум `REMINDERS_MIN_INTERVAL_HOURS` (2) между любыми двумя. Строка лога пишется до вызова Telegram: после двусмысленной ошибки повтор job ничего не шлёт (не больше одного раза).
- Telegram 403 (`bot_blocked` / `not_started` / `user_deactivated`) или 400 `chat not found` (`chat_not_found`) → строка лога удаляется, `dm_blocked_at = now`, событие `reminder.failed {reason, kind}`, без повторов; learner выпадает из выборки. `my_chat_member` в личном чате: `kicked` → `dm_blocked_at = now`, `member` → `NULL` (learner не создаётся). **Любой** входящий `message` или `callback_query` из приватного чата (`/help`, `/stats`, `/promo`, кнопки, текст) снимает `dm_blocked_at` — `TelegramDispatcher` делает это до хендлеров одним условным UPDATE по `telegram_user_id`, без создания learner'а. Вход в TWA (`/auth`) флаг **не** снимает: открытие приложения не доказывает, что ЛС с ботом открыт.
- События: `reminder.sent {kind, due_count[, quiz]}`, `reminder.failed {reason, kind}`, `reminder.quiz_sent`, `reminder.quiz_answered`, `reminder.quiz_expired` (см. ниже).
- **Напоминание-вопрос** (решение владельца 2026-11-17; правила, таблица видов, конфиг `REMINDERS_QUIZ_*` — docs/12 §7.5). Для видов `review` / `streak_at_risk` / `new_words` и `inactive` до `REMINDERS_QUIZ_MAX_IDLE_DAYS` (7) `ReminderSender` вместо `sendWebAppButton` шлёт одно `sendMessage` (plain text, без `parse_mode`) с клавиатурой: 4 строки по одной callback-кнопке (текст = вариант, обрезан до 40 символов; полный текст — в вердикте) + строка web_app «Открыть LexiFlow» → `/twa/?from=reminder`. Текст: подводка по виду (`reminders.quiz.lead.*`) + вопрос (`quiz.question.meaning|reverse`) + футер `/settings`. Любой сбой → прежнее текстовое напоминание.
- **`callback_data` `rq1:<token>:<i>`** (стиль онбординга): `token` — 10 символов `[A-Za-z0-9]`, `i` — 0..3; строгий regex `~^rq1:([A-Za-z0-9]{10}):([0-3])$~D`, подписи нет — данные недоверенные, верного варианта в них нет (он в Redis `reminders:quiz:v1:<learner_id>`, docs/19 §2.2). Принимается только из личного чата отправителя (`message.chat.type = private`, `chat.id = from.id`); learner — по `from.id` (`DmLearnerResolver`, у сотрудника ничего не происходит, `blocked_at` — молчание), никогда по данным кнопки. Идемпотентность: под `Cache::lock` первый тап пишет ответ и событие, повтор / double tap / retry `HandleTelegramUpdate` получают тот же вердикт без события и снова правят сообщение. «Устарело» (нет состояния, > 26 ч, чужой токен, `InaccessibleMessage`): `answerCallbackQuery` с alert, `editMessageReplyMarkup` → одна web_app-кнопка `/twa/?from=reminder`, событие `reminder.quiz_expired`.
- **После ответа** бот вызывает `editMessageText` (новый метод контракта `TelegramClient`; plain text): «✅ Верно! слово — перевод.» / «❌ Не совсем. слово — перевод.» + «В приложении ждут N слов — хватит 5 минут.» (N — живой счётчик due, не вчерашний; 0 → «Продолжите в приложении — там новые слова.») + футер `/settings`, клавиатура — одна web_app-кнопка → `/twa/?from=reminder_quiz`. Отказ Telegram («message is not modified», сообщение старше 48 ч) только логируется. Ответ **не** повторение: ничего не пишется в `word_repetitions` / `learner_daily_stats` / `training_*`. `allowed_updates` не меняется (`callback_query` уже есть). `POST /training/sessions`: `source` дополнен значением `reminder_quiz` (`OpenTrainingSessionRequest`).- **Напоминание после урока** (docs/31 §5б, G1–G3, 25.11.2026). `/finish` сессии `lesson`, в которой были новые слова (`SessionSummary::newWords > 0`; только вызов, закрывший сессию, после коммита) → событие `Learning\Events\LessonWithNewWordsFinished` → синхронный слушатель `QueueLessonNudge` → `LessonNudgeScheduler`: маркер `learners:lesson-nudge:v1:<learner_id>:<локальная дата>` в невытесняемом Redis (`Cache::add`, TTL до конца локального дня + 6 ч) — не больше одной постановки в локальный день; задание `SendLessonNudgeJob` в очередь `REMINDERS_LESSON_NUDGE_QUEUE` (`default`) с задержкой `REMINDERS_LESSON_NUDGE_DELAY_MINUTES` (45). Выключатель — `REMINDERS_LESSON_NUDGE_ENABLED` (в тестах `false`, свои тесты включают). Ничего не ставится, если ученик заблокирован, ЛС закрыта или напоминания выключены.
  - `LessonNudgeSender` при срабатывании перепроверяет: ученик есть, не `blocked_at`, `dm_blocked_at` и `dm_blocked_reason` пусты, `reminder_time` задан (напоминания не выключены); всё ещё тот же локальный день; не ночь — местный час вне `[quiet_from_hour, quiet_to_hour)` = `[22, 8)` (ночное задание **пропускается**, на утро не переносится); `DayPlanService::forLearner()->offersGame()` (урок с новыми словами сегодня пройден, игра открыта ученику, игры на его словах сегодня не было); общий лимит «одно сообщение о занятиях в сутки» — `StudyMessageLimit` (тот же замок `reminders:send:<id>` и та же проверка `notifications_log`, что у персонального напоминания: любой из типов напоминаний **или** `lesson_nudge` с начала локального дня / за `REMINDERS_MIN_INTERVAL_HOURS`). Значит, после этого сообщения вечернее напоминание в тот же день не придёт, и наоборот.
  - Текст `reminders.lesson_nudge.text` (`text_untitled` без названия урока): «Урок «:lesson» пройден: :count новых слов. Закрепите их в игре «Пары на время»…» + подвал `/settings`; `:count` — слова урока, встреченные сегодня (`DayPlan::todayWords`). Об очках не говорит: слова, выученные сегодня, в игре в тот же день очков не дают. Одна кнопка `buttons.lesson_game` («🎮 Закрепить в игре») через `BotButtons`: `web_app` на `TwaScreen::PracticeHub` — `/twa/practice/<slug курса>?lesson=<id>&play=pairs_timed&from=lesson_nudge`.
  - Маркер отправки — строка `notifications_log` `type = lesson_nudge` (`payload: lesson_id, words, date`) до вызова Telegram; ошибки — по docs/34 §4в п. 8: 403 → строка удаляется, `DmReachability::markUnreachable`; иной определённый отказ (и 429) → строка удаляется, исключение пробрасывается (429 — `ThrottleTelegramOutbound` откладывает задание на `retry_after`); таймаут / 5xx → строка остаётся. Событие `lesson_nudge.sent {lesson_id, words}`.
- **Напоминание следующего дня про очки** (docs/31 §5б, 25.11.2026) — вариант текста персонального напоминания, не отдельное сообщение:
  - `ReminderDispatcher` на каждую пачку (`chunkById`, `REMINDERS_BATCH_SIZE`) делает **один** запрос `ReviewScoreHint::learnerIds()`: `SELECT DISTINCT learner_id FROM word_repetitions WHERE (learner_id IN (…) AND created_at >= <начало локального вчера> AND created_at < <конец>) OR (…другой пояс…) AND introduced_mode IN ('lesson','group_lesson') AND last_reviewed_at IS NOT NULL AND next_review_at <= now` (ученики пачки сгруппированы по UTC-границам своего вчерашнего дня; ученики, которые не участвуют в рейтинге — `meta.leagues_opt_out` или `blocked_at`, — отбрасываются ещё в PHP по уже загруженной пачке: им «начисляются баллы рейтинга» было бы неправдой). Признак уходит в задание: `SendLearnerReminderJob::$scoreHint` (обычное свойство со значением `false` — задание, поставленное до выката, читается без признака). Пока очки не считаются (`leagues.scoring.mode` не `live` или рейтинговый день ни в неделе, ни в месяце не на v2 — `ReviewScoreHint::enabled()`, только конфиг) запроса нет.
  - План на PostgreSQL 16 (3 млн строк `word_repetitions`: 2 000 учеников × 1 500 слов, 10 новых в день, два пояса в пачке из 200): BitmapOr из двух Bitmap Index Scan по `word_repetitions_learner_id_created_at_index` (читаются только строки вчерашнего дня — 2 000), Bitmap Heap Scan 49 страниц, 652 буфера, 1,2 мс. Новых индексов нет.
  - Число запросов рассылки: было 2 + N (выборка без указателя, пачка, N условных `UPDATE`), стало 2 + N + 1 на пачку в режиме очков (тест `ReviewScoreHintReminderTest`: 3 и 30 учеников — разница ровно 1).
  - `ReminderSender` подменяет текст, если признак есть, очки всё ещё считаются, вид напоминания `review` и ученик сегодня не занимался (`idleDays >= 1`) — без новых запросов. Текст `reminders.score_due` + кнопка `buttons.review_words` «🔁 Повторить слова» → `/?from=reminder`; в напоминании-вопросе — вводная `reminders.quiz.lead.score_due`. `notifications_log.payload.score_hint = true`, `reminder.sent {…, score_hint: true}` (у остальных напоминаний поля нет). Лимит «одно сообщение о занятиях в сутки» (`StudyMessageLimit`) — общий: если сегодня уже было напоминание после урока, ничего не уходит.

### B.5b Опросы в боте: кнопки `pl1` *(docs/35 §1.5; ядро голоса — O4, обработчик — O5 ✅ `PollCallbackHandler`)*

Опрос — фото с подписью (`sendPhotoMessage`) или текст (`sendTextMessage`) без `parse_mode` и inline-кнопки по одной в ряд; одна
строка `poll_deliveries` на получателя (docs/03 §2.74). Подпись — вопрос; если какой-то вариант длиннее 24 символов — ниже
нумерованный список вариантов, а кнопки — «1», «2», … Кнопки строятся только через `BotButtons`.

- **`callback_data` `pl1:<token>:<n>`**: `token` — 12 символов `[A-Za-z0-9]` (`poll_deliveries.token`), `n` — позиция варианта
  `0..9`, `s` — «Отправить» (несколько ответов), `c` — «Изменить ответ». До 19 байт из 64. Строгий regex
  `~\Apl1:([A-Za-z0-9]{12}):([0-9]|s|c)\z~` (`PollCallback`; перевод строки в конце — не конец); подписи нет — данные недоверенные и
  ничего не утверждают (как `rq1`).
- Принимается только из личного чата: `message.chat.id = from.id` и `from.id = poll_deliveries.telegram_user_id` строки, найденной по
  токену. Пересланное сообщение, чужое нажатие, группа, неизвестный токен, позиция ≥ числа вариантов, `InaccessibleMessage`
  (`date = 0`) → алерт «Опрос недоступен».
- Опрос `closed` / `cancelled` или наступил `closes_at` (проверка на сервере при нажатии) → алерт «Опрос завершён», клавиатура
  убирается. Массово клавиатуры при закрытии не правятся.
- Один ответ: нажатие варианта записывает голос; повтор того же варианта ничего не пишет и даёт тот же итог. Несколько ответов:
  нажатие переключает вариант (☐/☑, с учётом `max_choices`), «Отправить» с пустым выбором — тост «Выберите хотя бы один вариант».
- После ответа подпись (или текст) правится (`editMessageCaption` у фото / `editMessageText` у текста): вопрос (без списка вариантов)
  + «✅ Спасибо! Ответ принят.» на языке доставки — **без** выбранного ответа и без итогов (docs/31 §5г); ниже «✏️ Изменить ответ»
  (`pl1:<token>:c`), если смена разрешена, иначе кнопок нет. Отказ правки только логируется (`telegram.poll_edit_failed`, код);
  «message is not modified» (двойное нажатие) — не ошибка, не логируется.
- Ответы на нажатие (`answerCallbackQuery`), по итогу `PollVoteService::tap()`:

  | Итог | Ответ на нажатие | Правка сообщения |
  |---|---|---|
  | `Voted`, `Repeated` | без текста | «Спасибо» (+ «Изменить ответ»); `Repeated` лечит потерянную правку |
  | `Locked` (ответ есть, смена запрещена) | тост «Ответ уже принят, изменить его нельзя.» | «Спасибо» |
  | `Changing` («Изменить ответ») | без текста | вопрос и варианты снова, текущий ответ отмечен (несколько ответов) |
  | `Toggled` (несколько ответов) | без текста | только клавиатура ☐/☑ (`editMessageReplyMarkup`) |
  | `LimitReached` | тост «Можно выбрать не больше N.» | нет |
  | `EmptySubmit` | тост «Выберите хотя бы один вариант.» | нет |
  | `Closed` | алерт «Опрос завершён.» | клавиатура убирается |
  | `Unavailable`, а также группа, пересланное сообщение, чужое нажатие, `InaccessibleMessage`, неверный формат данных | алерт «Опрос недоступен.» | нет |

  Язык — язык доставки (`poll_deliveries.locale`); для отказа до того, как доставка найдена, — `from.language_code` (`uz…` → uz, иначе ru).
- Цена нажатия в БД — только операторы `tap()` (1–5) + `markReachable` диспетчера (1) + событие при ответе (1): подпись и клавиатура
  берутся из чистого кэша `polls:view:v2:<poll_id>` (`PollViewCache`, 600 с, массив скаляров с `version` — `updated_at` опроса в
  секундах; промах — 2 чтения, `polls` и `poll_options`; сбрасывает `PollEditor` после записи черновика). Нажатие знает версию опроса
  из своего же оператора (`PollTapResult::pollVersion`) и запись старше неё читает заново: старый вид, положенный в кэш нажатием,
  которое прочитало опрос до правки, не показывается. Закреплено тестом `PollCallbackTest`.
- Ответ не пишет ничего в `learner_daily_stats`, очки и серию; событие продукта — `poll.answered` (`poll_id`, `delivery_id`, `changed`)
  при `Voted` и не тестовой доставке, без `learner_id` (в итоге нажатия его нет; ученик — по `delivery_id`).
  `allowed_updates` не меняется (`callback_query` уже есть). Тексты — `lang/{ru,uz}/polls.php`: `submit`, `change`, `thanks`, `closed`,
  `unavailable`, `empty`, `limit`, `locked`.

### B.6 Событие `update` от WebApp (опционально)

Если TWA вызывает `Telegram.WebApp.sendData(...)`, то в чат приходит апдейт типа `web_app_data` (только в приватных чатах). В нашем дизайне студент общается с API напрямую, а не через `sendData` — это упрощает масштабирование. Но оставляем возможность добавить лёгкие «отправки» (например, «поделиться результатом экзамена»).

### B.7 Rate limiting отправки сообщений
- Не более 20 сообщений в минуту в один чат (группа: лимит Telegram; личный чат: Telegram просит не чаще ~1 в секунду, короткие всплески терпит — core.telegram.org/bots/faq, сверено 25.11.2026).
- Реализация: скользящее окно в Redis в `TelegramMessageRateLimiter::reserve()`, его вызывает `IrazasyedTelegramClient::sendMessage()`.
- Если лимит достигнут или чат на паузе после 429 (B.7a) — сообщение уходит заданием `SendTelegramMessageJob` с задержкой.
- Общий бюджет бота (~30/с у Telegram, у нас `TELEGRAM_OUTBOUND_GLOBAL_PER_SECOND` = 25/с) и пауза после 429 — docs/13 §9 (2R.3, 2R.3b). Ответы на входящие обновления не ждут, но учитываются в том же счётчике секунды (посредник `CountTelegramReplies` у `HandleTelegramUpdate`).
- **Массовая рассылка уступает всем** (опросы, docs/35 O3): `TelegramMessageRateLimiter::reserveGlobalBelow($ceiling)` даёт слот, только пока в счётчике текущей секунды (все фоновые отправки + учтённые ответы) меньше `POLLS_SEND_PER_SECOND` (15 из 25; проверка и `INCR` — один Lua), нет паузы после 429 и в последние 2 с никому из обычных отправителей не отказали (`reserveGlobal()` при отказе ставит `telegram:outbound:contended` на 2 с). Итог: рассылка забирает только простаивающую часть бюджета, обычным всегда остаётся не меньше 10/с, а под их нагрузкой рассылка встаёт целиком.

### B.7a Ошибки Bot API: классификация (docs/34 §4в п. 1)

Всё, что SDK бросает, проходит через `IrazasyedTelegramClient::call()`. Наружу выходят только классы ниже, без токена бота в сообщении и без `previous` (docs/DEVOPS_GUIDE §15.1a). Порядок проверки:

| Ответ Telegram | Класс | Доставлено? | `isApiResponse()` | Что делает отправитель |
|---|---|---|---|---|
| 429 на **любом** методе (`parameters.retry_after`) | `TelegramRateLimitedException extends TelegramApiException` (`retryAfter`) | нет | true | Удалить маркер, пробросить. Клиент уже поставил паузу (docs/13 §9, 2R.3b): паузу чата всегда, общую паузу бота — только при 429 в личный чат (`chat_id > 0`) или в запросе без чата; 429 в группу (`chat_id < 0`) общую паузу не ставит; `ThrottleTelegramOutbound` откладывает задание на `retryAfter` + 0–3 с, `maxExceptions` не тратится |
| 400 с `parameters.migrate_to_chat_id` | `TelegramChatMigratedException extends TelegramApiException` (`newChatId`) | нет | true | Для отправок (`sendMessage`, `sendPhoto`, `sendPhotoMessage`, `sendTextMessage`, `sendWebAppButton`, отложенная `SendTelegramMessageJob`) и `getChatMember` клиент сам переносит группу (B.5) и **один раз** повторяет вызов в новом чате — отправитель ничего не видит. Правка сообщения (`editMessageText`, `editMessageReplyMarkup`, `editMessageCaption`) не повторяется (у сообщений нового чата другие id): группа переносится, исключение пробрасывается. Второй ответ «migrated» подряд — тоже пробрасывается |
| 403; 400 `chat not found` (только отправки) | `TelegramForbiddenException` (`reason`) | нет и не будет | — | Удалить маркер, отметить адресата недоступным, завершить без ошибки |
| Прочие 4xx | `TelegramApiException` | нет | true | Удалить маркер, пробросить (повтор по `backoff`, после `maxExceptions` — `failed_jobs`) |
| Таймаут, сеть, 5xx | `TelegramApiException` | неизвестно | false | Маркер оставить, пробросить (повтор увидит маркер — не больше одного раза) |

Старые `catch (TelegramApiException)` ловят оба новых класса: для них это та же «определённая отказом» ошибка, что и раньше. Пауза после 429 ставится до того, как исключение выйдет из клиента; недоступный Redis паузу теряет (`telegram.rate_limit_pause_failed`), но не скрывает 429. Спецификация по каждому отправителю — docs/34 §4в, «Спецификация для отправителей».

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
- Learner: ЛС с web_app-кнопкой «Продлить Pro» → `/twa/billing?from=renewal`; `notifications_log.type = renewal_reminder`, `payload.key = "<subscription>:<N>@<конец периода>"`; не пишем при `blocked_at`/`dm_blocked_at`; 403 → `dm_blocked_at` и запись лога удаляется. Порядок с предложениями Pro после пробного (`trial_offer`, docs/34 §2д п. 4): не больше одного сообщения о Pro в локальные сутки, **напоминание о подписке важнее** — оно уходит всегда, а предложение того же дня переносится на следующий (`notifications_log.type = trial_offer_deferred`, +24 ч жизни шага; на следующий день условия предложения проверяются заново).
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

### B.15 Караван класса: сообщения в чат группы (docs/18 §12.11; задача G2.9b)
Команды `/start_caravan` и `/cancel_caravan` — B.3. Все тексты — простой текст без HTML, на языке группы (`LocaleResolver::forGroup`, `lang/{ru,uz}/game.php`, `game.class.*`); их шлёт `ClassCaravanMessenger`, тот же набор говорит страница «Караваны» в `/org` (но отказы в кабинете — своими текстами `org.caravans.errors.*`, на языке кабинета).
Итоги недели — не отсюда: `PostClassCaravanSummaryJob` (HTML, ровно один раз за караван, замок `game:class-summary:{id}`).

| Когда | Текст (`game.class.*`) |
|---|---|
| Старт | `started`: «Караван класса отправился! Цель недели — N ⭐, караван в пути до D.» + кнопка `open_button` «Открыть караван» на `{twa.base_url}/twa/game/class/{id}` (ссылка Mini App: в группе `web_app`-кнопка невозможна, `MiniAppLinks`) |
| Telegram не принял сообщение о старте | `not_delivered`; караван снимается (`canceled_reason = not_delivered`), повтор команды работает |
| Уже идёт | `already_active` («до D») |
| Отмена | `canceled` — только если отменил этот вызов; итогов не будет |
| Нет идущего | `no_active` |
| Отказ | `not_teacher`, `group_inactive`, `centers_only`; прочее — `failed` |

**Путь.** Сообщение уходит сразу, как у `/start_exam` (`GroupSessionAnnouncer`, `sendWebAppButton(immediate: true)`), а не через очередь исходящих: отказ Telegram должен быть виден вызывающему, иначе караван, которого никто не видит, нельзя снять. Транспортный сбой (таймаут, 5xx) — сообщение могло дойти, караван остаётся. Сообщение «отменён» при отказе Telegram только логируется: отмена остаётся в силе.
**Когда молчим.** `GAME_ACCESS` не `all` — ни ответа, ни запросов к таблицам игры; группа не активна (блокировка группы).

### B.16 Кубок друзей в группе (docs/33 §6; ядро — K4, бот — K6 ✅)

- `callback_data` (`CupCallback`, строгий regex с `\z`): `cup:new`, `cup:pub:{id}`, `cup:cancel:{id}` (id — без ведущих нулей); команда
  `/cup` (и `/start cup` — то, что Telegram присылает после добавления бота по ссылке `?startgroup=cup`) в группе. Обработчики
  `CupCallbackHandler`, `CupCommandHandler` возвращают `requiresActiveGroup() = false` (кубок работает в `pending`-группах). Каждое нажатие
  получает ровно один `answerCallbackQuery`; отказ — короткий тост только нажавшему (не alert), в чат ничего не пишется. Тосты — на языке
  нажавшего (`from.language_code`), сообщения в чате — на языке кубка (`cups.locale`). Простой текст без HTML.
- `cup:new` / `/cup` → `CupLifecycleService::propose($group, from.id, message_thread_id темы форума)`; новый черновик — строка outbox
  `cup_messages card` и `PostCupMessageJob` (картинка рисовальщика v2 + подпись + кнопки «Опубликовать» `cup:pub`, «Настроить» (url
  `/cups/setup/<code>`), «Отменить» `cup:cancel`); в теме форума — текстом (у отправки фото нет темы). Уже есть кубок: `cup:new` — тост
  «В этом чате уже есть кубок», `/cup` — одна строка и кнопка «Открыть кубок» (url `/cups/c/<code>`); если это **черновик** — строка
  «черновик кубка» и кнопка «Настроить» (url `/cups/setup/<code>`): предложение могло не дойти (таймаут первой отправки — `unknown`,
  повторно не шлётся), а вступить в черновик нельзя. `/cup` — не чаще раза в 10 минут на
  чат (`RateLimiter` `cup-chat:<chat_id>`), иначе молчание.
- `cup:pub:{id}` → `publish($cup, from.id)` (админ чата проверяется в ядре свежим `getChatMember`, бот должен быть администратором —
  решение владельца К1), затем предложение правится в карточку (то же сообщение). `cup:cancel:{id}` → `cancelByChatAdmin($cup, from.id)`,
  карточка — «Кубок отменён» без кнопок. Кнопка принимается, только если кубок — этого чата и кнопка стоит на его карточке
  (`message_id = cups.card_message_id`); иначе (чужой чат, старое сообщение, закрытый кубок, неверный формат, ЛС) — один и тот же тост
  «Эта кнопка больше не действует». Не админ — «Это может сделать только администратор чата.».
- `CUP_ACCESS=off` (или группа вне пилота): `/cup` не распознаётся (как неизвестная команда), нажатие `cup:*` только подтверждается без
  текста, в приветствии нет кнопки кубка. Приветствие новой `pending`-группы (`GroupWelcomeMessenger`) при открытом кубке и без кубка в чате
  — та же строка и кнопка «🏆 Создать кубок друзей» (`cup:new`).
- Карточка (`CupCardRenderer`): «🏆 Кубок друзей Muhrla · «группа» · A2», статус (старт / «День N из 7» / завершён / отменён), до 10
  строк таблицы `место. display_name [эмодзи Pro] [(вышел(а))] — очки` (эмодзи — только обычный из набора и только при действующем Pro,
  `CupProEmoji`, один запрос на кубок), «Участников: N · сводка в 20:00»; имя печатается через `DisplayName::forChat()` — всё, из чего
  Telegram сделал бы ссылку (`@`, `/`, `#`, `$` в начале слова, точка домена, `://`), заменено похожим символом (＠ ∕ ＃ ＄, U+2024,
  U+2044), комбинирующих знаков не больше 2 подряд. Лимиты Telegram (подпись 1024, текст 4096) считаются в единицах UTF-16
  (`TelegramText`): подпись, не влезающая в 1024, теряет строки таблицы с конца целиком («…и ещё N»), обрезка по графемам — крайний
  случай; кнопки — url «Участвовать» / «Играть сегодня», «Таблица»,
  «Правила» (после финиша — одна «Итоговая таблица»). Картинка без персональных данных (бренд, «Кубок друзей», уровень, правила) — один
  файл на уровень, размеры и язык.
- Outbox `cup_messages` (`CupOutbox`, `PostCupMessageJob`, docs/33 §6.4): условный UPDATE `pending → sending` (два воркера не публикуют
  одно сообщение дважды); кубок на паузе / выключен флагом / закрыт → `skipped`; нет слота чата (20/мин или пауза этого чата после 429) →
  обратно `pending`, задание откладывается; 429 → `pending` и проброс (`ThrottleTelegramOutbound` откладывает на `retry_after`; 429 группы
  ставит паузу только этого чата); 403 / chat not found → `skipped` + группа `disabled` (`GroupBotRemoval`); иной определённый отказ →
  повтор, после 5 попыток `failed`; таймаут / 5xx → `unknown`, без повтора; переезд в супергруппу клиент проходит сам (повтор в новом чате).
  Строка `final` ставится в очередь сразу после финиша (`CupScheduler` → `CupOutbox::queue()`), `cups:send-outbox` — страховка. Запрос
  перепубликации карточки (возврат бота), пока её строка `sending`, — метка `cups.meta.card_repost`; конец этой отправки без публикации
  (skipped / unknown / failed) возвращает строку в `pending`, после успешной метка просто снимается. `cups.meta.card_kind` пишется
  одним ключом (`jsonb_set` / `json_set`), не из модели, прочитанной без блокировки.
- Правка карточки (`CupCards::refresh`): только при изменении текста (`card_text_hash`), «message is not modified» — успех; карточка
  удалена админом («message to edit not found») — публикуется заново не чаще раза в сутки; чат переехал в супергруппу
  (`card_chat_id ≠ chat_id` или ответ «migrated») — заново сразу; 403 — группа `disabled`.
- Уход из чата (`chat_member` left/kicked/restricted-вне-чата, `left_chat_member`) → после `leave()` всегда
  `CupParticipationService::onLeftChat($learner, $group, $date)`; событие старше последней успешной проверки (`membership_checked_at`
  — участник уже вернулся) ничего не меняет (повтор / опоздавший вебхук); время события помечается (`cup:member-left:v1:*`, 120 с), и
  ответ «член», спрошенный до него, не переиспользуется и не делает участника снова `active`. Возврат в чат участника не активирует —
  это делает следующая свежая проверка.
  Бот снова добавлен в группу с открытым кубком (`my_chat_member` из left/kicked) → строка `card` снова `pending` (новое сообщение), второго
  кубка нет, сроки прежние.
- Расписание (`routes/console.php`, все `withoutOverlapping`): `cups:advance` каждые 5 минут (истечение черновиков, старт / сдвиг старта /
  отмена без игроков, снимки следующего дня, закрытие брошенных попыток, финиш); `cups:post-summaries` каждые 5 минут (сводка дня в
  `summary_minute` по поясу кубка — время сводки проверяется в SQL, так что пачка не забивается кубками, чьё время не наступило; только
если очки таблицы изменились с прошлой сводки и сводки не выключены; в последний день — подвал «Это последний день…» вместо «Раунд нового
дня откроется в полночь»); `cups:refresh-cards` каждые
  5 минут (карточки с новостями, не чаще раза в 5 минут каждая); `cups:send-outbox` каждую минуту (`sending` старше 10 минут → `unknown`,
  забытые `pending` — снова в очередь). Событие `cup.summary_posted {cup_id, day}`.

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
