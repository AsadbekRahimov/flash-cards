# Тарифы после пробного Pro, лимиты Free, месячный рейтинг и бот без падений

Статус: **проект, код не написан** (25.11.2026). Автор — architect, по запросу владельца от 25.11.2026.
Продукт в бета-режиме, боевых учеников нет. Документ не противоречит [docs/31](31_COMPETITION_PLAN_RU.md) и встраивает четыре темы в его выпуски (§5).

| № | Тема | Главное по коду | Что делаем |
|---|---|---|---|
| 1 | Параметры тарифов | Все четыре числа — в конфиге и env, тексты почти везде подставляют числа сами. Пробный Pro и награда реферала — **уже два разных параметра** | Меняем умолчания и прод-env, правим docs, руководство и тесты |
| 2 | После пробного Pro | «Цель дня» — это **число оценённых карточек**, а не новых слов. С тарифом она не связана. После конца пробного бот ничего не пишет: есть только напоминания за 3 и 1 день до конца и баннер в Mini App | Лимит цели дня по тарифу, автоматическое снижение и возврат, замки в настройках, 403 с сервера, цепочка предложений |
| 3 | Umar в лиге, но не в месяце | Воспроизведено тестом: **ученик, не прошедший онбординг в боте, попадает в недельную лигу, но в месяце `participant = false`** | Убрать условие онбординга из месяца, объяснять ученику, почему он «без места» |
| 4 | Блокировка бота и лимиты Telegram | 403 и «chat not found» обрабатываются хорошо, флаг `learners.dm_blocked_at` есть, общий лимит 25 сообщений в секунду есть. **429 не распознаётся**: часть сообщений теряется, а запросы в группы при удалённом боте уходят в `failed_jobs` | Классификация ошибок, обработка `retry_after`, одна точка установки флага с причиной, счётчик в `/admin` |

---

## 1. Тема 1 — параметры тарифов

### 1а. Как сейчас по коду

| Параметр | Где задаётся | Ключ / env | Сейчас | Станет |
|---|---|---|---|---|
| Пробный Pro новичку | `config/billing.php` → `trial.days`; читает `ProTrialService::days()` | `BILLING_TRIAL_DAYS` | 7 | **14** |
| Награда пригласившему за веху | `config/referrals.php` → `milestone_days`; читает `ReferralService` / `GrantReferralMilestoneJob` | `REFERRALS_MILESTONE_DAYS` | 7 | 7 (не меняем) |
| Новые слова в день, Free | `config/billing.php` → `plans.free.limits.new_words_per_day`; `Plan::newWordsPerDay()` → `Entitlement::$newWordsPerDay` → `NewWordQuota` | `BILLING_FREE_NEW_WORDS_PER_DAY` | 20 | **10** |
| «Игры со словами» (практика), Free | `config/practice.php` → `free_limits.sessions_per_day`; `PracticeLimits` | `PRACTICE_FREE_SESSIONS_PER_DAY` | 2 | **3** |
| Баттлы, Free (для справки) | `practice.free_limits.battles_per_day` | `PRACTICE_FREE_BATTLES_PER_DAY` | 1 | 1 |
| Забеги «Каравана» по карте, Free (для справки) | `config/game.php` → `free_limits.runs_per_day` | `GAME_FREE_RUNS_PER_DAY` | 1 | 1 |

Выводы:

- **Пробный период и реферал разделять не нужно.** Это уже два параметра: `billing.trial.days` и `referrals.milestone_days`. Друг по ссылке получает обычный пробный период новичка, а пригласивший — `milestone_days` за 3 и за 6 засчитанных друзей.
- **Лимит, равный 2, — это практика** (`sessions_per_day`): карточка «Игры со словами» на экране «Сегодня» (`today.games.title`) и хаб практики. Остальные лимиты Free равны 1: вызов на баттл и забег по карте «Каравана». «Повторить ошибки» после сегодняшней сессии бесплатен и в лимит не входит (`PracticeLimits`).
- **Новое число вступает в силу сразу после перезагрузки конфига.** Кэш тарифа (`entitlement:learner:{id}`) хранит план, а `new_words_per_day` при чтении вычисляется из плана (`Entitlement::fromArray`). Если Free-ученик уже получил сегодня 15 новых слов, остаток станет 0 (`max(0, …)` в `NewWordQuota::remaining`).
- **Пробный период в 14 дней получат только новые выдачи.** Уже выданные пробные (строки `subscriptions` с `provider = trial`) не продлеваются. Ученики с меткой `meta.pro_trial_pending`, которые ещё не приходили сами, получат 14 дней.
- **В проде env задан явно.** В `.env.example` есть `BILLING_FREE_NEW_WORDS_PER_DAY=20`, `BILLING_TRIAL_DAYS=7` и `PRACTICE_FREE_SESSIONS_PER_DAY=2`, и та же тройка, скорее всего, стоит в `.env` сервера. Смены умолчаний в конфиге мало: нужно поменять env на сервере и перезагрузить конфиг.

Где числа стоят в текстах:

| Место | Что там | Нужно ли менять |
|---|---|---|
| `lang/{ru,uz}/billing.php` `trial.welcome`, `renewal.trial_dm`; `lang/{ru,uz}/referrals.php` | Плейсхолдеры `:days`, `:reward`, `:count` | Нет, правим только комментарии «`:days` = 7 дней» |
| `lang/{ru,uz}/landing.php`, `LandingPricing::limits()` | Число берётся из `Plan::Free->newWordsPerDay()` | Нет. Кэш страниц сайта зависит от этих ключей конфига (`SitePageCacheTest`) |
| `lang/ru/billing.php` `stars.description_*` | «свои колоды до 20 по 2000 слов» — это лимит колод (`decks.limits`), к новым словам отношения не имеет | Нет |
| TWA `resources/twa/src/i18n/{ru,uz}.ts`: `today.trial.line`, `practice.limit.sessions`, `billing.plan.free_limit`, `finished.trial_ending.*` | Плейсхолдеры `{count}`, `{limit}` | Нет |
| `docs/USER_GUIDE.md` строки 19, 123, 243, 285 | «Pro на 7 дней», «2 сессии в день», «у него свой пробный Pro на 7 дней» | **Да** |
| `docs/ADMIN_GUIDE.md` строка 552 | «Free learners get 20 new words a day» | **Да** |
| `docs/13_PHASE2_DESIGN.md` строки 6, 29 и §7.1 | «Free = 20 новых слов/день», «7 дней пробного Pro» | **Да** |
| `docs/12_PHASE1_DESIGN.md` §5.3–5.4, `docs/04_API_AND_BOT.md` (примеры `new_words_per_day: 20`), `docs/16_PRACTICE_DESIGN.md` §3, `docs/18_GAME_PLAN.md` (стр. 100, 170) | Те же числа | **Да** |
| `.env.example` (строки ~309, 313, 510), `tests/load/k6/README.md` (стр. 83) | Умолчания | **Да** |
| `PROGRESS.md` | История | Новая строка, старые записи не трогаем |
| Сидеры (`DemoLearnersSeeder`, `PracticeDemoSeeder`, `PerfSeeder`) | Цель дня демо-учеников `[10, 20, 30]` в `PerfSeeder` | Не трогаем: тема 2 снизит цель сама |

Тесты, которые опираются на умолчания (найдены поиском; перед правкой — `grep -rn "20" tests | grep -iE "word|quota|remain"`):
`tests/Unit/BillingPlanTest.php:17`, `tests/Feature/Billing/EntitlementResolverTest.php:67`, `tests/Feature/TwaContractTest.php:262,294`, `tests/Feature/Billing/NewWordQuotaTest.php:183–212`, `tests/Feature/LearnerDecksTest.php:687`, `tests/Feature/LearningSprintTest.php:520,546` (проверить, квота это или размер урока), тесты пробного периода (`ProTrialServiceTest`, `ProTrialConcurrencyTest`, `TrialWelcomeBotTest`, `OnboardingFlowTest`, `ReferralBotTest`, `PromoCommandTest`) — где ждут 7 дней без явного `config()`. `LaunchConfigTest` и `LandingLimitsTest` задают значения явно, их не трогаем.

### 1б. Что менять

1. Умолчания в `config/billing.php` (`trial.days` = 14, `new_words_per_day` = 10) и `config/practice.php` (`sessions_per_day` = 3), плюс комментарии рядом.
2. `.env.example` и прод-`.env`: `BILLING_TRIAL_DAYS=14`, `BILLING_FREE_NEW_WORDS_PER_DAY=10`, `PRACTICE_FREE_SESSIONS_PER_DAY=3`. `REFERRALS_MILESTONE_DAYS=7` не трогаем. После этого — `php artisan config:cache` и перезагрузка воркеров и Octane.
3. Тесты переводим с «магического» 20 на явный `config()->set(...)` в `beforeEach` или на чтение `Plan::Free->newWordsPerDay()`. Тогда следующая смена числа тесты не сломает.
4. Новый тест «умолчания запуска» (`tests/Feature/Billing/LaunchDefaultsTest.php`): без env значения равны 14 / 10 / 3 / 7.

### 1в. Задачи

| № | Задача | Кто | Зависит от | Definition of Done и ключевые тесты |
|---|---|---|---|---|
| T1 | Docs до кода: docs/13 (стр. 6, 29, §7.1), docs/12 §5.3–5.4, docs/16 §3, docs/18 G1.1, docs/04 (примеры), USER_GUIDE, ADMIN_GUIDE | координатор / coder | — | Нигде в docs нет «20 новых слов», «2 сессии», «пробный Pro на 7 дней» (кроме истории в PROGRESS и аудитах) |
| T2 | Умолчания в `config/billing.php` и `config/practice.php`, `.env.example`, комментарии в lang про `:days` | coder | T1 | `LaunchDefaultsTest`; pint, phpstan |
| T3 | Перевести тесты с умолчаний на явный конфиг (список в §1а) | coder | T2 | Тесты из списка и всё `tests/Feature/Billing` зелёные |
| T4 | Прод: env, `config:cache`, перезапуск; проверить `/pro`, лендинг и хаб практики у тестового Free-ученика | владелец / devops | T2 | На лендинге «10 новых слов в день», в хабе «осталось 3 из 3», новичку бот пишет «Pro на 14 дней» |

### 1г. Вопросы владельцу

- **В1.1.** «Игры слов» — это практика («Игры со словами»: пары, диктант, предложения и т. п.), а не забеги «Каравана» и не баттлы? *Рекомендация:* да, меняем только `PRACTICE_FREE_SESSIONS_PER_DAY`.

---

## 2. Тема 2 — что происходит после пробного Pro

### 2а. Как сейчас по коду

**Цель дня ≠ новые слова.** Это главное, что нужно знать до проектирования.

| Понятие | Где хранится | Что считает | Кто ограничивает |
|---|---|---|---|
| Цель дня | `learners.daily_goal` (int). Новичку ставится `learners.default_daily_goal` = 10 (`LearnerRegistrar`) | **Число оценённых карточек за день: повторения и новые вместе** (`DailyStatsService` сравнивает `reviews_count >= daily_goal`; `TrainingFlowService::dailyGoal()` задаёт размер дневного раунда) | Только список `learners.daily_goal_options` = `[10, 20, 40]` (`LearnerSettingsService::normalize`, `UpdateSettingsRequest`). **От тарифа не зависит** |
| Лимит новых слов | Нигде не хранится; считается из `word_repetitions` за локальный день (`NewWordQuota`) | Сколько слов впервые показано сегодня | Тариф: Free 20 (станет 10), Pro без лимита |

- Цель меняется только в Mini App: экран «Настройки», `PATCH /api/twa/me/settings` с полем `daily_goal`. В боте её не меняют, при онбординге не спрашивают.
- Подпись в Mini App сбивает с толку: `settings.daily_goal.unit` = «слов в день», хотя считаются оценённые карточки.
- Когда цель снижают, `DailyStatsService::reconcileGoal()` засчитывает сегодняшний день, если сделано уже больше новой цели. Вызов идёт отдельной транзакцией после фиксации `learners` (docs/06 §4.2).

Как сейчас заканчиваются пробный период и подписка:

- **Пробный:** `ProTrialService` → `SubscriptionService::grantTrial()` создаёт подписку с `provider = trial`. Тариф ученика всегда вычисляется по часам: `SubscriptionLiveness` в `EntitlementResolver`. Поэтому Pro пропадает в момент `current_period_ends_at`, ещё до того, как `subscriptions:expire` (раз в час) поставит статус `expired`.
- **События окончания нет.** `SubscriptionService::markExpired()` пишет только продуктовое событие `subscription.expired`. Laravel-события и сообщения ученику нет.
- **До конца:** `subscriptions:remind-expiring` (ежедневно, 06:00 UTC) поднимает `SubscriptionRenewalDue` за 3 и 1 день (`billing.checkout.renewal_reminder_days = [3, 1]`, для пробного действует тоже: `SubscriptionProvider::isReminded()`). Дальше `RenewalReminderJob` → `RenewalReminderSender` отправляет текст `billing.renewal.trial_dm` + `hint_trial` с кнопкой `/twa/billing?from=renewal`. **Цен и списка плюсов в сообщении нет.**
- **После конца:** `LearnerBillingService::lapsed()` 14 дней (`billing.checkout.lapsed_banner_days`) отдаёт `/me.entitlement.lapsed`, и Mini App показывает `LapsedBanner` («Пробный Pro закончился»). Сообщений бота после конца нет.
- **Пейвол Mini App:** `PaywallSheet.vue`, причины `quota | info | decks | streak_freeze | exam_simulator | practice | practice_limit | game`. В режиме `billing.paywall.mode = purchase` показывает периоды и цены из `GET /billing/catalog`, а плюсы — компонентом `ProBenefits` (от фич, `ProPromises`). Просмотры пишутся по `OfferPlacement`.
- **Бот:** `/pro` → `ProScreen::build()` (цены по периодам и провайдерам, кнопки `pro1:buy:<period>:<provider>`); `ProScreen::buyRows()` уже переиспользуется в `OfferMessenger` (личная скидка после выхода из центра, `billing.offers.post_org_discount`).

Цены и периоды из `config/billing.php` (умолчания; в проде берём из каталога, **в коде и текстах не зашиваем**):

| Период | Дней | Stars | Сумы (тийины в конфиге) |
|---|---|---|---|
| month | 30 | 150 ⭐ (`BILLING_PRICE_PRO_MONTH_XTR`) | 29 000 (`BILLING_PRICE_PRO_MONTH_UZS` = 2 900 000) |
| quarter | 90 | 390 ⭐ | 75 000 |
| year | 365 | 1 300 ⭐ | 249 000 |

Продаётся только то, что разрешено флагами провайдеров (`BILLING_STARS_ENABLED`, `PAYME_ENABLED`, `CLICK_ENABLED`) при `BILLING_PAYWALL_MODE=purchase`. Иначе `CheckoutService::catalog()` пуст, и бот пишет «Pro скоро».

### 2б. Что менять

#### 2б.1. Лимит цели дня по тарифу

Владелец говорит «у Free 10 слов в день» и про лимит новых слов, и про цель дня. В коде это два разных числа, и сливать их не нужно: цель считает и повторения. Делаем **отдельный лимит цели** и по умолчанию приравниваем его к лимиту новых слов:

- `config/billing.php` → `plans.free.limits.daily_goal_max` = `(int) env('BILLING_FREE_DAILY_GOAL_MAX', 10)`, у `pro` — `null` (без лимита).
- `Plan::dailyGoalMax(): ?int` и поле `Entitlement::$dailyGoalMax`. Поле вычисляется из плана, как `newWordsPerDay`, и в снимок кэша не пишется, поэтому версия кэша не меняется.
- Проверка конфига (тест): лимит Free входит в `learners.daily_goal_options`, а `learners.default_daily_goal` ≤ лимита Free.

Почему не «цель = лимит новых слов»: Pro-ученик с целью 40 почти всегда набирает её повторениями. Связь чисел — продуктовое решение, а не правило кода. Если владелец захочет 15 новых слов при цели 10, это будет одна строка env.

#### 2б.2. Автоматическое снижение и возврат цели

Варианты:

| Вариант | Суть | Почему нет / да |
|---|---|---|
| А. Хранить выбор, считать «эффективную цель» = `min(daily_goal, лимит)` при каждом чтении | Ничего не пишется | Нет: цель читается в 6+ местах, в том числе скалярным подзапросом внутри транзакции ответа (`DailyStatsService`), серией (`StreakService`) и сверкой (`reconcileGoal`). Одно забытое место — и серия рвётся. К тому же в настройках будет «40», а работать будет 10 |
| Б. Снижать по событию окончания подписки | Слушатель на `subscriptions:expire` | Нет как единственный путь: Pro заканчивается и без него — выход из центра, отключение группы, возврат платежа, отзыв выдачи, просто по часам до прогона `expire` |
| **В. Записывать снижение лениво при чтении + ежедневная «подметалка»** | Там, где тариф уже вычислен (экран «Сегодня», настройки, старт дневного раунда), сервис сравнивает цель с лимитом и при превышении один раз записывает новую цель. Команда раз в сутки доделывает за тех, кто не заходит | **Выбрано.** Одно место записи, любые источники окончания Pro, ноль лишних запросов в обычном случае (сравнение в памяти) |

**Сервис** `App\Domain\Learners\Services\DailyGoalLimiter` (ядро — architect):

```php
public function cap(Entitlement $e): ?int;                       // null = без лимита
/** @return list<array{value: int, locked: bool}> */
public function options(Entitlement $e): array;
public function assertAllowed(int $goal, Entitlement $e): void; // DailyGoalLimitException (403 daily_goal_pro_required)
public function enforce(Learner $learner, Entitlement $e, CarbonImmutable $now): ?DailyGoalChange; // null = ничего не менялось
```

Правила `enforce()`:

1. **Проверка в памяти:** нужно снизить (`cap !== null && daily_goal > cap`) или нужно вернуть (`cap === null` и в `meta` есть `daily_goal_before_limit`). Если ни то ни другое — выход без запросов. Это обычный случай, так что бюджет запросов `/today` не меняется.
2. **Транзакция:** строка `learners` `FOR UPDATE`, повторная проверка на свежих данных — это и есть идемпотентность: два параллельных вызова, второй ничего не делает.
   - Снижение: `daily_goal = cap`, `meta.daily_goal_before_limit = <прежняя цель>`. Ключ пишем, только если его нет, чтобы второе снижение не затёрло исходный выбор.
   - Возврат: `daily_goal = meta.daily_goal_before_limit` (если значение есть в `daily_goal_options`, иначе ближайшее меньшее), ключ удаляем.
3. После фиксации — `DailyStatsService::reconcileGoal($learner)` в отдельной транзакции, в том же порядке, что и при ручном снижении (сначала `learners`, потом `learner_daily_stats`, никогда вместе). Ошибку сверки отправляем в `report()` и не пробрасываем: так уже сделано в `LearnerSettingsService::update`.
4. Продуктовые события `daily_goal.limited {from, to}` и `daily_goal.restored {to}`.
5. Если ученик сам сменил цель в настройках, `daily_goal_before_limit` удаляется: ручной выбор важнее автоматики.

**Где вызывается:**
- `TodayService::summary()` — сразу после `$this->entitlements->for()`.
- `LearnerSettingsService::describe()` и `update()` — тариф вычисляется до транзакции настроек.
- `TrainingFlowService` при старте дневного раунда — чтобы размер раунда был новым.
- Команда `learners:enforce-goal-limits` (ежедневно, 03:00 UTC, `withoutOverlapping`): выбирает `learners.daily_goal > min(cap)` пачками по id, по каждому вычисляет тариф и вызывает `enforce()`. Она нужна ради напоминаний и экрана `/today` бота у тех, кто не открывает Mini App. Возврат цели при покупке делает только ленивый путь.

Что увидит ученик с целью 20 или 40 после конца пробного периода: при первом открытии экран «Сегодня» уже показывает цель 10. На экране окончания пробного периода (§2б.4) есть строка «Цель дня теперь 10 карточек — это максимум на Free». При покупке Pro прежняя цель вернётся сама.

**Существующие ученики.** Free с целью 20 или 40 снизятся при первом заходе или ночной командой. Ученики на пробном Pro и на Pro от центра остаются как есть, пока Pro не кончится.

#### 2б.3. Экран выбора цели и проверка на сервере

- `GET /me` → `settings.options` получает новое поле `daily_goal_max: int|null`. Поле добавочное, старые клиенты его не заметят. Список `daily_goals` остаётся полным.
- Mini App, `SettingsView.vue`: значения больше `daily_goal_max` показываются с замком; нажатие не сохраняет, а открывает `PaywallSheet` с новой причиной `daily_goal` (заголовок «Цель больше 10 — в Pro»). Новое место показа — `OfferPlacement::PaywallDailyGoal = 'paywall_daily_goal'` (и зеркало в `offerViewed.ts`).
- Сервер: `PATCH /api/twa/me/settings {daily_goal: 20}` у Free → **403 `daily_goal_pro_required`**, `{max: 10}` в деталях ошибки. Формат — как у `practice_limit_reached`. Значение не из списка по-прежнему даёт 422. Проверка — в `LearnerSettingsService::update()` через `DailyGoalLimiter::assertAllowed()` (сервис — последняя линия защиты, `FormRequest` проверяет только список).
- Гонка «тариф закончился между проверкой и записью» безвредна: сохранится 20, и ближайший `enforce()` снизит цель.
- Подпись: `settings.daily_goal.unit` «слов в день» → «карточек в день». Это вопрос В2.4.

#### 2б.4. Последовательность предложений после пробного периода

Шаги считаем от конца пробного периода `T` (`subscriptions.current_period_ends_at` подписки с `provider = trial`):

| Шаг | Когда | Канал | Содержание | Сейчас |
|---|---|---|---|---|
| −3 | за 3 дня | бот | «Пробный Pro закончится через 3 дн. (дата)» + 3–4 главных плюса + цены «от 150 ⭐ / 29 000 сум в месяц» + кнопки периодов + «Пригласить друзей» | есть (`RenewalReminderSender`, `trial_dm`), но без цен и плюсов — **дополнить** |
| −1 | за 1 день | бот | то же коротко | есть — **для пробного убрать** (вопрос В2.2), для платных оставить |
| 0 | в день окончания, ≥ 1 ч после `T`, в окне 11:00–19:00 по часам ученика | бот + Mini App | «Пробный Pro закончился. Теперь у вас Free: 10 новых слов и цель до 10 карточек в день, 3 игры со словами» + таблица цен (месяц / квартал / год, «−N %» по `saving_pct`) + плюсы + кнопки | **нет** |
| +3 | через 3 дня | бот | Что ученик сделал за пробный период (выучено слов, лучшая серия — из `learner_daily_stats`) + «вернуть Pro» + цены | **нет** |
| +10 | через 10 дней | бот | Последнее напоминание; при согласии владельца — личная скидка (вопрос В2.3) | **нет** |

Mini App: при первом открытии после `T`, если в `/me.entitlement.lapsed.provider = trial`, один раз сам открывается `PaywallSheet` с причиной `trial_ended`. «Уже показан» хранится в `localStorage` по ключу `trial_ended_seen:<ended_at>`, на сервере ничего не храним. Место показа — `paywall_trial_ended`. `LapsedBanner` остаётся на 14 дней, как сейчас.

**Плюсы** не пишем вручную: они собираются из фич плана через `ProPromises`, как это уже делает `ProBenefits`. Это новые слова без лимита, игры и баттлы без лимита, «Написание слова» и «Пропуск в примере», свои колоды, заморозки серии, пробные тесты IELTS и SAT, «Караван» без лимита и все боссы. После docs/31 §5а добавится эмодзи Pro, после docs/32 — таблица Pro. Скрытая фича (например, баттлы при `PRACTICE_BATTLE=false`) в список не попадает.

**Цены** — только из `CheckoutService::catalog($learner)` в момент отправки, через уже существующие `ProScreen::priceOptions()` и `buyRows()`. Если каталог пуст или `paywall.mode != purchase`, шаги +3 и +10 не отправляются, а шаг 0 уходит без цен, с текстом «Pro скоро» и реферальной подсказкой.

**Частота и отписка (не спамить):**

- Не больше **одного** сообщения о Pro (любого шага и любой напоминалки о продлении) в локальные сутки ученика. Учебное напоминание (`reminders:dispatch`) — отдельный канал со своим правилом «одно в день». В один день с ним предложение уходит днём (11:00–19:00), а напоминание — в выбранное учеником время.
- Цепочка останавливается, если: тариф снова Pro (любая покупка, промокод, место в центре, награда за друзей); `dm_blocked_at`, `blocked_at` или `deleted_at`; ученик отписался; пропущен срок шага (сообщение старше суток не досылаем — как `max_delay_minutes` у напоминаний).
- **Отписка:** под каждым сообщением цепочки кнопка «Не присылать предложения» (`callback_data = bill1:offers_off`) → `learners.meta.billing_offers_opt_out = true`; повторное нажатие ничего не делает. Тумблер «Предложения Pro» в настройках Mini App можно сделать позже. Отписка **не** отключает служебные сообщения о платной подписке (продление, оплата, возврат).
- Идемпотентность: маркер `notifications_log` (`type = trial_offer`, `payload = {subscription_id, step}`) пишется **до** отправки, удаляется при 403 и при 429 (тема 4), остаётся при таймауте. Это тот же образец, что в `RenewalReminderSender`: лучше пропустить, чем отправить дважды.

**Где живёт:**
- `App\Domain\Billing\Services\TrialOfferSender` — выбор, тексты, отправка.
- `App\Jobs\SendTrialOfferJob` с `ThrottleTelegramOutbound`, `tries = 0`, `maxExceptions = 3`, `retryUntil` +12 ч.
- Команда `billing:trial-offers` раз в час: выбирает подписки `provider = trial`, `subscriber_type = learner`, у которых `current_period_ends_at` попал в окно шага, проверяет местное время ученика и ставит задания.
- Настройка шагов: `billing.trial_offers.after_days = [0, 3, 10]` (env `BILLING_TRIAL_OFFERS_AFTER_DAYS`), пустой список — выключено. Для −1 нужна отдельная настройка напоминаний пробного периода: `billing.trial.remind_days = [3]`, `RenewalReminderSender` и `SubscriptionService::remindExpiring` читают её для `provider = trial`.

### 2в. Задачи

| № | Задача | Кто | Зависит от | Definition of Done и ключевые тесты |
|---|---|---|---|---|
| P0 | Docs до кода: docs/12 §5.3–5.4 (лимит цели Free), docs/13 §7.1 (новый подраздел «После пробного Pro»), docs/04 (поле `daily_goal_max`, код 403 `daily_goal_pro_required`, причины пейвола), docs/05 (экран настроек), docs/06 §4.2 (автоснижение цели и сверка) | координатор | T1 | docs согласованы |
| P1 | `billing.plans.*.limits.daily_goal_max`, `Plan::dailyGoalMax()`, `Entitlement::$dailyGoalMax`, проверка конфига | coder | P0 | `BillingPlanTest`, тест согласованности конфига (лимит в списке, умолчание ≤ лимита) |
| P2 | **Ядро `DailyGoalLimiter`** (`enforce`, `assertAllowed`, `options`) + команда `learners:enforce-goal-limits` + расписание | **architect** | P1 | Снижение 40 → 10 и возврат 10 → 40; повторный вызов ничего не меняет; ручная смена убирает `daily_goal_before_limit`; снижение засчитывает день, если сделано ≥ 10 (серия +1 один раз); **гонки на PG:** два `enforce` параллельно, `enforce` вместе с `PATCH settings`, `enforce` вместе с оценкой карточки (без взаимной блокировки, день засчитан ровно один раз); 0 лишних запросов, когда менять нечего |
| P3 | Подключение: `TodayService`, `LearnerSettingsService::describe/update` (403), `TrainingFlowService`; поле `options.daily_goal_max` в ресурсе | coder | P2 | Тесты бюджета запросов `/today` и `/me` не растут; 403 `daily_goal_pro_required` у Free; 200 у Pro и у ученика центра; 422 для значения не из списка |
| P4 | Mini App: замки в выборе цели, `PaywallSheet` с причиной `daily_goal`, `OfferPlacement::PaywallDailyGoal`, i18n ru/uz, подпись единицы | coder | P3 | vitest: замок, нажатие открывает пейвол, PATCH не уходит; ответ 403 тоже открывает пейвол |
| P5 | Цепочка: `TrialOfferSender`, `SendTrialOfferJob`, `billing:trial-offers`, тексты ru/uz с ценами из каталога и плюсами из `ProPromises`, кнопка `bill1:offers_off` (обработчик рядом с `ProCallbackHandler`) | coder (ревью architect — выбор окна и маркер) | P1 | Каждый шаг один раз; остановка при Pro / блокировке / отписке; окно 11–19 по поясу ученика; не больше 1 сообщения в сутки вместе с напоминаниями о продлении; при пустом каталоге шаг 0 без цен, +3 и +10 не уходят; 403 ставит флаг (тема 4) и удаляет маркер |
| P6 | Напоминание −3 для пробного: плюсы и цены, `billing.trial.remind_days`, без −1 | coder | P5 | Тест текста с ценами; для платных подписок −3 и −1 не изменились |
| P7 | Mini App: разовый пейвол `trial_ended`, `localStorage`, место показа | coder | P4 | vitest: открывается один раз на одно `ended_at` |
| P8 | Аналитика: события `trial_offer.sent {step}` и `trial_offer.opted_out`, переход «шаг → оплата» в `BillingFunnelService` | coder | P5 | Тест отчёта |

### 2г. Вопросы владельцу

- **В2.1.** При покупке Pro вернуть прежнюю цель (например, 40)? *Рекомендация:* да, автоматически. Ученик её сам выбирал, а это ещё и довод в пользу покупки.
- **В2.2.** Шаги цепочки: −3, 0, +3, +10, а −1 для пробного убрать. *Рекомендация:* да, 4 сообщения за 13 дней. Сейчас пробный получает −3 и −1, а после конца — ничего.
- **В2.3.** Скидка на шаге +10 (например, −20 % на месяц на 72 часа; механизм `OfferService` уже есть для учеников после центра)? *Рекомендация:* пока без скидки. Включить после 2–3 недель данных по шагам 0 и +3.
- **В2.4.** Подпись цели: «слов в день» или «карточек в день»? *Рекомендация:* «карточек в день». Считаются все оценки, и с подписью «слов» Free-ученик решит, что его ограничили дважды.

---

## 3. Тема 3 — Umar первый в лиге, но его нет в рейтинге месяца

### 3а. Как сейчас по коду

| | Недельная лига (`LeagueService`) | Рейтинг месяца (`MonthlyRatingService`) |
|---|---|---|
| Кто попадает | Любой, кто оценил карточку или закончил забег на неделе (`DailyStatsService` → `place()`, `RunFinisher` → `place()`). Не попадают: лиги выключены, `leagues_opt_out`, `blocked_at` | Все, у кого есть строка `learner_daily_stats` в месяце, но **в таблице (`participant = true`) только если** `deleted_at IS NULL AND blocked_at IS NULL AND onboarding_state = 'done' AND NOT leagues_opt_out` (`upsertScores`) |
| Очки | Сумма `LeaguePoints::dayExpression` по дням недели, **без потолка** | Та же формула по дням месяца, **с потолком 300 в день** (`leagues.monthly.daily_cap`) |
| Видимость | Все участники группы (до 30), удалённые — без имени | Топ-50 (`leagues.monthly.top`), в нём только `participant = true` |
| Пороги приза | — | `min_active_days = 10`, `min_account_days = 14` — **в топ-списке не фильтруют**, влияют только на приз и на список кандидатов в `/admin` |
| Свежесть | Кэш группы 60 с | Пересчёт раз в 10 мин (только активные за 3 дня), кэш топа 120 с; свою строку ученик видит сразу (`refreshLearner`) |
| Границы | Неделя пн–вс в поясе лиг | Календарный месяц в поясе лиг |

Все причины, по которым ученик может быть первым в неделе и отсутствовать в месяце:

1. **Не пройден онбординг в боте** (`onboarding_state != 'done'`). Mini App позволяет учиться без онбординга (`TodayView` показывает только подсказку `onboarding-hint`), а онбординг (язык → уровень → время напоминаний) идёт только в боте. Ученик, который открыл Mini App и не ответил на вопросы бота, в лиге есть, а в месяце — `participant = false`. Сам он видит «Пока без места · N баллов» без объяснения, остальные не видят его вовсе. **Воспроизведено** временным тестом на sqlite (удалён): Umar (`onboarding_state = level`, 240 очков) — первый в группе недели, а в топе месяца только второй ученик с 60; у Umar `rank = null, participant = false`.
2. Отказался от лиг (`leagues_opt_out`) **после** того, как попал в группу недели: в неделе остаётся до конца недели, из месяца выпадает при ближайшем пересчёте. Ученик видит «Вы не участвуете в рейтингах».
3. Заблокирован администратором после попадания в группу недели (то же поведение).
4. Удалён: в неделе остаётся строка без имени, то есть «Umar» там бы не было — не наш случай.
5. Неделя на стыке месяцев: очки 27–31 октября идут в неделю, но в октябрь. Если смотрели ноябрь 1–2 числа, а 1–2 ноября Umar не занимался, строки ноября у него нет вовсе. Сегодня (25.11) текущая неделя внутри ноября — маловероятно.
6. Топ месяца — 50 строк, а первое место в группе нижнего яруса может оказаться за пределами топ-50 общего рейтинга. В бете учеников мало — маловероятно.
7. Потолок 300 в день: снижает место, но не убирает из таблицы.
8. Задержка до ~12 минут (пересчёт раз в 10 мин + кэш 120 с) для того, кто впервые занимается в месяце: временно.
9. **Если «статистику месяца» смотрели в `/admin` → «Рейтинг месяца»**: там после закрытия видны только кандидаты на приз — это `participant` **и** ≥ 10 дней с очками **и** аккаунту ≥ 14 дней (`snapshotCandidates`). Новичок беты туда не попадает, даже будучи первым.

**Самая вероятная причина — п. 1**, при просмотре в `/admin` — п. 9. Проверить на проде одним запросом (подставить id):
`select id, first_name, onboarding_state, meta->>'leagues_opt_out', blocked_at, created_at from learners where first_name ilike 'umar%';`
и `select participant, points, active_days from league_month_scores where learner_id = ? order by league_month_id desc limit 2;`.

### 3б. Ошибка или правило

Это **правило, которое расходится между неделей и месяцем и ничем не объяснено ученику**: docs/13 §7.6 «Кто участвует» требует онбординг, а недельная лига — нет. Требование появилось, когда учиться можно было только после бота. Сейчас Mini App его обходит, и правило просто наказывает тех, кто не ответил на три вопроса в боте. От злоупотреблений оно не защищает: от накруток защищают потолок, пороги приза и ручная проверка кандидатов.

Решение:

1. **Убрать `onboarding_state = 'done'` из условия `participant`** месяца (и из будущего `learner_score_totals` в docs/32 §5.2). Остаются удаление, блокировка и opt-out — те же, что и для недели.
2. **Объяснять «без места».** В `MonthlyMyLine` добавить `not_counted_reason: 'opted_out' | 'blocked' | null`. Для `opted_out` экран уже показывает свою строку. Для `blocked` — нейтральное «Ваш аккаунт не участвует в рейтинге, напишите в поддержку».
3. **Пороги приза показывать в таблице, а не только у себя.** В строке топа — признак `prize_eligible`; у тех, кто без права на приз, приглушённая пометка «вне зачёта призов». Своему ученику — уже существующие строки «Для приза нужно заниматься ещё N дн.». Тогда «первый, но без приза» перестанет удивлять.
4. **В `/admin` на странице месяца** — блок «Лидеры без права на приз»: топ участников, не попавших в кандидаты, с причиной (дней с очками X из 10, аккаунту Y дн. из 14).

Чинить сейчас или в новой системе (docs/32): **п. 1–2 — сейчас, отдельно.** Это одно условие в SQL и одно поле, а участники месяца заметят ошибку уже в декабре. S9 (месяц v2) перепишет источник очков, но выражение `participant` перенесёт как есть — поэтому сразу правим и docs/32 §5.2. **П. 3–4 — в рамках новой системы**: вкладка «Лига» перерисовывается в выпуске 1, `LeagueBoardResource` и `/admin` — в S12 и S15.

### 3в. Задачи

| № | Задача | Кто | Зависит от | Definition of Done и ключевые тесты |
|---|---|---|---|---|
| L0 | Docs: docs/13 §7.6 «Кто участвует» (без онбординга), docs/32 §5.2 (стр. 366), docs/04 (поле `not_counted_reason`) | координатор | — | Docs согласованы |
| L1 | `MonthlyRatingService::upsertScores` — убрать условие онбординга и его binding; комментарий в `MonthlyMyLine`; после выката — `php artisan leagues:refresh-month --full` | coder | L0 | Переписать тест «keeps out … the not onboarded» (Fresh теперь участник); новый тест: ученик без онбординга в группе недели и в топе месяца на том же месте; закрытие месяца берёт его в кандидаты при выполненных порогах |
| L2 | `not_counted_reason` в `MonthlyMyLine` и ресурсе, строка в `MonthlyRatingView.vue`, i18n ru/uz | coder | L1 | Pest для ресурса, vitest для строки |
| L3 | `prize_eligible` в строках топа (ключ кэша `league:month:top:v2:` — смысл значения меняется, docs/19 §3 п. 3), пометка в таблице | coder | выпуск 1 (макет вкладки) | Кэш v2, тест порогов на границе (ровно 10 дней / 14 дней) |
| L4 | `/admin`: «Лидеры без права на приз» на странице месяца | coder | S15 docs/32 или раньше | Тест бюджета запросов страницы (docs/20 §1) |

### 3г. Вопросы владельцу

- **В3.1.** Убираем требование «пройти вопросы в боте» для рейтинга месяца? *Рекомендация:* да. Неделя его не требует, и в бете это единственная причина, по которой лидер пропадает из месяца.

---

## 4. Тема 4 — блокировка бота и лимиты Telegram

### 4а. Как сейчас по коду

**Клиент** `IrazasyedTelegramClient::call()`:

- 403 любого вида и 400 `chat not found` (при `mapForbidden`, то есть для `sendMessage` и `sendPhoto`) → `TelegramForbiddenException` с причиной: `bot_blocked`, `not_started` («can't initiate»), `user_deactivated`, `chat_not_found`, `forbidden`.
- Остальное → `TelegramApiException`, где `isApiResponse()` = true для любого 4xx (Telegram ответил отказом) и false для таймаута, cURL и 5xx («могло дойти»). Токен вырезается (`SecretRedactor`), `previous` не сохраняется.
- **429 отдельно не распознаётся**: это обычный `TelegramApiException` (4xx), `retry_after` из `parameters` теряется. **400 `migrate_to_chat_id`** (группа стала супергруппой) тоже не распознаётся — это K1 в docs/31.

**Флаг недоступности:** `learners.dm_blocked_at` (timestamp, есть частичный индекс для напоминаний).

| Кто ставит | Кто снимает |
|---|---|
| `my_chat_member` в личном чате со статусом `kicked` → `DmLearnerResolver::markBlocked()` | `my_chat_member` в личном чате со статусом `member` → `markReachable()` |
| 403 в `ReminderSender`, `RenewalReminderSender`, `LeagueResultSender` (прямой `update`), `MonthWinnerSender`, `ReferralMilestoneSender`, `AchievementSender`, `GameCardSender`, `FriendsMessenger`, `BattleMessenger`, `OfferMessenger`, `OrganizationMessenger`, слушатели организаций | **Любое сообщение или нажатие в личном чате**: `TelegramDispatcher` вызывает `markReachable()` до обработчика; `/start` → `registerLearner()` |

Причина не хранится, а ставят флаг двумя способами: через `DmLearnerResolver` и прямым `update`.

**Кто учитывает флаг:** `ReminderDispatcher` (в SQL), все отправители выше (`blocked_at || dm_blocked_at` → пропуск), `LeagueService::queueResultMessages`, `OrganizationMembershipService` (приглашения).

**Скорость:**

- Общий бюджет фоновых отправок: `telegram.outbound.global_per_second` = 25/с (`TelegramMessageRateLimiter::reserveGlobal`, ключ Redis `telegram:outbound:global:{сек}`). Его берёт посредник задания `ThrottleTelegramOutbound` (≈17 заданий и слушателей); синхронные пути используют `awaitGlobal()` (`LeagueResultSender::sendBatch`, `AssignmentService`).
- На чат: скользящее окно 20 сообщений за 60 с (`telegram.outbound_rate_limit`) в `sendMessage()`. При превышении сообщение откладывается заданием `SendTelegramMessageJob`.
- Напоминания разносятся с темпом 25/с (`learning.reminders.send_rate_per_second`), итоги лиги — пачками по 100 (`SendLeagueResultsJob`).
- Ответы на входящие обновления общий бюджет не берут.
- Массовых рассылок из `/admin` **нет**. Самые большие волны — напоминания, итоги недели и победители месяца.

**Что попадает в `failed_jobs` или теряется:**

| Ситуация | Что происходит | Риск |
|---|---|---|
| 403 ученику в фоновом задании | Корректно: маркер удалён, флаг поставлен, задание завершено без ошибки | — |
| 403 / chat not found в `SendTelegramMessageJob` (отложенное сообщение) | Задание завершается, **флаг не ставится** | низкий |
| 403 в группу (бота удалили), `PostLeaderboardJob`, `PostClassCaravanSummaryJob` | Исключение пробрасывается → 3 попытки → **`failed_jobs`**; группа не получает статус `disabled` (только через `my_chat_member`) | средний |
| 429 в `ReminderSender`, `AchievementSender`, `GameCardSender`, `FriendsMessenger`, `BattleMessenger`, `LeagueResultSender` | Маркер остаётся (ошибка считается «могло дойти»), повтор видит маркер → **сообщение потеряно**; `LeagueResultSender` пишет `report()` → шум в Sentry | **высокий** при волнах |
| 429 в `MonthWinnerSender`, `ReferralMilestoneSender`, `RenewalReminderSender` | Маркер удалён (`isApiResponse`), повтор через `backoff`, а не через `retry_after`; после 3 исключений — `failed_jobs` | средний |
| 429 в `GroupSessionAnnouncer` (урок в группе) | Считается окончательным отказом: **урок отменяется**, а отправка текста об ошибке тоже получит 429 | средний |
| 429 в `AssignmentService` | Счётчик неудач +1, повтор при следующем `assignments:refresh` | низкий |
| Повторный 429 без паузы | Остальные задания продолжают слать 25/с, а Telegram за игнорирование `retry_after` наказывает дольше | средний |

### 4б. Лимиты Telegram Bot API

**Сверено с официальной документацией 25.11.2026** (core.telegram.org/bots/faq, «My bot is hitting limits, how do I avoid this?»; core.telegram.org/bots/api, `ResponseParameters`, `sendMessage.allow_paid_broadcast`): цифры ниже подтверждены. Уточнения: платные рассылки требуют не меньше 100 000 ⭐ на балансе и 100 000 активных пользователей в месяц, плата берётся только за успешно доставленные сообщения сверх бесплатных 30/с. Сам формат ответа 429 в документации описан общо: `error_code` + `parameters.retry_after` («сколько секунд ждать до повтора»); что повторное нарушение удлиняет ожидание, в документации **не** написано — это практика, на неё код не опирается.

| Лимит | Значение | У нас |
|---|---|---|
| Один личный чат | не чаще ~1 сообщения в секунду (короткие всплески терпимы) | Окно 20 за 60 с — всплеск из 20 сообщений за секунду в один личный чат формально возможен. На практике фоновые задания одному ученику так не шлют |
| Одна группа | не больше 20 сообщений в минуту | 20 за 60 с ✔ |
| Массовые уведомления в сумме | ~30 сообщений в секунду | 25/с для фоновых ✔, но ответы на обновления не считаются: на пике волны + ответы возможно > 30 |
| Платные рассылки | `allow_paid_broadcast = true` — до ~1000 сообщений в секунду за Stars с баланса бота (~0,1 ⭐ за сообщение сверх бесплатных 30/с); требования к боту — порог баланса Stars и активной аудитории | Не используем. При нашем объёме **не включать** |
| 429 | Ответ `{"ok":false,"error_code":429,"parameters":{"retry_after":N}}`; до N секунд запросы отклоняются; повторное нарушение увеличивает N | Не обрабатывается |
| Правки сообщений, `answerCallbackQuery` | Входят в общий поток; правки в группах тоже ограничены | Правок немного, кубок (docs/33) добавит — учтено там |

### 4в. Что спроектировать

**1. Единая классификация ошибок отправки** (в `App\Domain\Telegram\Exceptions`, без смены интерфейса `TelegramClient`):

| Класс | Когда | Доставлено? | Что делает отправитель |
|---|---|---|---|
| `TelegramForbiddenException` (есть) | 403, 400 chat not found | нет, и не будет | Удалить маркер, отметить адресата недоступным, завершить без ошибки |
| **`TelegramRateLimitedException extends TelegramApiException`** (новый, `retryAfter: int`, `isApiResponse() = true`) | 429 | нет | Удалить маркер, пробросить; посредник отложит задание на `retryAfter` |
| **`TelegramChatMigratedException`** (новый, `newChatId`) | 400 с `parameters.migrate_to_chat_id` | нет | Делает K1 docs/31 (обновить `telegram_groups.chat_id`, повторить) |
| `TelegramApiException`, `isApiResponse() = true` | прочие 4xx (наша ошибка в запросе) | нет | Удалить маркер, пробросить, `report()` |
| `TelegramApiException`, `isApiResponse() = false` | таймаут, 5xx, сеть | неизвестно | Маркер оставить, пробросить (как сейчас) |

Распознавание — в `IrazasyedTelegramClient::call()` по `TelegramResponseException::getResponseData()['parameters']`. Для 429 это делается **для всех методов**, а не только при `mapForbidden`.

**2. Обработка 429 без потери сообщений:**

- Клиент при 429 ставит паузу `telegram:outbound:pause_until` (Redis, TTL = `min(retry_after, 60)` с) и паузу этого чата `telegram:outbound:pause:{sha256(chat)}`.
- `reserveGlobal()` возвращает false, пока действует общая пауза, `reserve($chatId)` — пока действует пауза чата. Тогда все фоновые отправки сами ждут, и задания откладываются через существующий `release()`.
- `ThrottleTelegramOutbound` ловит `TelegramRateLimitedException` от обработчика и делает `$job->release(retryAfter + random_int(0, 3))`, то есть исключение не съедает `maxExceptions`.
- `LeagueResultSender::sendBatch`: при 429 удалить маркер текущего ученика, вернуть его и остальных в `remaining` и остановить пачку; задание поставит остаток с задержкой `retryAfter`.
- `GroupSessionAnnouncer`: при 429 с `retry_after ≤ 3` подождать и повторить один раз, иначе отменить урок, но не слать текст об ошибке в тот же чат (он тоже получит 429).
- `AssignmentService`: при 429 снять захват **без** увеличения счётчика неудач и прекратить прогон (остальное — в следующий запуск).

**3. Флаг недоступности с причиной и датой:**

- Миграция: `learners.dm_blocked_reason varchar(24) null` (значения: `bot_blocked`, `not_started`, `user_deactivated`, `chat_not_found`, `forbidden`, `kicked` — из `my_chat_member`). `down()` удаляет колонку. Имя `dm_blocked_at` не меняем (на нём индекс и десяток запросов).
- Один сервис `App\Domain\Telegram\Services\DmReachability`:
  - `markUnreachable(int $telegramUserId, string $reason, ?CarbonImmutable $at = null)` — ставит дату только если её не было, причину обновляет всегда, событие `bot.dm_closed {reason, via}` — только при переходе;
  - `markReachable(int $telegramUserId, string $via)` — снимает дату и причину, событие `bot.dm_reopened {via}` — только при переходе.

  `DmLearnerResolver::markBlocked/markReachable/registerLearner` делегируют ему. Все прямые `update(['dm_blocked_at' => …])` (`ReminderSender::markDmBlocked`, `RenewalReminderSender`, `LeagueResultSender`) заменяются вызовом сервиса.
- Тест-страж: в `app/` нет записи `dm_blocked_at` вне `DmReachability` (поиск по исходникам в Pest).
- Остановка всех уведомлений уже есть: все отправители ученику проверяют флаг. Добавить проверку в `SendTelegramMessageJob` (при 403 — `markUnreachable`, если чат личный, то есть id > 0) и в `TrialOfferSender` (тема 2).
- Снятие флага уже есть (`/start`, любое сообщение и нажатие, `my_chat_member member`) — только перевести на сервис. Открытие Mini App флаг **не** снимает: оно не доказывает, что бот разблокирован.
- Группы: при 403 в группу (`bot was kicked`, `not a member`) — `TelegramGroup::update(['status' => 'disabled'])` через Eloquent, чтобы сработал `TelegramGroupObserver`, как в `MyChatMemberHandler`. Задание завершается без ошибки. Это касается `PostLeaderboardJob`, `PostClassCaravanSummaryJob`, `ClassCaravanMessenger`, `AssignmentService`, `GroupSessionAnnouncer`.

**4. Метрика и `/admin`:**

- Дашборд: строка «Заблокировали бота: N (за 7 дней: M)» в `TotalLearnersWidget` или `DashboardKpiWidget`. Число — из кэша на 60 с, как `PendingLeagueMonthsBadge` (docs/20 §1). Запрос `count(*) where dm_blocked_at is not null and deleted_at is null` и тот же с `dm_blocked_at >= now() - 7 days`.
- `LearnerResource`: фильтр «Бот недоступен» и колонка причины (переводы в `lang/{ru,uz}/admin.php`), тест бюджета запросов списка.
- Продуктовые события `bot.dm_closed` и `bot.dm_reopened` позволяют позже построить график.

**5. Общий ограничитель:** ответы на входящие обновления не ждут, но **учитываются** в общем счётчике. Метод `TelegramMessageRateLimiter::consumeGlobal()` (только INCR, без отказа) вызывает посредник у `HandleTelegramUpdate` — по одному слоту на обновление. Так фоновые волны уступают место ответам, и вместе они не превышают ~30/с.

**6. Чистка «мёртвых» адресатов:** учеников **не удаляем** — данные обучения и оплаты им принадлежат, а Mini App может работать и без личного чата. «Мёртвые» (`user_deactivated`, `chat_not_found`) уже исключены из всех рассылок флагом. Дополнительно: `ReminderDispatcher` не ставит им новый `next_reminder_at` (сейчас ставит, просто не выбирает). Удалённый аккаунт Telegram сам не вернётся.

**7. Mini App (по желанию):** если `/me.dm_reachable = false`, на экране «Сегодня» показать баннер «Напоминания не приходят: бот заблокирован» с кнопкой-ссылкой `t.me/<bot>`.

**8. Спецификация для отправителей (B3, B4) — по итогам B1.** Сделано в B1 (architect, 25.11.2026): классы ошибок (docs/04 B.7a), пауза после 429 и `consumeGlobal` (docs/13 §9, 2R.3b), перехват 429 в `ThrottleTelegramOutbound`, перенос группы при `migrate_to_chat_id` (клиент повторяет отправку сам, отправителю делать ничего не нужно), `FakeTelegramClient::$failSendsRetryAfter` для тестов. Лимиты Telegram сверены с core.telegram.org/bots/faq и /bots/api 25.11.2026: §4б верна (1 сообщение/с в личный чат с допуском всплесков, 20/мин в группу, ~30/с всего; платные рассылки — до 1000/с за 0,1 ⭐, требуют 100 000 ⭐ на балансе и 100 000 активных пользователей в месяц; `ResponseParameters.retry_after` и `migrate_to_chat_id`).

Общее правило для каждого отправителя с маркером идемпотентности (строка `notifications_log`, ключ кэша, колонка `*_posted_at`):

```php
try {
    $this->telegram->sendMessage(...);            // или sendWebAppButton / sendPhoto
} catch (TelegramForbiddenException $e) {
    // как сейчас: маркер удалить, адресата отметить недоступным (после B2 — DmReachability::markUnreachable), вернуть без ошибки
} catch (TelegramApiException $e) {           // ловит и TelegramRateLimitedException, и TelegramChatMigratedException
    if ($e->isApiResponse()) {
        $log->delete();                        // точно не доставлено: повтор должен отправить
    }                                          // таймаут / 5xx — маркер остаётся (не больше одного раза)
    throw $e;                                  // 429: ThrottleTelegramOutbound отложит задание на retry_after
}
```

Эталон — `ReminderSender` (сделан в B1 вместе с вопросом-напоминанием: при отказе удаляется и состояние вопроса; тесты `ReviewReminderTest` «deletes the log row on a 429…», «…another 4xx…» и `ReminderQuizSendTest` «drops the question… on a 429»). Тест на каждого отправителя — тот же: `$tg->failSends = 1; $tg->failSendsRetryAfter = N` → исключение `TelegramRateLimitedException`, маркера нет; повторный вызов доставляет ровно одно сообщение; `failSendsAsTransport = true` → маркер остаётся, повтор молчит.

| Отправитель | Маркер | Что сделать |
|---|---|---|
| `AchievementSender`, `GameCardSender` (`sendPhoto`) | `notifications_log` | Добавить ветку `TelegramApiException` по образцу выше |
| `FriendsMessenger::deliver`, `BattleMessenger::deliver` | `notifications_log` | То же внутри `deliver()` |
| `RenewalReminderSender::dm` | `notifications_log` у ученика или ключ `billing:renewal-reminder:dm:{key}` без ученика | При `isApiResponse()` — `$log?->delete()` и `Cache::forget('billing:renewal-reminder:dm:'.$key)` (только если ставили его). В циклах `notifyOwners` / по ученикам: `TelegramRateLimitedException` пробрасывать **сразу** (не копить «первую ошибку» и не слать остальным — они тоже получат 429) |
| `MonthWinnerSender`, `ReferralMilestoneSender` | `notifications_log` | Маркер уже удаляется по `isApiResponse()` — 429 покрыт. В цикле победителей `MonthWinnerSender` — `TelegramRateLimitedException` пробрасывать сразу, как выше |
| `OfferMessenger`, `OrganizationMessenger` (+ слушатели через `RunsDmWithoutRetryOnSyncQueue`) | после отправки (`markAnnounced`) или нет | Код не менять: исключение и так выходит наружу и откладывается посредником. Нужен только тест «429 → повтор доставляет один раз» |
| `LeagueResultSender::sendBatch` | `notifications_log` в `deliver()` | В `deliver()` — ветка `TelegramApiException` по образцу. В цикле `sendBatch`: `catch (TelegramRateLimitedException)` **перед** `catch (TelegramApiException)` → `$outcome['remaining'] = array_slice($memberIds, $i); break;` (текущий участник тоже в остатке; без `report()`). Задание поставит остаток с `globalRetryDelay()` — во время паузы это остаток паузы + 0–3 с, отдельная задержка не нужна. Прочие ошибки — как сейчас (`failed++`, `report()`), но маркер при `isApiResponse()` уже удалён в `deliver()` |
| `SendTelegramMessageJob` | нет | 429 уже работает (исключение → посредник). 403 при `chatId > 0` → `DmReachability::markUnreachable` (после B2), при `chatId < 0` — группа, см. B4 |
| `PostLeaderboardJob`, `PostClassCaravanSummaryJob` (группа) | колонка `*_posted_at` пишется **после** отправки | 429 уже работает. B4: `catch (TelegramForbiddenException)` → `TelegramGroup::query()->whereKey($group->id)->first()?->update(['status' => 'disabled'])` (через Eloquent — наблюдатель), лог, задание завершается без ошибки, маркер не ставится |
| `ClassCaravanMessenger::send` | нет (ответ на команду) | B4: 403 → `disabled` как выше; 429 — только лог (это ответ на команду в вебхуке, не ждём) |
| `GroupSessionAnnouncer::announce` | нет (сессия отменяется через `$cancel`) | B4: `TelegramRateLimitedException` с `retryAfter ≤ 3` → `sleep(retryAfter)` и один повтор той же отправки; иначе (или повтор снова 429) — `$cancel()`, но **без** `sendMessage($failureText)` в этот чат. `TelegramForbiddenException` → `$cancel()` и группа `disabled` |
| `AssignmentService::post` | колонка-захват `announced_at` / `reminded_at` | B4: `catch (TelegramRateLimitedException)` перед общей веткой → снять захват (`$column => null`) **без** `*_failures + 1`, `$this->slotDenied = true` (прогон `assignments:refresh` остановится), `return false`. 403 → группа `disabled` и снять захват |

`TelegramChatMigratedException` до отправителя доходит только из правок сообщения или при двух переносах подряд — это обычная «определённая» ошибка по таблице выше. Ловить его отдельно не нужно.

**Сводка: сделано / не хватает / риск**

| Что | Сделано | Не хватает | Риск |
|---|---|---|---|
| 403 и chat not found ученику | ✔ классификация, флаг, остановка, снятие | причина, один сервис, `SendTelegramMessageJob` | низкий |
| 403 в группе | частично: ловится в `GroupSessionAnnouncer`, `AssignmentService` | `PostLeaderboardJob`, `PostClassCaravanSummaryJob` → `failed_jobs`; статус группы не меняется | средний |
| 429 | ✗ | распознавание, `retry_after`, пауза, удаление маркеров | **высокий** (потеря сообщений, бан на минуты при волнах) |
| Переход в супергруппу | ✗ | K1 docs/31 | средний |
| Общий лимит 30/с | ✔ 25/с для фоновых | учёт ответов | низкий |
| 1/с на личный чат, 20/мин на группу | ✔ 20/мин на чат | сглаживание всплесков в личный чат (необязательно) | низкий |
| Видимость в `/admin` | ✗ | счётчик, фильтр, причина | низкий (слепое пятно) |
| Платные рассылки | не нужны | — | — |

### 4г. Задачи

| № | Задача | Кто | Зависит от | Definition of Done и ключевые тесты |
|---|---|---|---|---|
| B0 | Docs: docs/04 (бот: классификация ошибок, снятие флага), docs/13 §9 (исходящие: пауза 429, учёт ответов), docs/03 (`dm_blocked_reason`), ADMIN_GUIDE (счётчик), DEVOPS_GUIDE (что значит запись в `failed_jobs`) | координатор | — | docs согласованы |
| B1 ✔ | **Классификация в клиенте:** `TelegramRateLimitedException`, `TelegramChatMigratedException` (совместно с K1), паузы в `TelegramMessageRateLimiter`, перехват 429 в `ThrottleTelegramOutbound` | **architect** | B0 | Поддельные ответы SDK 429 / 403 / 400 migrate / 400 other / 5xx / таймаут → нужный класс; токен не утекает; пауза блокирует `reserveGlobal` и `reserve`; задание после 429 откладывается на `retry_after`, `maxExceptions` не тратится; два воркера на Redis видят одну паузу |
| B2 | Миграция `dm_blocked_reason`, сервис `DmReachability`, перевод всех мест на него, события `bot.dm_closed` / `bot.dm_reopened`, тест-страж | coder | B0 | Переходы закрыт ↔ открыт: событие один раз; повторный `kicked` не сдвигает дату; `/start` снимает; `DmReachabilityTest` и `ReminderDispatcher` зелёные |
| B3 | Отправители: при 429 удалить маркер и пробросить (`ReminderSender`, `AchievementSender`, `GameCardSender`, `FriendsMessenger`, `BattleMessenger`, `OfferMessenger`, `OrganizationMessenger`, `RenewalReminderSender`, `MonthWinnerSender`, `ReferralMilestoneSender`), `LeagueResultSender::sendBatch` — остановка пачки, `SendTelegramMessageJob` — флаг при 403 | coder по спецификации B1 | B1, B2 | По тесту на каждого отправителя: 429 → маркер удалён, повтор доставляет ровно один раз; таймаут → маркер остаётся (как сейчас) |
| B4 | Группы: 403 → `disabled` через Eloquent, задание без ошибки; 429 в `GroupSessionAnnouncer` и `AssignmentService` по §4в п. 2 | coder (ревью architect) | B1 | `PostLeaderboardJob` с 403 не попадает в `failed_jobs`, группа `disabled`, наблюдатель сбросил тариф участников; урок не отменяется при 429 с `retry_after ≤ 3` |
| B5 | `/admin`: счётчик на дашборде, фильтр и колонка в `LearnerResource` | coder | B2 | Тест бюджета запросов страницы (docs/20 §1), переводы ru/uz |
| B6 ✔ | `consumeGlobal()` для `HandleTelegramUpdate` (сделал architect вместе с B1) | coder | B1 | Ответ на обновление никогда не ждёт; счётчик секунды растёт |
| B7 | `ReminderDispatcher`: не вести `next_reminder_at` для `user_deactivated` / `chat_not_found` | coder | B2 | Тест выборки |
| B8 | Mini App: баннер «бот заблокирован» (`/me.dm_reachable`) | coder | B2, выпуск 1 | vitest |
| B9 | Проверка на живом боте (стенд): заблокировать бота → напоминание → флаг; разблокировать → `/start` снимает; удалить бота из группы → итоги экзамена не в `failed_jobs`; искусственный всплеск в один чат → 429 обработан | владелец / devops | B1–B4 | Чек-лист в STAGING-документе |

---

## 5. В какой выпуск docs/31 §2 встаёт каждая тема

| Тема | Задачи | Выпуск | Почему |
|---|---|---|---|
| 1. Параметры тарифов | T1–T4 | **Сразу, отдельной мелкой поставкой до выпуска 0** | Только конфиг, docs и тесты; ни от чего не зависит |
| 2. После пробного Pro — сервер и бот | P0–P3, P5, P6, P8 | **Сразу после T2, параллельно с выпуском 0**. Крайний срок — через 14 дней после включения нового пробного периода, когда закончатся первые пробные | Иначе первые 14-дневные пробные закончатся молча |
| 2. После пробного Pro — экраны | P4, P7 | **Выпуск 1** (редизайн: настройки и пейвол перерисовываются). Если выпуск 1 позже крайнего срока — сделать минимально в текущем дизайне | Не рисовать экраны дважды |
| 3. Месяц без Umar | L0–L2 | **Сразу, вместе с выпуском 0** | Одно условие и одно поле; декабрьские итоги должны быть честными |
| 3. Пороги в таблице и `/admin` | L3, L4 | **Выпуск 1** (вкладка «Лига») и **выпуск 3** (S12, S15) | Экраны и ресурс таблицы меняются там |
| 4. Бот без падений | B0–B7, B9 | **Выпуск 0 «Починка групп»** | Тот же слой Telegram: K1 (`migrate_to_chat_id`) использует ту же классификацию, K2/K3 — статус группы и отправка в группу |
| 4. Баннер в Mini App | B8 | **Выпуск 1** | Экран «Сегодня» перерисовывается |

## 6. Риски для ревью

- **Гонки вокруг цели дня (P2).** Снижение цели засчитывает день и продлевает серию. Порядок блокировок `learners` → (после фиксации) `learner_daily_stats` нарушать нельзя: при совместной блокировке возможна взаимная блокировка с транзакцией ответа. Тест на PG с двумя соединениями обязателен.
- **Звёздная подписка во время 14-дневного пробного периода.** `CheckoutService::assertCanBuyOver` отказывает в покупке Stars-месяца, пока до конца живого периода больше `billing.stars.subscribe_window_days` (7) дней: первую неделю пробного периода ученик получит `subscribe_too_early`. Сообщения −3 и 0 в окно попадают, пейвол из настроек (P4) в первую неделю — нет. На ревью P4 проверить, что пейвол предлагает квартал, год или сумы, или явно объясняет отказ.
- **Потеря сообщений при 429 (B3).** Сейчас 429 в половине отправителей оставляет маркер. Это нужно закрыть до волн выпуска 3 (итоги недели и месяца в двух таблицах — Free и Pro).
- **Шум предложений.** Цепочка + напоминания о продлении + учебные напоминания. Правило «одно сообщение о Pro в сутки» проверить тестом вместе с `RenewalReminderSender`.
- **Прод-env.** Без смены `.env` на сервере новые умолчания не действуют (T4). После смены — `config:cache` и перезапуск Octane и воркеров, иначе старые числа живут до перезапуска.
- **Тексты с ценами.** Цены берутся только из каталога на момент отправки. Тест должен падать, если в `lang` появится зашитая цена.
