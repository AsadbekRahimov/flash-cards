# Очки за слова (v2), Free/Pro и «За всё время» — техническое проектирование

Документ architect'а · 24 ноября 2026 года. Основа: [28 — очки за знание слов](28_LEAGUE_SCORING_PROPOSAL_RU.md) и [30 — лучшие за всё время](30_ALL_TIME_RATING_PROPOSAL_RU.md) (предложения владельца). Обе ссылаются на «план реализации 31_COMPETITION_PLAN_RU.md». Такого файла нет. Его роль выполняет этот документ. Кубок Telegram-группы ([29](29_GROUP_CUP_PROPOSAL_RU.md)) проектируется отдельно. Здесь о нём важно одно: **кубок не даёт очков лиги** и не пишет ни в одну таблицу ниже.

Статус: **фундамент сделан (25.11.2026): S1–S3** — каталог лексем (`lexemes`, `words.lexeme_id`, `LexemeKey`, `LexemeCatalog`, `lexemes:backfill`), таблицы §2.2–§2.6 (docs/03 §2.66–§2.71), `ScoreRules` и `ScorePeriodService`. **Ядро начисления и подключения сделаны (25.11.2026): S4–S6** — `WordScoreService` за контрактом `AnswerScoreRecorder`, тренировка, игра (§12 — принятые решения и контракт для S7–S16). По умолчанию `leagues.scoring.mode = off`: лиги, тренировка и игра работают по v1, начисление не выполняет ни одного оператора. **Сделано (26.11.2026): поле `score` ответа `/review` (остаток S5), S7, S14, S16 (очистка журнала и событие `score.day_capped`)** — §12.5. **Сделано (26.11.2026): S8, S9, S11** — неделя v2 (группы по категориям, лучшие 5 дней, ничьи и зоны), месяц v2 (две таблицы, `RANK()`, кандидаты, призы 3 + 3), исправление дня, команда перехода `leagues:scoring-cutover` (§12.6); контракт для S10, S12–S15 — §12.7. **Сделано (26.11.2026): S15** — /admin: правила (только чтение), «Очки по дням» и журнал дня с исправлениями, проверка месяца v2 по категориям, категория в виджете групп (§12.8). **Сделано (26.11.2026): S12** — API недели и месяца v2 (две таблицы, курсор, соседи, призы по категориям), «Сегодня N из 100», очки занятия в `/finish`, эмодзи Pro в списках, событие `score.credited` (§12.9; контракт для экранов S13 — §12.9). **Сделано (27.11.2026): S10 «За всё время» и таблицы области (docs/35 R6, R7)** — §12.11 (решения, замеры, контракт для экранов R8 / S13b). Экраны (S13) не сделаны. Решения, которые меняют действующие правила, переносятся в docs/13 §7.3 и §7.6, docs/03, docs/04, docs/06 и docs/19 **до кода** (задача S0, §9).

**Решения владельца 25.11.2026 (docs/31 §5) меняют этот проект** — где они расходятся с текстом ниже, верны они:
- В1: старт **сразу, без теневого месяца**. Дата 01.02.2027 (§6.2) и порядок выката §9 не действуют: `starts_on` версии 2 — переменная `LEAGUES_SCORING_V2_STARTS_ON` (пусто — не назначена), открытые неделя и месяц перед включением закрываются по старым правилам; `shadow` остаётся только как режим «только запись» на случай отката;
- В1: «Очки до [дата]» и архив лидеров не нужны — `learner_score_legacy` (§2.6) **не создана**, `leagues:compute-legacy` (§6.3, S16) не делается, S16 сокращается до очистки журнала;
- В3: `flash` на зрелых повторениях заменяется проверяемым выбором перевода (§3.2 «Конфликт с лестницей»: решено «да»);
- В5: по 3 приза в Free и в Pro с первого месяца (не «1, пока Pro < 100»);
- В6: игра даёт очки сразу, с выключателем `leagues.scoring.game_enabled`;
- К4 (кубок, docs/33) «опечатка = 0» к лиге не относится: в лиге опечатка — успех (В8, docs/31 §5 «решено без владельца»).

Термины:
- **рейтинговый день** — календарный день в поясе лиги `leagues.timezone` (Asia/Tashkent);
- **лексема** — словарная единица (§2.1);
- **зачёт** — одна из двух независимых сумм одного дня: **недельный** и **месячный**;
- **категория** — `free` или `pro`, фиксируется отдельно на неделю и на месяц;
- **v1** — действующая формула, **v2** — новая.

---

## 0. Как устроено сейчас (по коду, не по докам)

### 0.1. Все точки начисления очков лиги сегодня

| # | Точка | Файл | Что пишется | Как становится очками |
|---|---|---|---|---|
| 1 | Ответ в тренировке (`POST /api/twa/training/sessions/{id}/review`) | `TrainingFlowService::review/answer` → `ReviewSubmissionService::accept` → `DailyStatsService::recordReview` | `learner_daily_stats`: `reviews_count +1`, `correct_count +1` при quality ≥ 3, `new_words_count +1`, если карточка была `new`. Внутри транзакции ответа | `LeaguePoints::dayExpression`: `reviews×1 + correct×1 + new_words×2` |
| 2 | Цель дня | там же (`goal_met_at`, когда `reviews_count ≥ daily_goal`) и `DailyStatsService::reconcileGoal` (цель снижена в настройках) | `learner_daily_stats.goal_met_at` | `+10` за день |
| 3 | Забег игры «Караван» (все виды: stop, boss, daily, class_boss) | `RunFinisher::finish`, шаг 5 | `learner_daily_stats.game_points` (верный ответ 2, прочие 1, потолок `game.league.daily_cap` = 40 в день), `game_runs.league_points` | `+ game_points` как есть |
| 4 | Финиш занятия (`/finish`) | `SessionSummaryResource` | ничего не пишет | только показ `league_points` = `LeaguePoints::ofCounts` по счётчикам сессии |
| 5 | Практика (solo), баттлы, экзамены, тест уровня, квиз-напоминание бота | `Practice/*`, `ExamAnswerService`, `LevelQuizService`, `ReminderQuizAnswerService` | — | **0** очков. Это сказано прямо в docblock'ах `LevelQuizService` и `ReminderQuizAnswerService` |

Формулу читают три места: недели (`LeagueService::computeStandings`), месяц (`MonthlyRatingService::upsertScores`, потолок `monthly.daily_cap` = 300) и показ финиша. Все читают одну строку `learner_daily_stats`. **Ключ этой строки — локальная дата ученика** (`LearnerClock`, `learners.timezone`), а не дата Ташкента. Неделя при этом идёт Пн–Вс по Ташкенту и суммирует строки по их локальной дате. Это известная «принятая аномалия» docs/13 §7.3.

### 0.2. Ход ответа в тренировке

- Одна транзакция. Блокировки по порядку: `training_sessions` FOR UPDATE → `word_repetitions` FOR UPDATE (вместе с `words` одним join'ом, `RepetitionWithWord`) → `scheduler->schedule()` (SM-2 `SpacedRepetitionEngine` или `FsrsScheduler`) → INSERT `training_reviews` → `DailyStatsService::recordReview` (`learner_daily_stats` FOR UPDATE). После commit идут хуки: размещение в лигу при первой оценке локального дня, достижения, рефералка.
- **Идемпотентность.** Поля `request_id` у тренировки нет. Повтор отсекается данными: оценить можно только карточку с `next_review_at <= now` или с одноразовым `serve_token` (learn-ahead). Принятый ответ сдвигает срок и гасит билет, поэтому повтор получает 409 `card_not_due`. У игры (`game_run_attempts`) и практики (`practice_attempts`) есть журнал `(run|session, request_id)` unique.
- **Бюджет:** `tests/Feature/TrainingCycleQueryBudgetTest.php` — `/review` ≤ **14** операторов.
- **Журнал ответов есть:** `training_reviews` (`learner_id, word_id, quality, exercise, answer_text, scheduler, interval_before_days, time_spent_ms, created_at`). Он не чистится. Колонки `exercise` и `interval_before_days` появились 23.10.2026. Ответы игры туда **не** пишутся.
- **Состояние слова:** `word_repetitions` (`state` new/learning/review/relearning, `repetitions`, `interval_days`, `easiness_factor`, `last_reviewed_at`, `next_review_at`, `is_hard`, `meta.last_exercise`, `meta.ladder_floor`). SM-2 и FSRS при прохождении пишут `next_review_at = now + interval_days`, `last_reviewed_at = now` (`LearningStepMachine::commitAnswer`).
- **`ReviewPull::toNow`** (ошибка в игре, раунд трудных слов) ставит `next_review_at = now` и **не трогает** `interval_days` и `last_reviewed_at`. Поэтому по строке видно «назначенный срок»: `last_reviewed_at + interval_days`. Отсюда правило «в срок» в §3.3.

### 0.3. Типы упражнений и кто оценивает (`ExerciseType`, `ExerciseSelector`)

| Тип | Кто оценивает | Что видит ученик до ответа |
|---|---|---|
| `flash` | **сам ученик** (`quality` 0..5) | переводит сам, открывает перевод кнопкой (`training.reveal()` в TWA, сервер этого не видит) |
| `meaning`, `pretest` | сервер, по выбранному варианту | 4 варианта, правильный скрыт (`hidesChoiceAnswer` / `hidesTranslation`, заметка скрыта) |
| `reverse`, `audio` | сервер, по варианту. **Но** при `learning.objective_grading.accept_legacy_quality` старый клиент присылает `quality`, и сервер переводит его в 4/2 | 4 варианта |
| `spelling`, `gap_fill` | сервер, `SpellingGrader` (5 — верно, 4 — опечатка, иначе ошибка); только Pro или `typed_recall_in_free` | ввод |

Ранняя лестница (`learning.ladder.early.enabled = true`, по умолчанию) работает так. Новая карточка показывается `flash`: первое знакомство, перевод открыт. Карточка в минутных шагах и `review` на ступенях 0–1 идут по списку распознавания `meaning,reverse,audio`. `review` на ступени 2 идёт по **списку формы `reverse,audio,flash`**. Аудио отдаётся только с файлом (`audio_requires_file`), а у курсов файлов пока почти нет. Значит, на ступени 2 **каждое второе повторение — самооценка `flash`** (правило «не тот же формат, что в прошлый раз»). Конфликт с предложением — §3.2 и вопрос В3.

**Подсказок в тренировке нет**, кроме открытия перевода во `flash`, которое и так самооценка. Личная заметка (`note`) на карточках с проверкой скрыта. В игре подсказка есть: `POST /game/runs/{run}/hint` и бустер `hint` (две неверные опции убираются или открываются две буквы). Сервер её видит: `game_run_attempts.kind = hint` по `element_id`. Пропуск (`skip`) тоже виден.

### 0.4. Игра и практика: как они ссылаются на слова

- Игра: в снимке забега (`game_runs.snapshot`) у словесных задач (`translate_fwd`, `translate_back`, `listen`, `spell`, `gap`) есть `word_id`. У `pairs_round`, `collocation`, `sentence`, `dictation` (авторские задачи практики) — `target_word_ids`. Верный ответ расписание не меняет. Ошибка подтягивает срок (`GameSrsAdjuster` → `ReviewPull`, после commit). `word_repetitions` в транзакции игры не трогается (правило 17 docs/18).
- Практика и баттлы работают с `practice_items` (словосочетания, предложения, диктант, формы, поиск ошибки), а не с проверкой одного слова. `word_repetitions` не меняют, очков не дают.

### 0.5. «Словарная единица»: что есть в данных

**Каталога лемм нет.** Слово — это строка `words` в уроке (`unique(lesson_id, word)`). Одно и то же английское слово в двух курсах или в своей колоде (`courses.owner_type = learner`, `LearnerDeckService`) — это разные `words.id` и разные `word_repetitions` со своими расписаниями. Связи «копия ↔ оригинал» тоже нет. Есть только индекс `lower(word)` (trigram) для поиска в /admin. **Требование «одно слово — одни очки во всех режимах и копиях» в текущих данных невыполнимо.** Нужен новый общий идентификатор (§2.1).

### 0.6. Тариф, день, участие, удаление

- Тариф: `EntitlementResolver::for($learner, $now)` даёт максимум из Free, своей живой подписки и подписки организации, где у ученика есть место (seat). Пробный Pro (`pro_trials`) — обычная подписка. **Истории мест в организациях нет**: тариф ученика на прошлую дату надёжно восстановить нельзя. Free-квота новых слов — 20 в день (`billing.plans.free.limits.new_words_per_day`).
- День: для серии, цели, квоты и `learner_daily_stats` — локальный (`LearnerClock`). Для недели и месяца лиги — Ташкент. Месяц закрывается через `close_delay_minutes` = 180, чтобы досчитать запаздывающие локальные вечера.
- Участие: `learners.meta.leagues_opt_out`. С этим флагом ученик не размещается в недельные группы, а в месяце у него `participant = false`. Отдельной настройки «скрыть профиль» нет. Удаление — мягкое (`deleted_at`). Через 30 дней `learners:purge-deleted` удаляет каскадом. При удалении, блокировке, смене opt-out и восстановлении `MonthlyRatingService::participationChanged` пересчитывает строку месяца.

### 0.7. Какая история сохранилась

| Источник | Период | Полнота | Годится для |
|---|---|---|---|
| `learner_daily_stats` | с первой активности (апрель 2026), без чистки | **все** ученики (кроме удалённых окончательно), по локальным датам | сумма по **старой** формуле на ученика |
| `league_members.final_points` | недели с 22.10.2026 | только размещённые (без opt-out) и только внутри своих групп | личная история недель; общей таблицы нет |
| `league_month_scores` | с ноября 2026 | **все** активные за месяц, `final_rank` у участников | «архив лидеров» месяцев по старым правилам |
| `training_reviews` | с апреля 2026 (`exercise` и интервал — с 23.10.2026) | все ответы тренировки | v2 **не восстанавливается**: нет лексемы, нет «назначенного срока» (подтягивания не видны), нет ответов игры, нет тарифа |

Вывод для docs/30: сопоставимого учёта v2 до запуска нет и быть не может. Старые очки можно честно показать только как личную строку «Очки до [дата]» и как архив месяцев по старым правилам (§6.3).

---

## 1. Разрыв: правило → что есть → что менять

| Правило из 28/30 | Что есть | Что менять |
|---|---|---|
| Очки за слово: новое 2, в срок 3, интервал ≥ 7 сут — 5 | очки за любой ответ (1 + 1 за верный), новое слово 2 | новый сервис начисления `WordScoreService` (§3), журнал `score_credits` |
| Самооценка, раскрытие, подсказка, исправление после показа, раньше срока — 0 | всё приносит очки | классификация ответа (§3.2), правило «назначенный срок» (§3.3) |
| Единица — ученик × словарная единица | только `words.id` | каталог `lexemes` + `words.lexeme_id`, `learner_lexemes` (§2.1) |
| Личное слово без связи с каталогом — без очков | — | колода ученика получает `lexeme_id` только при совпадении с каталогом |
| Одна попытка по слову за рейтинговый день, ошибка/подсказка закрывают | — | `learner_lexemes.last_attempt_on` под блокировкой строки |
| Новое слово — один раз за всю историю | `new_words_count` при первой оценке карточки (то есть каждой копии) | `learner_lexemes.confirmed_on` + разовая загрузка прошлых слов (backfill) |
| День — по Ташкенту, через полночь работает | день — локальный ученика | `learner_score_days.rating_day` по Ташкенту, время — момент принятия ответа сервером |
| Монеты, звёзды, победы, покупки, цель дня — 0 | цель дня +10, `game_points` | в v2 эти слагаемые не участвуют. Игра приносит только очки за слова (§3.6) |
| Лимиты Free 20/20/100, Pro 30/30/150, остаток до потолка | недели без потолка, месяц — потолок 300 | лимиты в строке дня, отдельно для каждого зачёта (§3.4) |
| Неделя = 5 лучших дней | сумма всех дней | `WeeklyScoreStandings` — top-5 по строкам дня (§3.5) |
| Категория недели и месяца фиксируется при первом зачётном событии | категорий нет, все в одной таблице | `learner_score_periods`, `league_groups.category`, `league_month_scores.category` |
| Отдельные таблицы и призы Free/Pro | одна | категория в группах недели, в месяце и в «За всё время», призы по категориям (§7) |
| Ничьи: длинные повторения → дни → совместное место; повышаем всех на границе | ранги не делятся (раньше размещён — выше) | конкурентный ранг (1, 2, 2, 4) по кортежу `(points, long_words, active_days)` (§5.3) |
| «За всё время» = сумма месячного дневного зачёта по категориям | нет | `learner_score_totals` + чтение со смещением по ключу (keyset) (§5) |
| История: «Очки до [дата]», архив, «учёт с [дата]» | — | `learner_score_legacy` + экран архива месяцев (§6.3) |
| Правила публикуются заранее, веса меняются только на будущие периоды | веса в env, читаются «вживую» | неизменяемые версии правил в `config/leagues.php`, номер версии у периода (§6.1) |
| Исправление дня — замена вклада, идемпотентно | нет | `ScoreCorrectionService`: аннулирование начислений + повторный расчёт дня (§6.4) |
| Достижение «25 слов после недельного интервала» | нет | ключ `long_recall_25` (§8.4) |

---

## 2. Модель данных

Все новые таблицы — в домене Engagement, кроме `lexemes` (Content). У каждой миграции есть `down()`. Индексы на больших существующих таблицах создаются `CONCURRENTLY`, как в `2026_11_16_200002` и `2026_11_12_100001`.

### 2.1. Каталог лексем (Content)

**`lexemes`**: `id`, `language varchar(5)` (`en`), `key varchar(100)` (нормализованное написание), `created_at`. `unique(language, key)`.

**`words.lexeme_id`**: `bigint null`, FK → `lexemes` `nullOnDelete`, индекс.

Нормализация (`App\Domain\Content\Support\LexemeKey::of(string $word): ?string`):
- `trim`, нижний регистр (`mb_strtolower`), схлопнутые пробелы;
- типографские апострофы и дефисы приводятся к ASCII;
- снимается финальная пунктуация (`.,!?;:`) и артикль в начале (`a `, `an `, `the `), если остаётся непустая строка;
- `to ` перед глаголом **не** снимается: «to be» и «be» — разные ключи. Ошибка в пользу «разные» безопасна: в худшем случае ученик получит очки за обе формы, а лимиты дня всё равно действуют.

Части речи не различаем. «bank» (берег) и «bank» (банк) — одна лексема. Для очков это правильно: одно написание — одна попытка в день.

**Кто создаёт лексемы** (`App\Domain\Content\Services\LexemeCatalog`):
- `ensureFor(Word)` вызывается при создании и изменении слова **платформенного** (`owner_type = platform`) и **организационного** курса: из `CourseTreeObserver`, `CoursePackImporter`, `VocabularyImporter`, `WordContentEditor`. Он создаёт лексему (`insertOrIgnore` + select) и пишет `words.lexeme_id`;
- `matchFor(Word)` — для **колоды ученика** (`LearnerDeckService::importText`, добавление и правка слова). Только поиск существующей лексемы по ключу. Нет совпадения — `lexeme_id = null`: слово учится как обычно, но без публичных очков (так требует docs/28). Когда это же слово позже появится в каталоге, команда `lexemes:backfill --decks` свяжет его;
- команда `lexemes:backfill` — пачками по `words.id`, идемпотентная.

Почему не связь «копия → оригинал» и не ИИ-сопоставление. Копии в данных нет, а ИИ — новая зависимость и недетерминированный результат. Ключ по написанию проверяем и дёшев.

### 2.2. Состояние «ученик × лексема»

**`learner_lexemes`** (PK `(learner_id, lexeme_id)`, FK learner `cascadeOnDelete`, FK lexeme):

| Колонка | Смысл |
|---|---|
| `confirmed_on date null` | рейтинговый день первого подтверждения нового слова (null — ещё не подтверждено) |
| `confirmed_source varchar(8) null` | `training`, `game` или `legacy` (backfill) |
| `last_attempt_on date null` | рейтинговый день последней **засчитанной попытки** (успех, ошибка, подсказка, раскрытие). Попытка за день — одна |
| `last_credit_due_at timestamp null` | «назначенный срок» (§3.3), за который уже начислено повторение. Новый цикл повторения начинается, только когда назначенный срок станет позже этого |
| `first_long_on date null` | первый засчитанный длинный повтор в любой категории (для достижения) |
| `long_free_on`, `long_pro_on date null` | первый длинный повтор, засчитанный в **месячном** зачёте этой категории (признак для ничьих «За всё время») |

Частичный индекс `(learner_id) WHERE first_long_on IS NOT NULL` — для достижения. `fillfactor = 90`: таблица обновляется часто, как в `2026_11_10_100000_tune_storage_parameters_of_hot_update_tables`. Строки создаются лениво при первой проверяемой попытке, а для старых слов — разовым backfill (§9, S7).

### 2.3. Периоды и категория

**`learner_score_periods`** (PK `(learner_id, period, starts_on)`): `period varchar(5)` (`week` или `month`), `starts_on date` (понедельник или 1-е число по Ташкенту), `category varchar(4)` (`free` или `pro`), `rules_version smallint`, `fixed_at timestamp`. Строка вставляется при **первом начислении** периода (`INSERT … ON CONFLICT DO NOTHING`, затем чтение), и категория дальше не меняется. Объём: не больше 64 строк на ученика в год.

### 2.4. Дневные итоги

**`learner_score_days`** (`id`, FK learner `cascadeOnDelete`, `unique(learner_id, rating_day)`, индекс `(rating_day)`):

| Группа | Колонки |
|---|---|
| ключ | `rating_day date` |
| неделя | `week_starts_on date`, `week_category varchar(4)`, `week_version smallint` |
| месяц | `month_starts_on date`, `month_category varchar(4)`, `month_version smallint` |
| сырые факты (без лимитов) | `raw_points int`, `new_count smallint`, `review_count smallint` (включая длинные), `long_count smallint` |
| недельный зачёт | `week_points smallint`, `week_new smallint`, `week_review smallint`, `week_long smallint`, `week_capped_at timestamp null` |
| месячный зачёт | `month_points smallint`, `month_new smallint`, `month_review smallint`, `month_long smallint`, `month_capped_at timestamp null` |
| служебное | `revision int default 0` (растёт при исправлении), `created_at`, `updated_at` |

Денормализация: категории и версии обоих периодов копируются в строку дня при её создании. Тогда ответ не читает `learner_score_periods`, а недельные и месячные таблицы суммируют строки дня без join'ов. `*_long` — число лексем дня с длинным повтором, **засчитанным** (добавлено > 0) в этом зачёте.

### 2.5. Журнал начислений

**`score_credits`** (`id`, `learner_id` FK `cascadeOnDelete`, `rating_day date`, `lexeme_id` FK, `word_id bigint`, `source varchar(12)` (`training`, `group_lesson` или `game`), `source_id bigint` (`training_sessions.id` или `game_runs.id`), `kind varchar(6)` (`new`, `review` или `long`), `week_points smallint`, `month_points smallint`, `voided_at timestamp null`, `voided_by_user_id` FK users `nullOnDelete`, `void_reason varchar(255) null`, `created_at`).

- `unique(learner_id, rating_day, lexeme_id)` — гарантия БД «одна лексема — одно начисление за день», вторая линия обороны после блокировки `learner_lexemes`;
- индекс `(rating_day)` — для очистки и месячного подсчёта различных длинных повторов.

Пишется строка на **каждое** успешное подходящее событие, даже если оба зачёта обрезаны до 0. Так видно «срезано потолком».

### 2.6. Итоги периодов и «За всё время»

Изменения существующих таблиц:
- `league_weeks.rules_version smallint default 1`; `league_months.rules_version smallint default 1`;
- `league_groups.category varchar(4) default 'free'`, индекс `(league_week_id, category, tier, members_count)` вместо `(league_week_id, tier, members_count)`;
- `league_members.category varchar(4) null`, `final_long_words smallint null`, `final_days smallint null`;
- `league_month_scores.category varchar(4) null` (null — месяц v1, одна общая таблица), `long_words int default 0`. Индекс `(league_month_id, category, participant, points desc, long_words desc, active_days desc, learner_id)`;
- `league_month_candidates.category varchar(4) null`. У `place` убрать любые предположения о единственности: при ничьей одно место у нескольких (§7);
- `league_months.prizes` — для v2 форма `{free: [{place, ru, uz}], pro: [...]}`. Форма v1 (список) читается по `rules_version`.

**`learner_score_totals`** (PK `(learner_id, category)`): `points int`, `long_words int`, `active_days int`, `first_day date`, `last_day date`, `participant bool`, `updated_at`. Индекс `(category, participant, points desc, long_words desc, active_days desc, learner_id)`.

**`learner_score_legacy`** (PK `learner_id`): `points int`, `active_days int`, `from_day date`, `to_day date`, `formula varchar(8)` (`v1`), `computed_at`. Пишется один раз командой после закрытия последнего месяца v1.

### 2.7. Объём и срок хранения

Допущения: зарегистрировано 10–50 тыс. учеников, активных в день 20 % (2–10 тыс.). Засчитанных лексем в активный день — ~25 (при лимитах 40/60). Проверяемых попыток по разным лексемам — ~40. Выученных слов на ученика в среднем — 300–500.

| Таблица | Строк | Объём | Хранение |
|---|---|---|---|
| `lexemes` | ~20–60 тыс. | мало | всегда |
| `learner_lexemes` | 3–25 млн (как `word_repetitions`) | 0,3–2,5 ГБ | всегда (уходит с учеником) |
| `learner_score_days` | 0,7–3,7 млн в год | 0,1–0,6 ГБ в год | **всегда**: источник «За всё время» и исправлений |
| `score_credits` | 18–90 млн в год | 2–12 ГБ в год | **180 дней** (`leagues.scoring.credits_retention_days`). Ежедневная очистка `leagues:prune-score-credits`, только по дням закрытых месяцев |
| `learner_score_periods` | до 64 на ученика в год | мало | всегда |
| `learner_score_totals` | ≤ 2 × учеников | мало | всегда |

Почему журнал не храним вечно. Он нужен для исправлений, для проверки кандидатов в призёры и для числа **различных** длинных повторов внутри месяца. После закрытия месяца эти числа заморожены в `league_month_scores.long_words`. Признаки для «За всё время» живут в `learner_lexemes`.

---

## 3. Алгоритм начисления

### 3.1. Единая точка

`App\Domain\Engagement\Services\Scoring\WordScoreService::record(ScoredAnswer $answer): ScoreOutcome` вызывается **внутри транзакции ответа** тренировки и игры. Чтобы домен Learning не зависел от Engagement напрямую, вызов идёт через контракт `App\Domain\Learning\Contracts\AnswerScoreRecorder` с привязкой в провайдере. Так уже сделано с `NewCardBudgetResolver`, и тесты тренировки могут подменять контракт.

`ScoredAnswer` (DTO, readonly):
`learnerId, lexemeId(?int), wordId, source(training|group_lesson|game), sourceId, verdict(Passed|Failed|Assisted|Revealed|Introductory|NotChecked), stateBefore(RepetitionState), intervalBefore(int), lastReviewedAt(?Carbon), plan(Plan), now`.

`ScoreOutcome` (DTO): `kind(new|review|long|null)`, `reason` (`credited`, `no_catalog`, `already_today`, `closed`, `not_due`, `early`, `same_cycle`, `capped`, `off`), `base`, `weekAdded`, `monthAdded`, `today` (`{week_points, week_cap, month_points, month_cap, week_new, week_review, new_limit, review_limit, week_category, month_category}`), `firstCreditOfWeek bool`, `newLongLexeme bool`.

### 3.2. Что считается «самостоятельным проверяемым ответом»

| Режим | Засчитывается | Почему |
|---|---|---|
| Тренировка `meaning`, `reverse`, `audio` с `answer` (выбор варианта) | **да** | правильный вариант скрыт, сервер сверяет выбор |
| `reverse` и `audio` с устаревшим `quality` (`accept_legacy_quality`) | **нет** (`NotChecked`, попытку закрывает) | сервер не видел выбора, «верно» — слова клиента. До запуска v2 рекомендуем выключить `LEARNING_OBJECTIVE_GRADING_ACCEPT_LEGACY_QUALITY` |
| `pretest` (новое слово, 4 варианта) | **да**, как подтверждение нового слова; «Не знаю» — ошибка | перевод скрыт. Угадывание с шансом 25 % даёт в среднем 0,5 очка за слово при лимите 20 — приемлемо |
| `spelling`, `gap_fill` | **да**. Опечатка (quality 4) — успех | так же решает SM-2. Строже — вопрос В8 |
| `flash` у новой карточки (первое знакомство) | **нет, но попытку не закрывает** (`Introductory`) | docs/28 разрешает ознакомительный показ |
| `flash` у любой другой карточки | **нет, попытку закрывает** (`Revealed`) | самооценка с открытием перевода |
| Групповой урок (`group_lesson`) | по тем же правилам упражнения | ответы проверяемые. Риск подсказки учителя вслух — в рисках |
| Раунд трудных слов (`hard_review`) | по правилам выше, но карточка, подтянутая раундом, — «раньше срока» (§3.3) | раунд ставит `next_review_at = now` без ошибки |
| Игра: `translate_fwd`, `translate_back`, `listen` (варианты), `spell`, `gap` (ввод) | **да**, если по элементу не было подсказки (`kind = hint` или бустер `hint`) до ответа; `typo` — успех | сервер видит и ответ, и подсказку |
| Игра: подсказка, пропуск (`skip`), `wrong` | **нет, попытку закрывает** | подсказка, раскрытие, ошибка |
| Игра: `pairs_round` | **нет**, попытку не трогает | все стороны видны, последняя пара угадывается исключением. Ошибка тут не закрывает попытку: она не про одно слово |
| Игра: `collocation`, `sentence`, `dictation`; практика (solo) и баттлы | **нет** | проверяется словосочетание или предложение, а не слово. Баттл — PvP |
| Экзамен, тест уровня, квиз-напоминание бота | **нет** | измерение, расписание не трогается |

**Конфликт с лестницей (В3) — решено владельцем «да» (25.11.2026), сделано в S5:** пока `leagues.scoring.mode` ≠ `off`, `flash` в списке `form` заменяется на `meaning` (`LadderPolicy::scoringForm`, docs/06 §13.1). На ступени 2 ранней лестницы список формы `reverse,audio,flash` чередуется. Без аудиофайлов каждое второе повторение зрелого слова приходит как `flash`, а это 0 очков и закрытая попытка. В итоге 5 очков за «длинный» повтор (интервал ≥ 7 — это как раз ступень 2+) Free получал бы примерно в половине случаев не по своей вине. Сервер выбирает упражнение детерминированно, ученик на это не влияет. Рекомендация: при `leagues.scoring.mode = live` заменить в `learning.ladder.early.form` `flash` на `meaning`, то есть `reverse,audio,meaning`. Это изменение метода docs/06 §12.1 и docs/25. Нужно решение владельца.

### 3.3. «В срок», «длинный интервал», «новое слово»

**Назначенный срок** — по строке **до** оценки (в `ReviewSubmissionService` её снимают рядом с `$intervalBefore`):

```
nominalDue = lastReviewedAt + intervalBefore суток   (только state = review и lastReviewedAt ≠ null)
```

`next_review_at` не годится: `ReviewPull` (ошибка в игре, раунд трудных) двигает его к «сейчас», а назначенный срок остаётся прежним. Значит, подтянутая карточка — это «повтор раньше срока» и даёт 0. Так и сказано в docs/28.

Решение по успешной попытке (`verdict = Passed`), в таком порядке:
1. `confirmed_on IS NULL` → **new** (2). Ставим `confirmed_on = rating_day`. Состояние карточки не важно: так засчитываются и `pretest`, и первый правильный выбор после знакомства, и слово, которое ученик сразу отметил «Знаю» (q 5). Если карточка в `review` и срок наступил, `last_credit_due_at = nominalDue`, чтобы этот же цикл не дал ещё и повторение.
2. Иначе `stateBefore = review`, `nominalDue ≤ now` (допуск +60 с на округление), `last_credit_due_at IS NULL OR nominalDue > last_credit_due_at`, рейтинговый день `lastReviewedAt` < сегодня → **review** (3) или **long** (5), если `intervalBefore ≥ 7`. Пишем `last_credit_due_at = nominalDue`.
3. Иначе 0: `early` (срок не наступил), `same_cycle` (этот цикл уже оплачен, например игрой) или `not_due` (карточка в шагах).

Какой интервал брать. Именно `interval_days` **до** попытки: это интервал, назначенный SM-2 или FSRS при прошлом успехе. Просрочка его не увеличивает, как и требует предложение. Провал в тренировке обнуляет интервал (`interval_days = 0`, relearning). Провал в игре подтягивает срок, и карточка становится «раньше срока». Отдельный признак «не было ошибки с прошлого успеха» поэтому не нужен.

Зачем `last_credit_due_at`. Верный ответ в игре не сдвигает расписание. Без «цикла» одно и то же созревшее слово приносило бы 3–5 очков **каждый день**, пока ученик играет и не идёт в тренировку. С циклом игра оплачивает созревший срок один раз. Тренировка потом сдвигает расписание (0 очков, `same_cycle`), и следующий срок снова можно заработать.

Копии одной лексемы в разных курсах имеют свои расписания. «Новое» по-прежнему даётся один раз (`confirmed_on`), попытка — одна в день (`last_attempt_on`). Повторения двух копий могут созревать в разные дни, и лексема получит повтор чаще, но не больше раза в день. Колода ученика попадает сюда только через каталог. Цена — несколько очков в неделю внутри тех же лимитов. Принимаем.

### 3.4. Одна попытка в день, лимиты, потолок, два зачёта

Псевдокод `record()` (внутри транзакции вызывающего):

```
if mode = off or lexemeId = null or verdict = Introductory: return reason   // pairs/авторские задачи record() вообще не вызывают
day = ratingDay(now)                                   // Ташкент, момент принятия ответа
L = lockLearnerLexeme(learnerId, lexemeId)              // SELECT … FOR UPDATE; нет строки — INSERT в savepoint, повторное чтение
if L.last_attempt_on = day: return already_today        // ничего не пишем
L.last_attempt_on = day
if verdict ≠ Passed: save(L); return closed             // Failed, Assisted, Revealed, NotChecked закрывают день
kind = classify(L, answer)                              // §3.3; null → save(L); return early|same_cycle|not_due
save(L)
D = lockOrCreateDay(learnerId, day, plan)               // при создании: ScorePeriodService::fix(week), fix(month) → категории и версии
for T in [week, month]:
    if version(T) < 2: added[T] = 0; continue           // период ещё по старым правилам
    lim = rules(version(T)).limits[category(T)]
    slotUsed = kind = new ? D.T_new : D.T_review
    slotLimit = kind = new ? lim.new : lim.review
    if slotUsed < slotLimit:
        added[T] = min(base(kind), max(0, lim.day_cap − D.T_points))
        D.T_new|T_review += 1
        D.T_points += added[T]
        if kind = long and added[T] > 0: D.T_long += 1
        if D.T_points ≥ lim.day_cap and D.T_capped_at = null: D.T_capped_at = now
    else added[T] = 0
D.raw_points += base(kind); D.new_count|review_count|long_count += 1
save(D)
INSERT score_credits (…, week_points = added[week], month_points = added[month])
if kind = long and added[month] > 0: L.long_<month_category>_on ??= day; L.first_long_on ??= day; save(L)
return ScoreOutcome
```

Правила, которые здесь зашиты:
- **Слот категории тратится, только пока не исчерпан лимит штук.** После лимита событие всё равно **потребляет** попытку дня, подтверждение нового слова и цикл повторения: «первые подходящие ответы до лимита» (docs/28). Иначе выгодно копить слова сверх лимита на завтра.
- **Остаток до потолка:** при 98 из 100 ответ на 5 даёт 2. Дальше 0 очков, но слот категории всё равно тратится.
- **Два зачёта независимы.** Одни и те же исходные ответы, у каждого зачёта своя категория и свои лимиты. Например, неделя Free (20/20/100), месяц Pro (30/30/150): 25-е новое слово даст 0 в неделю и 2 в месяц.
- **Граница суток.** День — по `now` сервера в момент принятия ответа. Карточка, показанная в 23:59 и отвеченная в 00:01, относится к новому дню. Проверка срока делается по `now`, поэтому переход через полночь её не отменяет. Игра берёт день по моменту ответа, а не `game_runs.local_date`.

### 3.5. Неделя = 5 лучших дней

Сумма недели — это сумма пяти наибольших `week_points` среди дней недели. Порядок дней при равенстве: `week_points desc, week_long desc, rating_day asc`. Он детерминированный и важен для ничьих. Признаки для ничьих: `long_words` = сумма `week_long` по этим пяти дням (внутри недели одна лексема не может дважды получить длинный повтор: следующий интервал ≥ 7 суток); `active_days` = число дней среди пяти с `week_points > 0`.

«За занятие +70, в недельный рейтинг +40»: `top5(дни недели)` минус `top5(дни недели, где у сегодняшнего дня вычтены очки занятия)`. Очки занятия — `SUM(week_points)` из `score_credits` по `source_id` сессии. Это одно чтение семи строк дня и одно чтение журнала, всё на экране финиша.

### 3.6. Игра

`RunService::accept` (транзакция ответа под `game_runs FOR UPDATE`), для словесных задач:
- `lexeme_id` кладётся в снимок при сборке забега (`RunComposer`), отдельного чтения нет. Забеги, начатые до выката, без него очков не дают;
- состояние повторения (`state`, `interval_days`, `last_reviewed_at`) читается **без блокировки** одним индексированным запросом `word_repetitions (learner_id, word_id)`. Правило 17 docs/18 («транзакция игры не трогает `word_repetitions`») сохраняется. Гонку с тренировкой по той же лексеме разводит блокировка `learner_lexemes`: второй увидит `last_attempt_on = today`;
- подсказка по элементу до ответа → `Assisted`; `skip` или `wrong` → `Failed`; `correct` или `typo` → `Passed`;
- повтор запроса возвращается из `game_run_attempts` по `request_id` и до начисления не доходит;
- в v2 `RunFinisher` перестаёт писать `game_points` и размещать в лигу. `game_runs.league_points` = сумма `week_points` начислений забега, только для показа.

### 3.7. Порядок блокировок (новый общий)

`… → learner_lexemes → learner_score_days → learner_daily_stats`.

- Тренировка: `training_sessions → word_repetitions → learner_lexemes → learner_score_days → learner_daily_stats`. **Вызов `record()` ставится до `DailyStatsService::recordReview`.**
- Игра: `game_runs → learner_lexemes → learner_score_days → (финиш: game_stop/city_progress → wallets → … → learner_daily_stats → class_caravans)`.

Обратного порядка нигде нет, поэтому цикла нет. Тест на взаимную блокировку — в S5/S6.

---

## 4. Конкурентность, идемпотентность, бюджет

| Сценарий | Что защищает |
|---|---|
| Повторная доставка `/review` | 409 `card_not_due` до начисления (срок уже сдвинут, билет погашен) |
| Повторный запрос игры | `game_run_attempts (run_id, request_id)` unique: ответ из журнала, `record()` не вызывается |
| Два устройства, одна карточка | `word_repetitions FOR UPDATE`, второй получает 409 |
| Два устройства, разные копии одной лексемы, или игра + тренировка | `learner_lexemes FOR UPDATE` — второй видит `last_attempt_on = today` |
| Первое начисление дня с двух устройств | строка дня: `insertOrIgnore` + `FOR UPDATE`, как `DailyStatsService::lockedRow` |
| Фиксация категории двумя запросами | PK `learner_score_periods`, `ON CONFLICT DO NOTHING` + чтение: побеждает первый |
| Повторный финиш занятия | финиш в v2 ничего не начисляет, только читает |
| Любая ошибка выше по стеку | всё в одной транзакции с оценкой: откат = нет ни оценки, ни очков |
| Последняя защита | `score_credits unique(learner_id, rating_day, lexeme_id)` |

**Синхронно, в транзакции ответа:** решение о попытке и начислении, строка дня, журнал, признаки в `learner_lexemes`. Решение зависит от порядка ответов и от состояния карточки до оценки. Оба есть только здесь. Асинхронный разбор журнала (очередь по `training_reviews`) отвергнут: пришлось бы расширять журнал состоянием «до», журнала игры там нет, «Сегодня N из 100» запаздывал бы, а лимиты решались бы задним числом.

**После commit (хуки, ошибки только в `report()`):** размещение в недельную группу при `firstCreditOfWeek`; проверка достижения при `newLongLexeme`.

**По расписанию** (`leagues:refresh-month` раз в 10 минут, существующая команда): строки месяца v2 и `learner_score_totals` для учеников со строкой дня за последние `refresh_recent_days`. При показе своей строки — `refreshLearner`, как сейчас.

**Бюджет `/review`.** Карточка без лексемы и знакомство: +0 операторов. Проверяемая попытка без начисления: +2 (`learner_lexemes` FOR UPDATE + UPDATE; первая встреча лексемы: +1 INSERT). Начисление: +5 (ещё строка дня FOR UPDATE + UPDATE + INSERT журнала). Первое начисление дня: ещё +3–4 (периоды и вставка строки дня). Лексема приходит вместе со словом (`RepetitionWithWord::WORD_COLUMNS += lexeme_id`). Тариф уже есть в руках (`$entitlement`). Поэтому `TrainingCycleQueryBudgetTest` поднимается с 14 до **19** с комментарием «v2 scoring: +2 попытка, +3 начисление» и проверкой, что `learner_score_periods` на втором ответе дня не читается. Объединять SELECT и UPDATE в один `UPDATE … RETURNING OLD` (PG 18) не стоит: тесты гоняются и на sqlite. Цена — 3 индексированных запроса по PK (~1 мс).

---

## 5. Таблицы лидеров

### 5.1. Неделя: группы по категориям

- `LeagueService::place` в v2 вызывается **при первом начислении недели** (хук после commit по `ScoreOutcome::firstCreditOfWeek`), а не при первой оценке дня. Так в группе нет нулевых участников, а категория группы = категории недели из `learner_score_periods`. Для недели v1 поведение прежнее.
- `groupWithSeat($week, $category, $tier)`: фильтр по категории. Ключ advisory-lock — `week_id*32 + (category==pro ? 16 : 0) + tier`.
- **Ярус переносится** между категориями: ярус отражает уровень игрока, категория — пул соперников. Перешёл в Pro из «золота» — играет в Pro-«золоте».
- `computeStandings` v2: по участникам группы — top-5 `week_points` (оконная функция `row_number() over (partition by learner_id order by week_points desc, week_long desc, rating_day)` ≤ 5; на sqlite она тоже есть) → `points, long_words, active_days` → конкурентный ранг. Удалённые и заблокированные выпадают из рангов: соседи сдвигаются, как требует docs/30.
- Ключ кэша — `league:standings:v3:{group}`: смысл значения изменился (docs/19 §3 п. 3).
- **Повышение и понижение при ничьей:** `rank ≤ promote` → повышен. Все, кто делит проходное место, проходят (решение владельца). Понижение: `rank > count − demote`, **но** тот, кто делит место с последним «безопасным», не понижается (рекомендация, вопрос В7). Пересечение зон — повышение побеждает, как сейчас.

### 5.2. Месяц и «За всё время»

- Месяц v2: `upsertScores` получает источник по версии месяца. `MonthScoreSourceV1` — нынешний SQL по `learner_daily_stats`. `MonthScoreSourceV2` — `learner_score_days`:
  - `points = SUM(month_points)`;
  - `active_days = COUNT(month_points > 0)`;
  - `capped_days = COUNT(month_capped_at)`;
  - `long_words` = `COUNT(DISTINCT lexeme_id)` из `score_credits` (`kind = long, month_points > 0`, не аннулировано);
  - `category = month_category`.
  Колонки `reviews`, `correct`, `time_spent_ms` для сигналов администратору по-прежнему берутся из `learner_daily_stats`. Это приблизительно (локальные даты), но сигналы только подсказывают.
- `learner_score_totals` пересчитывается **целиком из источника** на ученика: `SUM(month_points)`, `COUNT(month_points > 0)` по `learner_score_days` с группировкой по `month_category`, `MIN` и `MAX(rating_day)`; `long_words = COUNT(long_<cat>_on IS NOT NULL)` из `learner_lexemes`; `participant` — как в месяце (не удалён, не заблокирован, нет opt-out; онбординг не требуется — docs/34 §3). Пересчёт из источника снимает вопросы о дельтах: закрытие месяца сумму не меняет (она не зависит от статуса месяца), повтор даёт тот же результат, исправление дня просто меняет источник.
- **Личная сумма** «Мои очки за всё время» = сумма строк ученика по обеим категориям. Считается при чтении (≤ 2 строки), opt-out не влияет.

### 5.3. Ранг, ничьи, соседи, отрыв, пагинация

Порядок таблицы: `points desc, long_words desc, active_days desc, learner_id asc`. Последний ключ нужен только для стабильного внутреннего порядка и наружу не выходит.

- **Место (конкурентный ранг)** = 1 + число участников категории со строго лучшим кортежем `(points, long_words, active_days)`. Один `count` по индексу (§2.6). Для 50 тыс. строк в худшем случае — индексный обход нескольких десятков тысяч записей, единицы миллисекунд. Результат своего места кэшируется на 60 с.
- **Страница** — keyset: `?after=<cursor>`, где курсор = base64 JSON `{p, l, d, id}` от последней строки, `limit` 50. Места на странице: ранг первой строки берётся одним `count` (строго лучшие), позиция — вторым `count` (лучшие с учётом `learner_id`). Дальше места раздаются в PHP: равный кортеж — то же место, иначе место = позиции строки. Совместные места выглядят как «7, 7, 9», а порядок строк стабилен при перелистывании.
- **Соседи:** два запроса keyset — по 2 строки выше и ниже своей.
- **Отрыв до следующего места.** Берём ближайшего участника со строго лучшим кортежем, у него `P' ≥ P`. Гарантированный обгон = `P' − P + 1` очков: очки — первый ключ, поэтому лишнее очко обгоняет всегда. Если `P' = P` (впереди только по признакам ничьей), отрыв = 1. Ровно так docs/30 разрешает показывать отрыв, когда он вычислим. Для недели и месяца дополнительно сверяем отрыв с остатком дня (`day_cap − сегодня` и свободные слоты): не хватает — «Сегодня не догнать, завтра — да».
- **Почему не Redis sorted set.** ZSET — либо источник истины (нарушает docs/19 §3 п. 4 и 10: чистый кэш вытесняемый, без TTL нельзя), либо кэш, который при вытеснении надо перестраивать целиком (50 тыс. ZADD, лавина). Ничьи с тремя ключами пришлось бы кодировать в одно число. SQL с составным индексом отвечает за миллисекунды. Кэшируем только верх таблицы: `league:alltime:top:v1:{category}`, `league:month:top:v2:{month}:{category}` — массивы скаляров, TTL 120 с, сброс после пересчёта.

---

## 6. Версии правил и переход

### 6.1. Версии

`config/leagues.php` → `scoring`:

```php
'scoring' => [
    'mode' => env('LEAGUES_SCORING_MODE', 'off'),          // off | shadow | live
    'credits_retention_days' => 180,
    'versions' => [                                         // НЕИЗМЕНЯЕМЫЕ: правка = новая версия
        2 => [
            'starts_on' => env('LEAGUES_SCORING_V2_STARTS_ON', '2027-02-01'),
            'points' => ['new' => 2, 'review' => 3, 'long' => 5],
            'long_interval_days' => 7,
            'best_days' => 5,
            'limits' => [
                'free' => ['new' => 20, 'review' => 20, 'day_cap' => 100],
                'pro'  => ['new' => 30, 'review' => 30, 'day_cap' => 150],
            ],
        ],
    ],
],
```

- Версия периода = наибольшая версия с `starts_on ≤ начала периода`. Она пишется в `league_weeks.rules_version` и `league_months.rules_version` при создании строки и дальше не меняется. Открытые периоды доигрывают по своей версии.
- Веса в env не выносим. Значения версии — это опубликованные правила. Они проходят ревью кода и не меняются переменной окружения посреди месяца. Изменение = версия 3 с будущим `starts_on`.
- Режим `shadow`: начисления пишутся (`learner_lexemes`, дни, журнал) для калибровки, но версия периодов остаётся 1, ничего не показывается, места и призы — по v1. `live`: периоды, начинающиеся с `starts_on`, — v2.
- Редактор правил в /admin отвергнут: он позволил бы поменять веса живого периода. В /admin — только просмотр версий.

### 6.2. Дата старта

Рекомендация — **1 февраля 2027 года**. Это одновременно понедельник и 1-е число, поэтому неделя и месяц переходят на v2 в одну полночь, без смешанных периодов. Январь — режим `shadow` с 4 января (тоже понедельник): калибровка лимитов, доли дней с потолком и ничьих на реальных данных. В середине января — публикация правил в приложении. Следующая такая дата — 1 марта 2027 (тоже понедельник). Код поддерживает и несовпадающие даты: зачёты независимы.

### 6.3. Старая история

- **Не пересчитываем v1 в v2 и не раскладываем по категориям.** Данных для v2 нет (§0.7), тариф в прошлом не восстановить.
- **«Очки до 1 февраля 2027»:** команда `leagues:compute-legacy` после закрытия января (идемпотентный upsert) кладёт в `learner_score_legacy` личную сумму по формуле v1 с дневным потолком 300 (как считал месячный рейтинг) по всем строкам `learner_daily_stats` ученика. Период покрытия — `from_day..to_day`. В профиле: «Очки до 1 февраля 2027: 4 120 (с 23 апреля 2026)». В «За всё время» не входят.
- **Архив лидеров:** месяцы v1 с `league_month_scores` (ноябрь 2026 – январь 2027), полный список участников с `final_rank`, одна общая таблица с подписью «по прежним правилам». Недели в архив не идут: общей недельной таблицы никогда не было.
- В «За всё время» подпись «Учёт с 1 февраля 2027».

### 6.4. Исправление дня, скрытие, удаление

`ScoreCorrectionService` (Engagement):
- `void(Learner, ratingDay, ?list<creditId>, reason, User)` — одна транзакция. Строка дня FOR UPDATE, затем у выбранных (или всех) начислений ставится `voided_at`. Потом **повторный расчёт дня**: начисления дня, не аннулированные, по `id`, проходят через те же лимиты и потолки с категориями и версиями строки дня. Результат **заменяет** зачёты, `revision += 1`. Признаки `long_*_on` для затронутых лексем пересчитываются из журнала. `confirmed_on` и `last_credit_due_at` **не откатываются**: аннулированное слово второй раз очков не принесёт, и это консервативно. Запись в `audit_logs`.
- `restore(...)` — обратное действие с тем же пересчётом.
- **Идемпотентность:** повторное аннулирование уже аннулированного ничего не меняет, повторный расчёт детерминирован. Пример «60 → 45» из docs/30 — это аннулирование начислений на 15 очков. Повтор ничего больше не вычитает.
- После commit: `refreshLearner` открытого месяца, пересчёт `learner_score_totals` ученика, сброс верхов таблиц.
- **Закрытые периоды не переписываются.** Неделя (повышения уже применены) и месяц (кандидаты и места) остаются как есть. Меняется только «За всё время» и строка месяца как запись. Призы решает администратор: дисквалификация в `LeagueMonthReview`. Отмена приза очки не трогает (docs/30).
- Произвольное «поставить дню N очков» не делаем: вклад всегда выводится из журнала.

**Скрытие профиля** = существующий `leagues_opt_out`, отдельной настройки нет. Начисления продолжаются: личные суммы видит только владелец. В публичных таблицах всех трёх периодов `participant = false`, ученик не размещается в новые недели. `participationChanged` расширяется: пересчёт `learner_score_totals` ученика.

**Удаление:** мягкое → `participant = false` везде (тот же хук), из ранга недели выпадает сразу (§5.1). Окончательное удаление — каскад по FK всех новых таблиц.

---

## 7. Призы и закрытие периодов

- **Неделя v2:** `closeGroup` пишет итог из `computeStandings` v2: `final_points`, `final_rank` (конкурентный), `final_long_words`, `final_days`. Повышение и понижение — с учётом ничьих (§5.1). `league_top1` — всем с `final_rank = 1` и очками > 0. При ничьей победителей может быть несколько.
- **Месяц v2:** `closeMonth` — тот же барьер. `writeFinalRanks` = `RANK() OVER (PARTITION BY category ORDER BY points desc, long_words desc, active_days desc)` вместо `ROW_NUMBER`. `snapshotCandidates` — по каждой категории: допущенные (`active_days ≥ 10`, возраст аккаунта ≥ 14 дней к концу месяца — пороги прежние) с `final_rank ≤ candidates`, **включая всех, кто делит граничное место**. `close_delay_minutes` для месяцев v2 — 10 минут (`monthly.close_delay_minutes_v2`): день уже ташкентский, ждать западные вечера не нужно.
- **Подтверждение (`MonthlyRatingReviewService::confirm`)** — отдельно по категориям. Места = конкурентный ранг среди недисквалифицированных. Все разделившие призовое место получают одно место и одинаковый приз. Число победителей может превысить `places`, но только в пределах заранее объявленного резерва (`monthly.places.{free|pro}`, `monthly.tie_reserve.{free|pro}`). Если ничья выходит за резерв, администратор видит предупреждение и решает вручную. Правило публикуется заранее (В5).
- Сверка при закрытии: полный пересчёт строк **всех** учеников месяца из строк дня (как сейчас, `upsertScores` full), а не только кандидатов. Пересчёт `learner_score_totals` — после закрытия, тем же источником.

---

## 8. API и экраны

### 8.1. Ответ тренировки и игры

`ReviewResultResource` (POST /review) и `GameAnswerResource` получают блок `score`. Он собирается из `ScoreOutcome`, лишних запросов нет:

```json
"score": {
  "kind": "long",            // new | review | long | null
  "reason": "credited",      // credited | already_today | closed | early | same_cycle | not_due | no_catalog | capped | off
  "base": 5,
  "week": 2, "month": 5,     // добавлено в каждый зачёт (с учётом остатка)
  "today": {"points": 100, "cap": 100, "category": "free", "new": [20, 20], "review": [14, 20]},
  "today_month": null        // заполняется, только если категория или итог месяца отличаются от недели
}
```

`mode = off` или `shadow` → `score: null`, контракт для старых клиентов не меняется.

### 8.2. «Сегодня» и финиш

- `TodayResource.score_today`: `{points, cap, category, new: [used, limit], review: [used, limit], day_ends_at}` — чтение строки дня по PK, +1 оператор к бюджету «Сегодня» (docs/19 K10). `day_ends_at` — полночь по Ташкенту: ученик западнее видит «рейтинговый день закончится в 22:00».
- `SessionSummaryResource.league_points` в v2 = `SUM(week_points)` начислений сессии. Новые поля:
  - `score_session: {earned, week_rating_delta, month_added, capped_off}`;
  - `earned` = сумма base;
  - `capped_off` = сколько срезано лимитами;
  - `week_rating_delta` — §3.5.
  
  Экран: «За занятие +70 · в недельный рейтинг +40 · 5 очков сверх дневного потолка не засчитаны».

### 8.3. Вкладка «Лига»

- `GET /api/twa/league` (неделя) — свою группу. Новые поля: `category`, `rules_version`, у строк `long_words`, `active_days`, совместный `rank`, `next_gap` (§5.3), `action` (ниже). Неделю другой категории не показываем: недельная таблица — это группа, а не общий список.
- `GET /api/twa/league/month?category=free|pro&after=…` — по умолчанию категория месяца ученика (или текущий тариф, если начислений ещё не было). Новый ресурс страницы `LeagueBoardResource`: `{period, category, counted_since, me: {rank, points, long_words, active_days, next_gap, participant}, top3, rows (4–10 или страница), neighbours: {above[], below[]}, next_cursor, empty_hint}`.
- `GET /api/twa/league/all-time?category=free|pro&after=…` — тот же `LeagueBoardResource`, плюс `my_total` (личная сумма по всем категориям) и `legacy` (`{points, from, to}` или null).
- **«Ближайшая позиция и действие»** (`action`): `{kind: due_reviews|new_words|tomorrow, due_count, gap}`. `due_count` — число созревших повторений ученика. Это существующий подсчёт для «Сегодня», с кэшем на 60 с, без нового SQL. Если `gap ≤` остатка дня → «До 4-го места 8 очков. К повторению готовы 4 слова». Иначе → «Сегодняшний лимит почти выбран — следующий рейтинговый день через 5 ч».
- TWA: `LeagueView` получает сегменты «Неделя / Месяц / За всё время» (`SegmentedNav`, «Друзья» остаются отдельным переходом). На «Месяц» и «За всё время» — переключатель Free/Pro. Личная карточка, тройка, места 4–10, «Ещё», двое соседей сверху и снизу, подсказка новичку «Подтвердите новое слово в занятии». `MonthlyRatingView` становится панелью «Месяц». Профиль: «Мои очки за всё время» и строка «Очки до …». Экран тренировки показывает короткий чип «+3» после ответа (по `score.week`).

### 8.4. Достижение

`AchievementKey::LongRecall25` (`long_recall_25`): хук после commit при `newLongLexeme`. Если достижения ещё нет (существующий `unlock` идемпотентен по unique), считаем `COUNT(*) FROM learner_lexemes WHERE learner_id = ? AND first_long_on IS NOT NULL` по частичному индексу и при ≥ 25 открываем. На экране «Достижения» прогресс «N из 25» из того же счётчика.

### 8.5. /admin

- Страница «Правила лиги» (только чтение): версии из конфига, режим, дата старта.
- `LearnerResource` → менеджер связей «Очки по дням» (`learner_score_days`) с журналом дня. Действия «Аннулировать начисления» и «Восстановить» — с причиной, через `ScoreCorrectionService`, запись в audit.
- Виджет групп недели: колонка категории. `LeagueMonthReview`: переключатель категории, совместные места, предупреждение о резерве призов. Призы месяца — по категориям.
- Каждая новая или изменённая страница списка — с тестом бюджета запросов (docs/20 §1).

---

## 9. Этапы реализации

Обозначения исполнителя: **A** — architect (ядро, блокировки, закрытие), **C** — coder.

| # | Задача | Кто | Зависит от | Definition of Done и ключевые тесты |
|---|---|---|---|---|
| S0 (частично) | Обновить docs: 13 §7.3/§7.6 (v2), 03 (таблицы), 04 (A.13/A.19, новые поля), 06 §12.1 (если В3 = да), 19 (ключи `v3`/`v2`/`alltime`), статус 28/30 | координатор | ответы на §10 | docs согласованы до кода |
| S1 ✅ | Каталог лексем: миграции `lexemes`, `words.lexeme_id`; `LexemeKey`, `LexemeCatalog::ensureFor/matchFor`; подключение к импортёрам, наблюдателю, колодам; `lexemes:backfill` | C | — | нормализация (таблица случаев); платформа и организация создают, колода только совпадает; правка слова меняет лексему; backfill идемпотентен |
| S2 ✅ | Миграции §2.2–2.6 с `down()` и индексами | C (ревью A) | S1 | migrate/rollback на PG и sqlite |
| S3 ✅ | `ScoreRules` (версии из конфига, версия периода), `ScorePeriodService::fix()` | C | S2 | версия по дате; категория фиксируется один раз (параллельный тест); смена тарифа посреди недели не меняет категорию |
| S4 ✅ | **Ядро `WordScoreService`** + DTO + контракт `AnswerScoreRecorder` | **A** | S3 | матрица §3.2–3.4: new один раз за историю (две копии, два дня); review/long по назначенному сроку; подтянутая карточка = early; цикл после игры = same_cycle; ошибка или раскрытие закрывают день; лимит штук; остаток 98 → +2; разные категории недели и месяца; полночь; гонки двух соединений на PG (две копии одной лексемы, игра + тренировка) |
| S5 ✅ | Тренировка: снимок состояния «до», `lexeme_id` в `RepetitionWithWord`, вызов до `recordReview`, `AcceptedReview.score`, `ReviewResultResource.score` | A (C — ресурс) | S4 | бюджет `/review` 19, повтор → 409 без начисления, порядок блокировок (тест deadlock на PG), legacy `quality` → 0, flash новой карточки не закрывает |
| S6 ✅ | Игра: `lexeme_id` в снимке, начисление в `RunService::accept`, подсказка/skip/wrong, v2-`RunFinisher` без `game_points` | **A** | S4 | повтор `request_id` не начисляет; подсказка до ответа → 0 и закрыто; pairs/authored → 0; гонка игра + тренировка |
| S7 ✅ | `learner_lexemes:backfill` (подтверждённые по `word_repetitions.state ≠ new`, `confirmed_source = legacy`), режим `shadow` | C | S5, S6 | запускается **после** включения `shadow`; `ON CONFLICT DO NOTHING`; повтор безопасен |
| S8 ✅ | Неделя v2: категории групп, размещение при первом начислении, `computeStandings` top-5, ничьи и зоны, закрытие | **A** | S5 | top-5 с заменой слабого дня (пример 260 → 300); ничья на границе повышения повышает всех; удалённый выпадает; кэш `v3` |
| S9 ✅ | Месяц v2: источник по версии, категории, `RANK()`, кандидаты с ничьими, подтверждение по категориям | **A** | S5 | закрытие идемпотентно; кандидаты включают ничью на границе; v1-месяц не сломан |
| S10 ✅ | «За всё время»: пересчёт `learner_score_totals`, ранг, keyset, соседи, отрыв (§12.11) | A | S9 | пример 800+600 Free / 950 Pro → 1 400 / 950 / 2 350; закрытие месяца не меняет сумму; совместные места 7, 7, 9 и стабильные страницы |
| S11 ✅ | Исправления: `ScoreCorrectionService` | **A** | S4 | 60 → 45, повтор не вычитает; пересчёт признаков; закрытая неделя не меняется |
| S12 ✅ | API-ресурсы (`LeagueBoardResource`, поля «Сегодня», финиша, игры) | C | S8–S10 | контракт docs/04, тесты ресурсов (§12.9) |
| S13 | TWA: вкладка «Лига» с периодами и категориями, «Сегодня N из 100», финиш, профиль, чип ответа | C | S12 | vitest-снимки, i18n ru/uz |
| S14 ✅ | Достижение `long_recall_25` | C | S4 | открывается на 25-й лексеме, не раньше; идемпотентно |
| S15 ✅ | /admin: правила, очки по дням + действия, категории в виджетах и проверке месяца | C | S11 | тесты бюджета списков (§12.8) |
| S16 (частично ✅) | ~~`leagues:compute-legacy`, архив месяцев~~ (не нужны, В1), `leagues:prune-score-credits` ✅, события продукта для пилота (`score.credited` агрегатом в день ✅ §12.9, `score.day_capped` ✅) | C | S10 | идемпотентно; очистка не трогает дни открытого месяца |
| S17 | Ревью раздела | reviewer | всё | — |

**Порядок выката:**
1. S1–S7 с `mode = off`: схема, каталог, ядро, интеграции.
2. `mode = shadow` с 04.01.2027 + backfill S7: только данные, калибровка лимитов.
3. S8–S16 за флагом, публикация правил в середине января.
4. `live` с 01.02.2027 00:00 по Ташкенту: январь и неделя 25–31.01 закрываются по v1.
5. `leagues:compute-legacy` после закрытия января.

Аварийный откат после старта: v1-данные (`learner_daily_stats`) продолжают писаться. Команда `leagues:set-period-version {week|month} {id} 1` возвращает открытый период на v1. Это ручное решение администратора, всё логируется.

---

## 10. Открытые вопросы к владельцу (без них нельзя начинать)

| # | Вопрос | Рекомендация |
|---|---|---|
| В1 | Дата старта v2 и пилот | 01.02.2027 (понедельник и 1-е число); январь — теневой режим |
| В2 | Что считать каталогом | Лексемы создают платформенные **и** организационные курсы; колода ученика получает очки только при совпадении написания с каталогом |
| В3 | Самооценка `flash` на ступени 2 ранней лестницы (каждое второе зрелое повторение без очков) | В v2 заменить `flash` на `meaning` в списке формы (`reverse,audio,meaning`) |
| В4 | Пробный Pro и место в организации — это Pro? | Да: категория = действующий тариф (`EntitlementResolver`) в момент первого начисления периода |
| В5 | Число призов по категориям и резерв на ничью | Free — 3 места, Pro — 3 (или 1, пока Pro-участников < 100); резерв +1 приз на категорию; всем разделившим — одинаковый приз |
| В6 | Включать ли игру в начисления на старте | Да, по правилам §3.6 (цикл срока, подсказка закрывает), с выключателем `leagues.scoring.game_enabled` |

Решения architect'а, которые владелец может поменять без остановки работ: понижение при ничьей не применяется (В7); опечатка в вводе — успех (В8); групповой урок считается (В9); перенос яруса между категориями (§5.1); практика и баттлы — 0.

## 11. Риски

1. **Горячий путь.** `/review` +2…+5 операторов, бюджет 14 → 19. Нужен замер на PG под нагрузкой (perf-стенд `PerfSeeder`) до `live`.
2. **Порядок блокировок.** Новые таблицы должны идти до `learner_daily_stats` и в тренировке, и в игре. Ошибка = взаимные блокировки под нагрузкой. Обязателен тест на двух соединениях PG.
3. **Назначенный срок.** Предположение `next_review_at = last_reviewed_at + interval_days` у SM-2 и FSRS. Если когда-нибудь добавят случайный разброс интервала (fuzz) или привязку к началу дня, «в срок» сломается молча. Нужен тест-инвариант на оба планировщика и комментарий в `SpacedRepetitionEngine` и `FsrsScheduler`.
4. **Backfill `learner_lexemes` до `live`.** Без него каждое старое слово один раз даст «новое» (+2). Порядок: сначала `shadow`, потом backfill.
5. **Качество каталога.** Неудачная нормализация склеит или разделит слова. Склейка безопаснее. Нужна таблица случаев в тестах S1 и отчёт «слова колод без лексемы».
6. **Устаревший `quality` у `reverse`/`audio`.** Пока флаг включён, у таких клиентов 0 очков. Выключить до `live`.
7. **Понимание ученика.** День рейтинга (Ташкент) ≠ день цели и серии (локальный). Нужна подпись «до конца рейтингового дня N ч».
8. **Мало Pro.** Pro-группы недели будут маленькими, а Pro-таблица месяца — короткой. Пересмотреть число призов после пилота.
9. **Рост журнала.** `score_credits` до ~12 ГБ в год при 50 тыс. учеников. Срок хранения 180 дней обязателен с первого дня. Мониторинг размера — в DEVOPS_GUIDE.
10. **Групповые уроки.** Учитель может назвать ответ вслух. Если в пилоте доля начислений из `group_lesson` у лидеров аномальна, исключить их флагом (`source` в журнале это позволяет).

---

## 12. S4–S6: что сделано и контракт для S7–S16 (architect, 25.11.2026)

### 12.1. Принятые решения (отличия от текста выше)

- **DTO и контракт — в домене Learning**: `Learning\Contracts\AnswerScoreRecorder` (`records()`, `recordsGame()`, `record(ScoredAnswer): WordScoreOutcome`), `Learning\DTO\ScoredAnswer`, `CardStateBefore`, `WordScoreOutcome`, `ScoreDayCount`, `Learning\Enums\AnswerVerdict`, `WordScoreReason`. Имя `ScoreOutcome` занято игрой (`Game\DTO\ScoreOutcome`), поэтому `WordScoreOutcome`. Причины §3.1 дополнены `introductory` и `capped` (начисление записано, но лимиты дали 0 во всех v2-зачётах).
- **Ленивые входы.** `ScoredAnswer` принимает состояние карточки и тариф как значение или замыкание: игра читает `word_repetitions` (без блокировки) только когда решению нужен срок, тариф (`EntitlementResolver`, кэш) — только на первом начислении рейтингового дня.
- **Первая встреча с лексемой** — одна вставка строки `learner_lexemes` с итоговыми значениями (`INSERT … ON CONFLICT DO NOTHING`); проиграл гонку — обычный путь под `FOR UPDATE`. Строка дня, созданная этим вызовом, назад не читается. Журнал — `INSERT … ON CONFLICT DO NOTHING`: 0 строк = «уже сегодня», день не трогается.
- **Обновление `learner_lexemes` — одно на ответ**, в конце (попытка, подтверждение, оплаченный срок и признаки длинного повтора вместе).
- **`first_long_on`** ставится, когда длинный повтор добавил очки хотя бы в один зачёт (§2.2 «в любой категории»); `long_<категория месяца>_on` — только когда добавил в месячный. В `shadow` (версия 1) признаки не ставятся.
- **Вид и сырые очки** (`raw_points`, `long_interval_days`) берутся по старшей версии зачётов дня, а если оба зачёта на версии 1 (`shadow`, `live` до `starts_on`) — по последней настроенной версии: записанные факты соответствуют будущим правилам.
- **`learner_score_totals` в ответе не обновляется** — по §5.2 он пересчитывается целиком из источника (S10, расписание). Запись на каждый ответ меняла бы индексированные `points` (не-HOT UPDATE горячей строки) ради значения, которое и так пересчитывается.
- **Видимость**: `visible` = `live` и хотя бы один зачёт дня на v2; `toApi()` = `null` при `off`, `shadow` и `live` до старта (контракт §8.1 для старых клиентов не меняется).
- **Игра** (`RunService::accept`): начисление после оценки, до `RunFinisher`; в теле попытки (`game_run_attempts.result`) блок `score` пишется **только в `live`** — повтор `request_id` отдаёт тот же блок, при `off`/`shadow` тело прежнее. `game_runs.league_points` в неделе v2 копится из `weekAdded` ответов (без запроса на финише); `RunFinisher` в неделе v2 не размещает в лигу и не перезаписывает `league_points`, `game_points` дня не пишет, только когда **и** неделя, **и** месяц на v2 (иначе v1-зачёт месяца потерял бы игру). Версия недели/месяца на финише пока берётся из конфигурации (`ScoreRules::versionFor`) — S8/S9 переводят на `league_weeks/league_months.rules_version`.
- **Практика («Игры со словами»), баттлы, экзамены, тест уровня, квиз бота, `lesson_review`** — очков не дают и `record()` не вызывают (§3.2, docs/06 §15).
- **Групповой урок** — источник `group_lesson`. Групповые уроки идут по классической лестнице (`flash` на ступени 0): `flash` невыученной карточки после знакомства — раскрытие, попытка дня закрывается; слово подтверждается в личной тренировке на следующий день.
- **Индексы** (EXPLAIN на PostgreSQL, миграция `2026_11_25_300101`, docs/03 §2.70): все запросы ядра идут по PK/unique (`learner_lexemes_pkey`, `learner_score_days_learner_id_rating_day_unique`, `learner_score_periods_pkey`, `word_repetitions_learner_id_word_id_unique`); UPDATE `learner_score_days` — HOT, `learner_lexemes` — HOT, кроме записи `first_long_on` (предикат partial-индекса). В `score_credits` B-tree `(rating_day)` заменён на BRIN, снят FK `lexeme_id` (KEY SHARE на общую строку лексемы на каждой вставке), добавлен partial-индекс `voided_by_user_id IS NOT NULL`. Новых индексов на горячих таблицах нет.

### 12.2. Бюджет (закреплён `WordScoreQueryBudgetTest`)

| Путь ответа | Операторов добавляет начисление |
|---|---|
| `off`, слово без лексемы, знакомство (`flash` новой карточки) | 0 |
| попытка дня уже потрачена | 1 |
| закрыто / рано / тот же цикл / в шагах | 2 |
| начисление, строка дня есть | 5 |
| первое начисление рейтингового дня (неделя и месяц уже зафиксированы) | 7 |
| первое начисление недели (месяц зафиксирован) | 9 |
| первое начисление недели и месяца | 10 |
| игра, повторение | +1 (карточка без блокировки) |

`POST /review` (ежедневное повторение, следующее начисление дня): **14 → 19** операторов (`off` — без изменений). `TrainingCycleQueryBudgetTest` (режим `off`) не менялся.

### 12.3. Контракт для S7–S16

- **S7 `learner_lexemes:backfill`** — пачками по `learner_id`, каждая пачка в своей транзакции: `INSERT INTO learner_lexemes (learner_id, lexeme_id, confirmed_on, confirmed_source) SELECT wr.learner_id, w.lexeme_id, <рейтинговый день запуска>, 'legacy' FROM word_repetitions wr JOIN words w ON w.id = wr.word_id WHERE wr.state <> 'new' AND w.lexeme_id IS NOT NULL GROUP BY 1, 2 ON CONFLICT (learner_id, lexeme_id) DO UPDATE SET confirmed_on = COALESCE(learner_lexemes.confirmed_on, EXCLUDED.confirmed_on), confirmed_source = COALESCE(learner_lexemes.confirmed_source, EXCLUDED.confirmed_source)` — строки, созданные ядром до backfill (попытка закрыта, слово не подтверждено), тоже получают подтверждение. Блокирует только `learner_lexemes`; с ответом (одна строка лексемы, затем день) цикла не образует. Запускать до `live`.
- **S8 неделя v2** — размещение: хук после commit по `WordScoreOutcome::firstCreditOfWeek` (добавить `DB::afterCommit` в `WordScoreService::credit` рядом с `firstOfWeek` — одно место для тренировки и игры). Источник таблицы: `learner_score_days` (`learner_id`, `week_starts_on`, `week_points`, `week_long`, `rating_day`) по unique `(learner_id, rating_day)`. `RunFinisher::v2Periods` перевести на `league_weeks.rules_version`.
- **S9 месяц v2** — `SUM(month_points)`, `COUNT(month_points > 0)`, `COUNT(month_capped_at)` по `learner_score_days`; `long_words` = `COUNT(DISTINCT lexeme_id) FROM score_credits WHERE learner_id = ? AND rating_day BETWEEN ? AND ? AND kind = 'long' AND month_points > 0 AND voided_at IS NULL` (индекс unique, план проверен); категория — `month_category`.
- **S10 «За всё время»** — пересчёт из `learner_score_days` по `month_category` + `COUNT(long_<cat>_on IS NOT NULL)` из `learner_lexemes`; ответ не пишет `learner_score_totals`.
- **S11 исправления** — **порядок блокировок тот же, что у ответа**: сначала строки `learner_lexemes` затронутых лексем (`ORDER BY lexeme_id`, `FOR UPDATE`), потом строка дня, иначе взаимная блокировка с ответом (ответ: лексема → день). Пересчёт дня — повтор лимитов §3.4 по неаннулированным начислениям в порядке `id`; вынести расчёт зачёта из `WordScoreService::credit` в чистую функцию и использовать в обоих местах.
- **S12 API** — `ReviewResultResource.score` = `ReviewOutcome::$score?->toApi()`; в игре блок `score` уже в теле ответа (`live`). `TodayResource.score_today` — строка `learner_score_days` по unique `(learner_id, rating_day)`; `day_ends_at` — `ScoreRules::ratingDay(now)->addDay()`. Финиш занятия: `SUM(week_points) FROM score_credits WHERE learner_id = ? AND rating_day IN (дни сессии) AND source IN ('training','group_lesson') AND source_id = ?` (индекс unique).
- **S14 `long_recall_25`** — хук после commit по `WordScoreOutcome::newLongLexeme`, счёт — partial-индекс `learner_lexemes_first_long_idx`.
- **S16 очистка** — `DELETE FROM score_credits WHERE rating_day < ? AND id IN (SELECT id … LIMIT 5000)` пачками; BRIN `(rating_day)` обслуживает диапазон (план проверен).

### 12.4. Порядок включения на сервере

1. Выкатить код (`mode = off`), `php artisan migrate` (300101 — мгновенно: таблица пуста), `php artisan lexemes:backfill` (и `--decks`).
2. Выключить `LEARNING_OBJECTIVE_GRADING_ACCEPT_LEGACY_QUALITY` (иначе `reverse`/`audio` старых клиентов — 0 очков).
3. S7 backfill `learner_lexemes` (иначе каждое изученное слово один раз даст +2).
4. `LEAGUES_SCORING_V2_STARTS_ON=<ближайший понедельник или 1-е>` и `LEAGUES_SCORING_MODE=live`, `php artisan config:cache`, перезапуск воркеров; `php artisan leagues:scoring-cutover` — проверить план (открытые неделя и месяц остаются v1 и доигрываются по v1 — их строки уже записаны с версией 1). В день старта после 00:00 (неделя) / после закрытия месяца (03:00) — `php artisan leagues:scoring-cutover --apply` (закрывает то, что пора, по v1 и создаёт строки v2; то же сделает и планировщик). До S12–S13 экраны v2 не готовы — включать вместе с ними; `shadow` — только запись для отката (§12.6).

### 12.5. S5 (ресурс), S7, S14, S16: что сделано (coder, 26.11.2026)

- **`ReviewResultResource.score`** = `ReviewOutcome::$score?->toApi()` (docs/04 A.3): `null` при `off`, `shadow` и `live` до старта; `lesson_review` — всегда `null`. Тип `WordScore` в `resources/twa/src/api/types.ts` (поле необязательное, UI — S13).
- **S7 `learner_lexemes:backfill`** (`LearnerLexemeBackfill`, опции `--batch=200` учеников, `--learner=`): форма §12.3 с двумя дополнениями — `DO UPDATE … WHERE learner_lexemes.confirmed_on IS NULL` (повторный запуск не пишет ни одной строки и сообщает `confirmed: 0`; подтверждение ядра не трогается) и `ORDER BY learner_id, lexeme_id` (блокировки пачки в порядке S11). Пачка — диапазон `learners.id` (keyset, мягко удалённые включены), `word_repetitions` читается по диапазону unique `(learner_id, word_id)`. `confirmed_on` — рейтинговый день начала запуска (как в §12.3; настоящий день подтверждения не восстановить, §0.7); две копии лексемы — одна строка; повторный запуск в другой день дату не меняет. Слова без лексемы считаются в отчёте («studied cards without a lexeme») и пропускаются. Порядок запуска — DEVOPS_GUIDE §14.4.
- **S14 `long_recall_25`** (`AchievementKey::LongRecall25`, семейство `recall`, порог 25, ru «Долгая память», uz «Uzoq xotira»). Хук — `WordScoreHooks`: обёртка контракта `AnswerScoreRecorder` вокруг `WordScoreService` (привязка в `AppServiceProvider`), одна для тренировки и игры; `WordScoreService` не менялся. При `newLongLexeme` после commit — `AchievementService::onLongRecall`: проверка «уже есть» (unique), затем счёт `SELECT count(*) FROM (SELECT … WHERE learner_id = ? AND first_long_on IS NOT NULL LIMIT 25)` по `learner_lexemes_first_long_idx`, при 25 — `unlock` (идемпотентен). Прогресс «N из 25» в списке — тот же ограниченный счёт (пока ключ не получен). Механизма скрытия ключей в списке нет: при `off` ключ виден закрытым, как `game_first_boss` без игры.
- **S16:** `leagues:prune-score-credits` (`ScoreCreditPruner`, ежедневно 04:40 по поясу лиги, `withoutOverlapping(60)`, `--batch=5000`): граница = min(сегодня − `credits_retention_days`, 1-е число текущего месяца, 1-е число самого раннего месяца в `open`/`closing`/`pending_review`); `DELETE … WHERE rating_day < ? AND id IN (SELECT id … WHERE rating_day < ? LIMIT ?)` до пустой пачки, каждая — отдельный оператор. Событие `score.day_capped` (`{count: week|month, rating_day, category, cap}`) пишет `WordScoreHooks` после commit того ответа, который довёл зачёт дня до потолка (`points ≥ cap`, `points − added < cap`) — одно на ученика, день и зачёт, без записи на каждый ответ. **`score.credited` (агрегат в день)** — сделан в S12 (§12.9) по решению архитектора: одно сводное событие на рейтинговый день. Исходный вопрос: источник — строка `learner_score_days` за вчера (ночная команда), нужно решение, событие на ученика-день или одно сводное, и как сделать повтор команды безопасным.

### 12.6. S8, S9, S11: что сделано (architect, 26.11.2026)

**Версия периода — строка периода.** `league_weeks.rules_version` / `league_months.rules_version` пишутся один раз при создании строки (`LeagueService::currentWeek`, `MonthlyRatingService::currentMonth`) значением `ScoreRules::versionFor(первый день)` и дальше не меняются. Фиксация периода ученика (`ScorePeriodService::insert`) берёт версию **из этой строки** скалярным подзапросом по unique `starts_on` (`INSERT … SELECT … coalesce((select rules_version from league_weeks where starts_on = ?), versionFor) ON CONFLICT DO NOTHING` — тот же один оператор), а нет строки — `versionFor`, то самое значение, которое строка получит. Поэтому период ученика никогда не считается по другой версии, чем его таблица, даже если дату старта поставили в прошлое или режим переключили посреди периода. `RunFinisher::v2Periods` читает версии строк одним оператором (два скалярных подзапроса; `off` — ни одного).

**Неделя v2 (S8).**
- Размещение — после commit **первого начисления каждого рейтингового дня** недели v2 (`WordScoreHooks` — та же обёртка контракта, что вешает достижение и `score.day_capped`, одно место для тренировки и игры; признак — `WordScoreOutcome::firstCreditOfDay`): `LeagueService::place($learner, $now, $category)` с категорией недели из строки дня. Первое начисление недели — частный случай; последующие дни — no-op (2 чтения), зато ученик, вернувший участие, разблокированный или чей хук упал, садится на следующий день, как в v1. Хуки v1 (первая оценка локального дня, первый забег) в неделе v2 возвращают `null` без единого оператора после чтения недели: без начисления ученик не садится (нет нулевых строк).
- Группа: `groupWithSeat(week, category, tier)` — фильтр по категории и в v1 (все группы v1 — `free`); advisory-ключ `week_id·32 + (pro ? 16 : 0) + tier`. Ярус переносится между категориями. Ёмкость не превышается: `members_count < capacity` под `FOR UPDATE`/`SKIP LOCKED` + условный `increment` (как в v1).
- Таблица (`rankedStandingsV2`, один оператор): дни участника группы из `learner_score_days` по unique `(learner_id, rating_day)`, `row_number() over (partition by learner order by week_points desc, week_long desc, rating_day)`, первые `best_days` (5): `points` = Σ `week_points`, `long_words` = Σ `week_long`, `active_days` = дни из пяти с очками. Порядок `points, long_words, active_days desc`, `league_members.id` — только для стабильности; ранг конкурентный (1, 2, 2, 4). Удалённые, заблокированные и отказавшиеся (`leagues_opt_out`) вне рангов — соседи сдвигаются.
- Зоны по **рангу**: повышен `rank ≤ promote` (все, кто делит проходное место); понижен `rank > count − demote` (кто делит место с последним «безопасным», не понижается); пересечение — повышение. В v1 ранги различны — поведение v1 не изменилось.
- Закрытие: `final_points`, `final_rank` (конкурентный), `final_long_words`, `final_days`, `result` одним оператором на группу; член вне рангов — `final_rank = NULL`, `stayed` (ярус сохраняется, сообщения ему не уходят). Идемпотентно по `league_groups.closed_at`. `league_top1` — всем с `final_rank = 1` и очками > 0.
- Кэш: `league:standings:v3:{group}` для недели v2 (`LeagueService::cacheKey`), v1 — прежний `v2`. Факты размещения `league:member:v1` у недели v2 получают `version` (у v1 форма прежняя).
- `LeagueMembershipView` += `category`, `rulesVersion`, `nextGap` (`P' − P + 1` до ближайшего строго лучшего; `null` у лидера); `LeagueStanding` += `longWords`, `activeDays` (null в v1).

**Месяц v2 (S9).**
- Источник по версии месяца: `upsertScoresV2` — один `INSERT … SELECT … ON CONFLICT DO UPDATE … WHERE <изменилось>`: `SUM(month_points)`, дни с очками, `COUNT(month_capped_at)`, `raw_points`, `max_day_points = max(raw_points)` из `learner_score_days`; `long_words` = `COUNT(DISTINCT lexeme_id)` журнала (`kind = long`, `month_points > 0`, не аннулировано); `category` = `month_category`; сигналы администратора — из `learner_daily_stats` (локальные даты, как в v1). Участник — как в v1 (не удалён, не заблокирован, нет opt-out; онбординг не нужен — исправление выпуска A сохранено, `not_counted_reason` тоже).
- Ранг: конкурентный внутри категории (`ScoreBoard::rank`); `writeFinalRanks` — `RANK() OVER (PARTITION BY category …)` (PostgreSQL: `UPDATE … FROM`, окно считается один раз).
- Кандидаты — по категориям: допущенные (пороги прежние) с конкурентным рангом среди допущенных ≤ `candidates`, **включая всех на граничном месте** (граничный кортеж `offset candidates−1`, затем все не хуже). `league_month_candidates.category`, `rank` = этот ранг.
- Подтверждение (`MonthlyRatingReviewService::confirm`) — по категориям: место = конкурентный ранг среди недисквалифицированных категории; побеждают все с местом ≤ `monthly.categories.{cat}.places` (3 и 3, В5). Если победителей больше `places + tie_reserve` (резерв 1) — отказ `LeagueMonthException::TIE_BEYOND_RESERVE`, пока администратор не подтвердит с `acceptTieOverflow: true` (в аудите `tie_overflow_accepted`). Победители события `LeagueMonthConfirmed` несут `category`; поздравление берёт приз своей категории.
- Призы v2: `league_months.prizes = {free: [...], pro: [...]}`; список без категорий — одни призы обеим таблицам; места до `places(category)`.
- Чтение (`ScoreBoard`, общий с S10): `top(month, category)` (кэш `league:month:top:v2:{month}:{category}`), `board()` — keyset-страница `?after=` (`BoardCursor`, base64url `{p,l,d,id}`, строгая проверка: 4 неотрицательных int, иначе `null`), `neighbours()` (по 2 сверху/снизу, свой ранг, `next_gap`), `myLine` (+ `category`, `longWords`), `scoreDays()` (дни месяца для /admin).
- Закрытие v2 — тот же барьер и та же единственная транзакция, полный пересчёт перед рангами; задержка закрытия прежняя (180 мин; отдельные 10 мин для v2 не делались — расписание одно).

**Исправление дня (S11)** — `Scoring\ScoreCorrectionService::void/restore` (§6.4). Порядок блокировок: (1) `league_groups` группы ученика в неделе дня `FOR SHARE` — закрытие группы ждёт исправления или уже было; (2) `learner_lexemes` всех лексем журнала дня `ORDER BY lexeme_id FOR UPDATE`; (3) строка дня `FOR UPDATE`; журнал дня перечитывается под ней — начисление новой лексемы между чтениями → откат и повтор (до 3 раз, затем `score_day_busy`): лексемы никогда не берутся после дня. Пересчёт — чистая функция `Support\ScoreCount::add/replay`, та же, что в `WordScoreService::credit`, по неаннулированным начислениям в порядке `id`; хранимые очки активных начислений приводятся к пересчёту. Неделя дня окончательна (группа закрыта, неделя не `open` или закончилась) — недельный зачёт и недельные очки начислений не меняются; месячный пересчитывается всегда (из него «За всё время»). Признаки `first_long_on`, `long_<cat>_on` пересчитываются по журналу (следующий длинный день или пусто); `confirmed_on`, `last_attempt_on`, `last_credit_due_at` не откатываются. Повтор (уже аннулированные) — ничего не пишет, `revision` не растёт. Аудит `score.day_voided` / `score.day_restored`. После commit — пересчёт строки ученика в открытом месяце дня, сброс кэшей.

**Переход (`leagues:scoring-cutover`).** Без флага — план: режим, дата старта, строки текущих и следующих недели/месяца, хранимая версия и по чему считается; предупреждения (дата не понедельник / не 1-е, строка периода расходится с конфигурацией). `--apply` (только `live` + дата): закрывает всё, что пора закрыть (`closeDue` недель и месяцев — по их версии, то есть открытые v1-периоды по v1), и создаёт строки текущих недели и месяца, чтобы их версия была зафиксирована сейчас. Ничего не переписывается. Тест `LeagueScoringCutoverTest`: v1-неделя и месяц доигрываются и закрываются по v1, новые — v2, первое начисление сажает в группу своей категории.

**Индексы** (каждый — цена записи):
| Таблица | Решение | Почему |
|---|---|---|
| `league_groups` | сняты `(league_week_id, tier, members_count)` и `(league_week_id, category, tier, members_count)`; добавлен `league_groups_week_category_tier_idx (league_week_id, category, tier)` (миграция `2026_11_26_100001`) | поиск места фильтрует по категории и в v1; `members_count < capacity` сравнивает две колонки — индекс по нему не ищет, а `members_count` меняется на каждом размещении: без него инкремент — HOT. На 41 неделе групп (68 тыс. строк) план — этот индекс, 0,3 мс |
| `score_credits` | добавлен partial `score_credits_long_idx (learner_id, rating_day, lexeme_id) WHERE kind = 'long' AND month_points > 0 AND voided_at IS NULL` (`2026_11_26_100002`, CONCURRENTLY) | `long_words` месяца: без него обновление каждые 10 минут читало весь месяц журнала (BRIN bitmap, 30 тыс. страниц на 2 млн начислений); с ним — index-only 8 тыс. страниц, 145 мс на 20 тыс. учеников, один ученик — 4 страницы. В индекс попадают только длинные повторы с месячными очками (10–25 % вставок), 48 МБ на 5 млн строк против 226 МБ unique |
| `score_credits` | BRIN `(rating_day)` (S4, 300101) заменён на B-tree `score_credits_rating_day_index` (`2026_11_26_100003`) | очистка удаляет «голову» кучи, освобождённое место занимают новые дни — корреляция BRIN разрушается сама собой, план очистки становился Seq Scan; B-tree по растущему ключу пишет только в правый лист. При росте журнала за десятки миллионов строк — помесячные партиции и `DROP PARTITION` вместо `DELETE` (отдельная задача) |
| `learner_score_days` | без изменений; `(rating_day)` нужен | «недавние» ученики обновления месяца и диапазон месяца при закрытии; `rating_day` не меняется — UPDATE ответа остаётся HOT |
| `league_members` | без изменений | таблица недели — `league_group_id` + unique дней; `final_*` пишутся раз в неделю |
| `league_month_scores` | без изменений; **предложение:** снять v1-индекс `league_month_scores_standings_idx` отдельной миграцией после подтверждения последнего v1-месяца | сейчас каждое изменение строки v2 при обновлении пишет запись и в v1-индекс; после перехода он нужен только архиву, который по В1 не показывается |
| `learner_score_periods` | без индексов | подзапрос версии идёт по unique `starts_on` строк периодов |

**Операторы (закреплены `LeagueV2BudgetTest`) и планы** (PostgreSQL 16, 50 тыс. учеников, 1,25 млн строк дня, 5 млн начислений за 2 месяца, 1,25 млн `learner_daily_stats`, 50 тыс. участников недели в 1 668 группах + 68 тыс. групп прошлых недель, `EXPLAIN (ANALYZE, BUFFERS)`):
| Чтение / запись | Операторов | План, время |
|---|---|---|
| таблица группы недели v2 (промах кэша / попадание) | 1 / 0 | index `league_members_league_group_id_index` → unique `(learner_id, rating_day)` по 3–7 строк на участника, `learners_pkey`; 0,8 мс, 290 буферов — не зависит от размера таблиц |
| закрытие группы | 5 | блокировка, неделя, таблица, один UPDATE участников, штамп |
| место в группе (размещение) | 9 (PG 10: SKIP LOCKED + FOR UPDATE) после commit первого начисления недели — **те же**, что у размещения v1 на первой оценке; сверх v1 — +2 на первое начисление дня: чтение ученика (у хука только id) и чтение недели хуком v1, который в неделе v2 сразу выходит. Весь `/review` первого ответа недели: v1 + 10 (очки) + 2 (место) — `WordScoreQueryBudgetTest`; остальные ответы дня — 0 операторов лиги | `league_groups_week_category_tier_idx`, 0,3 мс |
| топ месяца категории (промах / попадание) | 1 / 0 | index-only `league_month_scores_category_standings_idx` + `learners_pkey`, 0,6 мс |
| страница после курсора | 3 (чтение + 2 count) | index-only, `points <= P` как диапазон, 0,45 мс |
| свой ранг | 1 | index-only по `points >= P`: середина таблицы 4,7 мс, последний из 40 тыс. — 9,8 мс (единственный месяц в таблице — планировщик берёт seq scan 907 страниц; кэш на 60 с — за S12) |
| соседи + отрыв | 6 | index-only (назад для «выше»), 0,1 мс каждое |
| строка ученика (`refreshLearner`) | 2 | unique-индексы и `score_credits_long_idx`, 0,7 мс |
| обновление «недавних» (каждые 10 мин; 20 тыс. учеников) | 1 | 1,3 с (диапазон дней месяца по `rating_day`, `learner_daily_stats_date_idx`, long — index-only); полный пересчёт при закрытии — 1,9 с; `RANK()` закрытия — 1,1 с |
| версии периодов в финише забега | 1 | два скалярных подзапроса по unique `starts_on`, 0,04 мс |
| исправление дня | 11 + 1 на каждое начисление с изменившимися очками + обновления признаков + пересчёт месяца после commit | все по PK/unique |

Гонки на PostgreSQL (`LeagueV2RaceTest`, fork): 12 одновременных первых начислений недели (ёмкость 3) — каждый сидит один раз, группы не переполнены и однородны по категории; два первых начисления одного ученика — одно место; закрытие недели одновременно с первыми начислениями понедельника тех же учеников — каждая группа закрыта один раз, ярус каждого = вердикт старой группы; два исправления дня одновременно с ответами — без взаимной блокировки, одно аннулировало, второе ничего не вычло, день = пересчёт журнала.

### 12.7. Контракт для S10, S12–S15

- **S10 «За всё время».** Пересчёт `learner_score_totals` — как §5.2 и §12.3. Чтение — тем же `Scoring\ScoreBoard` с `BoardScope::allTime($category)` (`rank`, `page`, `neighbours`, `gapToNext`) по существующему `learner_score_totals_standings_idx`; кэш верха — `league:alltime:top:v1:{category}`, массивы `BoardRow::toArray()`, TTL 120 с, сброс после пересчёта. Хуки пересчёта ученика: `ScoreCorrectionService::afterCommit` (после пересчёта месяца), `MonthlyRatingService::participationChanged`, после `closeMonth`.
- **S12 API.** Неделя: `LeagueService::overview()` → `membership` (`category`, `rulesVersion`, `nextGap`, `rank`, `standings[]` с `longWords`/`activeDays`); `overview()->week->rules_version`. Месяц: `MonthlyRatingService::isV2($month)`; для v2 — `myLine($month, $learner)` (`category`, `longWords`, конкурентный `rank`), `top($month, $category)`, `board($month, $category, BoardCursor::decode($request->query('after')), $limit ≤ 100)` → `{rows: BoardRow[], next: ?BoardCursor}` (наружу `next?->encode()`; неверный `after` → 422 или первая страница — решает ресурс, в запрос он не попадает), `neighbours($month, $score)` → `{above, below, rank, next_gap}|null`, `prizes($month, $category)`, `participantsCount($month, $category)`. Категория по умолчанию — `myLine()->category`, без строки — по тарифу (`ScoreCategory::fromPlan(EntitlementResolver)`). «Сегодня» и финиш — §12.3. Кэш своего места на 60 с — в ресурсе/сервисе S12 по правилам docs/19 §3.
- **S13 TWA** — только API S12.
- **S15 /admin.** Исправления: `ScoreCorrectionService::void(Learner, 'Y-m-d', ?list<int> $creditIds, string $reason, User, ?string $ip)` и `restore(Learner, 'Y-m-d', ?list<int>, User, ?string $ip)` → `ScoreCorrection` (`changed()`, `changedCreditIds`, очки недели и месяца до/после, `revision`, `weekFrozen`); ошибки — `ScoreCorrectionException::$errorCode` (`score_day_not_found`, `score_credit_not_found`, `score_reason_invalid`, `score_day_busy`); доступ — только `admin` (policy). Дни месяца — `MonthlyRatingService::scoreDays($month, $learnerId)`; журнал дня — `score_credits` по unique `(learner_id, rating_day)` в порядке `id` (страница списка — с тестом бюджета, docs/20 §1). Проверка месяца: `MonthlyRatingReviewService::review()` (у кандидата `category`, `provisionalPlace` — место в своей категории), `confirm($month, $user, $ip, null, acceptTieOverflow: bool)` — при `TIE_BEYOND_RESERVE` показать предупреждение и флажок «принять»; призы — `setPrizes($month, ['free' => [...], 'pro' => [...]])`, показ — `prizes($month, $category)` (у `MonthReview::$prizes` v2-месяца — только Free). Правила — `ScoreRules::mode()`, `versions()`, `version(2)?->startsOn`. Виджет групп недели — `league_groups.category`.

### 12.8. S15: что сделано (coder, 26.11.2026)

- **Правила (только чтение)** — блок «Правила очков за слова» над списком месяцев на странице «Рейтинг месяца» (`LeagueMonths`): режим, дата старта v2, версия текущей недели и месяца (строки `league_weeks` / `league_months` по unique `starts_on`; строки нет — версия, которую она получит сейчас, `ScoreRules::versionFor`), веса, интервал длинного повтора, лучшие дни, лимиты и потолки Free / Pro каждой версии, `game_enabled`, срок хранения журнала. Сборка — `Scoring\ScoreRulesOverview` (конфиг в памяти + 2 чтения). В списке месяцев — колонка «Правила» (`rules_version`). Редактора нет (§6.1).
- **«Очки по дням»** — менеджер связей `ScoreDaysRelationManager` карточки ученика (связь `Learner::scoreDays`): день, категории недели и месяца, их очки, длинные повторы (неделя / месяц), потолок, «без лимитов», число начислений и аннулированных (два скалярных подзапроса по unique `score_credits`), число исправлений; новые сверху, страницы 10/25/50/100. Действия строки: «Журнал дня», «Аннулировать день» (видно, пока есть активные начисления), «Восстановить день» (видно, если есть аннулированные).
- **Журнал дня** — страница `LearnerScoreDayCredits` (`/admin/learners/{learner}/score-days/{Y-m-d}`, не в меню): сводка дня (оба зачёта с категорией, версией и потолком, сырые факты, исправления) и начисления дня (слово по лексеме, вид, источник с id, очки в неделю и в месяц, время, кто и когда аннулировал, причина), `with(['lexeme:id,key', 'voidedBy:id,name'])`. Действия: «Аннулировать» / «Восстановить» начисления, «Аннулировать выбранные», «Аннулировать день» / «Восстановить день». Все исправления — `ScoreCorrectionService::void/restore` через `ScoreCorrectionActions`: причина обязательна (до 255), подтверждение, перед каждым вызовом `Gate::authorize('update', $learner)` (`LearnerPolicy::update` — активный админ), плюс `RequiresActiveAdmin` на каждом запросе компонента; уведомление «Очки недели: 60 → 45, очки месяца: …» и «Неделя этого дня уже закрыта…» при `weekFrozen`; повтор — «Ничего не изменилось»; отказы — по `errorCode` (`admin.learners.score_days.errors.*`).
- **Проверка месяца v2** (`LeagueMonthReview`): вкладки Free / Pro (`?category=`, по умолчанию Free) с числом кандидатов; кандидаты своей категории, их место в категории и пометка «общее место» при ничьей, колонка «Длинных повторов» (`signals.long_words`); призы обеих категорий (`MonthlyRatingService::prizes($month, $category)`), форма «Изменить призы» — 3 + 3 места (`free_ru_1`…, `pro_uz_3`) → `setPrizes($month, ['free' => …, 'pro' => …])`; «Баллы по дням» кандидата v2 — `scoreDays()` (месячный зачёт, потолок, исправления) со ссылкой на журнал дня. Ничья сверх резерва: предупреждение на странице и в окне подтверждения; обычное «Подтвердить итоги» получает отказ `TIE_BEYOND_RESERVE` (уведомление с числами), отдельное действие «Подтвердить с ничьей сверх резерва» (видно только при такой ничьей) требует флажок «Я принимаю…» и вызывает `confirm(..., acceptTieOverflow: true)` (в аудите `tie_overflow_accepted`). Месяц v1 — без изменений.
- **Виджет групп недели** — колонка «Категория»; в заголовке недели v2 — версия правил и итоги по категориям (одно сгруппированное чтение вместо двух). Ключи `admin:widget:league_week:v2:all`, `admin:widget:league_groups:v2:…` (форма значения изменилась, docs/19 §3 п. 3).
- **Индексы** (EXPLAIN (ANALYZE, BUFFERS) на PostgreSQL 16.15, 2 000 учеников, 360 тыс. строк дня, 2,7 млн начислений): новых нет. Страница «Очки по дням» — Index Scan Backward по `learner_score_days_learner_id_rating_day_unique` без сортировки (ключ к порядку не добавляется — `rating_day` уникален у ученика), подзапросы — Index Only / Bitmap по `score_credits_learner_id_rating_day_lexeme_id_unique`, 1,6 мс на 50 строк; счётчик — Index Only, 0,16 мс. Журнал дня — Bitmap по тому же unique + сортировка 25 строк по `id`, 0,07 мс; счётчик 0,04 мс; строка дня — unique, 0,02 мс. Группы недели по категориям — `league_groups_week_category_tier_idx`.
- **Бюджеты** (`ScoreAdminPagesTest`, 10 и 50 строк, два рендера на замер): «Очки по дням» — 5 операторов (потолок 6), журнал дня — 12 (потолок 14), страница месяца v2 — 2 на 10 и на 60 кандидатов (потолок 8).

### 12.9. S12: что сделано и контракт для экранов S13 (coder, 26.11.2026)

**API** (docs/04 A.2a, A.3 `/finish`, A.13, A.14, A.17, A.19; типы — `resources/twa/src/api/types.ts`). Правило совместимости: ключи v2 появляются **только** у периода v2 (неделя / месяц с `rules_version = 2`, день с зачётом v2 в `live`); в `off`, `shadow` и у периодов v1 ответы прежней формы — этих ключей нет. Исключение — `pro_emoji` (docs/31 §5а, не зависит от очков): есть всегда, `null` без действующего Pro.

- **Неделя** `GET /league`: верхний `rules_version: 2`; `membership.category`, `membership.rules_version`, `membership.next_gap`; в строках `long_words`, `active_days`, `pro_emoji`; ранги конкурентные.
- **Месяц** `GET /league/month?category=&after=`: все ключи v1 + `rules_version`, `category` (показанная таблица), `my_category`, `read_only`, `next_cursor`, `neighbours {above, below}`; `month.participants`, `prizes`, `top` — показанной таблицы; `me` + `category`, `long_words`, `next_gap`; `last_month` v2 — `winners` показанной категории и `winners_by_category`. Своя категория — строка месяца, без неё — текущий тариф (`MonthlyRatingService::categoryOf`). Чужая таблица — `read_only: true`, `neighbours: null`, `me` — своя строка. `?after=` — только страница (`LeagueBoardResource`: `{rules_version, category, read_only, rows, next_cursor}`); курсор привязан к месяцу и категории (`BoardPageCursor`: `m{месяц}{f|p}.{BoardCursor}`), битый / чужой / у месяца v1 → 422 `invalid_cursor` (`InvalidBoardCursor`).
- **«Сегодня»** `GET /today.score_today` (`ScoreTodayService`): `{points, cap, category, new, review, remaining, day_ends_at, month}` — пять подзапросов в общем операторе скаляров, **0 новых операторов** (в `off`/`shadow` подзапросов нет).
- **Финиш** `POST /finish.score_session` (`SessionScoreService`): `{earned, week_added, month_added, week_rating_delta, capped_off, today}`; в неделе v2 `league_points = week_added`. +2 оператора только в `live` у сессии с ответами.
- **Своё место месяца** — кэш `league:month:rank:v1:{месяц}:{кат}:{очки}:{длинные}:{дни}` (int, 60 с), соседи и отрыв — `league:month:near:v1:…:{ученик}:{кортеж}` (60 с). Свой кортеж в ключе: своё начисление (после `refreshLearner` в том же запросе) читает новый ключ сразу — сброс не нужен; движения других отстают ≤ 60 с. Худший случай без кэша — один `count` ~10 мс (§12.6).
- **Эмодзи Pro в списках** — `ProEmojiService::visibleForMany` одним запросом на список. Решение по кэшу: **(а) для топа месяца** — эмодзи едут в строках `league:month:top:*` (промах +1, из кэша 0), смена эмодзи сбрасывает ключи месяца (`MonthlyRatingService::proEmojiChanged`); **(б) для недели** — отдельный ключ `league:emoji:v1:group:{группа}` с TTL таблицы (60 с), сброс при смене эмодзи (`LeagueService::proEmojiChanged` по фактам размещения): таблица группы общая со строкой «Лига» на «Сегодня», и класть эмодзи в неё значило бы платить запрос эмодзи на промахе `/today`. Соседи месяца — в кэше `near` (60 с). Истёкший Pro — не дольше TTL (60 / 120 с): событие окончания Pro не пишется, а TTL короткий. Друзья (`friends[].pro_emoji`), баттлы (`opponent` / `from` / `friend`) — без кэша, +1 запрос на ответ. Бот: в итогах недели и в ЛС победителю месяца имён других учеников нет (сообщение — самому ученику), добавлять эмодзи некуда.
- **Событие `score.credited`** (`Scoring\ScoreDayReport`, команда `leagues:report-score-day [--day=Y-m-d]`, расписание 00:30 по поясу лиги, `withoutOverlapping`): одно событие на рейтинговый день, без ученика; props — `rating_day`, `learners`, `credits`, `{free|pro}_learners`, `{free|pro}_credits` (по категории месяца), `{week|month}_{free|pro}_{p50|p90|capped_share}` (только зачёты v2). Повтор безопасен: `INSERT … SELECT … WHERE NOT EXISTS (name = 'score.credited' AND props->>'rating_day' = ?)` — одна строка на день; проверка идёт по существующему `product_events_name_occurred_at_index` (одна строка этого имени в день), отдельный индекс не нужен. Вне режима `off`.

**Операторы** (sqlite, замер запросами `LeagueApiV2Test`; «после» — замер, «до» — замер минус операторы, добавленные S12; промах — холодные кэши таблиц, попадание — тёплые):

| Маршрут | off до → после (промах / попадание) | live v2 до → после (промах / попадание) |
|---|---|---|
| `GET /league` | 6 / 5 → 7 / 5 (эмодзи группы) | 6 / 5 → 7 / 5 |
| `GET /league/month` | 7 / 7 → 8 / 7 (эмодзи топа) | 10 / 9 → 17 / 7 (+6 соседи и отрыв, +2 эмодзи, −1 повторное чтение своей строки, место и соседи из кэша) |
| `GET /league/month?after=` (новое) | 422 | 7 (ученик, месяц, своя строка, доска 3, эмодзи 1) |
| `GET /today` (тёплый) | 5 → 5 | 5 → 5 (пять подзапросов в общем операторе) |
| `POST /finish` | 7 → 7 | 7 → 9 (начисления сессии, дни недели) |
| `GET /friends` | 5 / 4 → 6 / 5 (эмодзи) | то же |
| вид баттла / список / опции | +1 (эмодзи) | то же |

**Планы** (PostgreSQL 16, 50 тыс. учеников, 1,25 млн строк `learner_score_days`, 5 млн `score_credits`, 1 млн `product_events`, `EXPLAIN (ANALYZE, BUFFERS)`):

| Запрос | План | Время |
|---|---|---|
| пять подзапросов «Сегодня» | `learner_score_days_learner_id_rating_day_unique`, `learner_score_periods_pkey` ×2, строки недели и месяца по `starts_on` | 0,17 мс, 17 буферов |
| начисления сессии (`/finish`) | Bitmap по `score_credits_learner_id_rating_day_lexeme_id_unique` (ученик + дни), фильтр `source_id` по 25 строкам, GroupAggregate | 0,27 мс, 34 буфера |
| дни недели (`/finish`) | Index Scan `learner_score_days_learner_id_rating_day_unique`, ≤ 7 строк | 0,06 мс |
| эмодзи страницы (50 id) | `learners_pkey` × 50 + `subscriptions_one_live_per_subscriber` | 0,35 мс, 165 буферов |
| сводка дня `score.credited` (50 тыс. строк дня) | Index Scan `learner_score_days_rating_day_index` | 31 мс, 1 063 буфера, раз в сутки |
| `INSERT … WHERE NOT EXISTS` | InitPlan: `product_events_name_occurred_at_index` (`name`), фильтр `props` по 60 строкам | 0,1–0,4 мс |

Новых индексов нет.

**Контракт для экранов S13** (только этот API):
- *Вкладка «Лига» → «Неделя»*: при `rules_version = 2` — подпись категории группы (`membership.category`), совместные места («2, 2, 4»), под своей строкой «До N-го места X очков» из `membership.next_gap` (у лидера — нет), для строк — `long_words` / `active_days` как подсказка ничьей; эмодзи `pro_emoji` после имени во всех строках. Без `rules_version` — экран v1 как сейчас.
- *«Месяц»*: переключатель Free / Pro (`category` запроса, по умолчанию — ответ без параметра = своя `my_category`); при `read_only` — плашка «Вы соревнуетесь в {my_category}», без соседей. Личная карточка — `me` (`rank`, `points`, `next_gap`, `long_words`); тройка — `top[0..2]`, места 4–10 — `top[3..]` (размер топа `rules.top`); «Ещё» — `GET …?category=&after=next_cursor` до `next_cursor = null`, строки дописываются как есть (ранги приходят готовыми); «соседи» — `neighbours.above` / `below` рядом с `me`, если своей строки нет в показанном куске. Призы — `prizes` показанной таблицы, `month.participants` — её участники. Итоги прошлого месяца — `last_month.winners` показанной категории (или `winners_by_category`). 422 `invalid_cursor` → перечитать первую страницу.
- *«Сегодня N из 100»*: `score_today` — «Сегодня {points} из {cap}», полоски `new` / `review` ([использовано, лимит]), «до конца рейтингового дня» по `day_ends_at`; при `remaining = 0` — «Лимит дня выбран»; `month` (если не `null`) — вторая строка «В месяц: …». Нет ключа — блок не показывать.
- *Финиш*: `score_session` — «За занятие +{earned} · в недельный рейтинг +{week_rating_delta}», при `capped_off > 0` — «{capped_off} очков сверх дневного потолка не засчитаны»; `today` — та же строка «Сегодня N из 100». Без ключа — прежнее «+{league_points}».
- *Чип ответа*: `score.week` ответа `/review` и игры (§8.1, уже в API).
- *Друзья, баттлы*: `pro_emoji` после имени (`friends[]`, `opponent`, `from`, `friend`).
- Не сделано в S12: блок `action` (§8.3 «ближайшая позиция и действие») — экран собирает его сам из `next_gap`, `score_today.remaining` и `GET /today.due_count`; «За всё время» — S10.


### 12.10. Аудит SQL и индексов: что изменилось в числах §12.6 и §12.9 (architect, 26.11.2026)

Полный отчёт — `docs/38_SQL_AND_INDEX_AUDIT_RU.md` (стенд PostgreSQL 16.15: 50 тыс. учеников, 5,2 млн `word_repetitions`, 3 млн `score_credits`, 1 млн `learner_score_days`). Числа выше в §12.6 и §12.9 для этих строк больше не действуют:

| Чтение | Было | Стало | Чем закреплено |
|---|---|---|---|
| `GET /league/month`, месяц v2, промах / попадание | 17 / 7 (на стенде с прошлым месяцем 19 / 9) | **16 / 6** на стенде; в тесте без прошлого месяца 13 / 4 | `MonthlyRatingReadBudgetTest`, `LeagueApiV2Test` (промах − попадание = 9, было 10) |
| `GET /league/month/me` | 5 | **3** | `MonthlyRatingReadBudgetTest` |
| соседи + отрыв | 6 | **5**; **4**, когда место уже посчитано; +1 только если обе строки выше делят место ученика | `LeagueV2BudgetTest` |
| строка ученика при `GET` | транзакция + `FOR SHARE` + upsert на каждый запрос | один запрос (`MonthlyRatingService::freshScore`); пересчёт — только если день ученика изменился позже строки | `MonthlyRatingReadBudgetTest` |
| число участников таблицы | `count` на каждый запрос | кэш `league:month:count:v1:{месяц}:{категория\|all}`, в `topKeys()` | там же |
| эмодзи Pro списка (`visibleForMany`) | 7,4 мс, 314 буферов: читались все действующие подписки | 0,22 мс, 52 буфера: подзапросы сужены до id страницы | там же |

Что ещё: `ScoreRules::versions()` запоминает разбор конфигурации (сравнение с самим массивом — смена конфигурации в тесте и под Octane читается сразу); `MonthlyRatingReviewService::confirm` сбрасывает все ключи месяца (`topKeys()`), а не только ключ v1; `GET /league` у недели v2 отдаёт `best_days` из правил версии. Граница проверки свежести строки (редкая гонка начисления с пересчётом, расхождение часов серверов — своя строка отстаёт до следующего начисления или планового обновления раз в 10 минут) описана в `docs/38` §3.2.

Индексы: новых нет. Снят `training_sessions_status_index` (начало индекса `(status, expires_at)`, миграция `2026_11_26_200001`). `league_month_scores_standings_idx` — предложение §12.6 подтверждено замером (15–20 % записи при обновлении месяца), снимать после последнего месяца v1. `learner_lexemes.lexeme_id → lexemes` — явное `ON DELETE RESTRICT`, индекса на стороне ссылки нет намеренно.

### 12.11. S10 «За всё время», R6–R7 области: что сделано, решения и контракт для экранов R8 / S13b (architect, 27.11.2026)

Решения владельца, которые здесь главнее текста выше (docs/31 §5, §5г): «За всё время» считается **только из очков v2** (В1: подписи «Очки до…» и архива нет, `legacy` в ответе нет); рейтинг области — для месяца и «За всё время», **открыть можно таблицу любой области** (docs/35 Р3 отменено), призов в таблицах области нет, смена области — раз в 30 дней.

**Как считается.** `learner_score_totals` = §5.2 буквально: по ученику и `month_category` — `SUM(month_points)`, дни с `month_points > 0`, первый и последний рейтинговый день по строкам `learner_score_days` с `month_version >= 2`; `long_words` = `COUNT(long_<категория>_on)` из `learner_lexemes` (лексема считается один раз за всё время; читается по partial `learner_lexemes_first_long_idx` — `long_<кат>_on` без `first_long_on` не бывает: ядро ставит оба, исправление пересчитывает оба из одного журнала); участие — как в месяце; `region_code` — **текущая** область ученика. Пример docs/30: 500 + 300 (февраль, Free) + 600 (март, Free) + 950 (апрель, Pro) → строки Free 1 400 и Pro 950, личная сумма 2 350 (`AllTimeRatingTest`). Дни v1 (`shadow`, до старта) не входят.

**Когда обновляется** (`Scoring\ScoreTotalsService`, один оператор `INSERT … SELECT … ORDER BY learner_id, category ON CONFLICT DO UPDATE` на набор учеников):

| Когда | Кого | Почему так |
|---|---|---|
| никогда в ответе | — | §12.1: индексированные `points` сделали бы каждое начисление не-HOT обновлением; сумма всё равно пересчитывается из источника |
| `leagues:refresh-month` (раз в 10 мин), после месяца | учеников, чей день записан с **начала прошлого запуска минус `refresh_margin_seconds` (300 с)**; маркер `league:alltime:refreshed:v1` — на невытесняемом Redis. Маркера нет — ученики за `refresh_recent_days` (как месяц) | на стенде: 3 346 изменившихся учеников — 0,32–0,38 с; ничего нового — 12 мс. Запас ловит начисление, которое взяло отметку времени до начала запуска, а зафиксировалось после |
| хуки, после commit | один ученик: исправление дня (любого месяца, закрытого тоже), отказ от лиг / блокировка / удаление / восстановление (`participationChanged`), смена области (`regionChanged`) | сразу, без ожидания планировщика; ошибка — только `report()` |
| после закрытия месяца (`closeDue`) и `--full` | все, пачками по 5 000 id (`reconcile_batch`), пишутся только изменившиеся строки | сверка §7; закрытие сумм не меняет — повтор пишет 0 строк |
| свой экран | своя строка, если день ученика (сегодня/вчера — только они принимают начисления) изменился позже строки | один оператор чтения: свои строки + момент изменения дней + категория текущего месяца; устарело — пересчёт (1) и чтение (1) |

**Что показывает таблица между пересчётами:** последний пересчёт; чужие строки отстают ≤ 10 мин и TTL топа (120 с); своя строка, место и соседи — по своему свежему кортежу (ключ места содержит кортеж, как у месяца). Риск «отметка в ту же секунду» — как docs/38 §3.2: строгое сравнение, совпадение секунды → пересчёт.

**Блокировки и идемпотентность.** Пересчёт читает зафиксированные строки дней без блокировок и блокирует только свои строки `learner_score_totals` — ответ их не трогает, никто из ответа не ждёт. Все пересчёты блокируют строки в одном порядке (`ORDER BY learner_id, category`): запуск, сверка и хук не дают взаимной блокировки. Более старый пересчёт не перезаписывает более новый: `DO UPDATE … WHERE learner_score_totals.updated_at <= excluded.updated_at` (проигравший гонку проиграл более свежему; если тот не увидел позднее начисление, его отметка новее строки — следующий запуск или свой экран пересчитает). Повтор даёт те же строки; сверка без изменений пишет 0 строк. Гонки на PostgreSQL (`AllTimeRatingRaceTest`, fork): 6 учеников отвечают по 4 слова, параллельно — пересчёты каждого, два плановых запуска и сверка: без взаимных блокировок, следующий запуск приводит все строки к источнику; закрытие февраля (два закрывающих) одновременно со сверкой, плановым запуском, сменой области и 6 пересчётами — месяц закрыт один раз, строки равны источнику, область сменившего — новая.

**Область (R6).**
- `learner_score_periods.region_code` пишется **тем же** `INSERT … SELECT`, что фиксирует категорию (скалярный подзапрос по PK `learners`, без блокировки): +0 операторов на первом начислении периода. Месяц считается в области, где начат; `league_month_scores.region_code` берётся из периода месяца (`left join` по PK в обновлении месяца).
- Первое указание: `LearnerRegionService::recordChange` (все пути — настройки, онбординг, `set`, отмена) → `MonthlyRatingService::regionChanged`: после commit пустая область периодов **открытых месяцев v2** заполняется (`UPDATE … WHERE region_code IS NULL`), строка месяца пересчитывается, топы месяца сбрасываются; «За всё время» пересчитывается всегда.
- Смена области посреди месяца: заполненная область месяца **не меняется** (очки остаются там, где набраны); «За всё время» — сразу в новой области. Сброс «не указывать»: месяц остаётся в своей области (иначе сброс + новый выбор переносили бы месяц), «За всё время» уходит из таблиц области сразу.
- Известная граница: если первое начисление месяца и **первое** указание области совпали по времени (начисление прочитало `learners` до commit смены, а заполнение выполнилось до commit начисления), месяц остаётся без области до конца месяца — ученик не виден в таблице области этого месяца (в общей — виден; «За всё время» — в области). Окно — длительность транзакции ответа (миллисекунды); исправлять дороже, чем последствие.

**Чтение (R7).** Один класс чтения: `ScoreBoard` с `BoardScope::month($m, $cat, ?$region)` / `allTime($cat, ?$region)` — та же keyset-страница, ранг, соседи, отрыв; кэш — `BoardReader` + `BoardKeys` (docs/19 §3). Порог `leagues.regions.min_participants` (5): при 4 таблица скрыта (`shown: false`, строк нет), при 5 видна. Своя область — с местом и соседями; любая другая — только чтение (`read_only`, без соседей). Курсор привязан к периоду, категории и области (`m{месяц}{f|p}[~{код}]`, `a{f|p}[~{код}]`): чужой → 422 `invalid_cursor`. Без своей области `scope=region` → 422 `region_not_set`; область у месяца v1 → 422 `region_scope_unavailable`. Режим `off`/`shadow`: «За всё время» — `{enabled: false}`, ничего не пишется; ответ месяца v1 — без новых ключей.

**Стенд** (PostgreSQL 16.15, настройки как в `docker-compose.prod.yml`: `random_page_cost=1.1`, `jit=off`; Redis на своей БД): 50 000 учеников, 78 % с областью — город Ташкент 34,7 %, Ташкентская обл. 8 %, … редкие (Джизак, Навои, Сырдарья, Каракалпакстан, «Другая страна») по 1,0–1,1 %; 1 109 482 строки `learner_score_days` (февраль–10 апреля 2027, у каждого ученика своя активность 4–60 % дней, 20 % Pro, 3 % сменили категорию), 143 131 период месяца, 2 млн `learner_lexemes` (600 тыс. с длинным повтором), 143 тыс. `league_month_scores` (3 месяца), 51 381 строка `learner_score_totals`. `EXPLAIN (ANALYZE, BUFFERS)` — каждого оператора чтения (4 ученика: середина Ташкента, редкая область, последний в таблице, Pro; холодный и тёплый кэш): **ни одного Seq Scan по большой таблице** (Seq Scan — только `league_months` из 3 строк и пустая на стенде `subscriptions`).

| Чтение | Операторов холодный / тёплый (тест) | Время на стенде, холодный / тёплый (SQL) |
|---|---|---|
| `GET /league/all-time` | 13 / 2 | 20–49 мс (SQL 13–38) / 2–3,4 мс (SQL ≤ 0,9) |
| `GET /league/all-time?scope=region` (своя) | 12 / 2 | 15–31 мс (SQL 9–23) / 2,3–3,3 мс |
| `… &region=UZ-TK` (чужая, 13,5 тыс. участников) | 8–12 / 2 | 11–28 мс / 2–4 мс |
| `GET /league/all-time?after=` | 7 | SQL 3,5–6,7 мс |
| `GET /league/month` (с `me.region`) | 15 / 4 (S12: 13 / 4) | 16–39 мс (SQL 10–27) / 4–8 мс |
| `GET /league/month?scope=region` | 14 / 4 | 10–25 мс / 3,5–8,6 мс |
| пересчёт ученика (`recomputeLearner`) | 1 | 0,33 мс, 51 буфер |
| плановый запуск: 3 346 учеников / ничего нового / без маркера (16 тыс.) | 1 | 322–376 мс / 12 мс / 1,75 с |
| сверка всех (51 тыс. строк): без изменений / все изменились | 1 + 11 пачек | 1,5–1,6 с / 2,3–2,5 с |

Операторы и планы (максимум по 4 ученикам):

| Оператор | План | Время, буферы |
|---|---|---|
| свои строки + отметка дней + категория месяца | `learners_pkey`, `learner_score_totals_pkey`, `learner_score_days_learner_id_rating_day_unique` (2 дня), `learner_score_periods_pkey` | 0,07–0,1 мс, 15 |
| место во всей категории (последний из 38,6 тыс.) | Index Only `learner_score_totals_standings_idx`, `points >= P` диапазоном, Heap Fetches 0 | 8,5–13 мс, 423 (кэш 60 с по кортежу) |
| число участников категории | Index Only `learner_score_totals_standings_idx` | 7,6 мс, 423 (кэш 120 с) |
| место в области: Ташкент / Джизак | Index Only `learner_score_totals_region_standings_idx` | 1,4 мс, 79 / 0,05 мс, 5 |
| число участников области (Ташкент) | Index Only `…_region_standings_idx` | ≤ 4,2 мс, 149 |
| топ / страница / соседи (категория и область, месяц и «всё время») | Index Only `…standings_idx` (назад — для «выше») + `learners_pkey` (Memoize) | ≤ 0,33 мс, ≤ 158 |
| место и число в области месяца | Index Only `league_month_scores_region_standings_idx` | ≤ 2,7 мс, ≤ 110 |
| плановый запуск (набор) | `learner_score_days_rating_day_index` → `…learner_id_rating_day_unique` (вся история ученика) + `learner_lexemes_first_long_idx` + `learners_pkey` | ~30 строк дней на ученика; растёт с историей (риск) |
| пачка сверки (5 000 id) | диапазон `learner_score_days_learner_id_rating_day_unique` + `learner_lexemes_first_long_idx` | ~150 мс |

**Индексы** (каждый — цена записи; без префиксных дублей; v1-индекс месяца не тронут — решение аудита docs/38 §5.2):

| Индекс | Под какой запрос | Цена записи (замер: `UPDATE points + 1` всех строк, 3 прогона) |
|---|---|---|
| `league_month_scores_region_standings_idx (league_month_id, category, region_code, points desc, long_words desc, active_days desc, learner_id) WHERE participant AND region_code IS NOT NULL` (`2026_11_27_200002`, CONCURRENTLY) | топ, страница, место, соседи, число таблицы области месяца | 44 800 строк апреля: 770–806 мс с ним, 605–660 мс без (+~20 %, ~3 мкс на строку). Пишут только обновление месяца (раз в 10 мин — изменившиеся строки) и закрытие; ответ — нет. 6,2 МБ на 3 месяца (у индекса категории — 11 МБ): ученики без области и не-участники в него не попадают |
| `learner_score_totals_region_standings_idx (category, region_code, points desc, long_words desc, active_days desc, learner_id) WHERE participant AND region_code IS NOT NULL` (`2026_11_27_200003`, CONCURRENTLY) | то же для «За всё время» | 51 381 строка: 479–497 мс с ним, 346–405 мс без (+25–30 %, ~2,5 мкс на строку): плановый запуск (≈3 тыс. учеников) — +~8 мс. 1,9 МБ |
| `learner_score_periods.region_code` — без индекса | читается по PK (обновление месяца, заполнение области) | — |
| `learner_lexemes` — без нового | `long_words` по существующему `learner_lexemes_first_long_idx` | — |

`participant` — не ключ, а условие partial-индекса: каждое чтение спрашивает `participant = true`, при `DB_DISABLE_PREPARES=true` значение приходит литералом и предикат доказывается (в плане `Index Cond` без `participant`). При серверных подготовленных операторах PDO использует неименованные — план строится с известными значениями, предикат тоже доказывается.

**Контракт для экранов R8 / S13b** (docs/04 A.19, A.19a; типы `AllTimeResponse`, `BoardRegion`, `BoardMyRegion` в `resources/twa/src/api/types.ts`):
- Вкладка «Лига»: сегменты «Неделя / Месяц / За всё время»; на «Месяц» и «За всё время» — переключатель Free / Pro (`?category=`) и «Все / {область}» (`?scope=region`, своя — `me.region.code`/`name`). Список областей для выбора чужой — `GET /me` (`settings.regions`), запрос `?scope=region&region=<код>`.
- «За всё время»: `enabled: false` → вкладку не показывать; подпись «Учёт с {counted_since}»; личная карточка — `me` (место `rank`, `points`, «До N-го места X очков» из `me.next_gap`, «№ {me.region.rank} в {me.region.name}» при `me.region.shown`), «Мои очки за всё время: {my_total.points}» (Free / Pro — `my_total.free/pro`); тройка и 4–10 — `top`; «Ещё» — `?after=next_cursor` (с теми же `category`, `scope`, `region`) до `null`; соседи — `neighbours`.
- Таблица области: `region.shown = false` → пустое состояние «В {region.name} пока {region.participants} участника. Пригласите друзей» (`min_participants` — порог), без строк; `read_only` → плашка «Это не ваша таблица», без соседей. Призов в таблице области нет (`prizes: []` у месяца).
- Ошибки: 422 `region_not_set` → карточка «Укажите область» (docs/35 §2.3), 422 `invalid_cursor` → перечитать первую страницу, 422 `region_scope_unavailable` → переключатель области скрыть (месяц v1).
- Ученик без области: переключатель «Все / область» не показывать (или показать карточку выбора области); чужие области доступны всё равно.

**Шаги на сервере:** `php artisan migrate` (200001 — колонки без перезаписи таблиц и разовое заполнение области у периодов **открытых** месяцев текущей областью ученика — месяц, начатый до выкладки, иначе остался бы без области до конца; 200002/200003 — CONCURRENTLY, на 50 тыс. строк — секунды); при необходимости `LEAGUES_ALL_TIME_TOP_CACHE_SECONDS`, `LEAGUES_REGIONS_MIN_PARTICIPANTS` (умолчания 120 и 5); в режиме `live` один раз `php artisan leagues:refresh-month --full` — переносит область в строки открытого месяца и заполняет «За всё время» целиком (дальше — расписание; на стенде ~1,5 с месяц + ~2,3 с «За всё время»).

Не сделано: экраны (R8, S13b); `action` «ближайшая позиция» (§8.3) для «За всё время» — экран собирает из `me.next_gap`; плановый запуск читает всю историю каждого изменившегося ученика (~30 дней на стенде, через год ~150) — если станет заметно, хранить сумму закрытых месяцев отдельно.
