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
`docs/03_DATABASE.md` §2.8. FSRS (docs/11 §4.1) реализован рядом как A/B (§9), контракт
`applyReview(WordRepetition, int $quality, CarbonImmutable $now)` не менялся.

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

Сигнатура: `pickNext(int $learnerId, TrainingScope $scope, ?NewCardBudget $budget = null, ?CarbonImmutable $now = null, ?SprintLimits $sprint = null): CardPick`
(`CardPick` — карточка `{repetition, word, kind, ahead}` **или** `doneReason` + `canContinue`; `SprintLimits` — остаток раунда
ежедневной сессии, §13.2).

### 4.1 Scope — какие карточки допустимы (`App\Domain\Learning\Support\TrainingScope`)

| Режим сессии | Scope | Due / learn-ahead | Новые слова |
|---|---|---|---|
| `group_lesson`, `lesson` | `TrainingScope::lesson($lessonId)` | строки `word_repetitions` learner'а по словам **этого урока** | слова урока без строки `word_repetitions`, по `words.id` |
| `daily_review` | `TrainingScope::daily($courseId)` (`training_sessions.course_id` — снимок `learners.active_course_id`) | строки learner'а по **всем** словам всех уроков и курсов | слова курса `course_id` без строки, в порядке `(stage.number, lesson.number, words.id)`; без курса — только due |
| `hard_review` | `TrainingScope::hard($wordIds)` (`training_sessions.meta.hard_words`, набор зафиксирован при открытии, §14) | due-строки learner'а по словам **набора** (`words.id IN (набор)`, активные) | **нет**: `includesNew() = false`, `newWordCandidates() = null` |

Слова с `is_active = false` (замена удаления, docs/03 §8) **не выдаются ни в одном шаге** — ни как новые, ни как due,
ни досрочно; их строки `word_repetitions` не трогаются и не считаются в `DueRepetitionCriteria`, прогрессе урока и
`LearningCache::lessonWordCount`. `TrainingScope::forSession(TrainingSession)` строит scope из строки сессии; для
`lesson`-режимов без `lesson_id` и для `hard_review` без `meta.hard_words` бросает `LogicException` (инвариант `mode_shape`).

### 4.2 Порядок выдачи

1. **Due**: `next_review_at <= now` в scope, `ORDER BY is_hard DESC, next_review_at, id` — ровно порядок индекса
   `word_repetitions_next_card_idx (learner_id, is_hard DESC, next_review_at, id)` (docs/03 §2.8), так что карточка —
   первая подходящая запись индекса, без сортировки всех просроченных; менять порядок — только вместе с индексом.
   Когда раунд ежедневной сессии выбрал свой потолок дневных повторений (§13.2), сюда добавляется `state <> 'review'`:
   карточки в минутных шагах по-прежнему выдаются, выпущенные ждут следующего раунда.
   Сюда попадают и review-карточки, и learning-карточки, у которых минутный шаг уже истёк. Для `daily_review` —
   из любых уроков: трудные карточки разных курсов идут вперемешку, по сроку. Строка и её слово читаются **одним**
   оператором (`App\Domain\Learning\Support\RepetitionWithWord`, R12g): `words` присоединяется через `INNER JOIN` с условиями
   scope (`is_active`, для урока — `lesson_id`), колонки слова едут как `word__*` и раскладываются в модель `Word`; план на
   PostgreSQL тот же обход индекса, что у прежней формы `whereHas` (проверено EXPLAIN на наборе 200 тыс. учеников). Так же читается
   learn-ahead (шаг 3) и строка под оценкой (§5.1).
2. **New**: первое невыданное слово scope (создаётся строка `state = new`, `next_review_at = now`) — в транзакции
   под `SELECT … FOR NO KEY UPDATE` строки **learner'а** (`learners.id`; sqlite блокирует всю БД). Не `FOR UPDATE`: `FOR NO KEY UPDATE` не конфликтует с `FOR KEY SHARE`, который берёт любая вставка с внешним ключом на `learners` (`word_repetitions`, `training_reviews` и др.), поэтому такие вставки не ждут пикер. Блокировка сериализует
   выдачу новых карточек одному человеку: две вкладки получают разные слова, а бюджет (ниже) считается после
   блокировки подзапросом в выборке нового слова и не может быть превышен совместно. Блокировки на строке `words` нет — она была бы глобальной для всех учеников.
   Вкладка, пришедшая **после** коммита первой, может получить её ещё не оценённую `new`-карточку через шаг 1 (строка
   `state = new`, `next_review_at = now` — due): это штатно, как и с любой due-карточкой — оценку примет одна вкладка,
   вторая получит 409 `card_not_due` и перезапросит `/next`. Гарантия блокировки — каждое слово **вводится** (строка
   создаётся) ровно один раз и бюджет не превышается.
   **Инвариант блокировки learner'а** (иначе возможен цикл ожидания и deadlock): под ней пикер делает только чтения и вставку
   строки `word_repetitions`; он не блокирует `word_repetitions` / `training_sessions` / `learner_daily_stats` и не меняет
   `learners`. Обратно: транзакция оценки (§5) не обновляет и не блокирует `learners` — ни явным `FOR UPDATE`, ни `UPDATE`
   (стрик, цели, `last_seen_at` и т. п. пишутся вне неё или в `learner_daily_stats`). Квота 1.4b считается обычным
   чтением под блокировкой learner'а — без `lockForUpdate` других таблиц.
   **Бюджет** (`NewCardBudget`, docs/12 §5.3): решение — `check(learnerId, now)` — вычисляется под той же блокировкой и
   **всегда** заново (никогда из памяти, docs/19 §3 п. 9); `null` — безлимит (по умолчанию и для Pro), `<= 0` — шаг пропускается,
   строка не создаётся; после вставки строки пикер зовёт `spend()`. Показ (`quota.new_words_remaining`) — `remaining(learnerId, now)`:
   число последней проверки минус выданное после неё, а если в этом запросе проверки не было (выдана due-карточка) — одно
   обычное чтение; один запрос — один подсчёт квоты (R12g). Повторения бюджетом
   **никогда** не ограничиваются. Бюджет отдаёт `NewCardBudgetResolver::budgetFor(Learner, now, ?Entitlement)` (контракт в
   `App\Domain\Learning\Contracts`; план передаётся, если вызывающий уже прочитал его в этом запросе — `TrainingFlowService`
   читает entitlement один раз на запрос для оценки, бюджета и выбора упражнения); привязан `NewWordQuota` (задача 1.4b: Pro → `unlimited()`, Free →
   `NewCardBudget::lazy(fn ($learnerId, $now) => limit − выдано сегодня)`; `UnlimitedNewCardBudgetResolver` остался для тестов),
   где «сегодня» — `LearnerClock::limitWindowUtc($learner, $now)` → `[startUtc, endUtc)` локального дня, при смене часового пояса —
   перенесённого в новый пояс (docs/12 §5.3), а «выдано» — `word_repetitions.created_at` в этих границах с `introduced_mode <> 'group_lesson'`
   (не `learner_daily_stats.new_words_count`, docs/12 §1.6). Групповые уроки (`mode = group_lesson`) бюджетом не ограничиваются:
   `TrainingFlowService` отдаёт пикеру `unlimited()`, а пикер помечает каждое введённое слово режимом сессии (`TrainingScope::$sessionMode` →
   `word_repetitions.introduced_mode`), чтобы личная квота не считала слова группового урока.
   **Граница новых слов** (daily-scope с курсом; решение владельца 13.11.2026). До транзакции пикер читает из чистого кэша
   подсказку `NewWordFrontier` — урок, где полный запрос кандидатов в прошлый раз нашёл первое не начатое слово (ключ
   `learning:frontier:v1:{ученик}:{курс}:n{версия дерева курса}`, строится до чтения строк). Под блокировкой выполняется тот же
   запрос кандидатов (с тем же подзапросом квоты), суженный `lessons.id = ?`; если строки нет (урок закончен, удалён, подсказка
   устарела) — полный запрос, и его результат (урок либо 0 — «не осталось») пишется после commit. Значение 0 — транзакция и
   запросы не нужны: ответ тот же, что дал бы полный запрос, пока не сдвинется версия или не истечёт TTL (300 с; у урока — 1800 с).
   Подсказка точна: строки `word_repetitions` приложением не удаляются, а всё, что может добавить не начатое активное слово в
   более ранний урок (слова, уроки, модули, статус, видимость и владелец курса), поднимает версию. Собственные колоды — так же.
   Lesson-scope не затрагивается. Кэшируется место поиска, а не выдача: что слово не начато и активно, курс доступен и квота
   есть, решает БД под блокировкой; худший исход потерянной инвалидации — слово позднего урока раньше раннего, двойная выдача и
   превышение квоты невозможны. Замер (50 тыс. учеников, курс 8 тыс. слов): SQL выдачи 4,9–11,4 → 1,5–1,9 мс.
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
   (lesson-scope), `hard_round_complete` (hard-scope, §14) или `nothing_due` (daily-scope). `TrainingFlowService` добавляет `session_cap`, когда достигнут
   `twa.training.max_cards_per_session` — ещё до обращения к пикеру. В ежедневной сессии со спринтом (§13.2) раньше этих
   причин проверяется `sprint_complete` (+ `canContinue = true`): лимиты раунда придержали карточку, которая есть, — due
   `review`-карточку сверх потолка или невыданное слово при оставшейся дневной квоте. Клиент получает `done_reason` и
   `can_continue` рядом с `card: null`.

`card_kind` в ответе API: `new` (state new) · `learning` (learning/relearning) · `due` (review).
`card.serve_token` — `null` для due/new карточек и для learning-карточек, чей шаг уже истёк (они due).

Таким образом сессия не завершается, пока в scope есть карточки в минутных шагах
(при `learn_ahead_minutes >= max(шаг)`). Если learn-ahead выключен (`0`), сессия завершится,
а карточка вернётся, когда наступит её срок.

**Прогресс** (`progress: {done, total}`): lesson-scope — `CardPicker::progress`: выпущенные (`repetitions >= 1`)
активные слова урока / все активные слова урока (`LearningCache::lessonWordCount`); daily-scope со спринтом — оценок в этой
сессии / оценка размера раунда (§13.2, `total` никогда не меньше `done`); daily-scope без спринта (`LEARNING_SPRINT_ENABLED=false`) —
оценок в этой сессии / `learners.daily_goal`, как раньше.

### 4.3 Типы упражнений и серверная оценка (2D.2, docs/13 §2.5)

> С 11.11.2026 тип выбирает **лестница по зрелости карточки** (§12.1); таблица ниже описывает прежнюю ротацию, которая
> остаётся за `LEARNING_LADDER_ENABLED=false`. Оценка `reverse`/`audio` теперь серверная (§12.2).

Карточка выдаётся с упражнением (`TrainingStep.prompt`, `card.prompt` в API — docs/04 A.3). Тип выбирает
`App\Domain\Learning\Services\ExerciseSelector::select(rep, word, entitlement, settings, sessionMode, example)` — **чистая
функция без состояния**, поэтому `POST /review` пересчитывает его по тем же входам, а не хранит «ожидаемый тип»
(`repetitions`/`state`/`interval_days`/`meta` меняются только после принятой оценки, а повторно оценить одну выдачу нельзя — §5.2):

| Условие | Тип |
|---|---|
| `state ∈ {new, learning, relearning}` или `repetitions < 2` | `flash` |
| `group_lesson` | `flash` / `reverse` (ротация ниже; `audio` в классе не выдаётся) |
| Pro + настройка + `repetitions ≥ 3` и `repetitions % 3 = 0` | `gap_fill`, если `GapFillBuilder` нашёл форму слова в `example`; иначе `spelling` (если включён); иначе — ротация |
| иначе | ротация `flash` / `reverse` / `audio` (из включённых в `learners.meta.exercises`): индекс `crc32("{repetitions}:{word_id}") mod n` |

`ExerciseType::serverGraded()` — `spelling`, `gap_fill`; `feature()` — `exercise.spelling` / `exercise.gap_fill` (Pro, `config/billing.php`).
`ExercisePromptBuilder` собирает экран: для `reverse` — 4 английских слова, для `audio` — 4 перевода; дистракторы — активные слова того же
урока → того же курса → опубликованных публичных курсов платформы (приватные курсы центров и колоды никогда не попадают), выбор и порядок —
по хэшу `(seed = crc32(repetitions:word_id), id/текст)`, то есть стабильны между двумя `/next` одной выдачи; при < 3 дистракторах карточка приходит как `flash`.

**Серверная оценка** (`SpellingGrader::grade(expected, answer)`): оба текста нормализуются (trim, NFKC при наличии intl, нижний регистр,
`’ ‘ ʼ ´ \`` → `'`, пробелы схлопываются), расстояние — OSA (Дамерау–Левенштейн с соседними транспозициями, каждая правка = 1);
допуск по длине **ожидаемого** текста: ≤ 4 → 0, 5–8 → 1, ≥ 9 → 2; совпадение → `quality = 5`, в допуске → `4`, иначе `1` (lapse: карточка
уходит в relearning). Для `gap_fill` ожидаемый текст — **форма из примера** (`walked`, а не `walk`). `GapFillBuilder` маскирует первое вхождение
целого слова без учёта регистра: `word`, `+s`, `+es`, `+ed`, `+d`, `+ing`, `y→ies/ied`, `e→ing`; не нашёл → `null` (селектор берёт spelling/ротацию).

В `training_reviews` каждая оценка несёт `exercise` (всегда) и `answer_text` (ответ learner'а, обрезка 100; `''` — пустой ответ; `null` — оценка
клиентом), плюс `interval_before_days`. SM-2 сам не меняется: сервер лишь подставляет `quality` в `SpacedRepetitionEngine::applyReview`.
`reverse`/`audio` оцениваются клиентом (верно → 4, неверно → 2) и приходят как обычный `quality`. Ошибки — docs/04 A.3 (`quality_not_accepted`,
`answer_not_accepted`, `exercise_pro_required`).

## 5. Приём оценки (`ReviewSubmissionService::submit`)

### 5.1 Порядок проверок
1. Сессия `open` (под `lockForUpdate`) и, для личной, `expires_at > now` (планировщик мог ещё не пройти) — иначе 410 `session_closed`.
2. Строка `word_repetitions` существует — иначе 409 `card_not_started`.
3. Слово в scope сессии (`TrainingScope::contains`: урок сессии для `group_lesson`/`lesson`, любое уже виденное слово для `daily_review`; неактивное слово — всегда вне scope) — иначе 422 `out_of_scope`.
4. Карточку **можно оценить** (§5.2) — иначе 409 `card_not_due`.
5. `SpacedRepetitionEngine::applyReview`, запись в `training_reviews`, затем `DailyStatsService::recordReview(learner, quality, timeSpentMs, isNewCard, now)` — **в той же транзакции** (`isNewCard` = карточка была в `new` до этой оценки; агрегат за день наполняет задача 1.3b, в 1.3a это хук-заглушка). Ошибка в любом шаге откатывает всё, включая оценку.

Операторы транзакции (R12g, порядок блокировок §4.2 прежний): строка сессии `FOR UPDATE` — читаются только `status` и `expires_at`
(режим и урок сессии не меняются после создания, они берутся из уже прочитанной модели; `course_id` сессии `daily_review` может смениться при `openPersonal()`, но ни scope, ни выбор упражнения его не читают); строка `word_repetitions` **вместе со
словом** одним `JOIN`-чтением под `FOR UPDATE OF word_repetitions` (`RepetitionWithWord`: строку `words` делят все ученики урока, её
блокировать нельзя; sqlite блокирует всю БД); `UPDATE` планировщика; `INSERT` в `training_reviews` обычным билдером (строка назад не
читается); затем `learner_daily_stats` (docs/12 §1.6: чтение строки дня под `FOR UPDATE` с `daily_goal` подзапросом — подзапросы не
блокируются, `learners` не трогается; `INSERT … ON CONFLICT DO NOTHING` только когда строки дня ещё нет, то есть на первой оценке дня).
План (entitlement) читается один раз на запрос в `TrainingFlowService` и передаётся сюда и в выбор следующей карточки.

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

## 5a. Игра и расписание («Караван», docs/18 §5, §12.4) — G2.2a

**Правило:** игра может только **приблизить** повторение слова, но никогда не отдалить его. Один и тот же код для SM-2 и FSRS.

- **Ошибка в забеге** (`wrong`, `skipped`, верный ответ после подсказки, `wrong_left` раунда пар, ошибка авторского задания — для всех его целевых слов) → `GameSrsAdjuster::pull(learnerId, wordIds, now)` **после commit** транзакции ответа (`DB::afterCommit`, `try / report()`):

  ```sql
  UPDATE word_repetitions SET next_review_at = :now, is_hard = true, updated_at = :now
   WHERE learner_id = :me AND word_id IN (:ids) AND next_review_at > :now;
  ```

  плюс `upsert` в `game_word_misses` (`misses + 1`, `last_missed_at`, `last_pulled_at` у задетых строк). Ничего другого не пишется: ни `state`, `repetitions`,
  `interval_days`, `easiness_factor`, `lapses`, `stability`, `difficulty`, `scheduled_days`, `last_reviewed_at`, `served_ahead_*`, ни `training_reviews`.
- **Верный ответ и `typo` в игре ничего не пишут:** интервал не растёт (выбор из вариантов — слабая проверка памяти), `last_reviewed_at` не меняется,
  `training_reviews` не пополняется — метрики точности и выполнения повторений в карточках остаются чистыми от игры.
- **Что это значит для SM-2 (§3.3):** карточка `review` с `next_review_at` в будущем становится due сейчас и приходит в `CardPicker` (трудные первыми,
  §3.5). Интервал пересчитает **обычная** оценка в карточках по сохранённому `interval_days` и EF: `round(interval_days · EF')` — приближение формулу не
  меняет, карточка получает честное «повторено раньше срока», а не «пропущено». Карточки `new` / `learning` / `relearning` в минутных шагах задеваются,
  только если шаг ещё в будущем (становятся due); `learning_step` и `state` не меняются; билет learn-ahead (`served_ahead_*`) остаётся валидным.
- **Для FSRS (§9):** `R` считается от фактического `t` с `last_reviewed_at` — меньше `t` → выше `R` → меньший рост `S` при верном ответе. Это та же формула,
  что и без игры: приближенная карточка, оценённая в момент `now`, получает ровно те же `S`, `D`, интервал, что получила бы карточка-двойник с теми же
  полями, оценённая в тот же момент.
- **`is_hard = true`** — та же семантика, что §3.5: снимается первым успешным ответом на `review`-карточку. Следствия приняты: трудные первыми у пикера,
  бонус ×1.5 в экзамене.
- **Идемпотентность:** `UPDATE` условный (`next_review_at > now`) — повтор ничего не меняет; к хуку ведёт только первый принятый запрос (повтор `request_id`
  отвечает из журнала `game_run_attempts`). **Правило §5.2** (приём оценки) не меняется: приближенная карточка оценивается как обычная due.
- **Ежедневный караван** на due-словах: верные ответы не отодвигают — слова остаются due, карточки спросят их снова (разминка, не замена).

Тест — `tests/Feature/Game/GameSrsRuleTest` (ученики SM-2 и FSRS; карточки `new` / `learning` / `review` / `relearning`; остальные колонки байт-в-байт прежние).
Инвариант «игра не трогает ничего, кроме `next_review_at`, `is_hard`, `updated_at`» — `GameIsolationTest`.
Формула «приблизить» вынесена в `App\Domain\Learning\Services\ReviewPull::toNow()` и общая с раундом трудных слов (§14, без `is_hard`).
Игра притягивает слово только по ошибке (сигнал и цена), поэтому горизонта, как у раунда трудных слов (§14), ей не нужно.

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
строки в `new` (карточка показана, но ни разу не оценена — «повторять» нечего). Слова с `is_active = false`
исключаются анти-join'ом `NOT EXISTS (SELECT … FROM words WHERE id = word_id AND is_active = false)` — под него
построен partial-индекс `words_inactive_idx` (docs/03 §2.7). Форма эквивалентна `EXISTS (… is_active = true)`
(у каждой строки ровно одно слово: `word_id` NOT NULL, FK ON DELETE CASCADE; `is_active` NOT NULL), но не требует
прохода по всем активным словам на каждый `/today`, `/me` и напоминание.

**Когда и что приходит (docs/12 §7.5, задача 1.5b).** Напоминание — одно в день, в локальное время
learner'а (`learners.reminder_time` в `learners.timezone`; `NULL` = выключено). Указатель
`learners.next_reminder_at` (UTC) считает `LearnerClock::nextOccurrenceUtc` — при выборе пояса/времени
в онбординге и `/settings`, при создании learner'а (`LearnerRegistrar`, дефолты Ташкент 19:00) и после
каждого клейма; DST и смена пояса учитываются при каждом пересчёте.

- `reminders:dispatch` — `everyFifteenMinutes()->withoutOverlapping(10)->runInBackground()` (мьютекс живёт
  10 минут: упавший запуск не блокирует расписание сутками). `ReminderDispatcher::dispatchDue(now)`
  выбирает `next_reminder_at <= now AND dm_blocked_at IS NULL AND blocked_at IS NULL` (плюс soft-delete)
  пачками `chunkById(200)` — это предикат, под который построен партиал-индекс `learners_next_reminder_at_idx`
  (PostgreSQL использует его не всегда: на маленькой или перекошенной таблице планировщик вправе выбрать
  другой план) — и клеймит каждого условным
  `UPDATE learners SET next_reminder_at = <следующее наступление от now> WHERE id = ? AND next_reminder_at = <прочитанное>`;
  только `affected = 1` ставит `SendLearnerReminderJob(learnerId, scheduledFor)`. Второй инстанс или повтор
  той же минуты видит 0 строк и ничего не дублирует. Указатель двигается **до** постановки job: сбой между
  ними теряет одно напоминание, а не удваивает его. Следующее наступление считается от `now`, поэтому после
  простоя планировщика всё догоняется одним запуском, а клеймы старше `REMINDERS_MAX_DELAY_MINUTES` (120)
  не отправляются — напоминание «в случайный час» хуже тишины. Там же backfill: у кого `reminder_time` есть,
  а указателя нет (старые строки, импорт), получают его (до `REMINDERS_BACKFILL_LIMIT` = 10000 за запуск);
  `dm_blocked_at`, `blocked_at`, удалённые — не трогаются.
- Разнос пика (все хотят 19:00): job'ы идут в очередь `default` (не `high` — ответы бота не ждут рассылку)
  с задержкой `floor(n / REMINDERS_SEND_RATE_PER_SECOND)` секунд по порядковому номеру n в запуске (дефолт 25/с,
  лимит Telegram ≈ 30/с на бота), но не больше 14 минут: клейм уже сделан, поэтому хвост пика сверх
  `rate × 840` learner'ов уходит на 14-й минуте, а не теряется. Нижние границы конфига читаются там же, где
  используются: `max_delay_minutes` ≥ 15, `min_interval_hours` ≥ 1, `send_rate_per_second` ≥ 1.
- Текст решает job в момент отправки (`ReminderContentResolver`): `dueCount >= REMINDERS_DUE_THRESHOLD`
  (**1**, было 5 — в B2C одно слово к повторению уже повод вернуться) → `reminders.review`; иначе серия под
  угрозой (`StreakService::current().atRisk`) → `reminders.streak_at_risk`; иначе молчание (указатель уже
  на завтра — это и нужно). Кнопка → `/twa/?from=reminder`, экран «Сегодня» сам открывает `daily_review`
  (`source: 'reminder'`). Незавершённый онбординг не мешает: learner из группы получает одну попытку с
  дефолтами, 403 → `dm_blocked_at`, дальше тишина до его первого сообщения боту.
- Второй уровень идемпотентности — в job (`ReminderSender`, `Cache::lock` на learner'а), по
  `notifications_log` (`review_reminder` / `streak_reminder`): **не больше одного напоминания любого вида за
  локальный день learner'а** (`sent_at >= LearnerClock::dayBoundsUtc().start`) и страховочный минимум
  `REMINDERS_MIN_INTERVAL_HOURS` (2) между любыми двумя напоминаниями (полночь, смена времени). Окно «20 ч»
  не годится: напоминание в 19:00, перенос на 08:00 — и завтра в 08:00 оно бы не пришло. Повтор job, двойная
  доставка — одно сообщение.
- Семантика «не больше одного раза»: строка `notifications_log` пишется **до** вызова Telegram (под тем же
  lock'ом). Двусмысленная ошибка (таймаут, 5xx — сообщение могло дойти) строку оставляет и пробрасывается, а
  повтор job видит строку и заканчивает `SkippedRecent`: лучше пропустить напоминание, чем прислать два.
- Telegram 403 (`bot_blocked`, `not_started`, `user_deactivated`) или 400 `chat not found` (удалённый
  аккаунт; `reason = chat_not_found`) — «точно не доставлено»: строка удаляется, `dm_blocked_at = now`,
  `reminder.failed {reason, kind}`, без повторов. Флаг снимает любое входящее сообщение/кнопка в ЛС
  (`TelegramDispatcher`, до хендлеров) и `my_chat_member` `member` в личном чате; `my_chat_member` `kicked`
  в личном чате (пользователь заблокировал бота) выставляет его сразу, без ожидания первого 403
  (`DmLearnerResolver`, learner не создаётся).

**Legacy backfill.** Миграция `add_learning_state_to_word_repetitions` переводит строки
`repetitions = 0 AND last_quality IS NOT NULL` (проваленные в старом SM-2, у них `next_review_at`
был «завтра») в `relearning`. По правилу выше они снова попадают в напоминания через
`abandoned_after_hours` после своего `next_review_at`; слова не теряются.

> **Изменение 2026-11-16 (решение владельца): напоминания о перерыве.** Режим «умеренный»: не больше одного сообщения в день, особый текст при перерыве, давно ушедшим — реже, после 60 дней — тишина (иначе бота блокируют). `idle` = число локальных дней (`LearnerClock`) между сегодня и последним днём с `learner_daily_stats.reviews_count > 0` (один запрос `max(date)`; не занимался никогда — от локального дня `created_at`; занимался сегодня — 0). `ReminderContentResolver` берёт первое подходящее правило:
>
> | # | Условие | Вид (`ReminderKind`, `notifications_log.type`) |
> |---|---|---|
> | 1 | `idle > REMINDERS_STOP_AFTER_DAYS` (60) | тишина |
> | 2 | `idle > REMINDERS_DAILY_UNTIL_DAYS` (7) и `idle` не в `REMINDERS_SPARSE_DAYS` (`10,14,21,30,45,60`) | тишина в этот день |
> | 3 | `idle >= REMINDERS_WINBACK_FROM_DAYS` (3) | `inactive` / `inactive_reminder` («вы N дней не занимались», плюс число слов к повторению, если они есть); не занимался никогда — `never_started` / `never_started_reminder` |
> | 4 | `due >= due_threshold` | `review` / `review_reminder` |
> | 5 | серия под угрозой | `streak_at_risk` / `streak_reminder` |
> | 6 | сегодня нет оценённых карточек: никогда не занимался → `never_started`; иначе → `new_words` / `new_words_reminder` | см. справа |
> | 7 | иначе (сегодня уже занимался, повторять нечего) | тишина |
>
> Ученик без активного курса (`active_course_id IS NULL`) вместо `never_started` / `new_words` получает `choose_course` / `choose_course_reminder` («Выберите курс»); остаток непройденных слов курса не считается. Правило «одно в локальный день» и клейм `next_reminder_at` не менялись; `notifications_log.payload` дополнен `idle_days`.

> **Изменение 2026-11-17: вопрос в напоминании.** Напоминание может быть вопросом с четырьмя кнопками прямо в чате (docs/12 §7.5). Ответ в чате **не меняет** `word_repetitions` и `learner_daily_stats` (и не пишет в `training_sessions` / `training_reviews`): ни шага SM-2/FSRS, ни серии, ни цели дня, ни очков рейтинга. Слово — due по `DueRepetitionCriteria` (то же определение, что число в напоминании), иначе ближайшее к сроку из уже отвеченных; варианты строит `ExercisePromptBuilder` (§12.2).

## 6a. Заморозка серии (streak freeze, Pro) — 2C.2

Правила streak — docs/12 §7.4; здесь — что меняет заморозка (docs/13 §2.4, §7.2). Код: `App\Domain\Engagement\Services\StreakFreezeService`
(инвентарь и факт), `StreakService` (когда замораживать и как считать).

- **Удержанный день.** Локальный день удержан, если `learner_daily_stats.goal_met_at IS NOT NULL` **или** `game_held_at IS NOT NULL`
  (первый завершённый забег «Каравана», G2.10 — docs/18 §12.6) **или** `frozen_at IS NOT NULL`.
  `learners.streak_last_date` — последний удержанный день (не только «цель выполнена»). Серия = число дней с выполненной
  целью **или забегом** в непрерывной цепочке удержанных дней: замороженный день **не обрывает** цепочку и **не прибавляет** к `streak_current`
  (`+0`, `best` не меняется); пересчёт по фактам (`factRunEndingOn`) идёт по той же цепочке — замороженные дни «мосты», не очки.
  Чем удержан день — enum `StreakHeldBy {goal, game, freeze}` (`fromRow`: приоритет цель → игра → заморозка); его принимает проекция.
- **Когда тратится.** Только у learner'а с фичей `streak.freeze` (Pro, личный или спонсорский; `config('learners.streak_freeze.max') = 0`
  выключает для всех) и при пропуске **N дней подряд, если заморозок ≥ N** (решение владельца, 2C.6): тратится N, по одной на
  каждый пропущенный локальный день, серия сохраняется. Заморозок **меньше N** — серия сгорает, **ни одна не тратится**
  (всё или ничего); больше `max` (2) дней — тоже сгорает. Сгорание фиксируется сразу (2C.8): при обнаружении
  `streak_current = 0` пишется в БД, и пополнение заморозок позже серию **не** восстанавливает (из 0 заморозка не тратится). Повторное чтение/повторный зачёт цели те же дни уже видят удержанными
  и ничего не тратят.
  Триггеры: чтение `StreakService::current()` (экран «Сегодня», `/finish`, напоминания) и зачёт сегодняшней цели
  `apply(today)` из `afterCommit`-колбэка. Отставшая проекция (потерян колбэк за `today − 2`) сначала чинится по факту, потом замораживается.
- **Как тратится** (`tryFreeze(Learner, 'Y-m-d', now): bool` — обёртка над `tryFreezeMany(Learner, list<'Y-m-d'>, now): bool`, своя короткая транзакция, **никогда** внутри транзакции оценки):
  `UPDATE learners SET streak_freezes = streak_freezes − N WHERE id = ? AND streak_freezes >= N` (N = число пропущенных дней) (берёт строку `learners` первой —
  тот же первый замок, что у пикера, порядок §4.2 сохранён) → `INSERT … ON CONFLICT DO NOTHING` строки `(learner, вчера)` со
  счётчиками 0 → `UPDATE learner_daily_stats SET frozen_at = now WHERE … AND frozen_at IS NULL AND goal_met_at IS NULL AND game_held_at IS NULL`
  (affected ≠ 1 → откат, заморозка возвращается; день, удержанный забегом, не замораживается — и при гонке «чтение ∥ завершение забега», G2.10).
  Поэтому две параллельные `current()` тратят ровно одну заморозку
  (`StreakFreezeConcurrencyTest`, PG), а заморозка без факта невозможна. Затем `StreakService` продвигает проекцию
  (`streak_last_date = вчера`, `streak_current` без изменений, событий `daily_goal.met`/`streak.extended` нет) и пишется событие
  `streak.frozen {date, freezes_left}`. Цель, выполненная «задним числом» на уже замороженную дату (переезд на запад), ставит
  `goal_met_at` в ту же строку — день считается выполненным, заморозка потрачена зря; принимаем. То же с забегом, стартовавшим
  до полуночи и завершённым после того, как чтение заморозило его `local_date`: `game_held_at` ложится в замороженную строку,
  `+1` проекция не даёт (`last >= date`), пересчёт по фактам день считает — та же принятая аномалия.
- **Пополнение** (`refill(Learner, today)`, лениво в `TodayService::summary`, после «закрепления» сгорания чтением `current()`): +1 за локальную неделю (понедельник —
  `startOfWeek` локального дня learner'а), до `max` (2). Неделя помечается её понедельником в `streak_freezes_refilled_on`
  **и при полном инвентаре** — потраченная во вторник заморозка не возвращается в среду; первое открытие экрана в четверг
  даёт пополнение этой недели. Смена часового пояса сдвигает «сегодня» максимум на день: та же или более ранняя неделя
  пополнения не даёт (`refilled_on < понедельник` ложно), более поздняя — одна честная. Free: ни пополнения, ни траты;
  `/today.streak.freezes = 0`; остаток после окончания Pro хранится и оживает с новым Pro.
- **Смена пояса и заморозка.** Дата замораживается не больше одного раза (`frozen_at` под уникальностью `(learner_id, date)`);
  переезд на запад снова открывает замороженный день как «сегодня» (`met_today = true`, второй траты нет), переезд на восток
  может открыть следующий день раньше — та же «мелкая аномалия», что у самого streak (docs/12 §7.4), ограниченная
  интервалом смены пояса (`LearnerSettingsService`).

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
- `tests/Feature/Engagement/StreakFreezeTest.php` (§6a) — трата при пропуске N дней подряд только если заморозок ≥ N (2C.6: по одной на день, иначе ничего не тратится; 0 пропусков — нет), только Pro,
  нет заморозок → обнуление, один раз на дату при повторных чтениях, возврат при фактически выполненной цели, заморозка из
  `afterCommit` после зачёта цели (внутри транзакции оценки ничего не меняется — тест на порядок), пересчёт по фактам с
  «мостами», починка потерянного замороженного «вчера» без зачёта, два дня подряд двумя заморозками, пополнение по неделям
  (повтор/полный инвентарь/смена пояса — без второго), запад/восток без второй траты, `GET /today`, `max = 0`.
  `StreakFreezeConcurrencyTest.php` (PG, fork) — 4 параллельных `current()` → `streak_freezes −1`, одна строка `frozen_at`,
  одно событие; `apply(today)` ∥ `current()` → одна трата, серия 4.
- `tests/Unit/TrainingScopeTest.php` — `TrainingScope`, `NewCardBudget`, инвариант `mode_shape`.
- TWA `FlashCard.spec.ts` — кнопки шлют 2 / 4 / 5, метка «Закрепление» для `learning`;
  `stores/__tests__/training.spec.ts` — `serve_token` карточки уходит в `POST /review`.

## 8. Аналитика на основе SM-2

- **Трудное слово для группы** — где у ≥ 40% студентов `training_reviews.quality < 3` (при min. 10 попыток).
- **Прогресс студента** — средний EF по всем его карточкам (растёт = прогресс); число карточек в `review`.
- **Leech-кандидаты** — `lapses >= 5`.
- **Heatmap активности** — количество `training_reviews` на студента по дням недели.

## 9. FSRS-5 как A/B с SM-2 (2D.3, docs/13 §2.5, §8.3)

FSRS (Free Spaced Repetition Scheduler) работает **рядом** с SM-2 за интерфейсом
`App\Domain\Learning\Contracts\SchedulerAlgorithm` (`key()`, `schedule(WordRepetition, int $quality, CarbonImmutable $now)`);
реализации: `Sm2Scheduler` (тонкая обёртка над `SpacedRepetitionEngine::applyReview`, поведение SM-2 не менялось ни на бит)
и `FsrsScheduler`. Алгоритм learner'а выбирает `SchedulerResolver::for($learner)`: `learners.scheduler`, а при `NULL` —
`config('learning.algorithm')`. `ReviewSubmissionService` применяет его внутри той же транзакции оценки (§5) и пишет
выбранный ключ в `training_reviews.scheduler`.

### 9.1 Бакет A/B

`learners.scheduler` (`sm2` / `fsrs`) ставится **один раз**, когда `LearnerRegistrar` создаёт learner'а:
`crc32(telegram_user_id) % 100 < FSRS_ROLLOUT_PERCENT` → `fsrs`, иначе `sm2`. `rollout_percent` по умолчанию **0** (никто),
`100` — все новые. Хэш стабилен: один и тот же Telegram id при одном проценте всегда попадает в один бакет;
изменение процента **не двигает** существующих learner'ов (только новых). Единственный способ перенести существующего —
переключение администратором (§9.5). Откат: `FSRS_ROLLOUT_PERCENT=0` и `UPDATE learners SET scheduler = 'sm2'`
(колонки SM-2 FSRS не портит, §9.4).

### 9.2 Общая машина минутных шагов

Карточки `new` / `learning` / `relearning` ведёт **общая** `LearningStepMachine` (те же `learning_steps`,
`relearning_steps`, `graduating_interval_days`, `easy_interval_days`, §3.2, §3.4) для обоих алгоритмов. FSRS считает только
интервал карточек в `review`. Поэтому «первое повторение» после выпуска всегда 1 (или 4 при Easy) день, как в SM-2;
различие A/B — в интервалах после него. Провал карточки `review` в FSRS: `repetitions = 0`, `interval_days = 0`,
`lapses += 1`, `is_hard = true`, relearning-шаги общей машины.

### 9.3 Формулы (FSRS-5)

Маппинг `quality` → rating: **0..2 → 1 Again**, **3 → 2 Hard**, **4 → 3 Good**, **5 → 4 Easy**
(кнопки UI дают 2 / 4 / 5, q=3 — для API).

```
R(t, S)   = (1 + FACTOR · t / S) ^ DECAY                   DECAY = −0.5, FACTOR = 19/81;  t — дни с прошлого ответа (дробные)
D0(G)     = w4 − e^(w5 · (G − 1)) + 1                      начальная сложность, S0(G) = w[G − 1]
D'        = D + (−w6 · (G − 3)) · (10 − D) / 9             линейное демпфирование
D''       = w7 · D0(4) + (1 − w7) · D'                     возврат к среднему, зажим 1..10
успех     S' = S · (e^w8 · (11 − D) · S^(−w9) · (e^(w10 · (1 − R)) − 1) · [w15 при Hard] · [w16 при Easy] + 1)
провал    S' = min(S, w11 · D^(−w12) · ((S + 1)^w13 − 1) · e^(w14 · (1 − R)))
интервал  I(r, S) = S / FACTOR · (r ^ (1 / DECAY) − 1)     округление, минимум 1, максимум max_interval_days; I(0.9, S) = S
```

`S'` считается по сложности **до** обновления (как fsrs-rs / ts-fsrs). Веса `w17`, `w18` (повторы в тот же день)
не используются: повторы в тот же день у нас — минутные шаги. Стабильность зажата в `0.01..36500`, сложность — в `1..10`.
Cap `LEARNING_MAX_INTERVAL_DAYS` (§3.1) режет только `interval_days` / `next_review_at`; `stability` хранится реальная.

**Параметры** (`config/learning.php` → `fsrs.*`, класс `FsrsParameters` проверяет их при старте, как `LearningPolicy`):
`FSRS_ROLLOUT_PERCENT` (0..100, по умолчанию 0), `FSRS_DESIRED_RETENTION` (0 < r < 1, по умолчанию **0.9**),
`FSRS_WEIGHTS` — 19 чисел через запятую. По умолчанию — официальные веса FSRS-5 по умолчанию
(open-spaced-repetition: fsrs-rs `DEFAULT_PARAMETERS` / ts-fsrs `default_w` / fsrs4anki wiki «The Algorithm»):
`0.40255, 1.18385, 3.173, 15.69105, 7.1949, 0.5345, 1.4604, 0.0046, 1.54575, 0.1192, 1.01925, 1.9395, 0.11, 0.29605, 2.2698, 0.2315, 2.9898, 0.51655, 0.6621`.
Оптимизация весов на данных LexiFlow — отдельная будущая работа (нужно 100k+ ответов, docs/08).

### 9.4 Память карточки и инициализация из SM-2

Колонки `word_repetitions`: `stability` (дни), `difficulty` (1..10), `scheduler` (кто писал строку последним),
`scheduled_days` (интервал, назначенный FSRS, для метрик; 0 при провале). FSRS пишет `interval_days`,
`next_review_at`, `repetitions`, `lapses`, `is_hard` так же, как SM-2, и **никогда не меняет `easiness_factor`**.

- Свежая карточка, выпущенная под FSRS: `stability = S0(G)`, `difficulty = D0(G)` оценки выпуска.
- Карточка, которую до этого вела SM-2 (первое FSRS-повторение или переключение learner'а):
  `stability = max(1, interval_days)`, `difficulty = clamp(1..10, 5 + (2.5 − EF) · 4 / 1.2)` (EF 2.5 → 5, 1.3 → 9, 3.7 → 1).
- Карточка, провалённая под SM-2 и доученная под FSRS, стартует с того же SM-2-посева; провалённая под FSRS
  сохраняет послепровальную память.

Эталонная последовательность (юнит-тест `FsrsSchedulerTest`): новая карточка, Good, Good (выпуск, S = 3.173, D = 5.2824) →
через 1 день Good: S = 5.8691, интервал 6 → через 6 дней Easy: S = 47.6568, интервал 48 → через 48 дней Hard: S = 70.5796, интервал 71 →
через 71 день Again: S = 5.1445 (< 70.58), D = 6.8642.

### 9.5 Переключение администратором

Filament → Learners → кнопка **SRS algorithm** (только admin; `SchedulerSwitchService::switchTo`) в одной транзакции
с `FOR UPDATE` на learner'е: пишет `learners.scheduler`, запись аудита `learner.scheduler_changed`
(`meta {from, to, initialized_cards}`; повторное переключение на тот же алгоритм ничего не пишет) и, при переходе
на FSRS, инициализирует память выпущенных (`review`) карточек без неё по формуле §9.4 (порциями по 500).
Переход обратно на SM-2 ни одну карточку не трогает. Порядок блокировок: `learners`, затем `word_repetitions`
(как у `CardPicker`, §4.2).

### 9.6 Метрики A/B

Виджет «SM-2 vs FSRS» (Filament, только admin; `SchedulerMetricsService`, окно **28 дней**), по `training_reviews.scheduler`:
pass-rate «созревших» карточек `review` (`interval_before_days >= 1`, `quality >= 3`); повторений на выученное слово
(оценки в окне / различные карточки с `repetitions >= 1`); удержание D7 по бакету `learners.scheduler`
(созданные 28..7 дней назад и оценившие карточку через 7+ дней после регистрации).

## 10. Будущие улучшения (не MVP)

- Учёт времени ответа (`time_spent_ms`): если ответил «легко» за 10 секунд — подозрительно, сбавить EF прирост.
- Четвёртая кнопка «С трудом» (q=3) — если будет запрос; API уже поддерживает.
- Оптимизация весов FSRS на данных LexiFlow (когда накопится 100k+ ответов) и переход на FSRS по умолчанию по итогам A/B (§9).
- Fuzzing интервалов: к `next_review_at` добавлять случайный ±10%, чтобы не возникало «стен» в один день.
- «Своё предложение» для зрелых слов (docs/25 §3.8).

## 12. Лестница упражнений, трудные слова, подсказки (docs/25 §3; 11.11.2026)

Все настройки — `config/learning.php` → `App\Domain\Learning\Support\LadderPolicy` (читается на каждый запрос, не singleton);
значения по умолчанию включают всё, кроме предтеста и ввода для Free. Расписание (SM-2 / FSRS, §3, §9) **не меняется**: ниже
только о том, какое упражнение показывается и кто ставит оценку.

### 12.1 Лестница (`ExerciseSelector`, `learning.ladder.*`)

> С 17.11.2026 поверх этой лестницы работает **ранняя лестница** (§13.1): карточки в минутных шагах и первые дневные
> повторения приходят объективными упражнениями, а на ступенях 2–3 чередование идёт по последнему упражнению слова, а не по
> чётности. Таблица ниже — лестница без ранней части (`LEARNING_LADDER_EARLY_ENABLED=false`) и правила ступеней/пола, на
> которые ранняя лестница опирается. Порог ввода `typed_from_interval_days` с той же даты **6** (было 21).

Ступень карточки `review` = `max(зрелость, пол)`:

| Ступень | Условие по зрелости | Упражнение |
|---|---|---|
| 0 | `repetitions < meaning_from_repetitions` (2) | `flash` — самооценка |
| 1 | `repetitions ≥ 2` | `meaning` — **выбор значения**: слово показано (с транскрипцией и озвучкой, как `flash` до раскрытия), ученик выбирает перевод из 4; чередования нет. Нужны `meaning_enabled` (конфиг) и настройка ученика; доступно всем тарифам и в `group_lesson` |
| 2 | `repetitions ≥ choice_from_repetitions` (3) | `reverse` / `audio` поочерёдно по чётности `repetitions + word_id` (выключенный в настройках тип уступает другому; в `group_lesson` только `reverse`) |
| 3 | `interval_days ≥ typed_from_interval_days` (21) | `gap_fill`, если форма слова есть в примере этой выдачи, иначе `spelling`; какой из двух предпочесть — по той же чётности. Нужны фича Pro (`exercise.spelling`/`exercise.gap_fill`) **или** `typed_recall_in_free = true`, и настройка ученика. В `group_lesson` ввода нет |

**Правило «ступенью ниже».** Если на ступени карточки упражнения нет (выключено учеником или конфигом, нет в тарифе, не для режима сессии),
карточка выдаётся упражнением **ближайшей ступени ниже**, а не сразу `flash`: Free на ступени 3 получает варианты; выключены оба
варианта → `meaning`; выключен и он → `flash`. Так тип карточки всегда — самое высокое доступное упражнение не выше её ступени,
и ни одна ступень не перепрыгивается. Почему `meaning` — отдельная ступень, а не третий участник чередования ступени 2: узнать значение
по слову легче, чем вспомнить форму по значению (docs/25 §3.1: узнавание раньше вспоминания), и в SM-2 ступень 2 для Pro длится один проход
(интервал 15 дн. < 21) — в чередовании слово получало бы одно из трёх упражнений без порядка по сложности, а шаг назад с вариантов
вёл бы сразу к самооценке; с отдельной ступенью лестница для Pro — `flash` (1 дн.) → `meaning` (6 дн.) → `reverse`/`audio` (15 дн.) →
ввод (37+ дн.), для Free — `flash` → `meaning` → варианты.

Карточки `new`/`learning`/`relearning` — всегда `flash` (предтест — §12.7). **Шаг назад при ошибке:** провал (`quality < 3`)
карточки `review` пишет `word_repetitions.meta.ladder_floor = ступень − 1` (в том же `UPDATE`, что делает планировщик), и после
переучивания карточка выдаётся не ниже этой ступени, хотя `repetitions`/`interval_days` сброшены; каждый следующий провал опускает ещё на
ступень: ввод → варианты → значение → `flash` (пол 0 не хранится). Пол снимается, когда зрелость снова дорастает до него. Пол хранится
номером ступени в нумерации выше; миграция `2026_11_12_100002` подняла прежние значения 1/2 на единицу (откат возвращает, пол на новой
ступени 1 при откате снимается). `meta.last_exercise` — тип последней оценки (для метрики §12.2). При < 3 дистракторах карточка
по-прежнему приходит как `flash` (`ExercisePromptBuilder::effectiveType`).

**Выкат.** Тип уже идущих карточек может смениться (карточка на `repetitions = 2` была `flash`, станет `meaning`): это допустимо, сервер
ничего не хранит между `/next` и `/review`; запрос `/review` старого клиента с `exercise`, не совпадающим с пересчитанным, получает 409
`exercise_mismatch`, а не неверную оценку. Старый клиент на неизвестный `prompt.type` показывает экран «Обновите приложение» с кнопкой
закрытия (как `flash` показать нельзя — ответ в карточке скрыт). Выключить тип целиком: `LEARNING_LADDER_MEANING_ENABLED=false`.

### 12.2 Объективная оценка (`ReviewSubmissionService`, `learning.objective_grading.*`)

Самооценка (`quality` от клиента) принимается **только для `flash`**. `meaning`/`reverse`/`audio` присылают выбранный вариант как `answer`;
сервер сравнивает его с правильным (reverse — `words.word`, meaning/audio — перевод в локали сессии; нормализация `SpellingGrader::normalize`)
и ставит **4** (верно) / **2** (неверно); ответ `graded {quality, correct, expected}` приходит и для них, `answer_text` хранит выбранный
вариант. Переходный режим `accept_legacy_quality = true` (по умолчанию): `quality` от старого клиента на карточке `reverse`/`audio` принимается,
но нормализуется до 4/2 («Легко» получить нельзя); `false` → 422 `quality_not_accepted`. Выключить после выката TWA, который шлёт `answer`.
`meaning` появился после этого перехода и `quality` не принимает никогда (422 `quality_not_accepted` при любом значении флага).

Карточка `meaning`/`reverse`/`audio` приходит **без** того, что выдаёт правильный вариант (reverse: `word`, пример, транскрипция, аудио; meaning и audio:
перевод и пример; крючки, заметка и `confused_with` — у всех); всё это возвращается в `revealed` ответа `/review`, цвета вариантов клиент ставит
по `graded`/`revealed.correct` (docs/04 A.3). Варианты `meaning` строятся тем же `ExercisePromptBuilder`, что у `audio`/`pretest`: пул дистракторов
урока из кэша, переводы одним MGET, дедупликация без учёта регистра и пробелов — второе слово с тем же переводом не даёт второго верного варианта.
Выключив в настройках все упражнения с проверкой, ученик остаётся на самооценке `flash` — это допустимо и считается метрикой честности (ниже).
Те же варианты (`meaning`, при выключенном `meaning` — `reverse`) идут в чат как вопрос напоминания бота (docs/12 §7.5): тот же `ExercisePromptBuilder` и фильтр общих смыслов, но ответ там проверяется по индексу из состояния вопроса и **не** оценивается как повторение (§6).

**Метрика честности самооценки** (docs/25 «Общие метрики»): если неверный объективный ответ (вариант или ввод) следует за самооценкой
`flash` с `quality ≥ 4` по тому же слову (`last_quality` + `meta.last_exercise` из той же строки — без лишних SQL), после коммита пишется
событие `review.self_grade_mismatch {word_id, exercise, previous_quality, interval_before_days}`. Доля = события / неверные объективные
ответы за период; то же считается и офлайн по `training_reviews` (LAG по `(learner_id, word_id)`).

### 12.3 Крючки памяти (`learning.hooks.*`)

- **Авторский** — `word_translations.hook` (string 500, на язык ученика; без fallback между языками: русская звуковая ассоциация
  узбекскому читателю не помогает). Импорт: ключ `hooks: {ru, uz}` у слова (ключ есть → перезаписывает, в т. ч. снимает отсутствующую
  локаль; ключа нет → не трогается; у языка должен быть перевод). Админка: поле «Крючок памяти» в переводах слова. Чтение —
  `WordHookResolver` (кэш `word:hook:v1:{word_id}` на все локали, TTL 2 ч, сброс наблюдателем `WordTranslation`).
- **Свой** — `word_repetitions.note` (string 200, простой текст: теги и управляющие символы вырезаются, пробелы схлопываются, длина режется).
  `PUT /api/twa/words/{word}/note {note: string|null}` → `WordNoteService::save` — один условный `UPDATE` строки ученика, без блокировки
  `learners`; слово, которое ученику не выдавалось — 404 `card_not_started`; лимит `throttle:twa-word-notes` (30/мин на ученика).
- **Показ** (`card.hook`, `card.note`): крючок читается и отдаётся только когда карточка `new`/`learning`/`relearning`, `is_hard` или
  «трудная» (§12.6) — обычный `review` его не читает (ноль обращений к кэшу); `note` отдаётся всегда, когда есть. На карточках с вводом
  (`spelling`/`gap_fill`) и на предтесте оба скрыты — они выдают ответ. Все поля карточки добавлены к контракту, прежние не менялись:
  `is_leech`, `is_hard`, `hook`, `note`, `confused_with`.

### 12.4 Несколько примеров (`learning.examples.rotate`)

`words.example` остаётся **якорем** (позиция 0, без миграции данных); дополнительные — `word_examples (word_id, position 1.., text,
translations json)` (docs/03 §2.21a), импорт — ключ `examples: [{text, translations: {ru, uz}}]` (заменяет набор; `[]` удаляет).
`WordExamples::pick(word, rep)`: пока карточка не в `review` — всегда якорь; после — `[якорь, …дополнительные][repetitions mod n]`,
то есть два соседних повторения показывают разные предложения, а `gap_fill` режется из предложения, которого на прошлом повторении
не было. Детерминировано: `POST /review` берёт тот же пример и проверяет форму из него. Кэш `LearningCache::wordExamples`
(`word:examples:v1:{word_id}`, массивы, TTL 1 ч, пустой список — тоже запись; сброс `forgetWordExamples` после коммита импорта).
Без дополнительных примеров поведение прежнее. `card.example` — пример этой выдачи.

`card.example_translation` — перевод ИМЕННО этого примера на язык сессии (откат на ru, как у перевода слова, без второго механизма):
якорь берёт `word_translations.example_translation` (читается тем же запросом и тем же кэшем `word:tr:v2:*`, что и перевод слова:
`ResolvedTranslation::$exampleTranslation`, дополнительных запросов нет), ротируемый — `word_examples.translations[locale]` своего
предложения (`WordExamples::pickRow`), никогда не перевод якоря. Нет перевода — `null`. Скрыт вместе с `example`: у
`meaning`/`reverse`/`audio`/`pretest`/`spelling`/`gap_fill` в карточке `null` (перевод предложения с пропуском или примера у reverse выдал бы
ответ), а для `meaning`/`reverse`/`audio` приходит в `revealed.example_translation` ответа `/review`. У `spelling`/`gap_fill`/`pretest`
`revealed` нет и пример после ответа не показывается.

### 12.5 Путаемые слова (`CardPicker`, `learning.confusables.*`)

Разметка — `words.meta.confusable_with: ["father", …]` (импорт: ключ `confusable_with`, слова того же курса по тексту; `Word::confusableWith()`).
При выдаче нового слова пикер читает `lookahead` (5) первых кандидатов **тем же одним оператором**, и если среди них есть размеченные —
одним чтением берёт слова, введённые ученику за `window_hours` (20 ч), с их разметкой; кандидат пропускается, если он называет недавнее слово
или недавнее слово называет его. Первый неблокированный идёт в выдачу; если блокированы все пять — выдаётся первый (правило дневное, урок
не останавливается). Порядок урока иначе не меняется; `group_lesson` правило не применяет. Эвристика «та же часть речи + лексическая группа»
не реализована: в уроках-семьях она блокировала бы почти всё.

### 12.6 Трудные слова — «пиявки» (`learning.leech.threshold`)

`is_leech = lapses ≥ threshold` (4; 0 — никогда). Слово **не** приостанавливается: меняется подача — чип «Трудное слово» в TWA, крючок (§12.3),
другой пример (§12.4) и `card.confused_with {word, translation|null}`: сначала первая метка `confusable_with`, иначе самый частый неверный выбор
ученика на `reverse` по этому слову из последних пяти (`training_reviews.answer_text`, **один** дополнительный SQL только для «пиявок»);
перевод пары — из кэшированного пула дистракторов урока, без запроса. Учителю (Filament) — отдельная задача.

### 12.7 Предтест нового слова (`learning.pretest.enabled`, по умолчанию выкл.)

Тип `pretest`: карточка `new` (не в `group_lesson`) приходит со словом и 4 переводами, перевод и пример скрыты; ответ — `answer`
(вариант или `''` = «не знаю»). Верно → `quality 5` (выпуск с `easy_interval_days`, «уже знал»); неверно → `quality 2` (минутные шаги,
обычное знакомство), в `graded.expected` — правильное значение. `quality` от клиента → 422. После первой оценки слово — обычная карточка.

### 12.8 Цель удержания FSRS (`learning.fsrs.retention_modes`)

`learners.meta.retention_mode` ∈ `calm` 0.85 / `normal` (= `FSRS_DESIRED_RETENTION`, 0.9) / `intense` 0.93; `PATCH /me/settings {retention_mode}`,
в `settings` — `scheduler` (`sm2`/`fsrs`), `retention_mode`, `options.retention_modes`. `SchedulerResolver::for()` отдаёт FSRS с этой целью
(`FsrsScheduler::withDesiredRetention`); для ученика на SM-2 настройка хранится, но ничего не меняет. `FSRS_ROLLOUT_PERCENT` не трогался.

### 12.9 Стоимость на горячем пути

`/review` и `/next`: без новых SQL для обычной карточки (крючок, пример, пол лестницы — из той же строки `word_repetitions` и чистого кэша);
+1 SQL только для «пиявки» (§12.6) и только при разметке `confusable_with` среди первых пяти кандидатов нового слова (§12.5). Бюджеты
`TrainingCycleQueryBudgetTest` не менялись; карточка `meaning` стоит не больше операторов, чем `audio` (тот же путь — проверяется в
`LearningMethodTest`). Тесты: `tests/Unit/ExerciseLadderTest.php`, `tests/Feature/LearningMethodTest.php`.

## 13. Изменение 2026-11-17 (решение владельца): ранняя лестница, спринт, стадии слова

Причина: первые тестовые пользователи назвали занятие скучным. Аудит показал два корня: всю первую неделю карточка одна и та же
(`flash` с самооценкой, новое слово показывается сразу с ответом), а у ежедневного занятия нет конца (Pro без лимита новых слов,
счётчик «35 / 20»). Настройки — `config/learning.php` → `ladder.early.*`, `sprint.*`, `stages.*`; прежнее поведение возвращают
`LEARNING_LADDER_EARLY_ENABLED=false` и `LEARNING_SPRINT_ENABLED=false`. Расписание (SM-2 / FSRS, §3, §9) и машина шагов (§9.2) не
менялись; миграций нет — существующие ученики просто получают форматы по новым правилам.

### 13.1 Ранняя лестница (`ExerciseSelector`, `learning.ladder.early.*`)

- **Первое знакомство** (`state = new`) — по-прежнему `flash` с открытыми переводом и примером: это обучение, а не проверка;
  кнопки «не запомнил / запомнил / уже знал» (2 / 4 / 5) остаются, «уже знал» выпускает слово на `easy_interval_days`.
  Предтест (§12.7), если включён, по-прежнему заменяет первый показ.
- **Все последующие выдачи** слова — объективные упражнения, которые оценивает сервер (4 / 2, §12.2), а машина шагов
  (§3.2, §3.4) двигает карточку по этой оценке как по самооценке: верно → следующий шаг / выпуск, неверно → шаг 0 и `is_hard`.
  Так работают оба шага обучения (1 и 10 мин) в той же сессии, переучивание после провала и дневные повторения.
- **Списки-циклы.** У каждой стадии свой список предпочтений (env, через запятую): `recognition` — карточки в минутных шагах
  (`learning` / `relearning`) и `review`-карточки ступеней 0–1 (по умолчанию `meaning,reverse,audio`); `form` — ступень 2
  (`reverse,audio,flash`); ступень 3 — `gap_fill,spelling` (не настраивается). Выдаётся **следующий после последнего
  упражнения слова** (`word_repetitions.meta.last_exercise`, §12.1) элемент списка по кругу, пропуская недоступные (план,
  настройки ученика `exercises.*`, `meaning_enabled`, нет аудио, в примере нет формы слова); последнее упражнение не из
  списка — с начала списка. Поэтому одно слово никогда не приходит дважды подряд в одном формате, а все форматы списка
  получают свою очередь. Ступень, у которой доступен только последний выданный формат, одалживает у ступени ниже
  (Pro без формы слова в примере: `spelling` → `reverse` → `spelling`); если ничего не осталось — `flash` (ученик, выключивший
  всё объективное: `meaning` → `flash` → `meaning`). Пол после провала (§12.1) и ступени по зрелости сохранены: карточка
  обслуживается списком своей ступени `max(зрелость, пол)`, при недоступности — списком ниже, как «ступенью ниже» в §12.1.
- **Audio только с файлом** (`audio_requires_file = true`): Mini App без `audio_url` озвучивает через Web Speech, чей голос на
  устройстве не гарантирован, а немая карточка на слух неразрешима; в поставляемых курсах файлов пока нет
  (`php artisan audio:pregenerate <курс>`), поэтому `audio` появится, когда они будут. `false` — как раньше, через Web Speech.
- **Порог ввода** `typed_from_interval_days` 21 → **6**: слово, пережившее первый дневной интервал, на следующем повторении
  (≈ 8-й день под SM-2) получает `gap_fill` / `spelling` — допуск опечаток `SpellingGrader` и пол после провала смягчают шаг.
  Пробный Pro (7 дней) ввода всё равно не покажет: третья встреча со словом под SM-2 приходится на 8-й день; показать ввод
  в пробном периоде можно только `LEARNING_LADDER_TYPED_FROM_INTERVAL_DAYS=1` (ввод уже на 2-й день — решение владельца).
- **Групповые уроки** (`group_lesson`) ранняя лестница не затрагивает: один экран на класс, правила §12.1.
- **Метрика честности самооценки** (§12.2) теперь считается только на `review`-карточках: под ранней лестницей неверная первая
  проверка следует за «запомнил» на карточке знакомства, где ответ был открыт, — это не самооценка.
- **Одинаковые переводы (ревью 17.11.2026).** Два слова с общим смыслом перевода на языке сессии — оба верные ответы на один вопрос
  (uz: he / she / it = «u», it / this = «bu»; ru: watch / look = «смотреть», answer / reply = «отвечать»). `ExercisePromptBuilder` сравнивает
  не тексты вариантов, а **смыслы** переводов (`Content\Support\TranslationParts`: скобки вырезаются, текст делится по `,` `;` `/`, части
  сравниваются без регистра и пробелов, `ё` = `е`): в `reverse` не попадает слово, чей перевод на языке сессии пересекается по частям с
  переводом карточки; в `meaning` / `audio` / `pretest` — перевод, пересекающийся с верным **или с уже взятым вариантом** (четыре варианта
  не читаются как «u» и «u, bu»). Переводы кандидатов читаются из кэша резолвера (один `many` на слой), SQL не добавляется. Если после
  отсева вариантов меньше трёх, работает прежняя лестница слоёв (урок → курс → публичные курсы) и затем запасной `flash` — так колода, где у
  всех слов один перевод, обслуживается `flash`. Оценка — по тому же правилу (`ExercisePromptBuilder::acceptsPick`): выбор слова урока или курса
  с тем же смыслом (`she` для «u» при карточке `he`; один запрос по курсу только при неверном `reverse`-выборе вне урока) или перевода с общей
  частью («u» для «u, bu») засчитывается как верный — карточка, собранная до правила или старым `serve_token`, не провалит ученика за правильный
  ответ. «Найди пары» (docs/16 §4.4) и раньше не ставила такие слова в один раунд (`PairsPacker` / `TranslationVariants`).
  **Экзамены** (`ExamQuestionBuilder::pickDistractors`) отсекают варианты по тому же правилу: перевод, делящий смысл с верным
  ответом или с уже выбранным вариантом, не берётся (`TranslationParts::keys` по уже загруженным текстам, запросов не прибавилось).
  Кандидат, отброшенный так, считается отсутствующим: нехватка вариантов работает как раньше (вопрос с 2–3 вариантами, а если у слова
  не осталось ни одного — `ExamSessionException::notEnoughDistractors`). Подсчёт результатов экзамена не менялся: ответ сверяется
  с каноническим текстом, вариантов с общим смыслом в новых вопросах нет; сессии, собранные до правила, доживают как были.
- **Пол после провала** (§12.1) считается от **ступени карточки** (`LadderPolicy::rungOf`: зрелость или прежний пол), а не от ступени упражнения:
  под ранней лестницей форматы ступени чередуются (`reverse` / `audio` / `flash` на ступени 2), и провал в любом из них опускает карточку на одну
  ступень от той, где она стояла, — провал `flash` на ступени 2 оставляет тот же пол 1, что провал `reverse`.
- **Типичный первый спринт новичка (Free, курс A1, все ответы верные):** 6 × `flash` (знакомство) → 6 × `meaning` (проверка
  через learn-ahead, варианты — переводы того же урока) → `sprint_complete`. 12 карточек, 12 оценок (реферальная активация
  «10 оценок за день» — за один спринт; «уже знал» на всех шести даёт 6 оценок и второй раунд). Назавтра те же слова — `reverse`
  (день 2), затем `meaning`/`audio*` (день 8, Free) или ввод (Pro).

### 13.2 Спринт ежедневной сессии (`TrainingFlowService`, `CardPicker`, `learning.sprint.*`)

- **Раунд** = одна `daily_review`-сессия: не больше `due_cards` (15) дневных повторений и не больше `new_words` (6) новых слов
  вместе с их шагами обучения. Дневная квота Free (20, docs/12 §5.3) остаётся **дневным** лимитом, у Pro дневного лимита нет —
  раунд ограничивает обоих. Уроки (`lesson`, `group_lesson`), экзамены и практика раундов не знают.
- **Счётчики раунда** едут в том же операторе, что счётчики сессии (`CardPicker::stepCounts`): `due_done` — оценки сессии с
  `interval_before_days > 0` (карточка была `review`); `new_done` — строки `word_repetitions` ученика с `created_at >=
  started_at` сессии и `introduced_mode = daily_review` (сессия, выдавшая слово, не хранится; предыдущая daily-сессия закрыта до
  открытия этой, урочная помечает слова `lesson`; граница — секунда `started_at`); `pending` — сколько оценок ещё должны карточки
  в минутных шагах (`new` — все `learning_steps`, `learning` / `relearning` — оставшиеся; выведенное слово `is_active = false` ничего не
  должно — анти-join по `words_inactive_idx`; строки в шагах читает частичный индекс `word_repetitions_in_steps_idx`, docs/03 §2.7 —
  5 007 буферов / 24 мс → 11 буферов / 0,07 мс у ученика с 5 000 строк); `due_left` — due `review`-карточки scope, посчитанные до
  `due_cards + 1`: остаток раунда — `min(due_left, due_cards − due_done)`, а `due_left + due_done > due_cards` — **долг повторений**.
- **Долг повторений** (`sprint.debt_holds_new`, по умолчанию `true`; ревью 17.11.2026). Пока у ученика просрочено больше дневных повторений,
  чем вмещает один раунд (`due_cards`, с учётом уже оценённых в этом раунде — ответ не меняется посреди раунда), раунд **не вводит новых слов**
  (`newLimit = 0`): долг гасится раунд за раундом (`sprint_complete`, `can_continue`), новые слова возвращаются, как только остаток помещается
  в раунд — как до спринта, когда новые шли только после просроченных. Новичка и ученика без долга правило не касается. Отвергнуто: уменьшенная
  порция новых при долге — усложняет оценку прогресса и объяснение ученику, а вернувшемуся после перерыва важнее не наращивать долг.
- **Лимит 0 = без ограничения** (`LEARNING_SPRINT_NEW_WORDS=0` / `LEARNING_SPRINT_DUE_CARDS=0`, `SprintPolicy` читает как `null`): раунд ограничен
  только вторым лимитом (или ничем — тогда конец `nothing_due`, а не вечный пустой `sprint_complete` с `can_continue`). Без лимита новых слов
  оценка прогресса не предвидит их (`total` растёт с каждым встреченным словом, но не меньше `done`).
- **Пикер** получает `SprintLimits {startedAt, newLimit, newUsed, dueRemaining}`: при `dueRemaining <= 0` шаг 1 не выдаёт
  `review`-карточки (§4.2); при `newUsed >= newLimit` шаг 2 пропускается без транзакции; иначе лимит раунда проецируется
  подзапросом `sprint_used` в тот же оператор кандидатов под блокировкой `learners` рядом с `quota_used` — две вкладки не
  превысят его совместно (`TrainingConcurrencyTest`; потолок due — мягкое чтение до выдачи: в гонке возможна одна лишняя карточка). Порядок блокировок
  §4.2 не менялся. Дневная квота проверяется первой: Free, исчерпавший день, получает `new_quota_exhausted`, а не пустой раунд.
- **Конец раунда.** Когда карточек нет, пикер один раз спрашивает «есть ли ещё»: при достигнутом потолке — `EXISTS` due
  `review`-карточки; при исчерпанной порции новых — `EXISTS` невыданного слова и остаток дневной квоты (то же число, что
  `quota.new_words_remaining`, второй раз не считается). Что-то есть → `sprint_complete`, `can_continue = true`; иначе
  `nothing_due` / `new_quota_exhausted` (можно отличить «спринт окончен» от «на сегодня новых слов нет»). «Ещё раунд» для клиента —
  `POST /finish` текущей сессии и `POST /training/sessions {mode: daily_review}` → новая сессия. Learn-ahead 20 мин ≥ шага 10 мин,
  поэтому раунд не кончается с «недоученными» словами: проверка выдаётся сразу, когда больше нечего показать; при провале на последней
  карточке её минутный шаг тоже отдаётся досрочно.
- **Конец раунда без `/finish`** (ревью 17.11.2026; `training_sessions.completed_at`, docs/03 §2.9). Шаг, отвечающий `card: null` личной
  сессии, у которой есть оценки (любая причина: `sprint_complete`, `nothing_due`, `new_quota_exhausted`, `session_cap`, `lesson_complete`),
  помечает её **пройденной** — один условный `UPDATE ... WHERE status = 'open' AND completed_at IS NULL`, только на последнем шаге
  (`TrainingSessionService::markCompleted`); сессия без оценок не помечается (нечего было играть — та же сессия обслужит, что станет due позже).
  Ученик, закрывший Mini App на вердикте последней карточки, не вызвал `/finish`; раньше «Начать занятие» через несколько часов возвращало ту же
  исчерпанную сессию (`new_done = 6`, `card: null`, вчерашние цифры). Теперь `openPersonal(daily_review)` открытую сессию, которая **пройдена**
  либо **начата до локального «сегодня»** ученика (`LearnerClock::today`), не возвращает: пройденную закрывает `finished` через тот же
  `finish()` (под блокировкой её строки, с итогом; побочные эффекты `/finish` — событие `training.session_finished` и проверка реферала —
  переехали из контроллера в сервис и выполняются `DB::afterCommit`, один раз, только когда именно этот вызов закрыл сессию `finished`),
  вчерашнюю незаконченную — `expired` (как планировщик; в отчёте «Удержание» она брошена честно), и создаёт новую. Незаконченный раунд
  сегодняшнего дня продолжается. Гонка двух вкладок: строка открытой сессии берётся `FOR UPDATE` первой, вторая вкладка видит её уже
  закрытой, проигрывает INSERT по partial unique и перечитывает победителя — одна новая сессия, одно закрытие, одно событие
  (`TrainingConcurrencyTest`); порядок блокировок docs/12 §3.1 не менялся (`training_sessions` → чтение итога, рефералы после commit).
  Планировщик `training:close-expired` закрывает пройденную сессию как `finished`, остальные — `expired` (два UPDATE вместо одного), без
  событий. `/finish` остаётся идемпотентным: 200 с итогом закрытой сессии, второго события нет. `GET /today.open_session_id` пройденную
  сессию не предлагает. Клиент: вызывать `finalize()` (`POST /finish`) сразу при получении шага с `card: null`, не дожидаясь нажатия
  «Продолжить», — тогда итог и событие приходят в тот же сеанс; сервер при этом ничего не теряет, если вызов не дошёл.
- **Прогресс раунда** (`progress`): `done` = оценок в сессии; `total` = `done` + `due_left` + `min(остаток порции новых, остаток
  дневной квоты) × count(learning_steps)` + `pending` (после выдачи новой карточки — с её шагами), не меньше `done`. У новичка
  это 12 с первой карточки до последней; провал добавляет свой повтор, «уже знал» убирает шаг. Для сессии без курса новых слов
  в оценке нет; `card: null` → `total = done`. `total_words` ответа `/start` ежедневной сессии = тот же `total`. **Конец курса** (ревью
  17.11.2026): как только шаг 2 пикера посмотрел и не нашёл невыданных слов (`CardPick::newExhausted` — нет источника, подсказка «ничего не
  осталось» или полный обход без результата), остаток порции новых считается нулём — последний раунд курса кончается точным `8 / 8`, а не
  `8 / 12`. Пока пикер не смотрел (сначала шли due-карточки), оценка по-прежнему берёт лимит порции: точный подсчёт невыданных слов курса —
  анти-join по всем словам курса (25 мс у ученика с 5 000 строк и курсе на 20 000 слов, EXPLAIN) на каждом шаге — для горячего пути отвергнут.
- **Баллы лиг и рейтинга** не меняются: одна оценка — одна строка `training_reviews` и `+1` в `learner_daily_stats`, какой бы
  формат ни был; `league_points` в `/finish` — та же формула (docs/13 §7.6).

### 13.3 Стадии слова и итог сессии (`WordStage`, `TrainingSessionService::summarise`, `learning.stages.*`)

Стадия строки `word_repetitions` для показа ученику (`App\Domain\Learning\Enums\WordStage`): `new` — встречено, не выпущено
(`state` new / learning); `learning` — выпущено с интервалом меньше `known_from_interval_days` (7) или в `relearning`; `known` —
интервал от 7; `mastered` — от `mastered_from_interval_days` (21). Итог `/finish` считается **одним** оператором по словам сессии
(`training_reviews` LEFT JOIN `word_repetitions`, группировка по слову): `reviewed`, `correct`, `new_words` как прежде, плюс
`stages {graduated, promoted, mastered}` — новые слова сессии, вышедшие из минутных шагов; слова, встреченные раньше, чья стадия
выросла (стадия до — по `interval_before_days` **первой** оценки слова в сессии: оконная `first_value` по `id`, не `min` — у карточки, провалившейся
в сессии, релёрнинг-оценка несёт 0 и не должна выглядеть стартом с `new`; интервал 0 — карточка в минутных шагах: `new` (0), если она ни разу
не выпускалась — `lapses = 0`, слово встречено в прошлой сессии и оставлено в шагах, — и `learning` (1), если была в `relearning` (`lapses > 0`:
выпущенная карточка в учебные шаги не возвращается, провал на строке отличает одно от другого); после = по текущей строке); слова, достигшие
`mastered` в этой сессии. Так слово, введённое в прошлой сессии и выпущенное в этой, попадает в `promoted` (ревью 17.11.2026: раньше получало
стадию 1 «до» и не считалось нигде). Бюджет `/finish` прежний (одно обращение к `training_reviews`, оконная функция внутри подзапроса).

Готовность к уровню (docs/13 §8.5) читает **те же** стадии и пороги: доля активных слов курса, у которых `state = review` и `interval_days >= known_from_interval_days`, считается суммой в запросе строк ученика по курсу (`CourseCatalogService::lessonsStarted`). Тест недели расписание не меняет: он не пишет в `word_repetitions`.

### 13.4 Стоимость и тесты

`/review` ежедневной сессии — в бюджете урочного цикла (≤ 14 операторов, `TrainingCycleQueryBudgetTest`): счётчики раунда —
подзапросы одного оператора, `sprint_used` — подзапрос оператора кандидатов; конец раунда — до двух `EXISTS` один раз на сессию.
Выбор упражнения новых SQL не добавил (`last_exercise` — из той же строки; аудио — сравнение хэша `WordAudioService::urlFor`).
Тесты: `tests/Unit/ExerciseLadderTest.php` («The early ladder»), `tests/Feature/LearningSprintTest.php` (первый раунд новичка на
словах урока 1 курса A1, чередование и шаги при провале, «уже знал», причины `sprint_complete` / `new_quota_exhausted` /
`nothing_due` для Free и Pro, потолок due, прогресс, стадии, бюджет, выключатели, уроки не затронуты; долг повторений и его выключатель,
лимит 0, выведенное слово в шагах, ученик на FSRS — первый раунд и проверки следующего дня, `stage_before` для слова из прошлой сессии и для
релёрнинга, точный конец курса); `tests/Feature/SharedTranslationOptionsTest.php` (одинаковые переводы: урок 2 курса A1 в uz на реальных данных —
за 40 сборок ни одного второго верного варианта в `reverse`, `it` в `meaning` / `audio`, ru-пары, деградация до `flash`, слои курса, оценка выбора по
смыслу); `tests/Feature/TrainingRoundReopenTest.php` (раунд без `/finish`: новый раунд через 3 часа и через полночь, вчерашний незаконченный —
`expired`, сегодняшний продолжается, пустая сессия не помечается, урок, планировщик) и `tests/Feature/Analytics/RetentionReportServiceTest.php`
(пройденная без `/finish` — `finished` в отчёте); `tests/Feature/EarlyLadderGradingTest.php` (пол от ступени карточки, `confused_with`, честность
самооценки на дневной карточке); `tests/Feature/TrainingConcurrencyTest.php` (PostgreSQL, fork: `sprint_used` двух вкладок, повторное открытие
пройденной сессии несколькими вкладками); `Tests\Support\CardAnswers` отвечает на карточку как Mini App (по `prompt.type`).

## 14. Раунд трудных слов (`hard_review`, 2026-11-18; `HardRoundService`, `learning.hard_round.*`)

Редкий «событийный» формат из слов, на которых ученик спотыкается: 6-10 слов, только объективные упражнения, свой финал «побеждено N из 10»,
приглашение на «Сегодня» и на итоге раунда / урока. Ответы — **настоящие повторения** (SM-2 / FSRS, очки, серия, дневная статистика);
ядро планировщиков не менялось. Образ — «Раунд трудных слов» (без «босса» и «Хранителя»: это лексика «Каравана»); внутренние ключи `hard_review` / `hard_round`.

**Кандидат** (`HardRoundService::candidates`, один оператор с bindings): строка `word_repetitions` ученика по **активному** слову (любой курс / колода),
`state IN (review, relearning)`, `is_hard = true ИЛИ lapses >= learning.hard_round.lapses_from` (2), **срок слова наступил или наступит в ближайшие
`learning.hard_round.due_within_days` (2) дней** (`next_review_at <= now + N·24ч`; единое правило для всех кандидатов, `0` — только просроченные) и слово **не оценивалось в окне дня**
(`last_reviewed_at IS NULL OR < начала окна`) — одно слово нельзя «прокачать» дважды за день ради очков (утром в ежедневном раунде и потом здесь).
Порядок — как у босса игры без промахов: `is_hard DESC, lapses DESC, easiness_factor ASC, next_review_at ASC, id ASC`; раунд — первые `words` (10).
Окно дня — `LearnerClock::limitWindowUtc` (смена пояса не укорачивает и не удлиняет день). Предикат `is_hard = true OR lapses >= 1` написан в запросе
константами, слово в слово как в частичном индексе `word_repetitions_hard_idx (learner_id, next_review_at)` (docs/03 §2.8); диапазон `learner_id = ? AND next_review_at <= ?` читается по индексу,
остальное — фильтр и сортировка десятков строк. EXPLAIN (ANALYZE, BUFFERS) на 100 тыс. строк (20 учеников × 5000, ≈8 % слов с провалами, сроки от −5 до +55 дней; у ученика 404 слова с провалами, в горизонте 35):
`Index Scan using` частичного индекса (при наличии обычных `(learner_id, next_review_at)` и `next_card_idx` планировщик выбирает именно его), `Index Cond: learner_id = ? AND next_review_at <= ?`, 41 буфер на индекс,
146 буферов на весь оператор (с `words`), 0,16 мс; без горизонта (ключ `lapses`) пришлось бы читать все строки ученика с провалами.

**Почему горизонт.** SM-2 умножает сохранённый интервал на EF независимо от прошедшего времени (`reviewGraduated`), а `lapses` не уменьшается. Раунд без горизонта брал бы изо дня в день
одни и те же зрелые слова с `lapses >= 2` и раздувал бы их интервал каждый день (60 → 150 → 375 → … → `max_interval_days` за неделю), слова выпадали бы из повторения, стадии `known` / `mastered`
росли бы искусственно, а очки лиг (+2 за слово) начислялись бы ежедневно за один и тот же набор. С горизонтом раунд притягивает слово не больше чем на `due_within_days` дней вперёд:
последовательность интервалов — обычная (меняется только дата, не более чем на N дней раньше), после верного ответа `next_review_at >= now + 6 дней` — слово выходит за горизонт и не возвращается в раунд,
пока не подойдёт его срок или оно снова не провалится (провал → `relearning` → завтра `is_hard` и due → кандидат). `is_hard` тоже под горизонтом: у `review`-карточки он означает `repetitions <= 1` и срок
не дальше нескольких дней, исключение ничего бы не дало. FSRS от раздувания защищён сам (`R` считается от фактического `t`: при досрочном ответе `R → 1`, `S' ≈ S`), но горизонт убирает и у него накрутку очков
и дрейф `difficulty`. Рекомендуемые значения: `words` 10, `min_words` 6, `lapses_from` 2, `due_within_days` 2; если раунд предлагается слишком редко — первая ручка `due_within_days` → 3, не `lapses_from`.

**Доступность** (`availability`, порядок причин): `disabled` (`learning.hard_round.enabled = false`) → живая hard-сессия, начатая сегодня и не доигранная (`available`, `open_session_id`)
→ `played_today` (в окне уже ≥ `per_day` = 1 сессий `hard_review`, любых статусов; `resets_at` — конец окна) → `no_objective_exercise` (у ученика выключены и `meaning`, и `reverse`:
раунд выродился бы во `flash`; тест доступности — тот же, что у тренировки, `ExerciseSelector::available()`: конфиг-флаги упражнений (`learning.ladder.meaning_enabled`), право тарифа, настройки ученика; тариф берётся из
`EntitlementResolver` (кэш, `/today` передаёт уже прочитанный) → `not_enough` (кандидатов меньше `min_words` = 6). Размер раунда — `min(кандидаты, words)`; в доступность считается тем же запросом с `LIMIT words`.

**Открытие** (`HardRoundService::open`, одна транзакция; не через `openPersonal`): `LearnerRowLock::take` (первый замок) → открытая hard-сессия ученика под `FOR UPDATE`:
сегодняшняя живая — вернуть её (идемпотентно, две вкладки получают одну), доигранная (`completed_at`) — закрыть как `finished` со всеми побочными эффектами `/finish`, просроченная или
вчерашняя — `closed / expired` → лимит `per_day` в окне → объективное упражнение → кандидаты (`LIMIT words`, меньше `min_words` — отказ) → `INSERT training_sessions {mode: hard_review, learner_id,
course_id/lesson_id: null, source, expires_at: now + session_ttl_hours, meta: {hard_words: [...]}}` → **притягивание** набора: `ReviewPull::toNow(learnerId, wordIds, now, markHard: false)`:

```sql
UPDATE word_repetitions SET next_review_at = :now, updated_at = :now
 WHERE learner_id = :me AND word_id IN (:set) AND next_review_at > :now;
```

Это частный случай §5a: **одна формула** «приблизить, никогда не отдалить» (`ReviewPull`; `GameSrsAdjuster` вызывает её же с `markHard: true`), но без `is_hard` — провала не было.
После этого слова — обычные due-карточки, `ReviewSubmissionService::isAcceptable` и планировщики не менялись; для FSRS досрочный ответ даёт меньший рост стабильности (§5a). Отказы
(`HardRoundException` → 409 `hard_round_unavailable`) бросаются после commit; событие `hard_round.refused {reason, source}`. Брошенный раунд оставляет до 10 слов «к повторению» (эти слова и так были due в ближайшие 2 дня) —
осознанная цена простоты (игра делает то же при промахе): вырастут `due_count`, напоминание «N слов», слова придут в ежедневный раунд первыми. Порядок замков: `learners (FOR NO KEY UPDATE) → training_sessions → word_repetitions`;
транзакция оценки начинается с `training_sessions` и `learners` не берёт — цикла нет. Частичный unique `training_sessions_one_open_hard_per_learner` ловит гонку в обход замка.

**Выдача и оценка**: `TrainingFlowService` — те же `start / nextStep / review`, scope `TrainingScope::hard`. Шаг 1 пикера — due в наборе (`is_hard DESC, next_review_at, id`); новых слов нет
(`NewCardBudget::unlimited()`, `quota.new_words_remaining = null`, квота Free не читается и не тратится); learn-ahead возвращает провалившуюся карточку (relearning) в том же раунде; конец — `done_reason = hard_round_complete`
(`DoneReason::HardRoundComplete`), `can_continue = false`. Слово набора, ставшее `is_active = false`, не выдаётся и не принимается (422 `out_of_scope`); слово вне набора — тоже `out_of_scope`.
Слово набора, оценённое в ежедневном раунде раньше (оно due после притягивания), в раунде трудных не выдаётся и в «побеждено» не попадает, `total` остаётся прежним (риск А2: «9 из 10» без вины ученика).

**Упражнения** (`ExerciseSelector::hardRound`, чистая функция тех же входов): списки ступени карточки (ранняя лестница: `form` / `recognition`; без неё — типизация → варианты → смысл), **`flash` вычёркнут** из каждого; карточка в минутных шагах — список распознавания;
чередование «не два раза подряд» то же. Нет ни одного доступного — `meaning`, если он доступен (конфиг и настройки), иначе `reverse`, иначе `flash` (раунд такому ученику не предлагается: `no_objective_exercise`; выключенное конфигом упражнение не подаётся никогда). Известное исключение (риск А5, зафиксировано тестом): `ExercisePromptBuilder::effectiveType`
понижает карточку до `flash`, когда у слова меньше трёх дистракторов (слово одинокой колоды вне платформенных курсов) — редкий случай, принят.

**Прогресс и итог**: `progress = {done: побеждённые слова, total: размер набора}`; «побеждено» — слово, **последняя** оценка которого в сессии `quality >= 3` (`CardPicker::stepCounts(…, hardWon: true)`, подзапрос в том же операторе счётчиков).
`done` монотонен: верно отвеченное слово больше не выдаётся (`next_review_at` ≥ завтра). `/finish`: `hard_round: {total, won}` (`TrainingSessionService::summarise`: окно `first_value(quality) over (partition by word_id order by id desc)`
только для `hard_review`; `SessionSummary.hardWon/hardTotal`; у остальных режимов `null`); событие `training.session_finished` получает `hard_won`, `hard_total`. `PracticeEntryResolver::forSession` для `hard_review` — `null`.
Очки — как у любой сессии: оценка + верный (+1 +1), новых слов нет (+2 невозможно), цель дня +10; ограничители накрутки — раз в день, ≤ 10 слов, слова дня исключены, повтор `/review` — 409 `card_not_due`.

**Приглашение**: блок `hard_round` в `GET /today` (кандидаты — `count(*)` над тем же запросом с `limit` в общем операторе скаляров экрана, `docs/19` §5.2 K10, и только когда ответ зависит от них — `HardRoundService::needsCandidates()`; «сыграно сегодня» и открытая hard-сессия едут в том же операторе, что и открытая daily-сессия) и `hard_round_offer` в `/finish` ежедневной сессии / урока
(+2 оператора: сессии окна и кандидаты); `null` в обоих при `enabled = false` и у `/finish` самого раунда. Закрытие просроченных (`training:close-expired`) закрывает `hard_review` как любую личную сессию (`expired`, а доигранную — `finished`).
Отчёт «Удержание» (`RetentionReportService`): hard-сессия — активность (её оценки в `learner_daily_stats`), но **не** «первое» и **не** «второе занятие» (`mode <> 'hard_review'` в подзапросах).
Выключатель `LEARNING_HARD_ROUND_ENABLED=false`: блока нет, предложения нет, открытие → 409 `disabled`; уже открытый раунд доигрывается.

Тесты: `tests/Feature/HardRoundTest.php` (политика, кандидаты и границы окна дня Ташкента, смена пояса, горизонт срока (±1 минута, `due_within_days = 0`), невозврат слова после верного ответа, «7 дней подряд» с двойником (SM-2: интервал 25 один раз; FSRS: стабильность не растёт), доступность (в т. ч. `meaning` выключен конфигом), открытие, притягивание в пределах горизонта, scope, пикер, упражнения без `flash`, закрытие просроченных, удержание), `HardRoundFlowTest.php`
(полный раунд по HTTP для Free / Pro, с аудио и без; 9 из 10; блоки; приглашение; отказы; выключатель; бюджеты), `HardRoundConcurrencyTest.php` (PostgreSQL, fork: одна сессия при одновременных открытиях, открытие ∥ оценка в daily без дедлока),
`DatabaseSchemaTest` (индексы, откат миграций), план запроса кандидатов на PostgreSQL (`HardRoundTest`), `TwaContractTest` (ключи), бюджеты `TwaTodayMeQueryBudgetTest` (/today у Free 5 при закрытой игре, 6 при `GAME_ACCESS=all` — после K10, `docs/19` §5.2; до него 9 / 11) и `TrainingCycleQueryBudgetTest` (/finish 11).

## 11. Канонические источники
- Оригинальный пейпер SM-2: `https://super-memory.com/english/ol/sm2.htm`
- Anki (learning steps, learn ahead, relearning): `https://docs.ankiweb.net/deck-options.html`
- FSRS для сравнения: `https://github.com/open-spaced-repetition/fsrs4anki` (wiki «The Algorithm»), `https://github.com/open-spaced-repetition/fsrs-rs`, `https://github.com/open-spaced-repetition/ts-fsrs`
