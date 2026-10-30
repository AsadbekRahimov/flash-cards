# 16. Практика (4 режима) и «Баттл 1 на 1» — технический проект

> Статус: **проект, принятый к реализации (задача 18.0)**. Основание — продуктовый план `docs/15_PRACTICE_GAMES_PLAN.md`
> и решения координатора (бриф 18.0). Документ обновляется **до** кода соответствующей задачи (как docs/13 §14).
> Названия таблиц, колонок, маршрутов, кодов ошибок, ключей конфига и i18n — окончательные: исполнители копируют их буквально.
> При расхождении с docs/15 верен этот документ; при расхождении с кодом — код и тесты (порядок docs/00).

## Статус реализации (обновлено в 18.7b, 18.6, 18.6b, 18.7c)

Документ остаётся проектом: названия таблиц, колонок, маршрутов, кодов ошибок и ключей конфига — окончательные и **совпадают с кодом**. Ниже — что сделано и где реализация уточнила проект; подробности — короткие блоки
**«Реализация 18.x»** в соответствующих разделах (§3.3, §5.2, §5.4, §5.5, §11.1, §15.1; блок 18.4 — в §5.3; блоки 18.6 — в §1.2, §1.3, §2.7, §7.3, §7.4, §8.1, §8.3, §8.5, §8.6, §9, §10.3, §13, §14.1; блоки 18.6b — в §3.1, §7.3, §7.4, §7.5, §8.2, §8.4, §8.7, §12, §13, §14.1, §15; блок 18.9t — в §11.4).
Контракт API — `docs/04` A.16 (практика) и A.17 (баттл), ЛС баттла — `docs/04` B.14, схема — `docs/03` §2.40–§2.47, экраны TWA — `docs/05` §5a, безопасность — `docs/07`, руководства — `USER_GUIDE` (ученик), `ADMIN_GUIDE`, `DEVOPS_GUIDE`, `VPS_DEPLOY_GUIDE_RU`.

| Задача | Что | Статус |
|---|---|---|
| 18.1 | Общий модуль сервера: `config/practice.php`, четыре миграции, модели, enum'ы, DTO, исключения, `PracticeAccess` и блок `/me.practice`, `PracticeMaterialService`, снимки, `PracticeSessionService`, контроллеры, Resources, маршруты и лимитеры практики, `ApiErrorResource.details`, `practice:close-expired` | сделано |
| 18.1-T | Оболочка TWA: типы и API-клиент, маршруты, `stores/practice`, `usePolling`, `useServerClock`, `usePracticeAudio`, хаб, сессия, итоги, пейвол, вход «Практика» в курсе | сделано |
| 18.2 – 18.5 | Режимы: «Найди пары», «Подбери сочетание», «Собери предложение», «Слушай и пиши» (+ озвучка фраз: job, диск `practice_audio`, замена задания) | сделано |
| 18.7a | Админка заданий (версии, «Проверено» / «Опубликовать» / архив), JSON-импорт и `practice:import`, пилот ученика, пилотный пакет (90 заданий), `PracticeDemoSeeder` | сделано |
| 18.6-T | Экраны баттла в TWA (настройка, комната, отсчёт, раунды, разбор, итог, реванш, баттл в «Друзьях») | сделано, на моках API |
| 18.7b | Входы в практику (`finish.practice_entry`, строка урока в курсе), документация сольной практики | сделано |
| 18.6 | Ядро баттла: `Services/Battle/{BattleService, BattleClock, BattleReader, BattleJanitor, BattleFriends, BattleSettings}`, read-модели (`BattleView`, `BattlePollView`, `RoundView`, `BattleListView`, `BattleOptionsView`), `PracticeModeHandler::reveal()` в четырёх режимах, `practice:battles-sweep` + расписание, `SendBattleDmJob`, каркас `BattleMessenger`; `BattleServiceTest` (29, реальные режимы), `BattleConcurrencyTest` (7 гонок на PostgreSQL), баттл-части `PracticeIsolationTest` / `PracticeSchedulerTest` / `PracticeQueryBudgetTest` | сделано (сервисы и тесты; API — 18.6b, сделано) |
| 18.6b | Обвязка баттла: `BattleController`, Requests, Resources над read-моделями 18.6, маршруты баттла (группы `twa-battle-poll` / `twa-practice-audio` вне `twa-api`, маршрут `twa.practice.battles.audio`), тело `BattleMessenger::notify`, `lang/{ru,uz}/battle.php`, `BattleContractTest`, `BattleMessengerTest`, `BattleAudioTest`, `BattleHttpRulesTest`, `BattleHttpMatchTest`, `BattleRateLimitTest`, HTTP-бюджеты; правки ядра по ревью TWA: `presence_warn_seconds`, встречные приглашения | сделано |
| 18.9b | Правки по ревью сервера соло-практики: пары — ключи `base|…`; диктант — другое слово и другая форма есть ошибка, а не опечатка (`EnglishLexicon`); снимок ≤ 40 КБ в UTF-8; причина `items_locked`; одно решение для `retry_available` и открытия повтора, порядок проверок `open()`, `source: retry` ↔ `retry_of`, `request_id_reused`, диск замены вне блокировки, `your_option_id` в разборе сочетания; `PracticeIsolationTest` ещё и на четырёх настоящих режимах | сделано |
| 18.7c | Документация баттла по коду: `docs/04` A.17 (маршруты, лимитеры, JSON по статусам, коды с `details`) и B.14 (ЛС), `USER_GUIDE` «Баттл 1 на 1 с другом», `docs/07` (угрозы, лимитеры, чек-лист пен-теста), `docs/05` §5a.5 сверен с API, `ADMIN_GUIDE` / `DEVOPS_GUIDE` / `VPS_DEPLOY_GUIDE_RU` (выключатель, дворник `practice:battles-sweep`, «завис»), `docs/03` §2.45–§2.47 (ключи `meta`), блоки «Реализация 18.6b» и «Реализация 18.9t» здесь. Браузерный e2e двух игроков (§14.4) в 18.7c не входит — отдельная задача | сделано |

**Где реализация уточнила проект** (по коду и тестам):
- Порядок проверок ответа (§6.3): элемент, на который уже есть ответ, даёт `answer_already_accepted` **раньше**, чем «не текущий» `element_not_current`: отвеченный элемент никогда не текущий, и при порядке из §6.3 второй вкладке не досталось бы вердикта (§14.2). Повтор `request_id` отвечает сохранённым результатом и **текущим** видом сессии даже у уже закончившейся (потерянный ответ на последний элемент не превращается в 410).
- Замена диктанта: последний оставшийся элемент не удаляется (409 `replace_not_allowed`, `reason: limit`); повтор `request_id` второй раз не заменяет, а возвращает текущую сессию.
- `GET /practice/hub` читает сводку баттла (`battle`) одним запросом к `battles` уже сейчас — таблица создана в 18.1; сам баттл отвечает 18.6.
- `ApiErrorResource::respond(…, array $details = [])`: `details` лежит внутри `error` и появляется только у кодов, где он описан (`docs/04` A.6, закреплено `PracticeContractTest`); `answer_already_accepted` в соло отдаёт `{verdict}`, в баттле — ещё `points`.
- Контракт `finish.practice_entry` закреплён в `TwaContractTest` (а не в `PracticeContractTest`, как предполагал §14.1) и в `PracticeEntryTest` (варианты, цена в запросах).
- Вход «Практика урока» в строке урока (§11.1) стоит в конце строки прогресса, а не второй кнопкой рядом с «Учить» (подробности — «Реализация 18.7b»).
- Бюджеты запросов (§13) выдержаны (`PracticeQueryBudgetTest`: хаб ≤ 9, открытие ≤ 12, показ ≤ 3, ответ ≤ 6, аудио ≤ 2); `finish` с записью `practice_entry` — на один запрос больше. С 18.9b: последний ответ сессии ≤ 7 и замена диктанта ≤ 7 (`DictationSessionTest`), открытие повтора ≤ 13 (`PracticeRetryTest`) — хаб остался на 9.
- Баттл (18.6): реванш идёт **без** `friendship_id` (`invite(?int $friendshipId, …, ?int $rematchOf)`); счёт участника растёт при разрешении раунда в той же транзакции, что и ответы (`finish()` без SUM; досрочный конец считает только разрешённые раунды); `meta` хранит `answered`, `rounds_played`, `left`, `voided_reason`, `audio_failed_by`, `audio_reports`; ответ во время отсчёта — 409 `round_not_open` (не `battle_wrong_state`); повтор `request_id` ответа отвечает прежним результатом и после закрытия раунда; дворник закрывает просроченные приглашения поштучным условным клеймом (точные события), бюджет sweep — `4 + 2 × просроченных + 7 × live`; опрос ≤ 3 запросов без перехода и ≤ 9 с переходом (на сервисах, без `learners` от `TwaAuth`). Read-модели несут поля, которые ждёт TWA 18.6-T: `opponent_absent_since`, `presence_grace_seconds`, `deadline_grace_ms`, `rematch {battle_id, status, by_me}`, `friendship_id`, `audio_preload` (room и countdown), `round` в отсчёте без фазы. Правила блокировок §9 проверены гонками (без `FOR UPDATE` падают 5 из 7 сценариев, два остальных держит unique `active_learner_id`).
- Баттл (18.6b, HTTP): опрос и аудио — отдельные маршрутные группы вне `twa-api` (`twa-battle-poll` 90/мин, `twa-practice-audio` 60/мин), остальное — внутри `twa-api` плюс `twa-battle-action` / `twa-battle-invite` (+ `twa-practice-open`); отказанные приглашения тоже считаются. Id матча в маршруте — до 18 цифр (длиннее — 404 роутера, а не TypeError). Встречное приглашение друга — 409 `battle_busy` с id **его** приглашения; `rematch_of` не читает остальные поля тела;
  `surrender` требует `{confirm: true}`; 503 аудио баттла только сообщает о беде, аннулирует матч `audio-failed`; предупреждение «соперник молчит» — через 10 с (`presence_warn_seconds`), конец матча — по-прежнему через 30 с. `accept` не бросает `battle_friend_no_access` (его проверяет приглашение; принимающий без доступа получает 403 `practice_unavailable`). Тексты ЛС-приглашения — в `lang/{ru,uz}/battle.php`, отправка кнопки — не `immediate`.

**Что осталось** (не входит в 18.7b/18.6/18.6b/18.7c): браузерный e2e двух игроков (§14.4), публичный выпуск и тарифы (этап 8 `docs/15`).

---

## 0. Резюме решений

| Тема | Решение |
|---|---|
| Объём (этапы 0–7 docs/15 §6) | Четыре сольных режима (`pairs`, `collocation`, `sentence`, `dictation`), баттл 1 на 1 с другом, входы из курса / после занятия / из друзей, итоги, повтор ошибок, ru/uz, админ-редактор + JSON-импорт, пилотный контент, флаги. Этап 8 (публичный выпуск, тарифы) — не делаем |
| Значения docs/15 §8 | Приняты все: самостоятельная практика без влияния на основное обучение; 3 темы A1–A2; пилот по флагу; соло без таймера; баттл — 5 раундов, лимиты раунда, без бонуса за скорость, ничья при равном счёте; два друга, один формат |
| Изоляция | Практика и баттл **не пишут** `word_repetitions`, `training_sessions`, `training_reviews`, `learner_daily_stats`, `learners.streak_*`/`streak_freezes`, лиги, `assignment_results`, `achievements`, квоту новых слов, `challenges`. Пишут только свои таблицы (§2) и `product_events` (§3.4). Инвариант закреплён тестом `PracticeIsolationTest` (§14) |
| Домен | Один домен `app/Domain/Practice` (подпапки `Services/Modes`, `Services/Grading`, `Services/Battle`); тексты бота — `app/Domain/Telegram/Services/BattleMessenger`. Отдельный `app/Domain/Battle` отвергнут: баттл переиспользует материал, снимки и грейдеры режимов на уровне классов (§1.1) |
| Контент | `practice_items` (задание, стабильный `key`) + append-only `practice_item_versions` (текст, **целевые слова, урок, уровень**, аудио) + `practice_item_words` (проекция целевых слов одной версии). Опубликованная версия выдаётся, пока правка (новая версия-черновик) не прошла «проверено → опубликовано»; слова, урок и уровень правки вступают в силу вместе с ней. Целевые слова обязательны (≥ 1) — правила «без целевых слов» нет (§4.2) |
| Снимок | Сессия и баттл хранят **полный** снимок выдачи (`snapshot` jsonb: тексты, раскладка, ожидаемые ответы, непрозрачные id). Проверка ответов никогда не читает `practice_items`/`words` во время игры; правка контента на идущую сессию не влияет |
| Непрозрачные id | Элементы (`e…`), фрагменты (`f…`), варианты (`o…`), стороны пар (`l…`/`r…`) — случайные 10-символьные id, порождённые при создании снимка; порядок показа — keyed-sort по HMAC от случайного seed, seed не хранится |
| Один активный | Соло: одна активная сессия на ученика; баттл: один активный на ученика (своё исходящее приглашение, комната, матч). Оба инварианта держит **nullable unique-колонка `active_learner_id`** (как `challenges.open_pair`): одинаково на PostgreSQL и SQLite, без PHP-проверок как источника истины |
| Аудио диктанта | JWT-эндпоинт `GET …/audio/{element}` стримит файл по непрозрачному id элемента снимка; TWA загружает в blob при показе и играет по нажатию. Подписанные URL не нужны: `fetch` шлёт `Authorization`, а blob нужен и так (iOS). Генерация — job после commit через `TtsFallbackService`; файлы контент-адресные на диске, но путь наружу не уходит |
| Проверка ответов | Общий `PracticeTextNormalizer` (NFKC, регистр, апострофы, дефис → пробел, оговорённая пунктуация → пробел). Диктант: `correct` / `typo` / `wrong`; «безопасная опечатка» — ровно один токен, OSA = 1, длина ожидаемого токена ≥ 5, не грамматическая форма (концевая буква), набранный токен — не английское слово (`EnglishLexicon`), не защищённый токен (`not`, `n't`, числительные и порядковые, цифры) (§5.2) |
| Размеры соло | Пары: до 3 раундов по 4, допускаются раунды по 3, минимум один раунд из 3 (формула §4.3). Остальные режимы: 5 заданий, минимум 3 (`practice.solo.items.min`). «Не знаю» = подсказка + ошибка |
| Транспорт баттла | Опрос `GET …/poll` раз в 2 с только в комнате/матче; отдельный лимитер `twa-battle-poll` 90/мин (вне `twa-api`); ответ ≤ 600 байт; без блокировок, пока переход не назрел; переходы и финализация — лениво под `FOR UPDATE` строки `battles` + дворник `practice:battles-sweep` каждую минуту |
| Присутствие | Heartbeat = сам опрос; `battle_participants.last_seen_at` пишется условным UPDATE не чаще раза в 5 с; отсутствие > 30 с в матче при живом сопернике — техническое поражение, оба пропали — `closed/both_absent` |
| Флаги | `config/practice.php`: `PRACTICE_ACCESS` = `off` (дефолт) / `pilot` / `all`; `PRACTICE_PLAN` = `all` (дефолт) / `pro`; выключатели режимов и баттла. Выключенный режим/баттл блокирует только **создание**; открытые сессии и идущие матчи доигрываются. `access=off` — всё 403, дворник аннулирует живые матчи |
| Конверт ошибок | `ApiErrorResource` получает необязательный ключ `details` (объект), который появляется только у кодов, где клиенту нужны поля (`practice_not_enough_material`, `practice_session_active`, `answer_already_accepted`, …) — docs/04 A.6 дополняется |

Отвергнутые варианты — одной строкой каждый, в конце соответствующих разделов.

---

## 1. Границы доменов, сервисы и контракты

### 1.1 Раскладка

```
app/Domain/Practice/
├── Enums/        PracticeMode, PracticeItemType, PracticeItemStatus, VersionState, AudioStatus,
│                 SessionStatus, FinishReason, Verdict, BattleStatus, BattleEndReason, BattlePhase, BattleRole
├── DTO/          PracticeAccessState, ModeAvailability, MaterialReport, Snapshot, SnapshotElement, GradeResult,
│                 SessionView, SummaryView, BattleView, BattlePollView, RoundView, PracticeImportReport
├── Exceptions/   PracticeException, BattleException, PracticeContentException   (errorCode + httpStatus() + details)
├── Support/      PracticeTextNormalizer, SeededShuffle, OpaqueId, InflectionGuard, TranslationVariants, EnglishLexicon (+ resources/lexicon/en-common.txt)
└── Services/
    ├── PracticeAccess                 флаги, пилот, тариф, блок `/me`
    ├── PracticeMaterialService        пригодные слова/задания для ученика (соло) и для пары игроков (баттл)
    ├── PracticeSnapshotBuilder        снимок сессии/баттла через обработчики режимов
    ├── PracticeSessionService         соло: open / view / answer / replace / retry / closeExpired
    ├── PracticeAudioService           аудио версии задания и слова: генерация (job), поиск файла для элемента, стрим
    ├── PracticeItemService            авторинг: saveVersion / markReviewed / publish / archive / restore + валидация
    ├── PracticeImporter               JSON-импорт (админка и artisan — одна служба)
    ├── Modes/   PracticeModeHandler (interface), ModeRegistry, PairsMode, CollocationMode, SentenceMode, DictationMode
    ├── Grading/ PairsGrader, CollocationGrader, SentenceGrader, DictationGrader
    └── Battle/  BattleService (invite/accept/decline/cancel/ready/answer/surrender/audioFailed),
                 BattleClock (advance: дедлайны, присутствие, финализация), BattleJanitor (sweep), BattleReader (view/poll/round/list)
app/Domain/Telegram/Services/BattleMessenger        ЛС приглашения (web_app-кнопка), тексты lang/{ru,uz}/battle.php
app/Jobs/SendBattleDmJob, app/Jobs/GeneratePracticeAudioJob
app/Console/Commands/ClosePracticeSessions (practice:close-expired), SweepBattles (practice:battles-sweep), ImportPracticeItems (practice:import)
app/Models/PracticeItem, PracticeItemVersion, PracticeItemWord, PracticeSession, PracticeAttempt, Battle, BattleParticipant, BattleAnswer
app/Http/Controllers/Api/Twa/PracticeHubController, PracticeSessionController, PracticeAudioController, BattleController
app/Http/Requests/Twa/OpenPracticeSessionRequest, PracticeAnswerRequest, PracticeReplaceRequest, BattleInviteRequest, BattleAnswerRequest, BattleAudioFailedRequest
app/Http/Resources/Twa/PracticeHubResource, PracticeSessionResource, PracticeAnswerResource, BattleOptionsResource, BattleListResource,
                       BattleResource, BattlePollResource, BattleRoundResource
app/Filament/Resources/PracticeItemResource (+ Pages/ListPracticeItems, CreatePracticeItem, EditPracticeItem), app/Filament/Pages/ImportPracticeItems
```

Почему один домен: баттл — это те же четыре формата с другим жизненным циклом; материал (§4), снимок (§2.4), грейдеры (§5) и Resource-формы элементов общие. Границу с другими доменами держат три точки входа: `CourseCatalogService::isAvailable/findAvailable/lessonWithContext` (Content), `WordTranslationResolver::resolveMany` (Content), `FriendshipService::find/areFriends` (Engagement), `EntitlementResolver::for` (Billing). Практика **не вызывает** `TrainingFlowService`, `ReviewSubmissionService`, `CardPicker`, `DailyStatsService`, `StreakService`, `LeagueService`, `AchievementService`, `ChallengeService`.

### 1.2 Интерфейс обработчика режима

Общий конвейер каждого режима: **материал → снимок → клиентская проекция → проверка → разбор**. Баттл использует те же четыре шага с другими параметрами размера и локалями обоих игроков.

```php
namespace App\Domain\Practice\Services\Modes;

interface PracticeModeHandler
{
    public function mode(): PracticeMode;

    /** Сколько пригодного материала есть (не строит снимок). $learner = null — баттл: без учёта прогресса. */
    public function availability(MaterialScope $scope, ?Learner $learner, LocaleSet $locales): ModeAvailability;
    // ModeAvailability { bool $available; int $available; int $required; ?string $reason /* no_words | ambiguous | no_items | items_locked | audio_pending | no_audio_words */ }

    /**
     * Строит элементы снимка. Детерминировано от $seed (SeededShuffle). Бросает PracticeException::notEnoughMaterial(...)
     * с ModeAvailability в details. $size — целевое число элементов (соло: из config; баттл: ровно rounds).
     * @return list<SnapshotElement>
     */
    public function build(MaterialScope $scope, ?Learner $learner, LocaleSet $locales, string $seed, int $size, bool $strictSize): array;

    /** Что видит клиент (без ожидаемых ответов). $locale — локаль смотрящего (в баттле у игроков разные). */
    public function project(SnapshotElement $element, string $locale, ElementState $state): array;

    /** Валидация формы ответа (422 invalid_answer_shape / unknown_element_id) и проверка. Чистая функция от снимка. */
    public function grade(SnapshotElement $element, array $answer, ElementState $state): GradeResult;
    // GradeResult { Verdict $verdict; array $reveal; array $normalizedAnswer; bool $final /* пары: раунд завершён */ ; int $points /* баттл: 0..100 */ }

    /** Разбор для итогов (строка списка ошибок): подпись элемента и ответ ученика. */
    public function mistakeLabel(SnapshotElement $element, ElementState $state, string $locale): array; // {label, your_answer}
}
```

- `MaterialScope { Course $course; ?Lesson $lesson; }` — курс обязателен, урок необязателен.
- `LocaleSet { list<string> $locales }` — соло: `[locale ученика]`; баттл: локали обоих игроков (уникальные), однозначность пар проверяется в **каждой** после fallback (§4.4).
- `$strictSize`: соло `false` (допускается `min ≤ n ≤ size`), баттл `true` (ровно `size`, иначе исключение).
- `ElementState` — состояние элемента из `practice_sessions.state` / `battles.snapshot` (пары: сопоставленное; диктант: число подсказок).
- `ModeRegistry::for(PracticeMode): PracticeModeHandler`; выключенный режим (`config('practice.modes.<mode>') = false`) **есть** в реестре — флаг проверяет `PracticeAccess` только при создании.

**Реализация 18.6:** в интерфейс добавлен `reveal(SnapshotElement $element, string $locale, ElementState $state): array` — разбор элемента для смотрящего **независимо от ответа** (в баттле один элемент читают два игрока на разных языках): правильный ответ в форме режима (`correct_text` + `alternatives` / `correct_option_ids` + `full_text` / `expected` / `pairs`) плюс `translation` и `explanation` этой локали (`null`, если нет). Все ключи, кроме двух последних, — блок `correct` разбора раунда (§7.4). `grade()` по-прежнему строит разбор соло-ответа (одна локаль). Реализовано во всех четырёх режимах; у `CollocationMode` прежний публичный `reveal($element, ?$locale)` приведён к этой сигнатуре.

### 1.3 Контракты сервисов

```php
final class PracticeAccess
{
    /** available | off | not_in_pilot | pro_required. Без запросов к БД сверх EntitlementResolver (кэш). */
    public function state(Learner $l, ?CarbonImmutable $now = null): PracticeAccessState;
    /** @throws PracticeException practice_unavailable (403) | practice_pro_required (403) */
    public function assertCanPractice(Learner $l): void;
    /** @throws PracticeException practice_mode_disabled (409) */
    public function assertModeEnabled(PracticeMode $mode): void;
    /** @throws BattleException battle_disabled (409) */
    public function assertBattleEnabled(): void;
    /** Блок `practice` ответа GET /me (§3.3). */
    public function describe(Learner $l, Entitlement $e): array;
    /** Второй игрок баттла: тот же state(); не `available` → BattleException battle_friend_no_access (409). */
    public function assertFriendCanPractice(Learner $friend): void;
}

final class PracticeMaterialService
{
    /** Курс по slug, доступный ученику (CourseCatalogService::findAvailable), иначе PracticeException course_not_found (404). */
    public function course(string $slug, Learner $l): Course;
    /** Урок курса (lessonWithContext + сверка stage.course_id), иначе lesson_not_found (404) / lesson_not_in_course (422). */
    public function lesson(Course $c, int $lessonId): Lesson;
    /** Курс для баттла: published и не private (колоды не годятся), иначе course_not_found (404). */
    public function courseForBattle(string $slug): Course;
    /** Введённые ученику активные слова курса/урока с переводами на $locales (§4.1). @return list<MaterialWord> */
    public function introducedWords(MaterialScope $scope, Learner $l, LocaleSet $locales): array;
    /** Все активные слова курса/урока (баттл). */
    public function allWords(MaterialScope $scope, LocaleSet $locales): array;
    /** Опубликованные задания типа, все целевые слова введены ученику; диктант — только audio_status = ready (§4.2). @return list<PracticeItemVersion> */
    public function eligibleItems(MaterialScope $scope, PracticeItemType $type, ?Learner $l): array;
    /** Доступность каждого режима для хаба / опций баттла — ≤ 6 запросов на все режимы (§13). @return array<string, ModeAvailability> */
    public function availability(MaterialScope $scope, ?Learner $l, LocaleSet $locales): array;
}

final class PracticeSessionService
{
    /**
     * Открывает соло-сессию. Вне внешней транзакции (повтор чтения победителя после unique violation).
     * $replaceActive = false и есть активная → PracticeException practice_session_active (409, details {session_id, mode, course, lesson_id}).
     * $retryOf — id завершённой сессии ученика: материал = её ошибки, новый seed; нет ошибок / мало → retry_not_available (409).
     * @throws PracticeException practice_unavailable | practice_pro_required | practice_mode_disabled | course_not_found | lesson_not_found |
     *         lesson_not_in_course | practice_not_enough_material (422, details ModeAvailability) | practice_session_active | retry_not_available
     */
    public function open(Learner $l, PracticeMode $mode, string $courseSlug, ?int $lessonId, string $source, bool $replaceActive, ?int $retryOf, ?CarbonImmutable $now = null): PracticeSession;
    /** Своя сессия по id, иначе practice_session_not_found (404) — чужая неотличима от несуществующей. Просроченная активная закрывается лениво (expired). */
    public function find(Learner $l, int $id, ?CarbonImmutable $now = null): PracticeSession;
    public function view(PracticeSession $s, Learner $l): SessionView;
    /**
     * Ответ (§6.3). Транзакция: practice_sessions FOR UPDATE → practice_attempts. Повтор request_id → прежний результат;
     * другой ответ на принятый элемент → answer_already_accepted (409, details {verdict}); не текущий элемент → element_not_current (409, details {current});
     * закрытая → practice_session_closed (410); форма → invalid_answer_shape / unknown_element_id (422); пары: pair_already_matched (409).
     */
    public function answer(PracticeSession $s, Learner $l, string $requestId, string $elementId, array $answer, ?int $timeSpentMs, ?CarbonImmutable $now = null): AnswerOutcome;
    /** Замена диктанта без штрафа (§10.4): только dictation, ≤ practice.solo.dictation.max_replacements, иначе replace_not_allowed (409). */
    public function replace(PracticeSession $s, Learner $l, string $requestId, string $elementId, string $reason, ?CarbonImmutable $now = null): SessionView;
    /** Планировщик practice:close-expired (каждые 15 мин): active с expires_at <= now → finished/expired одним UPDATE (+ active_learner_id = NULL). */
    public function closeExpired(?CarbonImmutable $now = null): int;
}

final class PracticeAudioService
{
    /** Файл элемента снимка (сессии или баттла) для стрима; null — элемент без аудио / файла нет. Чтение снимка, без записи. */
    public function clipFor(Snapshot $snapshot, string $elementId): ?AudioClip;   // {path, mime}
    /** Готово ли аудио опубликованной версии (audio_status = ready и файл на диске непустой). */
    public function isReady(PracticeItemVersion $v): bool;
    /** Ставит GeneratePracticeAudioJob после commit; audio_status → pending. Вызывается при создании версии dictation (импорт, редактор). */
    public function schedule(PracticeItemVersion $v): void;
    /** Тело job: synthesize(text) → атомарная запись `{audio_key}.mp3` на диск `practice_audio` → audio_status ready/failed. Идемпотентно (§10.1). */
    public function generate(PracticeItemVersion $v, bool $force = false): void;
}

final class PracticeItemService
{
    /** Новая версия (черновик) из формы/импорта, если отличается хотя бы одно из четырёх: текст, целевые слова, урок, уровень (все четыре пишутся в версию); валидация формы (§4.5) → PracticeContentException (422, details {field, code}). Задание другого курса → InvalidArgumentException. */
    public function saveVersion(PracticeItem|null $item, Course $course, array $data, ?User $by): PracticeItemVersion;
    /** текущая версия draft → reviewed; audit practice_item.reviewed. $expectedVersionId — версия, которую админ видел: текущая другая → PracticeItemStateException version_changed (409); импорт и сидеры его не передают */
    public function markReviewed(PracticeItem $item, User $by, ?string $ip = null, ?int $expectedVersionId = null): PracticeItem;
    /** reviewed → published (валидация публикации §4.5 — по целевым словам САМОЙ версии); прежняя опубликованная → superseded; item.published_version_id = current, проекция (§2.4) = эта версия. audit practice_item.published. $expectedVersionId — как у markReviewed */
    public function publish(PracticeItem $item, User $by, ?string $ip = null, ?int $expectedVersionId = null): PracticeItem;
    public function archive(PracticeItem $item, User $by): PracticeItem;             // любой → archived (published_version_id сохраняется, но не выдаётся)
    public function restore(PracticeItem $item, User $by): PracticeItem;             // archived → published (если есть published_version_id) иначе состояние текущей версии; проекция не меняется
    /** «Сгенерировать аудио заново»: ставит (force) опубликованную версию диктанта и текущую, если это другая версия, — по разу */
    public function regenerateAudio(PracticeItem $item): void;
}

final class PracticeImporter
{
    public const MAX_BYTES = 2 * 1024 * 1024;
    /** Файл целиком в одной транзакции или ничего (как VocabularyImporter). $publish — опубликовать сразу (валидация публикации каждому заданию). $scheduleAudio = false — не ставить job озвучки новых версий диктанта (сам озвучивает вызывающий: демо-сидер) */
    public function import(string $rawJson, User $by, bool $publish, array $auditContext = [], bool $scheduleAudio = true): PracticeImportReport;
    public function importAsSystem(string $rawJson, bool $publish, array $auditContext = [], bool $scheduleAudio = true): PracticeImportReport;   // artisan practice:import
    public function maxBytes(): int;   // practice.import.max_bytes: тот же предел у страницы импорта и у команды
}
```

Баттл (`Services/Battle`):

```php
final class BattleService
{
    /**
     * Приглашение (§8.2). Вне транзакции: доступ обоих, флаги, дружба accepted, не сам себе, курс published и не private, материал ровно на 5 раундов
     * (снимок строится здесь). Транзакция: INSERT battles → INSERT battle_participants (challenger active_learner_id = me; unique violation → battle_busy 409)
     * → INSERT participant opponent. После commit: событие battle.invited, SendBattleDmJob.
     * @throws BattleException battle_disabled | battle_busy | battle_friend_no_access | not_friends (403) | friendship_not_found (404) |
     *         course_not_found | lesson_not_found | lesson_not_in_course | battle_material_unavailable (422, details ModeAvailability) | practice_mode_disabled
     */
    public function invite(Learner $by, int $friendshipId, PracticeMode $mode, string $courseSlug, ?int $lessonId, ?int $rematchOf, ?CarbonImmutable $now = null): Battle;
    /** Адресат принимает: battles FOR UPDATE → UPDATE participant active_learner_id = me (violation → battle_busy) → status room. Идемпотентно для room+. */
    public function accept(Learner $by, int $battleId, ?CarbonImmutable $now = null): Battle;      // battle_not_recipient 403 | battle_closed 409
    public function decline(Learner $by, int $battleId, ?CarbonImmutable $now = null): Battle;     // invited → closed/declined
    /** Инициатор отзывает приглашение (invited) либо любой участник покидает комнату (room | countdown) → closed/canceled. */
    public function cancel(Learner $by, int $battleId, ?CarbonImmutable $now = null): Battle;
    /** Готов: ready_at; диктант — сервер проверяет файлы 5 клипов (нет → closed/voided). Оба готовы → countdown, starts_at, раунд 1. */
    public function ready(Learner $by, int $battleId, ?CarbonImmutable $now = null): Battle;
    /** Окончательный ответ раунда (§8.5). Идемпотентность по request_id; второй ответ → answer_already_accepted; после дедлайна + грейс → round_closed. */
    public function answer(Learner $by, int $battleId, string $requestId, int $round, array $answer, ?CarbonImmutable $now = null): BattleAnswerOutcome;
    public function surrender(Learner $by, int $battleId, ?CarbonImmutable $now = null): Battle;   // countdown | playing → finished/surrender, победитель — соперник
    /** Игрок сообщил о сбое аудио: сервер проверяет файл; нет файла → closed/voided; файл есть → {verified: false}, матч идёт. */
    public function audioFailed(Learner $by, int $battleId, string $requestId, string $elementId, ?CarbonImmutable $now = null): AudioFailureOutcome;
}

final class BattleClock
{
    /**
     * Единственное место переходов по времени и присутствию (§8.3). Вызывается ТОЛЬКО под FOR UPDATE строки battles
     * (BattleService::*, BattleReader::poll при назревшем переходе, BattleJanitor). Идемпотентна: повтор ничего не меняет.
     */
    public function advance(Battle $locked, CarbonImmutable $now): BattleTransitionReport;   // {changed: bool, finalized: bool}
    /** Переход назрел? Чистая функция от строки и now — читается без блокировки в poll. */
    public function isDue(Battle $b, CarbonImmutable $now): bool;
}

final class BattleJanitor
{
    /** practice:battles-sweep (каждую минуту): 1) bulk UPDATE просроченных invited → closed/invite_expired (+ active_learner_id = NULL);
     *  2) по каждому live-баттлу (room|countdown|playing) — advance под FOR UPDATE; 3) при practice.access = off — voided всем live. */
    public function sweep(?CarbonImmutable $now = null): int;
}

final class BattleReader
{
    public function find(Learner $viewer, int $id): Battle;                    // участник, иначе battle_not_found (404)
    public function view(Battle $b, Learner $viewer, CarbonImmutable $now): BattleView;
    /** Опрос: присутствие (условный UPDATE ≤ 1 раз в heartbeat_write_seconds), advance только если isDue, маленький ответ. */
    public function poll(Battle $b, Learner $viewer, CarbonImmutable $now): BattlePollView;
    public function round(Battle $b, Learner $viewer, int $no, CarbonImmutable $now): RoundView;   // раскладка; разбор — после фиксации/дедлайна
    public function list(Learner $viewer, CarbonImmutable $now): BattleListView;                   // active, incoming, finished(10)
    public function options(Learner $viewer, int $friendshipId, string $courseSlug, ?int $lessonId): BattleOptionsView;
}
```

Исключения — по образцу `FriendshipException`: `public readonly string $errorCode`, `public readonly array $details = []`, `httpStatus(): int`. Контроллеры: `return ApiErrorResource::respond($e->httpStatus(), $e->errorCode, $e->getMessage(), $e->details)`. Полный список кодов — §7.6.

**Реализация 18.6** (`app/Domain/Practice/Services/Battle/`): `BattleService::invite(Learner $by, ?int $friendshipId, PracticeMode $mode, string $courseSlug, ?int $lessonId, ?int $rematchOf, ?CarbonImmutable $now = null)` — `friendshipId` nullable: реванш (`rematchOf`) идёт без него, друг/формат/курс/урок берутся из завершённого матча (решение координатора). `BattleClock`, кроме `advance`/`isDue`, публично отдаёт писателей терминальных переходов, которыми пользуется и сервис: `close(Battle, BattleEndReason, now, array $meta = [])`, `finish(Battle, BattleEndReason, ?int $winnerId, now)`, `resolveRound(Battle, now, Collection $answers)`, а также присутствие — `touch(Battle, BattleParticipant, now): bool` (условный UPDATE ≤ 1 раз в `heartbeat_write_seconds`), `isAbsent`, `isSilent` / `silentSince` (предупреждение раньше конца, 18.6b, §8.4), `acceptsAnswers`, `roundsPlayed`, `leader`. `BattleReader::find` делает `assertCanPractice` (как `PracticeSessionService::find`); `view`/`poll`/`round` — heartbeat смотрящего (live-статусы) и ленивый переход под `FOR UPDATE`, если `isDue`; `pollView(Battle, Learner, now)` строит опрос из строки в памяти (им отвечает `answer`). Общая проверка «с кем можно играть» — `BattleFriends::byFriendship` / `ofRematch` (404 `friendship_not_found` для чужой пары, пропавшего или заблокированного друга; 403 `not_friends` для не-accepted). Числа конфига — `BattleSettings`. Read-модели — `DTO/BattleView`, `BattlePollView` (с `toArray()` в форме провода — на нём проверяется бюджет 600 байт), `RoundStateView`, `RoundView`, `BattleListView`, `BattleOptionsView`, `BattleAnswerOutcome`, `AudioFailureOutcome`, `BattleTransitionReport`; Resources 18.6b оборачивают их. `BattleException::NOT_FRIENDS = 'not_friends'` (403) — код тот же, что у вызовов. `App\Domain\Practice\Enums\BattleDmKind` (Invited), `App\Jobs\SendBattleDmJob::send(kind, battleId)` (после commit), `App\Domain\Telegram\Services\BattleMessenger::notify()` — каркас 18.6, тело — 18.6b (§8.7).

---

## 2. Модель данных

Стиль — docs/03 §2. Все таблицы: `id bigint PK`, `created_at`, `updated_at` (если не сказано иное). FK всегда с явным `ON DELETE`. Перечисления — `varchar` + CHECK на PostgreSQL (на SQLite инвариант держит сервис/enum), как `training_sessions`.

### 2.1 `learners.practice_pilot_at` (правка существующей таблицы)

| Колонка | Тип | Описание |
|---|---|---|
| practice_pilot_at | timestamp nullable | ученик включён в пилот практики (`PRACTICE_ACCESS=pilot`); ставит/снимает админ на карточке ученика, `audit_logs` `learner.practice_pilot_changed {from, to}`; `NULL` — не в пилоте |

Индекса нет (фильтр админки по редкому флагу; при `access=all` колонка не читается).

### 2.2 `practice_items` — авторское задание

| Колонка | Тип | Описание |
|---|---|---|
| id | bigint PK | |
| course_id | bigint FK → courses, ON DELETE CASCADE | курс задания; только `owner_type ∈ {platform, organization}` (колоды learner'ов заданий не имеют — проверка сервиса) |
| lesson_id | bigint nullable FK → lessons, ON DELETE SET NULL | урок (вход «практика урока»); `NULL` — задание курса. **Проекция версии** (см. ниже и §2.4): читает `PracticeMaterialService`, пишет только `PracticeItemService` |
| type | varchar(12) | `collocation` / `sentence` / `dictation` (пары заданий не имеют — берут `words`) |
| key | varchar(64) | стабильный ключ автора (`/\A[a-z0-9][a-z0-9._-]{0,63}\z/`), идемпотентность импорта |
| level | varchar(2) nullable | `A1`..`C2`; проекция версии, как `lesson_id` |
| status | varchar(10) default 'draft' | `draft` / `reviewed` / `published` / `archived` — см. правила ниже |
| current_version_id | bigint nullable | последняя версия (без FK: цикл items ↔ versions; целостность — `PracticeItemService`, тест) |
| published_version_id | bigint nullable | версия, которую получают ученики; `NULL` — не публиковалось |
| created_by_user_id | bigint nullable FK → users, ON DELETE SET NULL | |
| archived_at | timestamp nullable | |

**Индексы:** unique `(course_id, key)`; `(course_id, status, type)`; `(lesson_id, status, type)`; `current_version_id`; `published_version_id`.
**PG CHECK:** `practice_items_type_check (type IN ('collocation','sentence','dictation'))`, `practice_items_status_check (status IN ('draft','reviewed','published','archived'))`,
`practice_items_published_shape_check ((status = 'published') = (published_version_id IS NOT NULL AND archived_at IS NULL))`.

**Статус = проекция версий** (пишет только `PracticeItemService`): `archived` — `archived_at NOT NULL`; иначе `published` — есть `published_version_id`; иначе — `state` текущей версии (`draft` / `reviewed`). Правка опубликованного задания создаёт новую версию `draft`, `current_version_id` переезжает, `status` остаётся `published` (ученики продолжают получать `published_version_id` — **вместе со словами, уроком и уровнем той версии**: они в версии, §2.3); у такого задания в админке признак «есть неопубликованная правка» (`current_version_id <> published_version_id`), он покрывает любое из четырёх изменений (текст, слова, урок, уровень). Переходы версии: `draft → reviewed` («Проверено»), `reviewed → published` («Опубликовать»: прежняя опубликованная → `superseded`), любая → `archived` всего задания. Публиковать `draft` напрямую нельзя (прямой переход — только через `import --publish`, который сразу создаёт версию `published`, минуя `reviewed`: это явное решение владельца «опубликовать сразу — только для проверенного контента», подтверждённое чекбоксом/опцией, а не файлом).

### 2.3 `practice_item_versions` — неизменяемый текст задания

| Колонка | Тип | Описание |
|---|---|---|
| id | bigint PK | |
| item_id | bigint FK → practice_items, ON DELETE CASCADE | |
| number | smallint | 1, 2, … внутри задания |
| state | varchar(10) | `draft` / `reviewed` / `published` / `superseded` |
| content | jsonb NOT NULL | тексты и структура по типу (ниже); **не меняется после INSERT** |
| target_word_ids | jsonb NOT NULL default '[]' | `words.id` целевых слов версии, по возрастанию, без повторов (активные слова курса; для публикации ≥ 1); не меняется после INSERT |
| lesson_id | bigint nullable FK → lessons, ON DELETE SET NULL | урок версии (как `practice_items.lesson_id`: при удалении урока — `NULL`); не меняется после INSERT |
| level | varchar(2) nullable | `A1`..`C2`; не меняется после INSERT |
| audio_key | varchar(40) nullable | только `dictation`: `sha1(version_id|normalized text|voice|audio_version)` — имя файла `{audio_key}.mp3` на отдельном диске `practice_audio` (не раздаётся nginx, §10.5); наружу не уходит |
| audio_status | varchar(8) default 'none' | `none` (не диктант) / `pending` / `ready` / `failed` |
| audio_error | varchar(100) nullable | последняя причина `failed` |
| audio_generated_at | timestamp nullable | |
| created_by_user_id, reviewed_by_user_id, published_by_user_id | bigint nullable FK → users, ON DELETE SET NULL | |
| reviewed_at, published_at, superseded_at | timestamp nullable | |
| created_at | timestamp | (без `updated_at` у содержимого; служебные колонки аудио меняет job) |

Текст, целевые слова, урок и уровень — **четыре составляющие версии**: изменилась любая — новая версия (`save()` и импорт не пишут «правку только урока или уровня» мимо версии). Миграция `2026_10_30_100010` добавила три последние колонки и заполнила ими все существующие версии из их заданий (слова — из `practice_item_words`, урок и уровень — из `practice_items`): в момент миграции выдача не меняется.

**Индексы:** unique `(item_id, number)`; `(audio_status) WHERE audio_status = 'pending'` — partial `practice_item_versions_audio_pending_idx` (PG и SQLite) для админ-виджета «аудио в очереди»/повторной постановки.
**PG CHECK:** `state IN (...)`, `audio_status IN ('none','pending','ready','failed')`.

`content` по типу (валидация §4.5; все строки — после `trim`, NFC):

```jsonc
// sentence
{ "prompt": {"ru": "Моя мама — учитель.", "uz": "Onam o'qituvchi."},
  "fragments": ["My", "mother", "is", "a", "teacher"],            // порядок автора = ПЕРВАЯ допустимая последовательность (§4.5); индексы 0..n-1
  "accepted": [[0,1,2,3,4]],                                      // перестановки индексов фрагментов (строки файла → индексы при сохранении)
  "display": "My mother is a teacher.",                           // необязательно: каноническая запись первой допустимой (регистр, пунктуация) для разбора
  "explanation": {"ru": "…", "uz": "…"} }
// collocation
{ "text": "Let's ___ a meeting on Monday.", "options": ["have", "make", "do"], "correct": [0],
  "translation": {"ru": "…", "uz": "…"}, "explanation": {"ru": "…", "uz": "…"} }
// dictation (фраза)
{ "text": "See you tomorrow", "accepted": ["See you tomorrow"],   // accepted всегда содержит text первым
  "translation": {"ru": "До завтра", "uz": "Ertagacha"}, "explanation": {"ru": "…", "uz": "…"} | null }
```

Почему JSON, а не колонки: структура различается по типу, не фильтруется и не агрегируется SQL, читается целиком при построении снимка и в редакторе; индексов по содержимому нет. Почему версии — отдельная таблица, а не `version int` в `practice_items`: аудио принадлежит тексту (`audio_key` версии), снимки ссылаются на `version_id` для аналитики, а «проверено/опубликовано» относится к конкретному тексту.

### 2.4 `practice_item_words` — целевые слова

| Колонка | Тип |
|---|---|
| id | bigint PK |
| item_id | bigint FK → practice_items, ON DELETE CASCADE |
| word_id | bigint FK → words, ON DELETE CASCADE |

**Индексы:** unique `(item_id, word_id)`; `(word_id)`. Слово — активное слово **того же курса** (любого урока).

**Проекция.** Целевые слова версии лежат в `practice_item_versions.target_word_ids` (§2.3). Строки `practice_item_words`, а также `practice_items.lesson_id` и `practice_items.level` — **проекция одной версии**: опубликованной, если у задания есть `published_version_id`, иначе текущей. Их читает `PracticeMaterialService` (§4.2), поэтому черновик опубликованного задания не меняет выдачу: ни слова (иначе опубликованная версия пропала бы у учеников, не знающих новое слово, а при пустом списке выдавалась бы всем), ни урок. Проекцию пишет только `PracticeItemService`: `write()` у задания **без** опубликованной версии (каждая новая версия — текущая) и `makePublished()` для версии, которая становится опубликованной, — в той же транзакции, что и `published_version_id`. `archive()`/`restore()` указатель не двигают, проекция та же.

### 2.5 `practice_sessions` — сольная сессия

| Колонка | Тип | Описание |
|---|---|---|
| id | bigint PK | |
| learner_id | bigint FK → learners, ON DELETE CASCADE | владелец |
| active_learner_id | bigint nullable **unique** | `= learner_id`, пока `status = active`; `NULL` после завершения — **«одна активная соло-сессия на ученика» как факт БД** (NULL не конфликтуют) |
| mode | varchar(12) | `pairs` / `collocation` / `sentence` / `dictation` |
| course_id | bigint FK → courses, ON DELETE CASCADE | |
| lesson_id | bigint nullable FK → lessons, ON DELETE SET NULL | |
| source | varchar(16) | `hub` / `after_training` / `retry` / `deeplink` (откуда открыта; аналитика) |
| retry_of_session_id | bigint nullable FK → practice_sessions, ON DELETE SET NULL | исходная сессия «Повторить ошибки» |
| status | varchar(10) default 'active' | `active` / `finished` |
| finish_reason | varchar(12) nullable | `completed` / `expired` / `replaced` (ученик начал новую с `replace_active`) |
| snapshot | jsonb NOT NULL | выдача (§2.8); **серверный**, в ответы не попадает (Resource — белый список) |
| state | jsonb NOT NULL default '{}' | прогресс по элементам (§6.2); меняется только под `FOR UPDATE` |
| total | smallint | число элементов (пары — раундов) |
| answered | smallint default 0 | завершённых элементов |
| correct_first | smallint default 0 | верно с первой попытки (`typo` не считается) |
| hints | smallint default 0 | «Не знаю» |
| mismatches | smallint default 0 | пары: неверных сопоставлений |
| locale | varchar(5) | язык текстов снимка (локаль ученика при создании) |
| expires_at | timestamp NOT NULL | `now + practice.solo.session_ttl_hours` (24) |
| started_at | timestamp NOT NULL | |
| finished_at | timestamp nullable | |

**Индексы:** `active_learner_id` unique; `(learner_id, status, started_at)`; `(status, expires_at)` — планировщик; `retry_of_session_id`; `(course_id, mode, finished_at)` — отчёты пилота.
**PG CHECK:** `practice_sessions_mode_check`, `practice_sessions_status_check`, `practice_sessions_active_shape_check ((status = 'active') = (active_learner_id IS NOT NULL))`, `practice_sessions_active_owner_check (active_learner_id IS NULL OR active_learner_id = learner_id)`.

### 2.6 `practice_attempts` — журнал принятых запросов (идемпотентность)

| Колонка | Тип | Описание |
|---|---|---|
| id | bigint PK | |
| session_id | bigint FK → practice_sessions, ON DELETE CASCADE | |
| learner_id | bigint FK → learners, ON DELETE CASCADE | |
| request_id | uuid (PG `uuid`, SQLite `char(36)`) | клиентский ключ запроса |
| element_id | varchar(16) | id элемента снимка |
| kind | varchar(8) | `answer` / `pair` / `skip` / `replace` |
| payload | jsonb | ответ как прислан (после валидации формы) |
| verdict | varchar(8) nullable | `correct` / `typo` / `wrong` / `skipped`; `NULL` у `replace` |
| is_first | boolean default true | первая попытка по элементу / по левому слову пары |
| result | jsonb | тело ответа API без `session` (для побайтово одинакового повтора) |
| time_spent_ms | int nullable | телеметрия клиента, на вердикт не влияет |
| accepted_at | timestamp NOT NULL | |
| created_at | timestamp | (без `updated_at`: строки не меняются) |

**Индексы:** unique `(session_id, request_id)` — повтор запроса; `(session_id, element_id)`; `(learner_id, accepted_at)`.

### 2.7 `battles`, `battle_participants`, `battle_answers`

`battles`:

| Колонка | Тип | Описание |
|---|---|---|
| id | bigint PK | |
| challenger_id | bigint FK → learners, ON DELETE CASCADE | инициатор |
| opponent_id | bigint FK → learners, ON DELETE CASCADE | приглашённый |
| friendship_id | bigint nullable FK → friendships, ON DELETE SET NULL | пара на момент приглашения |
| course_id | bigint FK → courses, ON DELETE CASCADE | |
| lesson_id | bigint nullable FK → lessons, ON DELETE SET NULL | |
| mode | varchar(12) | формат (один на матч) |
| status | varchar(10) | `invited` → `room` → `countdown` → `playing` → `finished` (есть результат: победа/ничья) ; `closed` (без результата) |
| end_reason | varchar(16) nullable | `finished`: `completed` / `surrender` / `absent`; `closed`: `declined` / `invite_expired` / `room_expired` / `canceled` / `both_absent` / `voided` |
| snapshot | jsonb NOT NULL | 5 раундов (§2.8), общий для обоих; локали обоих игроков |
| current_round | smallint default 0 | 0 до старта, 1..5 |
| phase | varchar(10) nullable | `answering` / `reveal` (только в `playing`) |
| round_deadline_at | timestamp nullable | конец приёма ответов текущего раунда (серверное время) |
| phase_ends_at | timestamp nullable | конец `reveal` → следующий раунд / финализация |
| invite_expires_at | timestamp NOT NULL | `now + battle.invite_ttl_seconds` (300) |
| room_expires_at | timestamp nullable | `accepted_at + battle.room_ttl_seconds` (120) |
| accepted_at, starts_at, finished_at | timestamp nullable | `starts_at` = конец обратного отсчёта |
| winner_learner_id | bigint nullable FK → learners, ON DELETE SET NULL | `NULL` — ничья или без результата |
| rematch_of_id | bigint nullable FK → battles, ON DELETE SET NULL | реванш какого матча |
| state_version | int default 0 | растёт при каждом изменении, видимом игрокам (переход, ответ, готовность); присутствие его **не** меняет |
| meta | jsonb default '{}' | `{voided_reason, audio_failed_by, …}` |

**Индексы:** `(status)`; `(status, invite_expires_at)`; `(challenger_id, status)`; `(opponent_id, status)`; `rematch_of_id`; `winner_learner_id`; `friendship_id`.
**PG CHECK:** `battles_distinct_players_check (challenger_id <> opponent_id)`, `battles_round_check (current_round BETWEEN 0 AND 5)`, `battles_status_check`, `battles_phase_shape_check ((status = 'playing') = (phase IS NOT NULL))`.

`battle_participants` (ровно две строки на матч, создаются вместе с ним):

| Колонка | Тип | Описание |
|---|---|---|
| id | bigint PK | |
| battle_id | bigint FK → battles, ON DELETE CASCADE | |
| learner_id | bigint FK → learners, ON DELETE CASCADE | |
| role | varchar(10) | `challenger` / `opponent` |
| active_learner_id | bigint nullable **unique** | `= learner_id`, пока участник «занят» этим матчем: у инициатора — с приглашения, у приглашённого — с принятия; `NULL` при любом завершении. **«Один активный баттл на ученика» как факт БД** |
| ready_at | timestamp nullable | |
| last_seen_at | timestamp nullable | heartbeat (условный UPDATE не чаще `heartbeat_write_seconds`) |
| surrendered_at | timestamp nullable | |
| score | smallint default 0 | сумма очков раундов (0..500) |
| locale | varchar(5) | локаль игрока при приглашении — язык текстов его стороны снимка |

**Индексы:** unique `(battle_id, learner_id)`; `active_learner_id` unique; `(learner_id, battle_id)`.
**PG CHECK:** `battle_participants_active_owner_check (active_learner_id IS NULL OR active_learner_id = learner_id)`.

`battle_answers`:

| Колонка | Тип | Описание |
|---|---|---|
| id | bigint PK | |
| battle_id | bigint FK → battles, ON DELETE CASCADE | |
| learner_id | bigint FK → learners, ON DELETE CASCADE | |
| round | smallint | 1..5 |
| request_id | uuid | |
| payload | jsonb | ответ |
| verdict | varchar(8) | `correct` / `typo` / `wrong` / `skipped` / `timeout` (`timeout` пишет сервер при дедлайне без ответа; `request_id` такой строки — **случайный uuid v4**, см. «Реализация 18.6-fix» ниже) |
| points | smallint | 0..100 |
| accepted_at | timestamp NOT NULL | серверное время приёма |
| created_at | timestamp | |

**Индексы:** unique `(battle_id, round, learner_id)` — один окончательный ответ; unique `(battle_id, learner_id, request_id)` — повтор запроса. **PG CHECK:** `points BETWEEN 0 AND 100`, `round BETWEEN 1 AND 5`.

**Реализация 18.6:** `battle_participants.score` растёт при **разрешении** раунда (начало `reveal`) в той же транзакции, что и ответы, которые его разрешили, — одним выражением на обе строки; поэтому опрос отдаёт текущий счёт без запроса, а матч, оконченный досрочно (сдача, отсутствие), считает только разрешённые раунды (ответ на неразрешённый раунд очков не даёт). `finish()` SUM по `battle_answers` не делает — источник счёта один. `meta` хранит: `answered {challenger: n, opponent: n}` — последний отвеченный раунд каждого (флаги `my_answered`/`opponent_answered` опроса без запроса к `battle_answers`); `rounds_played` — разрешённых раундов на момент конца (`result.rounds` и разбор только для них); `left` (роль покинувшего комнату), `voided_reason` (`audio_missing` | `practice_off`), `audio_failed_by`, `audio_reports {request_id: verified}` (идемпотентность `audio-failed`). `timeout`-строка: `payload = {"timeout": true}`.

**Реализация 18.6-fix (ревью сервера баттла):**
- `request_id` `timeout`-строки — **случайный uuid v4** (`Str::uuid()`), а не uuid5 от `battle:round:learner`: детерминированную формулу клиент мог вычислить (свой `learner.id` и `battle_id` ему известны) и прислать её как `request_id` ответа на предыдущий раунд — тогда INSERT таймаута бился в unique `(battle_id, learner_id, request_id)`, опрос обоих отдавал 500, дворник падал, а «застрявший» выигрывал по отсутствию соперника (Б1). Детерминизм таймауту не нужен: строка пишется под `FOR UPDATE` только игроку без ответа, повтор держит unique `(battle_id, round, learner_id)`.
- Новая колонка `battles.open_pair varchar(64) nullable **unique**` (миграция `2026_10_30_100005_add_open_pair_to_battles_table`, `down()` снимает CHECK, индекс и колонку): `"<меньший learner_id>:<больший>"` пока матч не терминален, `NULL` после любого конца — **«одна пара — один незавершённый матч» как факт БД** (как `active_learner_id` и `challenges.open_pair`). PG CHECK `battles_open_pair_shape_check (open_pair IS NULL OR status NOT IN ('finished','closed'))`. Ставит `invite()`, снимают `BattleClock::close/finish` и клейм дворника; фабрика `BattleFactory` ставит ключ любому нетерминальному матчу. Зачем — §8.2 (встречные приглашения).

### 2.8 Снимок (`snapshot` jsonb) — серверный, никогда не отдаётся целиком

```jsonc
{ "v": 1, "mode": "sentence", "locales": ["ru", "uz"],
  "pool": [ {"version_id": 41}, {"word_id": 1007} ],              // неиспользованные кандидаты в seeded-порядке (замена диктанта)
  "elements": [
    { "id": "eK7qM2x9Ab", "kind": "sentence", "item_id": 12, "version_id": 40,
      "prompt": {"ru": "…", "uz": "…"}, "fragments": [{"id": "fQ1…", "text": "mother"}, …],   // порядок показа (перемешан)
      "accepted": ["my mother is a teacher"],                                                     // нормализованные тексты допустимых последовательностей
      "accepted_display": ["My mother is a teacher."],                                            // для разбора (§5.3): [0] = display или собранная первая
      "explanation": {"ru": "…", "uz": "…"} },
    { "id": "eZ3…", "kind": "collocation", "text": "Let's ___ a meeting on Monday.",
      "options": [{"id": "oA…", "text": "make"}, …], "correct_ids": ["oB…"], "full_text": "Let's have a meeting on Monday.",
      "translation": {...}, "explanation": {...} },
    { "id": "eD4…", "kind": "dictation", "source": "phrase" | "word", "word_id": null | 1007, "version_id": 44 | null,
      "audio_disk": "practice_audio" | "public", "audio_path": "3f….mp3" | "audio/7a….mp3", "display": "See you tomorrow",
      "expected": ["see you tomorrow"], "words": 3, "translation": {...}, "explanation": {...} | null },
    { "id": "eP5…", "kind": "pairs_round",
      "left":  [{"id": "lM…", "word_id": 1001, "text": "mother"}, …],                 // перемешаны
      "right": [{"id": "rT…", "text": {"ru": "мама", "uz": "ona"}}, …],               // перемешаны отдельно; текст по локали смотрящего
      "pairs": {"lM…": "rT…", …} }
  ] }
```

Элемент `kind` совпадает с `mode`, кроме пар (`pairs_round`). Resource-классы строят клиентскую проекцию через `PracticeModeHandler::project` — ожидаемые ответы, `pairs`, `correct_ids`, `accepted`, `expected`, `audio_path`, `word_id`, `version_id` **не** попадают в ответ (закреплено контрактным тестом с запретными ключами, как `CONTRACT_FORBIDDEN_EXAM_KEYS`).

### 2.9 Миграции (по порядку; все с рабочим `down()`, проверяются `DatabaseSchemaTest`)

| # | Файл | Что |
|---|---|---|
| 1 | `2026_10_30_100001_add_practice_pilot_at_to_learners_table` | `learners.practice_pilot_at`; `down()` — drop column |
| 2 | `2026_10_30_100002_create_practice_items_tables` | `practice_items` (без FK на версии), `practice_item_versions`, `practice_item_words`; partial `practice_item_versions_audio_pending_idx` (pgsql и sqlite raw `CREATE INDEX … WHERE`); PG CHECK; `down()` — drop три таблицы в обратном порядке |
| 3 | `2026_10_30_100003_create_practice_sessions_tables` | `practice_sessions`, `practice_attempts`; PG CHECK; `down()` — drop |
| 4 | `2026_10_30_100004_create_battles_tables` | `battles`, `battle_participants`, `battle_answers`; PG CHECK; `down()` — drop |
| 5 | `2026_10_30_100005_add_open_pair_to_battles_table` (18.6-fix) | `battles.open_pair` nullable unique + PG CHECK `battles_open_pair_shape_check`; backfill нетерминальных строк (первый матч пары берёт ключ); `down()` — drop CHECK, unique, column |
| 5 | `2026_10_30_100010_add_scope_to_practice_item_versions_table` | `practice_item_versions.target_word_ids` / `lesson_id` / `level` + backfill всех версий из их заданий (чанки по `chunkById`, query builder); на SQLite FK добавляется пересборкой таблицы, поэтому partial-индекс аудио снимается и ставится заново; `down()` — drop FK и три колонки |

Правила: новые forward-миграции (не правка на месте — прод-релиз был); `uuid` — `$table->uuid('request_id')` (на SQLite — `char(36)`); CHECK — только `if (DB::getDriverName() === 'pgsql')` (образец `2026_10_23_400001`); partial-индекс — raw `CREATE INDEX` для `pgsql`/`sqlite` (образец `2026_07_13_000002`). **На SQLite** инварианты «один активный» держат unique-колонки `active_learner_id` (работают везде), а CHECK-инварианты дублируются в сервисах (`assertShape`) и тестах.

Отвергнуто: partial unique `(learner_id) WHERE status = 'active'` — на SQLite `ALTER TABLE` терял бы предикат при перестройке (прецедент 2D.4), unique-колонка проще и уже применяется (`challenges.open_pair`); FK `practice_items.current_version_id → versions` — цикл FK усложняет создание/`down()`, выигрыш нулевой (пишет один сервис).

---

## 3. Флаги, пилот, тариф, `/me`

### 3.1 `config/practice.php` (окончательные ключи; `env()` — только у флагов)

```php
return [
    'access' => env('PRACTICE_ACCESS', 'off'),          // off | pilot | all
    'plan' => env('PRACTICE_PLAN', 'all'),              // all | pro
    'modes' => [
        'pairs' => (bool) env('PRACTICE_MODE_PAIRS', true),
        'collocation' => (bool) env('PRACTICE_MODE_COLLOCATION', true),
        'sentence' => (bool) env('PRACTICE_MODE_SENTENCE', true),
        'dictation' => (bool) env('PRACTICE_MODE_DICTATION', true),
    ],
    'battle' => [
        'enabled' => (bool) env('PRACTICE_BATTLE', true),
        'rounds' => 5, 'pairs_per_round' => 4,
        'round_seconds' => ['collocation' => 30, 'pairs' => 45, 'sentence' => 45, 'dictation' => 60],
        'countdown_seconds' => 3, 'reveal_seconds' => 4,
        'invite_ttl_seconds' => 300, 'room_ttl_seconds' => 120,
        'presence_grace_seconds' => 30, 'heartbeat_write_seconds' => 5, 'deadline_grace_ms' => 1500,
        'points' => ['round' => 100, 'pair' => 25],
        'finished_list' => 10,
    ],
    'solo' => [
        'session_ttl_hours' => 24,
        'items' => ['size' => 5, 'min' => 3],
        'pairs' => ['rounds' => 3, 'per_round' => 4, 'min_per_round' => 3],
        'dictation' => ['max_replacements' => 2, 'phrases_max' => 3],
    ],
    'content' => [
        'sentence_fragments' => ['min' => 3, 'max' => 8], 'sentence_accepted_max' => 6, 'fragment_max_length' => 40,
        'collocation_options' => 3, 'collocation_text_max' => 160,
        'dictation_text_max' => 80, 'dictation_accepted_max' => 5,
        'key_pattern' => '/\A[a-z0-9][a-z0-9._-]{0,63}\z/',
        'explanation_max' => 300, 'prompt_max' => 200,
    ],
    'grading' => [
        'typo_min_length' => 5,
        'protected_tokens' => ['not', 'no', 'never', 'none', 'nothing', 'nobody',
            'zero','one','two','three','four','five','six','seven','eight','nine','ten','eleven','twelve','thirteen','fourteen','fifteen',
            'sixteen','seventeen','eighteen','nineteen','twenty','thirty','forty','fifty','sixty','seventy','eighty','ninety','hundred','thousand','million',
            'first','second','third','half'],
    ],
    'import' => ['max_bytes' => 2 * 1024 * 1024, 'max_items' => 300],
    'audio' => ['disk' => 'practice_audio'],   // config/filesystems.php: root storage/app/practice-audio, без url (§10.5)
];
```

**Реализация 18.6b.** В `battle` добавлен ключ `presence_warn_seconds` = 10 (молчание соперника, после которого экран предупреждает; не больше `presence_grace_seconds`, §8.4), читают его `BattleSettings` и `BattleClock::isSilent`. Числа баттла (`rounds`, `round_seconds`, `countdown_seconds`, `reveal_seconds`, `invite_ttl_seconds`, `room_ttl_seconds`,
`presence_*`, `heartbeat_write_seconds`, `deadline_grace_ms`, `points`, `finished_list`) меняются релизом, не окружением: из `env()` читается только `PRACTICE_BATTLE` (см. `DEVOPS_GUIDE`, «Practice variables»). Клиенту отдаётся то, что нужно экрану: `rounds_total`, `round_seconds`, `countdown_seconds`, `reveal_seconds`, `presence_grace_seconds`, `presence_warn_seconds`, `deadline_grace_ms` и готовые метки `invite_expires_at` / `room_expires_at` / `starts_at` — клиент ничего не угадывает.

### 3.2 Проверки доступа (на сервере, на каждом эндпоинте)

`PracticeAccess::state(Learner)`:

| Условие | Состояние | Ответ эндпоинтов практики |
|---|---|---|
| `access = off` | `off` | 403 `practice_unavailable` |
| `access = pilot` и `learners.practice_pilot_at IS NULL` | `not_in_pilot` | 403 `practice_unavailable` (один код — не раскрываем, есть ли пилот) |
| `plan = pro` и `EntitlementResolver::for()->plan` не Pro | `pro_required` | 403 `practice_pro_required` (paywall-reason `practice`, как `exam_simulator_pro_required`) |
| иначе | `available` | — |

Порядок: сначала доступ (`assertCanPractice`), затем флаг режима/баттла (`assertModeEnabled` / `assertBattleEnabled`) — **только на создании** (`POST /practice/sessions`, `POST /practice/battles`, `…/accept`) и в хабе/опциях (режим показан как `disabled`). Ответы, опрос, аудио, итоги — проверяют лишь `assertCanPractice`. Следствия:

- выключили режим → новые сессии/приглашения этого режима отклоняются 409 `practice_mode_disabled`; активные сессии доигрываются до TTL, идущие матчи — до конца (в т. ч. дворником);
- выключили баттл → `invite`/`accept` → 409 `battle_disabled`; живые матчи доигрываются; приглашения истекают сами;
- `access = off` → все эндпоинты 403, `practice:battles-sweep` переводит live-матчи в `closed/voided` (`meta.voided_reason = practice_off`), соло-сессии гаснут по TTL;
- `plan = pro` включили посреди сессии Free-ученика → следующий запрос 403 `practice_pro_required`, TWA показывает пейвол поверх; сессия остаётся активной до TTL (возврат возможен, если тариф появится).

Баттл требует `available` у **обоих**: приглашение проверяет друга (`assertFriendCanPractice` → 409 `battle_friend_no_access`), принятие — себя.

### 3.3 Блок `practice` в `GET /api/twa/me` (всегда присутствует)

```json
"practice": {
  "available": true,
  "pro_required": false,
  "modes": ["pairs", "collocation", "sentence", "dictation"],
  "battle": true
}
```

- `available` — `state ∈ {available, pro_required}`; `false` → TWA **не показывает** входы (курс, итог занятия, друзья) и маршруты практики отвечают 403;
- `pro_required` — Free-ученик при `plan = pro`: входы показаны с чипом Pro, нажатие открывает `PaywallSheet reason="practice"`, сервер всё равно отвечает 403 `practice_pro_required`;
- `modes` — включённые режимы (список), `battle` — флаг баттла. `MePayloadBuilder::build` += `'practice' => $this->access->describe($learner, $entitlement)`; `MeResource` отдаёт блок; `TwaContractTest` расширяется ключами `practice.available|pro_required|modes|battle`.

**Реализация 18.1.** Блок `practice` есть не только в `GET /me`, но и во всех ответах формы `/me` (`PATCH /me/settings`, `POST /billing/subscription/cancel|resume`, `POST /promo/redeem`): их собирает один `MePayloadBuilder`. Без блока (старый сервер, сбой `/me`) TWA считает, что практики нет: вход не рисуется (`billing.practiceAccess`).

### 3.4 События `product_events` (единственная запись вне таблиц практики)

| Имя | props | Когда |
|---|---|---|
| `practice.session_started` | `session_id, mode, course_id, lesson_id, source, total, retry` | создана соло-сессия |
| `practice.session_finished` | `session_id, mode, total, answered, correct_first, hints, mismatches, reason, duration_ms` | `completed` / `expired` / `replaced` (один раз, кто перевёл в finished) |
| `practice.not_enough_material` | `mode, course_id, lesson_id, available, required, reason, battle` | отказ 422 (соло и баттл) |
| `practice.audio_failed` | `session_id|battle_id, source (word|phrase), reason (file_missing|client_report), verified` | замена/сообщение о сбое |
| `battle.invited` | `battle_id, mode, course_id, lesson_id, rematch` | приглашение |
| `battle.accepted` / `battle.started` | `battle_id` (+ `mode`) | принятие / оба готовы |
| `battle.finished` | `battle_id, end_reason, result (challenger|opponent|draw), rounds_played, duration_ms` | статус `finished` |
| `battle.closed` | `battle_id, end_reason` | статус `closed` |

Все — через `ProductEventRecorder::record` **после commit** (`DB::afterCommit`), actor — ученик (`battle.*` — инициатор). Ключей с PII нет.

---

## 4. Материал

### 4.1 Слова (пары соло, диктант слов соло)

Кандидат — активное слово (`words.is_active = true`) курса `scope.course` (и урока `scope.lesson`, если задан), **введённое ученику**: есть строка `word_repetitions (learner_id, word_id)` — любое состояние, включая `new`. Курс доступен ученику (`CourseCatalogService::findAvailable(slug, learner)` — своя колода годится). Один запрос:

```sql
SELECT w.id, w.word, w.translation, w.audio_path
FROM word_repetitions wr
JOIN words w   ON w.id = wr.word_id AND w.is_active = true
JOIN lessons l ON l.id = w.lesson_id
JOIN stages s  ON s.id = l.stage_id
WHERE wr.learner_id = :learner AND s.course_id = :course [AND l.id = :lesson]
ORDER BY w.id
```

Индексы: `word_repetitions (learner_id, word_id)` (unique, есть), `words (lesson_id)` (есть), `lessons (stage_id, number)` (есть). Переводы — `WordTranslationResolver::resolveMany($words, $locale)` (один запрос; для `uz` цепочка `[uz, ru]` даёт обе локали). Итого 2 запроса на режим.

Диктант слов дополнительно: `w.audio_path === WordAudioService::pathFor(w.word)` (без диска) — слово с актуальным предсгенерированным аудио; у выбранных 5 проверяется `Storage::exists && size > 0` (до 5 обращений к диску при построении снимка; файла нет → следующий кандидат из пула).

### 4.2 Авторские задания (сочетание, предложение, фраза-диктант)

Соло — опубликованные задания (`practice_items.status = published`, версия `published_version_id`) курса/урока того же типа, у которых **все** целевые слова активны и введены ученику; диктант — дополнительно `audio_status = ready`. Урок и слова берутся из проекции (§2.4) — это урок и слова опубликованной версии. Правило «без целевых слов» не нужно: публикация требует ≥ 1 целевого слова в публикуемой версии (§4.5).

```sql
SELECT i.id, i.published_version_id
FROM practice_items i
WHERE i.course_id = :course [AND i.lesson_id = :lesson] AND i.type = :type AND i.status = 'published'
  AND NOT EXISTS (SELECT 1 FROM practice_item_words iw
                  JOIN words w ON w.id = iw.word_id
                  LEFT JOIN word_repetitions wr ON wr.word_id = iw.word_id AND wr.learner_id = :learner
                  WHERE iw.item_id = i.id AND (w.is_active = false OR wr.id IS NULL))
```
затем `SELECT * FROM practice_item_versions WHERE id IN (...) [AND audio_status = 'ready']`. Для баттла (`learner = null`) анти-join проверяет только `w.is_active`. Индексы: `practice_items (course_id, status, type)` / `(lesson_id, status, type)`, `practice_item_words (item_id, word_id)`. 2 запроса на тип.

**Как реализовано (18.9b):** годность задания — не фильтр запроса, а его колонка `lock_state = CASE WHEN EXISTS(слово неактивно) THEN 2 WHEN EXISTS(слово не введено ученику) THEN 1 ELSE 0 END` (ученик — binding), строки сортирует PHP; запросов столько же (2 на все типы сразу, хаб 9). Так видны «запертые» задания (`1`: целевые слова ещё не введены, неактивных нет) — основа причины `items_locked` (§4.3); `2` (слово выключено) не откроет никакое обучение и не считается нигде.

### 4.3 Размеры и распределение

| Режим | Соло | Баттл |
|---|---|---|
| `pairs` | `k = min(3, floor(N/3))` раундов из `N` пригодных слов; `k = 0` → мало материала; `total = min(N, 4k)` слов делится поровну: первые `total mod k` раундов получают `floor(total/k)+1`, остальные `floor(total/k)` (всегда 3 или 4). Примеры: 12 → 4,4,4; 11 → 4,4,3; 10 → 4,3,3; 9 → 3,3,3; 8 → 4,4; 7 → 4,3; 6 → 3,3; 5 → 4; 4 → 4; 3 → 3; 2 → ошибка. Раунд, в который после упаковки (§4.4) попало < 3 слов, отбрасывается; 0 раундов → ошибка | ровно 5 раундов × 4 пары = 20 слов, упакованных без конфликтов в **каждой** локали игроков; иначе формат недоступен |
| `collocation`, `sentence`, `dictation` | `size = 5`, `min = 3`: `n = min(5, available)`, `n < 3` → ошибка | ровно 5 заданий (диктант — только фразы с готовым аудио) |
| `dictation` соло — состав | `phrases = min(eligible_phrases, 3)`, остальное — слова; слов не хватает → добирается фразами; `n < 3` → ошибка | только фразы |

Ошибка 422 `practice_not_enough_material` (соло) / `battle_material_unavailable` (баттл) с `details`:
```json
{ "mode": "pairs", "available": 2, "required": 3, "reason": "no_words", "course": "english-a1", "lesson_id": 41 }
```
`reason`: `no_words` (введённых слов меньше минимума), `ambiguous` (слов хватает, но после исключения неоднозначных — нет), `no_items` (опубликованных заданий типа меньше минимума и «запертые» их не дополнят), `items_locked` (задания есть, но не хватает из-за невведённых целевых слов: с «запертыми» хватило бы — выучите слова урока), `audio_pending` (заданий хватает, но аудио не готово), `no_audio_words` (диктант слов: нет слов с готовой озвучкой). TWA по `reason` + `lesson_id` объясняет причину и предлагает «Учить урок» (`POST /training/sessions {mode: lesson, lesson_id}`) либо другой урок.

### 4.4 Однозначность пар

Ключи сравнения (`Support/TranslationVariants`, `PracticeTextNormalizer::key`):

- английский ключ: `key(word)` = NFKC → `mb_strtolower` → апострофы `’‘ʼ´`` → `'` → схлопнуть пробелы → trim;
- варианты перевода: убрать скобочные пояснения `\([^)]*\)`; разбить по `,` `;` `/`; каждый вариант: NFKC → lower → `ё → е` → убрать конечные `.!?…` → схлопнуть пробелы → trim; пустые отбросить. Подпись правой карточки — **первый** вариант исходной строки (в исходном регистре);
- слово без вариантов (пустой перевод) — непригодно.

Два слова **конфликтуют** в одном раунде, если равны английские ключи или пересекаются множества вариантов хотя бы в одной локали из `LocaleSet` (после fallback: для `uz` — текст `uz`, при его отсутствии `ru`). Ключами слова всегда служат и варианты его базового перевода `words.translation` (тег `base|…`; пустой базовый перевод не делает слово непригодным, просто нет таких ключей): так `mother` (uz «ona», база «мама») и `mum` (uz нет, на карточке «мама») разойдутся по раундам и у uz-ученика, и в баттле uz+uz. Упаковка (детерминирована seed'ом): слова в seeded-порядке; каждое кладётся в первый раунд, где есть место и нет конфликта; не поместилось — пропускается (попадёт в `pool`). Так два слова с переводом «мама» (`mother`, `mum`) оказываются в разных раундах, а не исключаются вовсе. Проверка — по локализованным текстам, не только по `words.translation` (docs/15 §3.1).

### 4.5 Валидация контента (сохранение версии / публикация)

Коды `PracticeContentException` (422 в API редактора, `ImportIssue`-коды импорта — те же строки): проверяются при сохранении **и** при публикации (публикация строже — помечено «П»).

| Код | Правило |
|---|---|
| `key_invalid` | `key` не по `content.key_pattern` |
| `key_duplicate` | второй `key` в одном курсе (unique `(course_id, key)`) |
| `type_invalid` | тип вне трёх |
| `course_invalid` | курс — колода learner'а или не найден |
| `lesson_not_in_course` | урок не из курса |
| `level_invalid` | не `A1`..`C2` |
| `target_words_required` (П) | в **публикуемой версии** нет целевых слов (проверяются `target_word_ids` самой версии; так же при `import --publish` и сохранении с публикацией) |
| `target_word_unknown` | слово не найдено среди активных слов курса (по `words.word` без регистра; в редакторе — по id; в сообщении — текст слова, а номер только если слова уже нет) |
| `text_required` / `text_too_long` | обязательные тексты; лимиты `content.*_max` |
| `markup_not_allowed` | в тексте (`prompt`, `explanation`, `translation`, `fragments`, `display`, `text`, `options`, варианты диктанта) есть `<` или `>`: разметки в заданиях нет, а `strip_tags` молча отрезал бы текст после одиночного `<` («I <3 you» → «I »). `&` и кавычки — обычный текст |
| `locale_text_required` (П) | `prompt`/`translation` без `ru` или `uz`; `explanation` без `ru`/`uz` у `collocation` и `sentence` (у `dictation` `explanation` необязательно) |
| `fragments_count` | `sentence`: фрагментов вне `[min, max]` (3..8) |
| `fragment_invalid` | пустой фрагмент / длиннее 40 / с переводом строки |
| `accepted_required` / `accepted_too_many` | `sentence`: нет допустимых последовательностей / больше 6 |
| `accepted_not_permutation` | последовательность — не перестановка **всех** фрагментов (сравнение мультимножеств нормализованных текстов) |
| `accepted_first_mismatch` | `sentence`: **первая допустимая последовательность не совпадает с порядком фрагментов** (сравнение нормализованного прочтения). Порядок фрагментов — всегда первая допустимая; в форме админки поле «Другие допустимые порядки» содержит только остальные, а порядок фрагментов добавляется первым автоматически (повторы первого и друг друга в форме отбрасываются). В файле импорта `accepted` по-прежнему содержит полные порядки. Проверяется и при публикации сохранённого содержимого |
| `accepted_duplicate` | две одинаковые (по нормализованному тексту) последовательности — убираются молча при сохранении, ошибка только если дубликат — единственная |
| `display_mismatch` | `sentence`: `display` задан, но его нормализованный текст ≠ нормализованному тексту первой допустимой последовательности (разбор не должен противоречить проверке); `display` длиннее `prompt_max` — `text_too_long` |
| `placeholder_invalid` | `collocation`: в `text` не ровно один `___` |
| `options_count` / `options_duplicate` | не ровно 3 варианта / совпадают после нормализации |
| `correct_invalid` / `all_options_correct` | `correct` пуст, вне `options` или содержит все 3 |
| `dictation_accepted_invalid` | вариант пуст / длиннее 80 / больше 5 дополнительных; `text` добавляется в `accepted` первым автоматически |
| `audio_not_ready` | **не** блокирует публикацию: выдача фильтрует `audio_status = ready` (§4.2); в админке — колонка «Аудио» и действие «Сгенерировать заново» |

Отвергнуто: блокировать публикацию до готовности аудио — ломает `import --publish` (аудио генерируется асинхронно после commit); требовать 4–8 фрагментов — A1-контент часто из 3 слов, 3 фрагмента дают 6 перестановок.

### 4.6 Детерминизм перемешивания

`Support/SeededShuffle::order(string $seed, string $label, int $n): list<int>` — keyed-sort индексов по `hash_hmac('sha256', "$label|$i", $seed, true)` (ровно как `ExamQuestionPersonalizer::permutation`). `seed = random_bytes(32)` на сессию/баттл, используется при построении снимка и **не сохраняется**: раскладка уже зафиксирована в снимке, повторная загрузка отдаёт её же. В тестах seed передаётся явно (`PracticeSnapshotBuilder::build(..., seed: str_repeat('a', 32))`) — ожидаемые раскладки фиксируются. `OpaqueId::make(prefix)` — `prefix . Str::random(10)` (62-символьный алфавит), уникальность внутри снимка проверяется при генерации (повтор → новая строка).

---

## 5. Проверка ответов

### 5.1 Нормализация (`Support/PracticeTextNormalizer`)

`normalize(string): string` — по порядку:
1. `Normalizer::normalize(…, FORM_KC)` (если есть intl; иначе как есть);
2. апострофы и кавычки `’ ‘ ʼ ´ ` ′ "` → `'` (двойные кавычки `" « »` удаляются);
3. тире/дефисы `– — ‑ -` → пробел («well-known» ≡ «well known»);
4. пунктуация `. , ! ? ; : … ( ) [ ]` → пробел;
5. `mb_strtolower`;
6. схлопнуть пробелы, `trim`.
`tokens(string): list<string>` — `normalize` → `explode(' ')` (пусто → `[]`). Апостроф внутри токена сохраняется (`don't`), цифры сохраняются. Сокращения (`I'm`/`I am`, `don't`/`do not`) **не** приравниваются: только авторские варианты `accepted`.

`SpellingGrader::distance` (OSA) переиспользуется для расстояния токенов; `SpellingGrader::normalize` не используется (другие правила пунктуации).

### 5.2 Диктант (`DictationGrader::grade(list<string> $expected, string $answer): GradeResult`)

Для каждого допустимого варианта `E` считается вердикт, берётся лучший (`correct` > `typo` > `wrong`):

1. `norm(answer) === norm(E)` → `correct`;
2. `typo`, если **все** условия: `tokens(answer)` и `tokens(E)` одной длины; ровно одна позиция `i` с разными токенами `a ≠ e`; `OSA(a, e) = 1`; `mb_strlen(e) ≥ 5` (`grading.typo_min_length`); `!InflectionGuard::isInflection(a, e)`; **набранный токен `a` — не известная английская словоформа** (`!EnglishLexicon::isWord(a)`, ниже); `e` и `a` не в `grading.protected_tokens` (отрицания, числительные **и порядковые**); ни `e`, ни `a` не содержат `n't`; ни один не состоит из цифр;
3. иначе `wrong`.

`InflectionGuard::isInflection(a, e)` (правка координатора: стеммер с отрезанием `es`/`ed` ошибался — `coffee`/`coffees` давали разные стемы `coffe`/`coff` и проходили как опечатка). Так как до проверки уже известно `OSA(a, e) = 1`, смена грамматической формы на одну правку бывает только на конце слова, и правило — по хвосту: пусть `x` — более короткий токен, `y` — более длинный (при равной длине — любой порядок); `true`, если
- (а) `y === x.c`, где `c` ∈ {`s`, `d`, `r`, `n`, `y`} — дописана одна из концевых букв форм: `s` (мн. число, 3-е лицо), `d` (прошедшее), `r` (степень, «деятель»), `n` (причастие), `y` (прилагательное от существительного) — `teacher/teachers`, `coffee/coffees`, `like/liked`, `nice/nicer`, `take/taken`, `rain/rainy`;
- (б) длины равны, токены совпадают во всём, кроме последней буквы, и эти две последние буквы — две разные из {`s`, `d`, `r`, `n`, `y`} (`likes/liked`, `watches/watched`, `walker/walked`, `taker/taken`, `rains/rainy`).
Иначе `false`. Строгость намеренная (docs/15 §3.4: сомнительное — ошибка): `friend`/`frien` (потеряна конечная `d`) — `wrong`; ложные «формы» безвредны — такой ответ просто не получает пометку «опечатка».

`EnglishLexicon::isWord(a)` (`app/Domain/Practice/Support/EnglishLexicon.php`, чистая функция без БД; правка 18.9b по docs/15 §3.4 — «другое слово» и «другая форма слова» не опечатка: `light` вместо `right`, `were` вместо `where`, `wrote` вместо `write`, `women` вместо `woman`). Данные — `resources/lexicon/en-common.txt`: слова A1–B2, **все** формы неправильных глаголов, неправильные множественные и степени сравнения; одно слово в строке, нижний регистр, отсортирован, шапка `#`; читается один раз за процесс. Токен — слово, если он в списке **или** одно правило правописания даёт лемму из списка: `'s`; `-s`, `-es` (после s, x, z, ch, sh, o), `-ies` → `y`; `-ed`, `-d` (после `e`), удвоенная согласная + `ed`, `-ied` → `y`; `-ing`, `-ing` + `e`, удвоенная согласная + `ing`, `-ying` → `ie`; `-er`/`-est`, `-r`/`-st` (после `e`), удвоенная + `er`/`est`, `-ier`/`-iest` → `y`; `-ly`, `-ily` → `y` (основа ≥ 2 букв; правило применяется один раз). Ошибаться можно только в безопасную сторону: лишнее «слово» (`begining` = `begin` + `ing`) делает проверку строже (`wrong` вместо `typo`), недостающее слово оставляет прежнее поведение (опечатка прощается).

Пустой ответ или `skip: true` → `skipped` (в итогах — ошибка, подсказка `+1`; разбор показан). `typo` в соло: показывается «Есть опечатка», в `correct_first` **не** считается; в баттле: 0 очков, в разборе — пометка «опечатка».

Разбор (`reveal`): `expected` (первый вариант `display`), `your_text` (как прислан, обрезка 200), `diff` — токены ожидаемого рядом с токенами ответа при равной длине (`[{expected, got, ok}]`), иначе `null`; `translation`, `explanation`.

**Тест-кейсы диктанта** (`tests/Unit/Practice/DictationGraderTest.php`):

| # | expected (accepted) | answer | Вердикт | Почему |
|---|---|---|---|---|
| 1 | `See you tomorrow` | `see you tomorrow!` | correct | регистр и пунктуация |
| 2 | `See you tomorrow` | `See  you   tomorrow` | correct | пробелы |
| 3 | `See you tomorrow` | `see you tomorow` | typo | 1 токен, OSA 1, len 8, не форма |
| 4 | `beautiful` (слово) | `beatiful` | typo | один токен, OSA 1, len 9 |
| 5 | `receive` | `recieve` | typo | транспозиция = 1 |
| 6 | `I have fifteen apples` | `I have fifty apples` | wrong | числительные защищены (и OSA > 1) |
| 7 | `She does not like tea` | `She does like tea` | wrong | число токенов различается (пропуск not) |
| 8 | `I don't know` | `I dont know` | wrong | токен с `n't` защищён |
| 9 | `He likes coffee` | `He liked coffee` | wrong | форма: последние буквы `s`/`d` (правило б) |
| 10 | `teacher` | `teachers` | wrong | форма: дописана `s` (правило а), хотя OSA 1 |
| 11 | `I want tea` | `I wont tea` | wrong | len(want) = 4 < 5 |
| 12 | `There is a cat` | `Their is a cat` | wrong | OSA(there, their) = 2 |
| 13 | `I am a student` + accepted `I'm a student` | `i'm a student.` | correct | авторский вариант |
| 14 | `I am a student` (без accepted) | `I'm a student` | wrong | число токенов |
| 15 | `See you tomorrow` | `` / `skip` | skipped | подсказка |
| 16 | `Ice cream` | `ice-cream` | correct | дефис → пробел |
| 17 | `It's five o'clock` | `its five oclock` | wrong | два токена различаются |
| 18 | `We walked home` | `We walkd home` | typo | OSA 1, len 6; `walkd` — не `walked` без `s`/`d` и не замена `s`↔`d` (не форма) |
| 19 | `I like coffee` | `I like coffees` | wrong | форма: дописана `s` (стеммер давал бы ложную «опечатку») |
| 20 | `My friend` | `My frien` | wrong | потеряна конечная `d` — правило (а), строгость намеренная |
| 21 | `They watched TV` | `They watches TV` | wrong | замена `d`→`s` в последней букве (правило б) |
| 22 | `Call me later` | `Call me late` | wrong | `late` — другое слово |
| 23 | `Where is your father?` | `Were is your father` | wrong | `were` — другое слово |
| 24 | `I wrote a letter` | `I write a letter` | wrong | `write` — слово (неправильный глагол), не опечатка |
| 25 | `The women are here` | `The woman are here` | wrong | другое число: `woman` — слово |
| 26 | `Turn right here` | `Turn light here` | wrong | `light` — другое слово |
| 27 | `The fourth floor` | `The forth floor` | wrong | порядковое защищено (`fourth`) |
| 28 | `The books are on the table` | `The boots are on the table` | wrong | `boots` — другое слово |
| 29 | `a nicer day` | `a nice day` | wrong | форма: дописана `r` (правило а) |
| 30 | `because` | `becuase` | typo | `becuase` — не слово, OSA 1 |
| 31 | `My friend is here` | `My freind is here` | typo | `freind` — не слово |
| 32 | `I love my family` | `I love my famliy` | typo | `famliy` — не слово |
| 33 | `Many people came` | `Many peopel came` | typo | `peopel` — не слово |
| 34 | `It is beautiful` | `It is beautifull` | typo | `beautifull` — не слово |
| 35 | `I cannot swim` | `I canot swim` | typo | `canot` — не слово |
| 36 | `We meet on Wednesday` | `We meet on Wendesday` | typo | `wendesday` — не слово |
| 37 | `I go to school` | `I go to shcool` | typo | `shcool` — не слово |
| 38 | `Call the client today` | `Call the client todya` | typo | `todya` — не слово |

**Реализация 18.5** (`Modes/DictationMode`, `Grading/DictationGrader`, `Support/InflectionGuard`, `PracticeAudioService`, `GeneratePracticeAudioJob`):
- Состав сессии (§4.3) — как в проекте: до `practice.solo.dictation.phrases_max` (3) фраз, остальное — слова; слов не хватает — добираются фразами; **сначала слова, потом фразы** (docs/15 §3.4). Слово годится, только если `words.audio_path` совпадает с файлом, в который хэшируется **текущий** текст слова (`WordAudioService::pathFor`): после правки слова старый файл — уже не его голос.
  Доступность считается без диска (хаб не ходит в файловую систему); файлы выбранных элементов проверяются при построении снимка (`exists && size > 0`, один раз на элемент), отсутствующий пропускается ради следующего кандидата. Причина нехватки: `audio_pending` → `items_locked` → `no_audio_words` → `no_words` → иначе `no_items`.
- Запасные кандидаты (`pool`) — до 4 фраз и до 4 слов; замена берёт следующего того же источника, при его отсутствии — другого, без запасных элемент убирается (`total` уменьшается, минимум один элемент).
- Аудио: снимок называет диск элемента (`practice_audio` — фразы, `public` — слова); **любое другое имя диска сервис не открывает**. Нет файла → 503 `practice_audio_unavailable` и событие `practice.audio_failed`. Сбой последней попытки job'а — `audio_status = failed` + `audio_error`; «Сгенерировать заново» в админке ставит job снова.
- `typo` в соло не засчитывается как «верно с первой попытки» и показывается в итогах отдельно (чип «Опечатка», счётчик `typos`).

### 5.3 Собери предложение (`SentenceGrader`)

Ответ — `order: list<fragment_id>`: все id из снимка ровно по одному разу (мультимножество равно множеству фрагментов), иначе 422 `invalid_answer_shape`; неизвестный id → 422 `unknown_element_id`. Текст = `join(' ', тексты фрагментов по порядку)` → `normalize` → сравнение с каждым `accepted` (уже нормализованы при снимке). Одинаковые слова эквивалентны автоматически (сравнивается текст). `skip: true` → `skipped`. Разбор: `correct_text` — `display`, если задан, иначе первая допустимая последовательность (фрагменты через пробел, первая буква заглавная, точка в конце); `alternatives` — остальные допустимые в том же виде (первая буква заглавная, точка); `explanation`, `your_text` (собранная строка, первая буква заглавная). Фрагменты автор пишет в «середине предложения» (строчные, кроме `I` и имён собственных: `my`, `Monday`) — тогда любой допустимый порядок читается естественно; заглавную первую букву строки ответа TWA рисует сама (только отображение).

| # | fragments | accepted | order (тексты) | Вердикт |
|---|---|---|---|---|
| 1 | I / usually / drink / tea | `I usually drink tea` | I usually drink tea | correct |
| 2 | то же + accepted `Usually I drink tea` | … | Usually I drink tea | correct |
| 3 | то же | … | I drink usually tea | wrong |
| 4 | the / cat / the / dog / sees | `the cat sees the dog` | the(id2) cat sees the(id1) dog | correct — одинаковые слова эквивалентны |
| 5 | I / usually / drink / tea | — | I usually drink | 422 `invalid_answer_shape` — не все фрагменты |
| 6 | I / usually / drink / tea | — | I usually drink tea tea(id повтор) | 422 `invalid_answer_shape` — дубликат id |
| 7 | My / mother / is / a / teacher. | `My mother is a teacher.` | My mother is a teacher. | correct — точка внутри фрагмента не мешает |
| 8 | любой | — | `{"skip": true}` | skipped |

**Реализация 18.4** (`Modes/SentenceMode`, `Grading/SentenceGrader`; тексты правил и форм разбора — в `SentenceGrader`, раскладка и снимок — в режиме):
- Порядок проверок ответа: форма (`order` — непустой список строк; `skip: true` побеждает `order`) → неизвестный id (`unknown_element_id`) → число и повторы (`invalid_answer_shape`, `expected = order_of_all_fragments`). Лимит запроса — не больше 8 id (`PracticeAnswerRequest`).
- Снимок: `prompt` и `explanation` хранятся по локалям `LocaleSet` (соло — одна, локаль ученика; баттл — обе). Поэтому `reveal.explanation` соло-ответа — единственная запись, а в баттле `grade()` берёт первую запись: объяснение для каждого смотрящего читатель раунда берёт из `elements[i].explanation[locale]`. Задание без `explanation` хранит `null`.
- `accepted[i]` и `accepted_display[i]` согласованы по индексу; допустимые последовательности с одинаковым нормализованным текстом хранятся один раз (первая остаётся первой). Формы «остальных» собираются как в §5.3 (заглавная, точка); если `display` кончается на `?` или `!`, этот знак ставится и им — вопрос не получает точки.
- Порядок показа фрагментов — HMAC-перестановка seed'а, которая перетягивается (до 16 попыток), пока показанный порядок читается как допустимое предложение: задание не должно выдавать ответ самим расположением.
- Версия с содержимым, которое нельзя сыграть (фрагмент не строка, нет ни одной настоящей перестановки, нет `prompt`), не входит ни в счётчик доступности, ни в выбор: хаб не обещает того, чего `build()` не соберёт. Для баттла `required = practice.battle.rounds` (5), точно 5 без повторов.
- TWA: ячейки банка не сдвигаются (занятая остаётся пустым слотом); незаконченная строка хранится в памяти страницы (`composables/sentenceDraft.ts`) до отправки ответа и возвращается при возврате к тому же элементу.

### 5.4 Подбери сочетание (`CollocationGrader`)

Ответ `option_id` ∈ options (иначе 422 `unknown_element_id`); `correct` ⇔ `option_id ∈ correct_ids` (верных может быть 1–2). `skip: true` → `skipped`. Разбор: `correct_option_ids`, `full_text`, `translation`, `explanation`.

**Реализация 18.3** (`Modes/CollocationMode`, `Grading/CollocationGrader`): «первый верный» вариант (из него собирается `full_text` разбора) — **первый в списке `correct` автора**, а не вариант с наименьшим индексом. Версия с поломанным содержимым (нет ровно трёх вариантов, нет `___`, нет верного) не входит ни в счётчик доступности, ни в выбор:
хаб не обещает того, чего `build()` не соберёт; такая версия попадает в лог `practice.collocation_content_unplayable` (`item_id`, `version_id`). Разбор доступен отдельным публичным методом `reveal(element, locale)` — баттл показывает его каждому игроку на его языке (метод войдёт в интерфейс режима в 18.6).

**Реализация 18.9b:** разбор **соло**-ответа (`grade()`) несёт ещё `your_option_id` — id выбранного варианта, `null` при «Не знаю» (экран отмечает выбор среди трёх; TWA читает ключ как необязательный). Форма `reveal()` баттла не меняется: разбор раунда один на двоих.

### 5.5 Пары (`PairsGrader`)

Соло: `{left_id, right_id}` — оба из текущего раунда (иначе 422); левая уже сопоставлена → 409 `pair_already_matched`; `correct` ⇔ `pairs[left_id] === right_id`. Состояние раунда: `matched {left: right}`, `mismatches`, `wrong_left` (левые слова, по которым была хотя бы одна ошибка — «первая ошибка по слову фиксируется»). Верный выбор левого слова после ошибки по нему — `matched`, но слово остаётся в `wrong_left`. Раунд завершён, когда все левые сопоставлены (`final = true`) → следующий раунд. Правильное соответствие при ошибке **не** раскрывается.

Баттл: `{pairs: {left_id: right_id, …}}` — ровно все левые, правые без повторов (биекция), иначе 422; `points = 25 × число верных`; вердикт `correct` при 4/4, иначе `wrong` (очки частичные). Разбор: правильная таблица и обе таблицы игроков.

**Реализация 18.2** (`Modes/PairsMode`, `Grading/PairsGrader`, `Support/PairsPacker`, `Support/TranslationVariants`, `DTO/PairsRound`):
- Раунд баттла несёт признак `battle: true` (`PairsRound::$battle`, ставится при построении снимка баттла): им сервер выбирает форму ответа — `{pairs: {…}}` целиком в баттле, `{left_id, right_id}` в соло; чужая форма — 422 `invalid_answer_shape`. Разбор баттла — `{pairs: таблица, correct_left: […]}`, до разрешения раунда не отдаётся.
- `409 pair_already_matched` — и для уже сопоставленного левого слова, и для **уже занятой правой карточки** (`details.left_id` — слово, которому она принадлежит).
- Упаковка (§4.4) — до `PairsPacker::ATTEMPTS = 8` проходов одного seed'а: берётся первый, заполнивший все места, иначе — самый полный по `[размещено, поместилось]`; один seed — всегда одна раскладка. Доступность хаб считает с фиксированным seed'ом `practice.pairs.availability` (число одно и то же при каждом открытии), `build()` повторяет те же проходы. Причины нехватки — `no_words` / `ambiguous`.
- Запасного пула у пар нет (`pool` не пишется: замены для пар не существует). Если все строки доски случайно оказались напротив своих переводов, правая колонка сдвигается на одну позицию (циклический сдвиг целой колонки не бывает соответствием).
- Итог сессии: слово из `wrong_left` — ошибка с вердиктом `wrong` в `mistakes[]`, хотя раунд хранится как пройденный (поправка ядра в `PracticeSessionService::summary`); на нём же строится «Повторить ошибки» (слова `wrong_left`, не меньше раунда).

---

## 6. Жизненный цикл сольной сессии

### 6.1 Состояния и переходы

```
            POST /practice/sessions                 последний элемент принят
  (нет) ───────────────────────────► active ─────────────────────────────► finished/completed
                                      │  expires_at <= now (planировщик practice:close-expired каждые 15 мин,
                                      │  либо лениво при любом обращении)      ──► finished/expired
                                      │  POST /practice/sessions {replace_active: true} другой/той же практики
                                      └──────────────────────────────────────────► finished/replaced
```

- **Одна активная на ученика** — `active_learner_id` unique. `open()` в транзакции: `SELECT … WHERE active_learner_id = :me FOR UPDATE` → есть и не просрочена → без `replace_active` → 409 `practice_session_active` (details `{session_id, mode, course, lesson_id, progress}`); с флагом → `finished/replaced` (+ `active_learner_id = NULL`, событие `practice.session_finished{reason: replaced}`); просрочена → `finished/expired`; затем `INSERT` новой. `UniqueConstraintViolationException` (гонка двух вкладок) → повторное чтение активной строки ученика и ответ ею (как `openPersonal`). Нельзя вызывать внутри внешней транзакции.
- **Возврат к незавершённой** — хаб (`GET /practice/hub`) отдаёт `active_session {id, mode, course, lesson_id, progress}`; TWA показывает «Продолжить» на карточке режима и в шапке хаба. `GET /practice/sessions/{id}` восстанавливает экран с текущего элемента (раскладка та же: снимок).
- **Истечение** — `expires_at = started_at + 24 ч`. Запрос к просроченной активной сессии сначала закрывает её (одиночный условный `UPDATE … WHERE status='active' AND expires_at <= now`), затем отвечает 410 `practice_session_closed`; `GET` финишной сессии — 200 со `summary` (не 410: итоги читаемы всегда).
- **Повтор ошибок** — `POST /practice/sessions {mode, retry_of: <id>, source: "retry"}`: исходная `finished` и своя; материал = её ошибки (`wrong`/`typo`/`skipped`; пары — слова из `wrong_left`), новый seed/раскладка; `source = retry`; `retry_of_session_id`. Пар < 3 или слов и заданий < 1 → 409 `retry_not_available` (TWA скрывает кнопку по `summary.retry_available`). Исходная сессия не меняется. `retry_of` и `source: retry` приходят **только вместе**: одно без другого — 422 (валидация), повтор не выдаётся за обычное открытие и наоборот (18.9b).
- **Кнопка и открытие — одно решение** (18.9b, `PracticeSessionService::retryBlocker`): `summary.retry_available` истинно ровно тогда, когда `open(retry_of)` не откажет по причинам, видимым без сборки снимка: (1) есть что повторять — пары ≥ 3 слов, остальные режимы ≥ 1 слова или задания; (2) ученик допущен (`practice_unavailable`, `practice_pro_required`); (3) режим включён (`practice_mode_disabled`); (4) хотя бы одно из заданий-ошибок ещё играбельно — опубликовано, все целевые слова активны и введены ученику, у диктанта аудио `ready` (**один** запрос `PracticeMaterialService::retryItemStock` по всем заданиям-ошибкам, с тем же `lock_state`, что у `items()` §4.2; у пар запроса нет). Чего предикат не видит — слова раунда пар, которого не стало, двух переводов, ставших неоднозначными, — то находит сборка снимка, и отказ тот же `retry_not_available`. Кнопка считается при каждом чтении итога, а не хранится: вернулись задания — вернулась кнопка. Цена — §13.
- **Порядок `open()`** (18.9b): доступ → исходная сессия повтора и её предикат → курс и урок → **без** `replace_active` чтение активной сессии: жива → 409 `practice_session_active` ДО сборки снимка (чтения материала, диск и событие `practice.not_enough_material` были бы работой впустую); с `replace_active` снимок строится **первым** — нехватка материала (422) не должна закрыть идущую сессию, старая закрывается только в транзакции создания новой. Две вкладки по-прежнему разбирает транзакция (§6.1, первый пункт).
- **Несколько вкладок** — все записи под `FOR UPDATE` строки сессии; вторая вкладка после ответа первой получает 409 `element_not_current` с `details.current` и перечитывает `GET /practice/sessions/{id}`; стор отбрасывает устаревшие ответы поколением (§11.4).
- **Правка контента во время сессии** — не влияет: проверка идёт по снимку; `version_id` в снимке остаётся для аналитики; архив задания тоже не влияет.
- **Выключение режима** — §3.2: доигрывается.

### 6.2 `practice_sessions.state` (jsonb, только под блокировкой)

```jsonc
{ "current": 2,                                    // индекс текущего элемента в snapshot.elements (пары — текущий раунд)
  "elements": {
    "eK7qM2x9Ab": { "status": "answered", "verdict": "typo", "first": true, "hints": 0, "answer": {"text": "see you tomorow"}, "answered_at": "2026-10-30T10:00:03.120Z" },
    "eP5…":       { "status": "answered", "verdict": "wrong", "first": true, "hints": 1 }   // skip → hints 1, verdict skipped→wrong в статистике
  },
  "pairs": { "eR1…": { "matched": {"lM…": "rT…"}, "mismatches": 2, "wrong_left": ["lC…"] } },
  "replacements": 1,
  "replaced": { "eD4…": "eN9…" }                   // элемент → чем заменён (новый элемент дописан в snapshot.elements на то же место)
}
```

Итоговые счётчики (`answered`, `correct_first`, `hints`, `mismatches`) дублируются колонками для отчётов и пишутся тем же UPDATE.

### 6.3 Приём ответа (транзакция, порядок §9)

```
BEGIN
  SELECT * FROM practice_sessions WHERE id = :id AND learner_id = :me FOR UPDATE      -- нет строки → 404 practice_session_not_found
  если status = finished → 410 practice_session_closed
  если expires_at <= now → UPDATE finished/expired, active_learner_id = NULL → 410
  SELECT * FROM practice_attempts WHERE session_id = :id AND request_id = :rid          -- есть, того же kind → COMMIT, ответить result + текущий view (повтор); другого kind → 409 request_id_reused
  элемент ≠ snapshot.elements[state.current] (пары: раунд) → 409 element_not_current {current}
  элемент уже answered → 409 answer_already_accepted {verdict}
  handler->grade(element, answer, state)  — 422 invalid_answer_shape | unknown_element_id | 409 pair_already_matched
  INSERT practice_attempts (…, result)   — unique(session_id, request_id) violation → ROLLBACK, перечитать attempt, ответить им
  UPDATE practice_sessions SET state, answered, correct_first, hints, mismatches[, status=finished, finish_reason=completed, finished_at, active_learner_id=NULL]
COMMIT
afterCommit: product_events (session_finished при завершении)
```

Ответ: `verdict`, `reveal` (по режиму §5), `first_attempt`, `session` (обновлённый `SessionView` с следующим элементом или `summary`). Повтор того же `request_id` возвращает тот же `verdict`/`reveal` (из `practice_attempts.result`) и **текущий** view.

`request_id` принадлежит одному виду запроса (`practice_attempts.kind`: `answer` | `replace`, 18.9b): ответ и замена с одним и тем же `request_id` — 409 `request_id_reused` (без `details`; одна строка журнала не может быть и тем и другим), а не молчаливый «успех» с чужим результатом. Клиент берёт новый uuid на каждый запрос; повторяет только тот, чей ответ потерял.

### 6.4 Итог (`summary`)

```json
{ "total": 5, "answered": 5, "correct_first": 3, "typos": 1, "hints": 1, "mismatches": null,
  "duration_ms": 93000, "finish_reason": "completed", "retry_available": true,
  "mistakes": [ { "element_id": "eD4…", "kind": "dictation", "label": "See you tomorrow", "your_answer": "see you tomorow", "verdict": "typo" } ] }
```
Пары: `correct_first` = слова, сопоставленные верно с первой попытки; `mismatches` = число неверных сопоставлений; `mistakes[]` — слова из `wrong_left` (`label: "mother — мама"`). `duration_ms` = `finished_at − started_at` (сервер).
`retry_available` — то же решение, что у открытия повтора (§6.1, `retryBlocker`): есть что повторять, ученик допущен, режим включён, задания-ошибки ещё играбельны. Не хранится — считается при каждом чтении итога.

---

## 7. API

Все маршруты — `/api/twa/practice/*`, под `TwaAuth` + `SetTwaLocale`; группа `throttle:twa-api`, кроме **опроса** и **аудио** (свои лимитеры вне `twa-api`, §7.5). Контроллеры тонкие (валидация → сервис → Resource); ошибки — `ApiErrorResource` (+ `details`). Чужие сессия/матч/дружба = 404 «не найдено». Все даты — ISO 8601 UTC с миллисекундами; `server_now` — в каждом ответе сессии/баттла.

### 7.1 Хаб

**`GET /practice/hub?course={slug}[&lesson_id={id}]`** — `PracticeHubResource`

```json
{ "course": { "slug": "english-a1", "title": "Английский A1" },
  "lesson": { "id": 41, "stage": 2, "number": 4, "title": "Еда" } | null,
  "modes": [
    { "mode": "pairs", "state": "available", "available": 14, "required": 3, "reason": null, "estimate_seconds": 120 },
    { "mode": "collocation", "state": "not_enough", "available": 1, "required": 3, "reason": "no_items", "estimate_seconds": 90 },
    { "mode": "sentence", "state": "disabled", "available": 0, "required": 3, "reason": null, "estimate_seconds": 150 },
    { "mode": "dictation", "state": "available", "available": 7, "required": 3, "reason": null, "estimate_seconds": 180 }
  ],
  "active_session": { "id": 77, "mode": "pairs", "course": "english-a1", "lesson_id": 41, "progress": { "done": 1, "total": 3 } } | null,
  "battle": { "enabled": true, "active_battle_id": null, "incoming_count": 1 } }
```
`state`: `available` / `not_enough` / `disabled` / `pro_required`. Ошибки: 403 `practice_unavailable`, 404 `course_not_found` / `lesson_not_found`, 422 `lesson_not_in_course`.

### 7.2 Сольная сессия

**`POST /practice/sessions`** (лимитер `twa-practice-open` 10/мин) — тело:
```json
{ "mode": "pairs", "course": "english-a1", "lesson_id": 41, "source": "hub", "replace_active": false, "retry_of": null }
```
`mode` ∈ 4; `course` строка ≤ 64; `lesson_id` int ≥ 1 nullable; `source` ∈ `hub|after_training|retry|deeplink`; `replace_active` bool; `retry_of` int nullable (при нём `mode`/`course`/`lesson_id` берутся из исходной, присланные игнорируются). `source: retry` и `retry_of` — **только вместе** (одно без другого — 422, валидация). Ответ **201** `PracticeSessionResource` (как `GET`). Ошибки: 403 `practice_unavailable` / `practice_pro_required`; 409 `practice_mode_disabled`, `practice_session_active` (details), `retry_not_available`; 404 `course_not_found`, `lesson_not_found`, `practice_session_not_found` (чужая/нет `retry_of`); 422 `lesson_not_in_course`, `practice_not_enough_material` (details §4.3), валидация Laravel.

**`GET /practice/sessions/{id}`** — `PracticeSessionResource`:
```json
{ "id": 77, "status": "active", "mode": "sentence", "source": "hub", "retry_of": null,
  "course": { "slug": "english-a1", "title": "Английский A1" }, "lesson": { "id": 41, "stage": 2, "number": 4, "title": "Еда" } | null,
  "progress": { "done": 2, "total": 5 }, "counters": { "correct_first": 1, "hints": 0, "mismatches": 0 },
  "expires_at": "2026-10-31T10:00:00.000Z", "server_now": "2026-10-30T10:00:05.321Z",
  "current": { "id": "eK7qM2x9Ab", "kind": "sentence", "prompt": "Моя мама — учитель.",
               "fragments": [ { "id": "fQ1…", "text": "teacher" }, { "id": "fW2…", "text": "My" }, … ] } | null,
  "summary": null }
```
Формы `current` по режиму:
- `pairs_round`: `{ "id", "kind": "pairs_round", "round": 2, "rounds": 3, "left": [{id, text}], "right": [{id, text}], "matched": [["lM…","rT…"]], "mismatches": 1 }`;
- `collocation`: `{ "id", "kind": "collocation", "text": "Let's ___ a meeting on Monday.", "options": [{id, text} ×3] }`;
- `sentence`: как выше;
- `dictation`: `{ "id", "kind": "dictation", "source": "phrase", "audio_url": "/api/twa/practice/sessions/77/audio/eD4…", "words": 3, "replacements_left": 2 }` — текста нет; `words` — число слов (для пустых плашек), не буквы.
`status = finished` → `current: null`, `summary` (§6.4). Ошибки: 404 `practice_session_not_found`.

**`POST /practice/sessions/{id}/answer`** (лимитеры `twa-api` + `twa-practice-answer` 90/мин) — тело:
```json
{ "request_id": "9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d", "element_id": "eK7qM2x9Ab", "time_spent_ms": 8200,
  "answer": { "order": ["fW2…", "fQ1…", …] } }
```
`answer` по режиму: пары `{left_id, right_id}`; сочетание `{option_id}` или `{skip: true}`; предложение `{order: [...]}` или `{skip: true}`; диктант `{text: "…" ≤ 200}` или `{skip: true}`. `request_id` — uuid (валидация `uuid`). Ответ 200 `PracticeAnswerResource`:
```json
{ "verdict": "typo", "first_attempt": true,
  "reveal": { "expected": "See you tomorrow", "your_text": "see you tomorow", "diff": [ { "expected": "see", "got": "see", "ok": true }, … ], "translation": "До завтра", "explanation": null },
  "pair": null,
  "session": { "...PracticeSessionResource..." } }
```
Пары: `verdict` `correct|wrong`, `pair: {left_id, right_id}`, `reveal: null`, `round_done: bool`. Ошибки: 404; 410 `practice_session_closed`; 409 `element_not_current` (details `{current}`), `answer_already_accepted` (details `{verdict}`), `pair_already_matched`, `request_id_reused` (этот `request_id` уже принят как замена, §6.3); 422 `invalid_answer_shape`, `unknown_element_id`, валидация.
Разбор сочетания в **соло-ответе** — `{correct_option_ids, full_text, translation, explanation, your_option_id}`: `your_option_id` — id варианта, который выбрал ученик (`null` при «Не знаю»), чтобы экран отметил выбор среди трёх (18.9b). Разбор раунда баттла (`reveal()`) один на двоих, без этого ключа; повтор `request_id` отдаёт его же из `practice_attempts.result`.

**`POST /practice/sessions/{id}/replace`** (`twa-practice-answer`) — `{ "request_id", "element_id", "reason": "audio_unavailable" }` → 200 `PracticeSessionResource` (элемент заменён/удалён, `progress.total` мог уменьшиться). Ошибки: 409 `replace_not_allowed` (не диктант, не текущий, лимит `max_replacements`), `request_id_reused` (этот `request_id` уже принят как ответ), 404, 410.

**`GET /practice/sessions/{id}/audio/{element}`** (лимитер `twa-practice-audio` 60/мин, **вне** `twa-api`) — `audio/mpeg`, `Cache-Control: private, no-store`, `Content-Length`. Ошибки: 404 `practice_session_not_found` (чужая сессия, неизвестный элемент — одинаково), 503 `practice_audio_unavailable` (файла нет: клиент предлагает «Заменить задание»). Доступ к аудио завершённой сессии — разрешён (повторное прослушивание в разборе) до `expires_at`.

### 7.3 Баттл — подготовка и список

**`GET /practice/battles/options?friendship_id=11&course=english-a1[&lesson_id=41]`** — `BattleOptionsResource`:
```json
{ "friend": { "first_name": "Dilnoza" }, "course": { "slug", "title" }, "lesson": {...} | null,
  "modes": [ { "mode": "pairs", "state": "available", "available": 26, "required": 20, "reason": null, "round_seconds": 45 }, … ],
  "friend_access": "available" | "no_access" }
```
Ошибки: 404 `friendship_not_found`, 403 `not_friends` (пара не `accepted`), 404 `course_not_found` (приватный/неопубликованный), 409 `battle_disabled`.

**`GET /practice/battles`** — `BattleListResource`:
```json
{ "active": { "...BattleResource(компактно)..." } | null,
  "incoming": [ { "id": 15, "mode": "pairs", "from": { "first_name": "Bek" }, "course": {...}, "lesson": {...}, "invite_expires_at": "…", "rematch": false } ],
  "finished": [ { "id": 9, "mode": "sentence", "opponent": { "first_name": "Bek" }, "outcome": "won", "scores": { "me": 300, "opponent": 200 }, "finished_at": "…" } ] }
```
`incoming` — `invited` ко мне с непросроченным `invite_expires_at`; `finished` — последние 10 `finished|closed` с моим участием.

**`POST /practice/battles`** (лимитер `twa-battle-invite` 5/мин) — `{ "friendship_id": 11, "mode": "sentence", "course": "english-a1", "lesson_id": 41, "rematch_of": null }` → **201** `BattleResource`. Ошибки: 409 `battle_disabled`, `battle_busy` (details `{battle_id}` моего активного), `battle_friend_no_access`, `practice_mode_disabled`; 403 `not_friends`; 404 `friendship_not_found`, `course_not_found`, `lesson_not_found`; 422 `lesson_not_in_course`, `battle_material_unavailable` (details §4.3). `rematch_of` — мой завершённый матч: формат/тема берутся из него (присланные игнорируются), друг — соперник того матча.

**Реализация 18.6:** реванш работает **без** `friendship_id` (решение координатора): друг — другая сторона завершённого матча, пара должна по-прежнему быть `accepted` (иначе 403 `not_friends`); `rematch_of` чужого матча — 404 `battle_not_found`, незавершённого — 409 `battle_wrong_state`. `BattleListView.active` — матч, которым я занят (`battle_participants.active_learner_id = me`: своё непросроченное приглашение, комната, отсчёт, игра; просроченное закрывается лениво и в `active` не попадает); `incoming` — `invited` ко мне с `invite_expires_at > now`; `finished` — последние `finished_list` по `finished_at` (лениво закрытое приглашение туда тоже попадает, `outcome: none`). `options.modes[].state`: `disabled` (выключен) / `available` / `not_enough` — доступность считается по локалям **обоих** игроков.

**Реализация 18.6b:** `BattleInviteRequest` — без `rematch_of` обязательны `friendship_id`, `mode`, `course`; **с** `rematch_of` остальные поля не валидируются и не читаются вовсе (TWA шлёт `mode`, `course`, `lesson_id` и `friendship_id` заодно — это не 422, даже если там мусор). `POST /battles` стоит за `twa-practice-open` **и** `twa-battle-invite` (§7.5). Встречное приглашение — 409 `battle_busy` с `details.battle_id` открытого приглашения друга **мне** (§8.2). Компактный `active` списка — ровно `id, status, mode, role, course, lesson, opponent`. Ошибки валидации Laravel (422 `{message, errors}`) вне конверта `ApiErrorResource`, как у соло.
`options` бросает ещё 404 `lesson_not_found` и 422 `lesson_not_in_course`; состояния формата — только `available` / `not_enough` / `disabled`, без `pro_required` (Free при `PRACTICE_PLAN=pro` получает 403 на весь запрос), и у `disabled` числа и `reason` настоящие (материал считается и для выключенного формата, в отличие от хаба, §7.1). Порядок проверок приглашения: доступ → `battle_disabled` → реванш (404/409) → `practice_mode_disabled` → пара → `battle_friend_no_access` → встречное `battle_busy` → курс → урок → материал → запись (`docs/04` A.17).

### 7.4 Баттл — комната и матч

**`GET /practice/battles/{id}`** — `BattleResource`:
```json
{ "id": 15, "status": "playing", "mode": "sentence", "role": "challenger",
  "course": { "slug", "title" }, "lesson": {...} | null, "opponent": { "first_name": "Dilnoza" },
  "rounds_total": 5, "round_seconds": 45, "countdown_seconds": 3, "reveal_seconds": 4,
  "invite_expires_at": "…", "room_expires_at": "…" | null, "starts_at": "…" | null,
  "version": 23, "server_now": "…",
  "ready": { "me": true, "opponent": true }, "opponent_present": true,
  "round": { "no": 2, "phase": "answering", "deadline_at": "…", "phase_ends_at": null, "my_answered": false, "opponent_answered": true } | null,
  "scores": { "me": 100, "opponent": 125 },
  "result": null | { "outcome": "won" | "lost" | "draw" | "none", "end_reason": "completed",
                     "rounds": [ { "no": 1, "me": { "verdict": "correct", "points": 100 }, "opponent": { "verdict": "wrong", "points": 0 } }, … ] },
  "rematch": { "battle_id": 16, "status": "invited" } | null }
```
`outcome: none` — у `closed` (без результата). `rematch` — ближайший матч с `rematch_of_id = id` (чтобы вторая сторона увидела предложение реванша).

**`GET /practice/battles/{id}/poll`** (лимитер `twa-battle-poll` 90/мин, **вне** `twa-api`) — `BattlePollResource`, ≤ 600 байт:
```json
{ "id": 15, "status": "playing", "version": 23, "server_now": "2026-10-30T10:00:05.321Z",
  "ready": { "me": true, "opponent": false }, "opponent_present": true,
  "round": { "no": 2, "phase": "answering", "deadline_at": "…", "phase_ends_at": null, "my_answered": false, "opponent_answered": true } | null,
  "scores": { "me": 100, "opponent": 125 }, "end_reason": null }
```
Клиент перечитывает `GET /battles/{id}` при смене `status`, `GET /battles/{id}/round?no=` при смене `round.no`/`phase`. Опрос — единственный heartbeat.

**`GET /practice/battles/{id}/round?no=2`** — `BattleRoundResource`:
```json
{ "no": 2, "phase": "reveal", "deadline_at": "…", "phase_ends_at": "…", "server_now": "…",
  "element": { "...как current соло, audio_url = /api/twa/practice/battles/15/audio/eD4…" },
  "reveal": null | { "correct": { "...по режиму: correct_text | correct_option_ids | expected | pairs" },
                     "me": { "answer": {...}, "verdict": "correct", "points": 100 },
                     "opponent": { "answer": {...}, "verdict": "typo", "points": 0 },
                     "explanation": "…", "translation": "…" } }
```
`no` ≤ `current_round` (будущие раунды → 409 `round_not_open`); `reveal` только у разрешённого раунда (`phase = reveal` или раунд < текущего).

**Реализация 18.6 (поля read-моделей, которые ждёт TWA 18.6-T; 18.6b выводит их в Resources):** `BattleView` дополнительно несёт `end_reason`, `opponent_absent_since` (последнее присутствие соперника, когда он считается отсутствующим, иначе `null`), `presence_grace_seconds`, `deadline_grace_ms`, `friendship_id`, `rematch = {battle_id, status, by_me}` (кто предложил), `audio_preload[]` (диктант, только `room`/`countdown`: URL пяти клипов — те же, что `audio_url` элементов раундов; иначе `null`). `round` в `countdown` = `{no: 1, phase: null, deadline_at: starts_at + round_seconds, phase_ends_at: null, my_answered: false, opponent_answered: false}`; у `invited`/`room`/терминальных — `null`. `result.rounds` — только разрешённые раунды (`meta.rounds_played`); у `closed` — `outcome: none`. `BattlePollView` (`toArray()`, ≤ 600 байт) = `{id, status, version, server_now, ready, opponent_present, opponent_absent_since, round, scores, end_reason, rematch}` — `rematch` только у терминального матча (один запрос), у живого `null` без запроса. `RoundView`: `phase` — фаза **этого** раунда (`reveal` у разрешённого, фаза матча у текущего, `null` в отсчёте), `deadline_at`/`phase_ends_at` только у текущего; `reveal.correct` — ключи `reveal()` режима без `translation`/`explanation`, `me`/`opponent` = `{answer: payload | null (таймаут), verdict, points}`. Ответ на раунд в `countdown` — 409 `round_not_open {round, phase: null}` (не `battle_wrong_state`): раунд уже есть, фаза ещё не открыта. URL аудио баттла — маршрут `twa.practice.battles.audio` (18.6b регистрирует его на `/api/twa/practice/battles/{battle}/audio/{element}`; пока маршрута нет, `BattleReader` строит этот путь строкой).

**Реализация 18.6b (проводная форма):** `BattleResource` отдаёт ключи в таком порядке: `id, status, end_reason, mode, role, course, lesson, opponent, rounds_total, round_seconds, countdown_seconds, reveal_seconds, presence_grace_seconds, presence_warn_seconds, deadline_grace_ms, invite_expires_at, room_expires_at, starts_at, version, server_now, ready, opponent_present, opponent_absent_since, round, scores, result, rematch, friendship_id, audio_preload` (все ключи есть всегда, отсутствующее — `null`); `result` есть у любого терминального матча, у `closed` (в том числе `both_absent`) — `{outcome: "none", end_reason, rounds}`. `audio_preload[i]` совпадает строка в строку с `round.element.audio_url` раунда `i + 1`, с префиксом `/api/twa` (TWA находит blob по точному совпадению URL; закреплено тестом). Счёт (`scores`) в опросе и в `BattleView` растёт только при разрешении раунда: пока раунд открыт, чужой вердикт по счёту не угадать. `surrender` принимает тело `{confirm: true}` (без него 422). `reveal.correct` по режимам: пары `{pairs}`, сочетание `{correct_option_ids, full_text}`, предложение `{correct_text, alternatives}`, диктант `{expected}`; `me`/`opponent.answer` — принятый payload в форме ответа клиента (`{pairs}` / `{option_id}` / `{order}` / `{text}` / `{skip: true}`), `null` у `timeout`. `GET …/round?no=` раунда, который уже разрешён, всегда отдаёт `reveal` (`phase: "reveal"`, без часов). Аудио баттла: участник (через `BattleReader::find`), элемент с аудио, раунд элемента ≤ `current_round` либо статус `room` / `countdown` — иначе 404 `battle_not_found` (одинаково для чужого матча, неизвестного элемента и клипа будущего раунда); файла нет — 503 `practice_audio_unavailable` и **ничего больше** (событие и аннулирование — у `audio-failed`, один отчёт, а не два).

**`POST /practice/battles/{id}/accept|decline|cancel|ready|surrender`** (лимитер `twa-battle-action` 60/мин) — без тела (у `surrender` — `{ "confirm": true }`); ответ 200 `BattleResource`. Ошибки: 404 `battle_not_found`; 403 `battle_not_recipient` (accept/decline не адресатом), `battle_not_challenger` (cancel приглашения не инициатором); 409 `battle_busy`, `battle_closed` (терминальный / просрочен — details `{end_reason}`), `battle_wrong_state` (details `{status}`), `battle_friend_no_access`, `battle_disabled` (accept при выключенном баттле); 403 `practice_unavailable`.

**`POST /practice/battles/{id}/answer`** (`twa-battle-action`) — `{ "request_id", "round": 2, "answer": {...по режиму; пары: {"pairs": {"lM…": "rT…", …}}} }` → 200:
```json
{ "verdict": "correct", "points": 100, "accepted_at": "…", "round_resolved": false, "poll": { "...BattlePollResource..." } }
```
Разбор здесь **не** возвращается (только после фиксации обоих/дедлайна — через `round`). Ошибки: 409 `round_not_open` (не текущий раунд / отсчёт не закончен), `round_closed` (дедлайн + грейс прошёл или раунд уже разрешён), `answer_already_accepted` (details `{verdict, points}`), `battle_closed`; 422 `invalid_answer_shape`, `unknown_element_id`.

**`POST /practice/battles/{id}/audio-failed`** (`twa-battle-action`) — `{ "request_id", "element_id" }` → 200 `{ "verified": true | false, "battle": {...BattleResource...} }`.

**`GET /practice/battles/{id}/audio/{element}`** — как у сессии (`twa-practice-audio`); доступ участникам; раунд ≤ `current_round` (клипы будущих раундов не отдаются, кроме комнаты диктанта: там все 5 для предзагрузки — иначе «готовность после загрузки аудио» невозможна; текст от этого не раскрывается).

**Реализация 18.6b (коды действий; полный перечень с JSON — `docs/04` A.17).** `accept`: 403 `battle_not_recipient`; 409 `battle_closed {end_reason}` (просроченное приглашение — `invite_expired`; дружбы больше нет — `canceled`), `battle_busy {battle_id}` (id матча, который держит принимающего), `battle_disabled`; для матча, который уже идёт, — 200 без изменений.
**`battle_friend_no_access` из `accept` не бросается**: доступ друга проверяет приглашение (§3.2), принимающий без доступа получает 403 `practice_unavailable` / `practice_pro_required`, как в любом маршруте. `decline`: 403 `battle_not_recipient`; 409 `battle_wrong_state {status}` (не `invited`), `battle_closed`. `cancel`: 403 `battle_not_challenger` (приглашение отзывает инициатор; комнату и отсчёт покидает любой),
409 `battle_wrong_state` в `playing` (там выходят сдачей). `ready`: 409 `battle_wrong_state` в `invited`; в `countdown` / `playing` — 200 без изменений. `surrender`: 422 без `confirm`; 409 `battle_wrong_state` в `invited` / `room`. `answer`: порядок проверок — §8.5; в `invited` / `room` 409 `battle_wrong_state`. `audio-failed`: 409 `battle_wrong_state` в `invited`; 422 `unknown_element_id`.
Любое действие над терминальным матчем — 409 `battle_closed`; чужой и несуществующий id — всегда один и тот же 404 `battle_not_found`; ошибки валидации Laravel (422 `{message, errors}`) и 429 лимитера (стандартный ответ с `Retry-After`) — вне конверта `ApiErrorResource`.
`rematch` терминального матча — **самый поздний** (по `id`) матч с его `rematch_of_id`, в любом статусе (`invited`, `room`, … `closed`); `by_me` — пригласил ли его смотрящий. Реванш разрешён для любого терминального матча участника, в том числе `closed` (отказ, истёкшее приглашение, отмена); сервер реванш после отказа разрешает, а TWA его не предлагает (после `declined` на экране остаётся лишь «К друзьям»).

### 7.5 Лимитеры (`AppServiceProvider`, ключ — `learner_id`)

| Имя | Лимит | Маршруты | Почему отдельно |
|---|---|---|---|
| `twa-practice-open` | 10/мин | `POST /practice/sessions`, `POST /practice/battles` (вместе с `twa-battle-invite`) | создание = снимок + запросы материала |
| `twa-practice-answer` | 90/мин | `…/sessions/{id}/answer`, `…/replace` | пары: до ~40 запросов/мин у быстрого ученика; `twa-api` (60) тоже действует |
| `twa-practice-audio` | 60/мин | оба `…/audio/{element}` | 5 клипов за раз при входе в комнату; вне `twa-api`, чтобы предзагрузка не съедала основной бюджет |
| `twa-battle-invite` | 5/мин | `POST /practice/battles` | каждое — снимок, строка, ЛС другу |
| `twa-battle-action` | 60/мин | accept/decline/cancel/ready/surrender/answer/audio-failed | |
| `twa-battle-poll` | 90/мин | `GET …/poll` | опрос раз в 2 с = 30/мин, две вкладки — 60; **вне** `twa-api`, иначе опрос съел бы половину общего бюджета (60) и душил бы `round`/`answer`. Не «без лимита»: буг цикла или скрипт упёрлись бы в этот бакет, не в БД |

Маршруты опроса и аудио объявляются отдельной группой `Route::middleware([TwaAuth::class, SetTwaLocale::class, 'throttle:twa-battle-poll'])` / `'throttle:twa-practice-audio'` без `twa-api`. Остальные — внутри существующей группы `twa-api` с дополнительным `throttle:…` на маршруте (как `twa-friends`).

**Реализация 18.6b.** Состав лимитеров на маршрутах закреплён `BattleRateLimitTest`: `options`, список, `show`, `round` — только `twa-api`; `POST /battles` — `twa-api` + `twa-practice-open` + `twa-battle-invite` (отказанные приглашения тоже считаются: каждое — снимок, строки и ЛС); семь действий — `twa-api` + `twa-battle-action` (один бакет на все); опрос — только `twa-battle-poll`; аудио — только `twa-practice-audio`.
Проверено: 30 опросов и 60 других запросов за минуту проходят, 61-й другой — 429, опрос продолжает идти; 91-й опрос — 429, остальной API жив. Ключ всех — `learner_id`; id матча в маршруте — `[0-9]{1,18}` (длиннее — 404 роутера до контроллера).

### 7.6 Коды ошибок (сводно; TWA: `KNOWN_ERROR_CODES` + `error.code.*` в ru/uz)

| Код | HTTP | details |
|---|---|---|
| `practice_unavailable` | 403 | — |
| `practice_pro_required` | 403 | — (paywall reason `practice`) |
| `practice_mode_disabled` | 409 | `{mode}` |
| `practice_not_enough_material` | 422 | `{mode, available, required, reason, course, lesson_id}` |
| `practice_session_active` | 409 | `{session_id, mode, course, lesson_id, progress}` |
| `practice_session_not_found` | 404 | — |
| `practice_session_closed` | 410 | `{finish_reason}` |
| `element_not_current` | 409 | `{current}` |
| `answer_already_accepted` | 409 | `{verdict[, points]}` |
| `pair_already_matched` | 409 | `{left_id}` |
| `invalid_answer_shape` | 422 | `{expected}` — словесно: `order_of_all_fragments`, `option_id`, `left_right`, `text_or_skip`, `bijection` |
| `unknown_element_id` | 422 | — |
| `replace_not_allowed` | 409 | `{reason: not_dictation|not_current|limit}` |
| `request_id_reused` | 409 | — (`request_id` уже принят как запрос другого вида: ответ ≠ замена, §6.3) |
| `retry_not_available` | 409 | — |
| `practice_audio_unavailable` | 503 | — |
| `lesson_not_in_course` | 422 | — |
| `battle_disabled` | 409 | — |
| `battle_busy` | 409 | `{battle_id}` |
| `battle_friend_no_access` | 409 | — |
| `battle_material_unavailable` | 422 | как `practice_not_enough_material` |
| `battle_not_found` | 404 | — |
| `battle_not_recipient` / `battle_not_challenger` | 403 | — |
| `battle_closed` | 409 | `{end_reason}` |
| `battle_wrong_state` | 409 | `{status}` |
| `round_not_open` / `round_closed` | 409 | `{round, phase}` |
| (переиспользуются) `course_not_found` 404, `lesson_not_found` 404, `friendship_not_found` 404, `not_friends` 403 | | |

`ApiErrorResource::respond(int $status, string $code, string $message, array $details = [])` — ключ `details` добавляется **внутрь** объекта `error` (`{"error": {"code", "message", "details"}}`) только при непустом массиве; существующие ответы не меняются. `ApiClientError` получает `details: Record<string, unknown> | null`.

---

## 8. Баттл 1 на 1

### 8.1 Машина состояний (`status` × событие → новое состояние)

| Состояние | Событие | Кто/что | Новое состояние | Побочный эффект |
|---|---|---|---|---|
| — | `invite` | инициатор (`POST /battles`) | `invited` | снимок, участники (инициатор `active_learner_id`), `invite_expires_at = now+300 с`, событие, ЛС другу |
| `invited` | `accept` | адресат | `room` | адресат `active_learner_id`, `accepted_at`, `room_expires_at = now+120 с`, `last_seen_at` обоих = now, `version++` |
| `invited` | `decline` | адресат | `closed/declined` | `active_learner_id = NULL` обоим |
| `invited` | `cancel` | инициатор | `closed/canceled` | то же |
| `invited` | `invite_expires_at <= now` | дворник / ленивый `accept`/`view` | `closed/invite_expired` | то же |
| `room` | `ready` | участник | `room` (один готов) / `countdown` (оба) | `ready_at`; оба → `starts_at = now+3 с`, `current_round = 1`, `round_deadline_at = starts_at + round_seconds`, `phase` остаётся `NULL` (CHECK `battles_phase_shape_check`: фаза есть только в `playing`), `version++`; событие `battle.started` |
| `room` | `cancel` | любой участник | `closed/canceled` | — |
| `room` | `room_expires_at <= now` | дворник / опрос | `closed/room_expired` | — |
| `room`, `countdown` | участник отсутствует > 30 с | `advance` | `closed/canceled` (`meta.left = role`) | — |
| `countdown` | `now >= starts_at` | первый `advance` после `starts_at` (статус хранится явно, не вычисляется) | `playing/answering` | `phase = answering` (дедлайн раунда 1 уже стоит — `starts_at + round_seconds`, от момента `advance` не зависит), `version++` |
| `countdown`, `playing` | `surrender` | участник | `finished/surrender` | победитель — соперник; очки как есть |
| `playing/answering` | `answer` второго игрока | участник | `playing/reveal` | `phase_ends_at = now + reveal_seconds`, `version++` |
| `playing/answering` | `now >= round_deadline_at + grace` | `advance` | `playing/reveal` | отсутствующим ответам — строка `timeout` 0 очков; `version++` |
| `playing/reveal` | `now >= phase_ends_at`, `current_round < 5` | `advance` | `playing/answering`, `current_round+1` | `round_deadline_at = now + round_seconds`, `version++` |
| `playing/reveal` | `now >= phase_ends_at`, `current_round = 5` | `advance` | `finished/completed` | `score` участников, `winner_learner_id` (ничья → NULL), `active_learner_id = NULL`, `finished_at`; событие `battle.finished` |
| `playing` | один отсутствует > 30 с, другой присутствует | `advance` | `finished/absent` | победитель — присутствующий |
| `playing` | оба отсутствуют > 30 с | `advance` | `closed/both_absent` | без победителя |
| `room`…`playing` | системная недоступность аудио (`audioFailed` подтверждён) / `access=off` | сервис / дворник | `closed/voided` | `meta.voided_reason` |
| `finished`, `closed` | любое действие | — | без изменений | `accept`/`ready`/`answer` → 409 `battle_closed`; `GET` — 200 |

Терминальные статусы: `finished` (есть результат: `completed`, `surrender`, `absent`) и `closed` (`declined`, `invite_expired`, `room_expired`, `canceled`, `both_absent`, `voided`). Любой переход в терминальный статус в той же транзакции ставит `active_learner_id = NULL` обоим участникам и `finished_at`.

**Реализация 18.6:** любой терминальный переход также обнуляет `phase` (CHECK `battles_phase_shape_check`) и пишет `meta.rounds_played`. `ready` повторно / в `countdown` и `playing` — 200 без изменений (оба уже готовы), в `invited` — 409 `battle_wrong_state`; `decline`/`cancel` терминального — 409 `battle_closed` (двойной тап клиент гасит перечитыванием). Отказ, возникший **после** ленивого перехода в той же транзакции (просроченное приглашение закрыто → 409 `battle_closed {invite_expired}`; 422 формы ответа после открытия раунда), возвращается из транзакции значением и бросается после commit — переход сохраняется. Присутствие: игрок, который действует (`ready`/`answer`/`surrender`/`audio-failed`), получает heartbeat **до** `advance`, чтобы собственное действие не признало его отсутствующим. В `room` `last_seen_at` обоих — `accepted_at` (ставится при принятии): инициатор, ушедший до принятия, через 30 с комнаты даёт `closed/canceled (meta.left = challenger)`.

### 8.2 Приглашение и принятие (атомарность)

Приглашение (`BattleService::invite`): вне транзакции — `assertCanPractice(by)`, `assertBattleEnabled`, `assertModeEnabled`, `FriendshipService::find(by, friendshipId)` → другая сторона `friend` (нет learner'а → 404), `areFriends` (иначе 403 `not_friends`), `friend.blocked_at = NULL` (иначе 404 `friendship_not_found`), `assertFriendCanPractice(friend)`, `courseForBattle(slug)` (published, не private), урок, снимок (`strictSize = true`; `LocaleSet` = локали обоих). Транзакция:

```
BEGIN
  INSERT battles (status invited, snapshot, invite_expires_at, …)
  INSERT battle_participants (challenger, active_learner_id = by.id)   -- unique violation → ROLLBACK → 409 battle_busy {battle_id: мой активный}
  INSERT battle_participants (opponent, active_learner_id = NULL)
COMMIT
afterCommit: battle.invited, SendBattleDmJob::send(BattleDmKind::Invited, battle.id)
```
Инициатор не может иметь второго активного (свой `active_learner_id` занят) — проверяет БД. Реванш: `rematch_of` — мой `finished|closed` матч с тем же другом; те же `mode/course/lesson`, новый seed (новая выборка/раскладка; при ровно 5 заданиях — те же задания в другом порядке).

Принятие:

```
BEGIN
  SELECT * FROM battles WHERE id = :id FOR UPDATE                      -- участник ли я: иначе 404 (проверка по строке после чтения)
  status ∈ {room, countdown, playing} и я участник → COMMIT, 200 (идемпотентно)
  status терминальный → 409 battle_closed {end_reason}
  invite_expires_at <= now → UPDATE closed/invite_expired, active NULL → COMMIT → 409 battle_closed {invite_expired}
  opponent_id ≠ me → 403 battle_not_recipient
  дружба уже не accepted (чтение без блокировки) → UPDATE closed/canceled → 409 battle_closed {canceled}
  assertCanPractice(me), assertBattleEnabled (выключили после приглашения → 409 battle_disabled, матч остаётся invited до истечения)
  UPDATE battle_participants SET active_learner_id = me, last_seen_at = now WHERE battle_id = :id AND learner_id = me
                                                                       -- unique violation → ROLLBACK → 409 battle_busy
  UPDATE battle_participants SET last_seen_at = now WHERE battle_id = :id AND learner_id = challenger
  UPDATE battles SET status = room, accepted_at, room_expires_at, state_version = state_version + 1
COMMIT
afterCommit: battle.accepted
```
**Реализация 18.6b (встречные приглашения):** перед транзакцией `invite()` ищет **открытое** приглашение друга ко мне (`challenger = друг`, `opponent = я`, `invited`, `invite_expires_at > now`; индекс `(opponent_id, status)`) и при находке отвечает 409 `battle_busy` с `details.battle_id` **этого** приглашения: оба нажали «Реванш» (или «Пригласить») в пределах секунд — второй получает приглашение первого, открывает его и принимает; иначе каждый сидел бы на своём «Ждём ответа», а принять чужое мешал бы `active_learner_id` (409). Это матч, где спрашивающий — адресат, поэтому `battle_busy` по-прежнему называет только матчи самого спрашивающего (`active_learner_id = learner_id` держит CHECK на PostgreSQL). ~~Проверка без блокировок (§9 правило 8): два приглашения, пересёкшиеся в ту же миллисекунду, обе пройдут~~ — ревью измерило «слепое окно» проверки (от её SELECT до COMMIT) в 6–15 мс на локальном PG, в проде — десятки; закрыто в 18.6-fix (ниже). `invite()` возвращает `Battle` с уже подвешенными двумя `participants` (полные строки, умолчания колонок записаны) — ответ 201 показывает матч без повторного чтения (бюджет §13).

**Реализация 18.6-fix (атомарность встречных приглашений — вариант (г), `open_pair`):** решает **БД**, а не проверка: `invite()` пишет `battles.open_pair = "<low>:<high>"` (§2.7, unique), поэтому из двух приглашений одной пары, идущих одновременно с любым разрывом, второй INSERT получает нарушение unique — вторая транзакция ждёт commit первой на индексе и откатывается, ровно как с `active_learner_id` (правило §9.9 расширено на `open_pair`: каждая держит только свои новые строки, цикла нет). В `catch UniqueConstraintViolation` сервис различает причины без разбора текста ошибки: (1) `busyWith(me)` — мой активный матч → 409 `battle_busy {battle_id: мой}`; (2) иначе `openBattleOfPair(me, friend)` — незавершённый матч пары (приглашение друга мне, закоммиченное первым) → 409 `battle_busy {battle_id: его}` — тот же ответ, что даёт прежняя проверка `assertNotCrossing`, которая осталась **вплотную перед транзакцией** (вариант (а)) как дружелюбный путь без исключения; в обоих случаях у проигравшего нет ни строки, ни события, ни ЛС. Отвергнуто: (б) `pg_advisory_xact_lock` по паре — замок вне строки `battles` (против духа правил 8/10) и только PostgreSQL; (в) пост-commit правило «закрыть приглашение с большим id» — у проигравшего уже созданы строка, событие `battle.invited` и job ЛС, а окно «commit → повторная проверка» лишь сужается, не исчезает. Гонка закреплена в `BattleConcurrencyTest` (два реванша с разрывом 0/5/10 мс: ровно одно открытое приглашение, второй — 409 с id первого).

**Реализация 18.6-fix (Ж1, своё истёкшее приглашение):** игрок, которого держит «занятым» только его просроченное, ещё не выметенное приглашение (до 60 с до прихода дворника), больше не получает 409: в `catch` `invite()` и `accept()` найденный активный матч, если он `isDue`, продвигается **отдельной короткой транзакцией** (`FOR UPDATE` + `advance` → `closed/invite_expired`, событие), и действие повторяется **один раз**; то же для просроченного приглашения пары (`openBattleOfPair`). Обычный путь без исключения не дорожает (бюджет invite ≤ 14 прежний); исключительный — +2–4 запроса.

**Реализация 18.6-fix (М2, М3 — `accept`):** принятие — «создание» для обоих выключателей (§3.2): после `assertBattleEnabled` проверяется и `mode->isEnabled()` → 409 `practice_mode_disabled {mode}`, приглашение остаётся `invited` до истечения; инициатор должен всё ещё иметь практику (`PracticeAccess::state(challenger)->isAvailable()`, не `blocked_at`) → иначе 409 `battle_friend_no_access` сразу, без комнаты, которая закрылась бы через 30 с (строка `learners` инициатора читается без блокировки; та же модель затем даёт actor события).

Параллельные принятия **двух разных** приглашений одним учеником: оба меняют его `active_learner_id` → второй ждёт commit первого на unique-индексе и получает нарушение → 409 `battle_busy`; каждый держит только свою строку `battles`, цикла нет. Параллельные `accept` одного приглашения (двойной тап) — сериализуются `FOR UPDATE`, второй идемпотентен. `accept ∥ cancel` — сериализуются, проигравший видит терминальный статус.

### 8.3 `BattleClock::advance` (под `FOR UPDATE`, идемпотентна)

```
advance(b, now):
  loop:
    switch b.status:
      invited:   if invite_expires_at <= now → close(invite_expired); return
                 return
      room:      if room_expires_at <= now → close(room_expired); return
                 if absent(any) → close(canceled, meta.left = role); return
                 return
      countdown: if absent(any) → close(canceled); return
                 if now >= starts_at → status = playing; phase = answering; version++; continue   -- round_deadline_at задан в ready()
                 return
      playing:
        if absent(challenger) && absent(opponent) → close(both_absent); return
        if absent(x) && !absent(y) → finish(absent, winner = y); return
        if phase = answering:
            if bothAnswered(current_round) → resolveRound(); continue        -- обычно уже сделано в answer()
            if now >= round_deadline_at + grace → writeTimeouts(); resolveRound(); continue
            return
        if phase = reveal:
            if now < phase_ends_at → return
            if current_round < rounds → current_round++, phase = answering, round_deadline_at = now + round_seconds, version++; continue
            finish(completed); return
      finished, closed: return
```
`absent(p)` ⇔ `p.last_seen_at < now − presence_grace_seconds` (у игрока без `last_seen_at` — считается от `accepted_at`). `resolveRound()` — `phase = reveal`, `phase_ends_at = now + reveal_seconds`, `version++`. `finish(reason, winner?)` — `score` обоих = `SUM(points)` из `battle_answers` (один запрос), `winner_learner_id` по счёту (`completed`) или явно (`surrender`/`absent`), статус, `active_learner_id = NULL`, `finished_at`, `version++`; событие после commit. `close(reason)` — аналогично без победителя. **Серверное время** — только `now` транзакции; клиентские таймеры — отображение (`server_now` в ответах, §11.5).

Переходы случаются «кто первый»: ответ второго игрока, опрос любого игрока с назревшим переходом (`isDue` без блокировки → только тогда транзакция `FOR UPDATE` + `advance`), дворник. Все три пути ведут в одну функцию под одной блокировкой строки, поэтому раунд разрешается и матч финализируется ровно один раз; события — только в транзакции, которая реально сменила статус (`changed`).

**Реализация 18.6:** `advance` в фазе `answering` до `deadline + grace` **не читает** `battle_answers` (ветка «оба ответили» обслуживается самим `answer()`, который разрешает раунд в своей транзакции); после дедлайна — одно чтение ответов раунда, `timeout`-строки недостающим, `resolveRound`. `finish(completed)` берёт победителя по `battle_participants.score` (инкремент при разрешении, см. §2.7), SUM не делает. `isDue` читает только строку и уже загруженных участников (`Battle::with('participants')`) — ноль запросов (закреплено тестом). Под блокировкой участники перечитываются (`load('participants')`) — свежие `last_seen_at` для решения об отсутствии. `duration_ms` события `battle.finished` = `finished_at − starts_at` (без старта — от `accepted_at`). Actor событий `battle.*` — инициатор (`$battle->challenger`, читается после commit).

### 8.4 Присутствие

- Heartbeat = `GET …/poll` (и любой другой запрос участника к матчу). `BattleReader::poll`: если `last_seen_at < now − heartbeat_write_seconds (5)` → `UPDATE battle_participants SET last_seen_at = now WHERE id = ? AND (last_seen_at IS NULL OR last_seen_at < ?)` — одиночное выражение вне транзакции, ≤ 1 запись на 5 с на игрока; иначе записи нет.
- Опрос раз в 2 с, в фоне остановлен (`visibilitychange`), при возврате — немедленный опрос. Допуск `presence_grace_seconds = 30` ≫ интервал: короткий обрыв и фон до 30 с безвредны; «вернулся после дедлайна» получает `round_closed` на старый раунд — ответ заново не открывается.
- Отсутствие проверяет `advance` (опрос соперника / дворник), не сам отсутствующий.

**Реализация 18.6b:** предупреждение раньше конца. `practice.battle.presence_warn_seconds` (10, не больше `presence_grace_seconds`): `opponent_present` в `BattleView` / `BattlePollView` становится `false`, когда последнее присутствие соперника (`last_seen_at`, до первого опроса — `accepted_at`) старше этого порога, `opponent_absent_since` = это присутствие, пока соперник «молчит», иначе `null`; `BattleView.presence_warn_seconds` отдаёт порог, а «осталось» экран считает как `opponent_absent_since + presence_grace_seconds − server_now`. Конец матча решает по-прежнему только `BattleClock::isAbsent` по `presence_grace_seconds` (30 с); у `invited` и терминальных статусов предупреждения нет (`true` / `null`). Читают оба: `BattleClock::isSilent` / `silentSince` (вместо прежнего `absentSince`). Запись присутствия — раз в 6 с у здорового клиента (опрос каждые 2 с, запись при `last_seen_at <= now − 5`), поэтому порог 10 с оставляет запас ≈ 2 с на задержку опроса.

### 8.5 Ответ раунда (транзакция)

```
BEGIN
  SELECT battles FOR UPDATE (участник → иначе 404)
  advance(b, now)
  status ≠ playing → 409 battle_closed | battle_wrong_state
  round ≠ current_round → 409 round_not_open (round > current) | round_closed (round < current)
  phase ≠ answering или now >= round_deadline_at + grace → 409 round_closed
  SELECT battle_answers WHERE battle_id, learner_id = me, request_id → есть → COMMIT, 200 тот же {verdict, points}
  есть ответ (battle_id, round, me) → 409 answer_already_accepted {verdict, points}
  handler->grade(element(round), answer) → 422 при неверной форме
  INSERT battle_answers (…, accepted_at = now)    -- unique (battle, round, me) violation → ROLLBACK → перечитать → 409 / 200 по request_id
  if answered(opponent, round) → resolveRound()
  version++
COMMIT
```
Грейс `deadline_grace_ms = 1500`: ответ, ушедший при 0:00 на клиенте и дошедший на 1,2 с позже, принимается; дворник/опрос разрешают раунд по таймауту тоже только после `deadline + grace` (одно правило в `advance`). Очки и ответ соперника до разрешения раунда недоступны (poll отдаёт лишь `opponent_answered`).

**Реализация 18.6:** порядок внутри транзакции уточнён — после `advance` **сначала** одно чтение `battle_answers` (ответы раунда обоих + моя строка с этим `request_id`, одним запросом: `round = ? OR (learner_id = me AND request_id = ?)`), и повтор `request_id` отвечает прежними `verdict`/`points` **даже если раунд уже закрыт или матч окончен** (потерянный ответ не должен превращаться в 409 при повторе — как у соло); затем проверки статуса/раунда/фазы, затем `answer_already_accepted {verdict, points}`, затем `grade()`. Нарушение unique при INSERT (под блокировкой невозможно, сетка оставлена) → транзакция откатывается и `answer()` повторяется один раз, отвечая по найденной строке. `points` обрезаются в `0..practice.battle.points.round`. `round_resolved` ответа — `true`, когда ответ был вторым.

### 8.6 Дворник и расписание

`routes/console.php`:
```php
Schedule::command('practice:battles-sweep')->everyMinute()->withoutOverlapping()->runInBackground();
Schedule::command('practice:close-expired')->everyFifteenMinutes()->withoutOverlapping();
```
`BattleJanitor::sweep`: (1) `UPDATE battles … WHERE status='invited' AND invite_expires_at <= now` → `closed/invite_expired` + `UPDATE battle_participants SET active_learner_id = NULL WHERE battle_id IN (…)` (две операции в одной транзакции, без блокировок строк заранее: UPDATE сам берёт их); (2) `Battle::whereIn('status', ['room','countdown','playing'])->lazyById()` → каждому — своя транзакция `FOR UPDATE` + `advance`; (3) при `access = off` — всем live `close(voided, practice_off)`. Live-баттлов в пилоте десятки, поэтому полный проход дешевле индекса «следующая проверка».

**Реализация 18.6:** шаг (1) выполнен не одним bulk UPDATE, а как у `practice:close-expired`: SELECT просроченных id → условный «клейм» каждой строки (`WHERE status = 'invited'`, `state_version + 1`) → один UPDATE участников закрытых → по одному `battle.closed` на закрытую строку после commit (приглашение, принятое за мгновение до прогона, не трогается и события не даёт — bulk UPDATE не дал бы точных событий). Возвращает число изменённых матчей (просроченные + сдвинутые `advance` + аннулированные). При `access = off` аннулируются только live-матчи, приглашения истекают сами по времени.

**Реализация 18.6-fix (Ж3, М6):** сбой одного матча изолирован — транзакция каждого live-матча и шаг просроченных приглашений обёрнуты в `try/catch Throwable` → `report()` + `Log::warning('practice.battles_sweep_failed')`, проход продолжается со следующего матча (правило §9.14; закреплено `PracticeSchedulerTest`: испорченная строка между двумя живыми матчами не мешает им). Live-матчи читаются вместе с участниками (`with('participants')`), и матч, у которого по `isDue` ничего не назрело, **не блокируется вовсе** (как в опросе) — дворник раз в минуту больше не берёт `FOR UPDATE` каждого идущего матча; бюджет sweep: `5 + 2 × просроченных + 7 × назревших live`.

### 8.7 Реванш, ЛС, глубокие ссылки

- **Реванш** = новое приглашение (`POST /battles {rematch_of}`), требует принятия вторым; на экране итогов обеих сторон `rematch` показывает входящее предложение.
- **ЛС** — `SendBattleDmJob` (копия `SendFriendDmJob`: `ThrottleTelegramOutbound`, `tries = 0`, `maxExceptions = 3`, `retryUntil` +1 ч — у приглашения срок 5 мин, позже слать бессмысленно) → `BattleMessenger::notify(BattleDmKind::Invited, battleId)`: перечитывает матч (`invited`, не просрочен — иначе молчит), адресат `reachable` (не заблокирован, `dm_blocked_at IS NULL`), маркер `notifications_log (type = battle_dm, payload.key = invited:<battle_id>)` **до** отправки (идемпотентность как у `FriendsMessenger::deliver`), 403 → маркер удалить, `dm_blocked_at`. Текст `lang/{ru,uz}/battle.php`: «{name} зовёт вас на баттл: {формат}, {курс · урок}. Приглашение действует 5 минут» + одна кнопка `web_app` «Открыть баттл» → `{twa.base_url}/twa/battles/{id}` (личный чат — `web_app` допустим; `MiniAppLinks::forPath` не нужен). Итоги в ЛС не шлём (оба в приложении).
- **Реализация 18.6b (`BattleMessenger`):** `sendWebAppButton` (не `immediate`: слот на чат берёт сам клиент, как у `LeagueResultSender`), маркер `notifications_log` `{type: battle_dm, payload.key: invited:<id>}` пишется до отправки; не-403 сбой оставляет маркер (сообщение могло уйти), 403 удаляет маркер и ставит `dm_blocked_at` через `DmLearnerResolver::markBlocked`. Текст: «{имя} зовёт вас на баттл: {формат}, {курс · Модуль N · Урок M}. Приглашение действует {N} минут» (`lang/{ru,uz}/battle.php`; срок — из `invite_ttl_seconds`, форма слова — `trans_choice`; без имени — «Ваш друг» / «Do'stingiz»; весь курс — без урока). Из-за `SendBattleDmJob` в синхронной очереди тестов приглашение сразу шлёт ЛС (бюджетный тест подменяет очередь).
  Кнопка не `immediate`, поэтому, если у чата исчерпан лимит бота в секунду, сообщение откладывается в `SendTelegramMessageJob`; 403 там только логируется (`telegram.deferred_message_forbidden`), `dm_blocked_at` не ставится, а доставка может прийти после срока приглашения (известный риск: приложение покажет такое приглашение закрытым; `docs/04` B.14).
- **Глубокая ссылка** — SPA-маршрут `/battles/:id` (nginx `try_files` уже отдаёт `index.html`). Для ссылок из групп (не в этом выпуске) годится `MiniAppLinks::forPath(".../twa/battles/15")` → `startapp` → `startParam.ts` уже разбирает путь.

---

## 9. Порядок блокировок (дополнение docs/13 §3)

```
practice_sessions (FOR UPDATE, своя строка)  → practice_attempts INSERT → product_events
battles (FOR UPDATE, одна строка)            → battle_participants (UPDATE строк этого матча) → battle_answers INSERT → product_events
practice_items / practice_item_versions / practice_item_words — пишет только админка/импорт: courses (lockForWriting, FOR NO KEY UPDATE) → practice_items → versions → words-связи → audit_logs
```

Правила (нумерация продолжает docs/13 §3):

8. Транзакции практики и баттла **никогда не блокируют** `learners`, `friendships`, `courses`, `words`, `word_repetitions`, `practice_items`: всё читается до транзакции или внутри неё без блокировок (снимок — единственный источник правды во время игры). Правило 4 («два learner'а в одной транзакции не блокируются никогда») сохранено: оба игрока живут в **одной** строке `battles` и двух строках `battle_participants` того же матча, строки `learners` не трогаются.
9. Единственная межигроковая зависимость — unique `battle_participants.active_learner_id` (и `practice_sessions.active_learner_id`). Ожидание на индексе возможно только между двумя транзакциями, пишущими один и тот же `learner_id`; каждая из них держит ровно одну строку `battles` (свою) и не ждёт ничего другого → цикл невозможен. Проигравшая получает `UniqueConstraintViolationException` после commit победителя и отвечает 409.
10. Ничто не ждёт под `battles`: внутри транзакции матча нет `SELECT … FOR UPDATE` других таблиц, внешних вызовов (Telegram — job после commit), чтений с блокировкой. Опрос в ожидании соперника блокировок не берёт вообще (`isDue` — чистая функция от прочитанной строки).
11. Соло-сессия и баттл одного ученика независимы (разные строки, разные индексы); `practice:close-expired` — одиночный `UPDATE` без предварительных блокировок; `practice:battles-sweep` держит по одной строке `battles` за транзакцию.
12. Импорт/редактор контента берёт строку курса первой (`CoursePublisher::lockForWriting`, как `VocabularyImporter`), затем свои таблицы: с импортом слов конфликтов нет (тот же первый замок), с сессиями — нет общих замков (сессии контент не блокируют).
13. Проверка — `tests/Feature/Practice/BattleConcurrencyTest` и `PracticeSessionConcurrencyTest` (pcntl, PG), сценарии §14.
14. (18.6-fix) Дворник изолирует сбой одного матча: транзакция каждого live-матча — в `try/catch Throwable` с `report()`, проход по списку продолжается; матч, у которого ничего не назрело, не блокируется. Правило 9 распространяется на unique `battles.open_pair`: ожидание на индексе — только между двумя транзакциями одной пары, каждая держит лишь свои новые строки.

**Реализация 18.6:** правила 8–11 выполнены буквально: внутри транзакции матча читаются без блокировки только `friendships` (при `accept`: пара ещё accepted?) и `battle_answers`; проверка файлов диктанта при `ready`/`audio-failed` — локальный диск (≤ 5 `stat`) под блокировкой строки, внешних вызовов нет. Каждый воркер `BattleConcurrencyTest` ставит `SET lock_timeout = '8000'`: deadlock (40P01) или бесконечное ожидание (55P03) видны как падение, а не как зависший тест. Проверка «зубастости»: без `lockForUpdate()` падают 5 из 7 сценариев (accept ∥ cancel, два ответа, answer ∥ дворник, финализация, surrender ∥ answer); два оставшихся (два приглашения одному адресату, три приглашения одного инициатора) держатся на unique `active_learner_id` — как и задумано правилом 9. В docs/13 §3 добавлена строка-ссылка на эти правила.

**Реализация 18.9b (соло):** правило 10 выполнено и для замены диктанта. Файлы запасных (`exists && size > 0`) читаются **до** транзакции — `PracticeSessionService::replace()` берёт пул одним чтением сессии без блокировки (`ReplaceableElements::playableSpares`), а под `FOR UPDATE` замена выбирает из уже проверенных кандидатов и диска не касается. Пул только убывает, поэтому всё, что лежит в залоченной строке, было и в прочитанном. Тест — шпион на диске, который при каждом обращении фиксирует `DB::transactionLevel()` (`DictationSessionTest`); цена — один запрос на замену (§13).

---

## 10. Аудио

### 10.1 Генерация и хранение

- Фразы: при создании версии `dictation` (редактор, импорт) → `PracticeAudioService::schedule(version)`: `audio_status = pending`, `GeneratePracticeAudioJob::dispatch(version_id)->afterCommit()` (очередь `low`, `tries 5`, backoff как `PregenerateWordAudioJob`). Job: `TtsFallbackService::synthesize(text, voice)` → атомарная запись `{audio_key}.mp3` на диск `practice_audio` (`.tmp` + `move`, как `WordAudioService::store`) → `audio_status = ready`, `audio_generated_at`; сбой → `failed` + `audio_error` (`Log::warning practice.audio_failed`). Повторная постановка — админское действие «Сгенерировать заново» (`force`): ставит опубликованную версию и текущую, если это другая (по разу). `tts_service_not_configured` (пустой `TTS_SERVICE_URL`) — постоянная ошибка: job падает сразу (`fail()`, без повторов и без исключения наружу — на sync-очереди оно сорвало бы `db:seed`), версия → `failed` с этим кодом. Демо-сидер импортирует с `scheduleAudio: false` (job не ставится) и озвучивает фразы сам (`ffmpeg` + `flite`). `audio_key = sha1(version_id|normalize(text)|voice|twa.tts.audio_version)` — имя не выводится из текста без знания `version_id`, а каталог `practice-audio/` nginx **не** раздаёт публично (в отличие от `audio/` словаря): файл доступен только через эндпоинт.
- Смена текста = новая версия = новый `audio_key`; старая версия и её файл остаются (идущие сессии/матчи ссылаются на `audio_path` в снимке). Чистка файлов `superseded`/`archived` версий — отдельная команда позже (вне выпуска).
- Слова: используется существующее `words.audio_path` (`audio/{sha1}.mp3`, `PregenerateWordAudioJob`); в снимке — путь; наружу путь не уходит (§10.2). Генерации в запросе ответа нет никогда.

### 10.2 Выдача

`PracticeAudioController` (сессия и баттл): владелец/участник (иначе 404), элемент в снимке с `audio_path` (иначе 404), файл читается с диска элемента снимка — `Storage::disk(element.audio_disk)->readStream(element.audio_path)` (`practice_audio` у фраз, `public` у слов) → `StreamedResponse` `audio/mpeg`, `Content-Length` из `size`, `Cache-Control: private, no-store`, `X-Content-Type-Options: nosniff`; файла нет → 503 `practice_audio_unavailable`. В баттле клипы раундов `> current_round` не отдаются, кроме статуса `room|countdown` (предзагрузка всех пяти для «готовности»). TWA (`usePracticeAudio`): при показе элемента `fetch(audio_url, {Authorization})` → `blob` → `URL.createObjectURL`; кнопка «Прослушать» играет blob (`<audio>` создаётся в обработчике нажатия — жест сохраняется на iOS); повтор — тот же blob без сети; `revokeObjectURL` при смене элемента. Web Speech для диктанта **запрещён** (нужен текст на клиенте); `useTts` в режиме диктанта не вызывается.

Отвергнуто: подписанный URL (`URL::temporarySignedRoute`) — лишний тип capability, который нужно прятать; `fetch` с Bearer решает ту же задачу, а blob всё равно обязателен (iOS). Публичная раздача `practice-audio/` nginx — имя файла не содержит текста, но перебор `version_id` при утечке соли дал бы доступ к библиотеке; эндпоинт дешевле.

### 10.3 Готовность в баттле

Диктант: `ready` допускается на клиенте только после загрузки всех 5 blob (`GET …/round?no=1..5` в комнате не нужен: список `audio_url` пяти раундов отдаёт `BattleResource.audio_preload[]` в статусе `room` — только URL, без текста). Сервер при `ready` проверяет `exists && size > 0` у 5 файлов снимка (5 обращений к диску); нет → `closed/voided (audio_missing)`. После старта: `audio-failed` от игрока → сервер проверяет файл: нет/пустой → `closed/voided (audio_missing)`; есть → `{verified: false}`, матч идёт (локальная проблема; игрок отвечает или получает 0 по таймауту — правило показано в комнате).

**Реализация 18.6:** `audio_preload` отдаётся в `room` **и** `countdown` (клиент мог перезагрузиться в отсчёте); `ready` с пропавшим клипом возвращает 200 с матчем `closed/voided` (клиент видит статус) и пишет `practice.audio_failed {battle_id, source, reason: file_missing, verified: true}`; `audio-failed` идемпотентен по `request_id` (`meta.audio_reports`), повтор не даёт второго события; `audio-failed` на неизвестный элемент или элемент без аудио — 422 `unknown_element_id`.

### 10.4 Ошибки аудио в соло

Blob не загрузился (503/сеть) → экран показывает «Аудио недоступно»: «Повторить загрузку» и «Заменить задание» (`POST …/replace {reason: audio_unavailable}`): элемент помечается `replaced`, на его место встаёт следующий кандидат из `snapshot.pool` того же источника (фраза/слово; при пустом пуле — другого источника; пул пуст → элемент удаляется, `total--`, минимум 1). Лимит `max_replacements = 2` на сессию → далее 409 `replace_not_allowed` (`details {reason: limit}`, §7.6) и кнопка «Сейчас без звука» → хаб. Штрафа нет: заменённый элемент не входит в `total`/`answered`. Сервер файл не перепроверяет (локальные сбои воспроизведения реальны), ограничение — лимит и событие `practice.audio_failed`. Кандидат из пула берётся только с файлом на диске (18.9b: проверка идёт до блокировки сессии, §9). Событие `practice.audio_failed` с причиной `file_missing` (503 при стриминге) пишется не чаще раза в 10 минут на пару «сессия — элемент» (`Cache::add`, `PracticeAudioService::MISSING_EVENT_SECONDS`): повторные нажатия «Повторить загрузку» не засоряют `product_events`; жалоба `client_report` (замена) пишется каждый раз.

### 10.5 Кэш-заголовки

| Что | Заголовки |
|---|---|
| `…/audio/{element}` | `Cache-Control: private, no-store`; тело — поток файла; `ETag` нет |
| `GET /practice/*` JSON | по умолчанию Laravel (`no-cache`); клиент не кэширует |
| Файлы `practice-audio/` | не раздаются nginx (без `location`), права как у `storage/app/public`, но **без** симлинка в `public/storage` для этого каталога — хранить на отдельном диске `practice_audio` (`config/filesystems.php`: `root = storage_path('app/practice-audio')`, без `url`) — тогда `Storage::disk('public')` не путается: в `config/practice.php` `audio.disk = 'practice_audio'`, `audio.dir = ''` |

Слова остаются на диске `public` (`audio/{sha1}.mp3`), но для диктанта тоже отдаются только через эндпоинт.

---

## 11. TWA

### 11.1 Маршруты (`router/index.ts`)

| Path | name | View | `meta.tab` | Назад (`useBackButton`) |
|---|---|---|---|---|
| `/practice/:slug` (query `lesson`) | `practice-hub` | `PracticeHubView` | `courses` | `{ name: 'course', params: { slug } }` |
| `/practice/sessions/:id(\d+)` | `practice-session` | `PracticeSessionView` | — (иммерсивный) | функция: хаб сессии (`practice-hub` с `slug`/`lesson` из стора; если стор пуст — `courses`). Прогресс сохранён — подтверждения нет |
| `/battles/new` (query `friendship`, `course`, `lesson`) | `battle-new` | `BattleSetupView` | `rating` | `{ name: 'friends' }` |
| `/battles/:id(\d+)` | `battle` | `BattleView` | — (иммерсивный) | функция §11.3 |

Входы: `CourseView` — `btn-tinted btn-block` «Практика» под главной кнопкой (виден при `me.practice.available`; чип Pro при `pro_required`) → `practice-hub`; строка урока — вторичная иконка-кнопка «Практика урока» (`btn-plain btn-sm`, имя кнопки называет урок) → хаб с `lesson`. `FinishedView` — `btn-tinted` «Практика урока» / «Практика курса» при `summary.practice_entry` (новое поле `POST /finish`: `{course, lesson_id|null} | null`, `null` при `daily_review` без курса / групповой сессии / недоступной практике). `FriendsView` — у друга кнопка «Баттл» (`btn-tinted btn-sm`, «Баттл с {имя}») → `battle-new?friendship=`; блок «Приглашения на баттл» (из `GET /practice/battles.incoming`) с «Принять»/«Отклонить»; «Идёт баттл» → `battle`. Главная не меняется. ЛС → `/twa/battles/{id}`.

**Реализация 18.7b.**
- `POST /training/sessions/{id}/finish` отдаёт `practice_entry` — **всегда** (ключ есть), `{course: slug, lesson_id}` или `null` (`Learning\Services\PracticeEntryResolver` — в домене Learning, потому что читает `TrainingSession`, а код практики не импортирует модели обучения (`PracticeIsolationTest`); практика лишь отвечает, открыта ли она; `docs/04` A.3). Урок занятия → `{course, lesson_id}`; ежедневное занятие с курсом (`training_sessions.course_id`) → `{course, lesson_id: null}`. `null`: ежедневное занятие без курса, групповая сессия, практика закрыта ученику (`off`, не в пилоте), курс/урок не открывается хабом (не опубликован, приватный курс центра).
  Free-ученик при `plan = pro` запись получает (`PracticeAccessState::showsEntries`): хаб покажет карточки с чипом Pro. **Материал не проверяется** (счёт четырёх режимов — 4+ запроса на каждый `finish`); хаб объяснит «Мало материала». Цена записи — один запрос.
- `FinishedView`: `btn-tinted` «Практика урока» / «Практика курса» под «На главную» (и раньше «Узнать о Pro»), ведёт в `practice-hub` с `?from=finished` (+ `lesson`); хаб, открытый так, открывает сессии с `source = after_training`, иначе `hub` (роут передаёт хабу проп `from`).
- `CourseView`: в строке урока — кнопка-иконка «Практика урока» (`btn-plain btn-sm`, `puzzle-piece`, область нажатия 44 × 44 px от `.btn-sm::after`, `aria-label` «Практика: Модуль 2 · Урок 4»), видна при `me.practice.available`, у Free при `plan = pro` открывает пейвол; отключена, пока открывается урок, и у урока без слов. Она стоит **в конце строки прогресса** («20 из 20 слов ⧉»), а не рядом с «Учить»:
  измерение в браузере при 320 px показало, что вторая кнопка справа сужает колонку названия со 138 до 98 px (на узбекском со 112 до 72) и ломает слова посередине; в конце строки прогресса название сохраняет прежнюю ширину, а высота строки не меняется (60 px).
- Иконок в `icons.ts` — 53 (практика добавила `arrows-right-left`, `puzzle-piece`, `bars-3-bottom-left`); для входов новых иконок не потребовалось.

### 11.2 Экраны и состояния

| Экран | Состояния |
|---|---|
| `PracticeHubView` | загрузка (`LoadingState list`); ошибка (`ErrorState` + «Повторить»); `practice_unavailable` → `EmptyState` «Практика пока недоступна»; хаб: заголовок курса/урока, карточка «Продолжить» (если `active_session`), 4 `ModeCard` (иконка, название, пример, «≈ N мин», состояние: доступно / «Мало материала: …» + `btn-plain` «Учить урок» / «Выключено» / чип Pro → пейвол), кнопка «Играть с другом» (`btn-tinted`, при `battle.enabled`) → выбор друга (лист) → `battle-new`; при `practice_session_active` (409) — лист «Незавершённая практика»: «Продолжить» / «Начать новую» (`replace_active`) |
| `PracticeSessionView` | `starting` → `ready` (компонент режима) → `submitting` → `verdict`/`round_done` → `ready` … → `finished` (`PracticeSummary`); `closed` (410: «Практика завершена» + «В хаб»); `error`; аудио: `audio_loading` / `audio_failed` (лист «Повторить загрузку» / «Заменить задание» / «Сейчас без звука»). Шапка: ✕ · полоса прогресса · «3 / 5»; под ней «Модуль 2 · Урок 4 · Еда» (или курс). Действия — внизу (`StickyActions`) |
| `PracticeSummary` | плитки «Выполнено / Верно с первой попытки / Подсказки» (пары: «Пар / Верно с первой / Ошибок»), список ошибок (`ListGroup`: подпись, ваш ответ зачёркнут, чип `typo`/`wrong`), `btn-primary` «Повторить ошибки» (если `retry_available`) иначе «Ещё раз» (новая сессия того же режима), `btn-tinted` «В курс», `btn-plain` «Закрыть» |
| `BattleSetupView` | шаги в одном экране: друг (из query), курс (`SegmentedControl`/список доступных курсов — `GET /courses`), урок (список уроков курса или «Весь курс»), формат (4 карточки из `GET /practice/battles/options`: доступно/недоступно с причиной, лимит раунда), `btn-primary` «Пригласить» → `battle` |
| `BattleView` | по `status`: `invited` (инициатор: «Ждём {имя}… осталось 4:12», «Отменить»; адресат: правила, формат, тема, «Принять»/«Отклонить»); `room` (соперник, тема, правила и лимит раунда, предзагрузка аудио у диктанта с индикатором, «Готов» / «Готов ✓ ждём соперника», «Выйти»); `countdown` («3 · 2 · 1»); `playing/answering` (шапка: раунд «2 / 5», `RoundTimer` по серверному дедлайну, счёт «100 : 125»; компонент режима в режиме «один ответ»; после ответа — «Ждём соперника…»); `playing/reveal` (`BattleReveal`: верно/неверно обоих, правильный ответ, пояснение, очки раунда, «Следующий раунд через 3…»); `finished|closed` (`BattleResult`: исход крупно — «Победа / Поражение / Ничья / Матч не состоялся», причина (`end_reason` текстом: сдача, соперник вышел, оба пропали, аннулирован), раунды списком, «Реванш» (`btn-primary`), «К друзьям», «В курс»); `opponent_present = false` → полоска «Соперник потерял связь… 0:21»; ошибка сети → «Нет связи, пробуем…» без сброса экрана |

Доступность: цели ≥ 44 px (`.option`, фрагменты — `btn` min-h 48; пары — карточки min-h 56); состояния `selected/correct/incorrect` — цвет **+** значок (✓/✕) **+** текст (`aria-live="polite"` для вердикта и таймера каждые 10 с и последние 5 с); фрагменты в строке ответа — `role="list"`, кнопки «Убрать: tea»; таймер — `role="timer"`; клавиатура диктанта — `autocapitalize="off" autocorrect="off" spellcheck="false" autocomplete="off"`.

### 11.3 Компоненты

`components/practice/`: `ModeCard.vue`, `PairsBoard.vue` (две колонки; выбранное слово подсвечено; верная пара — `correct` и исчезает через 400 мс; неверная — `incorrect` 600 мс + хаптик `error`; клавиатура: Tab/Enter), `SentenceBuilder.vue` (строка ответа + банк фрагментов; тап переносит; «Начать заново»; «Проверить» активна при всех фрагментах; первая буква первого фрагмента в строке ответа отображается заглавной — только отображение, в ответ уходят id), `CollocationChoice.vue` (3 `.option`), `DictationInput.vue` (кнопка-круг «Прослушать», поле `.field-surface`, «Проверить», «Не знаю»), `AnswerReveal.vue` (общий разбор по `kind`), `PracticeSummary.vue`, `AudioTrouble.vue` (лист). `components/battle/`: `BattleRoom.vue`, `RoundTimer.vue`, `BattleReveal.vue`, `BattleResult.vue`, `OpponentStatus.vue`, `LeaveConfirm.vue` (`.sheet`: «Сдаться и выйти?» — `btn-danger` «Сдаться», `btn-secondary` «Остаться»). Компоненты режимов принимают `element` + `mode: 'solo' | 'battle'` (баттл: один окончательный ответ, пары — набор + «Проверить»).

Назад/✕ в `BattleView`: `invited`/`room`/`countdown` → лист «Выйти из комнаты?» → `cancel` → `friends`; `playing` → `LeaveConfirm` → `surrender` → остаёмся на экране итогов; `finished|closed` → `friends` (или `course`, если пришли из хаба — по `history.state.back`).

### 11.4 Сторы

`stores/practice.ts`: `hub` (данные хаба, статус), `session` (`SessionView`), `status: 'idle'|'starting'|'ready'|'submitting'|'verdict'|'finished'|'closed'|'error'`, `verdict/reveal`, `pendingRequestId` (uuid, создаётся при нажатии; повтор после сетевой ошибки — **тот же** uuid), `generation` (как `training.ts`: любой ответ с чужим поколением отбрасывается; `resetForSession` увеличивает), `elementSeq` (ключ компонента: новый элемент — новый компонент, без остатков выбора), `audio: {state, blobUrl}`; actions `loadHub`, `open`, `load`, `answer`, `skip`, `replace`, `retry`. Ошибки: `element_not_current` → перечитать `load()`; `answer_already_accepted` → показать вердикт из `details` и перечитать; 410 → `closed`.

**Реализация 18.9t** (по ревью TWA соло; `stores/practice.ts`, `components/practice/*`, `api/client.ts`, `views/Practice*`):
- **Один `request_id` и один ответ на элемент.** `pendingRequestId` живёт, пока сервер не ответил окончательно (вердикт или определённый отказ), а не до следующего нажатия. Сочетание, предложение и диктант — один ответ на элемент: ключ — элемент, и после сетевого сбоя (сеть, 5xx, 429) уходит **тот же** `request_id` и **тот же** ответ — `pendingAnswer`, а не то, что на экране сейчас
  (сервер ответит первым результатом на тот же id, и экран не покажет другой выбор как засчитанный). Пара — отдельная попытка: ключ — элемент и сопоставление. Замена диктанта держит свой id (`replacing {elementId, requestId}`), чтобы потерянный ответ и потерянная замена не забывали друг друга.
- **Исход `earlier`.** `PracticeOutcome` получил признак `earlier`: на 409 `answer_already_accepted` (другая вкладка, ответ, потерянный навсегда) экран показывает вердикт из `details` и фразу «Засчитан ответ, отправленный раньше» (`practice.reveal.earlier`) — **без** правильного ответа и без выбора ученика: какой ответ засчитан, клиент не знает, и это не тот, что только что отправлен (у `answer_already_accepted` в соло в `details` только `verdict`). «Дальше» ведёт на перечитанную сессию; у пар такого исхода нет.
- **Поколения.** К `generation` добавлены `openSeq` — поколение вызова `open()` — и действие `leaveOpen()`: отказ открытия (лист «Незавершённая практика», пейвол, тост), пришедший после ухода с экрана, следующему экрану не показывается; `leaveOpen()` вызывают хаб и экран сессии при уходе. Геттер `placeOf(active)` называет место незавершённой сессии словами
  («Модуль 2 · Урок 4 · Еда» или курс) для карточки «Продолжить» и листа, пока оно известно клиенту: сервер в `active_session` и в `details` 409 `practice_session_active` даёт только slug курса и id урока, поэтому без показанной сессии или хаба того же места `placeOf` возвращает `null` (сервер может добавить названия — открытый вопрос).
- **`elementSeq`** растёт не только на новом элементе, но и после окончательного отказа сервера (422, 409 другого рода, 403 Pro): компонент режима начинается заново и разблокирован; после потерянного ответа (сеть, 5xx, 429) он остаётся заблокирован вместе с выбором — ответ мог быть засчитан.
- **«Сейчас без звука».** Кнопка `btn-plain` видна на экране диктанта постоянно, а не только при сбое звука, и ведёт в хаб. Перед уходом сессия помечается `abandoned` (`abandonSession()`): хаб открывает следующий режим сразу с `replace_active` и не спрашивает «Продолжить или начать новую» про сессию, из которой ученик только что вышел
  (`isAbandoned`, `clearAbandoned`; пометка снимается, когда новая сессия открыта).
- **Звук.** Клип грузится не дольше 15 с (`BLOB_TIMEOUT_MS` в `api/client.ts`: `AbortController`, `getBlob(path, signal)`; таймаут — ошибка `network`, слот в `failed`, экран предлагает «Повторить загрузку» / «Заменить задание» / «Сейчас без звука»). Уход с экрана и новая загрузка отменяют запрос (`revoke()` останавливает загрузку, которая ещё идёт).
- **Причины в хабе.** Тип `PracticeHubReason = PracticeShortageReason | 'items_locked'` (`items_locked` — задания есть, но ждут слов урока; причину считает сервер, §4.3): `no_items` («Для этой темы заданий пока нет») — без кнопки (учить больше не поможет, как и при `audio_pending` / `no_audio_words`), `items_locked` («Выучите слова урока — задания откроются») — с кнопкой «Учить урок».
  У баттла причины `items_locked` нет: `BattleModeOption.reason` держит `PracticeShortageReason`.

`stores/battle.ts`: `battle` (`BattleResource`), `poll` (последний `BattlePollResource`), `round` (`BattleRoundResource` текущего), `status` (зеркало сервера), `generation`, `pendingAnswer {request_id, round}`, `serverOffsetMs` (§11.5), `polling` флаг; actions `open(id)`, `startPolling`, `stopPolling`, `tick` (poll → при смене `status` → `GET /battles/{id}`; при смене `round.no`/`phase` → `GET /round`), `accept/decline/cancel/ready/surrender/answer/audioFailed`. Поздний ответ на уже закрытый раунд (`round_closed`) → перечитать `round`/`poll`, вердикт не показывать (сервер его не принял). Сетевой сбой `answer` → повтор с тем же `request_id` (не более 3 раз, затем кнопка «Повторить»).

### 11.5 Composables

- `usePolling(tick: () => Promise<void>, { intervalMs = 2000, enabled: Ref<boolean> })`: `setInterval` только когда `enabled && document.visibilityState === 'visible'`; `visibilitychange` → скрыто: стоп; видимо: немедленный `tick`, затем интервал; `tick` не накладывается (пропуск, если предыдущий в полёте); 429/5xx/сеть → интервал ×2 до 10 с, успех → 2 с; остановка при терминальном статусе и `onBeforeUnmount`.
- `useServerClock()`: `offsetMs = serverNow − Date.now()` по каждому ответу с `server_now` (медиана последних 3); `remaining(deadlineIso) = deadline − (Date.now() + offsetMs)`; `RoundTimer` тикает каждые 250 мс от него, при 0 показывает «Время вышло» и ждёт сервер (ответ после 0 всё равно уходит: сервер решает по грейсу).
- `usePracticeAudio()`: `load(url) → Promise<void>` (fetch с Bearer через `api.client` raw-метод `getBlob`), `play()`, `state: 'idle'|'loading'|'ready'|'failed'`, `revoke()`.
- `useBackButton` — существующий.

### 11.6 i18n и ошибки

Неймспейсы `ru.ts`/`uz.ts` (новые ключи — в отдельных блоках с маркерами `// ---- practice: hub ----`, `// ---- practice: pairs ----`, `… collocation / sentence / dictation / summary`, `// ---- battle ----`; каждая задача правит только свой блок): `practice.hub.*`, `practice.mode.{pairs,collocation,sentence,dictation}.{title,example,estimate}`, `practice.session.*`, `practice.pairs.*`, `practice.sentence.*`, `practice.collocation.*`, `practice.dictation.*`, `practice.verdict.{correct,typo,wrong,skipped}`, `practice.summary.*`, `practice.audio.*`, `battle.*` (`invite`, `room`, `countdown`, `round`, `reveal`, `result`, `reason.{completed,surrender,absent,both_absent,declined,invite_expired,room_expired,canceled,voided}`, `leave`). Коды → `error.code.<код>` для всех кодов §7.6 (+ `KNOWN_ERROR_CODES`). Тексты uz — латиница, «Siz»; каждая новая строка — в `docs/I18N_UZ_REVIEW.md` (раздел «Практика и баттл 2026-10»). Термины — docs/14 §8 («модуль/урок», «вы»).

### 11.7 Типы (`api/types.ts`, пишет 18.1-T целиком по §7)

`PracticeMode`, `PracticeHubResponse`, `PracticeSession`, `PracticeElement = PairsRoundElement | CollocationElement | SentenceElement | DictationElement` (по `kind`), `PracticeAnswerRequest`, `PracticeAnswerResponse`, `PracticeReveal`, `PracticeSummary`, `BattleOptionsResponse`, `BattleListResponse`, `Battle`, `BattlePoll`, `BattleRound`, `BattleAnswerResponse`, `MeResponse.practice: { available, pro_required, modes, battle }`, `SessionSummary.practice_entry`, `ApiError.error.details?`. `api/index.ts`: `api.practice.{hub, open, get, answer, replace, audioBlob}`, `api.battles.{options, list, invite, get, poll, round, accept, decline, cancel, ready, surrender, answer, audioFailed, audioBlob}`.

---

## 12. Безопасность (дополнение docs/07)

| Угроза | Мера |
|---|---|
| Утечка правильных ответов в сети/JSON | Ожидаемые ответы, `pairs`, `correct_ids`, `accepted`, `expected`, `audio_path`, `word_id`/`version_id` живут только в `snapshot`; Resource — белый список через `project()`; контрактный тест с запретными ключами (`CONTRACT_FORBIDDEN_PRACTICE_KEYS`); разбор — после принятия ответа, в баттле — после фиксации обоих/дедлайна (`round.reveal` иначе `null`) |
| Вывод ответа из идентификаторов | Случайные 10-символьные id, порядок — HMAC-перестановка от случайного seed (не хранится); никаких индексов правильного порядка, `word_id` в парах |
| Подмена участника / чужая сессия | Все выборки — с `learner_id = me` (сессии) или участник ∈ {challenger, opponent} (матч); чужой/несуществующий id → один и тот же 404; `learner_id` никогда не берётся из тела |
| Подмена элемента/варианта/фрагмента | id проверяются по снимку (422 `unknown_element_id`); перестановка должна содержать **все** фрагменты ровно по одному (`invalid_answer_shape`); биекция пар; `round` обязан быть текущим |
| Повтор/двойная отправка | `request_id` (uuid, обязателен) + unique `(session_id, request_id)` / `(battle_id, learner_id, request_id)`; один окончательный ответ — unique `(battle_id, round, learner_id)`; состояние меняется только под `FOR UPDATE` строки |
| Клиентское время | Клиентские `time_spent_ms` — телеметрия; дедлайны, `accepted_at`, грейс — только серверные часы; `server_now` в ответах |
| Накрутка/абьюз приглашений | Только принятый друг, не сам себе, `blocked_at` обеих сторон; один активный (БД); `twa-battle-invite` 5/мин; срок приглашения 5 мин; у выключенного баттла/режима — 409 |
| Доступ к аудио | Только владелец/участник, по id элемента снимка, JWT; `no-store`; каталог `practice-audio` не раздаётся nginx; имя файла не содержит текста; клипы будущих раундов не отдаются (кроме комнаты диктанта) |
| Размеры входа | `answer.text ≤ 200`, `order ≤ 8` id по 16 символов, `pairs ≤ 4` пар, тела ≤ 4 КБ (валидация), импорт ≤ 2 МБ / ≤ 300 заданий, `key ≤ 64`, тексты по `content.*_max`; `request_id` строго uuid |
| Лимиты | §7.5; опрос — свой бакет, не исключение; `TwaAuth` до `throttle` (уже так) |
| Контент из импорта (XSS) | Строки — untrusted: Filament экранирует; TWA выводит через `{{ }}` (без `v-html`); текст со знаками `<` и `>` отклоняется (`markup_not_allowed`), управляющие символы удаляются при сохранении версии; уведомления админки об ошибках файла собираются из строк, пропущенных через `e()` (тело уведомления Filament выводит как HTML) |
| Чужая практика в админке | Только активный `admin` (`PracticeItemPolicy` → `viewAny/create/update/review/publish/archive` = admin; teacher — 403); кабинет `/org` ресурса не получает; пилот — действие на `LearnerResource` только admin, с `audit_logs` |
| Изоляция обучения | Практика не вызывает сервисы SRS/статистики/лиг; `PracticeIsolationTest` (§14) |

Чек-лист пен-теста (docs/07 §14) дополняется: ответ сессии содержит `pairs`/`accepted`/`expected`? — нет; чужой `GET /practice/sessions/{id}` — 404; второй `accept` другого приглашения при активном — 409 `battle_busy`; `answer` после `round_deadline_at + 1.5 с` — 409 `round_closed`; `audio` будущего раунда — 404; `order` без одного фрагмента — 422; повтор `request_id` с другим телом — ответ первого запроса.

**Реализация 18.6b / 18.7c.** Угрозы баттла, лимитеры и полный чек-лист пен-теста — `docs/07` §1, §5, §6, §14. Что код делает сверх таблицы выше: (1) чужой матч — один и тот же 404 `battle_not_found` на **каждом** маршруте (и на аудио), запрос чужого не пишет даже присутствие; `battle_busy.details.battle_id` называет только матчи самого спрашивающего;
(2) пока раунд не разрешён, не видны ни ответ и вердикт соперника, ни очки раунда, ни правильный ответ: `scores` растут при разрешении, опрос и `BattleResource` несут лишь флаги `my_answered` / `opponent_answered`, `round.reveal` — `null` (`BattleContractTest`: запретные ключи снимка и тексты ответов, пояснений, переводов и путей клипов не встречаются ни в одном ответе до разбора);
(3) повтор `request_id` отвечает прежним результатом даже после закрытия раунда и конца матча, тело повтора не сравнивается; (4) `surrender` требует `{confirm: true}`; (5) `request_id` `timeout`-строк — случайный uuid v4 (18.6-fix): предсказуемый uuid v5 игрок мог занять своим ответом на предыдущий раунд и остановить разрешение раунда (Б1, §2.7); ключ повтора и так действует только в пределах `(battle, learner)` — чужой ответ он затронуть не может; (6) `{id}` длиннее 18 цифр — 404 роутера, не 500.

---

## 13. Производительность

Бюджеты запросов (закрепляются `QueryLog::during` в `tests/Feature/Practice/PracticeQueryBudgetTest.php`; `TwaAuth` даёт 1 запрос `learners`, он включён):

| Эндпоинт | Бюджет SQL | Состав |
|---|---|---|
| `GET /practice/hub` | ≤ 9 | learner, курс, урок (join), введённые слова + переводы (2), задания ×3 типа (3: один запрос по типам `IN (...)` + версии), активная сессия, активный баттл/входящие (1) |
| `POST /practice/sessions` | ≤ 12 (повтор — ≤ 13) | как хаб для одного режима (≤ 4) + чтение активной сессии до сборки снимка (§6.1; с `replace_active` его нет) + транзакция (lock активной, update/insert ≤ 3) + событие (1) + диск ≤ 5 `exists` у диктанта; повтор — ещё чтение исходной сессии и один запрос о заданиях-ошибках (§6.1) |
| `GET /practice/sessions/{id}` | ≤ 3 | learner, сессия (+ курс/урок подписи — из снимка: `course_title`, `lesson_title` кладутся в снимок при создании); у завершённой с ошибками-заданиями — + один запрос о них для `retry_available` (у пар и без ошибок его нет: 2) |
| `POST …/answer` | ≤ 6; последний, с итогом — ≤ 7 | learner, lock, attempt lookup, insert attempt, update session, событие при завершении; итог спрашивает, можно ли повторить ошибки (+1 запрос о заданиях, у пар нет) |
| `POST …/replace` | ≤ 7 | learner, сессия без блокировки (пул запасных; их файлы читаются вне транзакции, §9), lock, attempt lookup, insert attempt, update session, событие `audio_failed` |
| `GET …/audio/{element}` | ≤ 2 | learner, сессия/матч; дальше — поток файла |
| `GET /practice/battles/{id}/poll` | ≤ 4 без перехода (learner, battle+participants одним запросом с `JOIN`, условный UPDATE присутствия ≤ 1); ≤ 10 с переходом (транзакция: lock, answers SUM, updates, событие) |
| `POST …/battles/{id}/answer` | ≤ 8 | learner, lock, answer lookup ×2, insert, [resolve: count answers], update battle, poll-view |
| `POST /practice/battles` | ≤ 14 | доступ друга (friendship, learner, entitlement из кэша), курс/урок, материал (≤ 4), транзакция (3 insert), событие |
| `practice:battles-sweep` | ≤ 2 + 4 × live | bulk expire (2) + по матчу: lock, [SUM], update ×2 |

Размеры ответов: `poll` ≤ 600 байт; `PracticeSessionResource` ≤ 8 КБ (пары: 8 карточек; предложение: 8 фрагментов); `BattleRoundResource` ≤ 6 КБ; списки `finished` — 10 строк. Снимок ≤ **40 КБ в UTF-8** (`Snapshot::MAX_BYTES`; кириллица — 2 байта, а не 6 байт `\uXXXX`; проверяется при создании — `LogicException` при превышении, чтобы не раздувать строку). Худший валидный контент по `content.*` (5 заданий-предложений по 8 фрагментов × 40 символов, 6 порядков, тексты на пределе, ru и uz, баттл с обеими локалями) — около 29 КБ, остальные режимы меньше (диктант с 8 запасными ≈ 15 КБ, пары на пределе колонок `words` ≈ 9 КБ соло / 25 КБ баттл): запас ≥ 25 % держит `SnapshotSizeTest`.

**Реализация 18.6 (бюджеты баттла закреплены на сервисах, без запроса `learners` от `TwaAuth`; HTTP-тесты — 18.6b):** poll без перехода ≤ 3 (battle, participants, условный UPDATE присутствия; у терминального — вместо присутствия чтение реванша), с переходом ≤ 9 (худший — таймаут с одним ответом: + lock, participants, answers, INSERT timeout, UPDATE score, UPDATE battle; финализация: + lock, participants, release, UPDATE battle, challenger, INSERT события); answer ≤ 7 (lock, participants, присутствие, answers, INSERT, UPDATE score CASE на обе строки, UPDATE battle); invite ≤ 13 (пары: words 1 + word_translations 2 — по локали каждого игрока); sweep ≤ 4 + 2 × просроченных + 7 × live (а не «2 + 4 × live»: участники, release и событие в докладе не были учтены; с 18.6-fix — `5 + 2 × просроченных + 7 × назревших live`: участники читаются вместе с чанком, не назревшие матчи не трогаются). Фактические значения — в `PracticeQueryBudgetTest` (комментарии к константам `BUDGET_BATTLE_*`).

**Реализация 18.6b (бюджеты на маршрутах, с запросом `learners` от `TwaAuth`; `PracticeQueryBudgetTest`):** фактически — poll 4 без перехода (3, если присутствие свежее) / 9 таймаут / 7 следующий раунд / 10 финал / 4 у законченного матча (бюджет 4 и 10); answer 7 и 8 (второй разрешает раунд; бюджет 8), повтор 7; приглашение 14 (пары; бюджет 14) / 12 (диктант) / 13 (реванш); раунд 4, матч 4, список 7, клип 3 (`BattleReader::find` читает участников: на один запрос больше соло-клипа). Размер опроса — до 404 байт в самом тяжёлом состоянии (раунд с обоими часами и «молчащий» соперник), бюджет 600.

Индексы — §2; дополнительных не нужно: все выборки учениковые (`learner_id`/участник) или по `(course_id, status, type)`. Кэшей нет (все ответы персональные и короткоживущие), кроме существующего кэша entitlement. Генерация аудио — только в job.

---

## 14. План тестов

### 14.1 Pest (SQLite, `RefreshDatabase`)

| Файл | Сценарии |
|---|---|
| `tests/Unit/Practice/PracticeTextNormalizerTest` | NFKC, регистр, апострофы, дефис, пунктуация, пробелы; `tokens` |
| `tests/Unit/Practice/DictationGraderTest` | таблица §5.2 (38 кейсов) + пары «другое слово» в обе стороны (`right/light`, `were/where`, `wrote/write`, `women/woman`…) + порядковые + `InflectionGuard::isInflection` (`likes/liked`, `nice/nicer`, `take/taken`, `walker/walked`, `walked/walkd` → false, `receive/recieve` → false) + несколько accepted |
| `tests/Unit/Practice/EnglishLexiconTest` | файл `resources/lexicon/en-common.txt` (≥ 2500 слов, нижний регистр, отсортирован, без повторов и пустых строк), все формы ~150 неправильных глаголов, правила `-s/-es/-ies/-ed/-ing/-er/-est/-ly/'s`, описки (`becuase`, `freind`…) — не слова, 1000 вызовов < 50 мс |
| `tests/Unit/Practice/SentenceGraderTest` | таблица §5.3 (8 кейсов) |
| `tests/Unit/Practice/CollocationGraderTest` | один/два верных, skip, неизвестный id |
| `tests/Unit/Practice/PairsGraderTest` | верно/неверно, `wrong_left` фиксируется один раз, `pair_already_matched`, биекция баттла, 25×n |
| `tests/Unit/Practice/TranslationVariantsTest` | разбиение `,;/`, скобки, `ё/е`, конфликты по локалям с fallback |
| `tests/Unit/Practice/SeededShuffleTest` | детерминизм, биекция, разные label |
| `tests/Feature/Practice/PracticeAccessTest` | `off`/`pilot`/`all` × `plan all/pro` × Free/Pro; `/me.practice`; режим выключен → 409 только на создании; активная сессия выключенного режима отвечает |
| `tests/Feature/Practice/PracticeMaterialTest` | введённые слова (любое состояние), неактивные исключены, урок/курс, колода владельца, чужая/private → 404; задания: все целевые слова введены, `audio_status`; распределение пар §4.3 для N = 2..13; упаковка неоднозначных в разные раунды; `reason` в details |
| `tests/Feature/Practice/PracticeSessionTest` | open (201), одна активная (409 + details, `replace_active`), resume (та же раскладка), 410 просроченной, retry (материал = ошибки, `retry_not_available`), summary числа, пары: первая ошибка/мismatches, `element_not_current`, `answer_already_accepted` с прежним вердиктом, повтор `request_id` байт-в-байт, 422 формы, replace ×2 → 409, `total--` при пустом пуле |
| `tests/Feature/Practice/PracticeAudioTest` | владелец 200 `audio/mpeg` + `no-store`, чужой 404, нет файла 503, Web Speech текст не отдаётся (`current` без текста) |
| `tests/Feature/Practice/PracticeContractTest` | `assertExactJsonStructure` всех ответов §7 + запретные ключи снимка; `/me.practice`; `details` только где описан (`finish.practice_entry` — в `TwaContractTest`, варианты — в `PracticeEntryTest`: урок / курс / `null`, доступ, цена в запросах) |
| `tests/Feature/Practice/PracticeIsolationTest` | полный прогон 4 соло-сессий и баттла (оба игрока) → контрольные суммы `word_repetitions`, `training_sessions`, `training_reviews`, `learner_daily_stats`, `learners.{streak_*,streak_freezes,daily_goal}`, `league_members`, `assignment_results`, `achievements`, `challenges` до и после равны; `product_events` содержит только события §3.4. Соло-прогон идёт дважды: на тестовом обработчике и (18.9b) на **настоящих** четырёх режимах — фабрики заданий, `DictationKit` для клипов, каждый режим от открытия до итога и его повтор (`source: retry`), клип, пропавший с диска (503 и замена), вытесненная, просроченная планировщиком и просроченная запросом сессии, курс без материала (422); три сети: контрольные суммы, список таблиц, в которые шли `INSERT/UPDATE/DELETE`, события и их props. Утечка, посаженная в `SentenceMode::grade()`, ловится только вторым прогоном |
| `tests/Feature/Practice/PracticeRetryTest` | «Повторить ошибки» (18.9b): кнопка `retry_available` и открытие повтора — одно решение (режим выключен, задания сняты с публикации, потеряли озвучку или слова, нечего повторять), кнопка возвращается вместе с заданиями, цена в запросах (итог 3 / пары 2, открытие повтора ≤ 13), диск не читается для итога |
| `tests/Feature/Practice/SnapshotSizeTest` | размер снимка в UTF-8 (`Snapshot::MAX_BYTES` = 40 КБ), худший валидный контент каждого режима (ru и uz, баттл с обеими локалями) с запасом ≥ 25 %, кириллица — по 2 байта, превышение — `LogicException` |
| `tests/Feature/Practice/PracticeItemServiceTest` | версии (append-only), статусы и `published_version_id`, правка опубликованного → новая draft при прежней выдаче, archive/restore, валидация §4.5 (каждый код) |
| `tests/Feature/Practice/PracticeImporterTest` | формат §15.2: успех, повтор файла → `skipped`, изменённое задание → новая версия, `--publish`, каждый код ошибки с `position`, лимиты, транзакция «всё или ничего», аудит `practice.imported`, job аудио поставлен после commit |
| `tests/Feature/Practice/BattleServiceTest` | invite (друг, не друг, сам себе, blocked, private курс, мало материала, выключено, busy), accept/decline/cancel/expire (ленивое и дворником), ready → countdown → playing, ответы и очки по режимам, `round_not_open`/`round_closed`/грейс, разрешение раунда вторым ответом, таймаут с `timeout`-строками, reveal → следующий раунд, финализация 5 раундов (победа/ничья), surrender, absent/both_absent через `last_seen_at`, voided (аудио), рематч, присутствие пишется ≤ 1 раз в 5 с, `state_version`, `BattleListResource`, `options`. **Реализация 18.6:** 29 тестов на сервисах и реальных режимах (все четыре), тест-кит `tests/Support/Practice/BattleKit` (мир двух друзей ru/uz с материалом всех режимов, шаги матча, верный/неверный ответ из снимка); ровно так же — `BattleConcurrencyTest` (7 гонок), баттл-части `PracticeIsolationTest`, `PracticeSchedulerTest`, `PracticeQueryBudgetTest` |
| `tests/Feature/Practice/BattleContractTest` | формы §7.3–7.4, poll ≤ 600 байт, `reveal: null` до разрешения, чужой ответ не виден до reveal. **Реализация 18.6b:** 20 тестов — точные структуры по всем статусам (в том числе `closed` с `result.outcome: none`), `audio_preload` = `audio_url` раундов, формы разбора всех четырёх режимов, таймаут и старые раунды, `options` / список (компактный `active`), poll по состояниям, `rematch_of` с лишними полями, встречные реванши, повтор потерянного ответа, предупреждение о молчании соперника, конверт ошибок с `details` внутри `error`, `audio-failed`; рядом `BattleAudioTest` (клипы комнаты и раундов, 503), `BattleHttpRulesTest` (чужой = 404, 401, выключатели, `confirm`), `BattleRateLimitTest` (poll и аудио вне `twa-api`), `BattleHttpMatchTest` (весь матч двух игроков по HTTP, все режимы) |
| `tests/Feature/Practice/BattleMessengerTest` | ЛС приглашения один раз (маркер), молчание при `declined`/просроченном, 403 → `dm_blocked_at`, web_app URL. **Реализация 18.6b:** 9 тестов |
| `tests/Feature/Practice/PracticeQueryBudgetTest` | бюджеты §13 через `QueryLog::during` |
| `tests/Feature/Practice/PracticeAdminTest` | Filament: список/фильтры, формы по типам, действия «Проверено/Опубликовать/В архив», импорт JSON, переключатель пилота + `audit_logs`, teacher → 403 |
| `tests/Feature/Practice/PracticeSchedulerTest` | `practice:close-expired`, `practice:battles-sweep` (expire, advance, voided при `off`) |
| `DatabaseSchemaTest` | rollback четырёх миграций |

### 14.2 PostgreSQL-гонки (pcntl, `DatabaseTruncation`, образец `ImportConcurrencyTest`)

| Файл | Сценарий | Ожидание |
|---|---|---|
| `PracticeSessionConcurrencyTest` | два `open` одного ученика одновременно | одна активная строка, оба ответа — она же |
| | два `answer` одного элемента с разными `request_id` | одна строка `practice_attempts` с вердиктом, второй — 409 `answer_already_accepted` |
| | тот же `request_id` ×2 одновременно | одна строка, одинаковые тела |
| | `answer` ∥ `practice:close-expired` просроченной | либо 410, либо принято до закрытия; строка сессии консистентна |
| `BattleConcurrencyTest` | один адресат принимает два приглашения одновременно | один `room`, второй 409 `battle_busy`; `active_learner_id` ровно один |
| | `accept` ∥ `cancel` | ровно один терминальный/комнатный исход, без deadlock |
| | два `invite` одного инициатора одновременно | один `invited` |
| | оба игрока отвечают одновременно (второй ответ разрешает раунд) | одна `reveal`, `state_version` +2, ни одной двойной строки |
| | `answer` ∥ дворник на дедлайне | либо принято (до грейса), либо `timeout`-строка; никогда обе |
| | финализация: последний `reveal` истёк, опрос обоих ∥ дворник | ровно одно событие `battle.finished`, одни `score`/`winner` |
| | `surrender` ∥ `answer` соперника | единый исход, без deadlock |
| | импорт заданий ∥ импорт слов того же курса | без deadlock, оба успешны |

### 14.3 Vitest

`stores/__tests__/practice.spec.ts` (open/resume, поколения, `pendingRequestId` сохраняется при сетевой ошибке и повторе, `element_not_current` → reload, 410 → closed, summary/retry), `practiceRaces.spec.ts` (поздний ответ прежней сессии отбрасывается; двойной тап = один запрос), `battle.spec.ts` (переходы по `status/phase`, перечитывание `round` при смене, `round_closed` без показа вердикта, surrender-поток, offset серверного времени), `composables/__tests__/usePolling.spec.ts` (видимость, наложение, backoff, стоп на терминале), `useServerClock.spec.ts`, `usePracticeAudio.spec.ts` (blob, failed → лист), компоненты: `PairsBoard.spec.ts` (выбор/отмена, клавиатура, повторные нажатия не завышают точность — второй тап на сопоставленную пару не шлёт запрос), `SentenceBuilder.spec.ts` (нет потери/дублей фрагментов, «Начать заново», 320 px без переполнения), `CollocationChoice.spec.ts`, `DictationInput.spec.ts` («Не знаю», пустой ввод, атрибуты клавиатуры), `BattleView.spec.ts` (все статусы, таймер, reveal, result, причины), `designSystem.spec.ts` (новые компоненты ≥ 44 px), i18n-страж кириллицы вне `src/i18n`.

### 14.4 Браузерный e2e (настоящий локальный API)

Скрипт `resources/twa/scripts/e2e/practice.mjs` (Playwright запускается через `npx playwright@<version>` без добавления в `package.json` — зависимость не согласована; см. §16): сид `PracticeDemoSeeder` (курс `default`, 2 ученика-друга в пилоте, ≥ 12 введённых слов, по 5 заданий трёх типов, аудио — фикстурные mp3 вместо TTS), запуск TWA с `#tgWebAppData` двух учеников (как аудит docs/14). Сценарии: 4 сольных режима до итогов и «Повторить ошибки»; баттл в двух контекстах: приглашение → принятие → готов ×2 → 5 раундов (один игрок отвечает поздно → таймаут) → одинаковые итоги у обоих; перезагрузка страницы в середине матча; уход в фон (эмуляция `visibilitychange`) и возврат; сдача; оба закрыли → `both_absent` (ускорение через конфиг `presence_grace_seconds = 5` в тестовом окружении); проверка 320 px и тёмной темы на экранах сессии/баттла.

---

## 15. Нарезка на задачи для `coder`

Обозначения: **A** — architect (ядро + тесты), **C** — coder, ‖ — параллельно (разные worktree). Общие файлы и правило деления: `routes/api.php`, `config/practice.php`, `AppServiceProvider` (лимитеры), `ApiErrorResource`, `i18n/errors.ts`, `api/types.ts`, `api/index.ts`, `router/index.ts` — **пишет только 18.1 / 18.1-T целиком по этому документу**, остальные задачи их не трогают; `i18n/{ru,uz}.ts` — каждая задача правит только свой маркированный блок (§11.6); `lang/ru/admin.php` — только 18.7a; `docs/03/04/05/07` — только 18.7b; `PROGRESS.md` — координатор.

| # | Задача | Кто | Зависит | Что входит | Definition of Done |
|---|---|---|---|---|---|
| 18.1 | Общий модуль BE | C (ревью ядра — A) | — | `config/practice.php`; 4 миграции + модели + фабрики; `Enums`, `DTO`, `Exceptions`, `Support/*`; `PracticeAccess` + `/me.practice` (`MePayloadBuilder`, `MeResource`); `PracticeMaterialService` (слова/задания/availability); `PracticeSnapshotBuilder`, `ModeRegistry`, интерфейс `PracticeModeHandler`; `PracticeSessionService` (open/find/view/answer/replace/retry/closeExpired) с **заглушечным** режимом в тестах; `PracticeAudioService` (clipFor/stream; генерация — 18.5); контроллеры `PracticeHubController`, `PracticeSessionController`, `PracticeAudioController`, Requests, Resources (все формы §7, проекция через handler); все маршруты практики **и баттла** (баттл — к `BattleController` с методами-заглушками 501? — нет: маршруты баттла добавляет 18.6b, но лимитеры — здесь); лимитеры §7.5; `ApiErrorResource.details`; команда `practice:close-expired` + расписание; события | pint, phpstan 8, `PracticeAccessTest`, `PracticeSessionTest` (на заглушке режима), `PracticeContractTest` (хаб/сессия/`/me`), `PracticeIsolationTest` (каркас), `DatabaseSchemaTest`, `PracticeQueryBudgetTest` (хаб, open, answer, audio) зелёные; `TwaContractTest` обновлён |
| 18.1-T | Оболочка TWA на моках | C ‖ 18.1 | контракт §7, §11 | `api/types.ts` + `api/index.ts` (все типы/методы практики и баттла), `KNOWN_ERROR_CODES` + `error.code.*` (ru/uz), маршруты §11.1, `stores/practice.ts`, `stores/battle.ts` (каркас), `usePolling`, `useServerClock`, `usePracticeAudio`, `PracticeHubView`, `PracticeSessionView` (шапка, прогресс, слот компонента режима, `AnswerReveal`, `PracticeSummary`, `AudioTrouble`), `ModeCard`, вход «Практика» в `CourseView` (за `me.practice.available`), пейвол `reason="practice"`; i18n блоки hub/session/summary/audio + маркеры для остальных | vue-tsc, Vitest (`practice.spec`, `practiceRaces.spec`, `usePolling.spec`, `useServerClock.spec`), сборка; экраны на фикстурах в обеих темах, 320 px |
| 18.2 | Пары | C ‖ | 18.1, 18.1-T | `PairsMode` (availability/build/project/grade/mistakeLabel), `PairsGrader`, `TranslationVariants`, упаковка §4.3–4.4; `PairsBoard.vue`; i18n блок pairs | Unit + Feature пар, Vitest `PairsBoard.spec`; сценарий docs/15 §3.1 «готово, когда» |
| 18.3 | Сочетание | C ‖ | 18.1, 18.1-T | `CollocationMode`, `CollocationGrader`, `CollocationChoice.vue`, i18n блок | тесты режима; `PracticeItemService::saveVersion` валидация collocation (если 18.7a ещё не слит — тесты валидации здесь) |
| 18.4 | Предложение | C ‖ | 18.1, 18.1-T | `SentenceMode`, `SentenceGrader`, `SentenceBuilder.vue`, i18n блок | таблица §5.3; Vitest `SentenceBuilder.spec` (без потери/дублей, 320 px) |
| 18.5 | Диктант + аудио | C ‖ (грейдер — ревью A) | 18.1, 18.1-T | `DictationMode` (слова + фразы, состав §4.3), `DictationGrader`, `InflectionGuard`, `GeneratePracticeAudioJob`, `PracticeAudioService::schedule/generate`, диск `practice_audio` (`config/filesystems.php`), `replace`, `DictationInput.vue`, `usePracticeAudio` интеграция, i18n блок | таблица §5.2; `PracticeAudioTest`; Vitest `DictationInput.spec`, `usePracticeAudio.spec` |
| 18.7a | Админка и импорт | C ‖ | 18.1 (модели) | `PracticeItemService` (+ валидация §4.5), `PracticeImporter` + `practice:import {file} {--publish}`, `PracticeItemResource` (список: курс/урок/тип/статус/аудио/«есть правка»; фильтры курс/тип/статус; формы по типам с целевыми словами — выбор из слов курса; действия «Проверено», «Опубликовать», «В архив», «Вернуть», «Сгенерировать аудио»), `ImportPracticeItems` (страница, чекбокс «опубликовать сразу», отчёт по строкам через `AdminErrors`), `PracticeItemPolicy`, переключатель «Пилот практики» на `ViewLearner` + аудит, `lang/ru/admin.php` (блоки `practice_items`, `pages.import_practice`, `learners.actions.practice_pilot`), пример JSON для «Скачать пример», `PracticeDemoSeeder` | `PracticeItemServiceTest`, `PracticeImporterTest`, `PracticeAdminTest`; `FilamentRussianTranslationsTest` зелёный |
| 18.6 | Баттл — ядро конкурентности | **A** | 18.1, 18.2–18.5 (хотя бы один режим) | `BattleService` (invite/accept/decline/cancel/ready/answer/surrender/audioFailed), `BattleClock::advance`, `BattleJanitor` + `practice:battles-sweep`, `BattleReader` (view/poll/round/list/options — чистые read-модели), `BattleException`; `BattleServiceTest`, `BattleConcurrencyTest` (PG), `PracticeSessionConcurrencyTest` (PG), бюджеты poll/answer | все тесты §14.2 зелёные на PG 16 (отчёт с цифрами), SQLite — зелёные |
| 18.6b | Баттл — обвязка | C | 18.6 | `BattleController` + Requests + Resources (формы §7.3–7.4), маршруты баттла (группы `twa-battle-poll`/`twa-practice-audio` вне `twa-api`), `SendBattleDmJob`, `BattleMessenger`, `lang/{ru,uz}/battle.php`, `BattleContractTest`, `BattleMessengerTest` | контрактные тесты, pint, phpstan |
| 18.6-T | Баттл — TWA | C ‖ 18.6b (на моках) | 18.1-T | `stores/battle.ts` полностью, `BattleSetupView`, `BattleView` + компоненты §11.3, вход из `FriendsView` (кнопка «Баттл», входящие, идёт баттл), i18n блок battle, `LeaveConfirm` | Vitest `battle.spec`, `BattleView.spec`; экраны всех статусов на фикстурах |
| 18.7b | Входы, итоги, документы, e2e | C | всё выше | `finish.practice_entry` + кнопка в `FinishedView`; «Практика урока» в строках уроков `CourseView`; `PracticeDemoSeeder` в демо-сидах (только local/testing); обновление `docs/03` (§2.40–2.47, §3), `docs/04` (A.16 практика, A.17 баттл, A.6 `details`, B.14 ЛС баттла), `docs/05` (маршруты, сторы, composables), `docs/07` (§1 строки практики, §6 лимитеры, §14 чек-лист), `docs/I18N_UZ_REVIEW.md`; e2e-скрипт §14.4 и прогон на реальном API; `USER_GUIDE.md` раздел «Практика и баттл» | весь Pest (SQLite + PG), vue-tsc, Vitest, сборки, e2e-отчёт; ревью фичи `reviewer` |

Уточнения координатора к нарезке:
- `routes/api.php`: 18.1 добавляет маршруты практики (§7.1–7.2) и все лимитеры §7.5; блок маршрутов баттла (§7.3–7.4, включая группы вне `twa-api`) добавляет 18.6b — после слияния 18.6, параллельных правок файла нет.
- 18.2–18.5 берут задания для тестов из фабрик моделей 18.1 (`PracticeItem`/`PracticeItemVersion` с готовым `content`), **не** реализуют `PracticeItemService` и валидацию §4.5 — это только 18.7a (никакого дублирования валидации в режимах).
- 18.7a кладёт пилотный пакет координатора в `database/data/practice/pilot-a1-a2.json` (90 заданий, §16 п. 1) и проверяет его импорт тестом; `PracticeDemoSeeder` импортирует его с публикацией только в `local`/`testing`.
- **Реализация (нарезка).** 18.7b разделена: 18.7b — входы в практику, итоги и документы соло, 18.7c — документы баттла (`docs/04` A.17 и B.14, `docs/05` §5a.5, `docs/07`, `docs/03` §2.45–§2.47, `USER_GUIDE`, руководства администратора и DevOps); браузерный e2e §14.4 вынесен в отдельную задачу. По итогам ревью добавлены 18.9a / 18.9b / 18.9t (контент, сервер и TWA соло) и 18.10t (TWA баттла).

Порядок: 18.1 ‖ 18.1-T → (18.2 ‖ 18.3 ‖ 18.4 ‖ 18.5 ‖ 18.7a) → 18.6 (A) → 18.6b ‖ 18.6-T → 18.7b. Один простой сценарий «открыл → ответил → итог» должен проходить уже после 18.1 + 18.1-T + 18.2 (этап 1–2 docs/15 §6).

### 15.1 Формат импорта (окончательный; `PracticeImporter`)

```json
{ "format": "lexiflow-practice/1", "course": "default",
  "items": [
    { "key": "s1l3-sent-01", "type": "sentence", "lesson": {"stage": 1, "number": 3}, "level": "A1", "target_words": ["mother"],
      "prompt": {"ru": "Моя мама — учитель.", "uz": "Onam o'qituvchi."},
      "fragments": ["My", "mother", "is", "a", "teacher"],
      "accepted": [["My", "mother", "is", "a", "teacher"]],
      "explanation": {"ru": "…", "uz": "…"} },
    { "key": "s2l1-col-01", "type": "collocation", "lesson": {"stage": 2, "number": 1}, "target_words": ["meeting"],
      "text": "Let's ___ a meeting on Monday.", "options": ["have", "make", "do"], "correct": ["have"],
      "translation": {"ru": "…", "uz": "…"}, "explanation": {"ru": "…", "uz": "…"} },
    { "key": "s1l2-dict-01", "type": "dictation", "lesson": {"stage": 1, "number": 2}, "target_words": ["tomorrow"],
      "text": "See you tomorrow", "accepted": ["See you tomorrow"], "translation": {"ru": "До завтра", "uz": "Ertagacha"} }
  ] }
```

Отличия от предложения координатора: **`key` обязателен** (ключ идемпотентности задаёт автор; производный от содержимого ключ ломал бы идемпотентность при правке опечатки — вместо новой версии рождалось бы новое задание); `format` обязателен и равен `lexiflow-practice/1` (иначе `format_unsupported`); `lesson` необязателен (`null`/отсутствие — задание курса), `course` обязателен (slug существующего курса, не колоды); флага публикации в файле нет — он у вызывающего (чекбокс/`--publish`). `accepted` у предложения — массивы **строк-фрагментов**, при сохранении превращаются в индексы (одинаковые фрагменты сопоставляются по порядку появления; на проверку это не влияет — сравнивается текст). Необязательное поле предложения `display` (строка) — каноническая запись первой допустимой последовательности с регистром и пунктуацией для разбора (`display_mismatch`, §4.5); фрагменты в файле — строчные, кроме `I` и имён собственных (§5.3). Неизвестные поля задания **и ключи внутри объекта `lesson`** (кроме `stage`, `number`) — ошибка `field_unknown` (опечатка в имени поля не должна молча терять текст).

Правила файла: ≤ 2 МБ, 1..300 заданий, `key` уникален в файле (`key_duplicate_in_file`), `target_words` — активные слова курса по тексту без регистра (`target_word_unknown {word, position}`), остальное — §4.5. Повтор файла: задание с тем же `key`, у которого с текущей версией совпадают все четыре — `content`, целевые слова, урок (`lesson`) и уровень (`level`), → `skipped`; любое отличие → новая версия (`updated`; все четыре пишутся в неё; при `--publish` — сразу `published`, прежняя `superseded`); новое → `added`; архивное → `item_archived` (восстановите в админке). Отчёт `PracticeImportReport {added, updated, skipped, published, errors[], issues[]}`; `ImportIssue`-коды с `position` (номер задания, с 1) и `key`; тексты по-русски — `admin.pages.import_practice.errors.<code>` через `AdminErrors::text('pages.import_practice', …)`. Транзакция: `CoursePublisher::lockForWriting(course)` первым, затем задания; аудит `practice.imported {course_id, report}`; job аудио — `afterCommit`. Аналог для artisan: `php artisan practice:import storage/practice/pilot-a1.json --publish`.

**Реализация 18.7a** (`PracticeItemService`, `PracticeImporter`, `PracticePilotService`, `Filament\Resources\PracticeItemResource`, `Filament\Pages\ImportPracticeItems`, `PracticeDemoSeeder`):
- Переходы жизни задания, которые не подходят к его состоянию, — `PracticeItemStateException` (409 в админке, тексты — `AdminErrors`): `item_archived` (сначала «Вернуть»), `item_not_archived`, `not_draft` («Проверено» у уже опубликованной версии), `not_reviewed` (опубликовать можно только проверенное), `no_version`, `item_gone`.
- `course`, `type` и `key` после создания **не меняются**; целевые слова, урок и уровень — часть версии (исправления 18.9a ниже): любое их изменение, как и изменение текста, — новая версия.
- `PracticeDemoSeeder` (только `local` / `testing`) для демо-учеников дописывает строки `word_repetitions` по **всем** целевым словам пакета (иначе задание не выдаётся: ученик должен быть введён во все его слова), ставит их в пилот и озвучивает фразы сам (`ffmpeg` + `flite`; по 18.9a его импорт не ставит job озвучки); курс берёт по slug из пакета; повторный запуск ничего не меняет.
- Пилотный пакет `database/data/practice/pilot-a1-a2.json` — 90 заданий (по 10 каждого из трёх типов в трёх уроках курса `default`); после проверки языка тексты правились (коммит «pilot pack language fixes»); импорт на проде — черновиками.

**Исправления 18.9a** (по ревью админки и контента):
- Целевые слова, урок и уровень принадлежат **версии** (§2.3), `practice_items.lesson_id`/`level` и `practice_item_words` — проекция одной версии (§2.4): черновик больше не меняет выдачу опубликованной. Импорт: `skipped`, если у текущей версии совпали все четыре (текст, слова, урок, уровень), иначе `updated`. `SavedPracticeItem::attributesChanged` убран — изменения задания без новой версии нет.
- «Проверено» и «Опубликовать» принимают версию, которую админ видел (`ShownVersionAction` кладёт её id в кнопку; страница правки хранит версию своей формы); другая текущая — `version_changed`.
- Колонка «Аудио» — статус опубликованной версии (у неопубликованного задания — текущей), «Правка: …» — если у черновика иначе; фильтр находит и то, и другое. Кнопка «Сгенерировать аудио заново» ставит обе версии.
- Порядок фрагментов — всегда первая допустимая последовательность (`accepted_first_mismatch`, §4.5); `<`/`>` в тексте — `markup_not_allowed`.
- Кнопка пилота — два действия (`practicePilotInclude` / `practicePilotExclude`), направление не вычисляется при нажатии; уведомления импорта экранируют строки файла; лимит размера импорта у страницы и команды — `practice.import.max_bytes` (`PracticeImporter::maxBytes()`); `save()` отказывает заданию чужого курса.

---

## 16. Вопросы владельцу (работаем с рекомендуемым значением, пока нет ответа)

| # | Вопрос | Рекомендуемое значение (принято по умолчанию) |
|---|---|---|
| 1 | Какие курс/уроки — три пилотные темы A1–A2 и кто готовит ~90 заданий и uz-тексты? | Курс `default` демо-контента: «Модуль 1 · Урок 2» (время и дни недели, A1), «Модуль 1 · Урок 3» (семья, A1), «Модуль 2 · Урок 1» (работа, A2) — по 10 заданий каждого авторского типа на тему, всего 90. Пакет подготовил координатор (`database/data/practice/pilot-a1-a2.json`, формат §15.1); на проде импорт — черновиками, публикация после «Проверено»; uz-тексты — на проверку носителю (`docs/I18N_UZ_REVIEW.md`). Для другого курса пакет — образец: целевые слова и уроки должны существовать в курсе, иначе импорт отклоняется целиком |
| 2 | Минимум сольной сессии заданий — 3 (при нехватке) или строго 5? | **3** (`practice.solo.items.min`), чтобы практика урока работала при 3–4 заданиях на урок; баттл — строго 5 |
| 3 | Срок приглашения 5 мин и комнаты 2 мин — приемлемо для живого матча? | Да; оба в конфиге без env, меняются релизом |
| 4 | Пояснение (`explanation`) на uz обязательно для публикации сочетаний и предложений? | **Да** (ru+uz обязательны); у диктанта пояснение необязательно |
| 5 | Playwright для e2e двух игроков — добавить `@playwright/test` в devDependencies или запускать `npx playwright` вне `package.json`? | Вне `package.json` (без новой зависимости), скрипт в репозитории, отчёт прогона в PROGRESS |
| 6 | Нужны ли ЛС об итоге баттла (победа/поражение), как у вызовов? | **Нет** в первом выпуске: оба игрока в приложении; только ЛС-приглашение |
