# Опросы в боте, регион ученика, раздел английских фраз — проект и план задач

Документ architect'а · 25 ноября 2026 года. Статус: **проект, код не писался.** Три новые функции владельца. Перед кодом координатор переносит решения в docs/03, docs/04, docs/06, docs/12 и docs/32 (задачи O0, R0, F0).

Связь с соседними проектами:
- [31 — общий план соревнований](31_COMPETITION_PLAN_RU.md): выпуски, в которые встают функции (§4 ниже);
- [32 — очки v2, Free/Pro, «За всё время», каталог `lexemes`](32_SCORING_AND_ALLTIME_DESIGN_RU.md): региональный рейтинг — фильтр поверх таблиц из §5 этого документа; фразы — единицы каталога из §2.1.

Обозначения исполнителя: **C** — coder, **A** — architect, **K** — контент (автор и редактор по `docs/content-authoring`), **коорд.** — координатор.

---

## 1. Опрос в Telegram-боте

### 1.1. Что есть в коде

| Что | Где | Годится для опроса |
|---|---|---|
| Массовые рассылки и объявления из `/admin` | **нет.** Есть только адресные рассылки: итоги недели (`SendLeagueResultsJob` → `LeagueResultSender::sendBatch`), напоминания (`ReminderDispatcher`/`ReminderSender`), карточки достижений и игры | `SendLeagueResultsJob` — готовый образец рассылки пачками: пачка учеников на задание, общий бюджет отправки, остаток уходит новым заданием, срок жизни у всей рассылки |
| Клиент Telegram | `App\Domain\Telegram\Contracts\TelegramClient`, реализация `IrazasyedTelegramClient` (SDK `irazasyed/telegram-bot-sdk ^3.16`) | `sendPhoto` есть, но принимает только **локальный файл** и **ничего не возвращает** (нет `message_id`, нет `file_id`). Есть `sendMessage`, `answerCallbackQuery`, `editMessageReplyMarkup`, `editMessageText`. Нет `editMessageCaption`, нет `sendPoll` |
| Ответы кнопками | `ReminderQuizCallbackHandler` + `ReminderQuizCallback` (`rq1:<token>:<index>`) + `ReminderQuizAnswerService` | **Почти готовый образец** ответа на вопрос кнопкой: строгий разбор `callback_data`, ученик = отправитель нажатия, чат должен быть личным и совпадать с `from.id`, токен сверяется с сохранённым состоянием, повторное нажатие даёт тот же результат, устаревшая кнопка — алерт и замена клавиатуры |
| Маршрутизация обновлений | `TelegramDispatcher` (списки `messageHandlers` и `callbackHandlers`), `HandleTelegramUpdate` | Новый обработчик добавляется в список. `allowed_updates` (`IrazasyedTelegramClient::ALLOWED_UPDATES`) **не включает `poll_answer`** |
| Ограничение скорости | `TelegramMessageRateLimiter::reserveGlobal()/awaitGlobal()` — общий бюджет бота 25 сообщений в секунду; `ThrottleTelegramOutbound` для одиночных заданий | Используем как есть |
| Недоступный ученик | `learners.dm_blocked_at` (ставит `DmLearnerResolver::markBlocked` по 403 и 400 `chat not found`, ошибку `TelegramForbiddenException` даёт клиент), `learners.blocked_at` (бан), `deleted_at` | Ровно те же фильтры, что у `ReminderDispatcher` |
| Тариф в SQL | `App\Domain\Billing\Support\PaidSubscriptionQuery::personalLearnerIds/sponsoredLearnerIds`, через них работает фильтр «Тариф» в `LearnerResource::filterByPlan` | Аудитория Free/Pro |
| Загрузка файлов в `/admin` | `FileUpload` в `ImportLesson`, `ImportPracticeItems` | Тот же компонент для картинки |
| Выгрузка CSV | `App\Domain\Organizations\Services\OrgActivityReportCsv` (разделитель `;`, CRLF, BOM для Excel) | Тот же формат для результатов |

### 1.2. Как Telegram поддерживает опросы (проверено 25.11.2026)

**Предпосылка задачи устарела.** Раньше `sendPoll` не принимал картинку. С **Bot API 10.0 (8 мая 2026)** у `sendPoll` есть `media` (`InputPollMedia`, в том числе `InputMediaPhoto`) и `description` до 1 024 символов, у вариантов — `media` (`InputPollOptionMedia`). Ещё есть `allows_revoting`, `hide_results_until_closes`, `close_date` (не дальше 2 628 000 с ≈ 30 дней). Источник — https://core.telegram.org/bots/api#sendpoll и журнал изменений Bot API. Перед реализацией перепроверить ещё раз, а также узнать, умеет ли SDK `irazasyed` передавать эти поля. Скорее всего, понадобится сырой вызов с `attach://` для загрузки.

Ограничения, которые остались:
- в личном чате каждый ученик получает **свой отдельный опрос** со своим `poll_id`. Ответ приходит обновлением `poll_answer` (`poll_id`, `user`, `option_ids`) только по неанонимному опросу, который отправил сам бот;
- результаты, которые видит ученик, — это результаты **его копии опроса**. После ответа он видит «100 % — 1 голос». `hide_results_until_closes` только откладывает это до закрытия;
- вопрос — до 300 символов, вариант — до 100, вариантов от 1 до 12.

### 1.3. Варианты и решение

| | A. Фото с подписью + inline-кнопки, свой учёт | B. Нативный `sendPoll` (теперь с `media`) |
|---|---|---|
| Картинка и вопрос в одном сообщении | да (подпись до 1 024 символов) | да (с Bot API 10.0) |
| Что ученик видит после ответа | наш текст «Спасибо! Ваш ответ: …» | «100 %, 1 голос» своей копии — путает |
| Смена ответа, один ответ на ученика | под нашим контролем (блокировка строки) | `allows_revoting`; «один ответ» обеспечивает Telegram |
| Закрытие | проверка на сервере при нажатии; кнопки убираются лениво | `close_date` при отправке (≤ 30 дней) или `stopPoll` по каждому сообщению (N вызовов) |
| Новая инфраструктура | нет: те же `callback_query`, что у квиза-напоминания | новый тип обновления `poll_answer`, смена `allowed_updates` (`setWebhook` заново), сопоставление `poll_id → доставка`, новый метод SDK |
| Несколько ответов | своя клавиатура с отметками ☑ и кнопкой «Отправить» | нативно |
| Тестируемость | полностью на фейковом клиенте | без живого бота почти не проверить |

**Решение: вариант A.** Нативный опрос больше не проигрывает в картинке, но проигрывает в главном. Ученик видит бессмысленные «100 %». Нужна новая ветка обновлений, а живое поведение не проверить тестами. Вариант A повторяет уже работающий в проде квиз-напоминание: тот же разбор кнопок, та же защита, тот же способ редактировать сообщение.

Отвергнуто: B — см. таблицу. «Ответ ссылкой в Mini App» — лишний переход, отвечают меньше. Анонимный нативный опрос — нет учёта голосов на сервере.

### 1.4. Модель данных

Новые модели — в `app/Models`, сервисы — в новом домене `App\Domain\Polls\Services`, обработчик кнопок — в `App\Domain\Telegram\Handlers`. У всех миграций есть `down()`.

**`polls`**

| Колонка | Смысл |
|---|---|
| `id`, `title varchar(120)` | внутреннее название для `/admin` |
| `question jsonb` | `{ru, uz}`, оба обязательны, каждое ≤ 700 символов (остаток подписи 1 024 уходит под список вариантов и запас) |
| `image_path varchar(255) null` | файл на приватном диске `local`, каталог `polls/` |
| `image_file_id varchar(255) null` | `file_id` Telegram после первой удачной отправки. Дальше фото отправляется по нему, без повторной загрузки |
| `allows_multiple bool default false`, `max_choices smallint null` | один или несколько ответов |
| `allow_change bool default true` | можно ли поменять ответ до закрытия |
| `audience jsonb` | `{plan: all\|free\|pro, locales: [], course_ids: [], region_codes: [], onboarded_only: true}` (регионы — после R1) |
| `status varchar(10)` | `draft` → `sending` → `sent` → `closed`; `cancelled` из `draft`/`sending` |
| `closes_at timestamp null` | срок. Пусто — до ручного закрытия |
| `launched_at`, `closed_at`, `created_by_user_id` (FK users `nullOnDelete`), `launched_by_user_id` | |
| `results_snapshot jsonb null` | итоги на момент закрытия: доли не меняются после окончательного удаления учеников |
| `timestamps` | |

**`poll_options`**: `id`, `poll_id` FK `cascadeOnDelete`, `position smallint` (0..9), `label jsonb {ru, uz}` (≤ 40 символов, чтобы кнопка не обрезалась на телефоне), `unique(poll_id, position)`.

**`poll_deliveries`** — одна строка на получателя:

| Колонка | Смысл |
|---|---|
| `id`, `poll_id` FK `cascadeOnDelete` | |
| `learner_id` FK `cascadeOnDelete`, **null у тестовой отправки** | |
| `telegram_user_id bigint` | куда отправлено. С ним сверяется `from.id` нажатия |
| `is_test bool default false` | тестовая отправка администратору: в итоги не входит |
| `token char(12)` unique | случайный токен кнопок (`Str::random`, 62¹² вариантов) |
| `locale varchar(5)` | язык, на котором ушло сообщение |
| `status varchar(10)` | `pending`, `sending`, `sent`, `skipped`, `forbidden`, `failed`, `unknown` |
| `message_id bigint null`, `sent_at`, `error_code varchar(40) null` | |
| `draft jsonb null` | отмеченные, но ещё не отправленные варианты (несколько ответов) |
| `answered_at`, `changed_count smallint default 0` | |

Индексы: `unique(poll_id, learner_id) WHERE learner_id IS NOT NULL` (частичный; есть и в PG, и в sqlite), `(poll_id, status)`.

**`poll_votes`**: `id`, `poll_id`, `poll_delivery_id` FK `cascadeOnDelete`, `poll_option_id` FK `cascadeOnDelete`, `created_at`. `unique(poll_delivery_id, poll_option_id)`, индекс `(poll_id, poll_option_id)` для подсчёта.

Почему счётчиков в `polls` нет: подсчёт `GROUP BY` по `(poll_id, status)` и `(poll_id, poll_option_id)` дешёвый, а счётчик в одной строке стал бы горячей точкой под рассылкой.

### 1.5. Сценарии и правила

**Создание (`/admin`, `PollResource`, только платформенный администратор — `PanelDataScope::isAdmin()`, в `/org` не регистрируется).**
- Форма: название; вопрос ru и uz; картинка (`FileUpload`, диск `local`, `image/jpeg` и `image/png`, ≤ 5 МБ; у Telegram для фото предел 10 МБ, сумма сторон ≤ 10 000); варианты от 2 до 10 (`Repeater`, ru и uz, ≤ 40 символов); «несколько ответов» и предел выбора; «можно поменять ответ»; срок; аудитория.
- **Предпросмотр:** подпись и кнопки так, как их увидит ученик, на обоих языках. Рядом число получателей по текущей аудитории (один `count` тем же запросом, что и при запуске).
- **Тестовая отправка себе.** Сообщение уходит на `users.telegram_user_id` администратора (привязка уже есть в `TelegramAccountLinkService`; без привязки кнопка неактивна и показывает подсказку). Создаётся строка доставки с `is_test = true`, и весь путь проверяется живьём: голос, смена ответа, закрытие. Первая тестовая отправка сохраняет `image_file_id`. **Запуск без хотя бы одной тестовой отправки запрещён.**
- После запуска вопрос, варианты и картинка не редактируются. Меняются только срок, кнопки «Закрыть сейчас» и «Остановить рассылку».

**Запуск (`PollLauncher::launch(Poll, User)`).** Одна транзакция под `polls FOR UPDATE`, переход `draft → sending` (повторное нажатие ничего не делает). Затем одна вставка `INSERT INTO poll_deliveries … SELECT … FROM learners WHERE <аудитория> ON CONFLICT DO NOTHING`. Аудитория вычисляется один раз: кто зарегистрировался позже, опрос не получит. Фильтры:
- `deleted_at IS NULL`, `blocked_at IS NULL`, `dm_blocked_at IS NULL`; при `onboarded_only` — `onboarding_state = 'done'`;
- тариф — через `PaidSubscriptionQuery` (Pro = своя подписка или место в организации, пробный Pro тоже Pro, как решено в В4 docs/31);
- язык — `interface_locale`; курс — `active_course_id`; регион — `region_code` (после R1).

Язык сообщения — `interface_locale` ученика на момент вставки.

~~После commit задания `SendPollBatchJob` ставятся пачками по `polls.batch_size` (200) id доставок.~~ **Сделано иначе (O3):** вставка — диапазонами id учеников, каждый диапазон своей короткой транзакцией; после неё стартуют `polls.workers` (2) цепочек `SendPollBatchJob(pollId)` — задание несёт только id опроса и само арендует следующую пачку (§1.6а).

**Рассылка (`PollDeliverySender::sendBatch`, по образцу `LeagueResultSender::sendBatch`).**
- Перед каждым сообщением проверяется статус опроса: `cancelled` — пачка завершается. Ученик успел стать недоступным — `skipped`.
- Захват строки: `UPDATE poll_deliveries SET status='sending' WHERE id=? AND status='pending'` (+ опрос ещё `sending`, ученик ещё доступен — в том же операторе). Не одна строка — уже кто-то отправил. Перед этим пачка **арендуется** (`claimed_at`, `FOR UPDATE SKIP LOCKED`, §1.6а). **Отправка не больше одного раза:** упавшее после отправки задание оставляет `sending`. Через 15 минут команда `polls:sweep` переводит такие строки в `unknown`, повторно не шлёт. Дубль опроса хуже, чем недошедший.
- Слот бюджета: ~~`awaitGlobal`, плюс собственный потолок опросов `polls.max_per_second` (15 из 25)~~ **`reserveGlobalBelow(POLLS_SEND_PER_SECOND)`** — рассылка берёт слот, только пока весь бот в эту секунду отправил меньше 15, и встаёт совсем, пока кому-то из обычных отправителей отказывают (§1.6а). 10 тыс. учеников — около 11 минут при простое бота.
- 403 и `chat not found` → `forbidden` + `DmReachability::markUnreachable`. 429 → строка возвращается в `pending`, остаток пачки уходит новым заданием с задержкой `retry_after`. Сейчас клиент 429 не различает: нужен `TelegramRateLimitedException` (задача O1). Прочие ошибки → `failed` + `report()`.
- Срок рассылки — 24 часа от запуска (`polls.broadcast_ttl_hours`): после него отправитель не шлёт, `polls:sweep` делает остаток `skipped` (`expired`). Когда в опросе не осталось `pending` и `sending` (тестовые не в счёт) и вставка закончена (`recipient_count`), он переходит `sending → sent`.

**Сообщение.** С картинкой — `sendPhoto` с подписью, без картинки — `sendMessage`. Текст без `parse_mode`: вставить разметку через вопрос нельзя. Подпись: вопрос, а если какой-то вариант длиннее 24 символов — нумерованный список вариантов (кнопки тогда «1», «2», …). Кнопки — по одной в ряд.

**Кнопки и защита `callback_data`.** Формат `pl1:<token>:<n>`, где `n` — позиция варианта `0..9`, `s` — «Отправить» (несколько ответов), `c` — «Изменить ответ». Это до 19 байт из 64. Как и в `ReminderQuizCallback`, данные **не подписаны и ничего не утверждают**:
- строгое регулярное выражение `~^pl1:([A-Za-z0-9]{12}):([0-9]|s|c)$~D`;
- чат личный, `message.chat.id = from.id`, `from.id = poll_deliveries.telegram_user_id` найденной по токену строки. Пересланное сообщение или чужое нажатие → «Опрос недоступен»;
- позиция существует у этого опроса; `n` ≥ числа вариантов → то же.

**Голос (`PollVoteService::tap(token, fromId, action, now)`).** Одна транзакция, `poll_deliveries FOR UPDATE` по токену:
- опрос `closed`/`cancelled` **или** `closes_at ≤ now` (проверка на сервере, не ждём команду закрытия) → итог `Closed`. Обработчик показывает алерт «Опрос завершён» и убирает клавиатуру;
- один ответ: нажатие варианта при `answered_at IS NULL` или в режиме смены → голоса доставки заменяются (`DELETE` + `INSERT`), `answered_at` ставится, `changed_count +1` при смене. Повторное нажатие того же варианта даёт тот же итог (`repeated`) и ничего не пишет: так лечится потерянная правка сообщения;
- несколько ответов: нажатие варианта переключает его в `draft` (с учётом `max_choices`), обработчик меняет только клавиатуру (☐/☑). «Отправить» при пустом `draft` → тост «Выберите хотя бы один вариант», иначе `draft` переносится в голоса;
- «Изменить ответ» (при `allow_change` и открытом опросе) → клавиатура вариантов возвращается, `draft` = текущие голоса;
- итог — DTO `PollTapResult {status: Voted|Toggled|Repeated|Closed|Unavailable, selectedOptionIds, allowChange}`.

Ответ сервера — **не** ответ на вопрос урока: в `learner_daily_stats`, очки и серию ничего не пишется. Событие продукта — `poll.answered` (`poll_id`, `changed`).

**После ответа** подпись (или текст) редактируется: исходный вопрос + ~~«✅ Спасибо! Ваш ответ: Да»~~ **только «✅ Спасибо!»** (решение владельца, docs/31 §5г) на языке доставки, ниже кнопка «Изменить ответ», если смена разрешена. Нужен `editMessageCaption` (задача O1). Отказ Telegram при правке (`message is not modified`, слишком старое сообщение) только логируется, как в `ReminderQuizCallbackHandler::edit`.

**Закрытие.** Команда `polls:close-due` раз в 5 минут закрывает опросы с наступившим `closes_at`; есть кнопка «Закрыть сейчас». При закрытии пишется `results_snapshot`. Клавиатуры у всех учеников массово **не** правятся: это N правок под тем же бюджетом. Устаревшая кнопка при нажатии получает алерт и убирается.

**Результаты (страница опроса в `/admin`).**
- Шапка: получателей, отправлено, пропущено, заблокировали бота, ошибок, ответили (доля ответивших от отправленных).
- По каждому варианту — число голосов и доля **от ответивших**. При нескольких ответах сумма долей больше 100 %, это подписано.
- Разрезы: язык, Free/Pro, регион (после R1). Тестовые доставки не считаются.
- CSV (`PollResultsCsv`, формат `OrgActivityReportCsv`): сводка по вариантам и, отдельной кнопкой, строки ответов `delivery_id, learner_id, язык, тариф, регион, варианты, время`. **Без имён и username** (вопрос О2).

**Приватность.** Ученикам результаты не показываются (вопрос О1). Ответы видит только платформенный администратор. Удаление ученика каскадом удаляет его доставку и голос; итоги закрытого опроса сохранены в `results_snapshot`. Хранение доставок и голосов — 365 дней после закрытия, потом `polls:prune` (вопрос О2).

### 1.6. Интерфейсы

```php
// App\Domain\Telegram\Contracts\TelegramClient — новые методы (старые не меняются)
public function sendPhotoMessage(int|string $chatId, PhotoSource $photo, ?string $caption = null, ?array $replyMarkup = null): SentMessage; // PhotoSource::file($path) | PhotoSource::fileId($id)
public function sendTextMessage(int|string $chatId, string $text, ?array $replyMarkup = null): SentMessage;
public function editMessageCaption(int|string $chatId, int $messageId, string $caption, ?array $replyMarkup = null): void;
// SentMessage { int $messageId; ?string $photoFileId /* наибольший PhotoSize */ }
// 429 → TelegramRateLimitedException(int $retryAfter) extends TelegramApiException

App\Domain\Polls\Services\PollAudienceQuery::for(array $audience): Builder<Learner>
App\Domain\Polls\Services\PollLauncher::launch(Poll, User): bool; ::cancel(Poll, User): bool; ::close(Poll, ?User): bool   // O3: bool = эта ли попытка перевела
App\Domain\Polls\Services\PollTestSender::send(Poll, User, string $locale = 'ru'): PollDelivery
App\Domain\Polls\Services\PollDeliverySender::sendBatch(int $pollId, int $budgetSeconds): PollBatchOutcome   // O3: аренда пачки вместо списка id
App\Domain\Polls\Services\PollVoteService::tap(string $token, int $fromId, string $action, CarbonImmutable $now): PollTapResult
App\Domain\Polls\Services\PollResults::summary(Poll, array $filters = []): PollSummary
App\Domain\Telegram\Services\PollMessenger   // подпись, клавиатуры, тексты «Спасибо»/«Завершён» на ru/uz
App\Domain\Telegram\Handlers\PollCallbackHandler, App\Domain\Telegram\Support\PollCallback
App\Jobs\SendPollBatchJob; команды polls:close-due, polls:sweep, polls:prune
```

Запись в `audit_logs`: запуск, остановка, закрытие, выгрузка CSV с ответами.

### 1.6а. Сделано в O3 и O4 (architect, 27.11.2026): решения и контракт для O5–O7

**Схема** (миграция `2026_11_27_100005`, docs/03 §2.72, §2.74): `poll_deliveries.claimed_at` (аренда / момент шага к `sending`), `polls.recipient_count` (вставка закончена; «получателей» без подсчёта). Новых индексов нет.

**Решения и почему.**
1. **Захват — аренда пачки + условный шаг на строку.** Пачка (`polls.batch_size` = 100) берётся одним `WITH c AS (SELECT id … WHERE poll_id=? AND status='pending' AND (claimed_at IS NULL OR claimed_at < now−lease) ORDER BY id LIMIT ? FOR UPDATE SKIP LOCKED) UPDATE … SET claimed_at RETURNING …` по `poll_deliveries_unsent_idx`. Аренда — это `pending` + `claimed_at`: сообщение ещё не уходило, поэтому упавший между захватом и отправкой воркер ничего не теряет — через `polls.lease_seconds` (300 с) строки берёт другой. Единственность отправки решает не аренда, а `UPDATE … SET status='sending' WHERE id=? AND status='pending' AND <опрос sending> AND <ученик доступен>` прямо перед вызовом Telegram: даже если аренды пересеклись (воркер завис дольше аренды), строку отправит только тот, чей UPDATE изменил её. Упал после этого шага — строка остаётся `sending`, `polls:sweep` через 15 минут делает её `unknown`, повторно не шлёт. Отвергнуто: сразу `sending` для всей пачки (падение превращало бы 100 неотправленных строк в `unknown`); список id в задании (200 заданий в очереди на 40 тыс., устаревшие id, лишние `release`).
2. **Задание несёт только id опроса** (цепочка: пачка → преемник), `polls.workers` = 2 цепочки на очереди `low`. Лишняя цепочка (повтор задания, `polls:sweep`) безвредна по п. 1.
3. **Доля бюджета бота — приоритет, а не только потолок.** `TelegramMessageRateLimiter::reserveGlobalBelow(15)`: слот только пока общий счётчик секунды (все фоновые отправки + учтённые ответы) < 15 — проверка и `INCR` одним Lua, поэтому несколько воркеров рассылки не перешагнут потолок; плюс рассылка полностью встаёт на 2 с после любого отказа обычному отправителю (`telegram:outbound:contended`, ставит `reserveGlobal()`). Обычным всегда остаётся ≥ 10/с, под их нагрузкой (напоминания 19:00–21:00) — все 25. Отвергнуто: только собственный потолок 15/с поверх `awaitGlobal` (в волну напоминаний те получали бы 10/с вместо 25); отдельная очередь с приоритетом (воркер не знает о бюджете Telegram).
4. **Запуск идемпотентен и возобновляем.** Под `polls FOR UPDATE` `draft → sending`; вставка — диапазонами id по 2 000, каждый своей транзакцией под `polls FOR SHARE` (остановка ждёт текущий диапазон, следующий видит её), `ON CONFLICT (poll_id, learner_id) DO NOTHING`, только `created_at ≤ launched_at`. Токен — `Str::random(12)` в PHP (CSPRNG; переносимого сильного генератора строки в SQL нет), поэтому `INSERT … VALUES` вместо `INSERT … SELECT`: столкновение токена (~10⁻¹³) падает громко, а не теряет получателя. Упал посреди вставки — `recipient_count` пуст, `polls:sweep` через 10 минут продолжает.
5. **Токен** — 12 символов `[A-Za-z0-9]` (≈ 2⁷¹), но он только **находит** доставку: право голоса даёт `from.id` (подлинность — секрет вебхука), он обязан совпасть с `telegram_user_id`. Чужой токен ничего не даёт; неизвестный, чужой, несуществующий вариант, неотправленная доставка — один и тот же `Unavailable` после одного и того же оператора (нет оракула). Формат проверяется до запроса.
6. **Голос** — `SELECT … FOR UPDATE OF d` (строка опроса не блокируется: нажатия разных учеников не ждут друг друга); записанный ответ читается **отдельным** оператором после блокировки: подзапрос того же SELECT видел бы снимок до ожидания (так терялся голос в гонке — найдено тестом на PG).

**Бюджеты операторов** (закреплены тестами; стенд PG 16: 52 тыс. учеников, опрос на 40 тыс., 30 тыс. голосов, ещё 120 тыс. доставок старых опросов):

| Путь | Операторов | Стенд, `EXPLAIN (ANALYZE, BUFFERS)` |
|---|---|---|
| Нажатие: недоступно / закрыто | 1 | `poll_deliveries_token_unique`, 10 буферов, 0,09 мс |
| Первый ответ (один вариант) | 3 | + INSERT голоса, UPDATE доставки; ≈ 2 мс всего |
| Повтор того же / «Изменить ответ» | 2 | голоса — index only по `(poll_delivery_id, poll_option_id)`, 3 буфера |
| Смена ответа | 5 | + DELETE по тому же unique |
| Переключение (несколько ответов) | 2 (3 после ответа) | |
| Пачка рассылки из n | 3 + 2n (+ освобождение, + завершение) | аренда: Index Scan `poll_deliveries_unsent_idx` (`poll_id = ?`) + LockRows + Limit, **без сортировки**; 260–500 буферов при 18 тыс. мёртвых записей индекса, 2–5 мс; шаг к `sending` и итог — по `pkey`, 46 и 24 буфера, 0,3 мс; на стенде 100 сообщений = 203 оператора, ≈ 1,8 мс БД на сообщение |
| Завершение `sending → sent` | 1 | Bitmap по `poll_deliveries_unsent_idx`, 80 буферов |
| Предпросмотр аудитории | 1 | «все»: Seq Scan `learners` 913 буферов, 22 мс (весь список и так нужен); Pro: тот же + две хэшированные подвыборки подписок, 36 мс; язык + курс: Bitmap по `learners_active_course_id_index` |
| Запуск | 3 на диапазон 2 000 id + 6 | диапазон: `learners_pkey` по диапазону, подвыборки Pro сужены диапазоном (`subscriptions (subscriber_type, subscriber_id, status)`), 124 буфера; весь запуск 44 тыс. — 88 операторов, 1,6 с; Pro 6 тыс. — 0,4 с |
| Итоги (`PollResults::totals`) | 3 | статусы: Bitmap по `(poll_id, learner_id)`, 1 092 буфера, 14 мс; голоса — 252 буфера, 8 мс |
| `polls:sweep` | 1 + 2 на опрос `sending` | зависшие: Bitmap по частичному индексу без ключа (только неотправленные строки) |

**Контракт для O5–O7.**
- O5 (`PollCallbackHandler`): разобрать `pl1:<token>:<n|s|c>`, проверить личный чат (`chat.id = from.id`), `InaccessibleMessage`; затем `PollVoteService::tap($token, $fromId, $action, $now): PollTapResult` (`status`: `Voted`, `Toggled`, `Repeated`, `Changing`, `EmptySubmit`, `LimitReached`, `Locked`, `Closed`, `Unavailable`; `selectedOptionIds`, `allowChange`, `allowsMultiple`, `changed`, `pollId`, `deliveryId`, `locale`, `isTest`). Подпись/клавиатура — `PollMessenger::text()` / `keyboard($poll, $options, $token, $locale, $chatId, $selected)` (☐/☑, «Отправить» — `polls.submit`); тексты «Спасибо», «Опрос завершён», «Опрос недоступен», «Выберите хотя бы один вариант», «Можно выбрать не больше N», «Ответ уже принят», «Изменить ответ» — в `lang/{ru,uz}/polls.php` (ключ `submit` уже есть). После ответа — только «Спасибо» (docs/31 §5г). Событие `poll.answered` (`poll_id`, `changed`) — при `Voted` и `isTest = false` (O7).
- O6 (`PollResource`): предпросмотр числа — `PollAudienceQuery::count($audience)`; тестовая отправка — `PollTestSender::send($poll, $admin, $locale): PollDelivery` (статус в строке; `PollLaunchException` с `reason`: `no_telegram`, `finished`, `options`, `too_long`); запуск — `PollLauncher::launch($poll, $admin): bool` (false = уже запущен; `PollLaunchException`: `untested`, `options`, `too_long`); остановка — `cancel($poll, $admin): bool`; «Закрыть сейчас» — `close($poll, $admin): bool`. Аудит пишут сами методы (`poll.launched`, `poll.cancelled`, `poll.closed`). После запуска форма не меняет вопрос, варианты, картинку, `allows_multiple`, `max_choices`, `allow_change`, `audience` (рассылка читает их в каждой пачке). Статус рассылки — `recipient_count` и `GROUP BY status` (`PollResults::totals`).
- O7: `polls:close-due` — `PollLauncher::close($poll, null)` по `polls_close_due_idx`; страница итогов — поверх `PollResults::totals()` (тестовые исключены по `learner_id IS NULL`); `polls:prune`. `polls:sweep` уже в расписании (каждые 5 минут).

### 1.7. Задачи

| # | Задача | Кто | Зависит от | Definition of Done и ключевые тесты |
|---|---|---|---|---|
| O0 | docs/03 (четыре таблицы), docs/04 (раздел бота: `pl1`, тексты), ADMIN_GUIDE (как провести опрос) | коорд. | — | docs согласованы до кода |
| O1 | Клиент: `sendPhotoMessage`, `sendTextMessage`, `editMessageCaption`, `SentMessage`, `PhotoSource`, `TelegramRateLimitedException`; фейковый клиент тестов. **Делать вместе с K3** (docs/31: «отправка с возвратом номера сообщения») | C | — | `file_id` берётся из наибольшего `PhotoSize`; отправка по `file_id` без файла; 403 и `chat not found` → Forbidden; 429 → `retryAfter`; старый `sendPhoto` не изменился |
| O2 | Миграции и модели `polls`, `poll_options`, `poll_deliveries`, `poll_votes`, фабрики | C | O0 | migrate/rollback на PG и sqlite; частичный unique работает на обеих |
| O3 ✅ | **Ядро рассылки:** `PollAudienceQuery`, `PollLauncher` (запуск, остановка, закрытие, снимок), `PollDeliverySender`, `SendPollBatchJob`, `polls:sweep` | **A** | O1, O2 | двойной запуск — одна вставка; аудитория (тариф, язык, курс, заблокированные, удалённые, не прошедшие онбординг); захват строки двумя воркерами — одна отправка; падение после отправки → `unknown`, без повтора; 403 → `dm_blocked_at`; 429 → `pending` и новое задание с задержкой; остановка посреди пачки; `sending → sent` |
| O4 ✅ | **Ядро голоса:** `PollVoteService`, `PollTapResult` | **A** | O2 | один ответ, повтор того же варианта (`repeated`, без записи), смена при `allow_change` и запрет без него, `max_choices`, «Отправить» с пустым выбором, закрытие по `closes_at` до запуска команды, чужой `from.id`, неизвестный токен, гонка двух нажатий на двух соединениях PG — одна запись |
| O5 | `PollCallback`, `PollCallbackHandler`, `PollMessenger`, регистрация в `TelegramDispatcher`; тексты `lang/{ru,uz}/polls.php` | C | O1, O4 | пересланное сообщение и группа игнорируются; `InaccessibleMessage` (`date = 0`) → алерт; отказ правки только логируется; подпись не длиннее 1 024 символов на обоих языках; нумерация при длинных вариантах |
| O6 | `PollResource`: форма, предпросмотр с числом получателей, тестовая отправка, запуск с подтверждением, остановка, закрытие, статус рассылки | C | O3, O5 | только админ (тест на `/org` и на роль); правка после запуска запрещена; запуск без тестовой отправки запрещён; тест бюджета запросов списка (docs/20 §1) |
| O7 | Страница результатов, разрезы, `PollResultsCsv`, `polls:close-due`, `polls:prune`, событие `poll.answered` | C | O3, O4 | доли от ответивших; тестовые не входят; CSV открывается в Excel ru/uz; закрытие идемпотентно; бюджет запросов страницы |
| O8 | Фильтр аудитории по региону | C | O3, R1 | `region_codes` и «Другое» |
| O9 | Ревью раздела + проверка на живом боте (фото по `file_id`, правка подписи, 429 на тестовой рассылке ~200 сообщений) | reviewer, коорд. | всё | — |

### 1.8. Вопросы владельцу

| № | Вопрос | Рекомендация |
|---|---|---|
| О1 | Показывать ли ученику общие итоги после ответа | Нет. Если захочется — отдельным сообщением после закрытия опроса |
| О2 | Нужны ли ответы по конкретным людям и сколько их хранить | Сводка + строки по внутреннему id ученика без имён; хранить год после закрытия. В тексте опроса писать «ответы видит только команда LexiFlow» |

### 1.9. Риски

- **Бюджет отправки.** Рассылка делит 25 сообщений в секунду с напоминаниями и итогами недели. Не запускать в часы напоминаний (19:00–21:00); потолок `polls.max_per_second` обязателен.
- **Массовые блокировки бота.** Опрос — самый «рекламный» тип сообщения. Следить за числом `forbidden` по итогам первой рассылки; первая — на часть аудитории.
- **Длина подписи** на узбекском выходит длиннее русской. Проверять в форме по обоим языкам.
- **SDK.** Новые методы клиента опираются на ответ SDK (объект сообщения). Проверить на живом боте до O3.

---

## 2. Регион ученика и региональный рейтинг

### 2.1. Что есть в коде

- **Региона нет нигде** (ни колонки, ни конфига).
- **Онбординг — только в боте:** `OnboardingService` (шаги `locale → goal → level → reminder`; шаг `goal` пропускается, пока цель одна), состояние в `learners.onboarding_state`, ответы только на текущий шаг под блокировкой строки, кнопки — `OnboardingCallbackHandler` (`onb1`). Mini App онбординг не проводит: «Сегодня» показывает подсказку «отправьте боту /start» (`TodayView`, `onboarding_completed`). `LearnerRegistrar` только создаёт строку (`onboarding_state = new`).
- **Настройки:** `LearnerSettingsService::update` (бот `/settings` через `OnboardingService::applySetting`, Mini App `PATCH /api/twa/me/settings`). Готовый образец защиты от частой смены — часовой пояс: `TIMEZONE_CHANGE_INTERVAL_HOURS`, `TimezoneChangeTooSoonException`, отмена в течение 10 минут.
- **Рейтинги сейчас:** недельная лига — группы по ~30 человек (`league_groups`, `LeagueService`); месяц — общая таблица `league_month_scores` (`MonthlyRatingService`, `GET /api/twa/league/month`). По docs/32 появятся категории Free/Pro для недели и месяца, «За всё время» (`learner_score_totals`), конкурентный ранг и чтение страниц по ключу (§5.3). Ничего из этого ещё не написано.
- **`/admin`:** `LearnerResource` с фильтрами (группа, тариф, онбординг), панель управления с виджетами (`WidgetCache`).

### 2.2. Уровень детализации

**Решение: 14 единиц первого уровня** (12 областей, Республика Каракалпакстан, город Ташкент) с кодами ISO 3166-2:UZ, плюс `other`. Город и область Ташкент — разные единицы: так в ISO и в административном делении, и так понятнее ученикам.

Районы (около 200) **не делаем на старте.** При 100 учениках в Ташкенте на район придётся по 5–10 человек, таблицы будут пустыми. Можно добавить позже вторым уровнем (`learners.district_code`), не ломая первый (вопрос Р1).

| Код | ru | uz (латиница) | en |
|---|---|---|---|
| `UZ-TK` | город Ташкент | Toshkent shahri | Tashkent City |
| `UZ-TO` | Ташкентская область | Toshkent viloyati | Tashkent Region |
| `UZ-AN` | Андижанская область | Andijon viloyati | Andijan Region |
| `UZ-BU` | Бухарская область | Buxoro viloyati | Bukhara Region |
| `UZ-FA` | Ферганская область | Farg'ona viloyati | Fergana Region |
| `UZ-JI` | Джизакская область | Jizzax viloyati | Jizzakh Region |
| `UZ-NG` | Наманганская область | Namangan viloyati | Namangan Region |
| `UZ-NW` | Навоийская область | Navoiy viloyati | Navoiy Region |
| `UZ-QA` | Кашкадарьинская область | Qashqadaryo viloyati | Kashkadarya Region |
| `UZ-QR` | Республика Каракалпакстан | Qoraqalpog'iston Respublikasi | Republic of Karakalpakstan |
| `UZ-SA` | Самаркандская область | Samarqand viloyati | Samarkand Region |
| `UZ-SI` | Сырдарьинская область | Sirdaryo viloyati | Syrdarya Region |
| `UZ-SU` | Сурхандарьинская область | Surxondaryo viloyati | Surkhandarya Region |
| `UZ-XO` | Хорезмская область | Xorazm viloyati | Khorezm Region |
| `other` | Другая страна | Boshqa davlat | Outside Uzbekistan |

Коды сверены со списком ISO 3166-2:UZ. Перед миграцией ещё раз проверить по актуальной редакции ISO. Узбекские названия передать носителю вместе с остальными строками (docs/I18N_UZ_REVIEW.md).

**«Другое» — без списка стран.** Аудитория — Узбекистан. Список из 200 стран на двух языках — лишний перевод и лишний экран, а таблица «Казахстан: 2 ученика» никому не нужна. Все, кто вне Узбекистана, — одна единица `other` со своей таблицей «За пределами Узбекистана» (там же действует порог участников из §2.4). Если позже понадобятся страны, добавится `learners.country_code`, код `other` останется.

### 2.3. Хранение, выбор, смена

- **Справочник — в конфиге, не в таблице:** `config/regions.php` (порядок показа: сначала город Ташкент, затем по алфавиту языка — порядок задаётся отдельно для ru и uz), названия — `lang/{ru,uz}/regions.php`, английские — в конфиге для CSV. Список стабилен, в `/admin` его не редактируют; кода в базе достаточно. Класс `App\Domain\Learners\Support\Regions` (`codes()`, `isValid()`, `name($code, $locale)`, `ordered($locale)`).
- **`learners`:** `region_code varchar(8) null` (null — не указан или «не хочу указывать»), `region_changed_at timestamp null`, индекс `(region_code)`. Ограничение `CHECK` не ставим: список живёт в конфиге, проверяет сервис.
- **`LearnerRegionService::set(Learner, ?string $code, CarbonImmutable $now)`** (домен Learners), под блокировкой строки ученика (`LearnerRowLock`):
  - первое указание (было null) — сразу, без ограничения;
  - **смена — не чаще раза в 30 дней** (`learners.region_change_days`), иначе `RegionChangeTooSoonException($availableAt)` → 422 `region_change_too_soon` с датой. В течение 10 минут после смены — отмена без ограничения, как у часового пояса (защита от промаха пальцем);
  - сброс в null («не хочу указывать») разрешён всегда, но следующее указание после сброса подчиняется тем же 30 дням от прошлой смены (иначе сброс обходит ограничение);
  - после commit — хук рейтинга (§2.4) и событие продукта `region.set` (`{from, to, via: onboarding|settings|prompt}`).

**Шаг в онбординге бота** — сразу после языка: «в начале регистрации», и названия уже на языке ученика. Новый порядок: `locale → region → goal → level → reminder`.
- Экран 1: «Где вы живёте? Это нужно для рейтинга вашего региона» — кнопки «🇺🇿 Узбекистан», «🌍 Другая страна», «Не указывать».
- «Узбекистан» шаг не завершает: бот правит то же сообщение и показывает 14 регионов в два столбца и «← Назад». Состояние остаётся `region`, значение `uz` — это переход экрана, а не ответ. Так сохраняется правило «ответ только на текущий шаг», а старая кнопка экрана 1 после выбора региона получает `staleStep`.
- Регион, «Другая страна» и «Не указывать» завершают шаг. `OnboardingService::changes('region', …)` проверяет код по `Regions::isValid`.
- Ученики, которые сейчас посреди онбординга, на шаг не возвращаются: их состояние уже дальше. Их, как и старых, ждёт ненавязчивый запрос.

**Настройки:** бот `/settings` → «Регион»; Mini App → `SettingsView`, строка «Регион» с листом выбора (те же два уровня). Ответ на запрет смены — «Сменить регион можно с 24 декабря».

**Ученики без региона** (все нынешние и «Не указывать»): никакой рассылки. На вкладке «Лига» в панелях «Месяц» и «За всё время» — карточка «Укажите регион — появится рейтинг вашего региона» с кнопкой выбора. После «Не сейчас» она скрывается на 30 дней (`learners.meta.region_prompt_dismissed_at`). Владелец может спросить регион у старых учеников опросом (функция 1), но ответ опроса регион **не записывает** — так проще и честнее.

### 2.4. Региональный рейтинг

**Что это.** Фильтр поверх тех же таблиц docs/32: те же очки v2, те же правила, те же категории Free/Pro, тот же порядок и конкурентный ранг. Своих очков, лимитов и закрытий у региона нет.

**Для каких периодов.**
- **Месяц и «За всё время» — да.**
- **Неделя — нет.** Недельная таблица — это группа лиги из ~30 человек (docs/32 §5.1), а не общий список. Региональная неделя потребовала бы отдельный подсчёт «5 лучших дней» по всем ученикам региона и не добавила бы смысла.

**По какому региону считается.**
- **Месяц — по региону, зафиксированному при первом начислении месяца**, ровно как категория. В `learner_score_periods` (docs/32 §2.3) добавляется `region_code varchar(8) null`, его пишет `ScorePeriodService::fix()` из `learners.region_code`. Отсюда `league_month_scores.region_code` (через `MonthScoreSourceV2`). Сменил регион посреди месяца — новый регион действует со следующего месяца, набранные очки остаются в старом. Так нельзя перебежать в слабый регион за день до конца месяца.
  - Исключение — **первое указание** (было null): `LearnerRegionService` после commit проставляет регион в открытые периоды ученика, где он пустой, и вызывает `refreshLearner` открытого месяца. Иначе все нынешние ученики увидели бы свой регион только через месяц.
- **«За всё время» — по текущему региону.** Это сумма за всю историю, «люди из региона». Перебежки ограничены 30 днями. `learner_score_totals.region_code` денормализуется: пересчёт строки ученика (docs/32 §5.2) уже идёт целиком из источника и берёт регион из `learners`, а `LearnerRegionService` после смены вызывает этот пересчёт.

**Порог показа.** Региональная таблица категории показывается, если в ней **не меньше 5 участников** (`leagues.regions.min_participants`). Меньше — «В вашем регионе пока 3 участника Pro. Пригласите друзей» и ссылка-приглашение (`ReferralService::inviteLink`). Порог защищает и от пустых таблиц, и от того, что в таблице из двух человек каждый знает соседа.

**Место в регионе рядом с общим.** В `LeagueBoardResource.me` (docs/32 §8.3) добавляется `region: {code, name, rank, participants, shown}`. `rank` — один `count` «строго лучших» в той же категории и регионе, кэш 60 с, как у общего места. На карточке: «№ 12 в Ташкенте · № 340 в Узбекистане и мире» (точную формулировку утвердить в макете).

**API.**
- `GET /api/twa/league/month?category=free|pro&scope=all|region&after=…`;
- `GET /api/twa/league/all-time?…&scope=region`.

~~Регион не передаётся параметром; чужие регионы не листаются (вопрос Р3).~~ **Изменено решением владельца 25.11.2026 (docs/31 §5г): открыть можно таблицу любой области.** `scope=region` без параметра — своя область: из периода ученика (месяц) или текущая (за всё время); `&region=<код>` — любая область, только чтение (как чужая категория: `read_only`, без соседей). Без своей области `scope=region` без `region` → 422 `region_not_set`. Регион конкретного ученика вне таблицы области нигде не выводится. Сделано (R7) — docs/04 A.19, A.19a; решения и контракт для экранов R8 — docs/32 §12.11.

**Индексы и запросы.**
- `league_month_scores`: `region_code varchar(8) null` + индекс `(league_month_id, category, region_code, participant, points desc, long_words desc, active_days desc, learner_id)`. Это второй индекс рядом с общим из docs/32 §2.6; таблица — не больше учеников месяца.
- `learner_score_totals`: `region_code` + индекс `(category, region_code, participant, points desc, long_words desc, active_days desc, learner_id)`.
- Ранг, страница по ключу, соседи и отрыв — те же запросы docs/32 §5.3 с добавочным `AND region_code = ?`. Чтение реализуется **тем же классом** с необязательным фильтром региона, а не копией.
- Кэш верхов: `league:month:top:v2:{month}:{category}:r:{code}`, `league:alltime:top:v1:{category}:r:{code}` — массивы скаляров, TTL 120 с, сброс вместе с общими (docs/19 §3). Сделано так; плюс число участников, место и соседи области (`…:count:…:r:{code}`, `…:rank:…:r:{code}:{кортеж}`, `…:near:…`) — docs/19 §3.
- **Индексы — как сделано (R6):** partial `(league_month_id, category, region_code, points desc, long_words desc, active_days desc, learner_id) WHERE participant AND region_code IS NOT NULL` и `(category, region_code, points desc, …) WHERE participant AND region_code IS NOT NULL` — `participant` не ключ (его спрашивает каждое чтение), ученики без области и не-участники в индекс не пишут; замеры и цена записи — docs/32 §12.11.
- **Сброс области** («не указывать») убирает ученика из таблиц области «За всё время» сразу; зафиксированная область месяца остаётся (иначе сброс и новый выбор переносили бы месяц).

**Призы** — нет. Региональная таблица — мотивация, а не соревнование с призами: призы и проверка кандидатов остаются только в общих таблицах (вопрос Р2).

**Приватность.** Регион другого ученика нигде не выводится: ни в общих таблицах, ни в профиле, ни у друзей. Его можно вывести только из того, что вы видите человека в своей региональной таблице. `leagues_opt_out` скрывает и из региональных таблиц (тот же `participant = false`). В `/admin` регион виден.

**TWA.** На панелях «Месяц» и «За всё время» вкладки «Лига» (docs/32 §8.3) рядом с переключателем Free/Pro — переключатель «Все / Ташкент» (название своего региона). В профиле — строка «Регион: Ташкент · изменить».

**`/admin`.**
- `LearnerResource`: колонка и фильтр «Регион» (с вариантом «не указан»).
- Виджет на панели «Ученики по регионам»: число и доля прошедших онбординг, без удалённых. Один `GROUP BY` через `WidgetCache`.
- Аудитория опросов (O8).
- Тест бюджета запросов на изменённый список (docs/20 §1).

### 2.5. Задачи

| # | Задача | Кто | Зависит от | Definition of Done и ключевые тесты |
|---|---|---|---|---|
| R0 | docs/03 (колонки), docs/12 §7.1 (шаг региона), docs/04 (настройки, `scope`, ошибки), docs/32 §2.3, §2.6, §5, §8.3 (регион в периодах и таблицах) | коорд. | ответы Р1–Р3 | docs согласованы |
| R1 | `config/regions.php`, `lang/{ru,uz}/regions.php`, `Regions`; миграция `learners.region_code`, `region_changed_at`, индекс | C | R0 | 15 кодов, у каждого два названия; migrate/rollback |
| R2 | `LearnerRegionService::set` + отмена за 10 минут, `RegionChangeTooSoonException`, событие | C | R1 | первое указание без ограничения; смена через 29 дней — отказ, через 30 — да; сброс не обходит ограничение; отмена; неизвестный код — отказ |
| R3 | Онбординг бота: шаг `region` с двумя экранами, `/settings` → регион | C | R2 | старая кнопка экрана 1 после выбора → `staleStep`; «Не указывать» → null и шаг пройден; ученики посреди онбординга не возвращаются; узбекские подписи |
| R4 | Mini App: регион в `GET /me` и `PATCH /me/settings`, выбор в настройках, карточка-запрос на «Лиге» с «Не сейчас» | C | R2 | 422 с датой; карточка скрыта 30 дней; vitest, i18n ru/uz |
| R5 | `/admin`: колонка, фильтр, виджет распределения | C | R1 | бюджет запросов списка и панели |
| R6 ✅ | **Рейтинг:** `region_code` в `learner_score_periods`, `league_month_scores`, `learner_score_totals`, индексы; фиксация при первом начислении; дозапись при первом указании; пересчёт «За всё время» при смене; фильтр региона в чтении таблиц (ранг, ключ, соседи, отрыв). **Сделано 27.11.2026** — docs/32 §12.11 | **A** | S9, S10 docs/32 | смена посреди месяца не переносит очки; первое указание попадает в открытый месяц; ничьи и места внутри региона; удалённый и opt-out выпадают; один класс чтения для общего и регионального |
| R7 ✅ | API: `scope=region` (+ `region=<код>`), `me.region`, порог участников, ключи кэша. **Сделано 27.11.2026** (docs/04 A.19, A.19a) | C | R6 | порог 5 (4 → скрыто, 5 → видно); без региона — 422; ~~чужой регион запросить нельзя~~ чужой регион — только чтение (docs/31 §5г) |
| R8 | TWA: переключатель «Все / мой регион», место в регионе, пустое состояние с приглашением | C | R7, выпуск 1 | снимки vitest |
| R9 | Ревью | reviewer | R6–R8 | — |

### 2.6. Вопросы владельцу

| № | Вопрос | Рекомендация |
|---|---|---|
| Р1 | Области или районы | 14 областей и «Другое» сейчас; районы — вторым уровнем, когда в области будет больше 300 учеников |
| Р2 | Призы региональных таблиц | Нет. Призы остаются в общих таблицах Free и Pro |
| Р3 | Можно ли смотреть таблицы других регионов | ~~Нет, только своего.~~ **Решение владельца 25.11.2026: да, любой области** (docs/31 §5г); своя — с местом и соседями, чужая — только чтение |
| Р4 | Можно ли не указывать регион | Да, кнопка «Не указывать»; запрос на вкладке «Лига» раз в 30 дней |

### 2.7. Риски

- **Ложный регион.** Проверить его нельзя (геолокацию не спрашиваем). Ограничение 30 дней и фиксация на месяц снимают выгоду от перебежек, но не от первого ложного выбора. Призов нет — нет и мотива.
- **Маленькие таблицы Pro.** В большинстве регионов таблица Pro будет скрыта порогом. Это ожидаемо; подпись должна объяснять почему.
- **Порядок работ.** R6 зависит от ещё не написанных S9 и S10. Если делать региональную таблицу на старых правилах (v1), её придётся переписать. Поэтому R1–R5 идут раньше, R6–R8 — только после v2.

---

## 3. Раздел английских фраз

### 3.1. Что есть в коде

- **Контент:** курс → этап (модуль) → урок → слова. Пакеты в `database/data/courses/<slug>/` (`course.json` + `mNN-lN.json`), импорт — `CoursePackImporter` и `VocabularyImporter`. У слова есть: `word` (до 100 символов), `translations {ru, uz}`, `example` (до 2 000) с переводами, до 10 доп. примеров (`examples` → `word_examples`), `hooks` (подсказка-ассоциация по языкам), `confusable_with`, `part_of_speech` (белый список из 8 частей речи), `transcription`, `meta`. Процесс авторства, редактуры и проверочных скриптов — `docs/content-authoring` (план: «одна лемма a–z», 20 × 5 × 10).
- **Упражнения тренировки** (`ExerciseType`): `flash`, `meaning`, `reverse`, `audio`, `pretest` (выбор из 4), `spelling`, `gap_fill` (ввод, Pro). Отвлекающие варианты (`ExercisePromptBuilder::layers`) берутся из того же урока, затем из курса, затем из публичных курсов платформы. Ввод проверяет `SpellingGrader` (нормализация, допуск 0–2 правки по длине). Пропуск в примере строит `GapFillBuilder`: ищет слово или его правильную форму в примере.
- **Где можно учить:** урок любого доступного курса открывается как сессия урока (`TrainingScope::lesson`) без смены активного курса. Созревшие повторения **всех** изученных слов любых курсов приходят в ежедневную тренировку (`TrainingScope::daily`). Новые слова ежедневной тренировки — только из активного курса.
- **Практика и игра:** задания `collocation`, `sentence` (собери предложение из фрагментов), `dictation`, `word_form`, `error_hunt` (`practice_items`); игра «Караван» берёт слова ученика (`translate_fwd/back`, `listen`, `spell`, `gap`) и задания практики.
- **Озвучка** (docs/26): слово и якорный пример озвучиваются Google TTS по тексту. Фраза до 100 символов озвучивается тем же конвейером без изменений.
- **Каталог `lexemes`** (docs/32 §2.1, ещё не написан): ключ — нормализованное написание. «A piece of cake» → `piece of cake`, «How's it going?» → `how's it going`. Фраза платформенного курса автоматически станет лексемой и будет давать очки лиги как слово.
- **Каталог курсов:** `CourseCatalogService::recommend` подбирает курс по `exam_tag = goal`. Курс с `exam_tag`, который не является целью, онбордингом никогда не рекомендуется.

### 3.2. Варианты и решение

| Вариант | Плюсы | Минусы |
|---|---|---|
| **1. Курс-трек «Разговорные фразы» на движке уроков** (фраза — карточка курса) + небольшие доработки упражнений | Работает вся цепочка «урок → тренировка → игра», интервальное повторение, озвучка, очки лиги (фраза — лексема), практика, `/admin`, импорт. Работы в коде — на 1–2 недели | Карточка рассчитана на слово: нужна доработка показа длинного текста и правил упражнений |
| 2. Отдельный раздел со своей механикой (игра «Что сказать в ситуации?», свои таблицы) | Яркий формат | Своё повторение, свои очки, свой экран, своя аналитика — новый движок. Без интервального повторения фразы забываются. Месяцы работы |
| 3. «Фраза дня» в боте | Почти бесплатно, держит контакт | Это не обучение: нет повторения и проверки. Хорошо как добавка к варианту 1, но не замена |

**Решение: вариант 1, «Фраза дня» — позже поверх тех же данных.** Фразы учатся теми же способами, что и слова: узнать значение, узнать фразу по значению, услышать, вспомнить ключевое слово. Движок уроков это уже умеет, а методика (docs/25) одна. Отдельная механика «ситуаций» откладывается до замеров: она ценна, но не нужна для первого выпуска.

**Как это выглядит для ученика.** В каталоге курсов — раздел «Фразы» с курсом «Разговорные фразы» (по уровням, см. §3.4). Урок фраз открывается из каталога или с карточки на «Сегодня» («Урок фраз: Согласие и несогласие»), **активный курс не меняется**. Повторения фраз сами приходят в ежедневную тренировку вместе со словами. После урока — та же цепочка нового дизайна: тренировка, потом игра. Квота новых слов Free (20 в день) общая для слов и фраз (вопрос Ф3).

### 3.3. Что переиспользуется и что дорабатывается

**Без изменений:** импорт пакетов, уроки и этапы, `CardPicker`, SM-2/FSRS, `meaning`, `reverse`, `audio`, `pretest`, `flash` (знакомство), озвучка фразы и примера, доп. примеры, `hooks` (как «Когда говорят»), лексемы и очки docs/32, `/admin` (`WordResource`), практика `sentence` как «Собери фразу» (нужны только задания-данные).

**Доработки:**

| # | Что | Как |
|---|---|---|
| a | Тип карточки | `part_of_speech = 'phrase'` (добавить в `VocabularyImporter::ALLOWED_POS`) + блок `phrase` в записи пакета → `words.meta.phrase` (§3.4). Признак курса — `exam_tag = 'phrases'` (не цель: не рекомендуется онбордингом), каталог Mini App показывает такие курсы отдельным разделом |
| b | Ввод фразы целиком | **`spelling` у фраз отключается** (печатать «easy come, easy go» с запятой и проверять допуском 2 правки — неточно и скучно). На его место — **`gap_fill` по ключевому слову внутри самой фразы**: «Easy come, easy ___» + значение → ученик вводит `go`. Слово пропуска — `meta.phrase.gap` (одно слово a–z без апострофа). `GapFillBuilder` получает режим «маска в тексте фразы» (тот же DTO `GapFill`), проверка — существующий `SpellingGrader`. Нормализация пунктуации и сокращений не нужна, потому что целую фразу никто не печатает |
| c | Отвлекающие варианты | Для карточки-фразы третий слой (`публичные курсы платформы`) ограничивается курсами с `exam_tag = 'phrases'`, чтобы в вариантах не оказалось «surname» рядом с «piece of cake». Первые два слоя (урок, курс) и так дают фразы. Правило «отвлекающий вариант — не второй правильный ответ» (docs/06 §12.2) работает по переводам как есть |
| d | Карточка в Mini App | Длинный текст: уменьшение шрифта по длине, перенос до 3 строк в карточке и до 2 строк в вариантах ответа. Метка регистра («разговорное», «нейтральное», «официальное», «грубоватое»), пометка UK/US, «Буквально: …» и «Как сказать по-русски/узбекски: …» в панели ответа. Пример-диалог — две реплики с тире, без «A:/B:» (иначе озвучка прочтёт буквы) |
| e | Игра | В `RunComposer` фразам не даются задачи `spell` (ввод целиком). `gap` строится по ключевому слову, как в (b). `translate_fwd/back` и `listen` — без изменений |
| f | Очки лиги | Ничего: платформенный курс создаёт лексемы (`LexemeCatalog::ensureFor`). В тесты `LexemeKey` (S1 docs/32) добавить фразы: запятая внутри сохраняется, финальные `?`/`!` снимаются, «a piece of cake» = «piece of cake», `to` в начале не снимается. Фраза идёт в общие лимиты дня, как слово |
| g | Озвучка | Ничего, кроме проверки голоса на фразах с запятыми и вопросом (интонация); бюджет: 1 000 фраз × ~20 символов + примеры × ~80 ≈ 100 тыс. символов из 900 тыс. месячного предела |
| h | Проверочные скрипты | `check_syllabus.py` требует «одну лемму a–z», поэтому нужен свой `check_phrases.py`: фраза до 100 символов, `gap` входит во фразу ровно один раз как отдельное слово, пример содержит фразу (допускаются формы глагола), есть `register`, переводы ru и uz без кириллицы в uz, фраза не повторяется во всех фразовых курсах |

**Не делаем в первом выпуске:** новый тип упражнения «Собери фразу» в тренировке (это новый `ExerciseType`, изменения в лестнице, в TWA и в классификации docs/32 §3.2). «Собрать фразу» даёт практика `sentence` — нужны только данные, их можно сгенерировать скриптом из фраз и вычитать (задача F7).

### 3.4. База фраз

**Что реально значит «полная база».** Полного списка «всех выражений носителей» не существует. Устойчивых выражений в языке десятки тысяч, но реально частых в живой речи — около 1–1,5 тыс. Цель — **1 000 единиц за три выпуска**, по уровням и с пометкой частотности.

| Выпуск | Объём | Уровни | Состав |
|---|---|---|---|
| 1 | **300** самых частых | A2–B1 | разговорные формулы ~40 % (*No worries*, *How's it going?*, *Fair enough*), устойчивые выражения ~25 % (*by the way*, *take it easy*), идиомы ~20 % (*piece of cake*, *break the ice*), фразовые глаголы ~10 % (*figure out*, *give up*), пословицы ~5 % (*better late than never*, *easy come, easy go*) |
| 2 | +350 | B1–B2 | больше идиом и фразовых глаголов |
| 3 | +350 | B2–C1 | идиомы, деловые и сленговые выражения с пометкой регистра |

Курсы: `english-phrases-a2b1`, `english-phrases-b2`, `english-phrases-c1`. Модули — по темам общения (знакомство и вежливость; согласие и несогласие; эмоции и реакции; просьбы и предложения; работа и учёба; деньги и время; люди и характер; неудачи и успех; идиомы о еде, животных, теле…), 5 уроков по 10 фраз. Первый выпуск — 6 модулей.

**Запись фразы** (пакет `mNN-lN.json`, формат v2 `VocabularyImporter` + блок `phrase`):

```json
{
  "word": "easy come, easy go",
  "part_of_speech": "phrase",
  "translations": {"ru": "легко досталось — легко потерялось", "uz": "oson kelgan narsa oson ketadi"},
  "example": "I lost the fifty dollars I won yesterday. — Oh well, easy come, easy go.",
  "example_translations": {"ru": "…", "uz": "…"},
  "examples": [{"text": "…", "translations": {"ru": "…", "uz": "…"}}],
  "hooks": {"ru": "Говорят без сожаления о деньгах или вещах, которые достались без труда", "uz": "…"},
  "phrase": {
    "kind": "proverb",
    "register": "informal",
    "variant": "both",
    "gap": "go",
    "literal": {"ru": "легко пришло, легко ушло", "uz": "…"},
    "cefr": "B1",
    "frequency": 2,
    "synonyms": ["win some, lose some"]
  }
}
```

- `kind`: `formula`, `fixed`, `idiom`, `phrasal_verb`, `proverb`;
- `register`: `neutral`, `informal`, `formal`, `slang`, `rude` (последнее не берём в первый выпуск);
- `variant`: `both`, `uk`, `us`;
- `frequency`: 1 — очень частая, 2 — частая, 3 — встречается;
- `translations` — **смысловой эквивалент**, а не дословный перевод (дословный — в `literal`).

**Источники и права.** Сами выражения (*break the ice*) — часть языка и не охраняются. Охраняются **тексты чужих словарей**: определения, примеры, а также подборки как составные произведения. Правила:
- значения, примеры, подсказки и переводы пишем **сами** (автор и редактор по брифу, как карточки слов);
- отбор частотных фраз — **сверкой по нескольким источникам**, без переноса чужого списка целиком. Частотность — по открытым данным (Google Books Ngram, CC BY 3.0). Корпуса COCA и BNC — только для ручной проверки отдельных фраз; их данные целиком не скачиваем: у них ограничительные лицензии. Wiktionary (CC BY-SA) — только как список кандидатов, тексты оттуда не копируем (иначе действует лицензия share-alike);
- в брифе — прямой запрет копировать определения и примеры из Cambridge, Oxford, Longman, Merriam-Webster, Macmillan, Collins и с сайтов идиом. Редактор проверяет каждую строку поиском точного совпадения.

**Процесс** (как курсы B1–C2, docs/content-authoring):
1. **План** — список 300 фраз с `kind`, `cefr`, `frequency`, `gap` и темами. Составляет автор, утверждает координатор, проверяет `check_phrases.py`.
2. **Пилот** — один модуль (50 фраз). Читается целиком, импортируется настоящей командой, бриф дополняется.
3. **Авторы** — `PHRASE_BRIEF.md` (новый): по 2 модуля на автора. **Редакторы** — `PHRASE_PROOF_BRIEF.md`, задача «докажи, что неверно»: регистр, второй смысл, ложный эквивалент, британское и американское употребление.
4. **Узбекский** — смысловые эквиваленты пословиц и идиом требуют носителя. Решение владельца от 03.11.2026 разрешает выпускать узбекский без проверки, но для фраз ошибка заметнее, чем для слов (вопрос Ф2). Спорные места — в `CONTENT_PHRASES_REVIEW.md` для носителя.
5. **Импорт** — `content:import-course` черновиком → проверка в `/admin` → публикация. Озвучка — ночная сверка.

### 3.5. Задачи

| # | Задача | Кто | Зависит от | Definition of Done и ключевые тесты |
|---|---|---|---|---|
| F0 | docs/06 (правила упражнений для фраз), docs/25 (фразы в методике), docs/content-authoring/README (раздел фраз), docs/03 (`words.meta.phrase`) | коорд. | ответы Ф1–Ф3 | docs согласованы |
| F1 | Импорт: `phrase` в `ALLOWED_POS`, проверка и запись блока `phrase` в `meta`, `exam_tag = phrases`; каталог отдаёт признак раздела; `recommend` не трогает фразы | C | F0 | невалидный `gap` (нет во фразе, два вхождения) — ошибка импорта; повторный импорт без изменений; онбординг фраз не рекомендует |
| F2 | Упражнения для фраз: `spelling` → `gap_fill` по ключевому слову, маска в тексте фразы, слой отвлекающих вариантов «только фразовые курсы» | C, ревью **A** | F1 | на ступени формы у фразы нет `spelling`; маска только ключевого слова; варианты не смешивают слова и фразы; бюджет `/review` не вырос |
| F3 | Mini App: карточка и варианты с длинным текстом, регистр, UK/US, «Буквально», эквивалент; раздел «Фразы» в каталоге; карточка урока фраз на «Сегодня» | C | F1, выпуск 1 | снимки vitest на фразу в 100 символов; i18n ru/uz |
| F4 | Игра: без `spell` для фраз, `gap` по ключевому слову | C | F2 | состав забега с фразами; повтор запроса |
| F5 | Каталог лексем: случаи фраз в тестах `LexemeKey` | C | S1 docs/32 | таблица случаев §3.3 (f) |
| F6 | `check_phrases.py`, `PHRASE_BRIEF.md`, `PHRASE_PROOF_BRIEF.md`, план 300 фраз | K, коорд. | F0 | скрипт ловит каждое правило §3.3 (h) на заведомо плохом файле |
| F7 | Пилот (50) → 300 фраз первого выпуска; задания `sentence` «Собери фразу» из фраз; документ для носителя | K | F6 (пилот — после F1) | `PhrasePacksTest` (как `LevelCoursePacksTest`) зелёный; импорт и публикация на стейджинге; озвучка покрыта |
| F8 | Позже: «Фраза дня» в боте (вариант напоминания через `ReminderContentResolver`), игра «Что сказать в ситуации?» | — | замеры после выпуска | отдельный проект |
| F9 | Ревью | reviewer | F1–F5 | — |

### 3.6. Вопросы владельцу

| № | Вопрос | Рекомендация |
|---|---|---|
| Ф1 | Объём первого выпуска | 300 фраз A2–B1, дальше +350 и +350 |
| Ф2 | Узбекские эквиваленты фраз без носителя | Для фраз — **с носителем** до публикации (хотя бы первого выпуска): неудачный эквивалент пословицы заметнее, чем перевод слова |
| Ф3 | Доступ Free и квота | Раздел бесплатный; фразы расходуют ту же квоту 20 новых в день, что и слова (отдельная квота усложнит правила очков docs/32) |

### 3.7. Риски

- **Качество контента** — главный риск. Ложный эквивалент, неверный регистр, устаревшая идиома. Нужны вычитка «докажи, что неверно» и носитель для узбекского.
- **Права.** Соблазн «взять список с сайта идиом» велик. Бриф и проверка редактора — обязательны.
- **Конкуренция с основным курсом за квоту Free.** Ученик, который увлёкся фразами, медленнее идёт по курсу. Смотреть по событиям после выпуска.
- **Длинный текст в новом дизайне.** Карточку фразы проверить на узком экране (320 px) до выпуска.

---

## 4. Место в плане выпусков (docs/31 §2) и зависимости

| Функция | Когда | Зависит от | Почему так |
|---|---|---|---|
| **Опросы** O1–O9 | **Сразу, отдельной веткой**, параллельно с выпуском 1. O1 — вместе с K3 выпуска 0 | ничего; O8 — от R1 | Владельцу опрос нужен сейчас. Ни очки, ни кубок его не касаются |
| **Регион, часть 1** R1–R5 (данные, онбординг, настройки, `/admin`) | **С выпуском 1** (новый дизайн): экраны настроек и «Лиги» всё равно перерисовываются, место под переключатель региона закладывается в макет | — | Чем раньше спрашиваем регион, тем больше учеников будут с регионом к запуску таблиц |
| **Регион, часть 2** R6–R9 (региональные таблицы) | **С выпуском 4** («За всё время») или сразу после | S9 и S10 docs/32 | Таблица — фильтр поверх таблиц v2. Делать на v1 значит переписывать |
| **Фразы**, код F1–F5 | **После выпуска 1**, параллельно с выпусками 2–4 другим исполнителем | выпуск 1 (карточка в новом дизайне), F5 — S1 | Код небольшой; очки фразы получают автоматически, когда v2 включится |
| **Фразы**, контент F6–F7 | **Начинать сейчас** | F1 — для пилотного импорта | Самая долгая часть: 300 фраз с вычиткой и носителем |

Связи между функциями:
- опрос по региону (O8) требует R1;
- региональные таблицы (R6) требуют docs/32 S9/S10;
- очки за фразы требуют docs/32 S1 (каталог) и включения v2;
- новые методы клиента Telegram (O1) общие с кубком (K3) — делать один раз.
