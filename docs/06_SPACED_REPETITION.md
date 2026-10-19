# 06. Spaced Repetition — SM-2 + learning steps

## 1. Кратко об алгоритме

Основа — классический SM-2 (SuperMemo 2, Piotr Woźniak, 1987) для «дневных» интервалов,
дополненный **learning steps** в стиле Anki для работы внутри одной сессии. Для каждой пары
(студент, слово) в `word_repetitions` хранятся:

- `state` — `new` | `learning` | `review` | `relearning` (см. §3.1).
- `learning_step` — индекс текущего минутного шага внутри `learning`/`relearning`.
- `repetitions` — количество успешных повторений подряд в состоянии `review` (сброс при провале).
- `interval_days` — текущий интервал в днях (0, пока карточка в минутных шагах).
- `easiness_factor` (EF) — коэффициент лёгкости, от 1.30 до ~2.5+.
- `lapses` — сколько раз выпущенная карточка была провалена.
- `is_hard` — флаг «трудная» (см. §3.5).
- `served_ahead_at`, `served_ahead_token` — служебный одноразовый «билет» learn-ahead (см. §5.2).

Реализация: `app/Domain/Learning/Services/SpacedRepetitionEngine.php`, параметры —
`config/learning.php` → `App\Domain\Learning\Support\LearningPolicy`. Схема —
`docs/03_DATABASE.md` §2.8. Миграция на FSRS (docs/11 §4.1) добавит реализацию алгоритма
рядом, не меняя контракт `applyReview(WordRepetition, int $quality, CarbonImmutable $now)`.

## 2. Маппинг оценок студента

API принимает `quality` 0..5 (как и раньше). Смысл значений:

| Кнопка в UI | Quality | Anki-аналог | Результат |
|-------------|---------|-------------|-----------|
| 😰 Трудно | **2** | Again | провал: карточка уходит на минутные шаги (`learning`/`relearning`), `is_hard = true` |
| 🙂 Средне | **4** | Good | успех, **EF не меняется** |
| 😎 Легко | **5** | Easy | успех, EF +0.10; в минутных шагах — немедленный выпуск с `easy_interval_days` |
| — | 3 | Hard (pass) | успех с трудом, EF −0.14; кнопки в v1 нет, значение валидно для API |
| — | 0, 1 | Again | провал, как 2, но сильнее штраф EF (−0.80 / −0.54) при провале `review`-карточки |

Почему «Средне» = 4, а не 3: в SM-2 q=3 уменьшает EF на 0.14, и студент, честно нажимающий
«Средне», за 8–9 повторений упирается в EF = 1.30 («ease hell»: интервалы почти не растут,
повторений всё больше). q=4 оставляет EF без изменений — это и есть «вспомнил нормально».
Провал (q<3) по-прежнему пишется в `training_reviews.quality`, поэтому аналитика
«трудных слов» (`quality < 3`) не меняется.

## 3. Правила планирования

### 3.1 Состояния

```
new ──pass──▶ learning(step 1) ──pass──▶ review ──fail──▶ relearning ──pass──▶ review
 │                 ▲    │                  ▲                    │
 └──fail──▶ learning(step 0)  (fail: назад на step 0) ◀────────┘
```

- `new`, `learning` — карточка ещё не выпущена; расписание в **минутах** (`learning_steps`).
- `review` — выпущена; расписание в **днях** по SM-2.
- `relearning` — выпущенная карточка провалена; минутные шаги (`relearning_steps`), затем снова `review`.

Шаги по умолчанию (`config/learning.php`): `learning_steps = 1,10` мин, `relearning_steps = 10` мин,
`graduating_interval_days = 1`, `easy_interval_days = 4`, `learn_ahead_minutes = 20`,
`abandoned_after_hours = 12` (см. §6), `max_interval_days = 3650`.
Env: `LEARNING_STEPS`, `RELEARNING_STEPS`, `LEARNING_GRADUATING_INTERVAL_DAYS`,
`LEARNING_EASY_INTERVAL_DAYS`, `LEARNING_LEARN_AHEAD_MINUTES`, `LEARNING_ABANDONED_AFTER_HOURS`,
`LEARNING_MAX_INTERVAL_DAYS`.

**Потолок интервала.** Любой рассчитанный интервал (SM-2 в `review`, выпуск из (re)learning)
зажимается до `max_interval_days` (по умолчанию 3650 дней, валидация `1..32767` — колонка
`interval_days` это smallint); `next_review_at` считается от зажатого значения.

**Валидация конфигурации (fail fast).** `LearningPolicy` при построении проверяет согласованность:
шаги ≥ 1 мин; `learn_ahead_minutes` равен `0` (learn-ahead выключен) **или** ≥ максимального шага
среди `learning_steps`/`relearning_steps` (иначе сессия молча обрывалась бы с висящими карточками);
`abandoned_after_hours` ≥ 1 и дольше максимального шага. При нарушении бросается
`InvalidArgumentException` с подсказкой, какую env-переменную править. `AppServiceProvider::boot()`
резолвит политику сразу, поэтому ошибочный env валит `artisan config:cache` в entrypoint контейнера
и health-check **на этапе деплоя**, а не первый запрос студента.

### 3.2 Карточка в `new` / `learning` (EF **не трогаем**)

```
if q < 3:                       # Трудно
    state = learning, learning_step = 0
    next_review_at = now + learning_steps[0]
    is_hard = true
elif q == 5:                    # Легко — пропускаем оставшиеся шаги
    graduate(interval = easy_interval_days)
else:                           # 3 или 4
    step = learning_step + 1
    if step < len(learning_steps):
        learning_step = step; next_review_at = now + learning_steps[step]
    else:
        graduate(interval = graduating_interval_days)

graduate(interval):
    state = review, learning_step = 0, repetitions = 1
    interval_days = interval, next_review_at = now + interval days
    # is_hard не меняется: сбросится при первом успешном review-ответе
```

Как в Anki, ошибки во время заучивания не портят EF: иначе три «Трудно» на новом слове за одну
сессию стоили бы −0.96 EF.

### 3.3 Карточка в `review` — классический SM-2

```
EF' = max(1.30, EF + (0.1 − (5 − q) · (0.08 + (5 − q) · 0.02)))

if q < 3:                       # lapse
    lapses += 1, repetitions = 0, interval_days = 0
    state = relearning, learning_step = 0
    next_review_at = now + relearning_steps[0]
    is_hard = true
else:
    interval = 1 if repetitions == 0
             = 6 if repetitions == 1
             = round(interval_days · EF') иначе (минимум 1)
    repetitions += 1, interval_days = interval
    next_review_at = now + interval days
    is_hard = false
```

Штраф EF за провал начисляется **один раз** — в момент lapse. Дальнейшие провалы внутри
`relearning` EF не меняют.

### 3.4 Карточка в `relearning`

Те же правила, что в §3.2, но со списком `relearning_steps`; при выпуске `repetitions = 1`,
`interval_days = graduating_interval_days` (1 день) — то есть ровно то, что старый SM-2 давал
на следующий день после провала. `is_hard` остаётся `true` до первого успешного ответа в `review`.

### 3.5 Семантика `is_hard`

`is_hard = true` с момента провала (в любом состоянии) и до первого **успешного ответа на
review-карточку** (то есть после того, как слово пережило хотя бы один дневной интервал).
Выпуск из `relearning` флаг **не** снимает.

Где используется:
- `CardPicker` — среди due-карточек трудные показываются первыми.
- `ExamAnswerService` — бонус ×1.5 за правильный ответ на трудное слово. Поведение сохранено:
  слово, проваленное на тренировке, остаётся «трудным» на экзамене до следующего удачного
  дневного повторения.

## 4. Выборка «что показать студенту» (`CardPicker::pickNext`)

Сигнатура: `pickNext(int $learnerId, TrainingScope $scope, ?NewCardBudget $budget = null, ?CarbonImmutable $now = null): CardPick`
(`CardPick` — карточка `{repetition, word, kind, ahead}` **или** `doneReason`).

### 4.1 Scope — какие карточки допустимы (`App\Domain\Learning\Support\TrainingScope`)

| Режим сессии | Scope | Due / learn-ahead | Новые слова |
|---|---|---|---|
| `group_lesson`, `lesson` | `TrainingScope::lesson($lessonId)` | строки `word_repetitions` learner'а по словам **этого урока** | слова урока без строки `word_repetitions`, по `words.id` |
| `daily_review` | `TrainingScope::daily($courseId)` (`training_sessions.course_id` — снимок `learners.active_course_id`) | строки learner'а по **всем** словам всех уроков и курсов | слова курса `course_id` без строки, в порядке `(stage.number, lesson.number, words.id)`; без курса — только due |

Слова с `is_active = false` (замена удаления, docs/03 §8) **не выдаются ни в одном шаге** — ни как новые, ни как due,
ни досрочно; их строки `word_repetitions` не трогаются и не считаются в `DueRepetitionCriteria`, прогрессе урока и
`LearningCache::lessonWordCount`. `TrainingScope::forSession(TrainingSession)` строит scope из строки сессии; для
`lesson`-режимов без `lesson_id` бросает `LogicException` (инвариант `mode_shape`).

### 4.2 Порядок выдачи

1. **Due**: `next_review_at <= now` в scope, `ORDER BY is_hard DESC, next_review_at, id`.
   Сюда попадают и review-карточки, и learning-карточки, у которых минутный шаг уже истёк. Для `daily_review` —
   из любых уроков: трудные карточки разных курсов идут вперемешку, по сроку.
2. **New**: первое невыданное слово scope (создаётся строка `state = new`, `next_review_at = now`) — в транзакции
   под `SELECT … FOR NO KEY UPDATE` строки **learner'а** (`learners.id`; sqlite блокирует всю БД). Не `FOR UPDATE`: `FOR NO KEY UPDATE` не конфликтует с `FOR KEY SHARE`, который берёт любая вставка с внешним ключом на `learners` (`word_repetitions`, `training_reviews` и др.), поэтому такие вставки не ждут пикер. Блокировка сериализует
   выдачу новых карточек одному человеку: две вкладки получают разные слова, а бюджет (ниже) считается после
   блокировки и не может быть превышен совместно. Блокировки на строке `words` нет — она была бы глобальной для всех учеников.
   Вкладка, пришедшая **после** коммита первой, может получить её ещё не оценённую `new`-карточку через шаг 1 (строка
   `state = new`, `next_review_at = now` — due): это штатно, как и с любой due-карточкой — оценку примет одна вкладка,
   вторая получит 409 `card_not_due` и перезапросит `/next`. Гарантия блокировки — каждое слово **вводится** (строка
   создаётся) ровно один раз и бюджет не превышается.
   **Инвариант блокировки learner'а** (иначе возможен цикл ожидания и deadlock): под ней пикер делает только чтения и вставку
   строки `word_repetitions`; он не блокирует `word_repetitions` / `training_sessions` / `learner_daily_stats` и не меняет
   `learners`. Обратно: транзакция оценки (§5) не обновляет и не блокирует `learners` — ни явным `FOR UPDATE`, ни `UPDATE`
   (стрик, цели, `last_seen_at` и т. п. пишутся вне неё или в `learner_daily_stats`). Квота 1.4b считается обычным
   чтением под блокировкой learner'а — без `lockForUpdate` других таблиц.
   **Бюджет** (`NewCardBudget`, docs/12 §5.3): `remaining(learnerId, now)` вычисляется под той же блокировкой;
   `null` — безлимит (по умолчанию и для Pro), `<= 0` — шаг пропускается, строка не создаётся. Повторения бюджетом
   **никогда** не ограничиваются. Бюджет отдаёт `NewCardBudgetResolver::budgetFor(Learner, now)` (контракт в
   `App\Domain\Learning\Contracts`); привязан `NewWordQuota` (задача 1.4b: Pro → `unlimited()`, Free →
   `NewCardBudget::lazy(fn ($learnerId, $now) => limit − выдано сегодня)`; `UnlimitedNewCardBudgetResolver` остался для тестов),
   где «сегодня» — `LearnerClock::dayBoundsUtc($learner, $now)` → `[startUtc, endUtc)` локального дня, а «выдано» —
   `word_repetitions.created_at` в этих границах (не `learner_daily_stats.new_words_count`, docs/12 §1.6).
   **Порядок блокировок** (1.3b, обязателен для всех будущих правок). Пикер держит строку `learners`
   (`FOR UPDATE` / `FOR NO KEY UPDATE`) в **своей** транзакции и под ней может читать `word_repetitions` /
   `learner_daily_stats`. Транзакция оценки (`ReviewSubmissionService::submit`) берёт
   `training_sessions → word_repetitions → learner_daily_stats` (`DailyStatsService::recordReview`) и **никогда не
   пишет и не блокирует `learners`** — обычный `UPDATE learners` взял бы ту же строчную блокировку и дал цикл
   «оценка держит статистику и ждёт learner'а, пикер держит learner'а и ждёт статистику». Поэтому `StreakService::onGoalMet`
   лишь регистрирует `DB::afterCommit`, а проекция `learners.streak_*` обновляется после коммита одиночным
   условным `UPDATE … WHERE streak_last_date = <прочитанное>` (без `FOR UPDATE`, вне любой другой транзакции);
   источник истины для streak — `learner_daily_stats.goal_met_at`, по нему `StreakService::apply()` при разрыве пересчитывает серию, а `current()` чинит
   отставшую проекцию (docs/12 §3.1, §7.4). Поэтому и `current()` — не чистое чтение: он может выполнить тот же
   одиночный `UPDATE learners`, и его (а с ним `TodayService::summary/progress`) нельзя вызывать внутри транзакции,
   которая держит `training_sessions` / `word_repetitions` / `learner_daily_stats` (в частности из
   `SendLearnerReminderJob` 1.5b — только вне транзакции).
3. **Learn-ahead**: если due и new нет — ближайшая `learning`/`relearning` карточка scope с
   `next_review_at <= now + learn_ahead_minutes`. Она выдаётся **досрочно**, и в строке
   ставится билет: `served_ahead_at = now`, `served_ahead_token = random(32 hex)`. Токен уходит
   клиенту как `card.serve_token`. Если билет уже выдан и не погашен (карточку досрочно получили
   два «таба»), он **переиспользуется**, а не перевыпускается — погасить его сможет только один ответ.
   Выдача — условный `UPDATE ... WHERE served_ahead_token IS NULL`, проигравший гонку берёт токен победителя.
4. Иначе карточки нет, и `CardPick::doneReason` объясняет почему (`App\Domain\Learning\Enums\DoneReason`):
   `new_quota_exhausted` — шаг 2 остановил именно бюджет, а невыданные слова в scope есть; иначе `lesson_complete`
   (lesson-scope) или `nothing_due` (daily-scope). `TrainingFlowService` добавляет `session_cap`, когда достигнут
   `twa.training.max_cards_per_session` — ещё до обращения к пикеру. Клиент получает `done_reason` рядом с `card: null`.

`card_kind` в ответе API: `new` (state new) · `learning` (learning/relearning) · `due` (review).
`card.serve_token` — `null` для due/new карточек и для learning-карточек, чей шаг уже истёк (они due).

Таким образом сессия не завершается, пока в scope есть карточки в минутных шагах
(при `learn_ahead_minutes >= max(шаг)`). Если learn-ahead выключен (`0`), сессия завершится,
а карточка вернётся, когда наступит её срок.

**Прогресс** (`progress: {done, total}`): lesson-scope — `CardPicker::progress`: выпущенные (`repetitions >= 1`)
активные слова урока / все активные слова урока (`LearningCache::lessonWordCount`); daily-scope — оценок в этой сессии /
`learners.daily_goal` (точнее «сделано сегодня» появится с `learner_daily_stats` в 1.3b).

## 5. Приём оценки (`ReviewSubmissionService::submit`)

### 5.1 Порядок проверок
1. Сессия `open` (под `lockForUpdate`) и, для личной, `expires_at > now` (планировщик мог ещё не пройти) — иначе 410 `session_closed`.
2. Строка `word_repetitions` существует — иначе 409 `card_not_started`.
3. Слово в scope сессии (`TrainingScope::contains`: урок сессии для `group_lesson`/`lesson`, любое уже виденное слово для `daily_review`; неактивное слово — всегда вне scope) — иначе 422 `out_of_scope`.
4. Карточку **можно оценить** (§5.2) — иначе 409 `card_not_due`.
5. `SpacedRepetitionEngine::applyReview`, запись в `training_reviews`, затем `DailyStatsService::recordReview(learner, quality, timeSpentMs, isNewCard, now)` — **в той же транзакции** (`isNewCard` = карточка была в `new` до этой оценки; агрегат за день наполняет задача 1.3b, в 1.3a это хук-заглушка). Ошибка в любом шаге откатывает всё, включая оценку.

### 5.2 Защита от повторного submit и learn-ahead

Оценка принимается, если выполняется одно из двух:
- `next_review_at <= now` (карточка due — как и раньше; `serve_token` в теле игнорируется), **или**
- карточка в `learning`/`relearning`, в теле запроса есть `serve_token`, он **совпадает**
  (`hash_equals`) с `served_ahead_token` строки и `next_review_at <= now + learn_ahead_minutes`
  (карточка выдана досрочно пикером, и клиент отвечает именно на эту выдачу).

Любой принятый ответ переносит `next_review_at` в будущее (минимум на 1 минуту) и
**обнуляет билет** (`served_ahead_at`, `served_ahead_token`). Поэтому повтор того же запроса
(тот же `word_id`/`quality`/`serve_token` — например, ретрай сети или второй таб) получает
409 `card_not_due` **даже если движок в том же ответе снова выдал ту же карточку досрочно**:
новая выдача — новый токен, а в повторе — старый или пустой. Без токена нельзя было отличить
легитимный ответ на повторную выдачу от реплея (урок из 1 слова: `/next` → review → в ответе та же
карточка → повтор запроса принимался и выпускал карточку без показа).

Гонка «два клиента получили одну и ту же learn-ahead карточку» разрешается блокировкой строки:
первый submit принимается, второй — 409. Старый клиент, не присылающий `serve_token`, продолжает
работать с due/new карточками и получает 409 на learn-ahead карточках (функция впервые выезжает
вместе с токеном, поэтому таких клиентов в проде нет). Рекомендуемая реакция TWA на
409 `card_not_due` — перезапросить `GET /next`, а не показывать экран ошибки.

Лимит `twa.training.max_cards_per_session` считает **оценки** (`training_reviews`), а не слова;
с шагами 1/10 мин урок из 25 слов стоит ~75 оценок плюс провалы, поэтому значение по умолчанию
поднято до 200.

## 6. Персональные напоминания и `words_due_today`

Единое определение «карточка, за которой студенту надо вернуться» —
`App\Domain\Learning\Support\DueRepetitionCriteria::apply()`. Его используют только через
`LearnerStatsService::dueCount()`: напоминание (`ReminderContentResolver` — порог и число в
сообщении), `words_due_today` в `GET /api/twa/me` и `due_count` экрана «Сегодня», чтобы число
на экране совпадало с текстом напоминания. Due считается карточка, если:

- `state = review` и `next_review_at <= now` — выпущенная карточка, срок настал; **или**
- `state in (learning, relearning)` и `next_review_at <= now - abandoned_after_hours` (по умолчанию
  12 ч, env `LEARNING_ABANDONED_AFTER_HOURS`) — **брошенная** карточка: минутный шаг истёк давно,
  студент ушёл из сессии. Без этого правила провал в `relearning` посреди сессии (и legacy-строки
  после backfill, см. ниже) навсегда выпадал бы из напоминаний.

Не считаются: свежие минутные шаги (минуты–часы после тренировки — сессия, возможно, ещё идёт) и
строки в `new` (карточка показана, но ни разу не оценена — «повторять» нечего).

**Когда и что приходит (docs/12 §7.5, задача 1.5b).** Напоминание — одно в день, в локальное время
learner'а (`learners.reminder_time` в `learners.timezone`; `NULL` = выключено). Указатель
`learners.next_reminder_at` (UTC) считает `LearnerClock::nextOccurrenceUtc` — при выборе пояса/времени
в онбординге и `/settings`, при создании learner'а (`LearnerRegistrar`, дефолты Ташкент 19:00) и после
каждого клейма; DST и смена пояса учитываются при каждом пересчёте.

- `reminders:dispatch` — `everyFifteenMinutes()->withoutOverlapping()`. `ReminderDispatcher::dispatchDue(now)`
  выбирает по партиал-индексу `learners_next_reminder_at_idx` (`next_reminder_at <= now AND dm_blocked_at
  IS NULL`, плюс `blocked_at IS NULL`, soft-delete) пачками `chunkById(200)` и клеймит каждого условным
  `UPDATE learners SET next_reminder_at = <следующее наступление от now> WHERE id = ? AND next_reminder_at = <прочитанное>`;
  только `affected = 1` ставит `SendLearnerReminderJob(learnerId, scheduledFor)`. Второй инстанс или повтор
  той же минуты видит 0 строк и ничего не дублирует. Указатель двигается **до** постановки job: сбой между
  ними теряет одно напоминание, а не удваивает его. Следующее наступление считается от `now`, поэтому после
  простоя планировщика всё догоняется одним запуском, а клеймы старше `REMINDERS_MAX_DELAY_MINUTES` (120)
  не отправляются — напоминание «в случайный час» хуже тишины. Там же backfill: у кого `reminder_time` есть,
  а указателя нет (старые строки, импорт), получают его (до `REMINDERS_BACKFILL_LIMIT` = 1000 за запуск);
  `dm_blocked_at`, `blocked_at`, удалённые — не трогаются.
- Текст решает job в момент отправки (`ReminderContentResolver`): `dueCount >= REMINDERS_DUE_THRESHOLD`
  (**1**, было 5 — в B2C одно слово к повторению уже повод вернуться) → `reminders.review`; иначе серия под
  угрозой (`StreakService::current().atRisk`) → `reminders.streak_at_risk`; иначе молчание (указатель уже
  на завтра — это и нужно). Кнопка → `/twa/?from=reminder`, экран «Сегодня» сам открывает `daily_review`
  (`source: 'reminder'`). Незавершённый онбординг не мешает: learner из группы получает одну попытку с
  дефолтами, 403 → `dm_blocked_at`, дальше тишина до его первого сообщения боту.
- Второй уровень идемпотентности — в job (`ReminderSender`, `Cache::lock` на learner'а): не чаще одного
  напоминания **любого вида** за `REMINDERS_MIN_INTERVAL_HOURS` (20) по `notifications_log`
  (`review_reminder` / `streak_reminder`). Повтор job, двойная доставка — одно сообщение.
- Telegram 403 → `dm_blocked_at = now`, `reminder.failed {reason, kind}`, без повторов; любое входящее
  сообщение/кнопка в ЛС снимает флаг (`TelegramDispatcher`, до хендлеров). Остальные ошибки Telegram
  пробрасываются — очередь повторит (tries 3).

**Legacy backfill.** Миграция `add_learning_state_to_word_repetitions` переводит строки
`repetitions = 0 AND last_quality IS NOT NULL` (проваленные в старом SM-2, у них `next_review_at`
был «завтра») в `relearning`. По правилу выше они снова попадают в напоминания через
`abandoned_after_hours` после своего `next_review_at`; слова не теряются.

## 7. Тестирование

- `tests/Unit/SpacedRepetitionEngineTest.php` — формула EF для 0..5 как раньше; q=4 не меняет EF;
  провал запускает шаги; выпуск после последнего шага; Easy-выпуск; lapse → relearning → review;
  EF не меняется в learning/relearning; жизненный цикл `is_hard`; legacy-строки после backfill;
  парсинг `LearningPolicy`.
- `tests/Unit/SpacedRepetitionEngineTest.php` (продолжение) — валидация `LearningPolicy`:
  `learn_ahead_minutes < max(step)` → исключение (0 и ≥ max — ок), `abandoned_after_hours`,
  fail-fast в `AppServiceProvider::boot()`.
- `tests/Feature/TwaTrainingTest.php` — «Трудно» возвращает карточку в той же сессии (learn-ahead);
  сессия не завершается, пока есть learning-карточки; повтор запроса, когда та же карточка
  переиздана в ответе (урок из 1 слова) → 409; повтор без повторной выдачи → 409; два таба делят
  один билет, гасит его один; ответ без/с чужим/просроченным токеном → 409; due-карточка принимается
  и без токена; learn-ahead выключен; порядок due → new; миграция `down()`/`up()` с backfill.
- `tests/Feature/ReviewReminderTest.php` — свежие learning-карточки не считаются due; брошенные
  (`> abandoned_after_hours`) считаются и в напоминании, и в `words_due_today`, и в deep-link;
  legacy-строки после backfill не теряются.
- `tests/Feature/TwaContractTest.php` — `card.serve_token` в контракте; `words_due_today`.
- `tests/Feature/PersonalTrainingSessionTest.php` — scope: due из разных уроков и курсов в `daily_review` (hard first),
  новые слова из активного курса в учебном порядке, без курса — только due; `out_of_scope`; `done_reason`
  (`lesson_complete` / `nothing_due` / `new_quota_exhausted` / `session_cap`); бюджет не трогает due; неактивные
  слова не выдаются и не считаются; `DailyStatsService::recordReview` в транзакции и откат оценки при его сбое.
- `tests/Feature/TrainingConcurrencyTest.php` (только PostgreSQL, форк процессов) — параллельные `openPersonal` → одна
  сессия; параллельные `pickNext` одного learner'а не выдают одно новое слово дважды и не превышают бюджет совместно;
  шесть одновременных `recordReview` → одна строка `learner_daily_stats`, суммы точны, `goal_met_at` и зачёт streak — один раз.
- `tests/Unit/LearnerClockTest.php` — 23:59 / 00:01 по Ташкенту — разные локальные дни при одной UTC-дате; день DST-перехода
  в `Europe/Berlin` — 23 и 25 часов; `nextOccurrenceUtc` строго после `after`, через DST, время в «дыре» перевода;
  fallback невалидного пояса.
- `tests/Feature/DailyStatsServiceTest.php` — счётчики, строка на локальный день, `goal_met_at` ровно один раз, цель 0,
  streak не проецируется на `learners` до коммита и не проецируется при откате, реальный поток `TrainingFlowService::review`.
- `tests/Feature/StreakServiceTest.php` — 1 → 2 → обнуление после пропуска, `at_risk` / `met_today`, зачёт дня один раз,
  граница дня, смена пояса на запад/восток, запрет зачёта «вчера» после уже зачтённого «сегодня», best, события,
  починка проекции из строки «сегодня», пересчёт серии по фактам при потерянном колбэке «вчера» (в т.ч. порядок
  колбэков вокруг полуночи), глубина 400 дней, `current()` чинит «вчера» и не пишет без факта.
- `tests/Unit/TrainingScopeTest.php` — `TrainingScope`, `NewCardBudget`, инвариант `mode_shape`.
- TWA `FlashCard.spec.ts` — кнопки шлют 2 / 4 / 5, метка «Закрепление» для `learning`;
  `stores/__tests__/training.spec.ts` — `serve_token` карточки уходит в `POST /review`.

## 8. Аналитика на основе SM-2

- **Трудное слово для группы** — где у ≥ 40% студентов `training_reviews.quality < 3` (при min. 10 попыток).
- **Прогресс студента** — средний EF по всем его карточкам (растёт = прогресс); число карточек в `review`.
- **Leech-кандидаты** — `lapses >= 5`.
- **Heatmap активности** — количество `training_reviews` на студента по дням недели.

## 9. Будущие улучшения (не MVP)

- Учёт времени ответа (`time_spent_ms`): если ответил «легко» за 10 секунд — подозрительно, сбавить EF прирост.
- Четвёртая кнопка «С трудом» (q=3) — если будет запрос; API уже поддерживает.
- FSRS (Free Spaced Repetition Scheduler) — интерфейс алгоритма поверх `applyReview`,
  поля `stability`/`difficulty` в `word_repetitions`, флаг алгоритма для A/B (docs/11 §4.1).
- Leech-detection: `lapses >= N` → пометить, показать учителю.
- Fuzzing интервалов: к `next_review_at` добавлять случайный ±10%, чтобы не возникало «стен» в один день.

## 10. Канонические источники
- Оригинальный пейпер SM-2: `https://super-memory.com/english/ol/sm2.htm`
- Anki (learning steps, learn ahead, relearning): `https://docs.ankiweb.net/deck-options.html`
- FSRS для сравнения: `https://github.com/open-spaced-repetition/fsrs4anki`
