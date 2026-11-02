# 19. Redis 8.10 и кэширование — план и задачи

> **Статус:** C1 и C2 выполнены 02.11.2026 (architect); C0, C3–C8 — план. Правила §3 действуют для всех новых задач с 02.11.2026.
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

## 6. Задачи

Нумерация — C (cache). Исполнители — по `CLAUDE.md`.

| # | Задача | Исполнитель | Готово, когда |
|---|---|---|---|
| C0 | **Замер и инвентаризация.** Команда объёмных данных `perf:seed` (только local и testing; общая с `docs/20` F0). Базовые замеры горячих эндпоинтов (`/me`, `/today`, `/courses`, `/courses/{slug}`, `/league`, `/friends`, `/practice/hub`, `/training/*`): число запросов, p50/p95. Таблица §2.2 дополнена всеми ключами | coder | Таблица замеров «до» в §8 |
| C1 | **Redis 8.10** по §1 | architect (вместе с C2) | ✅ 02.11.2026: `redis:8.10-alpine` в compose dev/prod и CI; документы и `PROGRESS.md` обновлены |
| C2 | **Два экземпляра и хранилища** по §4: compose (dev, prod, тестовый стек), `config/database.php` (`lru`), `config/cache.php` (`lru`, `pure_store`, `limiter`), `PureCache`, перенос существующих чистых кэшей на `lru`, маркеры и защита — на невытесняемом, версия entitlement — на невытесняемом, пароли, порты дева на `127.0.0.1`, `/health/ready` | architect | ✅ 02.11.2026: `PureCacheStoresTest` (потеря кэша не трогает лимиты, 2FA и маркеры), `DEVOPS_GUIDE` обновлён; `run.sh` переноса PostgreSQL зелёный с `redis-cache` |
| C3 | **Кэш админки и кабинета центра** — K1, K2 (вместе с `docs/20` F5) | coder | Тест бюджета запросов дашборда; данные обновляются по TTL и событиям |
| C4 | **Контент** — K3, K4, K5 | coder | Тесты инвалидации на каждый ключ |
| C5 | **Сайт** — K6 | coder | Ответ из кэша байт в байт равен свежему; заголовки безопасности на месте |
| C6 | **Telegram** — K7, и K8, K9 по замеру | coder | Меньше вызовов Telegram API в тестах-счётчиках; права после смены статуса обновляются |
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

Заполняется в C0 и C8.

| Эндпоинт / страница | Запросов до | p95 до | Запросов после | p95 после |
|---|---|---|---|---|
| | | | | |
