# 13. Фаза 2 — оплата, тарифы центров, рост и удержание, обучение

> Статус: проект, ред. 1 — 2026-10-19. Автор: architect (задача 2.0, Sprint 15). База: ветка `claude/magical-pascal-qz05gz` после мержа Фаз 0+1 (Pest 1495/1502, Larastan 8, PG 16 в CI).
> Документ **уточняет и в части Фазы 2 заменяет** [11_SYSTEM_ANALYSIS_AND_SAAS_STRATEGY.md](./11_SYSTEM_ANALYSIS_AND_SAAS_STRATEGY.md) §4.1–4.3, §6.3–6.6, §10 «Фаза 2» и реализует контракт оплаты из [12_PHASE1_DESIGN.md](./12_PHASE1_DESIGN.md) §6. Формат — как в docs/12: резюме решений, схема, контракты сервисов, порядок блокировок, нарезка на задачи с DoD, вопросы владельцу.

**Решения владельца, зафиксированные в проекте:** B2C-first, платные тарифы для всех; центр платит фиксированно раз в месяц, его ученики получают полный Pro без лимитов; интерфейс uz (латиница)/ru, изучаемый язык — только английский; Free = 20 новых слов/день, повторения без лимита; **прода с данными нет** — create-миграции правятся на месте + `migrate:fresh --seed`, новые таблицы — новыми миграциями с `down()`; цены ещё не назначены — всё в `config/billing.php`, UZS и Stars раздельно; новые зависимости — только по согласованию (§12).

Порядок фазы (координатор): **волна A — деньги** (2A оплата, 2B тарифы центров) проектируется до нарезки на coder; **волна B — рост и удержание** (2C) и **волна C — обучение** (2D) — схема и границы, задачи крупнее. Что из B/C зависит от A — отмечено явно (§11).

---

## 0. Резюме решений

| Вопрос | Решение | Почему |
|---|---|---|
| Модель оплаты | Два режима в одной `subscriptions`: **автопродление** (только Telegram Stars, `subscription_period` 30 дней, grace 3 дня) и **оплата периода** (Payme/Click: месяц/год, без grace, напоминание о продлении за 3 и 1 день). Оплата при живой подписке **продлевает** её от `max(конец, now)` | У Payme/Click нет рекуррентных списаний для мерчанта; grace имеет смысл только там, где продление делает провайдер |
| Таблицы оплаты | `payment_orders` (заказ = серверный источник суммы), `provider_transactions` (состояние транзакции провайдера: Payme state 1/2/-1/-2, Click prepare/complete), `payments` (леджер: charge/renewal/refund, unique `(provider, provider_event_id)`) | Клиент сумму не задаёт; Payme требует `CheckTransaction`/`GetStatement` по своей транзакции в любой момент; леджер отделён от состояния транзакции |
| Идемпотентность | Быстрый путь — `payments` по `(provider, provider_event_id)` без блокировки; медленный — транзакция с блокировкой `payment_orders` → … → INSERT `payments` последним; `UniqueConstraintViolationException` = повтор | Повтор и параллельная доставка вебхука безопасны; порядок совместим с docs/06 §4.2 и docs/12 §3.4 |
| Порядок блокировок | `promo_codes` → `payment_orders` → `provider_transactions` → субъект (`learners`/`organizations`) → `organization_members` → `subscriptions` → INSERT'ы с FK | Расширение правила docs/12 §3.4; никто, держа субъект, не ждёт заказ |
| `canceled` | Становится **live до `current_period_ends_at`** (без grace) одним согласованным изменением по чек-листу docs/12 §5.1 — задача 2A.1 | Отмена автопродления Stars = «Pro до конца периода» |
| `hasGrace()` | Только `telegram_stars` | Payme/Click — оплата периода, продления нет, grace нечего ждать |
| `pre_checkout_query` | Отвечается **синхронно** в `WebhookController` (чтение заказа + learner, без записи), остальные апдейты — в очередь как сейчас | Telegram ждёт ответ 10 с; очередь `high` этого не гарантирует |
| Тарифы центров | `subscriptions.plan` у организации остаётся `pro` (что получают ученики), тариф — новая колонка `subscriptions.tier` (`tutor`/`center_s`/`center_m`/`center_l`), `seats_limit` — из `config('billing.org_tiers')` | `EntitlementResolver`, `Plan` и Filament-гранты не меняются; `seats_limit` уже есть |
| Место (seat) | Явная запись `organization_members` со `seat_state = seated`; занимается при вступлении (инвайт-код или вступление в группу организации) под блокировкой строки `organizations`; освобождается при выходе/удалении; сверх лимита — `waitlisted` (без спонсорского Pro, FIFO при освобождении) | Детерминированный набор «кто занимает места», лимит защищает B2C, учеников не блокируют |
| Спонсорство | `EntitlementResolver` переходит с «активное членство в группе организации» на `organization_members (active, seated)`; членство из группы создаётся автоматически | Одно правило для мест и для Pro |
| Кабинет центра | **Отдельная Filament-панель `/org`** с tenancy по `Organization` (`organization_users`: `owner`/`teacher`), политики — второй рубеж; TWA-кабинет отклонён | Таблицы/формы/экспорт бесплатно, логин/2FA есть; изоляция tenant'а — встроенный механизм + тесты |
| Оплата центра | Payme/Click по ссылке из кабинета + ручная активация суперадмином с `tier` (счёт юрлицу — HTML-страница); Stars для организаций не предлагаем | Юрлица платят с расчётного счёта; Stars — личный кошелёк |
| B2B → B2C | `offers` (`post_org_discount`, −30 % на первый месяц, 14 дней): создаётся при потере места без личной live-подписки; скидка применяется к `payment_orders.amount` на сервере | Сумма заказа — единственный источник правды и для скидок |
| Рефералка | `learners.referral_code` (непредсказуемый), `referrals` со статусами; награда 7 дней Pro обоим **после активации** (≥ 10 оценок в ≥ 3 разных локальных дня за 14 дней), лимиты на referrer'а; `ref_<id>` отменяется | Фарм дорог, награды отложены, существующие аккаунты не рефералы |
| Streak freeze | Автопотребление при пропуске ровно одного дня (Pro), инвентарь `learners.streak_freezes` (≤ 2, +1 в неделю), факт — `learner_daily_stats.frozen_at` | Источник истины streak остаётся `learner_daily_stats`; без блокировок в транзакции оценки |
| Лиги | Недельные группы ≤ 30 по tier'ам; **очки считаются из `learner_daily_stats`**, не пишутся в горячем пути; таблица рейтинга — кэш 60 с | Ноль новых блокировок в транзакции оценки |
| Шеринг | Картинки — GD (расширение уже есть) + шрифт OFL как ассет; без новых composer-зависимостей | Imagick/Intervention не нужны |
| Упражнения | `TrainingStep` расширяется `exercise` + `prompt` (+ `audio_url` — хвост 1.2c); spelling/gap-fill **оцениваются сервером** (`POST /review {answer}`), Дамерау–Левенштейн с порогом от длины | Клиенту нельзя доверять оценку |
| FSRS | `SchedulerAlgorithm` (`sm2` / `fsrs`), поля `stability`/`difficulty` в `word_repetitions`, бакет A/B на learner'а (`learners.scheduler`), learning steps общие; метрики из `training_reviews.scheduler` | A/B без второй таблицы; откат — переключить бакет |
| Симулятор IELTS/SAT | Обобщить `exam_sessions` (`mode = personal_mock`, `learner_id`) как в Фазе 1 обобщили `training_sessions` | Переиспользуем builder/flow/TWA экзамена |
| Схема в коде | Новые таблицы — новые миграции с `down()`; правки существующих (`subscriptions` partial unique с `canceled`, `tier`; `word_repetitions` FSRS; `exam_sessions` nullable FK) — **на месте** в create-миграциях | Правило docs/12 §2.2 действует до первого прод-релиза |

---

## 1. Модель данных

### 1.1 ERD (добавления Фазы 2)

```mermaid
erDiagram
    LEARNERS ||--o{ PAYMENT_ORDERS : buys
    ORGANIZATIONS ||--o{ PAYMENT_ORDERS : buys
    PAYMENT_ORDERS ||--o{ PROVIDER_TRANSACTIONS : provider_state
    PAYMENT_ORDERS ||--o{ PAYMENTS : settles
    SUBSCRIPTIONS ||--o{ PAYMENTS : funds
    PAYMENTS ||--o| PAYMENTS : refunds
    LEARNERS ||--o{ OFFERS : personal_discount
    OFFERS ||--o| PAYMENT_ORDERS : applied_to
    ORGANIZATIONS ||--o{ ORGANIZATION_MEMBERS : seats
    LEARNERS ||--o{ ORGANIZATION_MEMBERS : member
    ORGANIZATIONS ||--o{ ORGANIZATION_INVITES : codes
    ORGANIZATION_INVITES ||--o{ ORGANIZATION_MEMBERS : joined_via
    ORGANIZATIONS ||--o{ ORGANIZATION_USERS : staff
    USERS ||--o{ ORGANIZATION_USERS : role
    ORGANIZATIONS ||--o{ ASSIGNMENTS : sets
    TELEGRAM_GROUPS ||--o{ ASSIGNMENTS : for_group
    LESSONS ||--o{ ASSIGNMENTS : target
    ASSIGNMENTS ||--o{ ASSIGNMENT_RESULTS : per_learner
    LEARNERS ||--o{ REFERRALS : referrer
    LEARNERS ||--o| REFERRALS : referee
    LEAGUE_WEEKS ||--o{ LEAGUE_GROUPS : has
    LEAGUE_GROUPS ||--o{ LEAGUE_MEMBERS : ranks
    LEARNERS ||--o{ LEAGUE_MEMBERS : competes
    LEARNERS ||--o{ ACHIEVEMENTS : unlocks
    LEARNERS ||--o{ FRIENDSHIPS : pairs
    LEARNERS ||--o{ COURSES : own_decks
    LEARNERS ||--o{ EXAM_SESSIONS : personal_mock
```

Таблицы Фазы 1 (`subscriptions`, `learner_daily_stats`, `word_repetitions`, `exam_sessions`, `courses`) получают колонки, перечисленные в §1.6.

### 1.2 Оплата — `payment_orders`, `provider_transactions`, `payments`, `offers`

**`payment_orders`** — намерение купить; единственный источник суммы.

| Колонка | Тип | Описание |
|---|---|---|
| id | bigint PK | |
| token | varchar(32) NOT NULL unique | `Str::random(32)`; уходит провайдеру как `invoice_payload` (Stars), `account.order_id` (Payme), `merchant_trans_id` (Click). Непредсказуем — по нему нельзя перебрать чужие заказы |
| subscriber_type / subscriber_id | varchar(16) / bigint NOT NULL | `learner` / `organization`; без FK (как `subscriptions`) |
| plan | varchar(16) NOT NULL | `pro` |
| tier | varchar(16) nullable | тариф организации (`tutor`/`center_s`/`center_m`/`center_l`), `NULL` для learner'а |
| period | varchar(8) NOT NULL | `month` / `year` |
| provider | varchar(16) NOT NULL | `telegram_stars` / `payme` / `click` |
| amount | bigint NOT NULL | минорные единицы: тийины для `UZS`, целые Stars для `XTR`; **после** скидки |
| list_amount | bigint NOT NULL | до скидки (аналитика) |
| currency | varchar(8) NOT NULL | `UZS` / `XTR` |
| offer_id | bigint nullable FK → offers `nullOnDelete` | применённая скидка |
| status | varchar(12) NOT NULL default `'pending'` | `pending` → `paid` / `canceled` / `expired` / `failed`. Для Stars-подписки заказ остаётся `paid` и служит якорем продлений (`invoice_payload` одинаков у всех списаний) |
| provider_ref | varchar(191) nullable | ссылка на инвойс (Stars) / URL оплаты; **не** id транзакции |
| expires_at | timestamp NOT NULL | `now + config('billing.checkout.order_ttl_minutes')` (1440); Payme/Click отвечают отказом на просроченный заказ |
| paid_at | timestamp nullable | первая успешная оплата |
| subscription_id | bigint nullable FK → subscriptions `nullOnDelete` | подписка, созданная/продлённая этим заказом |
| created_via | varchar(16) NOT NULL | `twa` / `bot` / `org_panel` / `admin` |
| meta | jsonb default `'{}'` | `{learner_locale, return_url, ...}` |
| created_at, updated_at | | |

Индексы: `token` unique; `(subscriber_type, subscriber_id, status)`; `(status, expires_at)`; `subscription_id`; `offer_id`.

**`provider_transactions`** — состояние транзакции на стороне провайдера (одна строка на `provider_transaction_id`; для Stars не используется).

| Колонка | Тип | Описание |
|---|---|---|
| id | bigint PK | для Click — это `merchant_prepare_id` |
| provider | varchar(16) NOT NULL | `payme` / `click` |
| provider_transaction_id | varchar(64) NOT NULL | Payme `params.id`, Click `click_trans_id` |
| payment_order_id | bigint NOT NULL FK → payment_orders `restrictOnDelete` | |
| state | smallint NOT NULL | Payme: 1 created, 2 performed, −1 canceled before perform, −2 canceled after perform; Click: 1 prepared, 2 completed, −1 canceled |
| amount | bigint NOT NULL | в минорных единицах, как пришло (сверяется с заказом) |
| provider_time | bigint nullable | Payme `params.time` (мс) |
| created_at_provider, performed_at, canceled_at | timestamp nullable | |
| cancel_reason | smallint nullable | Payme reason 1–5, 10; Click `error` |
| raw | jsonb default `'{}'` | последний запрос провайдера **после редактирования секретов** (без `Authorization`, `sign_string`) |
| history | jsonb default `'[]'` | `[{method, at, state_from, state_to}]` — журнал вебхука, отдельной таблицы не нужно |
| created_at, updated_at | | |

Индексы: `(provider, provider_transaction_id)` unique; `payment_order_id`; `(provider, state, created_at_provider)` — `GetStatement`.

**`payments`** — леджер (форма из docs/12 §1.9, уточнённая).

| Колонка | Тип | Описание |
|---|---|---|
| id | bigint PK | |
| payment_order_id | bigint nullable FK → payment_orders `nullOnDelete` | |
| provider_transaction_id | bigint nullable FK → provider_transactions `nullOnDelete` | |
| subscription_id | bigint nullable FK → subscriptions `nullOnDelete` | |
| subscriber_type / subscriber_id | varchar(16) / bigint NOT NULL | дублируется из заказа — отчёты без join'ов |
| provider | varchar(16) NOT NULL | |
| provider_event_id | varchar(191) NOT NULL | ключ идемпотентности: Stars `telegram_payment_charge_id`; Payme `<transaction id>:perform` / `:cancel`; Click `<click_trans_id>:complete` / `:cancel` |
| provider_charge_id | varchar(191) nullable | то, что нужно для возврата: Stars charge id, Payme transaction id |
| kind | varchar(12) NOT NULL | `charge` / `renewal` / `refund` |
| status | varchar(12) NOT NULL | `succeeded` / `refunded` / `failed` |
| amount, currency | bigint, varchar(8) | знак положительный; `refund` — отдельная строка с `refunded_payment_id` |
| refunded_payment_id | bigint nullable FK → payments `nullOnDelete` | |
| period_starts_at, period_ends_at | timestamp nullable | за какой период уплачено |
| occurred_at | timestamp NOT NULL | момент у провайдера |
| raw | jsonb default `'{}'` | нормализованное событие (без секретов) |
| created_at, updated_at | | |

Индексы: `(provider, provider_event_id)` unique; `(subscriber_type, subscriber_id, occurred_at)`; `(status, occurred_at)`; `subscription_id`; `payment_order_id`.

**`offers`** — персональные скидки (B2B → B2C, позже — промо-кампании).

| Колонка | Тип |
|---|---|
| id | bigint PK |
| learner_id | FK → learners cascade |
| kind | varchar(24): `post_org_discount` (Фаза 2), `winback`, `campaign` |
| plan, period | varchar(16), varchar(8) nullable (`NULL` = любой период) |
| discount_pct | smallint 1..90 |
| expires_at | timestamp NOT NULL |
| redeemed_at | timestamp nullable; `payment_order_id` FK nullable → заказ, в котором применена |
| source_organization_id | FK → organizations nullOnDelete (для `post_org_discount`) |
| meta, created_at, updated_at | |

Индексы: `(learner_id, kind, expires_at)`; partial unique `offers_one_open_per_learner_kind (learner_id, kind) WHERE redeemed_at IS NULL` (PG/sqlite с guard драйвера).

### 1.3 Организации — `organization_members`, `organization_invites`, `organization_users`

**`organization_members`** — явное членство и место.

| Колонка | Тип | Описание |
|---|---|---|
| id | bigint PK | |
| organization_id | FK → organizations cascade | |
| learner_id | FK → learners cascade | |
| status | varchar(12) NOT NULL | `active` / `left` (сам) / `removed` (учитель/владелец/суперадмин) |
| seat_state | varchar(12) NOT NULL | `seated` / `waitlisted` (мест нет) / `none` (status ≠ active) |
| joined_via | varchar(12) NOT NULL | `invite` / `group` / `admin` |
| invite_id | FK → organization_invites nullOnDelete | |
| seated_at, waitlisted_at, joined_at, left_at | timestamp | |
| meta | jsonb | |

Индексы: `(organization_id, learner_id)` unique; `(organization_id, status, seat_state)`; `(learner_id, status)`; `(organization_id, seat_state, waitlisted_at)` — FIFO.

Правила: место = строка `status = active AND seat_state = seated`; спонсорский Pro — только у `seated` в организации `status = active` с live-подпиской. `waitlisted` — обычный Free с баннером «мест в центре нет, попросите администратора». При освобождении места самый ранний `waitlisted` становится `seated` в той же транзакции. При понижении `seats_limit` (смена тарифа вниз) никто не снимается автоматически — владелец центра решает сам, виджет показывает «превышение»; **новые** не садятся, пока `seated >= seats_limit`.

**`organization_invites`**: id, `organization_id` FK cascade, `code` varchar(16) unique (`Str::upper(Str::random(10))` без `0/O/1/I`), `label` varchar(64) nullable («группа 7Б»), `telegram_group_id` FK nullable (авто-добавить в группу — нет: только метка; вступление в чат делает человек), `max_uses` int nullable, `uses_count` int default 0, `expires_at` nullable, `is_active` bool, `created_by_user_id` FK users nullOnDelete, timestamps. Deep-link: `t.me/<bot>?start=org_<code>` (`StartPayload` уже принимает `org_[A-Za-z0-9_-]{1,32}`).

**`organization_users`**: id, `organization_id` FK cascade, `user_id` FK users cascade, `role` varchar(12) (`owner` / `teacher`), `invited_by_user_id` nullable, timestamps; unique `(organization_id, user_id)`. Роли Filament `/org` читаются из этой таблицы (spatie-роли не используются для tenant-ролей — у одного пользователя могут быть разные роли в разных организациях). Учитель организации работает в группах организации; команды бота (`/start_training`) по-прежнему требуют `users.telegram_user_id` + `teacher_groups` — владелец в кабинете привязывает учителя к группам (создаются строки `teacher_groups`).

### 1.4 Задания — `assignments`, `assignment_results`

**`assignments`**: id, `organization_id` FK cascade, `telegram_group_id` FK cascade, `lesson_id` FK restrict, `title` varchar(120) nullable (дефолт — название урока), `due_at` timestamp NOT NULL, `announced_at` nullable (пост в группу отправлен), `reminded_at` nullable (напоминание за день), `status` varchar(10) (`active` / `closed`), `completion_threshold` smallint default 100 (% слов урока, засчитываемых как «выполнено»), `created_by_user_id` FK users nullOnDelete, `meta`, timestamps. Индексы: `(telegram_group_id, status, due_at)`; `(organization_id, due_at)`.

**`assignment_results`** — снимок прогресса (пересчитывается job'ом `assignments:refresh` каждые 30 мин для `active` и при открытии отчёта, если снимок старше 10 мин): id, `assignment_id` FK cascade, `learner_id` FK cascade, `progress_pct` smallint, `completed_at` nullable, `computed_at`; unique `(assignment_id, learner_id)`. Правило: `progress = (#активных слов урока с word_repetitions.repetitions >= 1 у learner'а) / (#активных слов урока)`; `completed_at` = момент первого достижения порога (ставится один раз; если прошёл после `due_at` — «выполнено с опозданием»). Источник — `word_repetitions`, достаточно для отчёта и дёшево; `training_reviews` — для детализации «сколько повторений/времени».

### 1.5 Рост и удержание — `referrals`, лиги, достижения, друзья

**`learners`** +: `referral_code` varchar(12) unique nullable (ленивая генерация при первом показе ссылки, алфавит без `0/O/1/I`), `streak_freezes` smallint default 0, `streak_freezes_refilled_on` date nullable, `scheduler` varchar(8) nullable (A/B: `sm2`/`fsrs`; `NULL` = дефолт конфига; ставится при создании по бакету `crc32(telegram_user_id) % 100 < fsrs_percent`).

**`referrals`**: id, `referrer_learner_id` FK cascade, `referee_learner_id` FK cascade **unique**, `status` varchar(12) (`pending` → `activated` → `rewarded`; `rejected` с `reject_reason`: `self`, `existing_learner`, `referrer_capped`, `flagged`), `linked_at`, `activated_at`, `rewarded_at`, `flagged_at`, `meta` (`{reward_subscription_ids, active_days}`), timestamps. Индексы: `(referrer_learner_id, status, linked_at)`.

**`learner_daily_stats`** +: `frozen_at` timestamp nullable (день закрыт заморозкой; `goal_met_at` остаётся `NULL`).

**`league_weeks`**: id, `starts_on` date unique (понедельник), `ends_on`, `status` (`open` / `closing` / `closed`), `closed_at`. **`league_groups`**: id, `league_week_id` FK cascade, `tier` smallint (0 Bronze … 4 Diamond), `capacity` smallint default 30, `members_count` smallint default 0; индекс `(league_week_id, tier, members_count)`. **`league_members`**: id, `league_group_id` FK cascade, `league_week_id` FK cascade (денормализация), `learner_id` FK cascade, `tier_before` smallint, `final_points` int nullable, `final_rank` smallint nullable, `result` varchar(10) nullable (`promoted` / `stayed` / `demoted`), timestamps; unique `(league_week_id, learner_id)`; индекс `(league_group_id)`. **Очки не хранятся до закрытия недели** — считаются из `learner_daily_stats` за даты недели (§7.3).

**`achievements`**: id, `learner_id` FK cascade, `key` varchar(32) (`words_100`, `words_500`, `words_1000`, `streak_7`, `streak_30`, `streak_100`, `league_top1`, `course_completed:<slug>`), `unlocked_at`, `shared_at` nullable, `meta`; unique `(learner_id, key)`.

**`friendships`**: id, `learner_id` (меньший id), `friend_id` (больший id), `status` (`pending` / `accepted` / `blocked`), `requested_by_learner_id`, timestamps; unique `(learner_id, friend_id)`; CHECK `learner_id < friend_id` (PG). **`challenges`** (минимум): id, `challenger_id`, `opponent_id`, `metric` (`reviews` / `new_words`), `starts_at`, `ends_at`, `status` (`pending` / `active` / `finished` / `declined`), `winner_learner_id` nullable, `scores` jsonb; результат считается из `learner_daily_stats` при закрытии.

### 1.6 Обучение — правки существующих таблиц (на месте)

| Таблица | Изменение | Задача |
|---|---|---|
| `subscriptions` | + `tier` varchar(16) nullable; partial unique `subscriptions_one_live_per_subscriber` → `WHERE status IN ('trialing','active','past_due','canceled')` | 2A.1 / 2B.1 |
| `word_repetitions` | + `stability` decimal(9,4) nullable, `difficulty` decimal(6,4) nullable, `scheduler` varchar(8) default `'sm2'`, `scheduled_days` smallint nullable (интервал, назначенный FSRS, для метрик) | 2D.4 |
| `training_reviews` | + `exercise` varchar(12) default `'flash'`, `answer_text` varchar(100) nullable, `scheduler` varchar(8) default `'sm2'`, `interval_before_days` smallint nullable | 2D.2 / 2D.4 |
| `exam_sessions` | `telegram_group_id`, `started_by_user_id` → nullable; + `learner_id` FK nullable cascade, `mode` varchar(16) default `'group_exam'` (`group_exam` / `personal_mock`), `course_id` FK nullable; PG CHECK формы по образцу `training_sessions_mode_shape_check`; `lesson_id` → nullable (`personal_mock` берёт слова курса) | 2D.5 |
| `courses` | `owner_type` получает значение `learner` (`owner_id = learners.id`), `visibility = private`; + `source` varchar(12) default `'platform'` (`platform` / `organization` / `learner`) для фильтров | 2D.1 |
| `words` | без изменений; пользовательские слова живут в `lessons` колоды (`stage` «Моя колода» создаётся автоматически) | 2D.1 |

### 1.7 Миграции (новые файлы, `down()` обязателен)

| Файл | Содержимое | Задача |
|---|---|---|
| `2026_10_20_000001_create_offers_table` | §1.2 `offers` (до `payment_orders` из-за FK) | 2A.1 |
| `2026_10_20_000002_create_payment_orders_table` | §1.2 | 2A.1 |
| `2026_10_20_000003_create_provider_transactions_table` | §1.2 | 2A.1 |
| `2026_10_20_000004_create_payments_table` | §1.2 (self-FK `refunded_payment_id`) | 2A.1 |
| `2026_10_21_000001_create_organization_users_table`, `…_000002_create_organization_invites_table`, `…_000003_create_organization_members_table` | §1.3 | 2B.1 |
| `2026_10_21_000004_create_assignments_table`, `…_000005_create_assignment_results_table` | §1.4 | 2B.4 |
| `2026_10_22_000001_create_referrals_table` | §1.5 | 2C.1 |
| `2026_10_22_000002_create_league_tables` (три таблицы, одна миграция) | §1.5 | 2C.3 |
| `2026_10_22_000003_create_achievements_table`, `…_000004_create_friendships_and_challenges_tables` | §1.5 | 2C.4 / 2C.5 |
| Правки на месте: `2026_04_23_095902_create_learners_table` (+ `referral_code`, `streak_freezes*`, `scheduler`), `2026_04_23_100016_create_learner_daily_stats_table` (+ `frozen_at`), `2026_04_23_100017_create_subscriptions_table` (+ `tier`, предикат partial unique), `2026_04_23_100006` / `2026_10_18_000002` (`word_repetitions` FSRS-поля), `2026_04_23_100008_create_training_reviews_table`, `2026_04_23_100009_create_exam_sessions_table` | §1.5–1.6 | по задачам |

`DatabaseSchemaTest` дополняется новыми таблицами, уникальностями (`payments (provider, provider_event_id)`, `provider_transactions (provider, provider_transaction_id)`, `payment_orders.token`, `organization_members (organization_id, learner_id)`, `referrals.referee_learner_id`, `league_members (league_week_id, learner_id)`), каскадами и `migrate:rollback`/`migrate` для новых файлов.

---

## 2. Домены и контракты сервисов

### 2.1 `app/Domain/Billing` — провайдеры и checkout

```php
namespace App\Domain\Billing\Contracts;

interface BillingProvider
{
    public function key(): SubscriptionProvider;                                  // TelegramStars | Payme | Click
    public function supports(string $subscriberType, string $currency): bool;     // Stars: learner + XTR; Payme/Click: learner|organization + UZS
    /** Создаёт платёжную ссылку/инвойс для уже сохранённого заказа (вызывается ВНЕ транзакции заказа). */
    public function createCheckout(PaymentOrder $order): CheckoutLink;           // {url, kind: 'invoice_link'|'redirect', expiresAt}
    /** Автопродление: только Stars. Остальные бросают ProviderCapabilityException. */
    public function cancelAutoRenew(Subscription $s): void;                       // editUserStarSubscription(is_canceled=true)
    public function resumeAutoRenew(Subscription $s): void;                       // is_canceled=false
    /** Возврат, инициированный нами (Stars). Payme/Click возвращают через свой кабинет — для них метод бросает ProviderCapabilityException, запись делает их вебхук CancelTransaction / Complete(error<0). */
    public function refund(Payment $p): void;
}

namespace App\Domain\Billing\DTO;

final readonly class ChargeEvent        // нормализованное «деньги получены»
{
    public SubscriptionProvider $provider; public string $providerEventId; public ?string $providerChargeId;
    public string $orderToken;          // payment_orders.token (invoice_payload / account.order_id / merchant_trans_id)
    public int $amount; public string $currency;
    public bool $isRenewal;             // Stars is_recurring && !is_first_recurring
    public ?CarbonImmutable $periodEndsAt; // Stars subscription_expiration_date; Payme/Click — null (считаем сами)
    public CarbonImmutable $occurredAt; public array $raw;
}
final readonly class RefundEvent { provider, providerEventId, refundedProviderChargeId, amount, currency, occurredAt, raw }
final readonly class CheckoutLink  { string $url; string $kind; CarbonImmutable $expiresAt; }
```

```php
namespace App\Domain\Billing\Services;

final class CheckoutService
{
    /** Валидирует план/период/провайдера/валюту по config('billing'), применяет открытый offer (если подходит), считает amount на сервере,
     *  создаёт payment_orders (pending) в короткой транзакции, затем ВНЕ транзакции provider->createCheckout(); provider_ref сохраняется.
     *  CheckoutException: price_not_configured (409) | provider_disabled (409) | provider_not_supported (422) | checkout_conflict (409 — живая Stars-подписка с автопродлением и другой провайдер) | too_many_pending (429 — > 3 pending заказов) | offer_not_applicable (409). */
    public function create(Learner|Organization $subscriber, Plan $plan, string $period, SubscriptionProvider $provider, ?string $tier, string $via, ?Offer $offer = null, ?CarbonImmutable $now = null): PaymentOrder;
    public function catalog(Learner|Organization $subscriber, ?CarbonImmutable $now = null): BillingCatalog; // {plans/tiers × periods × currencies, providers enabled, open offer, current subscription summary}
    public function expirePending(?CarbonImmutable $now = null): int;             // планировщик billing:expire-orders (каждые 15 мин)
    public function findByToken(string $token): ?PaymentOrder;                    // для вебхуков; token сравнивается как строка, не как id
}

final class PaymentApplier     // ядро идемпотентности (architect)
{
    /** Быстрый путь: payments.exists(provider, providerEventId) → DuplicatePayment (без побочных эффектов).
     *  Транзакция, порядок §3: lockForUpdate payment_orders по token → [provider_transactions] → субъект → subscriptions (lockedLive) →
     *  сверка amount/currency/plan с заказом (несовпадение → payments.status=failed, Log::error 'billing.amount_mismatch', подписка не меняется) →
     *  SubscriptionService::applyCharge → INSERT payments (unique; UniqueConstraintViolationException → rollback → DuplicatePayment) → order.paid → события → forget после commit.
     *  Возвращает Payment; бросает PaymentApplyException: order_not_found | order_expired | order_mismatch. */
    public function applyCharge(ChargeEvent $e, ?CarbonImmutable $now = null): Payment;
    /** Тот же порядок; находит исходный payment по provider_charge_id; создаёт payments.kind=refund, помечает исходный refunded;
     *  если возвращён текущий оплаченный период — SubscriptionService::endNow(reason='refund'); audit `payment.refunded` (если инициировал админ). */
    public function applyRefund(RefundEvent $e, ?User $by = null, ?CarbonImmutable $now = null): Payment;
}

final class SubscriptionService   // расширение 1.4a
{
    /** @internal для PaymentApplier, внутри его транзакции, субъект уже заблокирован.
     *  Нет live: создаёт active с provider=e.provider, starts_at=now, current_period_ends_at = e.periodEndsAt ?? now + period.
     *  Есть live: current_period_ends_at = max(текущий конец, e.periodEndsAt ?? max(конец, now) + period); status → active (из past_due/canceled при возобновлении Stars);
     *  provider = провайдер последнего платежа; provider_ref = providerChargeId (Stars — charge id первого платежа подписки хранится также в meta.stars.charge_id);
     *  tier/seats_limit — из заказа (организации); meta.history += {action: 'charge'|'renewal', payment_order_id}. */
    public function applyCharge(Learner|Organization $subscriber, PaymentOrder $order, ChargeEvent $e, CarbonImmutable $now): Subscription;
    /** active → canceled (canceled_at=now, период не трогается). Только для автопродляемого провайдера; вызывающий сначала делает provider->cancelAutoRenew() ВНЕ транзакции (§4.1). */
    public function cancel(Subscription $s, Learner|User $by, ?string $ip, ?CarbonImmutable $now = null): Subscription;
    public function resume(Subscription $s, Learner|User $by, ?string $ip, ?CarbonImmutable $now = null): Subscription;   // canceled → active, если период не истёк
    public function endNow(Subscription $s, string $reason, ?User $by, ?CarbonImmutable $now = null): Subscription;      // → expired, ended_at=now (refund/chargeback); порядок: субъект → подписка
    public function grantManual(..., ?string $tier = null): Subscription;         // + tier → seats_limit из config('billing.org_tiers.<tier>.seats') (organization)
    /** Награды (рефералка): как grantFromPromo, provider = reward; @internal для ReferralService в его транзакции. */
    public function grantReward(Learner $l, int $days, string $source, CarbonImmutable $now): Subscription;
    public function remindExpiring(?CarbonImmutable $now = null): int;           // §4.4: non-renewing live с концом через 3/1 день → RenewalReminderJob
}

final class OfferService
{
    public function openFor(Learner $l, string $kind, ?CarbonImmutable $now = null): ?Offer;
    public function createPostOrgDiscount(Learner $l, Organization $from, ?CarbonImmutable $now = null): ?Offer;   // null, если есть личная live-подписка или открытый offer того же kind
    public function apply(Offer $o, int $listAmount): int;                       // round(list × (100 − pct) / 100), минимум 1 (Stars) / 100 тийинов
}
```

`SubscriptionProvider` +: `case Reward = 'reward'`; `hasGrace()` → `$this === self::TelegramStars`; `isAutoRenewing()` — то же; `isPaid()` — Stars/Payme/Click; `currency()` — `XTR` / `UZS` / `null`. `PaidSubscriptionQuery::aliveAt` берёт список «с grace» из enum — изменится автоматически; матрица `PaidSubscriptionQueryTest` расширяется статусом `canceled` и провайдером `reward`.

> **Реализация 2A.1 (2026-10-19, architect) — уточнения к контрактам выше:**
> - `ChargeEvent` получил `?int $providerTransactionId` (наш `provider_transactions.id`; Payme/Click заполняют, пишется в `payments.provider_transaction_id`).
> - Повтор события — `DuplicatePaymentException` (409, `->payment` = первая строка леджера), а не «тихий» выход: вебхук отвечает так же, как в первый раз. Проверка идёт дважды: без блокировок до транзакции и под блокировкой заказа; unique-индекс — последний рубеж.
> - События, которые нельзя зачесть (чужой провайдер, мёртвый/оплаченный заказ, несовпадение суммы/валюты) **коммитятся** как `payments.status = failed` (`raw.failure_reason`) + `payment.failed`, затем бросается `order_expired`/`order_mismatch`; при несовпадении суммы исключения нет — возвращается `failed`-строка. `order_not_found` — без строки; для «заказ потерян, learner известен» (Stars-продление, §4.1) — `PaymentApplier::recordOrphan(event, subscriber, reason)`.
> - `applyRefund` возвращает строку `refund`; `endNow(reason='refund')` — если возвращённый платёж оплачивал текущий период (`payments.period_ends_at > now`).
> - `OfferService::apply(Offer, int $listAmount, string $currency)` — валюта нужна для минимума (1 XTR / 100 тийинов). Partial unique `offers` считает и просроченные непогашенные — `createPostOrgDiscount` удаляет просроченную того же вида перед вставкой. FK `offers.payment_order_id` — только PostgreSQL (sqlite при `ALTER TABLE` терял предикат partial-индекса).
> - `remindExpiring` **не** использует `notifications_log` (у организаций нет `learner_id`): маркер `"<days>@<ISO конца периода>"` пишется в `subscriptions.meta.renewal_reminders` под блокировкой субъект → подписка, затем после commit поднимается событие `App\Domain\Billing\Events\SubscriptionRenewalDue {subscriptionId, subscriberType, subscriberId, provider, daysLeft, endsAt}`; `RenewalReminderJob` (2A.6) — слушатель этого события и пишет `notifications_log` для ЛС.
> - Добавлен `ProviderTransactionService::open(provider, providerTransactionId, orderToken, amount, providerTime, raw)` — общее ядро Payme `CreateTransaction` и Click `Prepare`: блокировка заказа → проверка «нет другой открытой транзакции» (`order_busy`) → вставка state 1; повтор того же id идемпотентен. Остальные переходы состояний — в 2A.4/2A.5.
> - `CheckoutService::create` дополнительно даёт `invalid_period` (422) и `invalid_tier` (422); `catalog()` возвращает `BillingCatalog {items, providers, offer, subscription}` (`items` — строки plan[/tier] × period с `prices`/`list_prices` по валютам включённых провайдеров; пусто = продавать нечего). `CheckoutService::summarize(Subscription, now)` — сводка для `/me.entitlement.renewal`.
> - `grantManual(..., ?string $tier = null)` — последний необязательный параметр; `grantReward` при бессрочной подписке возвращает её без изменений (награда не бросает исключений).
> - `FakeBillingProvider` живёт в `tests/Support` (`FakeBillingProvider::register(SubscriptionProvider)` регистрирует в `BillingProviderRegistry` и включает провайдера в config).

> **Реализация 2A.2 (2026-10-19, coder) — уточнения к §5:**
> - Оркестрация для TWA и бота — `LearnerBillingService` (`checkout`, `orderFor`, `cancelAutoRenew`/`resumeAutoRenew`, `paywall`, `renewal`): контроллер и хендлеры бота тонкие. Сбой адаптера провайдера (`RuntimeException`, кроме `BillingException`/`QueryException`) → `ProviderUnavailableException` (`provider_unavailable`, 502); при cancel/resume провайдер вызывается до транзакции, и при его отказе подписка не меняется.
> - `GET /billing/catalog` отдаёт `items` (а не `plans`) — имя из `BillingCatalog` (2A.1). `POST /billing/checkout` отвечает `201`. `GET /billing/orders/{token}` для чужого/неизвестного токена — `404 order_not_found`.
> - `paywall.mode = purchase` в `/me` понижается до `coming_soon`, если каталог пуст — это делает `LearnerBillingService::paywall()`; `EntitlementResolver::paywall()` не менялся (там сырой режим конфига: файл в зоне 2B.1).
> - `entitlement.renewal` — только собственная live-подписка learner'а (`null` для Free/спонсорского Pro): `{auto, provider, ends_at, reminder_sent}`.
> - Бот: `/pro` создаёт заказ только по нажатию кнопки (`pro1:buy:<period>:<provider>`), чтобы меню не съедало лимит неоплаченных заказов; при работающем автопродлении Stars кнопок покупки нет (иначе двойное списание). `/pro` — только личный чат; `/paysupport` — ЛС и активная группа.
> - Событие `paywall.shown` по-прежнему пишет сервер при исчерпанной квоте (без поля `mode`): клиент событий не шлёт.

> **Реализация 2A.7 (2026-10-19, coder) — админка оплаты:**
> - `PaymentResource`, `PaymentOrderResource`, `ProviderTransactionResource` — только чтение (policies: `PaymentPolicy`, `PaymentOrderPolicy`, `ProviderTransactionPolicy`, активный `admin`; teacher → 403). Сырые payload'ы (`payments.raw`, `provider_transactions.raw/history`, `payment_orders.meta`) показываются через `PayloadMasker` (ключи `sign*`, `*key*`, `*token*`, `*secret*`, `authorization`, `invoice_payload`, `order_id`, `merchant_trans_id` → `[redacted]`, + `SecretRedactor` по строкам); токен заказа усечён (`AbCd…wXyZ`), `provider_ref` не выводится.
> - Refund: `PaymentRefundService::refund(Payment, User, note, returnedByHand, ip)` — только активный admin; не-`succeeded`/не-charge/renewal или без `provider_charge_id` → `not_refundable`; уже возвращён → `already_refunded` **до** вызова провайдера. Деньги: `BillingProviderRegistry::for(provider)->refund($payment)` вне транзакции; `ProviderCapabilityException` (Payme/Click, адаптер не развёрнут) → `provider_cannot_refund` без изменений — админ возвращает деньги в кабинете и повторяет с `returnedByHand=true` (локальная отметка без вызова провайдера); сбой адаптера → `provider_refund_failed` (502-класс). Затем `PaymentApplier::applyRefund` с детерминированным `providerEventId = admin-refund:<payment id>` — повторный клик/второй админ даёт `already_refunded`, второй `refund` не создаётся. Сбой записи после успешного возврата у провайдера → `Log::error('billing.admin_refund_unrecorded')`, повтор — с `returnedByHand`.
> - `PaymentApplier::applyRefund` получил два необязательных последних параметра `?string $note, ?string $ip`: пишутся в `audit_logs` (`meta.note`, колонка `ip`) для `payment.refunded`.
> - Виджеты (`BillingReportService`): «Выручка 30 дней» — по валютам раздельно (UZS в тийинах, XTR), строки `kind ∈ {charge, renewal}` с `occurred_at ≥ now−30d`: `gross` = `succeeded + refunded`, `refunded` = со статусом `refunded`, `net = gross − refunded` (= `succeeded`); строки `refund` и `failed` не считаются. «Ошибки вебхуков 24 ч» — `payments.status=failed` по `created_at` (с разбивкой по провайдеру) + `provider_transactions` в state −1/−2 по `canceled_at`.
> - Grant в `SubscriptionResource` — необязательный `tier` для организации (`OrgTiers`), вызывает существующий `grantManual(..., tier:)`; без `tier` поведение прежнее.

**`canceled` как live (2A.1, чек-лист docs/12 §5.1 — выполняется целиком одной задачей):** `SubscriptionStatus::liveValues()` + `canceled`; `SubscriptionLiveness::liveUntil()` — для `canceled` `current_period_ends_at` **без** grace (отдельная ветка до проверки провайдера); `PaidSubscriptionQuery::aliveAt` — `canceled` в ветку «по концу периода без grace» независимо от провайдера; partial unique index — предикат с `canceled` (правка миграции на месте); `EntitlementResolver::liveCandidates` — через `liveValues()` автоматически; `expireDue` — `canceled` → `expired` по концу периода (уже так); тесты `BillingPlanTest`, `EntitlementResolverTest` (кейс `canceled` до/после конца периода), `SubscriptionServiceTest` («одна live» теперь включает `canceled`: `grantManual` поверх `canceled` продлевает ту же строку и возвращает `active`), `PaidSubscriptionQueryTest`.

### 2.2 `app/Domain/Billing/Providers`

```php
final class TelegramStarsProvider implements BillingProvider
{
    public function createCheckout(PaymentOrder $o): CheckoutLink;   // createInvoiceLink: title/description на локали learner'а, payload = order.token, currency 'XTR', prices [{label, amount}], subscription_period 2592000 только для period=month (year — разовый инвойс на 365 дней, без subscription_period)
    public function parseSuccessfulPayment(array $message): ChargeEvent;  // message.successful_payment: telegram_payment_charge_id → providerEventId и providerChargeId; invoice_payload → orderToken; total_amount/currency; is_recurring/is_first_recurring → isRenewal; subscription_expiration_date → periodEndsAt
    public function parseRefundedPayment(array $message): RefundEvent;    // message.refunded_payment (возврат, сделанный Telegram/поддержкой)
    public function cancelAutoRenew(Subscription $s): void;              // editUserStarSubscription(user_id = learner.telegram_user_id, telegram_payment_charge_id = meta.stars.charge_id, is_canceled = true)
    public function refund(Payment $p): void;                            // refundStarPayment(user_id, telegram_payment_charge_id)
}
final class StarsPreCheckoutGuard        // синхронно в WebhookController (§4.1)
{
    /** ≤ 1 запрос к БД: заказ по invoice_payload — pending, не истёк, learner = from.id, не заблокирован, сумма/валюта совпадают, провайдер включён. Возвращает ok|error_message (локализован). Ничего не пишет. */
    public function check(array $preCheckoutQuery): PreCheckoutVerdict;
}

final class PaymeProvider implements BillingProvider   // + PaymeRpcService: JSON-RPC методы (§4.2)
{
    public function createCheckout(PaymentOrder $o): CheckoutLink;   // GET-ссылка `{checkout_url}/{base64("m={merchant_id};ac.order_id={token};a={amount};l={ru|uz};c={return_url}")}` — формат сверить
}
final class PaymeRpcService
{
    public function handle(array $rpc, PaymeAuth $auth): array;     // диспетчер методов → JSON-RPC ответ {id, result} | {id, error:{code, message:{ru,uz,en}, data}}
    // CheckPerformTransaction, CreateTransaction, PerformTransaction, CancelTransaction, CheckTransaction, GetStatement — §4.2
}

final class ClickProvider implements BillingProvider   // + ClickMerchantService: prepare()/complete() (§4.3)
```

Регистрация: `BillingProviderRegistry::for(SubscriptionProvider): BillingProvider` (singleton map в `AppServiceProvider`), `enabled(): list<SubscriptionProvider>` по `config('billing.providers.*.enabled')`. В тестах — `FakeBillingProvider` (in-memory, возвращает `https://pay.test/{token}`, умеет `emitCharge(order)` → `ChargeEvent`).

### 2.3 `app/Domain/Organizations` (новый)

```php
final class OrganizationMembershipService
{
    /** Транзакция: lockForUpdate organizations → upsert organization_members (active) → seat: seated, если seated_count < seats_limit live-подписки (нет live-подписки → waitlisted; seats_limit NULL при live → без лимита) → uses_count инвайта → событие org.member_joined {via, seat_state}; forget(learner) после commit.
     *  Идемпотентно для уже active. OrganizationMembershipException: invite_invalid (404) | invite_exhausted (409) | invite_expired (410) | organization_suspended (409). */
    public function joinByInvite(Learner $l, string $code, ?CarbonImmutable $now = null): OrganizationMember;
    /** Вызывается GroupMembershipService::join (после своей записи, отдельной транзакцией, best effort + job-повтор) для группы с organization_id. joined_via=group. */
    public function joinFromGroup(Learner $l, TelegramGroup $g, ?CarbonImmutable $now = null): ?OrganizationMember;
    /** status → left|removed, seat_state → none; освобождённое место отдаётся самому раннему waitlisted (та же транзакция); после commit — forget обоих и OfferService::createPostOrgDiscount(ушедшему), если место было seated. */
    public function leave(Learner $l, Organization $o, string $status, ?User $by = null, ?CarbonImmutable $now = null): void;
    /** GroupMembershipService::leave для группы организации: если joined_via=group и у learner'а не осталось активных членств в группах организации — leave(status='left'). */
    public function onGroupLeft(Learner $l, TelegramGroup $g): void;
    public function reseat(Organization $o, ?CarbonImmutable $now = null): int;  // после повышения тарифа: waitlisted → seated FIFO до лимита; вызывается SubscriptionService::applyCharge/grantManual (afterCommit) при росте seats_limit
    public function seatsSummary(Organization $o): SeatsSummary;             // {limit: ?int, seated, waitlisted}
}

final class OrganizationInviteService { create(Organization, User $by, array $attrs): OrganizationInvite; deactivate(...); link(OrganizationInvite): string /* t.me/<bot>?start=org_<code> */; }

final class OrganizationStaffService   // кабинет: владелец приглашает учителя по email (создаёт users при отсутствии, роль teacher в organization_users, письмо со ссылкой сброса пароля); привязка учителя к группам = teacher_groups
final class AssignmentService          // create/close; announce (бот: сообщение в группу с web_app-кнопкой на урок); remindDue (за 24 ч); refreshResults(Assignment) — §1.4
final class OrgActivityReport          // perLearner(Organization, ?TelegramGroup, DateRange): rows {learner, reviews, new_words, days_active, goal_met_days, streak_current, assignments_done/total, last_seen_at}; perGroup(...); источники learner_daily_stats + assignments; экспорт CSV через Filament
final class OrgBillingService          // tier catalog, checkout через CheckoutService (subscriber=organization), invoice page data; смена тарифа = новый заказ с tier (при оплате seats_limit пересчитывается, reseat после commit)
```

**EntitlementResolver (2B.1)**: запрос спонсора меняется на `subscriptions WHERE subscriber_type='organization' AND subscriber_id IN (SELECT om.organization_id FROM organization_members om JOIN organizations o ON o.id = om.organization_id WHERE om.learner_id = ? AND om.status='active' AND om.seat_state='seated' AND o.status='active') AND status IN (live)`. `forgetGroup` остаётся (группы по-прежнему создают членство), `forgetOrganization` — по `organization_members`. `PaidSubscriptionQuery::sponsoredLearnerIds` и `OrganizationSeatsTableWidget` — на `organization_members`. Команда `organizations:sync-members` создаёт членства `joined_via=group` из текущих активных членств в группах организаций (для демо-сида и тестов).

### 2.4 `app/Domain/Engagement` (новый: рефералы, streak freeze, лиги, достижения, друзья)

```php
final class ReferralService
{
    public function link(Learner $referee, string $code, CarbonImmutable $now): ?Referral;   // из OnboardingService::start при ref_<code>: только если learner создан этим /start (wasRecentlyCreated) и код ≠ его собственный; иначе referrals.status=rejected {self|existing_learner}
    /** Слушатель daily_goal.met / training.session_finished для referee с pending-рефералом: активен, если learner_daily_stats за 14 дней от linked_at содержит ≥ 3 дат с reviews_count ≥ 10 (config). Переход pending→activated условным UPDATE. */
    public function evaluate(Learner $referee, CarbonImmutable $now): void;
    /** Клейм условным UPDATE activated→rewarded (meta.rewards=[]), затем ДВЕ отдельные транзакции grantReward (referee, referrer) через стандартный порядок блокировок (никогда не блокировать двух learner'ов в одной транзакции); частичный успех — повтор job'ом по meta.rewards. Лимиты referrer'а: config referrals.max_rewards_per_30d (10), lifetime (50) → rejected {referrer_capped}; > 20 linked за сутки → flagged_at, награды ждут ревью админа. */
    public function reward(Referral $r, CarbonImmutable $now): void;
    public function codeFor(Learner $l): string;                                               // ленивая генерация referral_code (retry при коллизии unique)
}

final class StreakFreezeService
{
    /** Вызывается из StreakService::current()/apply() при разрыве ровно в один день (streak_last_date = today − 2): Pro (feature streak.freeze) и streak_freezes > 0 →
     *  UPDATE learners SET streak_freezes = streak_freezes − 1 WHERE id=? AND streak_freezes > 0 (affected=1) → INSERT learner_daily_stats (learner_id, yesterday) ON CONFLICT DO UPDATE SET frozen_at = now WHERE frozen_at IS NULL AND goal_met_at IS NULL → streak сохраняется (StreakService считает день «удержанным», если goal_met_at IS NOT NULL OR frozen_at IS NOT NULL) → событие streak.frozen. Вне любой транзакции оценки (как сам current()). */
    public function tryFreeze(Learner $l, string $missedLocalDate, CarbonImmutable $now): bool;
    public function refill(Learner $l, CarbonImmutable $today): void;   // +1 в понедельник локальной недели до config max (2), лениво на чтении /today
}

final class LeagueService
{
    public function currentWeek(CarbonImmutable $now): LeagueWeek;                        // недели в config('leagues.timezone') (Asia/Tashkent), понедельник; firstOrCreate
    /** Размещение при первой активности недели (слушатель daily stats, вне транзакции оценки): tier = результат прошлой недели (или 0); группа tier'а с members_count < capacity под `FOR UPDATE SKIP LOCKED`, иначе новая; unique (week, learner) делает повтор безопасным. */
    public function place(Learner $l, CarbonImmutable $now): LeagueMember;
    /** points(learner, week) = Σ по learner_daily_stats.date ∈ [starts_on, ends_on]: reviews_count×1 + correct_count×1 + new_words_count×2 + (goal_met ? 10 : 0) (веса в config). standings(group) — один запрос с группировкой, Cache 60 с, ключ `league:standings:{group}`. */
    public function standings(LeagueGroup $g, CarbonImmutable $now): Collection;
    /** Планировщик понедельник 00:05 (tz лиг): week.status=closing → для каждой группы финальные очки/ранги, топ-N promoted, низ-N demoted (config 5/5; tier 0 не понижается, tier 4 не повышается), league_members.final_*; события league.week_closed; сообщения в ЛС (через глобальный лимит исходящих 2R.3); week.status=closed. Идемпотентно по status. */
    public function closeWeek(LeagueWeek $w, CarbonImmutable $now): void;
}

final class AchievementService  { evaluate(Learner $l, CarbonImmutable $now): list<Achievement>; /* слушатели daily stats/streak/league после commit; INSERT ... ON CONFLICT DO NOTHING */ }
final class AchievementCardRenderer { render(Achievement $a, string $locale): string /* путь PNG на диске public, GD + шрифт resources/fonts/*.ttf, кэш по (key, locale, значение) */; }
final class FriendshipService   { request(Learner, Learner): Friendship; accept/decline/remove; list(Learner): Collection /* + streak, words_learned из LearnerStatsService */; }
final class ChallengeService    { propose(Learner, Learner, string $metric, int $days): Challenge; accept/decline; close(Challenge): Challenge /* из learner_daily_stats */; }
```

### 2.5 `app/Domain/Learning` и `app/Domain/Content` (волна C)

```php
// Упражнения
enum ExerciseType: string { case Flash='flash'; case Reverse='reverse'; case Audio='audio'; case Spelling='spelling'; case GapFill='gap_fill'; public function serverGraded(): bool; public function feature(): ?string; }
final readonly class ExercisePrompt { ExerciseType $type; string $front; ?string $audioUrl; ?string $maskedExample; ?array $options /* reverse/audio: 4 варианта */; ?int $answerLength; }
// TrainingStep: + public ?ExercisePrompt $prompt, + public ?string $audioUrl (хвост 1.2c: WordAudioService::urlFor(word) → /storage/audio/… или null → клиент идёт в /tts)
final class ExerciseSelector    { select(WordRepetition $rep, Word $w, Entitlement $e, LearnerExerciseSettings $s, string $sessionMode): ExerciseType; } // new → flash; review с repetitions ≥ 2 → чередование flash/reverse/audio (детерминированно от (repetitions, word_id)); Pro + feature → spelling при repetitions ≥ 3 каждое 3-е, gap_fill при наличии example; group_lesson — только flash/reverse
final class SpellingGrader      { grade(string $expected, string $answer): SpellingVerdict /* {quality, distance, normalizedExpected} */; }  // нормализация: trim, lower, NFKC, схлопывание пробелов, ’→'; OSA-Дамерау–Левенштейн; допуск: len ≤ 4 → 0, 5–8 → 1, ≥ 9 → 2; exact → 5, в допуске → 4, иначе 1
final class GapFillBuilder      { build(Word $w): ?string; }  // маска формы слова в example: \b(word|word+s|+es|+ed|+d|+ing|y→ies/ied) \b без регистра → «____»; не найдено → null (селектор берёт flash)
// ReviewSubmissionService::submit(..., int $quality | string $answer): для serverGraded() quality вычисляется сервером, answer_text пишется в training_reviews (обрезка 100), exercise — всегда

// FSRS
interface SchedulerAlgorithm { public function key(): string; public function schedule(WordRepetition $rep, int $quality, CarbonImmutable $now): void; }
final class Sm2Scheduler implements SchedulerAlgorithm  // обёртка SpacedRepetitionEngine::applyReview (поведение не меняется)
final class FsrsScheduler implements SchedulerAlgorithm // new/learning/relearning — ОБЩАЯ машина шагов (LearningPolicy), FSRS считает только интервал review-карточек: rating = q∈{0,1,2}→1 Again, 3→2 Hard, 4→3 Good, 5→4 Easy; формулы FSRS-5 с дефолтными w[0..18], desired_retention 0.9, cap LEARNING_MAX_INTERVAL_DAYS; при первом применении к SM-2 карточке — инициализация stability = max(1, interval_days), difficulty = clamp(1..10, 5 + (2.5 − EF)·(4/1.2))
final class SchedulerResolver   { for(Learner $l): SchedulerAlgorithm; }  // learners.scheduler ?? config('learning.algorithm'); бакет назначается LearnerRegistrar при создании: crc32(telegram_user_id) % 100 < config('learning.fsrs.rollout_percent')
// Метрики (Filament-виджет «SM-2 vs FSRS», 2D.4): по training_reviews.scheduler за 28 дней — pass-rate due review-карточек, повторений на выученное слово (repetitions ≥ 1 → graduated), удержание learner'ов D7 по бакету

// Колоды
final class LearnerDeckService  { create(Learner, string $title): Course; addWords(Course, list<DeckRow>): ImportReport; removeWord(...); delete(Course); } // owner_type=learner, visibility=private, status=published сразу, stage «Моя колода»/lesson «Часть N» по 50 слов; лимиты config('billing.plans.<plan>.limits.decks', .deck_words): Free 1×50, Pro 20×2000; DeckImportParser::parse(string $text, 'list'|'csv'): list<DeckRow> (строка «word - translation», CSV word,translation,example; ≤ 500 строк, ≤ 256 КБ, strip_tags, длины как у words) ; CourseCatalogService::isAvailable: private доступен владельцу-learner'у (и forLearner включает свои колоды)

// Симулятор
final class MockExamService     { open(Learner $l, Course $c, ?CarbonImmutable $now = null): ExamSession; } // mode=personal_mock, learner_id, course_id, config {questions: 40, seconds: 1200}, слова — случайные активные слова курса (seed = session id), ExamQuestionBuilder::forWords(); flow/answer/result — существующие ExamFlowService с политикой «владелец»; результат: процент + перцентиль среди personal_mock того курса за 90 дней (кэш 1 ч); Pro feature exam.simulator; одна открытая на learner'а (partial unique как у daily_review)
```

---

## 3. Порядок блокировок (обязателен; расширяет docs/12 §3.4 и docs/06 §4.2)

```
promo_codes → payment_orders → provider_transactions → субъект (learners FOR UPDATE / organizations FOR UPDATE)
            → organization_members → subscriptions → INSERT'ы с FK (payments, promo_redemptions, product_events, audit_logs, referrals, offers)
```

Правила:
1. Любой путь записи биллинга, держа строку субъекта или подписки, **никогда не ждёт** `payment_orders`/`provider_transactions` — заказ блокируется первым или не блокируется вообще (`grantManual`, `revoke`, `expireOne`, `cancel`, `endNow` заказы не трогают).
2. Внешние вызовы (Telegram API, HTTP провайдера) — **вне транзакций**: `createCheckout` после commit заказа; `cancelAutoRenew` до транзакции `cancel()` (если API упал — ничего не меняем; если упала БД после API — продление всё равно не придёт, `expireDue` закроет подписку по концу периода + grace; `billing:reconcile` чинит статус).
3. Места: `OrganizationMembershipService` блокирует `organizations` FOR UPDATE и пишет `organization_members`; `subscriptions` организации **читает** без блокировки (`seats_limit`). Путь подписки организации (`grantManual`/`applyCharge`) блокирует `organizations` → `subscriptions`; `reseat` вызывается после commit отдельной транзакцией. Цикла нет: никто, держа `organization_members`, не ждёт `subscriptions`, и наоборот.
4. Два learner'а в одной транзакции **не блокируются никогда** (рефералы — две транзакции с клеймом; друзья/вызовы — без блокировок learner'ов).
5. Пикер (`FOR NO KEY UPDATE` learner'а) и транзакция оценки (`training_sessions → word_repetitions → learner_daily_stats`) не меняются; лиги, достижения, freeze, рефералы работают **после commit** (`DB::afterCommit`) или по событиям — в транзакцию оценки ничего не добавляется. `training_reviews.exercise/answer_text/scheduler` — колонки той же вставки, не новые блокировки.
6. Проверка — `tests/Feature/Billing/BillingConcurrencyTest` расширяется сценариями: двойная доставка `successful_payment` (fork, PG) → один `payments`; Payme `PerformTransaction` параллельно с `grantManual` того же learner'а → нет deadlock; `CreateTransaction` × 2 для одного заказа → одна транзакция, вторая получает ошибку «заказ занят»; N одновременных `joinByInvite` на последнее место → ровно `seats_limit` seated.

---

## 4. Провайдеры оплаты — потоки

Общее: `payment_orders.amount` — единственная истина; провайдеру передаётся только `token`; суммы из вебхуков сверяются с заказом; все сравнения секретов — `hash_equals`; логи — канал `billing` через `SecretRedactor` (маски `PAYME_KEY`, `PAYME_TEST_KEY`, `CLICK_SECRET_KEY`, заголовок `Authorization`, поле `sign_string`); тела > 64 КБ отвергаются; маршруты `/billing/webhook/*` — без CSRF/сессии (как `/telegram/webhook`), `throttle:billing-webhook` 120/мин per IP, IP-allowlist из config (опционально; адреса Payme/Click — из их кабинетов, `PAYME_IP_ALLOWLIST`, `CLICK_IP_ALLOWLIST`, пусто = выключено).

> Пункты, помеченные **[сверить]**, перед соответствующей задачей проверяются по официальной документации (core.telegram.org/bots/api, developer.help.paycom.uz, docs.click.uz) — сеть из среды architect'а была недоступна (403 proxy).

### 4.1 Telegram Stars

- **Checkout.** `CheckoutService::create(provider=telegram_stars, currency=XTR)` → `createInvoiceLink({title, description, payload: order.token, currency: 'XTR', prices: [{label, amount}], subscription_period: 2592000 (только period=month)})`. **[сверить]**: `subscription_period` допускает только 2592000; максимальная цена подписки в Stars (координатор: ≤ 10 000; в Bot API 7.x было 2 500) — лимит в `config('billing.providers.telegram_stars.max_subscription_price')`, `CheckoutService` отказывает с `price_not_configured`, если цена выше. Годовой Pro через Stars — разовый инвойс без `subscription_period` на 365 дней (оплата периода). TWA открывает ссылку `Telegram.WebApp.openInvoice(url, cb)`, бот — отправляет ссылку кнопкой (или `sendInvoice` тем же payload — реализация на выбор coder'а, контракт одинаков).
- **`pre_checkout_query`** — ответ ≤ 10 с. `WebhookController`: если в апдейте `pre_checkout_query` — **синхронно** `StarsPreCheckoutGuard::check()` → `answerPreCheckoutQuery(ok | error_message)` и 200; всё остальное — в очередь как сейчас. `setWebhook.allowed_updates` += `pre_checkout_query` (и `message` уже покрывает `successful_payment`/`refunded_payment`). Guard читает заказ по `invoice_payload`: `pending`, не истёк, `subscriber = learner с telegram_user_id = from.id`, `blocked_at IS NULL`, `total_amount/currency` = заказ, провайдер включён. Ошибки локализованы на локали learner'а.
- **`successful_payment`** (в `message` приватного чата): `SuccessfulPaymentHandler` — **первый** в `messageHandlers` (до `LearnerDmHandler`), `requiresActiveGroup=false`. `TelegramStarsProvider::parseSuccessfulPayment` → `PaymentApplier::applyCharge`. Поля: `telegram_payment_charge_id` (идемпотентность и charge id), `invoice_payload` (заказ), `total_amount`, `currency`, `subscription_expiration_date` → `current_period_ends_at`, `is_recurring`/`is_first_recurring` → `kind = charge|renewal`. `provider_payment_charge_id` — в `raw`. Ответ learner'у: «Pro до <дата>» + кнопка «Сегодня». Заказ не найден (БД потеряла заказ, а продление пришло): ищем подписку по `meta.stars.charge_id`/`provider_ref` → продление без заказа (`payment_order_id = NULL`, `Log::warning`), иначе `payments.status=failed` + алерт — деньги у нас, Pro не выдан, поддержка возвращает вручную.
- **Продление.** Telegram списывает сам и шлёт `successful_payment` с `is_recurring=true`; наша подписка `active` до `subscription_expiration_date` + grace 3 дня; если продление не пришло — `past_due` → `expired` планировщиком (docs/12 §5.6). **[сверить]**: уведомляет ли Telegram бота об отмене подписки пользователем из настроек Stars; если нет — ежедневный `billing:reconcile-stars` сравнивает `getStarTransactions` с `payments` и логирует расхождения.
- **Отмена/возобновление автопродления.** `POST /api/twa/billing/subscription/cancel|resume` и бот `/pro` → `editUserStarSubscription(user_id, telegram_payment_charge_id, is_canceled)` **вне транзакции**, затем `SubscriptionService::cancel/resume` → `canceled` live до конца периода (§2.1). Событие `subscription.canceled {provider}` / `subscription.resumed`.
- **Возврат.** Суперадмин в Filament `PaymentResource` → `refund` (обязателен `note`): `refundStarPayment(user_id, charge_id)` вне транзакции → `applyRefund` (audit `payment.refunded`, подписка `endNow` если возвращён текущий период). Входящий `message.refunded_payment` (возврат сделал Telegram) → тот же `applyRefund`. **[сверить]** имена полей `refunded_payment`.
- **Требования Telegram:** цифровые товары внутри бота/Mini App — только Stars; команда **`/paysupport`** обязательна — `PaySupportCommandHandler` отвечает текстом (контакт `BILLING_SUPPORT_CONTACT`, правила возврата из оферты, ссылка на `/offer`). Условия вывода Stars (задержка/комиссия) — вопрос владельца (§12).
- **Клиент SDK.** `irazasyed/telegram-bot-sdk ^3.15` может не иметь обёрток `createInvoiceLink` с `subscription_period`, `refundStarPayment`, `editUserStarSubscription`, `answerPreCheckoutQuery` — использовать сырой вызов SDK (`$api->post('<method>', $params)` / `__call`) через существующий `IrazasyedTelegramClient::call()` (токен не утекает); новые методы — в контракт `TelegramClient` (+ `getChatMember` из 2R.2). Без новых зависимостей.

### 4.2 Payme (Merchant API, JSON-RPC 2.0)

- **Checkout.** Ссылка на `checkout.paycom.uz` (`checkout.test.paycom.uz` для sandbox) с base64-параметрами `m` (merchant id), `ac.order_id` (= `token`), `a` (сумма в тийинах), `l` (язык), `c` (return URL — страница `/billing/return?order=<token>` публичного сайта: «оплата получена / ждём подтверждение», без секретов) **[сверить формат]**. Открывается из TWA через `openLink` и из бота кнопкой-URL.
- **Эндпоинт.** `POST /billing/webhook/payme` — один URL для всех методов. Аутентификация: `Authorization: Basic base64("Paycom:" + KEY)`; сравнение `hash_equals` с `PAYME_KEY` (и с `PAYME_TEST_KEY`, если задан — sandbox и прод различаются ключом). Неверно → **HTTP 200** с JSON-RPC ошибкой `-32504` **[сверить: Payme ожидает 200 + error, не 401]**. Парсинг → `-32700`; неизвестный метод → `-32601`; невалидные params → `-32600`. Ответ всегда `{jsonrpc:'2.0', id: <из запроса>, result|error}`; тексты ошибок `{ru, uz, en}`.
- **Суммы** — тийины (`UZS × 100`), `payment_orders.amount` хранится в тийинах. Сверка строгая: `params.amount === order.amount`, иначе `-31001`.
- **Методы** (`PaymeRpcService`):
  - `CheckPerformTransaction {amount, account.order_id}` → заказ по `token`: нет → `-31050` («заказ не найден», код из диапазона `-31050…-31099`); не `pending` или истёк → `-31051`; сумма ≠ → `-31001`; у заказа уже есть транзакция в state 1 → `-31052`; иначе `{allow: true, detail: {receipt_type: 0, items: [{title, price, count: 1, code: <ИКПУ>, package_code, vat_percent}]}}` — фискальные реквизиты из `config('billing.providers.payme.fiscal')` **[сверить обязательность detail; ИКПУ — вопрос владельцу §12]**.
  - `CreateTransaction {id, time, amount, account}` → транзакция: `lockForUpdate payment_orders` → `provider_transactions` по `(payme, id)`: есть → вернуть её текущее состояние (идемпотентно; state 1 → `{create_time, transaction: <наш id>, state: 1}`; иначе `-31008`); нет → повторить проверки `CheckPerformTransaction`; другая транзакция state 1 на этом заказе → `-31052`; `time` старше `transaction_timeout_ms` (43 200 000) → `-31008`; создать state 1, `create_time = params.time`, ответ `{create_time, transaction, state: 1}`.
  - `PerformTransaction {id}` → транзакция не найдена → `-31003`; state 2 → вернуть `{transaction, perform_time, state: 2}` (идемпотентно); state −1/−2 → `-31008`; state 1 и `now − create_time > timeout` → cancel reason 4, state −1, `-31008`; иначе `PaymentApplier::applyCharge(ChargeEvent{providerEventId: id.':perform', providerChargeId: id, orderToken, amount, isRenewal=false, periodEndsAt=null})` в той же транзакции (порядок §3: заказ → provider_transactions → субъект → подписка → payments) → state 2, `perform_time = now(ms)`.
  - `CancelTransaction {id, reason}` → не найдена → `-31003`; state 1 → state −1, `cancel_time`, заказ `canceled`; state 2 → **разрешаем** (оплаченная подписка возвращается): `applyRefund` (`providerEventId: id.':cancel'`), state −2, подписка `endNow(reason=refund)` если период текущий; state −1/−2 → вернуть как есть (идемпотентно). Ответ `{transaction, cancel_time, state}`. Если возврат бизнес-правилом запрещён (например, период использован > 7 дней) — `-31007` (политика возврата — вопрос §12).
  - `CheckTransaction {id}` → `{create_time, perform_time, cancel_time, transaction, state, reason}` из `provider_transactions`; не найдена → `-31003`.
  - `GetStatement {from, to}` → все транзакции с `create_time ∈ [from, to]`: `{transactions: [{id, time, amount, account: {order_id}, create_time, perform_time, cancel_time, transaction, state, reason}]}`.
  - `SetFiscalData` / `ChangePassword` — не реализуем (`-32601`); смена ключа — через env.
- **Тесты:** фикстуры JSON-RPC всех методов в `tests/Fixtures/billing/payme/*.json`; матрица состояний (создать → выполнить → проверить; создать → таймаут; выполнить дважды; отменить до/после; чужой Basic; неверная сумма; заказ истёк); PG fork: `PerformTransaction` × 2 параллельно → один `payments`.

### 4.3 Click (Prepare / Complete)

- **Checkout.** Ссылка `https://my.click.uz/services/pay?service_id={service_id}&merchant_id={merchant_id}&amount={UZS с двумя знаками}&transaction_param={token}&return_url={return}` **[сверить параметры]**.
- **Эндпоинты.** `POST /billing/webhook/click/prepare` и `/complete` (form-encoded). Подпись **[сверить]**: Prepare `sign_string = md5(click_trans_id + service_id + SECRET_KEY + merchant_trans_id + amount + action + sign_time)`; Complete — `… + merchant_trans_id + merchant_prepare_id + amount + action + sign_time`. Сравнение `hash_equals`; `service_id` должен совпадать с config; `amount` (строка UZS с копейками) → тийины `(int) round(amount × 100)`. Ответ всегда HTTP 200 JSON `{click_trans_id, merchant_trans_id, merchant_prepare_id|merchant_confirm_id, error, error_note}`.
- **Коды ошибок (Click):** 0 ok; −1 подпись; −2 сумма; −3 action; −4 уже оплачен; −5 заказ/пользователь не найден; −6 транзакция не найдена; −7 ошибка обновления; −8 ошибка запроса Click; −9 транзакция отменена.
- **Prepare (`action=0`)**: подпись → заказ по `merchant_trans_id` (`token`): нет → −5; не `pending`/истёк → −4 или −5; сумма ≠ → −2; `provider_transactions` upsert `(click, click_trans_id)` state 1 (повтор Prepare → тот же `merchant_prepare_id`); ответ `merchant_prepare_id = provider_transactions.id`.
- **Complete (`action=1`)**: подпись (с `merchant_prepare_id`) → транзакция по `click_trans_id` и `merchant_prepare_id` (нет → −6); если `error < 0` в запросе (Click отменил) → state −1, заказ `canceled`, ответ `error: -9`; state 2 → ответ как есть (`error: 0`, идемпотентно — **[сверить]**: Click при повторе ожидает 0 или −4); иначе `applyCharge(providerEventId: click_trans_id.':complete', providerChargeId: click_trans_id)` → state 2 → `merchant_confirm_id = payments.id`.
- **Возвраты** — инициируются в кабинете Click; уведомления о возврате у Click нет **[сверить]** → суперадмин отмечает возврат вручную в Filament (`applyRefund` с `providerEventId = click_trans_id.':refund:'.<n>`, `note`). 
- **Тесты:** фикстуры form-данных с подписью, рассчитанной тестовым секретом; матрица: подпись неверна; Prepare для чужого/истёкшего/оплаченного заказа; Complete без Prepare; Complete дважды; Complete с `error<0`; гонка Complete × 2.

### 4.4 Оплата периода, продления, напоминания, сверка

- `period`: `month` = 30 дней, `year` = 365 дней (константы в config). Оплата при живой подписке продлевает от `max(конец, now)`; при живой **Stars-подписке с автопродлением** заказ через Payme/Click запрещён (`checkout_conflict`), пока автопродление не отменено — иначе два источника продления; обратное (Stars поверх оплаченного периода) разрешено, конец = `max`.
- `subscriptions:remind-expiring` (ежедневно 10:00 UTC, `SubscriptionService::remindExpiring`): live-подписки `provider ∈ {payme, click}` (без автопродления) и `canceled` любого провайдера с `current_period_ends_at` через 3 и 1 день → `RenewalReminderJob(learnerId|organizationId, daysLeft)`: ЛС learner'у с кнопкой «Продлить» (`/pro`), владельцу организации — ЛС (если `users.telegram_user_id`) и email. Идемпотентность — `notifications_log.type = renewal_reminder` + `meta.days_left` (один раз на (подписка, daysLeft)). Выключено для `manual`/`promo`/`reward`.
- `billing:expire-orders` каждые 15 мин: `pending` с `expires_at <= now` → `expired` (без блокировок субъекта). `billing:reconcile-stars` ежедневно (опционально, 2A.6): `getStarTransactions` постранично за последние 2 дня → отсутствующие в `payments` → `Log::error('billing.reconcile_missing')` (не применяет автоматически — только алерт).
- После истечения — Free; данные не удаляются (docs/11 §6.4). Экран «Сегодня» показывает «Pro закончился <дата> — продлить».

---

## 5. Paywall `purchase`, API и бот

**`config/billing.php` (дополнения):**

```php
'prices' => [ // суммы в минорных единицах (UZS — тийины; XTR — Stars); 0/отсутствие = не продаётся
    'pro' => [
        'month' => ['XTR' => (int) env('BILLING_PRICE_PRO_MONTH_XTR', 0), 'UZS' => (int) env('BILLING_PRICE_PRO_MONTH_UZS', 0)],
        'year'  => ['XTR' => (int) env('BILLING_PRICE_PRO_YEAR_XTR', 0),  'UZS' => (int) env('BILLING_PRICE_PRO_YEAR_UZS', 0)],
    ],
],
'periods' => ['month' => 30, 'year' => 365], // дней
'org_tiers' => [ // организации: plan остаётся 'pro' для учеников, tier задаёт места и цену; Stars не используются
    'tutor'    => ['seats' => 30,   'prices' => ['month' => ['UZS' => (int) env('BILLING_PRICE_TUTOR_MONTH_UZS', 0)]]],
    'center_s' => ['seats' => 100,  'prices' => ['month' => ['UZS' => (int) env('BILLING_PRICE_CENTER_S_MONTH_UZS', 0)]]],
    'center_m' => ['seats' => 300,  'prices' => ['month' => ['UZS' => (int) env('BILLING_PRICE_CENTER_M_MONTH_UZS', 0)]]],
    'center_l' => ['seats' => null, 'prices' => []], // индивидуально: только ручная активация суперадмином с seats_limit
],
'providers' => [
    'telegram_stars' => ['enabled' => (bool) env('BILLING_STARS_ENABLED', false), 'max_subscription_price' => (int) env('BILLING_STARS_MAX_SUBSCRIPTION_PRICE', 10000)],
    'payme' => ['enabled' => (bool) env('PAYME_ENABLED', false), 'merchant_id' => env('PAYME_MERCHANT_ID', ''), 'key' => env('PAYME_KEY', ''), 'test_key' => env('PAYME_TEST_KEY', ''),
                'checkout_url' => env('PAYME_CHECKOUT_URL', 'https://checkout.paycom.uz'), 'ip_allowlist' => env('PAYME_IP_ALLOWLIST', ''), 'transaction_timeout_ms' => 43_200_000,
                'fiscal' => ['ikpu' => env('PAYME_FISCAL_IKPU', ''), 'package_code' => env('PAYME_FISCAL_PACKAGE_CODE', ''), 'vat_percent' => (int) env('PAYME_FISCAL_VAT_PERCENT', 0)]],
    'click' => ['enabled' => (bool) env('CLICK_ENABLED', false), 'merchant_id' => env('CLICK_MERCHANT_ID', ''), 'service_id' => env('CLICK_SERVICE_ID', ''), 'merchant_user_id' => env('CLICK_MERCHANT_USER_ID', ''),
                'secret_key' => env('CLICK_SECRET_KEY', ''), 'checkout_url' => env('CLICK_CHECKOUT_URL', 'https://my.click.uz/services/pay'), 'ip_allowlist' => env('CLICK_IP_ALLOWLIST', '')],
],
'checkout' => ['order_ttl_minutes' => (int) env('BILLING_ORDER_TTL_MINUTES', 1440), 'max_pending_per_subscriber' => 3, 'renewal_reminder_days' => [3, 1]],
'offers' => ['post_org_discount' => ['pct' => (int) env('BILLING_POST_ORG_DISCOUNT_PCT', 30), 'valid_days' => 14, 'period' => 'month']],
'referrals' => ['reward_days' => 7, 'activation' => ['days' => 3, 'reviews_per_day' => 10, 'window_days' => 14], 'max_rewards_per_30d' => 10, 'max_rewards_lifetime' => 50, 'flag_links_per_day' => 20],
'paywall' => ['mode' => env('BILLING_PAYWALL_MODE', 'coming_soon'), ...], // 'purchase' включается владельцем, когда хотя бы один провайдер enabled и цены заданы; EntitlementResolver::paywall() понижает до coming_soon, если продавать нечего
```

`plans.pro.features` += `exercise.spelling`, `exercise.gap_fill`, `exam.simulator`, `decks.extended`; `plans.*.limits` += `decks`, `deck_words`.

**API TWA (`/api/twa/billing/*`, под `TwaAuth`; docs/04 A.8):**

| Метод | Путь | Сервис | Ответ |
|---|---|---|---|
| GET | `/billing/catalog` | `CheckoutService::catalog` | `{plans: [{plan, period, prices: {XTR?, UZS?}, list_prices}], providers: ['telegram_stars','payme','click'], offer: {kind, discount_pct, expires_at}|null, subscription: {status, provider, ends_at, auto_renew, can_cancel, can_resume}|null}` |
| POST | `/billing/checkout` `{plan, period, provider}` (throttle `twa-checkout` 10/мин) | `CheckoutService::create(via: twa)` | `{order: {id, token, status, amount, currency, expires_at}, checkout: {url, kind}}`; ошибки 409 `price_not_configured`/`provider_disabled`/`checkout_conflict`, 422 `provider_not_supported` |
| GET | `/billing/orders/{token}` | заказ владельца | `{status, paid_at, subscription: {...}}` — TWA опрашивает после `openInvoice` (`paid`) / возврата с Payme/Click, не чаще 1 раз/3 с |
| POST | `/billing/subscription/cancel` / `/resume` | провайдер → `SubscriptionService::cancel/resume` | `MeResource` |
| GET | `/me` | — | `entitlement` += `renewal: {auto: bool, provider, ends_at, reminder_sent: bool}` |

`PaywallSheet` в режиме `purchase`: цены из каталога на локали, кнопки «Оплатить Stars» (`openInvoice`), «Payme», «Click» (`openLink`), промокод остаётся; событие `paywall.shown {reason, mode}`; после `paid` — обновление `/me`, `/today`, тост «Pro до <дата>». Новый экран `BillingView` (`/billing`): текущая подписка, история платежей (`GET /billing/payments` — по желанию, 2A.7), кнопки отмены/возобновления.

**Бот:** `/pro` — текст с ценами и inline-кнопками (Stars-инвойс; Payme/Click — URL); `/paysupport` — поддержка/возвраты; `SuccessfulPaymentHandler`; `RenewalReminderJob` сообщения. Тексты в `lang/{ru,uz}/billing.php`, `I18N_UZ_REVIEW.md` дополняется.

**Filament `/admin`:** `PaymentResource` (список, фильтры провайдер/статус, действие Refund для Stars с `note`, «Отметить возврат» для Payme/Click), `PaymentOrderResource` (read-only + «Отменить заказ»), `ProviderTransactionResource` (read-only, журнал), виджет «Выручка 30 дней» по валютам и «Ошибки вебхуков за 24 ч» (из `payments.status=failed` + лог). `SubscriptionResource` «Grant Pro» получает поле `tier` для организаций.

---

## 6. Тарифы для центров (2B)

### 6.1 Места

Определение — §1.3. Лимит берётся из `subscriptions.seats_limit` live-подписки организации (ставится из `org_tiers.<tier>.seats` при `applyCharge`/`grantManual`; для `center_l` — вручную). Нет live-подписки → никто не `seated` (все `waitlisted`), организация без оплаты не спонсирует — как сейчас. Превышение: новые участники `waitlisted` (Free), кабинет показывает «Мест: 100/100, в ожидании: 7 — повысьте тариф», суперадмин видит виджет; **никто не блокируется и ничего не теряет**. Защита B2C от «одного центра на весь город»: жёсткие потолки тарифов, `center_l` только вручную; алерт `Log::warning('organizations.join_burst')` при > `config('organizations.invite_burst_per_day')` (50) вступлений по одному коду за сутки + баннер в админке; суперадмин может `suspended` организацию (уже есть) — все члены теряют спонсорство мгновенно (`forgetOrganization`).

### 6.2 Инвайт и привязка групп

- `/start org_<code>` → `OnboardingService::start` ставит `meta.pending_org_invite = code`, онбординг идёт как обычно; на шаге `done` (или сразу для learner'а с завершённым онбордингом) — подтверждение «Вступить в центр <name>?» (`org1:join:<code>` / `org1:skip`) → `joinByInvite`. Ответ: «Вы в центре X. Pro активен» / «мест пока нет — сообщите администратору». `acquisition_source = 'org'` только у новых learner'ов (first-touch, как сейчас).
- Вступление в Telegram-группу организации → `GroupMembershipService::join` → `joinFromGroup` (joined_via=group); выход из всех групп организации → `onGroupLeft` → `left`. Инвайт-членство живёт до «Покинуть центр» в настройках TWA/`/settings` или удаления в кабинете.
- Привязка групп к организации: суперадмин — как сейчас (+ предупреждение `getChatMember`, 2R.2 выполняется); владелец центра из кабинета — «Привязать группу»: вводит `chat_id`/выбирает группу, где бот активен **и** `getChatMember(chat, owner.telegram_user_id)` ∈ {creator, administrator} (иначе отказ — чужую группу привязать нельзя); аудит `organization.linked_group` с `by`.

### 6.3 Кабинет — Filament-панель `/org`

Выбор: **Filament** (не TWA). Обоснование: отчёты, таблицы, экспорт CSV, формы заданий и приглашений — всё готовое; логин/пароль + 2FA и `RequireTwoFactor` уже есть; `PanelDataScope`-подход проверен тестами. TWA-кабинет потребовал бы десятков экранов на Vue и смешивал бы learner-идентичность с ролью персонала. Телефон-ориентированность не критична: администратор центра работает с ноутбука. Компромисс: в бот добавляется `/org` для владельца с ссылкой на кабинет и краткой сводкой (мест/активных за неделю).

Устройство:
- `OrgPanelProvider` (`id('org')->path('org')`), **tenancy** `->tenant(Organization::class, slugAttribute: 'slug')`, `User::getTenants()` — организации из `organization_users`, `canAccessTenant()` — наличие строки; `->tenantMiddleware`; ресурсы — только из `app/Filament/Org/**`; `canAccessPanel('org')` — есть хотя бы одна организация. Каждая модель кабинета scoping'уется через `ownershipRelationship`/`BelongsToTenant`-подобный scope `organization_id = tenant`; `TelegramGroup` (прямой `organization_id`), `OrganizationMember`, `OrganizationInvite`, `Assignment`, `Learner` (через `members`), `User`-учителя (через `organization_users`).
- **Второй рубеж — политики**: `OrgPolicies` для всех моделей кабинета проверяют `organization_id === Filament::getTenant()->id` (`viewAny/view/update/delete`), для `Learner` — наличие активного членства в tenant'е; роль `teacher` — только чтение отчётов по своим группам (`teacher_groups`) и создание заданий в них; `owner` — всё, включая биллинг и персонал. Тесты изоляции обязательны: пользователь организации A по URL с `slug` организации B → 404/403; прямой `GET` на страницу learner'а чужой организации → 404; учитель не видит биллинг; суперадмин `/admin` видит всё как раньше.
- Экраны: Dashboard (места, активных за 7 дней, задания с дедлайном, статус подписки), Members (seat_state, активность, «удалить», «пересадить из ожидания» вручную), Groups (привязка/отвязка, учителя группы, предупреждение «бот не админ»), Invites (создать/деактивировать/ссылка/QR — QR без зависимостей не делаем, только ссылка), Teachers (пригласить по email, привязать к группам, указать `telegram_user_id`), Assignments (CRUD, прогресс), Reports (фильтры период/группа, CSV), Billing (тариф, места, оплата Payme/Click, запрос счёта, история платежей), Settings (название, часовой пояс, язык).
- Пароли/почта: `MAIL_*` уже в конфиге; приглашение учителя — письмо со ссылкой сброса пароля (стандартный `Password::broker`). 2FA обязательна для `owner` (middleware как в `/admin`), для `teacher` — по желанию.

### 6.4 Задания и отчёт

§1.4 и `AssignmentService`/`OrgActivityReport` (§2.3). Объявление в группу: «Задание: урок 3.2 до 25 окт» + web_app-кнопка `/twa/?from=assignment&lesson=<id>` → `TodayView` открывает `POST /training/sessions {mode: lesson, lesson_id}` (существующий путь). Для learner'а в TWA — блок «Задания центра» на «Сегодня» (`GET /api/twa/assignments` → активные задания его групп с прогрессом). Напоминание за 24 ч — в группу одним сообщением (не в ЛС каждому). Отчёт — таблица по learner'ам: оценки, новые слова, дней активности, streak, выполнение заданий, последний визит; уровни — group/organization; период — 7/30 дней/произвольный; CSV. Личные сессии ученика учителю не показываются по отдельности (приватность docs/12 §4.4) — только агрегаты `learner_daily_stats`.

### 6.5 Оплата центра и мост в B2C

`OrgBillingService` + `CheckoutService(subscriber=organization, tier)`: кабинет показывает тарифы из `org_tiers`, кнопки Payme/Click (создают заказ `created_via=org_panel`), «Запросить счёт» (письмо суперадмину/владельцу платформы + страница счёта HTML для печати с реквизитами из `config('site.legal')`), ручная активация — `grantManual(org, Pro, until, tier)` суперадмином (audit). Напоминания о продлении — §4.4. Потеря места (`leave` seated без личной live-подписки) → `OfferService::createPostOrgDiscount` → ЛС: «Вы покинули центр X. Личный Pro со скидкой 30 % на первый месяц до <дата>» + кнопки оплаты; скидка применяется только в заказе этого learner'а; offer одноразовый.

---

## 7. Волна B — рост и удержание (2C)

### 7.1 Рефералка
Схема §1.5, сервис §2.4. Ссылка `t.me/<bot>?start=ref_<referral_code>` (TWA «Пригласить друга» → `shareUrl`), `StartPayload::Ref` → `[A-Z0-9]{6,12}`, числовой `ref_<id>` **удаляется** (прода нет). Награда обоим — 7 дней Pro (`provider=reward`, продление live личной как у промо; бессрочная — пропуск награды с записью в meta). Что с `acquisition_source` у learner'а, созданного раньше: не меняется (first-touch), реферал `rejected {existing_learner}` — считаем только новых. События `referral.linked/activated/rewarded/rejected`. Зависимость от A: нет (награда — через существующий `SubscriptionService`).

### 7.2 Streak freeze (Pro)
§2.4. Инвентарь +1 в понедельник (локальная неделя) до 2; потребление автоматическое при пропуске ровно одного дня; `/today.streak` += `{freezes: n, frozen_yesterday: bool}`; событие `streak.frozen`. Free видит «заморозки — в Pro» (мотив покупки — **зависит от A** по смыслу, не по коду).

### 7.3 Лиги
§1.5, §2.4. Неделя — понедельник–воскресенье в `config('leagues.timezone')` (`Asia/Tashkent`): все пользователи региона, одна граница. Вступление — автоматически при первой оценке недели (можно отключить в настройках: `learners.meta.leagues_opt_out`). Очки — формула из `learner_daily_stats`, без записи в горячем пути; standings — кэш 60 с; закрытие — идемпотентный job. TWA `LeagueView`: моя группа, место, очки, до повышения; «Сегодня» — короткая строка. Сообщение о результате — ЛС через глобальный лимит исходящих (2R.3).

### 7.4 Шеринг достижений
`achievements` + `AchievementCardRenderer` (GD, шрифт OFL в `resources/fonts/`, кириллица + узбекская латиница; 1080×1080 PNG, кэш по ключу). Бот отправляет `sendPhoto` с подписью и кнопкой «Поделиться» → `switch_inline_query_chosen_chat` (без inline-режима — просто текст «перешлите это сообщение») **[сверить `shareMessage`/`savePreparedInlineMessage` Bot API 8.0 для TWA]**. Событие `achievement.unlocked/shared`.

### 7.5 Друзья и вызовы
§1.5. Добавление — по реферальной ссылке (`ref_` + уже существующий learner → предложение «добавить в друзья», а не реферал) или `f_<referral_code>`; список друзей со streak/выученными словами; вызов «кто больше повторит за 7 дней» — счёт из `learner_daily_stats` при закрытии. Низкий приоритет внутри волны.

---

## 8. Волна C — обучение (2D)

### 8.1 Свои колоды — §2.5 `LearnerDeckService`
Лимиты Free 1 колода × 50 слов, Pro 20 × 2000 (config); ввод списком/CSV в TWA (`MyDecksView`, `DeckEditView`), валидация и очистка на сервере, без модерации (private); публикация колоды публично — Фаза 3. Колода доступна как курс: выбор `active_course`, `daily_review` берёт новые слова из неё, квота Free применяется как обычно. Удаление колоды → `words.is_active=false` (правило docs/03 §8) и `courses.status=archived`.

### 8.2 Типы упражнений и `audio_url`
§2.5: `TrainingStep.prompt`/`audioUrl`, `ExerciseSelector`, `SpellingGrader`, `GapFillBuilder`; `POST /review` принимает `quality` **или** `answer` (взаимоисключающе; `answer` только для `serverGraded()` упражнений текущей карточки — сервер хранит ожидаемый тип в `training_sessions.meta.current_exercise` или пересчитывает детерминированно по тем же входам; выбранный путь — детерминированный пересчёт, без состояния). Reverse/audio — Free; spelling/gap-fill — Pro (`features`). Настройки learner'а: `exercises: {reverse, audio, spelling, gap_fill}` в `PATCH /me/settings`.

### 8.3 FSRS как A/B
§2.5. `config('learning.fsrs.rollout_percent')` (0 на старте), `learners.scheduler` фиксируется при создании; суперадмин может переключить learner'а в Filament (аудит). `training_reviews.scheduler` + виджет сравнения; docs/06 §9 → полноценный раздел FSRS (формулы, маппинг оценок, инициализация из SM-2). Откат — `rollout_percent = 0` и `scheduler = sm2` массово: SM-2 поля не трогались (FSRS пишет `interval_days`/`next_review_at` тоже, EF не меняет).

### 8.4 Симулятор IELTS/SAT vocab (Pro)
§1.6 обобщение `exam_sessions`, §2.5 `MockExamService`; TWA `ExamView` переиспользуется (таймер есть — `useTimer`); вход — с страницы курса `exam_tag ∈ {ielts, sat}` кнопка «Пробный тест» (Pro, иначе paywall `reason: exam.simulator`). Политика `ExamSessionPolicy`: `personal_mock` — только владелец.

---

## 9. Хвосты Фазы 1 (2R)

| # | Хвост | Решение | Кто |
|---|---|---|---|
| 2R.1 | Локаль TWA-запросов по learner'у (`APP_LOCALE=ru` в валидации) | **выполняется сейчас (coder)**; в этом документе не перепроектируется | coder |
| 2R.2 | `getChatMember`-предупреждение при привязке группы | **выполняется сейчас (coder)**; `TelegramClient::getChatMember` затем переиспользуется в 2B.2 | coder |
| 2R.3 | Глобальный лимит исходящих Telegram (> ~21 тыс. напоминаний за 15 мин) | `TelegramMessageRateLimiter::reserveGlobal()` — токен-бакет `telegram:outbound:global:{sec}` (Redis `INCR` + TTL 2 с, лимит `TELEGRAM_OUTBOUND_GLOBAL_PER_SECOND` 25), используется всеми исходящими job'ами (напоминания, лиги, задания, продления): нет слота → `release(delay)`; `ReminderDispatcher` ограничивает клеймы за запуск `send_rate × 840` (остальные due остаются на следующий запуск, `max_delay_minutes` защищает от устаревших) | coder (после 2A.3, т.к. меняет `TelegramClient`) |
| 2R.4 | `audio_url` через DTO `TrainingStep` | в 2D.2 (`TrainingStep.audioUrl`) | coder |
| 2R.5 | `referral_code` вместо `ref_<id>` | в 2C.1 | architect → coder |
| 2R.6 | `canceled` как live | в 2A.1 (чек-лист §2.1) | architect |
| 2R.7 | Отмена ошибочной смены часового пояса | `LearnerSettingsService::revertTimezone()`: в течение 10 мин после смены вернуть прежний пояс **и прежний снимок** `meta.local_day_carry`/`timezone_changed_at` (сохраняются в `meta.timezone_change_undo`), без штрафа 24 ч; окно квоты становится ровно таким, каким было бы без смены (начало переноса не двигается вперёд — инвариант docs/12 §5.3 сохраняется). Низкий приоритет | coder, тест квоты — по шаблону `NewWordQuotaTimezoneTest` |
| 2R.8 | Staging 0.7 (restore, ZAP, k6, Lighthouse) | инфраструктура — владелец/DevOps; k6-сценарии дополняются `/billing/catalog`, вебхуком Payme (фикстуры) | владелец / DevOps |
| 2R.9 | Маски секретов провайдеров в `SecretRedactor` | в 2A.4/2A.5 | coder |

---

## 10. Безопасность Фазы 2 (дополнение docs/07)

| Угроза | Мера |
|---|---|
| Подмена суммы/плана клиентом | Сумма только из `payment_orders` (сервер); провайдеры получают `token`; вебхук сверяет `amount/currency` с заказом; несовпадение → `payments.status=failed`, подписка не меняется, `Log::error` |
| Поддельный вебхук | Payme — Basic `hash_equals` + опциональный IP-allowlist; Click — `md5` подпись `hash_equals` + `service_id`; Stars — существующий secret-token webhook; все — throttle 120/мин per IP, тело ≤ 64 КБ |
| Replay / двойная доставка | `payments (provider, provider_event_id)` unique + блокировка заказа; `provider_transactions` — машина состояний (повтор возвращает текущее состояние) |
| Перебор заказов | `token` 32 случайных символа; `GET /billing/orders/{token}` — только владелец (JWT) |
| Доступ к чужой организации | Filament tenancy + политики на каждой модели кабинета + тесты изоляции; привязка группы владельцем — проверка `getChatMember` на права владельца в чате |
| Перепродажа мест | жёсткие `seats_limit` по тарифам, `center_l` вручную, алерт на всплеск вступлений, `suspended` снимает Pro мгновенно |
| Фарм рефералов | награда после активации (3 дня × 10 оценок), только новые learner'ы, лимиты и `flagged_at`, коды непредсказуемы |
| Накрутка лиг/вызовов | очки — из серверных `learner_daily_stats`; `max_cards_per_session` и квота остаются; аномалии (> 2000 оценок/день) — исключение из рейтинга недели (config) |
| Клиентская оценка spelling/gap-fill | оценивает сервер по `answer` |
| Пользовательский контент колод | private, очистка/лимиты, без публикации |
| Секреты в логах | `SecretRedactor` + маски `PAYME_*`, `CLICK_*`, `Authorization`, `sign_string`; `provider_transactions.raw` пишется после редактирования |
| Фрод возвратов | возврат Stars — только суперадмин с `note` + audit; Payme/Click — по их вебхуку/вручную с audit |

Чек-лист к пен-тесту (docs/07 §14) дополняется: вебхук Payme с чужим ключом → `-32504`; `PerformTransaction` дважды → один платёж; Click с неверной подписью → `-1`; чужой `GET /billing/orders/{token}` → 404; пользователь организации A на URL организации B → 404; `answer` для flash-карточки → 422; 31-й learner по инвайту Tutor → `waitlisted`, Pro не выдан.

---

## 11. Нарезка на задачи

Обозначения как в docs/12 §9: **A** — architect (ядро + тесты), **C** — coder, **A→C** — ядро architect, обвязка coder. ‖ — параллельно с предыдущей. Для всех: pint, Larastan 8, Pest (sqlite + PG), vue-tsc/vitest/build при изменении TWA; docs обновляются **до** кода задачи (§14).

### Волна A — деньги

| # | Задача | Исп. | Зависит | Что делать | DoD |
|---|---|---|---|---|---|
| 2A.1 | **Ядро биллинга: `canceled` live, схема оплаты, `CheckoutService`, `PaymentApplier`** | **A** | — | Чек-лист `canceled` (§2.1) одним изменением; `SubscriptionProvider` (`Reward`, `hasGrace` только Stars); правка `2026_04_23_100017` (`tier`, предикат); миграции `offers`, `payment_orders`, `provider_transactions`, `payments` + модели/factories; `BillingProvider`, DTO, `BillingProviderRegistry`, `FakeBillingProvider`; `CheckoutService`, `PaymentApplier::applyCharge/applyRefund`, `SubscriptionService::applyCharge/cancel/resume/endNow/grantReward/remindExpiring` (без job'а отправки), `OfferService`; `config/billing.php` §5; docs/03 §1.2, docs/12 §5.1 помечен «выполнено в 2A.1» | `BillingPlanTest`/`EntitlementResolverTest`/`SubscriptionServiceTest`/`PaidSubscriptionQueryTest` на новом правиле (`canceled` live до конца периода, без grace; матрица × `reward`); `PaymentApplierTest`: повтор события → без побочных эффектов; сумма ≠ → failed, подписка не тронута; продление от `max(конец, now)`; Stars поверх Payme-периода → `max`; Payme при живой Stars → `checkout_conflict`; refund текущего периода → `expired`; offer применяется один раз; `BillingConcurrencyTest` (PG fork): двойная доставка, `applyCharge` ∥ `grantManual`, два `CreateTransaction`; `DatabaseSchemaTest` новые таблицы/уникальности/`down()` |
| 2A.2 ‖ | **Checkout API, paywall `purchase`, бот `/pro`, `/paysupport`** | C | 2A.1 | Маршруты §5 (`BillingController`, FormRequest'ы, `BillingCatalogResource`, `CheckoutResource`, `OrderStatusResource`), `MeResource.entitlement.renewal`; TWA `PaywallSheet` purchase, `BillingView`, стор `billing`, `openInvoice`/`openLink`, опрос заказа; бот `/pro`, `/paysupport`, `lang/billing.php` ru/uz, `I18N_UZ_REVIEW.md`; `billing:expire-orders` в планировщике; docs/04 A.8, B.3 | `TwaContractTest` новые эндпоинты; `FakeBillingProvider` в feature-тестах: checkout → заказ pending → `emitCharge` → `/me` Pro; 409-коды; throttle 429; vitest paywall/BillingView; `coming_soon` при отсутствии провайдеров |
| 2A.3 | **Telegram Stars** | **A→C** | 2A.1 | A: `TelegramStarsProvider`, `StarsPreCheckoutGuard` + синхронная ветка в `WebhookController`, `SuccessfulPaymentHandler` (первым в dispatcher), `refunded_payment`, `TelegramClient` методы (`createInvoiceLink`, `answerPreCheckoutQuery`, `editUserStarSubscription`, `refundStarPayment`, `getStarTransactions`) через сырой вызов SDK, `allowed_updates` + `pre_checkout_query`; C: тексты ответов, `PaymentResource` Refund, `billing:reconcile-stars`, docs/04 B.11, docs/07 §2 | A: фикстуры `pre_checkout_query`/`successful_payment` (first/recurring/year); guard: чужой `from.id` → `ok=false`; истёкший заказ → `ok=false`; webhook с `pre_checkout_query` отвечает синхронно (очередь не используется — `Queue::fake` + assertion); повтор `successful_payment` → один платёж; продление ставит `current_period_ends_at = subscription_expiration_date`; cancel → `canceled` live; resume → `active`; `refundStarPayment` вызывается вне транзакции (порядок вызовов в тесте); C: Filament-тест refund с аудитом |
| 2A.4 ‖ | **Payme** | **A→C** | 2A.1 | A: `PaymeProvider`, `PaymeRpcService` (6 методов §4.2, коды ошибок, таймаут, идемпотентность состояний), `PaymeAuth` (Basic, `hash_equals`, test/prod ключ), контроллер JSON-RPC; C: маршрут без CSRF + throttle + `BillingIpAllowlist` middleware (общий с Click), env/config/`.env.example`/`EnvExampleTest`, `SecretRedactor` маски, `ProviderTransactionResource`, страница `/billing/return`, DEVOPS_GUIDE (регистрация URL в кабинете Payme, sandbox) | A: фикстуры всех методов; матрица состояний §4.2; неверный Basic → 200 + `-32504`; сумма ≠ → `-31001`; второй `CreateTransaction` на занятый заказ → `-31052`; таймаут → `-31008` и state −1 reason 4; `GetStatement` возвращает созданные; PG fork `PerformTransaction` × 2 → один платёж; C: `SecretRedactionTest` на ключи, allowlist-тест |
| 2A.5 ‖ | **Click** | **A→C** | 2A.1 | A: `ClickProvider`, `ClickMerchantService::prepare/complete`, подпись, коды ошибок; C: маршруты, config/env, redactor, допуск IP, ручная отметка возврата в Filament, DEVOPS_GUIDE | A: фикстуры с подписью тестовым секретом; неверная подпись → −1; Prepare чужого/оплаченного/истёкшего → −5/−4; Complete без Prepare → −6; Complete `error<0` → state −1 и заказ `canceled`; Complete × 2 → один платёж; суммы с копейками → тийины точно |
| 2A.6 | **Оплата периода: напоминания о продлении, job'ы** | C | 2A.2, 2A.4 или 2A.5 | `RenewalReminderJob` (ЛС learner'у / владельцу организации + email), планировщик `subscriptions:remind-expiring`, `notifications_log.type=renewal_reminder`, экран «Pro закончился — продлить»; `billing:reconcile-stars` (если не сделан в 2A.3) | напоминания ровно по одному разу на (подписка, 3/1 день); не шлются для `manual/promo/reward` и для Stars `active` (автопродление); `canceled` Stars — шлются; `dm_blocked_at` учитывается |
| 2A.7 ‖ | **Админка оплаты и документация** | C | 2A.1 | `PaymentResource`, `PaymentOrderResource`, `ProviderTransactionResource` (если не сделаны в 2A.3–2A.5), виджеты «Выручка 30 дней» (по валютам) и «Ошибки вебхуков 24 ч»; `SubscriptionResource` Grant с `tier`; ADMIN_GUIDE, DEVOPS_GUIDE (env, URL вебхуков, sandbox-ключи, что проверять после деплоя) | Filament-тесты: учитель не видит платежи; refund требует `note`; виджеты считают по `payments.status=succeeded` минус `refunded` |
| 2A.8 | **Ревью волны 2A, smoke с sandbox** | reviewer / координатор | все 2A | Полное ревью diff'а; smoke на демо-стенде с тестовыми ключами Payme/Click и тестовым Stars-инвойсом (вручную владельцем) | pint/phpstan/pest зелёные; блокирующих нет; `paywall.mode=purchase` на стенде |

### Волна A — центры

| # | Задача | Исп. | Зависит | Что делать | DoD |
|---|---|---|---|---|---|
| 2B.1 | **Членство и места: схема, `OrganizationMembershipService`, спонсорство по местам** | **A** | — (2A.1 — только для `tier` в `grantManual`; можно параллельно с общей правкой миграции `subscriptions`) | Миграции `organization_users`, `organization_invites`, `organization_members` + модели/factories; `OrganizationMembershipService` (join/leave/reseat, FIFO, блокировка `organizations`), `OrganizationInviteService`; хуки `GroupMembershipService::join/leave`; `EntitlementResolver` на `organization_members (seated)`, `forgetOrganization`, `PaidSubscriptionQuery::sponsoredLearnerIds`, `OrganizationSeatsTableWidget`; `organizations:sync-members`; `OrgTier` enum + `config('billing.org_tiers')`; `grantManual(tier)` → `seats_limit`; docs/03 §1.3, docs/12 §1.8/§5.1 помечены «изменено в 2B.1» | `OrganizationMembershipServiceTest`: инвайт → seated; 31-й в Tutor → waitlisted и Free; leave seated → первый waitlisted seated и его кэш сброшен; suspended → Free; группа организации → членство `group`; выход из всех групп → left, членство по инвайту остаётся; `EntitlementResolverTest` на новом правиле (участник группы без seat → Free); `PaidSubscriptionQueryTest` синхронна; PG fork: N `joinByInvite` на последнее место → ровно лимит; `BillingConcurrencyTest`: `joinByInvite` ∥ `grantManual(org)` без deadlock |
| 2B.2 ‖ | **Инвайт-ссылка и бот** | C | 2B.1 | `StartPayload::Org` → `meta.pending_org_invite`, подтверждение `org1:join/skip`, тексты ru/uz; «Покинуть центр» в `/settings` и `SettingsView`; суперадмин: `OrganizationResource` → relation manager Invites и Members (seat_state, remove); `getChatMember` из 2R.2 переиспользуется в 2B.3; события `org.member_joined/left/waitlisted`; docs/04 B.3, docs/12 §7.2 | `OnboardingFlowTest`: `/start org_X` → онбординг → подтверждение → членство; плохой код → обычный онбординг; повтор идемпотентен; `exhausted/expired` → сообщение; Filament-тесты |
| 2B.3 | **Кабинет `/org`: панель, tenancy, политики, экраны** | **A→C** | 2B.1 | A: `OrgPanelProvider` с tenancy, `organization_users` в `User::getTenants/canAccessTenant`, базовый `OrgTenantScope`/политики для всех моделей кабинета, тесты изоляции; C: ресурсы Members/Groups/Invites/Teachers/Assignments(заглушка до 2B.4)/Reports(заглушка)/Billing(заглушка)/Settings, Dashboard, приглашение учителя по email, привязка группы владельцем с `getChatMember`, бот `/org`; TEACHER_GUIDE → раздел «Кабинет центра» | A: пользователь A на `/org/<slug B>/…` → 404; прямой URL записи чужой организации → 404; teacher не видит Billing/Teachers и чужие группы; owner без 2FA → редирект на setup; суперадмин `/admin` не затронут; C: Filament-тесты CRUD в рамках tenant'а, письмо приглашения (`Mail::fake`) |
| 2B.4 ‖ | **Задания** | C | 2B.3 | Миграции `assignments`, `assignment_results`; `AssignmentService` (create/close/announce/remindDue/refreshResults); ресурс в `/org`; `assignments:refresh` (30 мин), напоминание за 24 ч в группу; `GET /api/twa/assignments` + блок на «Сегодня», переход на урок; события `assignment.created/completed`; docs/04 A.9 | прогресс считается по `word_repetitions` только по активным словам; `completed_at` ставится один раз; просроченное — помечено; объявление уходит один раз (`announced_at`); учитель видит только задания своих групп |
| 2B.5 ‖ | **Отчёт об активности** | C | 2B.3 | `OrgActivityReport` (perLearner/perGroup из `learner_daily_stats` + задания), страница Reports с фильтрами и CSV-экспортом (Filament export), еженедельная сводка владельцу (бот/email, опционально) | числа совпадают с `learner_daily_stats` (тест на фикстурах); learner чужой организации не попадает в отчёт; личные сессии не раскрываются; CSV в UTF-8 с BOM |
| 2B.6 | **Оплата центра** | C | 2A.1, 2A.4/2A.5, 2B.3 | `OrgBillingService`, страница Billing (тариф, места, кнопки Payme/Click, «Запросить счёт», история), счёт HTML (`/org/.../invoice/{order}`), ручная активация с `tier` (из 2A.7), напоминания владельцу (из 2A.6), `reseat` после оплаты повышения; docs/04 | заказ организации → `applyCharge` → `seats_limit` из тарифа, `reseat` сажает waitlisted; смена тарифа вниз никого не снимает, виджет «превышение»; `center_l` без цены не покупается (только вручную); Stars для организации → 422 |
| 2B.7 ‖ | **Мост B2B → B2C (offer со скидкой)** | C | 2A.2, 2B.1 | `OfferService::createPostOrgDiscount` из `leave()` (afterCommit), ЛС с предложением, offer в `/billing/catalog`, применение в checkout; Filament: список offers у learner'а | offer создаётся только при потере seated места и без личной live-подписки; одноразовый; истёкший не применяется; сумма заказа = цена − 30 % (округление), вебхук сверяет именно её |
| 2B.8 | **Ревью волны 2B** | reviewer | все 2B | Приоритет — изоляция tenant'ов и места | блокирующих нет |

### Волна B — рост и удержание (2C)

| # | Задача | Исп. | Зависит | Что делать | DoD (кратко) |
|---|---|---|---|---|---|
| 2C.1 | **Рефералка** | **A→C** | — (Pro-награда через существующий `SubscriptionService`; 2A.1 даёт `Reward`-провайдер — иначе добавить в этой задаче) | A: `referrals`, `referral_code` (2R.5), `ReferralService` (link/evaluate/reward с клеймом и двумя транзакциями, лимиты, флаг), `StartPayload` без `ref_<id>`; C: TWA «Пригласить друга» (`shareUrl`), экран рефералов, бот `/invite`, тексты, Filament-колонка «рефералы» + снятие флага; события | самореферал/существующий learner → rejected; активация только при 3 днях × 10 оценок за 14 дней; награда ровно один раз каждому (повтор job'а идемпотентен); лимит referrer'а; флаг при всплеске |
| 2C.2 | **Streak freeze (Pro)** | **A→C** | — | A: `frozen_at`, `streak_freezes*`, `StreakFreezeService`, правка `StreakService::apply/current` (день удержан при `goal_met_at` или `frozen_at`), пересчёт по фактам учитывает `frozen_at`; C: `/today.streak.freezes`, UI, тексты | заморозка тратится только при пропуске ровно одного дня, только у Pro, не чаще одного раза за дату; Free — нет; refill по понедельникам до 2; не вызывается внутри транзакции оценки (тест на порядок) |
| 2C.3 | **Лиги** | **A→C** | 2R.3 (рассылка итогов) | A: таблицы, `LeagueService` (place с `SKIP LOCKED`, standings из `learner_daily_stats` + кэш, closeWeek идемпотентный), веса/tz в `config/leagues.php`; C: `GET /api/twa/league`, `LeagueView`, строка на «Сегодня», opt-out, сообщения итогов, Filament-виджет | гонка размещения (fork) → группы ≤ 30; очки совпадают с суммой по `learner_daily_stats`; закрытие дважды → один результат; повышение/понижение по правилам; learner без активности не размещается |
| 2C.4 ‖ | **Достижения и шеринг** | C | — (шрифт — вопрос §12) | `achievements`, `AchievementService` (слушатели после commit), `AchievementCardRenderer` (GD), бот `sendPhoto` + «поделиться», TWA список достижений; `TelegramClient::sendPhoto` | ключи выдаются один раз; картинка рендерится для ru/uz (тест на размер/формат файла); без новых composer-пакетов |
| 2C.5 | **Друзья и вызовы** | C | 2C.1 | `friendships`, `challenges`, сервисы, API, TWA экран «Друзья», бот-уведомления | нормализованная пара unique; вызов закрывается по `learner_daily_stats`; удаление из друзей закрывает активные вызовы |

**Зависимость волны B от A:** по коду — только 2C.1 (провайдер `reward` из 2A.1) и 2C.3 (2R.3); по смыслу — streak freeze и лиги как мотив покупки Pro работают только при включённом paywall `purchase`.

### Волна C — обучение (2D)

| # | Задача | Исп. | Зависит | Что делать | DoD (кратко) |
|---|---|---|---|---|---|
| 2D.1 | **Свои колоды** | C (+A-консультация по лимитам/очистке) | — | `LearnerDeckService`, `DeckImportParser`, `courses.owner_type=learner`/`source`, доступность private владельцу, лимиты в `plans.*.limits`, API `/decks*`, TWA `MyDecksView`/`DeckEditView`; docs/04 A.10 | Free 1×50 / Pro 20×2000; CSV/список парсятся, мусор отсекается; чужая колода → 404; удаление архивирует, прогресс сохраняется; колода участвует в `daily_review` и квоте |
| 2D.2 | **Типы упражнений + `audio_url`** | **A→C** | — (2R.4 закрывается здесь) | A: `ExerciseType`, `ExercisePrompt`, `TrainingStep.prompt/audioUrl`, `ExerciseSelector` (детерминированный), `SpellingGrader` (OSA, порог от длины), `GapFillBuilder`, `ReviewSubmissionService` с `answer`, колонки `training_reviews`; C: `TrainingNextResource`, TWA компоненты Reverse/Audio/Spelling/GapFill, настройки упражнений, тексты; docs/04 A.3, docs/06 §4.3 | unit: расстояния/пороги/нормализация (регистр, апострофы, пробелы), gap-fill формы слова; feature: `answer` для flash → 422; spelling у Free → недоступно (paywall-reason); сервер ставит quality; `audio_url` отдаётся при `audio_path` |
| 2D.3 | **FSRS A/B** | **A** (+C виджет) | 2D.2 (колонка `scheduler` в `training_reviews`) | `SchedulerAlgorithm`, `Sm2Scheduler`, `FsrsScheduler` (FSRS-5, дефолтные веса, общие learning steps), `SchedulerResolver`, бакет в `LearnerRegistrar`, поля `word_repetitions`, `config/learning.php` `fsrs.*`, инициализация из SM-2, Filament-переключение learner'а с аудитом, виджет метрик; docs/06 новый §FSRS | unit: детерминированные значения интервалов на эталонных последовательностях; SM-2 путь не изменился (существующие тесты зелёные); бакет стабилен; переключение learner'а инициализирует stability/difficulty; cap `max_interval_days`; метрики считаются по `scheduler` |
| 2D.4 | **Симулятор IELTS/SAT (Pro)** | **A→C** | — | A: обобщение `exam_sessions` (`mode`, `learner_id`, `course_id`, nullable FK, CHECK), `MockExamService`, `ExamQuestionBuilder::forWords`, политика владельца, перцентиль; C: кнопка на странице курса, TWA вход/результат, тексты | Free → paywall `exam.simulator`; одна открытая на learner'а; групповой экзамен не изменился (тесты зелёные); результат и перцентиль детерминированы на фикстуре; таймер закрывает сессию |
| 2D.5 | **Ревью волны C** | reviewer | все 2D | | |

### Порядок внутри спринта
2A.1 → {2A.2 ‖ 2A.3 ‖ 2A.4 ‖ 2A.5 ‖ 2A.7} → 2A.6 → 2A.8; параллельно 2B.1 → {2B.2 ‖ 2B.3} → {2B.4 ‖ 2B.5} → 2B.6 (ждёт 2A.4/2A.5) → 2B.7 → 2B.8; 2R.3 после 2A.3; волна B после 2A.8 (или параллельно силами второго coder'а: 2C.2, 2C.4 независимы); волна C — после B или параллельно 2D.1/2D.2.

---

## 12. Вопросы владельцу

| # | Вопрос | Варианты | Рекомендация |
|---|---|---|---|
| 1 | **Цены** (UZS и Stars раздельно; месяц/год; тарифы центров) | задать в env `BILLING_PRICE_*` | Ориентиры: Pro 29 000 UZS/мес и 249 000/год (≈ −30 %); 150 Stars/мес (≈ $2–3 при текущем курсе Stars), год — 1 300 Stars; Tutor 129 000, Center S 349 000, Center M 849 000 UZS/мес; L — вручную. Код не зависит от значений |
| 2 | **Мерчант-аккаунты Payme и Click** | нужны юрлицо/ИП, договоры, `merchant_id`/`service_id`/ключи (test и prod), URL вебхуков в кабинетах; **ИКПУ/коды упаковки** для фискального чека Payme; политика возврата | начать оформление параллельно с 2A.1 — без ключей 2A.4/2A.5 тестируются только фикстурами |
| 3 | Кабинет центра: Filament `/org` или TWA | §6.3 | **Filament `/org`** с tenancy |
| 4 | Что считать местом | (a) явная запись `organization_members` seated, занимается при вступлении, освобождается при выходе/удалении **(рекомендуется)**; (b) активные за 30 дней — «плавающий» лимит, легко злоупотребить ротацией; (c) лимит на группу — не отражает размер центра | (a) |
| 5 | Что происходит при превышении мест | (a) новые — `waitlisted`, Free, без блокировки **(рекомендуется)**; (b) запрет вступления | (a) |
| 6 | Stars: подписка (автопродление, 30 дней) и/или разовые инвойсы (год) | обе формы — §4.1 | обе; проверить актуальный потолок цены подписки в Stars и условия вывода |
| 7 | Политика возвратов (для `/paysupport`, оферты и `CancelTransaction` после оплаты) | например, полный возврат в течение 7 дней без использования Pro; далее — только через поддержку | зафиксировать текст; код: Payme `-31007` при отказе |
| 8 | Скидка бывшему ученику центра | 30 % на первый месяц, 14 дней | подтвердить проценты/срок |
| 9 | Рефералка | 7 дней обоим; активация 3 дня × 10 оценок за 14 дней; лимиты 10/30 дней, 50 всего | подтвердить |
| 10 | Лиги | неделя пн–вс по `Asia/Tashkent`, группы 30, повышение/понижение по 5 | подтвердить |
| 11 | Шрифт для карточек достижений (ассет OFL, напр. Noto Sans) и использование GD | не composer-зависимость, файл в репозитории ~0.5–1 МБ | согласовать |
| 12 | Персонал центра: email + пароль (+2FA владельцу) в `/org` или вход через Telegram | §6.3 | email/пароль, 2FA владельцу обязательна |
| 13 | Платит ли организация Stars'ами | нет / да | **нет** — только Payme/Click и счёт |
| 14 | Контакт поддержки платежей (`/paysupport`, `BILLING_SUPPORT_CONTACT`) и реквизиты для счёта | | задать до 2A.2 |
| 15 | Uzum Bank как третий провайдер | позже | отложить до данных по Payme/Click |

Блокирует код только №2 (ключи — для sandbox-проверки, не для разработки) и №1 (для включения `purchase` на стенде). Остальное имеет дефолты в конфиге.

---

## 13. Отвергнутые варианты

| Вариант | Причина отказа |
|---|---|
| Хранить сумму заказа на клиенте / принимать `amount` в checkout | провайдеры сверяются только с серверным заказом; иначе подмена цены |
| Одна таблица `payments` без `payment_orders`/`provider_transactions` | Payme требует состояние транзакции (`CheckTransaction`, `GetStatement`, таймаут) независимо от леджера; заказ нужен до оплаты как якорь суммы и `invoice_payload` |
| Отдельная таблица журнала вебхуков | `provider_transactions.raw/history` + лог канала `billing` покрывают отладку и сверку; отдельная таблица — лишние записи на каждый `CheckTransaction` |
| Обработка `pre_checkout_query` через очередь `high` | 10 с Telegram не гарантируются очередью; синхронная проверка — одно чтение |
| Grace для Payme/Click | продления нет — grace лишь продлевал бы Pro бесплатно на 3 дня |
| Отдельный `plan` для тарифов организаций (`center_s` в `subscriptions.plan`) | `EntitlementResolver`/`Plan`/Filament-гранты пришлось бы переучивать на маппинг «тариф → план ученика»; `tier` + `seats_limit` дают то же без churn |
| Спонсорство по-прежнему «через активную группу», места — отдельно | два правила «кто получает Pro» расходятся при лимите; одно правило — `organization_members.seated` |
| Блокировка вступления при переполнении | ученик теряет доступ к обучению из-за решения центра; waitlisted + баннер мягче и мотивирует центр повысить тариф |
| TWA-кабинет центра | десятки экранов, смешение learner-идентичности и персонала; Filament даёт таблицы/экспорт/2FA сразу |
| Отдельная роль `org_admin` в панели `/admin` | платформенные ресурсы в той же панели — риск утечки через непокрытый scope; отдельная панель с tenancy изолирует по конструкции |
| Postgres RLS в Фазе 2 | tenancy + политики + тесты достаточны; RLS остаётся опцией для Фазы 3 (как в docs/12 §4.5) |
| Очки лиг инкрементом в транзакции оценки | новая блокировка в горячем пути (docs/06 §4.2); очки из `learner_daily_stats` — те же данные без записи |
| Награда рефералов сразу при `/start` | фарм; активация по реальной активности |
| Награда двум learner'ам в одной транзакции | блокировка двух строк `learners` — риск deadlock симметричных пар; клейм + две транзакции |
| Imagick / Intervention для картинок | новая зависимость; GD есть и достаточен для текста на фоне |
| Оценка spelling на клиенте | доверять клиенту нельзя; сервер считает расстояние |
| FSRS со своей машиной learning steps | две машины состояний; общие шаги + FSRS только для review-интервалов |
| Отдельная `mock_exam_sessions` | дубль flow/TWA экзамена; обобщение `exam_sessions` по образцу `training_sessions` Фазы 1 |
| Forward-миграции для `subscriptions`/`word_repetitions`/`exam_sessions` | прода нет — правка на месте по правилу docs/12 §2.2 |

---

## 14. Правки существующих документов (до кода соответствующей задачи)

- **docs/03_DATABASE.md** — 2A.1: §1.2 таблицы оплаты, ERD, порядок миграций; 2B.1/2B.4: организации, задания; 2C.*: рефералы, лиги, достижения, друзья; 2D.*: правки `word_repetitions`, `training_reviews`, `exam_sessions`, `courses`; `subscriptions.tier` и предикат partial unique.
- **docs/04_API_AND_BOT.md** — 2A.2: A.8 `/billing/*`, `/me.entitlement.renewal`, B.3 `/pro`, `/paysupport`; 2A.3: B.11 `pre_checkout_query`/`successful_payment`/`refunded_payment`, `allowed_updates`; 2A.4/2A.5: часть D «Вебхуки провайдеров» (Payme JSON-RPC, Click Prepare/Complete, коды ошибок); 2B.2/2B.4: `org_<code>`, A.9 `/assignments`; 2C/2D: `/league`, `/referrals`, `/decks`, A.3 `answer`/`prompt`/`audio_url`.
- **docs/06_SPACED_REPETITION.md** — 2D.2: §4.3 типы упражнений и серверная оценка; 2D.3: раздел FSRS (формулы, маппинг, инициализация, A/B), §4.2 — подтверждение, что лиги/freeze/достижения не входят в транзакцию оценки; 2C.2: streak freeze в правилах streak (ссылка на docs/12 §7.4).
- **docs/07_SECURITY.md** — 2A.*: §2a «Вебхуки провайдеров оплаты», §6 rate limits (`billing-webhook`, `twa-checkout`), §13 маски `PAYME_*`/`CLICK_*`, §14 чек-лист §10; 2B.3: §5 политики `/org` и tenancy; 2C.1: §1 фарм рефералов.
- **docs/02_ARCHITECTURE.md** — домены `Billing/Providers`, `Organizations`, `Engagement`; §2.5 планировщик (`billing:expire-orders`, `subscriptions:remind-expiring`, `billing:reconcile-stars`, `assignments:refresh`, `leagues:close-week`); ADR-10 «оплата периода + автопродление Stars, порядок блокировок §3», ADR-11 «кабинет центра — Filament tenancy», ADR-12 «FSRS как A/B через `SchedulerAlgorithm`».
- **docs/08_ROADMAP.md** — Фаза 2 по этому документу; **docs/11** — §4, §6, §10 «Фаза 2» помечены «уточнено в docs/13».
- **docs/12_PHASE1_DESIGN.md** — §5.1/§6.2 чек-лист `canceled` → «выполнено в 2A.1»; §1.8 организации → «расширено в docs/13 §1.3»; §7.2 `ref_<id>` → `ref_<code>` (2C.1).
- **DEVOPS_GUIDE.md** — env провайдеров, регистрация URL вебхуков, sandbox, `allowed_updates`, новые команды планировщика, k6-сценарии; **ADMIN_GUIDE.md** — платежи/возвраты/тарифы/места; **TEACHER_GUIDE.md** — кабинет центра; **I18N_UZ_REVIEW.md** — `billing.php`, `organizations.php`, `leagues.php`.

---

## 15. Что сверить с документацией провайдеров перед реализацией

1. Telegram Bot API: допустимые `subscription_period` (2592000) и потолок цены Stars-подписки; поля `successful_payment` (`subscription_expiration_date`, `is_recurring`, `is_first_recurring`), `refunded_payment`; сигнатуры `editUserStarSubscription`, `refundStarPayment`, `getStarTransactions`; уведомляется ли бот об отмене подписки пользователем; `shareMessage`/`savePreparedInlineMessage` для шеринга из TWA; требования к `/paysupport`.
2. Payme Merchant API: формат checkout-ссылки (`m`, `ac.*`, `a`, `l`, `c`), HTTP-код при ошибке аутентификации (200 + `-32504`), обязательность `detail`/ИКПУ в `CheckPerformTransaction`, актуальный таймаут транзакции (12 ч), коды `-31050…-31099`, формат `GetStatement`, адреса для IP-allowlist, sandbox (`checkout.test.paycom.uz`, тестовый ключ).
3. Click Merchant API: параметры checkout-URL, точная формула `sign_string` для Prepare/Complete, ожидаемый ответ на повторный Complete (0 или −4), поведение при `error < 0`, наличие уведомлений о возвратах, адреса для allowlist, тестовый контур.
4. `irazasyed/telegram-bot-sdk ^3.15`: наличие обёрток для новых методов или сырой `post()`.
