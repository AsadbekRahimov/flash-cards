# Очки за слова (v2), Free/Pro и «За всё время» — техническое проектирование

Документ architect'а · 24 ноября 2026 года. Основа: [28 — очки за знание слов](28_LEAGUE_SCORING_PROPOSAL_RU.md) и [30 — лучшие за всё время](30_ALL_TIME_RATING_PROPOSAL_RU.md) (предложения владельца). Обе ссылаются на «план реализации 31_COMPETITION_PLAN_RU.md». Такого файла нет. Его роль выполняет этот документ. Кубок Telegram-группы ([29](29_GROUP_CUP_PROPOSAL_RU.md)) проектируется отдельно. Здесь о нём важно одно: **кубок не даёт очков лиги** и не пишет ни в одну таблицу ниже.

Статус: **проект, код не писался.** Решения, которые меняют действующие правила, переносятся в docs/13 §7.3 и §7.6, docs/03, docs/04, docs/06 и docs/19 **до кода** (задача S0, §9).

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

**Конфликт с лестницей (В3).** На ступени 2 ранней лестницы список формы `reverse,audio,flash` чередуется. Без аудиофайлов каждое второе повторение зрелого слова приходит как `flash`, а это 0 очков и закрытая попытка. В итоге 5 очков за «длинный» повтор (интервал ≥ 7 — это как раз ступень 2+) Free получал бы примерно в половине случаев не по своей вине. Сервер выбирает упражнение детерминированно, ученик на это не влияет. Рекомендация: при `leagues.scoring.mode = live` заменить в `learning.ladder.early.form` `flash` на `meaning`, то есть `reverse,audio,meaning`. Это изменение метода docs/06 §12.1 и docs/25. Нужно решение владельца.

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
- `learner_score_totals` пересчитывается **целиком из источника** на ученика: `SUM(month_points)`, `COUNT(month_points > 0)` по `learner_score_days` с группировкой по `month_category`, `MIN` и `MAX(rating_day)`; `long_words = COUNT(long_<cat>_on IS NOT NULL)` из `learner_lexemes`; `participant` — как в месяце (не удалён, не заблокирован, онбординг пройден, нет opt-out). Пересчёт из источника снимает вопросы о дельтах: закрытие месяца сумму не меняет (она не зависит от статуса месяца), повтор даёт тот же результат, исправление дня просто меняет источник.
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
| S0 | Обновить docs: 13 §7.3/§7.6 (v2), 03 (таблицы), 04 (A.13/A.19, новые поля), 06 §12.1 (если В3 = да), 19 (ключи `v3`/`v2`/`alltime`), статус 28/30 | координатор | ответы на §10 | docs согласованы до кода |
| S1 | Каталог лексем: миграции `lexemes`, `words.lexeme_id`; `LexemeKey`, `LexemeCatalog::ensureFor/matchFor`; подключение к импортёрам, наблюдателю, колодам; `lexemes:backfill` | C | — | нормализация (таблица случаев); платформа и организация создают, колода только совпадает; правка слова меняет лексему; backfill идемпотентен |
| S2 | Миграции §2.2–2.6 с `down()` и индексами | C (ревью A) | S1 | migrate/rollback на PG и sqlite |
| S3 | `ScoreRules` (версии из конфига, версия периода), `ScorePeriodService::fix()` | C | S2 | версия по дате; категория фиксируется один раз (параллельный тест); смена тарифа посреди недели не меняет категорию |
| S4 | **Ядро `WordScoreService`** + DTO + контракт `AnswerScoreRecorder` | **A** | S3 | матрица §3.2–3.4: new один раз за историю (две копии, два дня); review/long по назначенному сроку; подтянутая карточка = early; цикл после игры = same_cycle; ошибка или раскрытие закрывают день; лимит штук; остаток 98 → +2; разные категории недели и месяца; полночь; гонки двух соединений на PG (две копии одной лексемы, игра + тренировка) |
| S5 | Тренировка: снимок состояния «до», `lexeme_id` в `RepetitionWithWord`, вызов до `recordReview`, `AcceptedReview.score`, `ReviewResultResource.score` | A (C — ресурс) | S4 | бюджет `/review` 19, повтор → 409 без начисления, порядок блокировок (тест deadlock на PG), legacy `quality` → 0, flash новой карточки не закрывает |
| S6 | Игра: `lexeme_id` в снимке, начисление в `RunService::accept`, подсказка/skip/wrong, v2-`RunFinisher` без `game_points` | **A** | S4 | повтор `request_id` не начисляет; подсказка до ответа → 0 и закрыто; pairs/authored → 0; гонка игра + тренировка |
| S7 | `learner_lexemes:backfill` (подтверждённые по `word_repetitions.state ≠ new`, `confirmed_source = legacy`), режим `shadow` | C | S5, S6 | запускается **после** включения `shadow`; `ON CONFLICT DO NOTHING`; повтор безопасен |
| S8 | Неделя v2: категории групп, размещение при первом начислении, `computeStandings` top-5, ничьи и зоны, закрытие | **A** | S5 | top-5 с заменой слабого дня (пример 260 → 300); ничья на границе повышения повышает всех; удалённый выпадает; кэш `v3` |
| S9 | Месяц v2: источник по версии, категории, `RANK()`, кандидаты с ничьими, подтверждение по категориям | **A** | S5 | закрытие идемпотентно; кандидаты включают ничью на границе; v1-месяц не сломан |
| S10 | «За всё время»: пересчёт `learner_score_totals`, ранг, keyset, соседи, отрыв | C по спецификации §5 | S9 | пример 800+600 Free / 950 Pro → 1 400 / 950 / 2 350; закрытие месяца не меняет сумму; совместные места 7, 7, 9 и стабильные страницы |
| S11 | Исправления: `ScoreCorrectionService` | **A** | S4 | 60 → 45, повтор не вычитает; пересчёт признаков; закрытая неделя не меняется |
| S12 | API-ресурсы (`LeagueBoardResource`, поля «Сегодня», финиша, игры) | C | S8–S10 | контракт docs/04, тесты ресурсов |
| S13 | TWA: вкладка «Лига» с периодами и категориями, «Сегодня N из 100», финиш, профиль, чип ответа | C | S12 | vitest-снимки, i18n ru/uz |
| S14 | Достижение `long_recall_25` | C | S4 | открывается на 25-й лексеме, не раньше; идемпотентно |
| S15 | /admin: правила, очки по дням + действия, категории в виджетах и проверке месяца | C | S11 | тесты бюджета списков |
| S16 | `leagues:compute-legacy`, архив месяцев, `leagues:prune-score-credits`, события продукта для пилота (`score.credited` агрегатом в день, `score.day_capped`) | C | S10 | идемпотентно; очистка не трогает дни открытого месяца |
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
