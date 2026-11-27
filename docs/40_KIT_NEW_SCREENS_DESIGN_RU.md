# 40. Новые экраны кита Muhrla: онбординг O1–O8 и F1–F5 — проектное решение (2026-11-27)

Кит: `docs/design/2026-11-27-muhrla-kit/` (`docs/ONBOARDING.md`, `docs/NEW-FEATURES.md`, `docs/TELEGRAM.md`, PNG `02-onboarding/`, `03-new-features/`).
Правила кита в коде — docs/14 §4.0. Существующие экраны уже перерисованы (PROGRESS.md, 27.11.2026). Здесь — как встроить **новые** экраны
в то, что есть, с минимумом новых сущностей, и как нарезать работу. F6 «Язык» сделан листом — не трогаем.

Главное правило: **кит — это вид.** Алгоритм обучения (SM-2 / FSRS, `CardPicker`, спринт), правила очков лиги и биллинг не меняются.
Где кит предлагает иное (свой алгоритм «[1,2,4,7,14,30]», «+100 баллов лиги за реферала», «5 слов в день»), мы берём вид и подкладываем
под него существующую механику; спорное — в §3 «Решения владельца».

Документы, которые правятся **до кода** соответствующей задачи (задача T0): docs/03 §2.17 (новые колонки `learners`), docs/04 (разделы
A.24–A.27 ниже), docs/05 §3 (маршруты Mini App), docs/06 §13.3 (производная «сила памяти»), docs/07 §6 и §10 (лимиты, имя),
docs/13 §7.4 (карточка 9:16), docs/14 §4.0 (отступления от кита из этого документа).

---

## 1. Сводная таблица

| Экран | Что переиспользуем | Новое: БД | Новое: API | Новое: Mini App | Объём |
|---|---|---|---|---|---|
| **O1–O8 онбординг** | `OnboardingService` (завершение, выбор курса, события), `LearnerSettingsService::update` (цель дня с лимитом Free, напоминание, уровень), `CourseCatalogService::recommend`, `DailyGoalLimiter`, `DisplayName` (правила имени из кубка), `ProTrialService` (пробный Pro уже выдаётся на `/auth`), `seenFlag`, `useBackButton`, `SegmentProgress`, `MascotArt`, картинки тем | `learners.display_name`, `age_range`, `goals` (одна миграция) | `GET /onboarding`, `PUT /onboarding/steps/{step}`, `POST /onboarding/complete`; поля `display_name`, `onboarding{completed, step}` в `/auth` и `/me` | маршрут `/onboarding`, 8 шагов, стор, страж роутера, `requestWriteAccess` в `useTelegram` | **L** |
| O5 «Тест за 2 минуты» | `ExamQuestionBuilder::forWords` (варианты), каталог курсов | — (черновик теста в `learners.meta.twa_onboarding.placement`) | `POST /onboarding/placement`, `POST /onboarding/placement/answers` | экран теста (10 вопросов) на `QuizOption` | **M** (отдельный выпуск, решение владельца) |
| **F1 Повторение** | Уже есть: `daily_review`, `FlashCard` (две кнопки кита), `SegmentProgress`, прогресс раунда 12 | — | `card.memory {strength, stage, next_in_days}` в `GET /training/sessions/{id}/next` (0 запросов) | точки силы 0–5 и «повтор через N дней» под карточкой | **S** |
| **F2 Мой словарь** | `word_repetitions` + `words`, `WordTranslationResolver`, `WordAudioService::urlFor`, `WordStage`, индекс `(learner_id, word_id)` | — (новых индексов нет, см. §2.3) | `GET /vocabulary` (поиск, фильтр, keyset) | маршрут `/vocabulary`, строка «Мой словарь» на экране курса | **M** |
| **F3 Сторис + баттл** | `AchievementCardRenderer` (GD, шрифты, диск `share-cards`, `publicUrl`, чистка `achievements:prune-cards`), `ShareKind`, лимит `twa-share`, `useShare('friend_invite')`, `ReferralService::inviteLink` | — | `POST /share/story` → `{media_url, text, widget_link}`; `ShareKind::Streak`; `StoryCardRenderer` 1080×1920 | `shareToStory` в `useTelegram`, экран `/share/story`, вход с достижений и с F4 | **M** |
| **F4 Серия спасена / прервалась** | `StreakService` (заморозки, `frozen_yesterday`, `freezes`), `learner_daily_stats` недели, `PaywallSheet reason=streak_freeze`, `StampMark` | `learners.streak_lost_days`, `streak_lost_on` (одна миграция) | `/today.streak` += `week[]`, `lost` (0 новых операторов) | экран `/streak/saved`, показ один раз (`seenFlag`) | **S/M** |
| **F5 Нет сети** | `ApiClientError kind=network`, `InlineAlert`, `serve_token` и защита от повторного submit (docs/06 §5.2) | — | — | `useOnline()`, экран `OfflineScreen`, незавершённый ответ тренировки в `sessionStorage` и повтор | **S** |

Что **не делаем** (подробно в §2): свой алгоритм повторения кита; «+100 баллов лиги»; «повторять слова офлайн» (очередь офлайн-ответов);
«Фразы» как стартовый курс; варианты «5» и «30» слов в день; возрастные ограничения кодом; показ своего имени чужим людям без решения владельца.

---

## 2. По каждому экрану

### 2.1 Онбординг O1–O8 в Mini App

**Что есть.** Онбординг идёт в боте: `/start` → `OnboardingService` (шаги `locale → region → goal (только при нескольких целях; сейчас одна — `general`, шаг пропускается) → level → reminder`), `learners.onboarding_state` (`new` … `done`), `onboarding_completed_at`. В конце `completeCourse()` выбирает курс (`recommend(goal, level)` → общий → курс по умолчанию). Поля ученика: `first_name` (снимок Telegram, **перезаписывается** на каждом `/auth` и `/start` — `LearnerRegistrar::SNAPSHOT_FIELDS`), `interface_locale`, `goal` (exam-tag: general/ielts/sat/school), `level` (A1..C2), `daily_goal` (оценок в день, варианты `learners.daily_goal_options` = 10/20/40, Free ≤ 10 — `DailyGoalLimiter`), `reminder_time` (любые четверть часа, `NULL` = выкл.), `timezone` (всем `Asia/Tashkent`, шага нет). Возраста и нескольких целей нет. Ученик из Mini App создаётся на `/auth` (`LearnerRegistrar::fromMiniApp`, `onboarding_state = new`, пробный Pro выдаётся там же как «первый собственный контакт»). Mini App сейчас показывает подсказку «завершите настройку в боте» на «Сегодня» и `IntroSheet` (слайды, `seenFlag`).

**Решение.** Онбординг Mini App — **отдельный поток поверх тех же колонок**, не вторая машина состояний:

- **Бот-онбординг остаётся как есть.** Его машина `onboarding_state` не расширяется (шаги разные: в боте нет имени, возраста, цели дня; в Mini App нет языка и области — язык берётся из `language_code` и F6, область остаётся карточкой на вкладке «Рейтинг» и шагом бота).
- **Прогресс Mini App** — `learners.meta.twa_onboarding = {step: 'age', started_at}` (следующий шаг; черновиков значений нет — каждый шаг пишет свою колонку сразу). Возобновление: `/auth` отдаёт `onboarding.step`, клиент открывает его.
- **Завершение — одно место.** В `OnboardingService` добавляется публичный `completeExternally(Learner, string $channel): void` — под той же блокировкой строки: если не `done` → `fillSkippedGoal`, `completeCourse()` (тот же выбор курса, что в боте), `onboarding_state = done`, `onboarding_completed_at`, `scheduleReminder`, события `onboarding.completed {channel: mini_app, …}` и `course.selected`. Повтор — ничего не делает. Так «done» пишет один класс, и бот с Mini App не расходятся.
- **Новые колонки** (`learners`): `display_name varchar(32) null` (имя, выбранное учеником; `NULL` → показываем `first_name`), `age_range varchar(8) null` (`lt14 | 14_17 | 18_24 | 25_34 | 35_44 | 45p`), `goals jsonb null` (список из `career | study | travel | relocation | communication | media`, 1–6 без повторов; sqlite — `json`). Индексов нет: на горячих путях по ним не фильтруют. `down()` — `dropColumn` трёх колонок. Старая колонка `goal` (exam-tag) не трогается: Mini App пишет в неё `general` через `fillSkippedGoal`, как бот.
- **Имя.** Правила — `App\Domain\Cup\Support\DisplayName::validate()` (1–32 графемы, ≤ 64 кодовых точек, без управляющих / невидимых / bidi, без ссылок, `@`, `/`, `#`): 422 `display_name_invalid {reason}`. Подсказка в поле — `initDataUnsafe.user.first_name` на клиенте; сервер имя из `initData` для этого шага **не читает** (в его распоряжении уже проверенный `first_name`). Где показывается `display_name`: приветствие «Сегодня», O8, «Профиль» — только свои экраны (§3, В1). Лига, друзья, баттлы, бот — по-прежнему `first_name`.
- **Возраст** — только хранится. Никому не показывается (ни в одном Resource, кроме `GET /onboarding` самого ученика), в `/admin` — колонка-фильтр по желанию позже. Ограничений для < 18 кодом нет (§3, В5).
- **Уровень (O5).** Варианты — **курсы**, не буквы: сервер отдаёт `options.levels` — для каждого `OnboardingService::LEVELS` курс `recommend('general', level)` (из кэша каталога `catalog:courses:published`), без курса фраз (docs/35 §3.3: он не бывает основным), слитые по slug (A1 и A2 ведут в один курс → одна строка «A1–A2»). Выбор пишет `level` (через `LearnerSettingsService::update`) и запоминает `course_slug` в `meta.twa_onboarding`; `active_course_id` ставится при завершении, если ещё пуст — **именно этот курс** (если он ещё в каталоге; иначе прежний путь `recommend(goal, level)`): `goal`, записанная ботом, иначе дала бы не тот курс, что на O8. Есть активный курс — он не меняется, и O8 / `GET /onboarding` / ответ `complete` показывают его (ревью 27.11.2026). Строки уровней — только уровни со своим курсом (+ соседний A2 в строке «A1–A2»), то же правило, что у теста уровня. Для «B1 · 20 модулей» на O8 — `stages_count` добавляется в `CatalogCourse` (кэш каталога `v1 → v2`); это же закрывает пробел «нет `stages_count` в `GET /courses`» из PROGRESS.md.
- **Цель дня (O6).** Варианты — `options.daily_goals` (10/20/40) с подписями кита «Спокойно / Серьёзно / Интенсив», `daily_goal_max` и замок как в настройках (docs/05 §8c-1). Новичок в пробном Pro выбирает любую; после пробного `DailyGoalLimiter` снижает до 10 (существующее поведение). «Рекомендуем» — 20. Вариантов 5 и 30 нет (§3, В3). Это **оценки в день**, не новые слова; подпись честная: «карточек в день · ~N мин».
- **Напоминание (O7).** Шесть времён кита (08:00, 12:30, 18:00, 20:00, 21:30, «Своё» — шаг 15 мин) и «Не напоминать» — всё уже принимает `LearnerSettingsService` (`reminder_time`). Переключатель «Сообщения от бота» → `WebApp.requestWriteAccess()` (Bot API 6.9): отказ → переключатель Off и подпись; сервер о результате узнаёт только событием (`onboarding.step_completed {step: reminder, write_access: granted|denied|not_asked}`). Доставка и так защищена: 403 при отправке → `dm_blocked_at`. Ученику, пришедшему через `/start`, запрос не нужен — клиент всё равно вызывает: Telegram отвечает `granted` сразу.
- **O8** — сводка из сохранённого; «Начать первый урок» → `POST /onboarding/complete` → переход на `/today?from=onboarding`; «Сегодня» сам предлагает шаг 1 плана (урок). Источник сессии `onboarding` в `SessionSource` уже есть.
- **Когда показывать.** Страж роутера: `auth.learner.onboarding_completed === false` **и** маршрут — корень вкладки (`meta.tab`) **и** нет `start_param` (deep link на баттл, кубок, задание, сессию не перехватываем) → `replace` на `/onboarding/<step>`. Прошедшие бот-онбординг (`done`) экранов не видят. Ученик посреди бот-онбординга видит Mini App-онбординг; что он завершит первым — то и поставит `done`; старые кнопки бота отвечают «устарело» (`staleStep`, уже так). Новичок до `/start`: `/auth` создаёт ученика, онбординг идёт в Mini App; позже `/start` видит `done` и шлёт приветствие пробного Pro (`StartCommandHandler` уже так делает). `IntroSheet` для прошедших Mini App-онбординг не показываем (ставим `markSeen(INTRO_KEY)` при завершении). Подсказку «завершите настройку в боте» на «Сегодня» убираем **позже** отдельной мелкой правкой (файл сейчас в работе у исполнителя).
- **Закрыть на O1** — системная «Закрыть» Telegram; при следующем входе онбординг снова с O1 (или с сохранённого шага). Своей кнопки «Пропустить» нет (в ките нет).

**Схема данных и миграция.** `2026_12_02_100001_add_profile_fields_to_learners_table`: три колонки выше; `down()` их снимает. `DatabaseSchemaTest`: откат. `LearnerFactory`: состояния `withTwaOnboarding(step)`.

**API (docs/04 A.24).** Все — под `TwaAuth`, `SetTwaLocale`; чтение под `twa-api`, записи ещё под новым `throttle:twa-onboarding` (30/мин на ученика: шаги — быстрые нажатия, но и подбор тут нечего).

| Метод | Путь | Тело | Ответ | Ошибки | Бюджет SQL |
|---|---|---|---|---|---|
| GET | `/api/twa/onboarding` | — | `OnboardingStateResource` (ниже) | 401 | ≤ 3: ученик (auth) + каталог из кэша (холодный — +1–2) + строка выбранного курса из кэша (0) |
| PUT | `/api/twa/onboarding/steps/{step}` | по шагу (ниже) | тот же Resource (200) | 401; 404 неизвестный шаг; 409 `onboarding_completed`; 422 `validation` / `display_name_invalid {reason}`; 403 `daily_goal_pro_required {max}` | ≤ 5: auth + `SELECT … FOR NO KEY UPDATE` + `UPDATE learners` (+ у `level` — `Course::find` курса 1; + у `daily_goal` — тариф из кэша 0 и `reconcileGoal` после commit 1–2, как в настройках) |
| POST | `/api/twa/onboarding/complete` | — | `{completed: true, course: {slug, title}\|null, next_lesson_id: int\|null}` | 401; 422 `onboarding_incomplete {missing: [level]}` | ≤ 7: auth + lock + поиск курса (кэш каталога, `Course::find` 1, `findAvailable` 1 в худшем случае) + `UPDATE` + следующий урок 1 |

Шаги и тела (`OnboardingStepRequest`, правила — строгие типы, без приведения):

| `step` | Тело | Куда пишется |
|---|---|---|
| `name` | `{display_name: string}` | `learners.display_name` (после `DisplayName::validate`) |
| `age` | `{age_range: enum}` | `learners.age_range` |
| `goals` | `{goals: string[]}` (1–6, уникальные, из белого списка) | `learners.goals` |
| `level` | `{level: 'A1'…'C2', course_slug: string}` (slug должен совпадать с курсом уровня из `options.levels`) | `learners.level` через `LearnerSettingsService::update`; slug — `meta.twa_onboarding.course_slug` |
| `daily_goal` | `{daily_goal: int}` | `LearnerSettingsService::update(['daily_goal'])` (лимит Free, `daily_goal_before_limit`, `reconcileGoal`) |
| `reminder` | `{enabled: bool, time: 'HH:MM'\|null, write_access: 'granted'\|'denied'\|'not_asked'}` | `LearnerSettingsService::update(['reminder_time' => enabled ? time : null])`; `write_access` — только в событие |

Каждый PUT под блокировкой двигает `meta.twa_onboarding.step` на следующий шаг (порядок `name → age → goals → level → daily_goal → reminder → plan`), но **принимает любой шаг в любом порядке** (ученик вернулся «Назад» и поменял ответ). Идемпотентно: тот же PUT дважды — то же состояние, одно событие `onboarding.step_completed` на фактическую смену значения. Первый PUT ученика с `onboarding_state = new` пишет `onboarding.started {channel: mini_app}` (аналог `start()` бота).

`OnboardingStateResource`:

```json
{
  "completed": false,
  "step": "level",
  "values": {
    "display_name": "Азиз", "first_name": "Aziz",
    "age_range": "18_24", "goals": ["career", "travel"],
    "level": "B1", "course": {"slug": "english-b1", "title": "B1", "level_label": "B1", "stages_count": 20},
    "daily_goal": 20, "reminder": {"enabled": true, "time": "20:00"}
  },
  "options": {
    "age_ranges": ["lt14", "14_17", "18_24", "25_34", "35_44", "45p"],
    "goals": ["career", "study", "travel", "relocation", "communication", "media"],
    "levels": [{"level": "A1", "levels": ["A1", "A2"], "course_slug": "english-a1", "title": "A1–A2", "stages_count": 20}, {"level": "B1", "levels": ["B1"], "course_slug": "english-b1", "title": "B1", "stages_count": 20}],
    "daily_goals": [10, 20, 40], "daily_goal_max": null, "recommended_daily_goal": 20,
    "reminder_times": ["08:00", "12:30", "18:00", "20:00", "21:30"], "reminder_step_minutes": 15
  }
}
```

Подписи вариантов (возраст, цели, «Начинаю с нуля» и т. п.) — в i18n Mini App (ru/uz), не в API. В `/auth` и `/me` блок `learner` получает `display_name: string|null` и `onboarding: {completed: bool, step: string|null}` — из уже загруженной строки, **0 запросов**.

**Состояния экранов.** Прогресс 7 сегментов (O2–O8), «n / 7». «Продолжить» — `aria-disabled`, пока нет выбора; O4 — «Продолжить · выбрано N». `BackButton` на O2–O8 (на O1 — нет). Сохранение шага: кнопка в состоянии loading (три точки), ошибка сети — `InlineAlert` под кнопкой с «Повторить» (ответ не теряется — он в сторе). 409 на любом шаге → `replace` на `/today`. 403 `daily_goal_pro_required` (пробный кончился между загрузкой и нажатием) → откат выбора на `daily_goal_max`, подпись. O7: `requestWriteAccess` недоступен (клиент < 6.9) — переключатель скрыт. Вне Telegram (e2e-заглушка) — поле имени пустое.

**Крайние случаи.** Ученик завершил бот-онбординг, пока Mini App открыт на O4 → PUT даёт 409 → «Сегодня». Два устройства — блокировка строки, последний PUT побеждает, оба читают одно состояние. Имя из Telegram длиннее 32 графем или со ссылкой — поле подсвечено, «Продолжить» неактивна, подсказка. Ученик удалил аккаунт и вернулся (строка ожила) — `onboarding_state` прежний, онбординг не повторяется. Курс уровня пропал из каталога между O5 и O8 — `completeCourse` возьмёт запасной (общий курс уровня → курс по умолчанию), O8 покажет то, что вернул `complete`.

**Безопасность.** Личность — только из JWT. Белые списки на каждое значение; никаких новых PII сверх `display_name`, `age_range`, `goals`. Возраст и цели не уходят ни в один список других людей, ни в бота, ни в карточки. `display_name` до решения владельца не покидает своих экранов. Лимит `twa-onboarding` 30/мин.

**Не делаем.** Шаг языка и области в Mini App (есть F6 и карточка области). Упрощение бот-онбординга (отдельное решение позже: оставить в боте язык + область и отправлять в Mini App кнопкой). Пропуск онбординга кнопкой. Запись `write_access` в колонку (нет потребителя).

### 2.1a O5 «Пройти тест за 2 минуты» (тест уровня)

**Что есть.** `LevelQuizService` — тест недели **по одному курсу** (экзаменационный поток `exam_sessions`, одна зачётная попытка в неделю, пишет `level_quiz_results`). Для подбора уровня он не годится: нужен срез по пяти уровням и без записи результатов. `ExamQuestionBuilder::forWords(words, course, locale)` строит вопросы «слово → 4 перевода» по любому набору слов.

**Решение — самый простой честный вариант, отдельным выпуском.** 10 вопросов: по 2 активных слова из курса каждого уровня `A1, A2→(курс A1–A2), B1, B2, C1` (случайно, `ORDER BY random()`-выборка по `lesson_id in (…)` ограничена 2 строками на уровень — 5 коротких запросов по индексу `words.lesson_id` или один с оконной функцией; варианты — `forWords`). Ключ ответов — в `learners.meta.twa_onboarding.placement = {items: [{word_id, level, correct}], issued_at}` (не в `exam_sessions`: это не экзамен, ничего не пишется в прогресс). Ответы — одним запросом `{answers: [idx×10]}`; уровень = самый высокий уровень, на котором верно ≥ 1 из 2, при условии что все уровни ниже тоже ≥ 1 (иначе — последний «пройденный»; ни одного — A1). Результат возвращает `level` и `course_slug`, клиент подставляет выбор в O5 («примерный уровень — можно сменить»). Повторная сдача — новый набор. Ничего не трогает в `word_repetitions`, очках, серии.

API (docs/04 A.27): `POST /api/twa/onboarding/placement` → 201 `{questions: [{id, word, options: string[4]}], expires_at}` (≤ 8 операторов: auth + lock/запись черновика + слова 1–5 + варианты как в экзамене); `POST /api/twa/onboarding/placement/answers {answers}` → `{level, course_slug, correct_by_level: {A1: 2, …}}` (≤ 3). Лимит `throttle:twa-session-create` (как пробный тест). 409 `onboarding_completed`, 410 `placement_expired` (20 мин), 422.

**Объём M.** Делать после основного онбординга (§3, В6); до этого кнопка на O5 скрыта.

> **Реализация T14 (27.11.2026, architect) — уточнения к контракту (точный — docs/04 A.27):**
> - **Уровни и курсы.** Спрашивается уровень, у которого в каталоге есть **свой** курс (`recommendFromCatalog('general', L)->level === L`), плюс A2 из курса A1, когда своего нет (строка «A1–A2» экрана O5). Уровень, чья рекомендация — запасной курс другого уровня (B1 → курс A1 при пустом каталоге), **не спрашивается** и в подсчёте прозрачен: спрашивать слова A1 как «B1» нечестно. Меньше 4 вопросов — 422 `placement_unavailable`; `GET /onboarding` отдаёт `options.placement_available` (≥ 2 уровней со словами, 0 операторов) — по нему T15 показывает кнопку.
> - **Варианты — из того же курса одним оператором.** Вместо `forWords` (2–4 оператора на курс + слой чужих курсов) — новый `ExamQuestionBuilder::forWordsFromPool(words, pool, locale)`: на курс один `ORDER BY random() LIMIT 2·уровней + 20` — первые два слова на уровень спрашиваются, остальные — пул отвлекающих вариантов; правила экзамена (настоящие переводы, язык ответа, «никогда второй правильный») те же. Переводы всех слов теста — одной пачкой. Бюджет закреплён тестом: старт **8** (4 курса), ответы **3**, повтор **2**.
> - **Ревью 27.11.2026: `set_id`.** Старт отдаёт `{set_id, questions, expires_at}`, ответы приходят `{set_id, answers}`; под блокировкой `set_id` сверяется с черновиком, чужой (заменённый новым стартом) — 410 `placement_expired`. Без этого ответы старого набора, вставшие за блокировкой нового старта, засчитывались бы по словам нового. При завершении онбординга черновик удаляется из `meta`.
> - **Ключ и идемпотентность.** Черновик `{set_id, items: [{id, word_id, level, correct, n}], issued_at, expires_at}` пишется под `FOR NO KEY UPDATE` строки ученика (той же, что шаги онбординга — ключи `meta` не теряются, fork-тест на PostgreSQL). Ответы — `(int|null)[]` (`null` — пропуск); оцениваются один раз: ключ удаляется, на его месте `{result, answered_at}`; повтор (ретрай, вторая вкладка, другие ответы) отдаёт **тот же** результат — перебором индексов ключ не узнать; защита от скриптов — `twa-session-create` 10/мин. 410 — и по сроку, и когда теста не начинали (действие клиента одно: начать заново). ~~После `done` черновик остаётся в `meta`~~ — с ревью 27.11.2026 удаляется при завершении.
> - **Что не трогается** (тест-страж): `word_repetitions`, `training_sessions`, `exam_sessions`, `score_credits`, `learner_daily_stats`, `learners.level`, `active_course_id`, серия. Событий у теста нет.

### 2.2 F1 «Повторение»

**Что есть.** Экран тренировки уже сделан по киту: `FlashCard` с кнопками «Повторить / Знаю» (оценки 2 / 4 / 5, docs/06 §2), `SegmentProgress` раунда (у новичка 12 сегментов — спринт `6 новых × шаги + due`, docs/06 §13.2), транскрипция, аудио, перевод с примером по тапу. Вход — «Сегодня» → занятие (`daily_review`), напоминание бота → `?from=reminder`. Алгоритм кита «[1,2,4,7,14,30]» **не вводим**: интервалы считает SM-2 / FSRS.

**Решение — только вид.** Под карточкой — пять точек «сила памяти» и подпись «Память: 3 из 5 · повтор через 4 дня». Сила — производная от строки `word_repetitions`, без новой колонки и без запросов (строка уже в `CardPick::$repetition`):

| Сила | Правило (`MemoryStrength::of(state, interval_days, last_quality)`) | Стадия (`WordStage`) |
|---|---|---|
| 0 | `state = new` и ещё не оценивалось (`last_quality IS NULL`) | new |
| 1 | в минутных шагах (`new` / `learning` с оценкой) или `relearning` | new / learning |
| 2 | `review`, `interval_days < 3` (`learning.stages.fresh_review_days`, новый ключ, 3) | learning |
| 3 | `review`, `3 ≤ interval_days < known_from_interval_days` (7) | learning |
| 4 | `review`, `7 ≤ interval_days < mastered_from_interval_days` (21) | known |
| 5 | `review`, `interval_days ≥ 21` | mastered |

Класс — `App\Domain\Learning\Support\MemoryStrength` (чистая функция + SQL-выражение `CASE` с bindings для F2). Описание — docs/06 §13.3a. Пороги — те же, что у стадий: сила и стадия никогда не противоречат друг другу.

**API.** `GET /training/sessions/{id}/next` → `card.memory: {strength: 0..5, stage: 'new'|'learning'|'known'|'mastered', next_in_days: int|null}`; `next_in_days` — для `review`-карточки `max(0, ceil((next_review_at − now) / сутки))`, для карточки в шагах `null` (клиент пишет «повтор в этой сессии»). Это сила **до** оценки — ровно то, что показывает макет. Бюджет `/next` прежний (`TrainingCycleQueryBudgetTest`).

**Mini App.** Компонент `MemoryDots` (5 точек: ≤ 2 — `status/error`, иначе `muhr`; пустые — `sunken`), подпись `t-caption text-muted`. Показывается на карточке-флеш `daily_review` и `lesson`, прячется в `hard_review` / `lesson_review` (там не повторение). Остальное на экране не трогаем.

**Не делаем.** Отдельный экран/маршрут «Повторение» (это `daily_review`). Свой интервал кита. Точки на объективных упражнениях (там ответ скрыт).

### 2.3 F2 «Мой словарь»

**Что есть.** Прогресс по слову — `word_repetitions` (unique `(learner_id, word_id)`, индексы `(learner_id, next_review_at)`, `next_card_idx`, `in_steps_idx`, `hard_idx`, `(learner_id, created_at)`); слово — `words` (`word`, `translation`, `transcription`, `audio_path`, `is_active`; индексы `word` (точное), триграммы `lower(word)` / `lower(translation)` на PostgreSQL — под `/admin`); переводы по языку — `word_translations (word_id, locale)` unique, читать только через `WordTranslationResolver` (пачкой, 1 запрос); аудио — `WordAudioService::urlFor(Word)` (без диска). Экрана словаря нет; есть `PUT /words/{word}/note` (заметка).

**Решение.** Один эндпоинт `GET /api/twa/vocabulary`, сервис `App\Domain\Learning\Services\VocabularyService`. «Словарь» = строки ученика, оценённые хотя бы раз (`last_reviewed_at IS NOT NULL`), по активным словам. Фильтры: `all` · `weak` (сила ≤ 2) · `strong` (сила ≥ 4). Поиск по слову **и** переводу. Сортировка — по алфавиту слова, keyset-пагинация, страница 50.

Запросы (PostgreSQL; sqlite — те же формы):

```sql
-- 1. Страница (всегда). Ведёт индекс word_repetitions (learner_id, word_id) / PK: строк у ученика сотни–тысячи,
--    words — по PK, word_translations — по unique (word_id, locale). Поиск и фильтр силы — по уже отобранным строкам ученика,
--    поэтому НОВЫХ индексов не нужно и b-tree по words.word / триграммы не требуются (они бы и не помогли при '%q%').
SELECT wr.id, wr.word_id, wr.state, wr.interval_days, wr.next_review_at, wr.is_hard, wr.last_quality,
       w.word, w.transcription, w.audio_path, w.translation,
       CASE … END AS strength                      -- MemoryStrength::sqlCase() с bindings порогов
FROM word_repetitions wr
JOIN words w ON w.id = wr.word_id AND w.is_active = true
LEFT JOIN word_translations wt ON wt.word_id = w.id AND wt.locale = ?        -- только для поиска по переводу в uz
WHERE wr.learner_id = ? AND wr.last_reviewed_at IS NOT NULL
  [AND (wr.state <> 'review' OR wr.interval_days < ?)]                       -- weak
  [AND (wr.state = 'review' AND wr.interval_days >= ?)]                      -- strong
  [AND (lower(w.word) LIKE ? ESCAPE '\' OR lower(w.translation) LIKE ? ESCAPE '\' OR lower(wt.translation) LIKE ? ESCAPE '\')]
  [AND (lower(w.word), wr.id) > (?, ?)]                                      -- keyset: курсор предыдущей страницы
ORDER BY lower(w.word), wr.id
LIMIT 51;                                                                     -- 51 → есть ли следующая страница

-- 2. Счётчики чипов (только первая страница без курсора; не зависят от q — так в макете).
SELECT count(*) AS all_count,
       count(*) FILTER (WHERE wr.state <> 'review' OR wr.interval_days < ?) AS weak_count,
       count(*) FILTER (WHERE wr.state = 'review' AND wr.interval_days >= ?) AS strong_count
FROM word_repetitions wr JOIN words w ON w.id = wr.word_id AND w.is_active = true
WHERE wr.learner_id = ? AND wr.last_reviewed_at IS NOT NULL;
```

Переводы для показа — `WordTranslationResolver::map(words, locale)` (1 запрос на страницу; `wt` в запросе 1 — только фильтр поиска, правило docs/03 §2.21 о чтении через резолвер соблюдено). Аудио — `urlFor()` на каждую строку, без диска. **Бюджет: ≤ 4 оператора** на первую страницу (auth, страница, счётчики, переводы), ≤ 3 на следующие; закрепить `TwaVocabularyQueryBudgetTest` (сценарий: 120 слов, поиск, фильтр, вторая страница). На PostgreSQL снять `EXPLAIN (ANALYZE, BUFFERS)` для ученика с 5 000 строк (ожидание: Index Scan по `word_repetitions_learner_id_word_id_unique`, Nested Loop по PK `words`, сортировка ≤ 5 000 строк в памяти, единицы мс) — записать в docs/38 §7.

Параметры: `q` — 1–50 символов после `trim`, `%`, `_`, `\` экранируются, регистр — `lower()` с обеих сторон (`mb_strtolower` на сервере для bindings; на sqlite `lower()` складывает только ASCII — в тестах поиск кириллицы в верхнем регистре не совпадёт, это ограничение тестового драйвера, не кода: на PostgreSQL `lower()` юникодный; тест на регистр — латиницей, а кириллицу проверять на PostgreSQL); `filter` — `all|weak|strong` (по умолчанию `all`); `after` — курсор base64url от `{"w": "<lower word>", "id": <int>}`, ≤ 200 символов, невалидный → 422 `invalid_cursor`; `limit` — 1..50 (по умолчанию 50). Ответ `VocabularyResource`:

```json
{
  "total": 120, "counts": {"all": 120, "weak": 18, "strong": 64},
  "items": [{"word_id": 7, "word": "tea", "translation": "чай", "translation_fallback": false, "transcription": "/tiː/",
             "audio_url": "https://…/storage/audio/…", "strength": 5, "stage": "mastered", "next_in_days": 12, "is_hard": false}],
  "next_cursor": "eyJ3IjoidGVhIiwiaWQiOjcwfQ"
}
```

`counts` и `total` — только в ответе без `after` (далее `null`). Кэша нет (docs/19 §3 п. 9, 12): данные личные и меняются каждой оценкой, запрос дешёвый. Лимит — общий `twa-api`.

**Mini App.** Маршрут `/vocabulary` (`name: vocabulary`, `meta.tab: 'courses'`, `useBackButton → my-course`), стор `vocabulary` (страницы, курсор, `q` с debounce 300 мс, фильтр; смена `q`/фильтра сбрасывает курсор; при `q.length < 1` — список без поиска). Строка: слово + перевод, `MemoryDots`, `IconButton` аудио (`useTts` c `audio_url`, как на карточке). Подгрузка — кнопка «Показать ещё» / `useRevealInView`. Пустые состояния: словарь пуст — маскот `reading` + «Пройдите первый урок — слова появятся здесь»; поиск без результата — «Ничего не нашли». Вход — строка «Мой словарь» в `CourseView` / `MyCourseView` (без числа, чтобы не расходиться с `words_learned`). Скелет — 7 строк.

**Крайние случаи.** Слова своих колод — в словаре (это прогресс ученика). Слово деактивировано курсом — пропадает из списка и из счётчиков (как из `due_count`). Курсор от другого фильтра — корректен (keyset не зависит от фильтра), просто страница с того места. Перевод на uz отсутствует — `translation_fallback: true` (ru), поиск в uz ищет и по ru.

**Не делаем.** Сортировки «по силе / по дате» (не в ките). Редактирование и удаление из словаря. Экспорт.

### 2.4 F3 «Поделиться в сторис» и «Позвать друга на баттл»

**Что есть.** `POST /share/prepare` (`SharePreparer` → `savePreparedInlineMessage` → `WebApp.shareMessage`, SDK 8.0.2) с карточкой 1080×1080 JPEG (`AchievementCardRenderer`, диск `public`, каталог `share-cards/`, имя `<key>-<ru|uz>-<hash20>.jpg`, `publicUrl()` только при https, nginx отдаёт только такие имена, `achievements:prune-cards` чистит неиспользуемое 30 дней). Виды `ShareKind`: `achievement`, `invite`, `friend_invite`, `game_city`. Баттл возможен только с другом → «позвать друга на баттл» = приглашение в друзья `f_<code>`. Inline-режим бота (H5) не включён, `switchInlineQuery` не используем. Рефералы: вехи 3 / 6 друзей → дни Pro (`milestone_days`), **не баллы лиги**.

`WebApp.shareToStory(media_url, {text, widget_link})` — Mini Apps **7.8** (есть в SDK 8.0.2; проверка `isVersionAtLeast('7.8')`): `media_url` — публичный `http(s)`-адрес картинки (jpg/png) или видео (mp4), 9:16; `text` — подпись (SDK режет на 2048, по документации Telegram у обычных пользователей — до 200 символов); `widget_link` — **только у Telegram Premium** (у остальных ссылка в сторис не ставится — поэтому адрес бота рисуем **на картинке**, как в ките, и дублируем в `text`). Сверить на живом боте (как H1 в docs/36): показывается ли `widget_link` без Premium, качает ли Telegram картинку через Cloudflare, как выглядит 1080×1920 на iOS / Android / Desktop.

**Решение.**

- **Новый рисовальщик** `App\Domain\Engagement\Services\StoryCardRenderer` (GD, те же шрифты Noto Sans, тот же диск/каталог/`publicUrl`/версия настроек, что у `AchievementCardRenderer`; общие куски — вынести в `CardCanvas`-трейт или передать рендереру как зависимость): холст **1080×1920 JPEG**, фон чернила `#1A1A1F` (константы — `CardPalette`, добавить тёмный набор кита), печать-кольцо 600 px с артом семейства (`achievements.illustrations/<family>.png`, без файла — медаль), заголовок Unbounded-подобный **нельзя** (TTF нет) → Noto Sans Bold 84 (≤ 2 строк), описание Noto Sans 44 (≤ 3 строк), внизу «Muhrla · @<bot>». Имя файла `story_<key>-<locale>-<hash20>.jpg` — подходит под `FILE_PATTERN` и под правило nginx (`[a-z0-9_]{1,40}-(ru|uz)-[0-9a-f]{20}\.jpg`), чистится тем же `ShareCardPruner`. Личных данных на картинке нет: число дней серии — да, имени — нет. Тест-снимок по ключевым пикселям (как `AchievementCardSnapshotTest`), тест длины текстов ru/uz.
- **`ShareKind::Streak`** (`'streak'`, без subject): предмет — `learners.streak_current` ученика **на момент запроса** (`StreakService::current()` уже вызывался на «Сегодня»; здесь читаем колонку — 0 операторов), `0` → 404 `share_subject_not_found`. Спец: `CardSpec(key: 'streak_<N>', palette: 'streak', badge: N, title: «N дней подряд», description: «Учу английский в Muhrla — каждый день по M карточек» (M = `daily_goal`), label: '')`. Для `achievement` — те же правила предмета, что в `ShareMessageBuilder` (своё, открытое; чужое = 404). `invite` / `friend_invite` / `game_city` в сторис не нужны (422 `unknown_kind` на этом маршруте).
- **Эндпоинт** `POST /api/twa/share/story {kind: 'streak'|'achievement', subject?}` → `StoryShareResource {media_url, text, widget_link: {url, name}}` — `StoryShareService::prepare()`: рисует (или берёт файл по хэшу), `publicUrl()`; `null` (не https / диск не public) → 403 `share_unavailable`; `share.enabled = false` → 403; лимит — существующий `throttle:twa-share` (20/мин и 100/сутки; рендер — работа с диском). `text`: «N дней подряд в Muhrla · t.me/<bot>?start=ref_<code>» (≤ 200 символов, язык ученика), `widget_link.url` — та же реферальная ссылка, `name` — бренд. Событие `share.prepared {kind, via: story}`; `POST /share/events` принимает `story_sent` / `story_fallback {reason}`. **Бюджет: ≤ 3 оператора** (auth; для `achievement` — своя строка `achievements` по unique; для `streak` — 0) + диск. Кэш Redis не нужен: файл на диске и есть кэш (ключ — хэш содержимого), Bot API не вызывается.
- **Mini App.** `useTelegram().canShareToStory()` (`isVersionAtLeast('7.8')` и метод есть) и `shareToStory(mediaUrl, {text, widget_link}): 'opened'|'unsupported'|'failed'` (исключения SDK `WebAppMethodUnsupported` / `WebAppMediaUrlInvalid` → `failed`); колбэка результата у `shareToStory` нет — считаем «открыто». Composable `useStoryShare(kind, subject)`: `canShareToStory()` → `POST /share/story` → `shareToStory`; 403/404/429/503 или `unsupported` → запасной путь: существующий `useShare('achievement'|'invite')` (`shareMessage` с карточкой 1:1), далее `t.me/share/url`. Экран `/share/story?kind=&subject=` (`ShareStoryView`, без `meta.tab` — таб-бара нет, как в макете F3; `BackButton` → откуда пришли): превью рисуется вёрсткой по композиции `StoryCardRenderer` из уже загруженных данных, без запроса (решение после ревью 27.11: запрос `POST /share/story` уходит только по нажатию; подпись «Примерно так будет выглядеть сторис»), текст под ней — про **дни Pro за друзей** (из `GET /referrals`: `milestone_days`, `next.friends`), кнопки «Поделиться в сторис» (Primary) и «Позвать друга на баттл» (Secondary → `useShare('friend_invite', null, {link: friends.invite_link, text})`). Входы: `AchievementsView` — у полученного достижения «В сторис» (рядом с «Поделиться»); F4 «Серия спасена» — ссылка «Поделиться серией». Хаптик `impactOccurred('light')` на нажатии.

**Крайние случаи.** `APP_URL` без https (стенд) — кнопка ведёт в запасной путь молча (событие `story_fallback {reason: unavailable}`). Клиент < 7.8 — кнопка «В сторис» не показывается, остаётся «Поделиться». Серия 0 — вход с F4 не предлагается, 404 с достижений невозможен (кнопка только у открытых). Telegram не смог скачать картинку — узнать нельзя (колбэка нет); проверяется на живом боте (H1-подобная проверка). Повторные нажатия — файл уже на диске, ответ мгновенный, лимит 20/мин всё равно считает.

**Не делаем.** «+100 баллов лиги за нового ученика» (§3, В4): меняет правила очков docs/32 и антифрод рефералов; награда остаётся — дни Pro за вехи. `switchInlineQuery` (inline-режим бота выключен). Видео-сторис. Сторис из бота.

> **Реализация T11 (27.11.2026, architect) — как сделано (точный контракт — docs/04 A.26):**
> - **Шрифты — Noto Sans.** Кит требует Unbounded/Onest, GD нужен TTF; в репозитории только woff2, а пакеты `@fontsource/unbounded` и `@fontsource/onest` (проверены `npm pack`, зависимость не добавлялась) содержат только woff/woff2 нарезанные по подмножествам (latin, cyrillic …) — собрать из них TTF без fonttools нельзя. Оставлен Noto Sans (Bold 84 заголовок, Regular 44 описание, 40 строка бота); `TEMPLATE_VERSION` карточки 1:1 не менялся. Если TTF появятся — заменить в `config/achievements.php` и поднять версии обоих шаблонов.
> - **Общий код** карточек — трейт `App\Domain\Engagement\Support\CardCanvas` (шрифты, холст, JPEG, текст/перенос/обрезка, PNG, запись через временное имя, `markUsed`); `AchievementCardRenderer::renderCached()` — общее хранение (имя по HMAC содержимого + версии шаблона, кэш по файлу). Рендер карточки 1:1 после рефакторинга байт-в-байт прежний (сверено md5).
> - **Макет 1080×1920:** чернила `#1a1a1f` (`--app-inverse`), кольцо-печать Ø 600 с центром на 700 px (muhr тёмной пары `#ff6b42`, внутри бумага `#fffdf8` и пунктирное кольцо), иллюстрация семейства в коробке 370 px или значок в `--app-accent`; заголовок с 1130 px, описание, строка «**Muhrla** · @bot» (бренд — muhr, адрес — бумага); верх и низ (UI Telegram) пусты. Палитра — `CardPalette::STORY_*`, тест сверяет с `style.css` (светлая и тёмная темы).
> - **Текст сторис** — фраза без ссылки из `lang/*/share.php` (`story.streak.text`, `story.achievement.text`) + « · » + реферальная ссылка, ≤ 200 символов (режется фраза, не ссылка). «Карточек» — `trans_choice('share.story.cards')`, «дней» — `share.story.days`.
> - **События клиента:** `story_sent` → `share.sent {kind, via: story}`, `story_fallback {reason: old_client | unavailable | error}` → `share.fallback {…, via: story}` (без новых `ProductEventName`).
> - **Бюджет:** серия — 2 оператора (auth + событие), достижение — 3; `streak_current` читается из строки auth. Файл на диске — один на содержимое и язык: два ученика с одной серией делят его.
> - **Не проверено:** живой Telegram (iOS/Android/Desktop, Premium и без, скачивание картинки через Cloudflare) — как было записано в §2.4 и рисках.

### 2.5 F4 «Серия спасена» / «Серия прервалась»

**Что есть.** Заморозки — Pro (`streak.freeze`), инвентарь `learners.streak_freezes` (+1 в понедельник, до 2), тратятся автоматически при чтении `StreakService::current()` на «Сегодня», `/finish` и в напоминаниях — по одной на каждый пропущенный день, **все или ничего** (docs/06 §6a). Сгорание фиксируется сразу: `burn()` пишет `streak_current = 0`. `/today.streak` уже отдаёт `freezes`, `frozen_yesterday`, `held_by`, `current`, `best`. Факты дней — `learner_daily_stats` (`goal_met_at` / `game_held_at` / `frozen_at`), `TodayService` читает строки вчера и сегодня **одним оператором** и отдаёт их в `current()` как `$dayRows`. У Free — `freezes = 0` и пейвол `streak_freeze`. «Серия прервалась» сервер сейчас не сообщает: после сгорания `current = 0` неотличим от «серии не было».

**Решение.**

- **`/today.streak` += два поля, 0 новых операторов:**
  - `week: [{date: 'Y-m-d', held_by: 'goal'|'game'|'freeze'|null, is_today: bool}]` — 7 дней локальной недели ученика (понедельник — воскресенье, `LearnerClock`); будущие дни — `held_by: null`. Оператор строк `learner_daily_stats` в `TodayService` расширяется с «вчера, сегодня» до «понедельник недели … сегодня» (≤ 7 строк по unique `(learner_id, date)`), те же строки идут в `current()`. Бюджет `/today` и `TodayStreakQueriesTest` не меняются (проверить тестом).
  - `lost: {days: int, on: 'Y-m-d'} | null` — серия, сгоревшая при последнем сгорании: `days` — сколько было, `on` — локальный день, когда это заметили. Хранится в **двух новых колонках** `learners.streak_lost_days smallint null`, `streak_lost_on date null` (миграция `2026_12_02_100002_add_streak_lost_to_learners_table`, `down()` снимает). Пишет `StreakService::burn()` **в том же** условном `UPDATE`, что `streak_current = 0` (новых операторов нет; отдельно от `meta`, чтобы оптимистичный UPDATE без блокировки не затирал чужие ключи json). Очищает `project()` в своём UPDATE, когда новая серия получает первый засчитанный день (`current` становится 1) — так «прервалась» показывается до первого нового дня и исчезает сама. Меняется `StreakState::toArray()`, `TodayResource`, `TwaContractTest`.
- **Mini App.** Экран `/streak/saved` (`StreakSavedView`, без `meta.tab`, `BackButton`/«Продолжить серию» → `/today`): маскот `freeze`, заголовок, календарь недели из `week` (done — печать-галочка `muhr`, freeze — снежинка на `sunken`, сегодня — кольцо `border-strong` с числом, будущее — пунктир), `StampMark` «N ДНЕЙ» (`current`), «Осталось заморозок: `freezes`», «Поделиться серией» → F3. Вариант **«Серия прервалась»** (`lost`): маскот `thinking`, «Было N дней», у Free — карточка «Заморозка сохранила бы серию — в Pro» → `PaywallSheet reason=streak_freeze` (существующий), у Pro — «Заморозок не хватило: пропущено дней больше, чем было заморозок». Кнопка «Начать заново» → `/today`.
- **Показ один раз.** Composable `useStreakMoments(todayStore)`: после загрузки `/today` — если `streak.frozen_yesterday && !hasSeen('streak_saved:<вчера>')` → `markSeen` и `router.push('/streak/saved')`; иначе если `streak.lost && !hasSeen('streak_lost:<lost.on>')` → то же с вариантом `lost`. Вызывается из `App.vue` (watch на стор «Сегодня»), **не из `TodayView.vue`** (файл в работе у другого исполнителя). Не показывается, если открыт deep link (`start_param`) или идёт сессия (`open_session_id`, иммерсивный маршрут). «Заморозка» двух дней подряд — тот же экран, в тексте «2 дня».

**Крайние случаи.** Заморозка потрачена вечером вчера другим устройством — сегодня `frozen_yesterday = true`, экран один раз на этом устройстве (`localStorage` локален — приемлемо). Переезд пояса (docs/06 §6a) — та же «мелкая аномалия», экран не навредит. `lost` у новичка — никогда (`burn` не трогает серию 0). Серия сгорела и в тот же день ученик уже выполнил цель — `lost` очищен `project()`, экрана нет (честно: серия уже новая). `prefers-reduced-motion` — печать без тряски (общее правило кита).

**Не делаем.** Покупку заморозки / ручную трату (нет в продукте). Пуш о сгорании (бот и так шлёт напоминания). Хранение истории сгораний.

### 2.6 F5 «Нет сети»

**Что есть.** `api/client.ts` различает `kind: 'network'` (fetch упал, таймаут), экраны показывают `InlineAlert` «нет сети» с «Повторить» (локализация по `kind`). Service worker отсутствует (Mini App — SPA под `/twa/`). Тренировка: карточку выдаёт сервер под блокировкой (`CardPicker`), ответ `POST /review` защищён от повтора (`serve_token`, docs/06 §5.2) — повтор того же ответа безопасен. Прогресс урока хранится на сервере (сессия, оценки); «сохранено 4 из 8» из макета — это уже факт сервера.

**Решение — только клиент, без изменения механики.**

- `composables/useOnline.ts` — `navigator.onLine` + события `online` / `offline` (реактивный `isOnline`), плюс «последний запрос упал по сети» из `api/client.ts` (хук `onNetworkError`). 
- `components/OfflineScreen.vue` (полноэкранный, маскот `thinking`, «Нет соединения», подпись, чип прогресса, «Повторить» Primary). Показывается **поверх** приложения из `App.vue`, когда `!isOnline` **или** главный запрос текущего экрана упал по сети и экран не имеет своего состояния ошибки (у иммерсивных есть — тренировка, экзамен, баттл, забег: у них остаётся свой `InlineAlert`, но при `!isOnline` показываем F5 с чипом). Возврат сети → автоматически повтор последнего неудачного запроса (колбэк экрана `retry()`), экран закрывается. Чип: на тренировке — «Урок N · сохранено done из total» из стора `training.progress`; иначе «Прогресс сохранён на сервере».
- **Незавершённый ответ тренировки.** Стор `training`: при `network`-ошибке на `/review` ответ (`word_id`, `serve_token`, `quality` / `answer`, `at`) остаётся в сторе и дублируется в `sessionStorage` `training:pending-review:<sessionId>`; «Повторить» и событие `online` отправляют его снова; после 200 — удаляется. Если первый запрос всё-таки дошёл, повтор получает 409 `card_not_due` («уже оценена») — стор и сейчас на этот код молча грузит `/next` (`stores/training.ts`, ветка `card_not_due`); 410 `session_closed` — ответ удаляется, экран ведёт на итог. При открытии тренировки с сохранённым ожидающим ответом — сначала он. Больше одного ответа не копится (следующая карточка не выдаётся, пока не принят ответ).
- Кнопка «Повторять слова офлайн» из макета — **не показываем** (см. ниже и §3, В7).

**Честно про «повторять слова офлайн».** Это отдельный проект: офлайн-очередь ответов конфликтует с серверным выбором карточек под блокировкой, спринтом, квотой Free, learn-ahead-билетами и начислением очков «одна попытка дня на лексему» (docs/32). Любая реализация либо дублирует пикер на клиенте, либо ломает идемпотентность и очки. Урезанный честный вариант, если владелец захочет: **«Листать словарь офлайн»** — последняя загруженная страница F2 (≤ 50 слов) хранится в `localStorage`, экран листает карточки слово → перевод без кнопок оценки и без записи. Объём S, но ценность мала; по умолчанию не делаем.

**Крайние случаи.** `navigator.onLine` врёт (true без интернета) — ловим по падению запроса. Сеть вернулась, а токен JWT истёк — `client.ts` сам переавторизуется (уже так). Ответ дошёл до сервера, но ответ не вернулся — повтор с тем же `serve_token` принимается идемпотентно / даёт «карточка уже оценена» → переход к `/next`. `sessionStorage` очищается при закрытии Mini App — это нормально: сервер не выдал следующую карточку, ученик просто ответит снова.

**Не делаем.** Service worker и кэш ассетов (Telegram WebView, отдельная тема). Офлайн-очередь ответов. Офлайн-урок.

---

## 3. Решения владельца

Список короткий: везде есть вариант по умолчанию, работа не ждёт ответа.

| № | Вопрос | Рекомендация | По умолчанию (если нет ответа) |
|---|---|---|---|
| В1 | Имя, которое ученик вводит на O2: показывать его **только на своих экранах** (приветствие, O8, профиль) или **и другим** (лига, друзья, баттлы, сообщения бота)? Показ чужим — это текст, который видят незнакомые люди (правила как у имени в кубке уже есть). | Сначала только свои экраны; через выпуск — всем, одной задачей (`first_name` → `display_name ?? first_name` в 6 местах API). | Только свои экраны |
| В2 | O5: строка «Фразы — готовые выражения» как стартовый курс. По docs/35 курс фраз не бывает основным. | Не показывать; фразы остаются разделом на «Сегодня». | Не показываем |
| В3 | O6: варианты кита 5 / 10 / 20 / 30 слов против наших 10 / 20 / 40 **карточек** в день (Free ≤ 10). Добавлять 5 и 30 — менять `daily_goal_options` и тексты настроек. | Оставить 10 / 20 / 40 с подписями кита, «рекомендуем 20». | 10 / 20 / 40, рекомендация 20 |
| В4 | F3: «+100 баллов лиги за каждого нового ученика» — меняет правила очков (docs/32) и антифрод рефералов. | Не делать; текст — «за друзей — дни Pro» (вехи 3 / 6). | Не делаем |
| В5 | Возраст: для «до 14» / «14–17» что-то ограничивать (баттлы, лига, чаты кубка)? | Пока только хранить; ограничения — отдельным решением с юристом. | Ничего не ограничиваем |
| В6 | Тест уровня на O5 (10 вопросов, 2 на уровень, ничего не пишет в прогресс): делать в этом заходе последним выпуском или отложить? | Делать последним выпуском (M). До него кнопка скрыта. | Делаем последним |
| В7 | F5: кнопка «Повторять слова офлайн». Полный офлайн — не делаем (§2.6). Урезанный «листать словарь офлайн без оценки» — нужен? | Не делать: мало ценности, лишняя сущность. | Не делаем, кнопки нет |

---

## 4. Порядок выпусков и задачи

Оценки: S — до половины дня исполнителя, M — 1–2 дня, L — 3–5 дней. «Параллельно» — задачи без общих файлов, их можно отдавать одновременно
разным исполнителям. Формат задач — docs/09 §4.4: каждая — отдельный промпт, после неё — проверки и коммит.

| Выпуск | Что | Задачи | Параллельно |
|---|---|---|---|
| **0** | Docs до кода | T0 | — |
| **1** | Онбординг O1–O8 (без теста) | T1 → T2 → T3 (сервер); T4 → T5 (Mini App) | T1–T3 ∥ T4 (Mini App начинает с моками по контракту §2.1) |
| **2** | F1 + F2 | T6 (сервер: сила памяти, `card.memory`, `/vocabulary`); T7 (Mini App F1), T8 (Mini App F2) | T6 ∥ T4/T5; T7 ∥ T8 |
| **3** | F4 | T9 (сервер) → T10 (Mini App) | T9 ∥ T8 |
| **4** | F3 + F5 | T11 (сервер сторис) → T12 (Mini App F3); T13 (Mini App F5) | T11 ∥ T10; T13 ∥ T12 |
| **5** | Тест уровня (В6) | T14 (сервер) → T15 (Mini App) | T14 ∥ T13 |
| после | Мелочи: убрать подсказку «завершите настройку в боте» на «Сегодня»; В1 (имя всем); ревью всего раздела | T16 | — |

Почему так: онбординг — самый большой и самый ценный (аудит кита, п. 1), его серверная часть не зависит от остального, Mini App-часть можно
начать по контракту; F1/F2 делят `MemoryStrength`; F4 даёт вход в F3; F3 требует проверки на живом боте — ставим позже, чтобы проверка не
блокировала остальное; F5 — чисто клиентская, закрывает выпуск.

---

### T0 — Docs до кода (координатор, S)

Правки: docs/03 §2.17 (колонки `display_name`, `age_range`, `goals`, `streak_lost_days`, `streak_lost_on`, с пометкой «пишет только …»); docs/04 — новые разделы
A.24 (`/onboarding*`), A.24a (placement), A.25 (`/vocabulary`), A.26 (`/share/story`), правки A.1/A.2 (`display_name`, `onboarding`), A.2a (`streak.week`, `streak.lost`),
A.3 (`card.memory`); docs/05 §3 (маршруты `/onboarding/:step`, `/vocabulary`, `/streak/saved`, `/share/story`; F5); docs/06 §13.3a (сила памяти); docs/07 §6 (`twa-onboarding`),
§10 (`display_name`, `age_range`, `goals` — что и кому не показывается); docs/13 §7.4 (карточка 9:16); docs/14 §4.0 (отступления: O5 без «Фразы», O6 — 10/20/40, F3 — без «+100»).
DoD: разделы согласованы с этим документом; ссылки на §2.x отсюда.

### T1 — Миграция и модель (coder, S)

**Контекст:** §2.1, §2.5; docs/03 §2.17; `database/migrations/2026_11_25_100003_add_daily_goal_before_limit_to_learners_table.php` (образец).
**Файлы:** две миграции (`…100001_add_profile_fields_to_learners_table`, `…100002_add_streak_lost_to_learners_table`), `app/Models/Learner.php` (`$fillable`, `casts`: `goals => array`, `streak_lost_on => date`), `database/factories/LearnerFactory.php`, `tests/Feature/DatabaseSchemaTest.php`.
**Сделать:** колонки из §2.1 и §2.5, `down()` — `dropColumn`. Без индексов. Фабрика: состояния `withDisplayName()`, `withTwaOnboarding(string $step)`.
**DoD / тесты:** `DatabaseSchemaTest` — колонки есть, откат чистый (обе миграции), sqlite и PostgreSQL (`json` / `jsonb`). pint, phpstan.
**Не трогать:** `OnboardingService`, резолверы, Mini App.

### T2 — Сервис и API онбординга Mini App (coder; ревью architect, M)

**Контекст:** §2.1 целиком; `app/Domain/Learners/Services/OnboardingService.php` (`completeCourse`, `fillSkippedGoal`, `lock`), `LearnerSettingsService::update` (цель дня, напоминание, уровень), `App\Domain\Cup\Support\DisplayName`, `CourseCatalogService::recommend/published`, `app/Http/Controllers/Api/Twa/SettingsController.php` (образец контроллера с ошибками), `ApiErrorResource`, `routes/api.php` (`twa`), `AppServiceProvider` (лимитеры `twa-*`).
**Файлы (новые):** `app/Domain/Learners/Services/TwaOnboardingService.php`, `app/Domain/Learners/DTO/TwaOnboardingState.php`, `app/Domain/Learners/Support/OnboardingChoices.php` (белые списки возраста и целей), `app/Http/Requests/Twa/OnboardingStepRequest.php`, `app/Http/Controllers/Api/Twa/OnboardingController.php`, `app/Http/Resources/Twa/OnboardingStateResource.php`, тесты `tests/Feature/TwaOnboardingTest.php`, `tests/Feature/TwaOnboardingQueryBudgetTest.php`. **Правки:** `OnboardingService` (+ `completeExternally(Learner, string $channel)` — вынести тело завершения из `apply()` в приватный метод и вызывать из обоих мест), `CatalogCourse` (+ `stagesCount`, кэш `catalog:courses:published:v1 → v2`), `AuthTokenResource`, `MeResource` (+ `display_name`, `onboarding`), `routes/api.php`, `AppServiceProvider` (`twa-onboarding` 30/мин), `ProductEventName` — без новых case (payload `channel`).
**Сделать:** ровно контракт §2.1 (таблица методов, тела шагов, Resource). `level` / `daily_goal` / `reminder` — через `LearnerSettingsService::update` (исключения `DailyGoalLimitException` → 403 `daily_goal_pro_required`, `InvalidSettingException` → 422). `name` / `age` / `goals` — своя транзакция под `FOR NO KEY UPDATE` (как в `LearnerSettingsService`). Любой шаг после `done` → 409. `complete` без `level` → 422 `onboarding_incomplete`. Ошибка `display_name_invalid {reason}` — причины `DisplayName::REASON_*`.
**DoD / тесты (Pest):** 200 каждого шага и форма Resource (`TwaContractTest` дополнить); 422 на каждое невалидное значение (пустые цели, 7 целей, повтор, неизвестный возраст, slug не того уровня, время не по четверти часа); имя: ссылка / `@` / zalgo / 33 графемы → 422 с причиной, обрезка пробелов; 403 цель 20 у Free без пробного; 409 после `done` (через бот-онбординг); `complete`: `done`, `onboarding_completed_at`, курс = `recommend('general', level)`, запасные варианты как в боте, `next_reminder_at` пересчитан, события `onboarding.started/step_completed/completed {channel: mini_app}` и `course.selected`; повторный `complete` — 200 без второго события; первый PUT пишет `onboarding.started` один раз; ученик посреди бот-онбординга (`state = level`) проходит Mini App до `done`, после чего кнопка бота отвечает `staleStep` (`OnboardingCallbackHandlerTest`-подобный сценарий); пассивный ученик из группы; восстановленный после удаления; `/auth` и `/me` отдают `display_name` и `onboarding` **без новых операторов** (`QueryLog::during`, сравнить с текущим числом в `TwaTodayMeQueryBudgetTest`/`TwaAuthTest`); бюджеты `GET` ≤ 3, `PUT` ≤ 5, `complete` ≤ 7 (точные числа закрепить); `stages_count` в `/courses` и смена версии кэша; гонка двух PUT одного шага (PostgreSQL, `ForkSafe`) — одно состояние, нет 500. pint, phpstan level 8.
**Не трогать:** бот-шаги `OnboardingService::apply()` (кроме выноса общего метода), `LearnerRegistrar`, `TodayView.vue`.

### T3 — Тексты и события (coder, S)

**Контекст:** §2.1; `lang/ru|uz/onboarding.php` (бот не меняется), `docs/I18N_UZ_REVIEW.md`.
**Сделать:** uz/ru строки сервера нужны только для ошибок (`error.code.*` у клиента — см. T4) — на сервере новых lang-строк нет, кроме `share.php` (T11). Добавить в отчёт `/admin` «Онбординг» разрез по `channel` (если отчёт есть; иначе пропустить). Проверить `RetentionReportService` на новый `channel` в payload (не ломается).
**DoD:** тесты отчётов зелёные. Можно слить с T2.

### T4 — Mini App: экраны O1–O8 (coder, L)

**Контекст:** §2.1, кит `02-onboarding/O1…O8.png`, `docs/ONBOARDING.md`, `docs/TELEGRAM.md`; docs/14 §4.0; компоненты `SegmentProgress`, `MascotArt`, `TopicArt`, `ToggleSwitch`, `QuizOption`/`.option`, `.field`, `btn-primary/secondary/ghost`, `useBackButton`, `seenFlag`, `useI18n`; `stores/settings.ts` и `composables/useLocaleSwitch.ts` (образец `saveSetting`); `api/index.ts`, `api/types.ts`; `router/index.ts`, `router/startParam.ts`; `App.vue`.
**Файлы (новые):** `api/onboardingTypes.ts`, `stores/onboarding.ts`, `views/OnboardingView.vue` (один экран, шаг — параметр маршрута `/onboarding/:step?`), `components/onboarding/{WelcomeStep,NameStep,AgeStep,GoalsStep,LevelStep,DailyGoalStep,ReminderStep,PlanStep}.vue`, `components/onboarding/SelectableTile.vue`, `composables/useOnboardingGuard.ts`, тесты `views/__tests__/OnboardingView.spec.ts`, `stores/__tests__/onboarding.spec.ts`, `router/__tests__/onboardingGuard.spec.ts`. **Правки:** `router/index.ts` (маршрут без `meta.tab`), `App.vue` (страж), `composables/useTelegram.ts` (+ `requestWriteAccess(): Promise<'granted'|'denied'|'unsupported'>`, ловить `WebAppMethodUnsupported` / `WebAppWriteAccessRequested`), `stores/auth.ts` (поля `display_name`, `onboarding`), `i18n/ru.ts`, `uz.ts`, `i18n/errors.ts` (`onboarding_completed`, `onboarding_incomplete`, `display_name_invalid`), `views/ProfileView.vue` и `TodayView.vue` — **имя из `display_name ?? first_name` — отдельной мелкой задачей T16, не здесь**.
**Сделать:** 8 шагов по макету (прогресс 7 сегментов, подписи «n / 7»); O1 — маскот `hello` и 6 картинок тем вокруг пунктирного кольца `muhr` (выбрать из `topics/index.json`), «Начать» → `/onboarding/name`; O2 — поле с подсказкой `initDataUnsafe.user.first_name`, строка «Взяли из Telegram — можно изменить», клиентская проверка длины 1–32 (серверная — главная); O3 — 6 плиток (одна); O4 — 6 плиток с картинками тем (несколько), счётчик в кнопке; O5 — строки уровней из `options.levels` (чип уровня слева, радио справа), кнопка «Начать с B1», текстовая кнопка «Пройти тест за 2 минуты» — **скрыта** до T15; O6 — 3 строки с подписями и чипом «рекомендуем»; O7 — 6 плиток времени («Своё» — `<input type="time" step="900">`), переключатель → `requestWriteAccess`, отказ → Off + подпись, «Не напоминать» (ghost) → `enabled: false`; O8 — сводка (курс · N модулей, цель, напоминание, зачем), «Начать первый урок» → `complete` → `markSeen(INTRO_KEY)` → `replace('/today?from=onboarding')`. Каждый шаг — `PUT` сразу; `BackButton` — предыдущий шаг (значения в сторе и на сервере). Страж (§2.1 «Когда показывать»). Resume: `auth.learner.onboarding.step`.
**DoD / тесты (vitest):** страж: `onboarding_completed=false` + `/today` → `/onboarding/<step>`, `start_param` на баттл → не перехватывает, `completed=true` → ничего; каждый шаг: «Продолжить» неактивна без выбора, активна с выбором, PUT с правильным телом, loading, ошибка сети → `InlineAlert` + повтор без потери выбора, 409 → `/today`; O2 — подсказка из Telegram, 422 `display_name_invalid` → подсветка; O4 — счётчик; O5 — список из `options.levels`, «Фразы» нет; O6 — замок при `daily_goal_max`, 403 → пейвол `daily_goal`; O7 — `requestWriteAccess` granted/denied/unsupported, «Своё»; O8 — тексты сводки, `complete`, переход; resume с шага `level`; кириллицы вне `i18n` нет; `vue-tsc`, `npm run build`, размер основного чанка — отдельный чанк маршрута (lazy import).
**Не трогать:** `TodayView.vue` (в работе), `SettingsView.vue`, бот.

### T5 — Mini App: онбординг — скриншоты и доводка (coder, S)

**Контекст:** T4, e2e-скрипты (`game.mjs --only shots`-подобные), 390×844 и 320 px, светлая и тёмная.
**Сделать:** скриншоты O1–O8 в обеих темах; сверка с PNG кита; правки отступов; uz-строки — в `docs/I18N_UZ_REVIEW.md`.
**DoD:** скриншоты у координатора; vitest зелёный.

### T6 — Сервер: сила памяти, `card.memory`, `GET /vocabulary` (coder; ревью architect по SQL, M)

**Контекст:** §2.2, §2.3; `WordStage`, `config/learning.php` (`stages`), `TrainingNextResource`, `CardPick`, `WordTranslationResolver::map`, `WordAudioService::urlFor`, `WordNoteController` (образец), `tests/Support/QueryLog.php`, `TwaTodayMeQueryBudgetTest` (образец бюджета), docs/38 (как снимать EXPLAIN).
**Файлы (новые):** `app/Domain/Learning/Support/MemoryStrength.php`, `app/Domain/Learning/Services/VocabularyService.php`, `app/Domain/Learning/DTO/VocabularyPage.php`, `app/Http/Requests/Twa/VocabularyRequest.php`, `app/Http/Controllers/Api/Twa/VocabularyController.php`, `app/Http/Resources/Twa/VocabularyResource.php`, тесты `tests/Unit/MemoryStrengthTest.php`, `tests/Feature/TwaVocabularyTest.php`, `tests/Feature/TwaVocabularyQueryBudgetTest.php`. **Правки:** `config/learning.php` (+ `stages.fresh_review_days` = 3, env `LEARNING_STAGE_FRESH_REVIEW_DAYS`), `TrainingNextResource` (+ `memory`), `routes/api.php`, `TwaContractTest`.
**Сделать:** таблицу силы §2.2 (функция и `sqlCase(): array{sql, bindings}` — `DB::raw` только с bindings); запросы §2.3 буквально (keyset `(lower(w.word), wr.id) > (?, ?)` — строковое сравнение кортежей есть и в PostgreSQL, и в sqlite ≥ 3.15); экранирование `LIKE`; курсор base64url + проверка; `counts` только без курсора; переводы через резолвер; `audio_url`.
**DoD / тесты:** `MemoryStrengthTest` — все 6 значений и границы порогов, согласие с `WordStage`; `TwaVocabularyTest`: пустой словарь, только оценённые строки, неактивное слово не показывается, фильтры weak/strong совпадают с силой, поиск по слову / ru-переводу / uz-переводу с `translation_fallback`, экранирование `%` и `_`, регистр, курсор (вторая страница без дублей и пропусков, 51-я строка → `next_cursor`), невалидный курсор 422, `limit` 0/51 → 422, слова чужого ученика не видны; бюджет: первая страница ≤ 4, следующая ≤ 3, поиск ≤ 4 — **точные числа**; `card.memory` в `/next` для новой, в шагах, review (значения и `next_in_days`), бюджет `/next` прежний (`TrainingCycleQueryBudgetTest`). На PostgreSQL — `EXPLAIN (ANALYZE, BUFFERS)` на 5 000 строк ученика, записать в docs/38 §7. pint, phpstan.
**Не трогать:** `CardPicker`, `ReviewSubmissionService`, схемы SM-2/FSRS, индексы.

### T7 — Mini App: F1 точки памяти (coder, S)

**Контекст:** §2.2, кит `F1-povtorenie.png`; `components/FlashCard.vue`, `views/TrainingView.vue`, `api/types.ts`.
**Файлы:** новый `components/ui/MemoryDots.vue`; правки `FlashCard.vue` (слот под карточкой), `api/types.ts` (`card.memory`), i18n (`card.memory.label`, `card.memory.next_days` с plural, `card.memory.this_session`), тест `components/__tests__/MemoryDots.spec.ts`, дополнение `FlashCard.spec.ts`.
**DoD:** точки и подпись по `memory`; цвета ≤ 2 — `danger`, иначе `muhr`; нет блока без `memory` (старый сервер) и в `hard_review`/`lesson_review`; vitest, vue-tsc.
**Не трогать:** оценки, порядок кнопок, `training` стор.

### T8 — Mini App: F2 «Мой словарь» (coder, M)

**Контекст:** §2.3, кит `F2-moy-slovar.png`; `ListRow/ListGroup`, `AppChip` (32 px как фильтр), `.field` поиск, `IconButton`, `useTts`/`remoteAudio`, `MemoryDots` (T7), `MascotArt reading`, `useRevealInView`, стор `courses` (образец кэша), `views/CourseView.vue` / `MyCourseView.vue` (вход).
**Файлы (новые):** `api/vocabularyTypes.ts`, `stores/vocabulary.ts`, `views/VocabularyView.vue`, тесты `views/__tests__/VocabularyView.spec.ts`, `stores/__tests__/vocabulary.spec.ts`. **Правки:** `router/index.ts` (`/vocabulary`, `tab: courses`), `api/index.ts` (`vocabulary.list`), `CourseView.vue` / `MyCourseView.vue` (строка «Мой словарь»), i18n.
**DoD / тесты:** заголовок «Мой словарь · N слов» (из `total`), поиск с debounce (один запрос на серию ввода), чипы с числами, смена фильтра сбрасывает курсор, «Показать ещё» грузит `after`, пустые состояния (словарь пуст / поиск пуст), аудио по `audio_url`, скелет, ошибка сети → `InlineAlert` + повтор, `BackButton` → курс; vitest, vue-tsc.
**Не трогать:** `TodayView.vue`.

### T9 — Сервер: `streak.week`, `streak.lost` (coder; ревью architect, S/M)

**Контекст:** §2.5; `StreakService` (`current`, `burn`, `project`), `StreakState`, `TodayService` (чтение строк дней, `$dayRows`), `TodayResource`, `TodayStreakQueriesTest`, `StreakServiceTest`, `LearnerClock`, миграция T1 (колонки `streak_lost_*`).
**Сделать:** расширить оператор строк дней до понедельника локальной недели (те же строки в `current()` и `handOutWeeklyFreeze`); `StreakState` += `week`, `lost`; `burn()` пишет `streak_lost_days = <current до сгорания>`, `streak_lost_on = <сегодня локально>` в том же `UPDATE`; `project()` очищает их в своём `UPDATE`, когда серия после сгорания получает первый засчитанный день; `TodayResource` и `TwaContractTest`.
**DoD / тесты:** неделя из 7 дней с верными `held_by` (цель / игра / заморозка / null / будущее), понедельник по поясу ученика, неделя через смену месяца; `lost` появляется после сгорания (1 день без заморозок у Free; 3 дня при 2 заморозках у Pro) с правильным `days`, не появляется при заморозке, исчезает после первого нового дня; гонка «два чтения сгорают одновременно» (PostgreSQL fork) — одно значение; **бюджет `/today` не изменился** (`TwaTodayMeQueryBudgetTest`, `TodayStreakQueriesTest`); `StreakServiceTest` зелёный без правок логики серии. pint, phpstan.
**Не трогать:** правила заморозок, `StreakFreezeService`, `TodayProfileCache`.

### T10 — Mini App: F4 экран серии (coder, M)

**Контекст:** §2.5, кит `F4-seriya-spasena.png`; `StampMark`, `MascotArt freeze/thinking`, `PaywallSheet` (`reason: streak_freeze`), `seenFlag`, `stores/today.ts`, `App.vue`.
**Файлы (новые):** `views/StreakSavedView.vue`, `components/today/WeekCalendar.vue`, `composables/useStreakMoments.ts`, тесты для всех трёх. **Правки:** `router/index.ts` (`/streak/saved`, без `tab`), `App.vue` (вызов `useStreakMoments`), `api/types.ts` (`streak.week`, `lost`), i18n.
**DoD / тесты:** показ один раз по `frozen_yesterday` (ключ `streak_saved:<дата>`), вариант `lost` (ключ `streak_lost:<lost.on>`), приоритет «спасена» над «прервалась», не показывается при deep link / открытой сессии / старом сервере без `week`; календарь: 4 состояния дня, подписи дней недели ru/uz; «Осталось заморозок: N»; Free в варианте `lost` — пейвол; «Поделиться серией» ведёт на `/share/story?kind=streak` (до T12 — скрыта); хаптик печати `impactOccurred('medium')` один раз; `prefers-reduced-motion`; vitest, vue-tsc.
**Не трогать:** `TodayView.vue` (триггер — из `App.vue`).

### T11 — Сервер: карточка 9:16 и `POST /share/story` (architect — рисовальщик и сервис; coder — маршрут/Resource/лимит, M)

**Контекст:** §2.4; `AchievementCardRenderer`, `CardSpec`, `CardPalette`, `ShareMessageBuilder` (правила предмета), `SharePreparer` (ошибки, события), `ShareController`, `config/share.php`, `config/achievements.php`, `docker/nginx/prod.conf` (имена файлов — менять не нужно), `ShareCardPruner`, `lang/*/share.php`, тесты `AchievementCardRendererTest`, `TwaShareTest`, `ShareCardTextsTest`.
**Файлы (новые):** `app/Domain/Engagement/Services/StoryCardRenderer.php`, `app/Domain/Engagement/Services/StoryShareService.php`, `app/Domain/Engagement/DTO/StoryShare.php`, `app/Http/Requests/Twa/StoryShareRequest.php`, `app/Http/Resources/Twa/StoryShareResource.php`, тесты `StoryCardRendererTest`, `StoryShareServiceTest`, `TwaShareStoryTest`. **Правки:** `ShareKind` (+ `Streak`; `isAvailable()` для `prepare` — `false`, для story — отдельный `forStory()`), `ShareController` (+ `story`), `routes/api.php` (`throttle:twa-share`), `CardPalette` (+ тёмный набор кита, тест сверки со `style.css`), `lang/ru|uz/share.php` (`story.streak.title/description/text`, `story.achievement.text`), `ShareAnalytics` (`via: story`, события `story_sent` / `story_fallback`).
**DoD / тесты:** JPEG 1080×1920, фон чернила, тексты ru/uz в строки (все ключи достижений + серии 1…365), имя файла под `FILE_PATTERN` и регулярку nginx, повторный рендер — тот же файл, `publicUrl` null → 403, серия 0 → 404, чужое достижение → 404, `share.enabled=false` → 403, 429 лимита, `text` ≤ 200 символов с реферальной ссылкой, бюджет ≤ 3 операторов (`QueryLog`), чистка файлов `story_*` прунером. pint, phpstan.
**Не трогать:** `SharePreparer` (inline-сообщения), nginx.

### T12 — Mini App: F3 экран сторис (coder, M)

**Контекст:** §2.4, кит `F3-share-stories.png`; `useTelegram` (`shareMessage` — образец обёртки), `useShare`, `AchievementsView.vue`, `stores/referrals.ts`, `api/shareTypes.ts`.
**Файлы (новые):** `views/ShareStoryView.vue`, `composables/useStoryShare.ts`, тесты. **Правки:** `useTelegram.ts` (+ `canShareToStory`, `shareToStory`), `api/index.ts` (`share.story`, событие), `router/index.ts` (`/share/story`, `tab: profile`), `AchievementsView.vue` (кнопка «В сторис» у открытых), `StreakSavedView.vue` (показать ссылку из T10), i18n.
**DoD / тесты:** клиент ≥ 7.8 → `POST /share/story` → `shareToStory(media_url, {text, widget_link})`; < 7.8 → кнопки нет; 403/404/429/503 или исключение SDK → `useShare` (prepare + shareMessage), далее `t.me/share/url`; превью — `<img>` из `media_url` со скелетом; текст про дни Pro из `/referrals`; «Позвать друга на баттл» → `useShare('friend_invite')`; события `story_sent`/`story_fallback`; `BackButton` — назад; vitest, vue-tsc. Проверка на живом боте (владелец/devops): iOS, Android, Desktop; Premium и без; картинка через Cloudflare — таблица в PROGRESS.md.
**Не трогать:** `SharePreparer` путь, `ReferralsView`.

### T13 — Mini App: F5 «Нет сети» (coder, S/M)

**Контекст:** §2.6, кит `F5-net-seti.png`; `api/client.ts` (`ApiClientError`, `kind`), `stores/training.ts` (отправка `/review`, `serve_token`), `App.vue`, `InlineAlert`, `MascotArt thinking`.
**Файлы (новые):** `composables/useOnline.ts`, `components/OfflineScreen.vue`, тесты. **Правки:** `api/client.ts` (хук `onNetworkError` / счётчик), `App.vue` (оверлей), `stores/training.ts` (ожидающий ответ + `sessionStorage` + повтор), `views/TrainingView.vue` (чип прогресса для F5, кнопка «Повторить» дергает повтор стора), i18n.
**DoD / тесты:** `offline` → экран, `online` → повтор и закрытие; падение главного запроса по сети при `onLine=true` → экран с «Повторить»; тренировка: ответ при `network` сохраняется, повтор отправляет тот же `serve_token`, 200 → очищено и `/next`, 409/410 → очищено и `/next`; перезагрузка с ожидающим ответом → сначала он; кнопки «Повторять слова офлайн» нет; иммерсивные экраны сохраняют свой `InlineAlert` при `onLine=true`; vitest, vue-tsc.
**Не трогать:** серверные маршруты, `TodayView.vue`.

### T14 — Сервер: тест уровня (coder; ревью architect, M) — после В6

**Контекст:** §2.1a; `ExamQuestionBuilder::forWords`, `LevelQuizService` (как **не** делать: без `exam_sessions`), `TwaOnboardingService` (T2), `OnboardingChoices`.
**Файлы:** `app/Domain/Learners/Services/PlacementTestService.php`, `app/Http/Controllers/Api/Twa/OnboardingPlacementController.php`, Requests/Resources, `tests/Feature/TwaPlacementTest.php` (+ бюджет).
**DoD / тесты:** 10 вопросов по 2 на уровень, варианты из того же курса, ключ только на сервере (`meta.twa_onboarding.placement`), ответы: подсчёт уровня по правилу §2.1a (таблица случаев: 0 верных → A1; верно A1–B1, пусто B2 → B1; «дырка» на B1, но верно B2 → A2…), 410 через 20 минут, 409 после `done`, повторная сдача — новый набор; ничего не пишется в `word_repetitions` / `training_*` / очки (тест-страж); бюджет ≤ 8 / ≤ 3.

### T15 — Mini App: экран теста уровня (coder, M) — после T14

Экран на `QuizOption` (10 вопросов, прогресс 10 сегментов, без таймера), результат → подстановка в O5 с подписью «примерный уровень — можно сменить»; кнопка на O5 показывается, когда сервер отдаёт `options.placement_available: true`. Тесты vitest.

### T16 — Мелочи после (coder, S)

Убрать подсказку «завершите настройку в боте» на «Сегодня» (после завершения текущих работ над `TodayView.vue`); приветствие и профиль — `display_name ?? first_name`; при решении В1 «показывать всем» — `display_name ?? first_name` в `LeagueRow`, друзьях, баттлах, рефералах, сообщениях бота (6 мест, один `Learner::shownName()`); PROGRESS.md.

---

## 5. Риски

1. **Два онбординга пишут одни колонки.** Бот и Mini App сходятся на `OnboardingService::completeExternally` и блокировке строки; но ученик, прошедший Mini App-онбординг без `/start`, получает первое сообщение бота только при `/start`. Проверить на ревью: `StartCommandHandler` для `done`-ученика без `pro_trial_notice`; `settle()` не двигает ничего у `done`.
   **Решено (27.11.2026, ревью architect): отложенное приглашение.** Ученик пришёл по `org_<code>` / `f_<code>` (бот запомнил код в `meta.pending_*_invite`), а онбординг завершил в Mini App — бот его шаг «предложить после последнего шага» не проходит. Теперь `completeExternally` после commit ставит `OfferPendingInvitesJob` (очередь, `ThrottleTelegramOutbound`) — **по задаче на каждое предложение** (`kind`: `organization` / `friend`; одно сообщение — один слот бюджета): те же `offerPending()` мессенджеров в личку (`telegram_user_id`); код снимается под блокировкой до отправки — повтор, второе устройство и параллельный `/start` предлагают один раз. 429 / нет слота (сообщение не ушло): мессенджер возвращает код в `meta` (если там уже не новый) и пробрасывает исключение — middleware откладывает задачу на `retry_after`, повтор доставляет (ревью 27.11.2026; раньше `catch (Throwable)` глотал 429 и код терялся). Прочие ошибки — `report`, задача завершается. Недоступная личка (`dm_blocked_at`, бан) — код остаётся, и `/start` `done`-ученика без payload предлагает его после «Сегодня» (вторая страховка — и на случай потерянной очереди). Тесты — `PendingInvitesAfterMiniAppOnboardingTest`.
2. **`first_name` перезаписывается Telegram** — поэтому имя идёт в `display_name`. Риск — забыть и показать `first_name` на O8/приветствии. Тест-страж в vitest.
3. **Имя — текст, который увидят другие (при В1 «всем»).** Защита — `DisplayName::validate` и `forChat` в боте; до решения В1 имя не покидает своих экранов.
4. **Цель дня в онбординге у Free без пробного** (пробный выключен `BILLING_TRIAL_DAYS=0` или уже использован): 403 на 20/40 — клиент обязан показать замок по `daily_goal_max`, иначе ученик упрётся в ошибку. Тест.
5. **Кэш каталога меняет форму** (`stages_count`) — версия ключа `v1 → v2` обязательна, иначе старые снимки без поля дадут `null`/ошибку типов.
6. **F2 и большие словари.** Пагинация keyset по `lower(word)` требует сортировки всех строк ученика при каждой странице (≤ тысяч строк — мс). Если у кого-то 50 000 строк (учитель-тестировщик), это десятки мс — приемлемо; при жалобах — индекс-выражение `(learner_id, lower(word))` невозможен (разные таблицы), вариант — денормализовать `word_lower` в `word_repetitions` (не делаем сейчас). Снять EXPLAIN на PostgreSQL до выката.
7. **`LIKE '%q%'` по переводу** не использует индексы — он и не должен: фильтр идёт по строкам ученика. Ревью: убедиться, что планировщик начинает с `word_repetitions`, а не с `words` (EXPLAIN); при обратном порядке — `MATERIALIZED` CTE по строкам ученика.
8. **Сторис.** `widget_link` только у Premium; у остальных сторис уйдёт без ссылки — поэтому адрес бота на картинке и в тексте. Колбэка результата у `shareToStory` нет — «отправлено» мы не знаем, аналитика `story_sent` означает «открыто». Картинку качает Telegram — Cloudflare/nginx должны отдавать `share-cards` без проверок (уже так для 1:1). Проверка на живом боте обязательна до включения кнопки (как H1).
9. **F4 гонки сгорания.** `burn()` — оптимистичный UPDATE без блокировки; два параллельных чтения: первый пишет `lost`, второй видит `affected = 0` и перечитывает — `lost` должен попасть и в `syncModel`. Fork-тест на PostgreSQL.
10. **`TodayView.vue` в работе.** Ни одна задача здесь его не правит (триггер F4 — из `App.vue`, вход F2 — на экране курса, имя — T16 позже). Координатору следить за очередностью T16.
11. **Офлайн.** `sessionStorage` в Telegram WebView на iOS иногда очищается при сворачивании; потеря ожидающего ответа безвредна (сервер не выдал следующую карточку). `navigator.onLine` ненадёжен — решает падение запроса.
12. **Размер чанка Mini App.** Онбординг, словарь, сторис, серия — ленивые маршруты (`import()`), иначе основной чанк (503 кБ) вырастет. Проверять `npm run build` в DoD.
13. **Узбекские тексты** всех новых экранов — носитель (`docs/I18N_UZ_REVIEW.md`), до того — черновик исполнителя.

---

## Приложение «Замеры» (T6, 27.11.2026)

**Стенд.** PostgreSQL 16 (локальный кластер, временная БД `lexiflow_explain`, удалена после замера): `words` 200 000 (2 % неактивных),
`word_translations` 100 000 (uz), `word_repetitions` 245 000 — ученик под замером 5 000 строк, ещё 60 учеников по 4 000. `ANALYZE` после
наполнения. Каждый оператор — тем же SQL и bindings, что шлёт `VocabularyService`, `EXPLAIN (ANALYZE, BUFFERS)`, второй прогон (кэш тёплый).
Две настройки планировщика: прод (`random_page_cost = 1.1`, `jit = off`, `effective_cache_size = 5GB`, `work_mem = 16MB` — как
`docker-compose.prod.yml`) и умолчания PostgreSQL (`random_page_cost = 4`).

**Как было по §2.3 буквально** (простой `JOIN words`, `LEFT JOIN word_translations`): план **плохой** при любых настройках —
`Parallel Hash Join` с `Parallel Seq Scan on words` (все 200 000 строк, 2 366 буферов) против строк ученика: страница 20–22 мс, счётчики 19–20 мс,
поиск 26–31 мс; время растёт с размером всего `words`, а не словаря ученика (риск §5 п. 7 подтвердился). `MATERIALIZED` CTE по строкам ученика
помог только с прод-настройками (Nested Loop, 11 мс); с `random_page_cost = 4` планировщик выбрал `Merge Join` вдоль всего `words_pkey`
(196 000 строк, 33 мс), поиск по переводу — `Hash Join` с `Seq Scan` `word_translations` (86 мс).

**Что сделано (без новых индексов).**
- Страница: `words` присоединяется как `JOIN LATERAL (SELECT … FROM words WHERE id = wr.word_id AND is_active OFFSET 0) w` — подзапрос с `OFFSET 0`
  планировщик не может ни поднять, ни развернуть: всегда Nested Loop от строк ученика (на sqlite — обычный `JOIN`, его планировщик и так идёт от `WHERE`).
- Поиск по переводу: `OR EXISTS (SELECT 1 FROM word_translations wt WHERE wt.word_id = w.id AND wt.locale = ? AND lower(wt.translation) LIKE ?)` вместо
  `LEFT JOIN` — `SubPlan` по unique `(word_id, locale)` на строку.
- Счётчики: слова не нужны — `NOT EXISTS (… words … is_active = false)` по частичному индексу `words_inactive_idx` (как `dueCount`, docs/38).

**После (мс, Execution Time; прод-настройки / умолчания):**

| Оператор | Прод | Умолчания | План |
|---|---|---|---|
| страница, `all` | 10,4 | 10,1 | Index Scan `word_repetitions_learner_id_created_at_index` (5 000 строк, 192 буфера) → Nested Loop → Index Scan `words_pkey` ×5 000 (20 000 буферов) → top-N heapsort 36 kB |
| страница, `weak` | 2,4 | 2,1 | то же, фильтр силы до соединения: 577 проб `words` |
| страница, `strong` | 9,1 | 9,3 | то же, 3 946 проб |
| поиск по слову `q=up` | 20,3 | 18,6 | то же + фильтр `LIKE` + `SubPlan` `word_translations_word_id_locale_unique` ×5 000 (35 192 буфера) |
| поиск по uz-переводу | 20,3 | 18,7 | то же |
| поиск без совпадений | 18,3 | 18,0 | то же |
| вторая страница (`after`) | 11,3 | 18,3 | то же + условие курсора |
| 61-я страница | 9,5 | 16,0 | то же |
| счётчики (первая страница) | 2,4 | 2,2 | Merge / Hash Anti Join: Index Scan строк ученика (2 501 буфер) + Index Only Scan `words_inactive_idx` (13 буферов) |

`Seq Scan` нет ни в одном операторе; сортируются только строки ученика (≤ 5 000, top-N в памяти). Стоимость страницы — 4 буфера на слово ученика
(проба `words_pkey`), то есть линейна по размеру словаря ученика и не зависит от размера `words`. У обычного ученика (сотни строк) — доли мс;
у ученика с 50 000 строк ожидаемо ~100 мс на страницу — тогда вариант из §5 п. 6 (денормализовать `word_lower` в `word_repetitions`), сейчас не нужен.
Цена для записи — ноль: ни индексов, ни колонок не добавлено.
