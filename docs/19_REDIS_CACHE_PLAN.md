# 19. Redis 8.10 и кэширование — план и задачи

> **Статус:** C1 и C2 выполнены 02.11.2026 (architect); C3–C6 выполнены 02.11.2026 (coder, §5.1); C0 (полный замер), C7, C8 — план. Правила §3 действуют для всех новых задач с 02.11.2026.
> **Основание — решение владельца 02.11.2026:**
> - всё, что можно кэшировать, — в Redis;
> - версия Redis — 8.10;
> - приложение ещё не запущено, поэтому конфликтов со старыми версиями Redis не будет.
>
> **Связано:** `20_FILAMENT_PERFORMANCE.md` — кэш виджетов и деплой Filament; `DEVOPS_GUIDE.md`; `07_SECURITY.md`.

## 1. Версия Redis 8.10

- **Прежняя причина держать 8.4 больше не действует.** 8.4 держали ради отката на коммит до обновления стека (`DEVOPS_GUIDE.md` §0 «Redis», решение U-обновления в `PROGRESS.md`): там был `redis:7.4`, который не читает RDB 13+, а 8.6+ пишут именно такой формат. Приложение не запущено, данных в Redis на проде нет, откатываться не на что.
- **Тег закрепляется точный — `redis:8.10-alpine`,** а не плавающий `redis:8-alpine`: образ воспроизводим, следующее обновление делается осознанно.
- **Последствие:** 8.10 пишет RDB 15. Обратно на 8.4 том Redis уже не поднимется. На проде это не важно, а на деве — пересоздать том `redisdata`, если понадобится старая версия.
- **Защитное монтирование тома PostgreSQL 16 (`pgdata`)** в эту задачу не входит, его снятие — отдельное решение. Комментарий в compose, связывающий эти два шага, удаляется.

**Где менять** — задача C1 (✅ выполнено 02.11.2026 вместе с C2):

| Файл | Что |
|---|---|
| `docker-compose.yml` (сервис `redis`) | `redis:8.10-alpine`, комментарий о закреплении |
| `docker-compose.prod.yml` (сервис `redis`) | то же |
| `.github/workflows/ci.yml` (job `test`, сервис `redis`) | `redis:8.10-alpine` — тесты на той же версии, что прод |
| `docs/DEVOPS_GUIDE.md` §0, абзац «Redis» | новое правило и причина |
| `PROGRESS.md` | решение «Redis закреплён на 8.4» помечено как заменённое; пункт «Следующий релиз» — только про `pgdata` |
| `docs/VPS_DEPLOY_GUIDE_RU.md` §5 | ставить 8.10 или новее из `packages.redis.io` |

## 2. Что есть сейчас

### 2.1. Инфраструктура

> Состояние до C2 (02.11.2026); что стало — §4.

- **Один экземпляр Redis:** AOF (`appendfsync everysec`), `maxmemory 256mb`, `maxmemory-policy noeviction`.
- **Подключения в `config/database.php`:**
  - `default` — DB 0: очереди и сессии;
  - `cache` — DB 1: хранилище кэша `redis`;
  - `locks` — DB 4: блокировки кэша.
- **Переменные:** `CACHE_STORE=redis`, `SESSION_DRIVER=redis`, `QUEUE_CONNECTION=redis`.
- **`cache.serializable_classes = false`** (с обновления до Laravel 13): в кэше только массивы и скаляры, объекты не десериализуются.
- **Ограничения скорости** (throttle-мидлвары, `RateLimiter`) пишут в хранилище по умолчанию — тот же Redis.
- **Кэши фреймворка:** `config:cache`, `route:cache`, `view:cache`, `event:cache` в `docker/entrypoint.sh` — есть. `filament:optimize` — нет (`docs/20`, F1).
- **OPcache** — с настройками по умолчанию, свои значения в `docker/php/zz-lexiflow.ini` не заданы (задача C7).

**Почему один экземпляр — проблема для «кэшировать всё».** При `noeviction` заполненный кэш ломает запись очередей и сессий: Redis отвечает OOM на любую запись. Если же включить вытеснение, Redis может выкинуть ключи лимитов и защиты 2FA. Тогда атака «забить память кэшем → сбросить лимит попыток» становится реальной. Решение — §4 (выполнено в C2).

### 2.2. Что уже кэшируется

| Где | Ключ | TTL | Вид |
|---|---|---|---|
| `Billing/Services/EntitlementResolver` | `entitlement:learner:{id}:v{версия}`, версия — `entitlement:ver:{id}` | `BILLING_CACHE_TTL` (300 с) | кэш с версией |
| `Content/Services/CourseCatalogService` | `catalog:courses:published:v1:{locale}` | 600 с | кэш |
| `Learning/Services/LearningCache` | слова урока, лидерборд экзамена, «выучено слов» друзей | 3600 / 3600 / 600 с | кэш |
| `Administration/Services/PlatformStatsService` | `platform_stats:subscriptions`, `platform_stats:funnel` | 120 с | кэш виджетов |
| `Engagement/Services/LeagueService` | `league:standings:v1:{группа}` | `LEAGUES_STANDINGS_CACHE_SECONDS` (60 с) | кэш |
| `Learning/Services/MockExamService` | `mock_exam:distribution:course:{id}` | конфиг | кэш |
| `Filament/Widgets/SchedulerComparisonWidget` | `admin:scheduler-comparison:v1` | — | кэш виджета |
| `Learning/Services/TrainingFlowService` | `paywall_shown:{learner}:{окно}` | окно квоты | **маркер** (событие раз в окно) |
| `Learners/Services/ReminderSender`, `Jobs/PostLeaderboardJob` | `Cache::lock(...)` | — | **блокировка** |
| `Organizations/Services/OrgBillingService` | маркеры запроса счёта | 10 мин, сутки | **защита от злоупотреблений** |
| `Billing/Services/RenewalReminderSender`, `Listeners/Billing/QueueRenewalReminder` | маркеры и счётчики | до 45 дней | **дедупликация ЛС** |
| `Administration/Services/TwoFactorAttemptService` | счётчики попыток, `2fa:step:{user}:{step}` | 60 с, 3600 с, 180 с | **безопасность** |
| `Telegram/Services/TelegramMessageRateLimiter` | счётчики и блокировки | — | **лимиты Telegram** |
| `Practice/Services/PracticeAudioService` | `practice:audio-missing:{session}:{element}` | 600 с | **маркер** |
| `Administration/Services/AdminDashboardStats` + `WidgetCache` (C3) | `admin:widget:<виджет>:v1:<зритель>[:<параметры>]`, зритель — `PanelDataScope::fingerprint()` | свежий 60–300 с, ещё ×4 отдаётся устаревшим (`flexible`) | кэш виджетов `/admin` |
| `Organizations/Services/OrgOverviewService::cachedSummary()` (C3) | `org:{id}:overview:v1:n{версия}:{owner \| u{id}g{хэш групп}}`; версия `org:{id}:overview:ver` | 90 с | кэш с версией |
| `Content/Services/CourseCatalogService` — дерево курса (C4) | `catalog:course:v1:{id}:{locale}:n{версия}`; версия `catalog:course:ver:{id}` | 1800 с | кэш с версией |
| `Content/Services/WordTranslationResolver` (C4) | `word:tr:v1:{слово}:{locale}` — строки `word_translations`, пачкой `many`/`putMany` | 7200 с | кэш |
| `Site/Services/SitePageCache` (C5) | `site:page:v1:{страница}:{locale}:{отпечаток релиза}` — готовый HTML | 86400 с | кэш страниц |
| `Telegram/Services/ChatMemberStatusCache` (C6) | `tg:member:v1:{чат}:{пользователь}` — статус бота в группе | 600 с | кэш |

**Кэш** можно потерять без вреда: значение пересчитается. **Маркеры, блокировки, счётчики и защита** терять нельзя: при вытеснении появятся дубли ЛС, сброс лимитов, повтор TOTP-кода.

**Отдельный случай — версия entitlement.** Ключ версии без TTL, значение — с TTL. Если вытеснить ключ версии, версия снова станет 0, и может вернуться старое значение `:v0` в пределах TTL — например, Pro после возврата денег. Поэтому с C2 значение лежит в `lru`, а ключ версии — в невытесняемом `redis` (тест `PureCacheStoresTest`: Pro → возврат → потеря всего `lru` → Free).

**Кэш → хранилище после C2** (`CACHE_PURE_STORE=lru` — чистый кэш через `PureCache::store()`; всё остальное — `Cache::` по умолчанию, невытесняемое):

| Где | Хранилище |
|---|---|
| `EntitlementResolver` — снимки `entitlement:learner:{id}:v{n}` | `lru` |
| `EntitlementResolver` — ключ версии `entitlement:ver:{id}` | `redis` (невытесняемое) |
| `CourseCatalogService` — `catalog:courses:published:v1:{locale}` | `lru` |
| `LearningCache` — слова урока, лидерборд экзамена, «выучено слов» | `lru` |
| `PlatformStatsService` — `platform_stats:*` | `lru` |
| `LeagueService` — `league:standings:v1:{группа}` | `lru` |
| `AdminDashboardStats` — `admin:widget:*` (C3) | `lru` |
| `OrgOverviewService` — значения `org:{id}:overview:v1:*` (C3) | `lru` |
| `OrgOverviewService` — ключ версии `org:{id}:overview:ver` (C3) | `redis` (невытесняемое) |
| `CourseCatalogService` — значения `catalog:course:v1:*` (C4) | `lru` |
| `CourseCatalogService` — ключ версии `catalog:course:ver:{id}` (C4) | `redis` (невытесняемое) |
| `WordTranslationResolver` — `word:tr:v1:*` (C4) | `lru` |
| `SitePageCache` — `site:page:v1:*` (C5) | `lru` |
| `ChatMemberStatusCache` — `tg:member:v1:*` (C6) | `lru` |
| `MockExamService` — `mock_exam:distribution:course:{id}` | `lru` |
| `SchedulerComparisonWidget` — `admin:scheduler-comparison:v1` | `lru` |
| `TrainingFlowService` `paywall_shown:*`, `PracticeAudioService` `practice:audio-missing:*` — маркеры | `redis` |
| `ReminderSender`, `PostLeaderboardJob`, `RenewalReminderSender`, `TelegramMessageRateLimiter` — блокировки `Cache::lock` | `redis` (`locks`) |
| `OrgBillingService`, `RenewalReminderSender`, `QueueRenewalReminder` — маркеры и счётчики | `redis` |
| `TwoFactorAttemptService` — счётчики попыток (`RateLimiter`) и `2fa:step:*` | `redis` |
| `TelegramMessageRateLimiter` — счётчики | `redis` |
| `SchedulerHeartbeat`, `QueueHeartbeat` — пульсы `/health/ready` | `redis` |
| `RateLimiter` (throttle-мидлвары, логин, TWA) | `redis` (`cache.limiter`) |
| Сессии, очереди, `APP_MAINTENANCE_STORE` | `redis` |

Чистых кэшей в `Practice` не оказалось: `PracticeAudioService` хранит маркер события, `PracticeMaterialService` и `Modes/*` в кэш не пишут.

## 3. Правила кэширования

Обязательны для всех задач — правило в `CLAUDE.md`.

1. **Только Redis.** В проде нет file- и database-кэшей.
2. **В значениях — массивы и скаляры.** Модели, DTO и коллекции не кладём (`serializable_classes = false`). DTO хранятся через `toArray()` / `fromArray()`, как `Entitlement`.
3. **Ключ:** `<домен>:<сущность>:v<версия формата>:<id>[:<locale>]`, например `league:standings:v1:`. При смене формата значения версия увеличивается — старые ключи просто истекают.
4. **TTL есть всегда.** `rememberForever` — только для неизменяемых данных и с явным `forget`.
5. **Инвалидация при записи.** Сбрасывает тот сервис, который пишет данные, после фиксации транзакции (`DB::afterCommit`), или наблюдатель модели. На каждый ключ есть тест «записал → старое значение не отдаётся».
6. **Защита от лавины пересчёта.** Тяжёлые агрегаты — через `Cache::flexible()` (отдать устаревшее и пересчитать в фоне) или `Cache::lock()->block()` вокруг пересчёта.
7. **Пакетное чтение** — `Cache::many` / `putMany`, как `LearningCache::wordsLearned`. Не делаем N отдельных `get` в цикле.
8. **В ключах только id,** без имён, телефонов и токенов. В значениях нет секретов.
9. **Не кэшируем решения, которые принимаются под блокировкой БД:** списание квоты, деньги и статусы оплат, выдачу карточки в тренировке, финал матча и экзамена. Их показ на экранах можно кэшировать коротко, но решение всегда принимается по БД.
10. **Чистый кэш — в вытесняемом хранилище** (§4) только через `App\Domain\Operations\Support\PureCache::store()` (возвращает хранилище `config('cache.pure_store')`), лучше внутри доменного сервиса-обёртки, как `LearningCache`. **Маркеры, блокировки, счётчики, лимиты и защита** — только в невытесняемом: `Cache::` по умолчанию. При сомнении — невытесняемое.
11. **Метрики:** у каждого нового ключа понятно, как замерить эффект — число запросов до и после, время ответа.

## 4. Инфраструктура: два экземпляра Redis

✅ Выполнено в C2 (02.11.2026). Фактические имена ниже.

| Экземпляр (compose) | Что хранит | Политика | Сохранение на диск |
|---|---|---|---|
| `redis` | очереди, сессии, блокировки (`locks`), лимиты (`cache.limiter`), маркеры и защита, ключи версий entitlement | `noeviction` (зашито в compose, переменной больше нет) | AOF `everysec`, том `redisdata` |
| `redis-cache` | чистый кэш — хранилище `lru` | `volatile-lru`: вытесняются только ключи с TTL (у чистого кэша он есть всегда, §3 п. 4) | нет: `--appendonly no --save ""`, после рестарта пусто |

- **Подключения `config/database.php` → `redis`:** `default`, `cache`, `queue`, `session`, `locks` — первый экземпляр (`REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`); `lru` — второй (`REDIS_LRU_HOST`, `REDIS_LRU_PORT`, `REDIS_LRU_PASSWORD`, `REDIS_LRU_DB`; в Docker `redis-cache:6379`, на голом сервере `127.0.0.1:6380`).
- **Хранилища `config/cache.php`:**
  - `redis` — по умолчанию (`CACHE_STORE=redis`), невытесняемое: всё, что не помечено как чистый кэш, в безопасности;
  - `lru` — `driver redis`, `connection lru`, `lock_connection locks`: блокировки чистого кэша (`Cache::lock`, `Cache::flexible`) остаются на первом экземпляре;
  - `cache.pure_store` (`CACHE_PURE_STORE=lru`; в тестах `array`) — то, что отдаёт `PureCache::store()`;
  - `cache.limiter` (`CACHE_LIMITER_STORE=redis`; в тестах `array`) — `RateLimiter` явно на невытесняемом хранилище, от `CACHE_STORE` не зависит.
- **Точка доступа:** `App\Domain\Operations\Support\PureCache` — `store()` (репозиторий), `storeName()`, `redisConnection()`; контейнерный ключ `cache.pure` (`PureCache::CONTAINER_KEY`), который тест подменяет через `app()->instance(PureCache::CONTAINER_KEY, $spy)`.
- **Безопасность.**
  - Пароли: `REDIS_PASSWORD` и `REDIS_CACHE_PASSWORD`. В проде обязательны: `docker-compose.prod.yml` без них не стартует (`${VAR:?…}` с понятным сообщением). Контейнерам приложения compose (dev и prod) сам подставляет `REDIS_LRU_HOST=redis-cache`, `REDIS_LRU_PORT=6379`, `REDIS_LRU_PASSWORD=$REDIS_CACHE_PASSWORD` — один пароль в `.env`, нечему разойтись; `REDIS_LRU_*` в `.env` — для голого сервера и `artisan` на хосте. В деве — по желанию, пустое значение = без пароля (именно пустое, не `null`).
  - Пароль попадает в `redis-server` строкой конфига через stdin, не аргументом командной строки; healthcheck авторизуется через `REDISCLI_AUTH`. В `ps` пароля нет; в `docker inspect` (окружение контейнера) он виден — компромисс описан в `DEVOPS_GUIDE.md`.
  - В проде порты не публикуются. В деве оба порта только на `127.0.0.1` (`REDIS_PORT_HOST`=6379, `REDIS_CACHE_PORT_HOST`=6380), как и PostgreSQL.
- **Размер:** `REDIS_CACHE_MAXMEMORY` (по умолчанию 256 МБ), уточняется по метрикам C8; первый экземпляр — `REDIS_MAXMEMORY`.
- **Мониторинг:**
  - `/health/ready` проверяет оба: `redis` (подключения кэша и очереди) и `redis_cache` (подключение `lru`) — отдельные проверки, видно, какой упал;
  - метрики `INFO`: `keyspace_hits` / `keyspace_misses`, `evicted_keys`, `used_memory`;
  - алерт, если в `redis` растёт память или в `redis-cache` слишком много вытеснений (`DEVOPS_GUIDE.md`, «Redis»).
- **Тесты:** `tests/Feature/Operations/PureCacheStoresTest.php` — конфиг, точка доступа, потеря всего чистого кэша не трогает `RateLimiter`, шаг 2FA и пульсы, entitlement после потери кэша, readiness обоих экземпляров. CI (job `test`) гоняет Pest на PostgreSQL с двумя сервисами Redis (`CACHE_PURE_STORE=lru`); `docker/postgres/tests/run.sh` держит `redis-cache` в тестовом стеке.

## 5. Что добавить в кэш

Кандидаты. Каждый подтверждается замером C0: кэшируем то, что даёт эффект.

| # | Что | Где сейчас | Ключ (черновик) | TTL | Инвалидация |
|---|---|---|---|---|---|
| K1 | Виджеты админки без кэша: `ActivityChartWidget`, `ExamsLast30DaysWidget`, `TotalLearnersWidget`, `TopLearnersTableWidget`, `HardestWordsTableWidget`, `LeagueGroupsTableWidget`, `OrganizationSeatsTableWidget`, `RevenueLast30DaysWidget`, `WebhookErrorsWidget` | `app/Filament/Widgets` | `admin:widget:<имя>:v1:<фильтры>` | 60–300 с, `Cache::flexible` | по TTL; для денег — сброс после оплаты и возврата |
| K2 | Кабинет центра: обзор (`OrgOverviewService`) и виджеты `/org` | `Organizations` | `org:<id>:overview:v1` | 60–120 с | события `OrganizationMember{Joined,Left,Seated}`, задания |
| K3 | Дерево курса для `/courses/{slug}`: модули, уроки, число слов — без личного прогресса | `CourseCatalogService` / контроллер | `catalog:course:v1:<id>:<locale>` | 10–60 мин | публикация, импорт, правка урока (`CoursePublisher`, `VocabularyImporter`, `LessonContentRefresher`) |
| K4 | Переводы слов пачкой | `WordTranslationResolver` | `word:tr:v1:<word>:<locale>` через `Cache::many` | 1–24 ч | `WordTranslationWriter`, правка слова |
| K5 | Статичная часть каталога оплаты: планы, цены, провайдеры — без личных офферов | `LearnerBillingService` | `billing:catalog:v1:<hash конфига>` | до деплоя | хэш конфига в ключе |
| K6 | Страницы сайта `/`, `/offer`, `/privacy` (ru/uz): без cookies и CSRF, CSP `script-src 'none'` | `Site` | `site:page:v1:<страница>:<locale>:<версия релиза>` | до деплоя | версия релиза в ключе |
| K7 | Ответы Telegram `getChatMember` при проверках «бот — админ группы» и прав учителя | `GroupBotAccessChecker` и др. | `tg:member:v1:<chat>:<user>` | 5–10 мин | событие `my_chat_member` / `chat_member` |
| K8 | Обзор хаба практики (сколько материала по урокам) | `PracticeHubService` | по замеру | короткий | по замеру |
| K9 | Части `/today` и `/me`, которые не меняются от ответа к ответу (активный курс, блок заданий) | `TodayService`, `MePayloadBuilder` | решает architect | короткий | события ученика |

Не кэшируются (правило 9): квоты, деньги, выдача карточки, ход матча и экзамена, решения доступа.

### 5.1. Что сделано в C3–C6 (02.11.2026)

**Ключ → TTL → инвалидация → тест** (тесты — в `tests/Feature/Cache/`; весь набор прогнан и на SQLite, и на PostgreSQL 16 с настоящим вторым Redis):

| Ключ | TTL | Инвалидация | Тест |
|---|---|---|---|
| `admin:widget:total_learners \| exams_30d \| activity_chart \| top_learners \| hardest_words:v1:<зритель>` (K1) | 120 / 120 / 300 / 300 / 300 с свежий, всего ×5 | TTL; у чарта день в ключе; у учителя состав групп в ключе (смена групп — новый ключ сразу) | `AdminDashboardCacheTest` |
| `admin:widget:league_week:v1:all`, `league_groups:w{неделя}:p{стр}:n{размер}:v1:all`, `organization_seats:p{стр}:n{размер}:v1:all` (K1) | 60 / 60 / 120 с | TTL; страница и размер страницы — свои записи | там же |
| `admin:widget:revenue_30d \| webhook_errors:v1:all` — **деньги** (K1) | 120 / 60 с | `MoneyWidgetsObserver` после коммита записи в `payments` и `provider_transactions` (оплата, возврат, отказ) | там же |
| `org:{id}:overview:v1:n{версия}:{зритель}` (K2) | 90 с | счётчик версии: `ForgetOrgOverview` (`OrganizationMember{Joined,Left,Seated}`), `TelegramGroupObserver` (группа перешла между центрами или удалена), `SubscriptionService::forgetAfterCommit` (подписка центра) | `OrgOverviewCacheTest` |
| `catalog:course:v1:{курс}:{locale}:n{версия}` (K3) | 1800 с | счётчик версии: `CoursePublisher::recount()` (все пути, меняющие слова: импорт, правка слова, колоды), `transition()` (публикация), `CourseTreeObserver` (любое сохранение и удаление `Stage`/`Lesson`) | `ContentCacheTest` |
| `word:tr:v1:{слово}:{locale}` (K4) | 7200 с | `WordTranslationObserver` после коммита сохранения и удаления строки `word_translations` (ручная правка `WordTranslationWriter`, импорт, колоды); обе локали слова сразу | `ContentCacheTest` |
| `site:page:v1:{страница}:{locale}:{отпечаток}` (K6) | 86400 с | не сбрасывается: отпечаток = хэш перечисленного конфига (цены, режим paywall, провайдеры, лимиты, ссылка бота, `APP_URL`, переключатели практики; без секретов) и времён изменения шаблонов и lang-файлов страниц — деплой или смена цены дают новый ключ | `SitePageCacheTest` |
| `tg:member:v1:{чат}:{пользователь}` (K7) | 600 с | `MyChatMemberHandler` на каждое изменение статуса бота; `ChatMemberHandler` на `chat_member` | `TelegramMemberCacheTest` |

**Решения и отступления от черновика §5.**
- **K1 — кэш поверх быстрого запроса.** Тяжёлые виджеты индексами не лечатся (`docs/20` §4, «Индексы F2»), поэтому вместе с кэшем переписаны их запросы: один агрегат по `training_reviews` за 30 дней вместо коррелированных подзапросов на каждое слово и каждого ученика, для админа без пустого фильтра по сессиям, анти-джойн по удалённым ученикам с partial-индексом `learners_deleted_idx`. Первый показ после истечения кэша — 153 мс вместо 1,1 с (50 тыс. учеников); результат тот же, что у прежних запросов (`AdminDashboardQueriesTest`, админ и учитель). Устаревшее значение отдаётся до четырёх свежих сроков, пока читатель пересчитывает его после ответа под блокировкой.
- **K3 — версия в ключе, а не `forget`.** Импорт идёт долгой транзакцией: читатель, посчитавший дерево по старым строкам, мог бы записать его после сброса. Счётчик версии на невытесняемом хранилище (как `entitlement:ver:*`) делает такую запись безвредной. Дерево колоды ученика (`owner_type = learner`) не кэшируется: колода личная и часто правится — её дерево читается всегда. Личный прогресс (`done` по урокам) не кэшируется нигде: один запрос по строкам самого ученика.
- **K4 — кэшируются строки, а не итог.** Итоговый перевод зависит ещё и от `words.translation` (запасная колонка) — она читается из самого `Word` и всегда свежая; правка слова не требует сброса. Слово без строк — тоже запись (пустой массив).
- **K5 — Redis-ключа нет.** Статическая часть каталога (провайдеры, валюты, цены) — только чтение конфига, без SQL: **9 мкс** на вызов против **15 мкс** на чтение записи из Redis даже по loopback (замер C4, PHP 8.5 CLI). Кэш был бы медленнее пересчёта и добавил бы риск показать старую цену. Вместо него: `CheckoutService::canSellToLearners()` — статическая половина каталога; `LearnerBillingService::paywall()` спрашивает её, а не строит весь каталог с офферами и подпиской ученика (−3 запроса на `/me` в режиме `purchase`). Личная часть (офферы, подписка) по-прежнему не общая.
- **K6 — отпечаток вместо версии релиза.** В репозитории нет идентификатора релиза, а деплой и `docker/compose` в этой задаче не менялись; времена изменения файлов + хэш конфига дают то же свойство (новый деплой — новый ключ) и работают в деве (правка шаблона видна сразу). Тест сканирует источники страниц на вызовы `config()` и требует, чтобы каждый ключ был в отпечатке.
- **K7 — `getChatMember` зовётся не на каждый апдейт.** Он вызывается дважды за жизнь группы: когда бота добавили (`MyChatMemberHandler`) и когда владелец центра привязывает группу (`OrgGroupLinkService`: статус владельца и ещё раз статус бота). Кэшируется только статус **бота**: его меняет `my_chat_member`, и обработчик сбрасывает запись; повторная проверка в пределах 10 минут не ходит в Telegram. Статус **владельца** центра не кэшируется: это решение о доступе (правило 9), а бот без прав администратора не получает `chat_member`, который мог бы закрыть устаревший ответ.
- **K8 (хаб практики) — нет.** Практика в этой задаче не меняется; замер C0 — 6–7 запросов и p50 13–16 мс на пустом каталоге практики (материалов в наборе нет), оценить выигрыш на реальном объёме нельзя. Вернуться после появления материалов и решения по G2+.
- **K9 (`/today`, `/me`) — нет.** 7 и 12–17 запросов, p50 18 и 38–45 мс при 200 тыс. учеников — уже лёгкие эндпоинты; кэшировать их части значит вешать инвалидацию на события ученика (каждый ответ в тренировке), а граница «что от ответа к ответу не меняется» — решение architect. Выигрыш — единицы миллисекунд.
- **Опрос виджетов.** Filament опрашивает StatsOverview- и Chart-виджеты **каждые 5 с по умолчанию** (`CanPoll::$pollingInterval = '5s'`); `docs/20` §2 ошибочно считал, что опроса нет. Теперь у всех 12 виджетов `/admin` и 2 виджетов `/org` — `null`, а виджеты остаются ленивыми (тест `AdminDashboardCacheTest`).

## 6. Задачи

Нумерация — C (cache). Исполнители — по `CLAUDE.md`.

| # | Задача | Исполнитель | Готово, когда |
|---|---|---|---|
| C0 | **Замер и инвентаризация.** Команда объёмных данных `perf:seed` (только local и testing; общая с `docs/20` F0). Базовые замеры горячих эндпоинтов (`/me`, `/today`, `/courses`, `/courses/{slug}`, `/league`, `/friends`, `/practice/hub`, `/training/*`): число запросов, p50/p95. Таблица §2.2 дополнена всеми ключами | coder | Таблица замеров «до» в §8 |
| C1 | **Redis 8.10** по §1 | architect (вместе с C2) | ✅ 02.11.2026: `redis:8.10-alpine` в compose dev/prod и CI; документы и `PROGRESS.md` обновлены |
| C2 | **Два экземпляра и хранилища** по §4: compose (dev, prod, тестовый стек), `config/database.php` (`lru`), `config/cache.php` (`lru`, `pure_store`, `limiter`), `PureCache`, перенос существующих чистых кэшей на `lru`, маркеры и защита — на невытесняемом, версия entitlement — на невытесняемом, пароли, порты дева на `127.0.0.1`, `/health/ready` | architect | ✅ 02.11.2026: `PureCacheStoresTest` (потеря кэша не трогает лимиты, 2FA и маркеры), `DEVOPS_GUIDE` обновлён; `run.sh` переноса PostgreSQL зелёный с `redis-cache` |
| C3 | **Кэш админки и кабинета центра** — K1, K2 (вместе с `docs/20` F5) | coder | ✅ 02.11.2026: дашборд `/admin` — 0 запросов от 12 виджетов на втором открытии (`AdminDashboardCacheTest`), обзор `/org` — `OrgOverviewCacheTest`; данные обновляются по TTL и событиям; опрос выключен |
| C4 | **Контент** — K3, K4, K5 | coder | ✅ 02.11.2026: K3, K4 с тестами инвалидации на каждый путь записи; K5 без Redis-ключа — см. §5.1 |
| C5 | **Сайт** — K6 | coder | ✅ 02.11.2026: `SitePageCacheTest` — тело байт в байт, заголовки (CSP `script-src 'none'` и др.) те же |
| C6 | **Telegram** — K7, и K8, K9 по замеру | coder | ✅ 02.11.2026: K7 (`TelegramMemberCacheTest`); K8, K9 — нет, причины в §5.1 |
| C7 | **OPcache для прод-образа:** `opcache.validate_timestamps=0` (код в образе неизменен), `memory_consumption`, `interned_strings_buffer`, `max_accelerated_files` по числу файлов `vendor` (Filament и Livewire — тысячи файлов); проверка, что после деплоя кэш новый (контейнер пересоздаётся) | coder | `opcache_get_status()` в проде без нехватки памяти и ключей |
| C8 | **Замер после и ревью:** сравнение с C0, hit ratio, вытеснения; `PROGRESS.md` | reviewer, координатор | Таблица «после» в §8, блокирующих находок нет |

**Общий DoD:**
- pint, PHPStan level 8, весь Pest зелёные: SQLite и PostgreSQL 18 + Redis 8.10;
- правила §3 соблюдены;
- новые зависимости не добавляются: всё делается средствами Laravel 13 и Redis.

## 7. Решения владельца

**Принято 02.11.2026:**
1. Второй экземпляр Redis (`redis-cache`) под кэш (§2.1, §4).
2. Пароль Redis в проде.

**Открыто:** размер памяти под кэш на сервере. По умолчанию — 256 МБ (`REDIS_CACHE_MAXMEMORY`), уточняется по метрикам C8.

## 8. Замеры

Заполняется в C0 (столбцы «до») и C8 (столбцы «после»). Замер страниц `/admin` и `/org` — в `docs/20` §4.

**Как снято (C0, 02.11.2026).**
- **Данные:** `perf:seed --learners=200000 --words=100000 --members=400` на PostgreSQL 18.6 (подробнее — `docs/20` §4). Кэш — Redis 8.10 (`CACHE_STORE=redis`), перед каждым эндпоинтом сброшен: общие кэши (каталог, лидерборды) прогреваются первым запросом, персональные видят каждого ученика впервые.
- **Что мерили:** `PERF_MEASURE=1 ./vendor/bin/pest --group=perf`: по одному запросу от 18 разных учеников на эндпоинт (лимитер и персональные кэши видят каждого ученика впервые) после прогрева другим учеником. «Опытный» — один из первых учеников набора: 300 повторений, друзья, место в лиге, участник центра (Pro — у части). «Обычный» — около 24 повторений, не в центре. Время — в процессе PHP CLI без OPcache и без nginx, на общей машине (±30 %); запросов это не касается.
- **Ограничения замера:** в наборе нет материалов практики, поэтому `/practice/hub` измерен на пустом каталоге практики; тренировка — дневное повторение, первая карточка (`POST /training/sessions`), следующая (`GET …/next`) и ответ (`POST …/review`).

| Эндпоинт / страница | Запросов до | p50 до, мс | p95 до, мс | Запросов после | p50 после, мс | p95 после, мс |
|---|---|---|---|---|---|---|
| `/me`, опытный | 7 (макс. 11) | 18 | 23 | | | |
| `/me`, обычный | 7 (макс. 11) | 17 | 22 | | | |
| `/today`, опытный | 16 (макс. 19) | 38 | 68 | | | |
| `/today`, обычный | 17 (макс. 23) | 45 | 60 | | | |
| `/courses`, опытный | 2 (макс. 3) | 6 | 9 | | | |
| `/courses`, обычный | 2 (макс. 3) | 8 | 14 | | | |
| `/courses/{slug}`, опытный | 3 (макс. 3) | 13 | 23 | 3 (макс. 4) | 9 | 20 |
| `/courses/{slug}`, обычный | 3 (макс. 3) | 17 | 22 | 3 (макс. 4) | 8 | 15 |
| `/league`, опытный | 6 (макс. 7) | 12 | 15 | | | |
| `/league`, обычный | 7 (макс. 7) | 15 | 24 | | | |
| `/friends`, опытный | 4 (макс. 4) | 10 | 14 | | | |
| `/friends`, обычный | 4 (макс. 4) | 10 | 16 | | | |
| `/practice/hub`, опытный | 6 (макс. 7) | 13 | 37 | | | |
| `/practice/hub`, обычный | 6 (макс. 7) | 16 | 23 | | | |
| `POST /training/sessions`, опытный | 10 (макс. 14) | 22 | 26 | | | |
| `POST /training/sessions`, обычный | 10 (макс. 13) | 23 | 34 | | | |
| `GET /training/sessions/{id}/next`, опытный | 7 (макс. 11) | 16 | 22 | | | |
| `GET /training/sessions/{id}/next`, обычный | 7 (макс. 10) | 20 | 26 | | | |
| `POST /training/sessions/{id}/review`, опытный | 18 (макс. 47) | 41 | 183 | | | |
| `POST /training/sessions/{id}/review`, обычный | 16 (макс. 39) | 40 | 117 | | | |

**«После» этой таблицы** — только строки `/courses/{slug}` (K3), снятые 02.11.2026 (C3–C6, W2-C) на **PostgreSQL 16.15 и 50 тыс. учеников**; остальные эндпоинты кэш не затрагивает (см. таблицу ниже), поэтому их столбцы «после» остаются пустыми до C8: «до» этой таблицы — 200 тыс. и PostgreSQL 18.6, и на другом объёме цифры «после» нельзя ставить рядом. Максимум 4 запроса — первый ученик другой локали собирает дерево курса (по одному разу на локаль после сброса).

**C3–C6 на одном наборе (50 тыс. учеников, PostgreSQL 16.15, Redis 7.0 на той же машине, PHP 8.5 CLI без OPcache, ±30 %).** Набор пересоздан командой `perf:seed` перед «до» (коммит `8718d45`) и перед «после» (ветка с C3–C6); общие кэши в начале каждого эндпоинта пусты (тест сбрасывает оба хранилища), первый запрос — другим учеником. Запросов p50 (макс.) / p50 общее время, мс:

| Эндпоинт | Опытный: до | Опытный: после | Обычный: до | Обычный: после |
|---|---|---|---|---|
| `/me` | 7 (11) / 13,5 | 7 (11) / 13,1 | 9 (11) / 15,5 | 9 (11) / 14,0 |
| `/today` | 16 (19) / 35,4 | 16 (19) / 37,2 | 12 (19) / 27,1 | 12 (19) / 22,7 |
| `/courses` | 2 (3) / 6,4 | 2 (3) / 6,1 | 2 (3) / 4,7 | 2 (3) / 4,7 |
| **`/courses/{slug}`** | 3 (3) / 13,2, SQL 7,2 | **3 (4) / 9,3, SQL 4,3** | 3 (3) / 12,0, SQL 6,4 | **3 (4) / 7,5, SQL 2,4** |
| `/league` | 6 (7) / 7,8 | 6 (7) / 8,4 | 4 (7) / 9,9 | 4 (7) / 6,5 |
| `/friends` | 4 (4) / 6,9 | 4 (4) / 7,5 | 4 (4) / 7,2 | 4 (4) / 5,9 |
| `/practice/hub` | 7 (10) / 17,3 | 7 (10) / 16,7 | 8 (10) / 13,0 | 8 (10) / 12,2 |
| `POST /training/sessions` | 10 (13) / 19,3 | 10 (13) / 19,9 | 11 (13) / 18,6 | 11 (13) / 16,3 |
| `GET …/next` | 7 (10) / 12,7 | 7 (10) / 13,0 | 8 (10) / 13,5 | 8 (10) / 12,7 |
| `POST …/review` | 19 (42) / 26,2 | 19 (42) / 36,8 | 20 (31) / 28,6 | 20 (31) / 29,7 |

- **Число запросов не изменилось нигде**: K3 заменяет один большой запрос двумя (дерево — из кэша, прогресс ученика — свой), а K4 в этом замере ничего не выигрывает — каждый ученик приходит впервые и читает свои слова, которых в кэше ещё нет; выигрыш K4 в работе, когда одни и те же слова читают много раз (повторения, экзамены, практика).
- **`/courses/{slug}`: SQL 7,2 → 4,3 мс (опытный) и 6,4 → 2,4 мс (обычный)**, общее время 13,2 → 9,3 и 12,0 → 7,5 мс. Размер ответа тот же (25,6 КБ): выигрыш во времени, не в payload.
- Остальные различия — шум общей машины (±30 %): у `POST …/review` на этом прогоне выросли и SQL, и рендер при тех же запросах; повторить на тихой машине при C8.
- **Дашборды `/admin` и `/org`** — `docs/20` §4 (F5): 0 запросов на повторном открытии, SQL 1 100 мс → 0.

**Что показал замер «до»:**
- **Горячие эндпоинты уже лёгкие:** 2–18 запросов, p50 6–45 мс и p95 9–68 мс (без ответа в тренировке) при 200 тыс. учеников и 4,8 млн повторений; единственный выброс — ответ в тренировке `POST …/review` (p95 183 мс у опытного и 117 у обычного, до 47 запросов у отдельных учеников). Самые дорогие по запросам — `/today` (16–17) и этот ответ (16–18).
- **`/courses/{slug}` отдаёт 25,6 КБ** при 3 запросах: кандидат на кэш K3, выигрыш — в payload и времени, а не в числе запросов.
- **Главный выигрыш кэша — не TWA, а дашборд админки** (`docs/20` §4: 3,0 с SQL на 200 тыс. учеников); план кэша K1 начинать с него.
