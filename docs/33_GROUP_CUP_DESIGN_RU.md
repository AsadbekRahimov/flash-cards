# 33. Кубок друзей в Telegram-группе — технический проект

Технический проект к предложению владельца [29_GROUP_CUP_PROPOSAL_RU.md](29_GROUP_CUP_PROPOSAL_RU.md). Дата: 24.11.2026. Автор: architect.
Статус: **проект, код не написан.** Документ для разработчика: что строим, из чего, в каком порядке и как проверяем.

> Общий порядок выпусков и вопросы владельцу — [31_COMPETITION_PLAN_RU.md](31_COMPETITION_PLAN_RU.md). План реализации кубка по задачам — этот документ, §9.
> Ссылку в docs/29 нужно заменить на этот файл.

Очки кубка живут только в таблицах кубка. В недельную лигу, месячный рейтинг и рейтинг за всё время (docs/28, docs/30) они не попадают.
Раунд кубка не пишет `learner_daily_stats`, `game_runs`, `word_repetitions`, монеты и очки лиги (§5.1, вопрос В3).

---

## 0. Что есть в коде сейчас (выжимка исследования)

| Тема | Где | Что важно для кубка |
|---|---|---|
| Группы | `telegram_groups` (`chat_id` UNIQUE, `status` pending/active/disabled, `meta`), `MyChatMemberHandler` | Группа создаётся `pending` при добавлении бота. Бот удалён → `disabled`. **При повторном добавлении статус не меняется** (`disabled` остаётся `disabled`). Нельзя отличить «бот удалён» от «отключено админом платформы». |
| Group Lock | `TelegramDispatcher`, `UpdateHandler::requiresActiveGroup()` | Команды учителя работают только в `active` группах (активирует центр или админ). Дружеские группы останутся `pending`. Значит, обработчики кубка **обязаны** возвращать `requiresActiveGroup() = false` и проверять всё сами. |
| Членство | `group_members`, `GroupMembershipService`, `ChatMemberHandler`, `NewMembersHandler` | Строки появляются только по событиям `new_chat_members` / `chat_member` / `left_chat_member`. Кто был в чате **до** бота, строки не имеет. `chat_member` приходит, только если бот — администратор. Для доступа к кубку `group_members` недостаточно: нужен свежий `getChatMember`. |
| getChatMember | `TelegramClient::getChatMemberStatus()` | Возвращает только строку статуса. Для `restricted` нужен ещё `is_member` (вышедший «заглушённый» участник — `restricted` + `is_member=false`). Нужен новый метод. |
| Кэш статусов | `ChatMemberStatusCache` (`tg:member:v1:*`, 10 мин) | Кэширует **только статус бота**. Статус человека для решений о доступе не кэшируется (docs/19 §3 п. 9). Кубок держится этого же правила (§4.3). |
| Проверка админа бота | `GroupBotAccessChecker::botIsAdmin()` | Готово, переиспользуем. |
| Приветствие | `GroupWelcomeMessenger` | Одно сообщение после добавления бота в `pending`-группу без центра. Текст про центр и номер группы. Кнопку кубка добавляем в это же сообщение. |
| allowed_updates | `IrazasyedTelegramClient::ALLOWED_UPDATES` | `message, my_chat_member, chat_member, callback_query, pre_checkout_query` — кубку хватает, `setWebhook` перезапускать не нужно. |
| Миграция в супергруппу | — | **Не обрабатывается нигде.** `message.migrate_to_chat_id` никто не читает. После миграции `telegram_groups.chat_id` указывает на мёртвый чат. Для кубка это блокер (§4.6). |
| Отправка | `TelegramClient::sendMessage()` → `void`; при исчерпании окна 20/мин на чат **уходит в очередь** (`SendTelegramMessageJob`) | `message_id` отправленного сообщения сейчас **не возвращается**. Для «одной обновляемой карточки» нужен новый метод, который его возвращает (§6.4). Нет `message_thread_id` (темы форумов). |
| Редактирование | `editMessageText`, `editMessageReplyMarkup` | Есть. Ошибка `message is not modified` бросается как `TelegramApiException` — кубку нужно считать её успехом. |
| Лимиты отправки | `TelegramMessageRateLimiter` (20/мин на чат, 25/с на бота), `ThrottleTelegramOutbound` | Переиспользуем. |
| «Ровно один раз» | `PostClassCaravanSummaryJob`, `PostLeaderboardJob` | Образец: маркер в строке + `Cache::lock` + условный UPDATE. |
| Mini App из группы | `MiniAppLinks`, `sendWebAppButton()` | В группах `web_app`-кнопки запрещены. Проект шлёт url-кнопку `https://t.me/<bot>/<app>?startapp=<base64url(путь TWA)>`. Клиент декодирует путь (`resources/twa/src/router/startParam.ts`). Сервер `start_param` **не читает вовсе**. |
| initData | `InitDataValidator`, `AuthController`, `LearnerContextResolver` | Личность — только `user.id` из подписанной initData. `start_param`, `chat_type`, `chat_instance` подписаны, но не сохраняются. JWT v2 несёт только `learner_id`. Учеников без `telegram_user_id` нет (колонка NOT NULL). |
| Баттлы | `BattleService`, `BattleClock`, `BattleReader`, `BattleJanitor`, `battles.open_pair`, `battle_participants.active_learner_id` | Живой матч 1 на 1 из 5 раундов: комната, готовность, опрос раз в 2 с, сдача, отсутствие. Приглашение требует дружбы (`BattleFriends`) и лимита Free (`PracticeLimits::claimBattle`). **В проде выключены (`PRACTICE_BATTLE=false`) и на настоящем Telegram не проверялись** (PROGRESS.md). |
| Игра | `RunSnapshotBuilder::forClassBoss()`, `RunComposer`, `TaskRegistry` / `TaskHandler` (чистые `project/grade/reveal`), `RunState`, `SeededShuffle` | Снимок заданий из списка слов курса на нескольких языках сразу. Проверка ответа — чистая функция от элемента и состояния. Это ядро раунда кубка. `RunService` / `RunFinisher` **не** берём: там жизни, монеты, очки лиги, SRS, «один активный забег». |
| Классный караван | `class_caravans` (`active_group_id` UNIQUE), `ClassCaravanService::closeDue`, `PostClassCaravanSummaryJob` | Образец: «один активный на группу», недельный цикл, итог ровно раз. |
| Дружба и блокировки | `friendships` (одна строка на пару, `blocked` + `requested_by_learner_id` = кто заблокировал), `FriendshipService` | Блокировка в любую сторону запрещает дуэль кубка между этими двумя. |
| Имена | везде `learners.first_name` | Отдельного «публичного имени» и настроек приватности нет. |
| Часы | `LearnerClock` (пояс ученика), `config('leagues.timezone')` = `Asia/Tashkent`, app `UTC` | У кубка свой пояс — у всех участников общие границы дней. |
| Расписание | `routes/console.php` | Все задачи идемпотентны, `withoutOverlapping`. Так же делаем кубок. |

---

## 1. Что переиспользуем, что новое

| Часть | Переиспользуем | Новое |
|---|---|---|
| Группа, бот в группе | `telegram_groups`, `MyChatMemberHandler`, `GroupBotAccessChecker`, `ChatMemberStatusCache` (только статус бота) | Колонки `telegram_groups.bot_status`, `bot_status_at`. `ChatMigrationHandler` (миграция в супергруппу) |
| Членство | `group_members` (как подсказка), `ChatMemberHandler` (хук ухода) | `CupMembershipGate` — свежий `getChatMember` с `is_member`. Новый метод клиента `getChatMember(): ChatMemberInfo` |
| Вход в Mini App | `MiniAppLinks::forPath()`, `startParam.ts`, `InitDataValidator`, JWT | Маршруты TWA `/cups/...`, публичный код кубка в пути |
| Задания раунда | `RunSnapshotBuilder` (новый метод `forCupDay`), `RunComposer`, `TaskRegistry`, `WordTaskHandler`, `RunState`, `SeededShuffle`, компоненты TWA `components/game/ChoiceTask`, `GapTask`, `SpellTask` | Таблицы `cup_days`, `cup_round_attempts`, `cup_round_answers`. `CupRoundService` |
| Подсчёт | — | `cup_day_scores`. Чистый класс `CupScoring` (лучшие 5 дней, ничьи) |
| Дуэли (этап 2) | `BattleService` / `BattleClock` / `BattleReader`, экраны `BattleView` | Таблицы `cup_duels`, `cup_duel_slots`. `CupDuelService`. Вход в `BattleService` для кубка без дружбы и лимита Free |
| Сообщения в группе | `TelegramMessageRateLimiter`, `ThrottleTelegramOutbound`, `LocaleResolver`, шаблон `PostClassCaravanSummaryJob` | Таблица `cup_messages` (исходящие кубка с ключом идемпотентности). `PostCupMessageJob`, `CupCardRenderer`. Метод клиента `sendGroupMessage(): int` |
| Обработчики бота | `TelegramDispatcher`, `CallbackAcknowledger`, `GroupWelcomeMessenger` | `CupCallbackHandler`, `CupCommandHandler` (`/cup`) |
| Аналитика | `ProductEventRecorder`, `ProductEventName` | События `cup.*`, команда `cups:pilot-report` |
| Админка | Filament, `TelegramGroupResource`, `audit_logs` | `CupResource` + участники |
| Флаги | — | `config/cup.php`: `CUP_ACCESS` off/pilot/all, `CUP_DUELS` |

Новый домен: `app/Domain/Cup/` (`Services`, `DTO`, `Enums`, `Exceptions`, `Support`). Модели: `Cup`, `CupParticipant`, `CupDay`, `CupRoundAttempt`, `CupRoundAnswer`, `CupDayScore`, `CupMessage`; на этапе 2 — `CupDuel`, `CupDuelSlot`.
Почему отдельный домен, а не `Game` или `Engagement`: у кубка своя область зачёта (группа + турнир), свои квоты и запрет на утечку очков в лигу. Из `Game` берём только чистые части (снимок, проверка), из `Practice` — движок баттла через узкий вход.

---

## 2. Модель данных

Все миграции — с `down()`. Перечисления и формы — CHECK на PostgreSQL (как `class_caravans`, `battles`). Время хранится в UTC.

### 2.1 `telegram_groups` — две колонки (миграция `add_bot_status_to_telegram_groups_table`)

| Колонка | Тип | Смысл |
|---|---|---|
| `bot_status` | string(16) null | Последний статус бота из `my_chat_member`: `member`, `administrator`, `left`, `kicked` |
| `bot_status_at` | timestamp null | `date` того апдейта. Старый апдейт новый статус не перетирает |

`MyChatMemberHandler` пишет обе колонки. `status` группы (pending/active/disabled) кубок **не читает и не меняет**. Кубок работает в `pending` и `active` группах. Кубок «на паузе», если `bot_status ∈ {left, kicked}`.
Запрет кубков админом платформы — `telegram_groups.meta.cups_blocked = true` (ставит админ в `/admin`, §8).

### 2.2 `cups`

| Колонка | Тип | Смысл |
|---|---|---|
| `id` | bigint PK | |
| `telegram_group_id` | FK → telegram_groups, cascade | Чат турнира |
| `active_group_id` | bigint null **UNIQUE** | `= telegram_group_id`, пока статус `draft/scheduled/running`; NULL после. «Один активный кубок на чат» — факт БД (как `class_caravans.active_group_id`) |
| `public_code` | string(22) **UNIQUE** | 16 байт CSPRNG, base64url. Стоит в ссылках из чата. Не секрет (ссылку видит весь чат), но неугадываем: по нему нельзя перебрать чужие кубки |
| `status` | string(10) | `draft` → `scheduled` → `running` → `finished`; из любого незавершённого → `canceled` |
| `cancel_reason` | string(24) null | `draft_expired`, `chat_admin`, `platform_admin`, `no_players` |
| `course_id` | FK → courses | Набор слов уровня |
| `level` | string(2) | `A1`…`C1` — для текста карточки |
| `timezone` | string(64) | По умолчанию `config('cup.timezone')` = `Asia/Tashkent` |
| `days` / `counted_days` | smallint | 7 / 5. CHECK `counted_days <= days` |
| `round_tasks` / `duel_tasks` | smallint | 10 / 5 — фиксируются при публикации, чтобы смена конфига не меняла идущий кубок |
| `starts_at` / `ends_at` | timestamp null | Начало дня 1 и конец последнего дня (UTC), ставятся при публикации |
| `summaries_enabled` | bool, default true | Админ чата выключает сводки |
| `summary_minute` | smallint, default 1200 | Время сводки: минута от полуночи по поясу кубка (20:00) |
| `locale` | string(5) | Язык сообщений в группе (§6.6) |
| `seed_secret` | string(64) | 32 байта hex, только сервер. Из него выводятся сиды дней и порядок у каждого участника |
| `word_plan` | jsonb | Слова на все дни: `{"1":[id,…], "2":[…]}`. Выбирается при старте (§5.1) |
| `proposed_by_telegram_user_id` | bigint | Кто нажал «Создать кубок» (ученика может ещё не быть) |
| `confirmed_by_telegram_user_id` | bigint null | Админ чата, который опубликовал |
| `confirmed_at` | timestamp null | |
| `card_chat_id` / `card_message_id` / `card_thread_id` | bigint null / bigint null / int null | Где живёт карточка. `card_chat_id ≠ group.chat_id` → чат мигрировал, карточку публикуем заново |
| `card_text_hash` / `card_rendered_at` | string(40) null / timestamp null | sha1 последнего показанного текста карточки и время рендера — правка только при изменении |
| `summary_scores_hash` | string(40) null | sha1 очков на момент последней сводки — «сводка только при новых результатах» |
| `finished_at` / `canceled_at` | timestamp null | |
| `meta` | jsonb default '{}' | |
| timestamps | | |

Индексы: `(status, starts_at)`, `(status, ends_at)` — планировщик; `(telegram_group_id, created_at)` — история чата.
CHECK: статус из списка; `(status IN ('draft','scheduled','running')) = (active_group_id IS NOT NULL)`.

### 2.3 `cup_participants`

| Колонка | Тип | Смысл |
|---|---|---|
| `id` | PK | Наружу (API) уходит только этот id. Ни `learner_id`, ни Telegram id |
| `cup_id` | FK cascade | |
| `learner_id` | FK → learners cascade | |
| `display_name` | string(32) | Имя для турнира. По умолчанию `first_name`. Без управляющих символов, обрезка пробелов, 1–32 символа |
| `status` | string(8) | `active` / `left` (Telegram сообщил о выходе или проверка вернула не-члена) |
| `joined_at` | timestamp | |
| `first_day` | smallint | День, с которого участник играет. 1 — вступил до старта; иначе текущий день на момент вступления |
| `left_at` | timestamp null | |
| `membership_checked_at` | timestamp null | Последний **успешный** свежий `getChatMember` с ответом «в чате» |
| `best_total` | smallint default 0 | Сумма лучших `counted_days` дней (≤ 125) |
| `best_round_points` | smallint default 0 | Баллы раундов в этих же днях — первый тай-брейк |
| `days_played` | smallint default 0 | Для метрик и карточки |
| `final_rank` | smallint null | Место при финише (ничьи делят место) |
| timestamps | | |

UNIQUE `(cup_id, learner_id)`. Индекс `(cup_id, best_total, best_round_points)` — таблица.
`best_*` пересчитываются под блокировкой строки участника при каждом изменении его дневных очков (§5.5). Таблица кубка — простой ORDER BY без агрегатов.

### 2.4 `cup_days`

| Колонка | Тип | Смысл |
|---|---|---|
| `id` | PK | |
| `cup_id` | FK cascade | |
| `day` | smallint | 1…`days` |
| `starts_at` / `ends_at` | timestamp | Границы дня в UTC (полночь по поясу кубка) |
| `snapshot` | jsonb null | Общий снимок 10 заданий дня на **всех** поддерживаемых языках. Только сервер |
| `built_at` | timestamp null | |
| `summary_posted_at` | timestamp null | Маркер «сводка за этот день уже была или пропущена» |
| timestamps | | |

UNIQUE `(cup_id, day)`. Все строки дней создаются в транзакции старта кубка.

### 2.5 `cup_round_attempts` — рейтинговая попытка дня

| Колонка | Тип | Смысл |
|---|---|---|
| `id` | PK | |
| `cup_id`, `cup_day_id`, `participant_id`, `learner_id` | FK cascade | |
| `order` | jsonb | Порядок id элементов снимка дня у этого участника |
| `state` | jsonb | `RunState`-совместимое состояние элементов (вердикт, время ответа) |
| `answered` / `correct` / `points` | smallint | `points` ≤ 2 × `round_tasks` |
| `status` | string(10) | `active` / `finished` |
| `finish_reason` | string(10) null | `completed` / `expired` |
| `locale` | string(5) | Язык участника при старте |
| `started_at` / `expires_at` / `finished_at` | timestamp | `expires_at = started_at + cup.round.minutes` (10 мин) |
| timestamps | | |

**UNIQUE `(cup_day_id, participant_id)`** — «одна рейтинговая попытка раунда на участника в день».
Индекс `(status, expires_at)` — планировщик. CHECK `points BETWEEN 0 AND 20`.

### 2.6 `cup_round_answers`

| Колонка | Тип |
|---|---|
| `id`, `attempt_id` (FK cascade), `learner_id` | |
| `element_id` string(16), `request_id` uuid | |
| `payload` jsonb, `verdict` string(8), `points` smallint (0 или 2) | |
| `result` jsonb — тело ответа для повтора запроса байт в байт | |
| `accepted_at`, `created_at` | |

UNIQUE `(attempt_id, element_id)` — один ответ на задание. UNIQUE `(attempt_id, request_id)` — повтор запроса.

### 2.7 `cup_day_scores`

| Колонка | Тип | Смысл |
|---|---|---|
| `id`, `cup_id`, `participant_id` (FK cascade), `day` | | |
| `round_points` | smallint default 0 | 0…20 |
| `duel_points` | smallint default 0 | 0…5 |
| `updated_at` | timestamp | Для «есть ли новые результаты» |

UNIQUE `(participant_id, day)`. Индекс `(cup_id, updated_at)`. CHECK на диапазоны. Итог дня = `round_points + duel_points` ≤ 25.

### 2.8 `cup_messages` — исходящие сообщения кубка (outbox)

| Колонка | Тип | Смысл |
|---|---|---|
| `id`, `cup_id` | | |
| `dedupe_key` | string(40) | `start`, `card`, `summary:3`, `final`, `duel:{id}` |
| `kind` | string(10) | `card` / `summary` / `final` / `duel` / `notice` |
| `chat_id`, `message_id`, `thread_id` | bigint null | Куда ушло |
| `status` | string(10) | `pending` → `sending` → `sent` / `skipped` / `unknown` / `failed` |
| `attempts` | smallint | |
| `claimed_at`, `sent_at` | timestamp null | |
| `error` | string(255) null | Причина без токена |

UNIQUE `(cup_id, dedupe_key)` — второе «опубликовать итог» не создаёт второй строки.

### 2.9 Этап 2: `cup_duels` и `cup_duel_slots`

`cup_duels`: `id`, `cup_id`, `day`, `challenger_participant_id`, `opponent_participant_id` (null — открытый вызов), `is_public` bool, `rated_offered` bool (что видели оба до принятия), `rated` bool null (что вышло при принятии), `status` (`open`/`accepted`/`declined`/`expired`/`canceled`/`done`/`void`), `open_challenger_id` bigint null UNIQUE (= challenger participant, пока `open`: «один открытый вызов от участника»), `battle_id` FK → battles null UNIQUE, `request_id` uuid (UNIQUE `(challenger_participant_id, request_id)`), `expires_at`, `accepted_at`, `scored_at` (маркер начисления), `challenger_points`, `opponent_points` smallint null, `winner_participant_id` null, timestamps.

`cup_duel_slots`: `id`, `cup_id`, `participant_id`, `day`, `cup_duel_id` FK cascade. **UNIQUE `(participant_id, day)`** — «одна зачётная дуэль на участника в день». При принятии зачётной дуэли вставляются **две** строки (по одной на игрока) в одной транзакции. Нарушение ключа = у кого-то квота уже израсходована.

В `battles` добавляем `cup_duel_id` bigint null (FK → cup_duels, nullOnDelete) — чтобы часы баттла знали, что матч кубковый (§5.7).

### 2.10 Ключи и инварианты — сводка

| Правило | Чем держится |
|---|---|
| Один активный кубок на чат | `cups.active_group_id` UNIQUE + CHECK формы |
| Один участник — одна строка в кубке | `cup_participants (cup_id, learner_id)` UNIQUE |
| Одна рейтинговая попытка в день | `cup_round_attempts (cup_day_id, participant_id)` UNIQUE |
| Один ответ на задание; повтор запроса | `cup_round_answers` два UNIQUE |
| Одна зачётная дуэль в день | `cup_duel_slots (participant_id, day)` UNIQUE |
| Один незавершённый матч на пару / один активный матч на ученика | уже есть: `battles.open_pair`, `battle_participants.active_learner_id` |
| Один открытый вызов от участника | `cup_duels.open_challenger_id` UNIQUE |
| Итог / сводка дня ровно раз | `cup_messages (cup_id, dedupe_key)` UNIQUE + условный UPDATE статуса |
| Очки дня не выше 25, кубка — не выше 125 | CHECK на `cup_day_scores`; `CupScoring` берёт `counted_days` лучших |

### 2.11 Миграции (порядок)

1. `add_bot_status_to_telegram_groups_table`
2. `create_cups_tables` — `cups`, `cup_participants`, `cup_days`, `cup_day_scores`, `cup_messages`
3. `create_cup_rounds_tables` — `cup_round_attempts`, `cup_round_answers`
4. (этап 2) `create_cup_duels_tables` — `cup_duels`, `cup_duel_slots`, `battles.cup_duel_id`

`down()` удаляет в обратном порядке. Четвёртая перед `dropForeign` обнуляет `battles.cup_duel_id` (как `class_caravans` с `game_runs`).

---

## 3. Жизненный цикл

### 3.1 Машина состояний кубка

| Состояние | Событие | Кто | Новое | Побочный эффект |
|---|---|---|---|---|
| — | «Создать кубок» (callback `cup:new` или `/cup`) | любой участник чата | `draft` | Черновик с настройками по умолчанию. Сообщение-предложение с кнопками. Если активный кубок уже есть — ответ-тост «В чате уже идёт кубок» и ссылка на карточку |
| `draft` | «Опубликовать» (callback `cup:pub:{id}` или Mini App) | **админ чата** (свежий `getChatMember`), бот — администратор | `scheduled` | `starts_at` = ближайшая полночь по поясу кубка, до которой ≥ 6 ч (иначе следующая); `ends_at = starts_at + days`. Сообщение-предложение **превращается** в карточку (тот же `message_id`). Открыт набор участников |
| `draft` | 48 ч без публикации | планировщик | `canceled/draft_expired` | Правка сообщения: «Предложение истекло» |
| `draft`, `scheduled`, `running` | «Отменить» | админ чата / админ платформы | `canceled` | Карточка: «Кубок отменён». Итог не публикуется |
| `scheduled` | `now ≥ starts_at` и участников ≥ 2 | планировщик | `running` | Строки `cup_days` 1…7 с границами; `word_plan`; снимок дня 1; правка карточки (без нового сообщения в полночь) |
| `scheduled` | `now ≥ starts_at` и участников < 2 | планировщик | `scheduled` | `starts_at` сдвигается на следующую полночь. Карточка: «Ждём второго участника» |
| `scheduled` | 7 дней без двух участников | планировщик | `canceled/no_players` | Правка карточки |
| `running` | день N кончился | планировщик | `running` | Снимок дня N+1 строится заранее (за 1 ч до конца дня N) |
| `running` | `now ≥ ends_at + settle` (30 мин) | планировщик | `finished` | Добор очков дуэлей, места (`final_rank`), строка `cup_messages` `final`, правка карточки на итог |

Пауза — не состояние, а условие: `group.bot_status ∈ {left, kicked}` **или** `meta.cups_blocked`. Пока условие держится, API отказывает в новых раундах и дуэлях (409 `cup_paused`), сообщения не уходят (строки получают `skipped`). Срок кубка не продлевается.

### 3.2 Кто что делает

**Вебхук** (быстро, без тяжёлой работы):
- `MyChatMemberHandler`: пишет `bot_status`. При добавлении — приветствие с кнопкой «Создать кубок друзей» (если флаг кубка открыт для чата). При повторном добавлении к активному кубку — ставит `card`-сообщение в очередь на перепубликацию (старое могло пропасть).
- `ChatMemberHandler`: после `leave()` — `CupMembershipSync::onLeft(learner, group)` → участник активного кубка этой группы становится `left`. Возврат в чат участника кубка **не** активирует: это делает свежая проверка при следующем действии.
- `ChatMigrationHandler` (новый, первым в списке сообщений): `message.migrate_to_chat_id` / `migrate_from_chat_id` (§4.6).
- `CupCallbackHandler`: `cup:new`, `cup:pub:{id}`, `cup:cancel:{id}`. Всегда `answerCallbackQuery`. Отказы — тостом только нажавшему, в чат ничего не пишем.
- `CupCommandHandler`: `/cup` в группе. Нет кубка → предложение. Есть → короткий ответ со ссылкой на карточку (`reply_to_message_id`). Не чаще раза в 10 мин на чат, иначе молчание.

**Планировщик** (`routes/console.php`, всё идемпотентно, `withoutOverlapping`):

| Команда | Частота | Что делает |
|---|---|---|
| `cups:advance` | каждые 5 мин | Истечение черновиков; старт `scheduled`; сдвиг старта; заранее строит снимок следующего дня; закрывает просроченные попытки (условный UPDATE); финиширует кубки |
| `cups:post-summaries` | каждые 5 мин | Кубки, где наступило `summary_minute` дня и сводка ещё не решена → строка `cup_messages summary:N` (или `summary_posted_at` с пометкой «нет новых результатов») |
| `cups:refresh-cards` | каждые 5 мин | Карточки, у которых изменились очки или состав (`EXISTS` по `cup_day_scores.updated_at` / `cup_participants.updated_at` > времени последнего рендера) → правка, если sha1 текста изменился |
| `cups:send-outbox` | каждую минуту | Переотправка `pending` и зависших `sending` (§6.4) |
| `cups:sweep-duels` (этап 2) | каждую минуту | Истечение открытых вызовов; добор очков завершённых матчей без `scored_at` |

### 3.3 Как надёжно проверить, что нажал админ

1. `callback_query.from.id` заполняет Telegram. Вебхук защищён секретом в заголовке (docs/07 §2). Подделать его нельзя. Анонимный админ, нажимая кнопку, приходит **своим** id (анонимность касается только сообщений).
2. `CupAdminCheck::isChatAdmin(group, telegramUserId)` вызывает `getChatMember(chat, user)` **без кэша**. Админ — только `creator` или `administrator`.
3. В Mini App (настройка и публикация) — то же по `learner.telegram_user_id` из JWT.
4. Ошибка Telegram при проверке → отказ («Не удалось проверить права, попробуйте ещё раз»), не допуск.
5. Публикация требует ещё `GroupBotAccessChecker::botIsAdmin() === true` (вопрос В1). Нет — тост «Сделайте бота администратором чата. Особые права не нужны».

---

## 4. Безопасность и доступ

### 4.1 Что даёт ссылка из чата и чего не даёт

- Кнопки в группе — только url (direct link Mini App), callback и login. `web_app`-кнопки в группах Bot API отклоняет. Это уже учтено в `sendWebAppButton()`.
- Ссылка: `https://t.me/<bot>/<app>?startapp=<base64url("/cups/c/<public_code>")>` (вариант `/play` — сразу к раунду). Схема пути — существующая, клиенту нужен только новый маршрут.
- `start_param` подписан Telegram в initData, но **его содержимое выбирает автор ссылки**. Это подсказка, «какой кубок открыть», и не больше. Код кубка — тоже не пропуск: ссылку можно переслать.
- **Решение о доступе принимается только так:** личность = `user.id` подписанной initData → JWT → `learner.telegram_user_id`. Членство = свежий `getChatMember(cups.telegram_group.chat_id, telegram_user_id)` на сервере. Кубок = строка `cups` в нужном статусе.
- **Не используем** `chat_type` / `chat_instance` из initData. `chat_instance` — непрозрачный id, его нельзя связать с `chat_id`. Что он равен `callback_query.chat_instance` того же чата, в документации прямо не сказано. Объект `chat` в initData есть только при запуске из меню вложений, у url-кнопки в группе его нет. **[проверить по Bot API перед реализацией, если захотим использовать как доп. сигнал; для решения о доступе не нужен]**

**Почему пересланное приглашение не даёт доступа:** получатель откроет Mini App своим аккаунтом. Сервер спросит Telegram, состоит ли **этот** пользователь в чате кубка. Ответ `left` / `kicked` / нет записи → 403 `cup_not_member`. Название группы, состав и таблицу не-член не видит: ответ один и тот же для «кубка нет» и «вы не в чате» (как `not_a_member` в docs/07 §5).

### 4.2 Где проверяется членство

| Действие | Проверка |
|---|---|
| Просмотр приглашения (`GET /cups/by-code/{code}`) | Свежий `getChatMember`. Не член → 403 без подробностей |
| Вступление (`POST /cups/{id}/join`) | Свежий `getChatMember` **внутри** запроса вступления (не доверяем просмотру минуту назад). `membership_checked_at = now` |
| Старт раунда дня | Свежий `getChatMember`. Ответ «не в чате» → участник `left`, 403 `cup_not_member`. Сбой Telegram — §4.4 |
| Ответ на задание в начатой попытке | Не проверяем. Попытка уже начата, срок 10 мин |
| Таблица кубка (`GET /cups/{id}`) | Участник (`cup_participants`) **или** член чата по свежей проверке. Негативный ответ кэшируется 30 с, чтобы не гонять Telegram |
| Вызов / принятие дуэли (этап 2) | Свежая проверка того, кто действует. Второго игрока проверяем по `status = active` и `membership_checked_at` не старше 24 ч |

Свежая проверка ограничена: не чаще одного вызова `getChatMember` на пару (кубок, ученик) за 10 с (Redis-замок `cup:member-check:{cup}:{learner}`). Лишние запросы ждут результата или получают 429.

«Член чата» по `ChatMemberInfo`: `creator`, `administrator`, `member`, или `restricted` с `is_member = true`. Всё остальное — не член.

### 4.3 Кэш членства и docs/19 §3 п. 9

Статус человека для **решения о доступе** не кэшируем. Единственное исключение — сбой Telegram при старте раунда (§4.4). Тогда решение принимается по нашей БД (`membership_checked_at`), а не по кэшу. Это правило вписываем в docs/19 §3 п. 9 как оговорку кубка.

### 4.4 Бот не может проверить членство

| Причина | Что видит ученик | Что видит админ |
|---|---|---|
| Бот не администратор, Telegram отвечает ошибкой вида «member list is inaccessible». `getChatMember` для чужих пользователей гарантирован только админу | 409 `cup_unverifiable {reason: bot_not_admin}`: «Бот не может проверить участников. Попросите администратора чата сделать бота администратором» + «Проверить снова» | До публикации: публикация не проходит. После понижения бота: карточка показывает строку-предупреждение (обновляется при `my_chat_member`) |
| Бот удалён (`bot_status` left/kicked, или Telegram отвечает 403) | 409 `cup_paused {reason: bot_removed}` | Кубок на паузе (§3.1) |
| Сеть / 5xx / таймаут Telegram при **вступлении** | 503 `cup_unverifiable {reason: telegram_unavailable}` + «Повторить» | — |
| То же при **старте раунда** | Пускаем, если участник `active` и `membership_checked_at` не старше 24 ч. Иначе 503 как выше | — |

Пометка: точные тексты ошибок Bot API для «не админ» **[проверить на живом боте]**. Код различает только «403 / bot is not a member» (пауза), «400 user not found» (не член) и «прочее» (`bot_not_admin`, если бот не админ по нашему кэшу; иначе `telegram_unavailable`).

### 4.5 Выход, возврат, удаление и повторное добавление бота

- **Ученик вышел из чата.** Если бот админ — сразу по `chat_member`, иначе при следующей свежей проверке. Участник становится `left`. Новых попыток нет. Очки остаются в таблице с пометкой «вышел(а)». Начатая попытка доигрывается до своего срока.
- **Вернулся в чат.** Свежая проверка при вступлении или старте раунда возвращает `active`. Строка та же, счёт прежний. Пропущенные дни не возвращаются.
- **Бот удалён.** Пауза (§3.1). Начатые попытки доигрываются. Сводки и итог, которые пришлись на паузу, получают `skipped`.
- **Бот добавлен снова.** `MyChatMemberHandler` видит активный кубок группы. Второй кубок не создаётся (UNIQUE `active_group_id`). Срок не меняется. Карточка публикуется заново (строка `cup_messages card` → `pending`, новый `message_id`). Приветствие с кнопкой «Создать кубок» при активном кубке не показывается.
- Если кубок за время паузы закончился, финальный итог уже `skipped`. Таблица остаётся в Mini App. Повторно итог не публикуем.

### 4.6 Миграция группы в супергруппу (смена `chat_id`)

**Сделано в выпуске B (architect, 25.11.2026, вместе с docs/34 B1):** `ChatMigrationHandler`, `GroupChatMigrator`, перенос и повтор отправки в `IrazasyedTelegramClient` (класс `TelegramChatMigratedException` с полем `newChatId`, а не поле в `TelegramApiException`). Поведение — docs/04 B.5 и B.7a. Слияние двух строк сделано только для «пустой» новой строки (участники и события переезжают на старую); «живая» новая строка не сливается, пишется `telegram.group_migration_conflict`. Таблицы кубка (`cups`, `cup_messages`) ссылаются на `telegram_group_id` и при появлении попадут в список «живых» таблиц `GroupChatMigrator::HISTORY_TABLES` — это задача K4. Порядок апдейтов на живом Telegram по-прежнему **[проверить на стенде, B9]**: если старая группа получает `my_chat_member` `left` до `migrate_to_chat_id`, строка станет `disabled` и останется такой после переноса — это закроет K2 (`bot_status`).

Исходное описание:

- В старый чат приходит `message.migrate_to_chat_id`, в новый — `message.migrate_from_chat_id`. Обработать нужно оба: первый пришедший выполняет перенос, второй — no-op.
- Перенос: под `FOR UPDATE` строки `telegram_groups` со старым `chat_id` записать новый `chat_id`.
- **Конфликт:** строка с новым `chat_id` уже может существовать, если `my_chat_member` из новой супергруппы пришёл раньше и `firstOrNew` её создал. Тогда переносим ссылки (`group_members`, `cups`, `teacher_groups`, …) на старую строку и удаляем пустую новую — или наоборот. **[проверить на живом Telegram, какие апдейты и в каком порядке приходят при миграции]**
- Отправка в старый `chat_id` получает 400 с `parameters.migrate_to_chat_id`. `IrazasyedTelegramClient::call()` должен поднимать это в `TelegramApiException` с полем `migrateToChatId`. Отправляющий job запускает тот же перенос и повторяет отправку.
- Кубок ссылается на `telegram_group_id`, поэтому идёт за группой сам. `message_id` в новом чате другие: `card_chat_id ≠ chat_id` → карточка публикуется заново.

### 4.7 Злоупотребления

| Угроза | Что ограничивает |
|---|---|
| Второй аккаунт в чате | Он должен быть настоящим членом чата (видят все). Свои очки он основному не передаёт: в кубке нет переводов очков. |
| Сговор в дуэли (играть со своим вторым аккаунтом или с другом, который «сливает») | Каждый получает очки **только за свои** верные ответы. Соперник ничего не может подарить. Сдача, неявка, слабый соперник дают 0 дополнительных очков. Максимум 5 в день достижим против любого соперника. Сговор даёт только гарантированного партнёра для одной дуэли — это разрешено и так. |
| Подсказки в чате (один сыграл, рассказал ответы) | Порядок заданий и вариантов у каждого свой (§5.2). Срок попытки 10 мин. Сами слова всё равно одинаковые — это дружеский турнир, полностью это не закрыть (риск Р5). |
| Перебор ответов | Один ответ на задание (UNIQUE). Повтор `request_id` возвращает сохранённый вердикт. |
| Вторая попытка через вторую вкладку | UNIQUE попытки дня. Вторая вкладка получает ту же попытку. |
| Спам кнопками в чате | Общий лимит входящих (`bot-in:`), плюс лимит кубка на чат: `cup:new` и `/cup` — раз в 10 мин. Отказ — только тост. |
| Оскорбительное имя для турнира | 32 символа, без управляющих символов. HTML экранируем при отправке. Админ платформы сбрасывает имя (§8). |
| Перебор `public_code` | 128 бит случайности, лимит `twa-cup-read`, одинаковый ответ на «нет кубка» и «не член». |

---

## 5. Игра

### 5.1 Слова и задания дня

- **Курс уровня.** `config('cup.level_courses')` — карта `A1 → slug`, `A2 → slug`, …. Публичный опубликованный курс. Нет в карте → первый `published` + `public` курс уровня по `position`. Выбор уровня в Mini App при публикации, по умолчанию A2.
- **План слов** строится при старте кубка (`scheduled → running`). Это `cup.days × cup.round_tasks` слов курса без повторов. Выборка `SeededShuffle::sample(daySeed, 'cup-words', n, k)` из слов курса, у которых есть перевод на **всех** поддерживаемых языках. Хранится в `cups.word_plan`. Слов не хватает → публикация этого уровня отклоняется ещё при публикации (422 `cup_material_unavailable`).
- **Сид дня** = `HMAC-SHA256("day:{N}", seed_secret)`. Секрет не выводим из `cup_id + day`, иначе задания можно посчитать заранее.
- **Снимок дня** = новый `RunSnapshotBuilder::forCupDay(Course, wordIds, LocaleSet(все поддерживаемые), seed)`. Повторяет `forClassBoss()`: задания из слов «по кругу», без пар, авторских заданий, событий и жизней, ровно `round_tasks` элементов. Для лимитов — `RunLimits::forCup()` (размер 10, без пар/событий/фляг). Снимок пишется в `cup_days.snapshot` один раз. Строит планировщик заранее. Запасной путь — лениво при первом старте раунда: `FOR UPDATE` строки `cup_days`, если `snapshot IS NULL` — построить и сохранить.
- Задание «послушай» требует озвучки. Если у слова нет аудио, `WordTaskHandler` берёт другой тип (как в игре). Для одинаковости у всех это решается один раз при сборке снимка.
- **Одинаковость:** состав слов, типы заданий и правильные ответы — одни на день у всех. Тексты — на языке участника (снимок многоязычный, `TaskHandler::project($element, $locale, …)` уже это умеет).

### 5.2 Попытка дня: старт, возобновление, срок

**Старт** — `CupRoundService::startToday(Learner, Cup, now)`:
1. Кубок `running`, не на паузе. Есть текущий день `d` (`starts_at ≤ now < ends_at`). `d ≥ participant.first_day`.
2. Свежая проверка членства (§4.2).
3. Транзакция: INSERT `cup_round_attempts` с `order` = `SeededShuffle::order(HMAC(daySeed, "order:{participant_id}"), 'cup-order', 10)`, `expires_at = now + 10 мин`.
4. Нарушение UNIQUE → вернуть существующую попытку (двойной тап, вторая вкладка). Если она `finished` → 409 `cup_round_done {points}`.
5. Ответ сразу содержит первое задание.

**Варианты ответов** перемешиваются при проекции: `CupProjection` переставляет массив `options` из `project()` по сиду участника и элемента. ID вариантов стабильны, проверка не меняется. Порядок не хранится — он детерминирован.

**Возобновление.** `GET /cup-rounds/{id}` возвращает ту же попытку, принятые ответы и **исходный** `expires_at`. Перезапуск Mini App ничего не сбрасывает.

**Срок.** Ответ принимается, пока `now ≤ expires_at + 1,5 с` (как `deadline_grace_ms` баттла). Позже — попытка закрывается `finished/expired` в этой же транзакции, 409 `cup_round_expired`, очки за уже принятые ответы остаются. Планировщик закрывает брошенные попытки условным UPDATE. Попытка, начатая в 23:58, доигрывается после полуночи, и очки идут в день старта.

**Проверка и очки.** `TaskRegistry::forElement()->grade()`. 2 очка за вердикт `correct`. 0 за `typo`, `wrong`, `skipped` (как у босса: опечатка — не чистое вспоминание; вопрос В4). Подсказок в кубке нет, поэтому «самостоятельный» = любой ответ. Каждое задание — ровно один ответ.

### 5.3 Ответ — транзакция и блокировки

Порядок блокировок (одинаковый везде, без циклов):
**`cup_round_attempts` (FOR UPDATE) → `cup_participants` (FOR UPDATE) → `cup_day_scores` (upsert).**

1. `FOR UPDATE` попытки. Повтор `(attempt_id, request_id)` → сохранённый `result`.
2. Срок, принадлежность элемента, «уже отвечено» (409 `answer_already_accepted` с первым вердиктом).
3. Проверка, INSERT `cup_round_answers`, UPDATE попытки (`state`, `answered`, `correct`, `points`). Последний ответ → `finished/completed`.
4. Если очки > 0: `FOR UPDATE` участника → **перечитать `cups.status`** (обычным SELECT после блокировки участника; финиш берёт тех же участников, §5.6) → если `running` или день ещё не подведён, `cup_day_scores.round_points += 2` → пересчёт `best_*` (§5.5).
5. Строку `cups` ответ **не трогает**. Иначе все ответы чата встали бы в очередь на одну горячую строку. Признак «есть новые результаты» читается по `cup_day_scores.updated_at` (§3.2).

### 5.4 Поздно вступил, ученик в двух группах

- Вступил в день 3 → `first_day = 3`. Дни 1–2 у него пустые. Итог — лучшие 5 из доступных (из 5 дней — все 5).
- Один ученик в двух кубках: попытки, очки и квоты раздельные (всё ключуется по `participant_id` / `cup_day_id`). Экран старта раунда показывает название группы и «Играю за: …». В TWA нет глобального «текущего кубка» — кубок всегда в адресе.
- Раунд кубка не мешает забегам игры и сессиям практики: свои таблицы, своё «одно активное».

### 5.5 Итог: лучшие дни, потолок, ничьи

Чистый класс `CupScoring` (без БД, полностью покрыт unit-тестами):

```
best(list<DayScore{day, round, duel}>, countedDays = 5): BestResult{total, roundPoints, countedDayNumbers}
```

- Дни сортируются по `round + duel` (убыв.), затем по `round` (убыв.), затем по номеру дня. Берутся первые `countedDays`. Так при равных суммах в зачёт идут дни с большей долей раундов — это сразу максимизирует тай-брейк.
- `total ≤ 25 × countedDays = 125` гарантирован CHECK дня.
- **Место:** по `best_total` (убыв.), затем `best_round_points` (убыв.). Полное равенство — общее место, следующее место пропускается (1, 1, 3).
- Пересчёт `best_*` — при каждом изменении `cup_day_scores` участника, под его блокировкой. Читаются ≤ 7 строк.

### 5.6 Закрытие дня и финиш

- День не «закрывается» отдельной транзакцией. Очки дня пишутся по мере ответов. Сводка читает то, что есть.
- **Финиш** (`cups:advance`, `now ≥ ends_at + 30 мин`): сначала добор очков дуэлей без `scored_at` (этап 2). Потом одна транзакция: `FOR UPDATE` всех участников кубка **по id** → `FOR UPDATE` `cups` → статус `finished`, `active_group_id = NULL`, `final_rank` всем → INSERT `cup_messages final` (ON CONFLICT DO NOTHING) → после коммита job публикации.
- 30 мин запаса покрывают: попытку до 10 мин + дуэль, принятую в 23:59 (приглашение до 5 мин, комната до 2 мин, 5 раундов по 30–60 с с разбором — ~10 мин), и минуту дворника баттлов.
- Писатель, ждавший блокировку участника, после финиша перечитывает `cups.status = finished` и очки не пишет.

### 5.7 Дуэль (этап 2)

**Решение:** дуэль кубка = **существующий живой баттл** (`BattleService`) с тонким слоем кубка. Причины: предложение прямо на него опирается (сдача, реванш, история встреч), готовы экраны и гонки баттла проверены на PostgreSQL. Отвергнуто: асинхронная «заочная» дуэль (оба играют те же 5 заданий в любое время). Она проще и не требует онлайна обоих, но это новый движок, новые экраны и история встреч. Оставляем запасным планом, если пилот покажет, что живые встречи в группе не складываются (вопрос В2).

**Предусловие:** баттлы проверены на настоящих телефонах и включены (`PRACTICE_BATTLE=true`). Сейчас это не так.

**Вызов.** `CupDuelService::challenge(by, cup, target|null, public, mode, requestId)`:
- оба — `active` участники; у адресата нет блокировки в `friendships` ни в одну сторону (иначе 403 `cup_duel_unavailable` без причины);
- `rated_offered` = у вызывающего нет слота сегодня **и** (у адресата нет слота сегодня или вызов открытый). Отдаётся в ответе и показывается адресату до принятия;
- строка `cup_duels` `open`, `expires_at = now + 10 мин`, `open_challenger_id` (второй открытый вызов от того же → 409 с id первого);
- `public = true` → строка `cup_messages duel:{id}` (сообщение в чат с url-кнопкой «Принять вызов»). Иначе — ЛС адресату через `SendBattleDmJob`-подобный job (может не дойти, если адресат не начинал диалог с ботом; тогда только список вызовов в Mini App).

**Принятие.** `CupDuelService::accept(by, duelId, expectRated: bool)` — транзакция:
1. `FOR UPDATE` `cup_duels`. Не `open` или истёк → 409 `cup_duel_closed`. Свой вызов → 409.
2. Пересчитать `rated` по слотам. Если `rated ≠ expectRated` → 409 `cup_duel_rating_changed {rated}`. Клиент показывает «Теперь это товарищеский матч» и повторяет с новым `expectRated`. Так «статус виден обоим до принятия» — гарантия, а не надежда.
3. `rated` → INSERT двух `cup_duel_slots`. Нарушение UNIQUE → то же 409 `cup_duel_rating_changed {rated: false}`.
4. `BattleService::openForCup(challenger, acceptor, mode, course, cupDuelId, now)` — **новый вход**: без дружбы и без `PracticeLimits` для зачётной дуэли; матч сразу в `room` (оба `active_learner_id`, принятие уже произошло). Нарушения `active_learner_id` / `open_pair` → 409 `battle_busy {battle_id}` — это и есть защита от **встречных принятий**: второй матч для той же пары или того же ученика не создаётся. Транзакция кубка откатывается вместе со слотами.
5. `cup_duels.status = accepted`, `battle_id`, `opponent_participant_id`. После коммита — ЛС вызвавшему «Вызов принят, заходите».

Вызвавший должен быть онлайн: комната живёт 120 с. Не пришёл → `closed/room_expired` → слоты освобождаются (ниже).

**Начисление.** Часы баттла при любом терминальном переходе матча с `cup_duel_id` ставят после коммита `ScoreCupDuelJob(cupDuelId)`. Запасной путь — `cups:sweep-duels`.
Job: `FOR UPDATE` `cup_duels` → если `scored_at` не NULL, выход → решение:
- **Слот освобождается**, если ни один игрок не дал ни одного ответа (строк `battle_answers` с вердиктом ≠ `timeout` нет) **и** матч `closed` (declined, room_expired, canceled, voided, both_absent). Это «сбой до первого показанного задания» и «сорванная дуэль». Слоты удаляются, очков нет, `status = void`.
- Иначе очки игрока = число **его** `battle_answers` с вердиктом `correct` (0…5). Сдача соперника, неявка, тайм-ауты соперника дают 0 дополнительных. Победа (`battles.winner_learner_id`) — только в истории встреч.
- Зачётная дуэль: `FOR UPDATE` участников по id → `cup_day_scores.duel_points = X` (присваивание, не прибавление: слот гарантирует одну дуэль в день) → пересчёт `best_*` → `scored_at = now`. День — день принятия.
- Товарищеская: только `scored_at`, очков кубка нет.
- Публичный вызов → правка его сообщения: «Малика 4 : 3 IceBoy». Отказ, истечение, `void` → правка на одну строку «Вызов не состоялся». Новых сообщений нет.

**Товарищеский матч** — тот же путь с `rated = false`. Лимит Free на баттлы к нему применяется как к обычному вызову (вопрос В5).

**Режим дуэли:** выбирает вызывающий из режимов баттла, для которых у курса кубка есть материал (`GET /practice/battles/options` для курса кубка). По умолчанию `pairs`. «Задание» дуэли = раунд баттла, всего 5.

---

## 6. Бот в группе

### 6.1 Сообщения

| Сообщение | Когда | Новое или правка | Кнопки |
|---|---|---|---|
| Приветствие + «Создать кубок друзей» | Бот добавлен в `pending`-группу без центра, флаг кубка открыт | Существующее приветствие, новая строка и кнопка (одно сообщение) | callback `cup:new` |
| Предложение | `cup:new` / `/cup` | новое | callback `cup:pub:{id}` «Опубликовать», url «Настроить» (`/cups/setup/<code>`), callback `cup:cancel:{id}` |
| Карточка кубка | При публикации — **правка предложения**. Дальше — только правки | правка | url «Участвовать / Играть сегодня», url «Таблица», url «Правила» |
| Сводка дня | `summary_minute`, только если `sha1(очки) ≠ summary_scores_hash` и `summaries_enabled` | новое, раз в день максимум | url «Таблица» |
| Открытый вызов (этап 2) | Вызывающий выбрал «в чат» | новое, потом правка итогом | url «Принять вызов» |
| Итог | Финиш | новое, ровно раз | url «Итоговая таблица» |

Старт кубка в полночь **не** шлёт нового сообщения — только правку карточки (правка не будит людей). О старте сообщает сама карточка при публикации: «Старт завтра в 00:00».

Пример карточки: «🏆 Кубок друзей · English Friends · A2 / День 3 из 7 · финиш 1 декабря, 00:00 / 1. Малика — 58 / 2. IceBoy — 54 / 3. Умар — 47 / Участников: 6 · сводка в 20:00». Только `display_name`, экранированные для HTML.

### 6.2 Типы кнопок и ограничения Telegram

- `web_app` в группах — нельзя. Вход в Mini App — url-кнопка с direct link (`startapp`). Для direct link нужен Mini App, зарегистрированный в BotFather (`TELEGRAM_MINI_APP_SHORT_NAME`), или main Mini App. **[проверить на проде, что `mini_app_short_name` задан]**
- `callback_data` ≤ 64 байт: `cup:pub:{id}` укладывается.
- `answerCallbackQuery` — текст до 200 символов. Правила целиком туда не влезут, поэтому «Правила» — url в Mini App.
- Отказы на кнопки — тостом только нажавшему (`show_alert`). В чат ничего не пишем.
- Добавление бота сразу админом: ссылка `https://t.me/<bot>?startgroup=cup&admin=pin_messages` из Mini App («Добавить бота в свою группу»). Telegram предложит выдать права. После добавления придёт `/start cup` в группе → сразу предложение кубка. **[проверить параметр `admin=` и приход `/start@bot cup` на живом клиенте]**
- Группы-форумы: сообщение нужно слать в тему (`message_thread_id`). Берём тему, из которой пришли `cup:new` / `/cup`, и храним в `cups.card_thread_id`. В новом методе клиента параметр нужен.

### 6.3 Антиспам и лимиты Telegram

- Telegram: ~20 сообщений в минуту в одну группу, ~30 в секунду на бота, 429 с `retry_after`. Правки, вероятно, считаются в тот же лимит. **[точные лимиты на правки не документированы — считаем их как сообщения]**
- Все отправки и правки кубка идут через `TelegramMessageRateLimiter::reserve(chat)` и `ThrottleTelegramOutbound`.
- Бюджет кубка на чат в сутки: 1 сводка, правки карточки не чаще раза в 5 мин (только при изменении текста), открытых вызовов ≤ 10 на чат и ≤ 3 на участника. Сверх — вызов только личный.
- `message is not modified` = успех. «message to edit not found» (админ удалил карточку) → публикуем карточку заново, не чаще раза в сутки.

### 6.4 Идемпотентность отправок (`cup_messages`)

Нужен новый метод клиента: `sendGroupMessage(chatId, text, parseMode, replyMarkup, ?threadId, disableNotification): int` — **сразу** шлёт и возвращает `message_id`. В очередь сам не откладывает (это делает job).

`PostCupMessageJob(cupMessageId)` (`ThrottleTelegramOutbound`, `tries = 0`, `maxExceptions = 3`, `retryUntil` +6 ч):
1. Условный UPDATE `pending → sending`, `claimed_at = now`. 0 строк → выход (уже отправляет другой или отправлено).
2. Пауза кубка / группы → `skipped`.
3. Лимит чата → вернуть `pending`, `release(retryAfter)`.
4. Отправка. Успех → `sent`, `message_id`. 429 → `pending` + release. 403 / бот удалён → `skipped`. Миграция чата → перенос (§4.6) и повтор.
5. Таймаут без ответа → `unknown`, **без повторной отправки**. Лучше пропустить сводку, чем отправить её дважды. Карточка всё равно покажет итог правкой.

`sending` старше 10 мин (job умер) → `cups:send-outbox` переводит в `unknown`. Не в `pending` — по той же причине.
Повторная постановка итога (два планировщика, ретрай, ручной запуск) упирается в UNIQUE `(cup_id, 'final')` и в шаг 1. Это и есть тест «повторная отправка финальной сводки».

### 6.5 Отключение сводок

Админ чата в Mini App (`/cups/{id}/settings`, свежая проверка админа при сохранении) выключает сводки или меняет их время. Карточка и итог остаются. Отдельной callback-кнопки «Настройки» в чате нет, чтобы не загромождать карточку.

### 6.6 Язык сообщений в группе

`cups.locale` выбирается при публикации. По умолчанию — `telegram_groups.meta.locale`, если задан. Иначе — язык опубликовавшего админа (`LocaleResolver::fromLanguageCode(from.language_code)`). Всё групповое (карточка, сводки, итог, вызовы, тосты в чате) идёт на `cups.locale`. Тосты на кнопки — на языке нажавшего (`from.language_code`). Mini App — на языке ученика, как везде. Строки — `lang/{ru,uz}/cup.php`. Узбекские строки — в `docs/I18N_UZ_REVIEW.md` на проверку носителем.

---

## 7. Mini App

### 7.1 Экраны и маршруты TWA

| Маршрут | Экран | Содержание |
|---|---|---|
| `/cups/c/:code` | Вход по ссылке из чата | Сервер решает: не член → «Кубок доступен только участникам чата». Член, не участник → **Вступление**. Участник → **Кубок** |
| `/cups/c/:code/play` | То же, но участник сразу попадает к раунду дня | |
| (Вступление) | | Название группы, уровень, даты, границы дней («день — с 00:00 до 24:00 по Ташкенту»), время сводки, правила очков, поле «Имя в турнире» (по умолчанию имя из Telegram), предупреждение «Имя увидят в чате». Кнопка «Участвовать» |
| `/cups/:id` | Кубок | Таблица (место, имя, очки, пометка «вышел(а)»), мой статус дня (раунд: не сыгран / идёт до HH:MM / сыграно N), дни 1…7 с моими очками и отметкой зачётных, кнопки «Раунд дня», «Вызвать на дуэль» (этап 2), «Правила» |
| `/cups/:id/round` | Раунд | Шапка «Играю за: English Friends · День 3». Перед стартом — подтверждение «Одна попытка, 10 заданий, 10 минут». Дальше задания через `ChoiceTask` / `GapTask` / `SpellTask`, таймер по серверному `expires_at` (`useServerClock`), итог раунда с разбором |
| `/cups/:id/duel` (этап 2) | Вызов | Выбор соперника или «открытый», «опубликовать в чате», бейдж «Зачётная / Товарищеская» до отправки и до принятия |
| `/cups/:id/duels/:duelId` (этап 2) | Вызов | Принять / отклонить; после принятия — переход в существующий `/battles/:id` |
| `/cups/setup/:code` | Настройка (админ чата) | Уровень, язык сообщений, время сводки, «Опубликовать» |
| `/cups/:id/settings` | Настройки (админ чата) | Сводки вкл/выкл, время, «Отменить кубок» |
| `/cups` | Мои кубки | Активные и последние 10 завершённых |

Навигация: на «Сегодня» — карточка «Кубок «English Friends»: раунд дня не сыгран» (одна, самая срочная; блок `today.cups`, без лишних запросов — один индексный запрос). Список `/cups` — из «Профиля» (строка «Кубки друзей»). В нижнее меню не добавляем.

### 7.2 API (`/api/twa`, все за `TwaAuth`, ответы — Resource-классы)

| Метод и путь | Что | Ответ / ошибки |
|---|---|---|
| `GET /cups/by-code/{code}` | Превью по ссылке | `CupInviteResource` {id, group_title, status, level, days, starts_at, ends_at, timezone, summary_time, participants_count, rules, me: {joined, participant_id, display_name}}; 403 `cup_not_member` (и для «нет кубка»); 409 `cup_unverifiable`; 410 `cup_closed` |
| `POST /cups/{id}/join` | Вступить `{display_name}` | 201 `CupParticipantResource`; 200 при повторе; 403, 409 `cup_unverifiable`, 410, 422 `cup_display_name_invalid` |
| `PATCH /cups/{id}/me` | Сменить имя | Не чаще 3 раз за кубок |
| `GET /cups` | Мои кубки | `CupSummaryResource[]` |
| `GET /cups/{id}` | Таблица и мой статус | `CupResource` {cup, standings: [{participant_id, rank, display_name, total, left}], me: {participant_id, today: {state, attempt_id, points, expires_at}, days: [...]}, can_manage: bool} |
| `GET /cups/{id}/rounds/today` | Состояние раунда дня | {state: `not_started` / `active` / `done` / `not_available`, …} |
| `POST /cups/{id}/rounds/today` | Начать или вернуть начатую | 201 / 200 `CupRoundResource` (с первым заданием); 409 `cup_round_done`, `cup_not_started`, `cup_paused`; 403; 503 `cup_unverifiable` |
| `GET /cup-rounds/{attempt}` | Возобновить | `CupRoundResource`; чужая → 404 `cup_round_not_found` |
| `POST /cup-rounds/{attempt}/answer` | `{request_id, element_id, answer}` | `CupRoundAnswerResource` {verdict, points, review, next, finished, summary}; 409 `answer_already_accepted` / `cup_round_expired`; 422 форма |
| `GET /cups/setup/{code}` · `POST /cups/setup/{code}/publish` | Настройка и публикация черновика | 403 `cup_not_chat_admin`; 409 `cup_bot_not_admin`, `cup_already_active`; 422 `cup_material_unavailable` |
| `PATCH /cups/{id}/settings` · `POST /cups/{id}/cancel` | Админ чата | 403 `cup_not_chat_admin` |
| Этап 2: `POST /cups/{id}/duels`, `GET /cups/{id}/duels`, `POST /cups/{id}/duels/{duel}/accept {expect_rated}`, `/decline`, `/cancel` | Дуэли | 409 `cup_duel_rating_changed {rated}`, `cup_duel_closed`, `battle_busy {battle_id}`; 403 `cup_duel_unavailable` |

Правила проекции: в ответах раунда нет ключей снимка, по которым видно правильный ответ. Это тот же список, что в контрактных тестах игры (`correct_option_id`, `expected`, `accepted`, `word_id`, …). Отдельный `CupContractTest`.

Лимиты (docs/07 §6): `twa-cup-read` 30/мин (by-code, show — в них вызов Telegram), `twa-cup-join` 10/мин, `twa-cup-start` 10/мин, `twa-cup-answer` 90/мин, `twa-cup-admin` 10/мин; плюс общий `twa-api`.

---

## 8. `/admin` и метрики

**`CupResource`** (Filament, по docs/20 §1, тест бюджета запросов на 50 строк):
- Колонки: группа (`telegramGroup.title`), статус, уровень, день X из 7 (считается из `starts_at`, без запросов), участники (`withCount`), сыграли раунд хоть раз (`withCount` с условием), старт, финиш.
- Фильтры: статус, «на паузе».
- Действия: «Отменить кубок» (причина обязательна, `cancel_reason = platform_admin`, `audit_logs cup.canceled`).
- Страница кубка: участники (имя, статус, `best_total`, дней сыграно). Действие «Сбросить имя» → `first_name`, `audit_logs cup.participant_renamed`.
- На `TelegramGroupResource` — действие «Запретить кубки в группе» (`meta.cups_blocked`, `audit_logs`). Активный кубок встаёт на паузу.
- Пилот: переключатель «Кубок: пилот» на группе (`meta.cup_pilot = true`) — при `CUP_ACCESS=pilot`.

**Жалобы.** Кнопки «Пожаловаться» в первом выпуске нет. В правилах — контакт поддержки (`BotSupportContact`). Админ отменяет кубок или сбрасывает имя. Если на пилоте появятся жалобы — отдельная задача.

**События** (`ProductEventName`): `cup.proposed`, `cup.published`, `cup.started`, `cup.invite_viewed`, `cup.joined`, `cup.round_started`, `cup.round_finished {points, reason}`, `cup.summary_posted`, `cup.finished {participants}`, `cup.canceled {reason}`, этап 2: `cup.duel_challenged/accepted/scored/void`.

**Метрики пилота** — `php artisan cups:pilot-report --since=` (и виджет в `/admin` позже):
1. **Сыграли первый раунд:** из вступивших (`cup.joined`) — доля с хотя бы одной `cup_round_attempts`. Отдельно — из открывших приглашение (`cup.invite_viewed`, уникальные ученики) — доля вступивших.
2. **Вернулись на 3-й день:** из участников, сыгравших в день 1 или 2, — доля сыгравших в день ≥ 3.
3. **Второй кубок:** из групп с завершённым кубком — доля, где опубликован следующий кубок в течение 14 дней после финиша.

---

## 9. Этапы реализации

### 9.1 Порядок и флаги

- `config/cup.php`: `access` (`CUP_ACCESS`: off / pilot / all; pilot = группы с `meta.cup_pilot`), `duels` (`CUP_DUELS`, false), `timezone`, `level_courses`, `days` 7, `counted_days` 5, `round.tasks` 10, `round.minutes` 10, `duel.tasks` 5, `settle_minutes` 30, `draft_ttl_hours` 48.
- Выкат: всё на проде с `CUP_ACCESS=off` → `pilot` на 3–5 знакомых группах (включая группу из двух друзей Free + Pro) → смотрим метрики неделю → `all`.
- **Первый выпуск (этап 1):** кубок, вступление, ежедневный раунд, таблица, карточка, сводки, итог, админка. **Без дуэлей.** Итог = лучшие 5 дней из 7, максимум 20 в день (100 за кубок), пока дуэлей нет. Правила в карточке говорят об этом честно.
- **Этап 2:** дуэли, после проверки баттлов на настоящих телефонах и включения `PRACTICE_BATTLE`.

### 9.2 Задачи

| № | Задача | Кто | Зависит от | Definition of Done и ключевые тесты |
|---|---|---|---|---|
| K0 | Правки docs (§10.3): 03, 04, 05, 07, 19, 29 | координатор | — | Документы обновлены до кода |
| K1 | Telegram-основа: `ChatMemberInfo` + `getChatMember()` (status, is_member); `sendGroupMessage(): int` с `threadId` / `disableNotification`; `TelegramApiException` несёт `retryAfter`, `migrateToChatId`, признак «not modified»; фейковый клиент тестов | coder | — | Unit-тесты клиента на разборе ответов SDK; фейк возвращает `message_id` |
| K2 | `telegram_groups.bot_status` + запись в `MyChatMemberHandler` (по `date`, старое не перетирает) | coder | — | Тесты: добавление, удаление, повышение до админа, апдейты не по порядку |
| K3 | Миграция группы в супергруппу: `ChatMigrationHandler`, слияние строк, перенос по ошибке отправки | **architect** | K1 | Тесты: оба апдейта в любом порядке; уже существующая строка нового чата; повтор апдейта; ссылки `group_members` / `teacher_groups` / `cups` после слияния |
| K4 | Схема кубка (миграции 1–3), модели, enums, фабрики; `CupLifecycleService` (draft, publish, start, сдвиг старта, cancel, finish), `CupScoring`, `CupMembershipGate`, `CupAdminCheck`, `cups:advance` | **architect** | K1, K2 | Миграции up/down на pgsql. `CupScoring`: лучшие 5, потолок, тай-брейк, ничьи 1-1-3, поздний вход. Гонки: два `cup:new` разом → один черновик; публикация и отмена разом; финиш и ответ разом. Пояс: границы дней в UTC. Членство: член / вышел / restricted без is_member / бот не админ / сеть — для каждого действия из §4.2 |
| K5 | `CupRoundService`: `RunSnapshotBuilder::forCupDay`, `RunLimits::forCup`, план слов, снимок дня (заранее и лениво под блокировкой), старт / возобновление / ответ / истечение, `CupProjection` | **architect** | K4 | Тесты: одинаковый состав у двух участников при разном порядке; два старта разом → одна попытка; повтор `request_id`; второй ответ на задание → 409; ответ после срока + grace → expired, прежние очки остаются; попытка 23:58 → очки в день старта; обрыв → тот же срок; 20 очков максимум; нет утечки ответа в проекции; очки не попадают в `learner_daily_stats` / лигу |
| K6 | Бот: приветствие с кнопкой, `CupCallbackHandler`, `CupCommandHandler`, `CupCardRenderer`, outbox `cup_messages` + `PostCupMessageJob`, `cups:post-summaries`, `cups:refresh-cards`, `cups:send-outbox`, хуки ухода в `ChatMemberHandler`, перепубликация карточки при возврате бота | coder | K4 | Тесты: нажал не админ → тост, без сообщения в чат; админ → карточка вместо предложения; сводка только при новых очках и не при выключенных сводках; **повторная постановка итога → одна отправка**; таймаут → `unknown` без повтора; бот удалён → `skipped`; `not modified` = успех; лимит чата → отложено; язык группы |
| K7 | HTTP API: контроллеры, FormRequest, Resource-классы, лимитеры, политики | coder | K4, K5 | `CupContractTest` (точная структура), `CupHttpAccessTest` (403 не-члену, одинаковый ответ на «нет кубка», чужая попытка 404), **пересланное приглашение**, выход и возврат, ученик в двух группах, лимитеры |
| K8 | TWA: экраны §7.1 (без дуэлей), карточка на «Сегодня», строка в «Профиле», i18n ru/uz | coder | K7 | Vitest на экраны и маршруты `startParam`; ручной прогон на стенде в настоящем Telegram (iOS, Android, Desktop): ссылка из группы, вступление, раунд, обрыв и возобновление |
| K9 | `/admin`: `CupResource`, действия, запрет кубков, пилот-флаг; `cups:pilot-report` | coder | K4 | Тест бюджета запросов 50 строк; `audit_logs` на каждое действие |
| K10 | Ревью этапа 1 | reviewer | K1–K9 | Блокирующих нет; pint, phpstan 8, весь pest зелёные; PROGRESS.md |
| D1 | Дуэли: миграция 4, `CupDuelService`, `BattleService::openForCup`, хук часов баттла → `ScoreCupDuelJob`, `cups:sweep-duels` | **architect** | K10, баттлы проверены | Тесты: **встречные принятия → один матч**; статус «зачётная» поменялся до принятия → 409 `cup_duel_rating_changed`; комната истекла → слоты свободны; сдача → очки только за свои ответы; повтор начисления не меняет итог; блокировка → нет вызова; Free и Pro одинаково инициируют зачётную; дуэль после полуночи → день принятия |
| D2 | Дуэли: API, экраны, публичный вызов и его правка итогом | coder | D1 | Контракт, отказ и истечение — правка без новых сообщений |
| D3 | Ревью этапа 2 | reviewer | D2 | |

### 9.3 Сценарии из «Спорных случаев» — где проверяются

| Сценарий | Тест |
|---|---|
| Два друга разных тарифов | K5, K7: Free и Pro получают одинаковые раунды; лимиты практики не тронуты; D1: оба инициируют зачётную дуэль |
| Один ученик в двух группах | K7: раздельные попытки, очки, «Играю за» |
| Ничья | K4: `CupScoring` 1-1-3 и тай-брейк по раундам; K6: текст итога с общим местом |
| Сорванная дуэль | D1: `room_expired` / `voided` до первого ответа → слоты освобождены |
| Пересланное приглашение | K7: чужой член Telegram открывает ссылку → 403 без названия группы |
| Выход и возврат | K4/K7: `left` → нет новых попыток; возврат → `active`, прежний `best_total` |
| Повторная финальная сводка | K6: два job'а и ручной перезапуск → одна отправка |
| Обрыв связи | K5: та же попытка и срок |
| Бот удалён и добавлен снова | K6: пауза, `skipped`, нет второго кубка, срок прежний, карточка заново |

---

## 10. Открытые вопросы, правки docs, риски

### 10.1 Блокирующие вопросы владельцу (с рекомендацией)

| № | Вопрос | Рекомендация |
|---|---|---|
| В1 | Обязательно ли боту быть администратором чата, чтобы опубликовать кубок? Без этого Telegram не гарантирует ответ `getChatMember` о других людях и не шлёт уходы из чата. | **Да, обязательно.** Права не нужны (достаточно `pin_messages` из ссылки добавления). Иначе проверка членства ненадёжна, а это ядро защиты от пересланных приглашений. |
| В2 | Дуэль — живой баттл (оба онлайн одновременно) или заочная (те же 5 заданий в течение дня)? | **Живой баттл, вторым выпуском**, после проверки баттлов на телефонах. Первый выпуск — без дуэлей. Если на пилоте живые встречи не складываются — заочная дуэль как замена. |
| В3 | Считается ли раунд кубка учебной активностью: цель дня, серия, SRS, очки лиги? | **Нет, в первом выпуске ничего не пишем**: очки кубка не идут в лигу (docs/29), а запись в цель/серию требует согласования с docs/28. Можно добавить позже одним хуком после коммита. |
| В4 | Опечатка в написании — 2 очка или 0? | **0** — как у босса игры («чистое вспоминание»). Правило видно в правилах кубка. |
| В5 | Товарищеский матч в кубке: тратит ли он дневной лимит баттлов Free? | **Да, как обычный вызов на баттл.** Бесплатна только зачётная квота кубка — так в предложении. Дружба для него не нужна (достаточно общего кубка). |

Неблокирующие решения, принятые в документе (меняются правкой конфига или текста): пояс кубка — Ташкент; запас перед финишем — 30 мин; срок попытки — 10 мин; старт — ближайшая полночь, до которой не меньше 6 ч; вышедший участник остаётся в таблице с пометкой; кубки разрешены и в группах учебных центров.

### 10.2 Где предложение невыполнимо как написано — и замена

- «Кнопка у публикации в чате открывает Mini App» — только url-кнопкой direct link (`web_app` в группах запрещён). Работает, но Telegram может показать подтверждение «Открыть приложение?».
- «Бот один раз предлагает создать кубок после добавления» — да, но потом нужен способ вернуться к предложению. Добавлена команда `/cup`.
- «Старт в начале первого полного дня» + «достаточно двух участников» — если к полуночи вступил один, старт сдвигается на сутки (§3.1). Иначе кубок из одного человека.
- «Сервер проверяет принадлежность к группе» — надёжно только при боте-администраторе (В1).
- «Итог появляется обновлением сообщения вызова» — только для публичного вызова. Личный вызов идёт в ЛС, а ЛС не дойдёт до того, кто не начинал диалог с ботом. Тогда вызов виден только в Mini App.
- «Ровно один раз» для итога в Telegram недостижимо при обрыве связи после отправки. Выбрано «не больше одного раза» плюс итог в карточке (§6.4).

### 10.3 Какие docs обновить до кода (K0)

- docs/03 — таблицы §2 и колонки `telegram_groups`.
- docs/04 — API §7.2 (новый раздел A.x «Кубок»), команды и callback-кнопки бота.
- docs/05 — экраны §7.1, карточка на «Сегодня».
- docs/07 — §5 строки «Кубок», «Попытка раунда кубка», «Дуэль кубка»; §6 лимиты; §10 PII (имя в турнире публикуется в чате, каскадное удаление участника).
- docs/19 §3 п. 9 — оговорка про `membership_checked_at` при сбое Telegram (§4.3).
- docs/29 — ссылку на план заменить на этот файл.

### 10.4 Риски

| № | Риск | Что делаем |
|---|---|---|
| Р1 | **Telegram и членство.** Бот не админ / понижен → проверки падают. Анонимные админы, скрытые участники, темы форумов | В1; предупреждение в карточке; `ChatMemberInfo` с `is_member`; `thread_id`; проверка на живом боте до K7 |
| Р2 | **Миграция в супергруппу** — сейчас ломает все групповые функции, не только кубок. Порядок апдейтов не проверен | K3 до выката; тест на живом Telegram |
| Р3 | **Лимиты Telegram на группы и правки** — не до конца документированы, 429 при всплеске | Правки не чаще раза в 5 мин и только при новом тексте; outbox с `retry_after`; бюджет сообщений на чат |
| Р4 | **Баттлы не проверены в бою** (выключены в проде) — этап 2 зависит от них | Дуэли — второй выпуск, только после e2e на телефонах |
| Р5 | Обмен ответами в чате (одинаковые слова у всех) | Свой порядок заданий и вариантов, 10 мин на попытку; принимаем остаток риска для дружеского формата |
| Р6 | Direct link без настроенного `mini_app_short_name` откроет не тот Mini App или упадёт на `MiniAppLinks::forPath() === null` | Проверка конфигурации в `/health/ready` при `CUP_ACCESS ≠ off` |
| Р7 | Полуночные гонки: попытка и дуэль через полночь, финиш | Очки — в день старта или принятия; запас 30 мин; перечитывание статуса под блокировкой участника (§5.3, §5.6) |
| Р8 | Нехватка слов уровня с переводом на все языки | Проверка при публикации (422), карта `level_courses` в конфиге |
| Р9 | Нагрузка на Telegram от `getChatMember` (каждое открытие и старт) | Замок на пару (кубок, ученик) 10 с; кэш отказа 30 с; лимитеры `twa-cup-*` |
