# 04. API & Telegram Bot — LexiFlow Pro

## Часть A — REST API для TWA

**Base URL:** `/api/twa`
**Auth:** JWT в `Authorization: Bearer <token>` (кроме `/auth`).
**Content-Type:** `application/json`.

**Реализация.** Контроллер только валидирует запрос и вызывает сервис (`TrainingFlowService`,
`ExamFlowService`, `LearnerStatsService`), ответ собирает Resource-класс из `app/Http/Resources/Twa/`
(ошибки — `ApiErrorResource`). Ответы идут **без обёртки `data`** — формы ниже закреплены
контрактными тестами `tests/Feature/TwaContractTest.php` (`assertExactJsonStructure`).

### A.1 `POST /api/twa/auth`
Аутентификация через Telegram `initData`. **Identity — только Telegram-пользователь**: первый вход создаёт `learner`
(`LearnerRegistrar`), повторные возвращают того же и обновляют имя/username/`last_seen_at`. Состоять в какой-либо группе
для входа не нужно; доступ к конкретной сессии проверяется на её маршрутах (A.3, A.4).

**Request:**
```json
{
  "init_data": "query_id=...&user=%7B...%7D&auth_date=...&hash=...",
  "context": { "type": "training", "session_id": 123 }
}
```

`context` необязателен и **не влияет на выбор learner'а**: он оставлен для совместимости клиента и аналитики
(`twa.authenticated`); группа сессии из `context` попадает в событие только если learner — её активный участник (контекст задаёт клиент и не доверяется). `acquisition_source` через `/auth` не пишется.
Несуществующая сессия в `context` не является ошибкой.

**Validation:**
- HMAC-SHA256 по алгоритму Telegram (см. `07_SECURITY.md`).
- `auth_date` не старше 24 часов.

**Response 200:**
```json
{
  "token": "eyJhbGciOi...",
  "expires_in": 900,
  "learner": {
    "id": 42,
    "first_name": "Alex",
    "locale": "ru",
    "onboarding_completed": false
  }
}
```

Токен — JWT **v2**: `{iss, sub: learner_id, v: 2, iat, exp}` (без `gid`), TTL 15 мин. Токен без `v = 2` (старый формат,
где `sub` был `students.id`) отклоняется с 401 `invalid_token`; клиент перелогинивается автоматически (`api/client.ts`).

**Errors:**
- 401 `invalid_init_data` — HMAC не сошёлся / `initData` просрочен.
- 401 `learner_blocked` — learner заблокирован платформой (`learners.blocked_at`).
- 422 — нет поля `init_data`.

Ошибок `session_context_required` (409) и `student_not_found` (403) на `/auth` больше нет.

**Ошибки защищённых маршрутов (`TwaAuth`, любой запрос с токеном):** 401 `missing_token`, 401 `invalid_token`
(подпись, срок, `iss`, версия), 401 `learner_not_found` (learner удалён), 401 `learner_blocked`
(проверяется на каждом запросе — бан действует немедленно).

### A.2 `GET /api/twa/me`
Информация о learner'е.

**Response:**
```json
{
  "learner": { "id": 42, "first_name": "Alex", "username": "alex_dev", "locale": "ru", "onboarding_completed": true },
  "groups": [{ "id": 7, "title": "B1 English Evening" }],
  "stats": {
    "words_learned": 234,
    "words_due_today": 18
  },
  "entitlement": { "plan": "pro", "source": "subscription", "until": "2026-12-31T12:00:00+00:00", "new_words_per_day": null },
  "paywall": { "mode": "coming_soon", "contact": null }
}
```

- `groups` — группы, где learner состоит **сейчас** (активное членство в активной группе); пустой массив, если групп нет.
- `words_learned` — слова с хотя бы одним успешным повторением (`repetitions >= 1`).
- `words_due_today` — то же правило, что у напоминаний (`DueRepetitionCriteria`, docs/06 §6): карточки в
  `review` с `next_review_at <= now` плюс **брошенные** learning/relearning карточки, чей минутный шаг истёк
  больше `LEARNING_ABANDONED_AFTER_HOURS` (12 ч) назад. Свежие шаги внутри сессии и `new`-строки
  (карточка показана, не оценена) не считаются. Считается по всему прогрессу learner'а, а не по одной группе.
- `entitlement` — тариф learner'а сейчас (`EntitlementResolver::for`, docs/12 §5.1): `plan` — `free` | `pro`; `source` — `free` |
  `subscription` (личная подписка: ручная выдача, промокод) | `sponsor:organization:<id>` (Pro центра, к группе которого привязана
  группа learner'а); `until` — ISO 8601, `null` = конца нет (Free или бессрочная выдача); `new_words_per_day` — лимит новых слов
  в день (`20` у Free), `null` = безлимит. Значение из кэша (TTL 300 с), изменения подписки/членства сбрасывают его сразу.
- `paywall` — что показать при исчерпанной квоте (docs/12 §5.4): `mode` — `coming_soon` | `purchase`; `contact` — контакт
  поддержки для центров (`BILLING_SUPPORT_CONTACT`), **`null`, если не задан — строку с контактом тогда не показывать**.
- Настройки и `current_streak_days` появятся в задачах 1.3b / 1.6 (docs/12 §3.8).

### A.3 Training mode

#### `POST /api/twa/training/sessions`
Learner открывает **личную** сессию (docs/12 §7.6): урок целиком (`lesson`) или ежедневное повторение по всем урокам (`daily_review`).
Идемпотентно: пока есть открытая неистёкшая сессия того же (learner, урок) / (learner, `daily_review`), возвращается она же
(одна на пару, в том числе при гонке двух вкладок); просроченная, но ещё не закрытая планировщиком — закрывается
(`done_reason = expired`) и заменяется новой. Для `daily_review` `course_id` сессии обновляется до текущего активного курса.

**Request:**
```json
{
  "mode": "lesson",          // lesson | daily_review (group_lesson открывает только учитель из бота)
  "lesson_id": 321,          // обязателен для lesson (integer ≥ 1), запрещён для daily_review
  "source": "picker"         // optional: self (по умолчанию) | picker | reminder | onboarding; записывается только в НОВУЮ сессию
}
```

**Response (200, всегда — и для новой, и для уже открытой сессии):** ровно как у `/start` ниже (`session_id`, `mode`, `lesson`,
`total_words`, `card`, `progress`, `done_reason`). Клиент после ответа переходит на экран тренировки по `session_id`
(экран снова вызывает `/start`, это идемпотентно: первая карточка та же).

**Ошибки:** 422 валидации Laravel (`mode` вне `lesson`/`daily_review`, `lesson_id` не integer, `source` вне списка) →
422 `lesson_mismatch` (`lesson` без `lesson_id` или `daily_review` с ним) → 404 `lesson_not_found` (урока нет **или** его курс
не `published` — статус курса не раскрывается) → 429 (лимит `twa-session-create`, 10 запросов/мин на learner'а).
(`invalid_mode` из сервиса при HTTP недостижим — режим отсекает валидация; код оставлен в списке для клиента.)

Побочный эффект: событие `training.session_opened` `{training_session_id, mode, source, reused}` (`reused = true`, если вернулась уже открытая сессия).

#### `POST /api/twa/training/sessions/{session_id}/finish`
Learner завершает **свою личную** сессию (`status = closed`, `ended_at`, `done_reason = finished`) и получает итог по ней.
Идемпотентно: повтор отвечает 200 с теми же числами и не меняет `ended_at`/`done_reason` (в том числе у сессии, уже закрытой
планировщиком с `expired`). Порядок проверок: 404 `session_not_found` → 403 (`forbidden` — чужая личная сессия; `not_a_member` / `group_inactive` — групповая сессия, к которой нет доступа) → 403 `forbidden` для
**любой** групповой сессии (её закрывает учитель/планировщик; сервис при прямом вызове отвечает `not_personal`). Закрытая
сессия — не 410: здесь 410 нет.

**Response:**
```json
{ "reviewed": 14, "correct": 11, "new_words": 4 }
```
`reviewed` — принятые оценки в сессии, `correct` — из них с `quality >= 3`, `new_words` — слова, у которых строка
`word_repetitions` создана не раньше начала сессии. Цель дня и streak добавит `/today` (task 1.3b).

Незавершённые сессии закрывает команда `training:close-expired` (планировщик, каждые 15 минут, `withoutOverlapping`): личные — по
`expires_at` (`now + learning.session_ttl_hours`, 12 ч, при открытии), групповые — старше `group_session_ttl_hours`; обе с
`done_reason = expired`, `ended_at` = момент закрытия.

После `finish` (и после закрытия планировщиком) `/start`, `/next`, `/review` этой сессии отвечают 410 `session_closed`.

#### `POST /api/twa/training/sessions/{session_id}/start`
Learner присоединяется к открытой тренировке. Доступ — по членству (docs/12 §4.4): групповая сессия доступна **активному
участнику активной группы**, личная (`mode <> group_lesson`) — только её владельцу. Порядок проверок: 404 `session_not_found` →
403 (`not_a_member` — нет активного членства, в т.ч. вышел/исключён; `group_inactive` — участник группы, которая не активирована;
`forbidden` — чужая личная сессия) → 410 `session_closed` (`status <> 'open'`).

Прогресс общий: у learner'а в двух группах одна строка `word_repetitions` на слово, поэтому оценка в тренировке группы A
видна в тренировке группы B.

**Response:**
```json
{
  "session_id": 123,
  "mode": "group_lesson",    // group_lesson | lesson | daily_review
  "lesson": { "stage": 4, "lesson": 8, "title": "Resilience" } | null,   // null для daily_review
  "total_words": 25,         // lesson/group_lesson: активные слова урока; daily_review: learners.daily_goal
  "card": { "word_id": 1001, "word": "Resilient", "translation": "Устойчивый", "translation_fallback": false, "example": null, "transcription": null, "card_kind": "new", "serve_token": null } | null,
  "progress": { "done": 0, "total": 25 },
  "done_reason": null        // см. ниже; не null ровно когда card = null
}
```

`card` / `progress` / `done_reason` — первая карточка, прогресс и причина «карточек нет» в том же формате, что у `/next`.
Если у урока сессии нет (удалён) — 404 `lesson_not_found`.

#### `GET /api/twa/training/sessions/{session_id}/next`

После `TWA_MAX_CARDS_PER_SESSION` принятых review в рамках одной сессии возвращает `card: null`.
Следующая карточка. Возвращает либо `due` (для повторения), либо `new` (новое слово из урока).

**Response:**
```json
{
  "card": {
    "word_id": 1001,
    "word": "Resilient",
    "translation": "Устойчивый",
    "translation_fallback": false, // true — перевод на русском, потому что на язык сессии его ещё нет (клиент показывает пометку)
    "example": "She is a resilient girl.",
    "transcription": "rɪˈzɪl.i.ənt",
    "card_kind": "due",     // due | new | learning (минутные learning steps, см. docs/06 §4)
    "serve_token": null     // string для карточки, выданной досрочно (learn-ahead) — вернуть в POST /review
  },
  "progress": { "done": 7, "total": 25 },
  "done_reason": null        // null, пока есть card; иначе причина, см. ниже
}
```

**`progress`:** в уроке — `done` = слова урока, дошедшие до `repetitions >= 1`, `total` = активные слова урока; в `daily_review` —
`done` = оценки в этой сессии, `total` = `learners.daily_goal`. В `daily_review` `done` может превысить `total`
(цель перевыполнена) — клиент обрезает полосу прогресса до `total`.

**`done_reason`** (`card: null` ⇒ ровно одно из значений; при наличии карточки — `null`):

| Значение | Когда |
|---|---|
| `lesson_complete` | scope `lesson`: все слова урока пройдены, ничего не due и не в learning steps |
| `nothing_due` | scope `daily_review`: ничего не due, а новых слов в активном курсе нет (или курс не выбран) |
| `new_quota_exhausted` | новые слова ещё есть, но дневной лимит новых карточек исчерпан, а due нет (план Free; в 1.3a бюджет безлимитный, лимит включает 1.4b) |
| `session_cap` | принято `TWA_MAX_CARDS_PER_SESSION` оценок в этой сессии |

Клиент показывает экран «готово» с текстом по `done_reason`; для `new_quota_exhausted` пока только текст (paywall — 1.4b).
Личную сессию на экране «готово» клиент закрывает `POST /finish` (best effort); групповую не закрывает никогда.

**Язык перевода** (`translation`): групповая сессия — язык группы (`telegram_groups.meta.locale`, по умолчанию `ru`),
личная сессия — язык интерфейса learner'а (`interface_locale`). Если перевода на этот язык нет, отдаётся русский
(`word_translations.ru` → `words.translation`) и `translation_fallback = true`; для русского языка флаг всегда `false`
(docs/12 §1.7, docs/03 §2.21).

При отсутствии карточек — `{ "card": null, "progress": { ... } }` и клиент показывает экран «готово».
Пока в уроке есть карточки в learning steps, сервер отдаёт их досрочно (learn-ahead), а не `null`.
Такая карточка приходит с одноразовым `serve_token` (docs/06 §4–5.2): клиент хранит его вместе с
карточкой и отправляет в `POST /review`. Для due/new карточек `serve_token = null`.

#### `POST /api/twa/training/sessions/{session_id}/review`
Оценка карточки.

**Request:**
```json
{
  "word_id": 1001,
  "quality": 2,             // 0..5; TWA шлёт 2=Трудно (Again), 4=Средне (Good), 5=Легко (Easy); 3 = Hard-pass
  "time_spent_ms": 4300,
  "serve_token": null       // optional, <= 64 символов: card.serve_token выданной карточки (обязателен для learn-ahead)
}
```

**Response:**
```json
{
  "next_review_at": "2026-04-22T14:00:00Z",
  "new_interval_days": 3,    // 0, пока карточка в минутных learning steps
  "easiness_factor": 2.45,
  "card": { ... } | null,    // следующая карточка (как в /next)
  "progress": { "done": 7, "total": 25 },
  "done_reason": null
}
```

Ошибки: 409 `card_not_started` (карточка не выдавалась), 409 `card_not_due` (повторный submit:
карточка ещё не due, а `serve_token` отсутствует, устарел или чужой — docs/06 §5.2; клиенту следует
перезапросить `GET /next`), 422 `out_of_scope` (слово вне scope сессии: в `group_lesson`/`lesson` — слово другого урока,
в любом режиме — неактивное слово `words.is_active = false`; бывш. `wrong_lesson`), 410 `session_closed`.

### A.4 Exam mode

**Персональный порядок (anti-cheat).** Набор вопросов строится один раз при `/start_exam`
(`ExamQuestionBuilder`) и хранится в `exam_sessions.config.questions` в *каноническом* виде
(`word_id, word, correct_translation, options[]`, без `correct_index`). При выдаче
`ExamQuestionPersonalizer` применяет к нему детерминированную перестановку **для конкретного learner'а**:
порядок вопросов и порядок вариантов у каждого свои, seed = `HMAC-SHA256(config.order_secret, session_id|learner_id)` (случайный секрет сессии, не отдаётся в API; для legacy-сессий без него — `app.key`) —
участник не может вычислить чужой порядок. Следствия:

- `question_index` везде — позиция в **личной** последовательности learner'а; повторный
  `GET /question?index=i` возвращает тот же `word_id` и тот же порядок `options`, таймер не перезапускается.
- Правильный индекс считается сервером для этого learner'а и при выдаче, и при `answer`; подсказка
  «3 — B» из чата у соседа не сработает (у него другой вопрос под №3 и другой порядок вариантов).
- `options` содержит правильный перевод и до 3 **реальных** дистракторов. Слои кандидатов (только активные слова):
  другие уроки того же stage → другие stage'ы **своего** курса → другие слова этого же урока → **чужие** курсы
  (только `published` + `public` + платформенные; draft/review/archived/unlisted/private и курсы организаций
  не используются, а слой подгружается лишь если в своём курсе меньше нужного числа уникальных кандидатов
  **на языке ответа**). Язык ответа обходится по всем слоям первым, другой язык — последняя мера
  (см. ниже); внутри слоя предпочтительна та же `part_of_speech`; без дубликатов с учётом регистра/пробелов.
  Заглушек нет; при бедной библиотеке вариантов может быть меньше 4 (минимум 2).
- Тексты вопросов — переводы на язык **группы** (`exam_sessions.config.locale`, фиксируется при `/start_exam`) с fallback
  на `ru`. Чтобы язык не выдавал ответ (кириллица рядом с латиницей), дистракторы того же языка, что и верный ответ,
  берутся первыми; другой язык — только если их не хватает. Флага fallback у экзаменационных вопросов нет.
  Если для слова нет ни одного реального дистрактора — `/start_exam` отвечает учителю ошибкой, сессия не создаётся.
- Сессии, созданные до этого режима (нет `config.question_order`), доигрываются с общим порядком как раньше.

**Доступ к экзамену** (все маршруты `/exam/sessions/{id}/*`): активное членство в активной группе сессии (`ExamSessionPolicy`).
Ошибки: 403 `not_a_member` (бывш. `forbidden`), 403 `group_inactive`, 410 `session_closed` (join/question/answer после конца), 409 `exam_still_open` (`/result` до закрытия).
Один learner в двух группах участвует в экзаменах каждой группы независимо (ответы и результаты привязаны к `(exam_session_id, learner_id)`).

#### `POST /api/twa/exam/sessions/{session_id}/join`
**Response:**
```json
{
  "session_id": 55,
  "ends_at": "2026-04-19T19:10:00Z",
  "total_questions": 10,
  "seconds_per_question": 10,
  "question": { "question_index": 0, "word_id": 1001, "word": "Resilient", "options": ["..."], "seconds_left": 10 }
}
```

`question` — первый вопрос **личной** последовательности learner'а (формат как у `/question`);
`null`, если в сессии нет вопросов. Повторный `join` идемпотентен: серверный таймер вопроса не перезапускается.

#### `GET /api/twa/exam/sessions/{session_id}/question?index=0`
**Response:**
```json
{
  "question_index": 0,
  "word_id": 1001,
  "word": "Resilient",
  "options": [
    "Устойчивый",
    "Хрупкий",
    "Смелый",
    "Тихий"
  ],
  "seconds_left": 10
}
```

#### `POST /api/twa/exam/sessions/{session_id}/answer`
**Request:**
```json
{
  "question_index": 0,
  "word_id": 1001,
  "selected_option_index": 0,
  "time_spent_ms": 3200
}
```

**Response:**
```json
{
  "is_correct": true,
  "correct_option": 2,
  "score_earned": 15,
  "total_score": 45,
  "has_next": true,
  "next_question": null
}
```

`next_question` всегда `null`: следующий вопрос запрашивается после показа фидбэка, чтобы его серверный
таймер стартовал в момент показа.

`selected_option_index` и `correct_option` — индексы в `options` **этого** learner'а (его личный порядок).
`correct_option` возвращается только после фиксации ответа (для подсветки в TWA). Ошибки: 404 `question_not_found`,
422 `word_mismatch` (`word_id` не соответствует вопросу под этим индексом у данного learner'а),
409 `question_not_served` / `already_answered`, 410 `session_closed`.

#### `GET /api/twa/exam/sessions/{session_id}/result`
Доступно только после закрытия сессии.
```json
{
  "learner_score": 95,
  "rank": 3,
  "total_participants": 12,
  "leaderboard": [
    { "rank": 1, "name": "Alex", "score": 120 },
    { "rank": 2, "name": "Maria", "score": 110 }
  ]
}
```

### A.5 TTS fallback
`GET /api/twa/tts?word=resilient&voice=en-US-AriaNeural`
Возвращает `audio/mpeg`. Cache-friendly: `Cache-Control: public, max-age=2592000`.

### A.5a Каталог курсов (docs/12 §1.7, §3.2)

Все три маршрута — под `TwaAuth` и `throttle:twa-api`. Название и описание отдаются на `learners.interface_locale`
с fallback на `ru` (пустое описание — `null`). Видимость: список показывает курсы `status = published` и
`visibility = public`; по slug открывается и выбирается любой `published` курс, кроме `private` (`unlisted` — «по ссылке»).
Всё остальное (draft / review / archived / private / неизвестный slug) отвечает **одинаково**: `404 course_not_found`
— существование не раскрывается.

**Прогресс** — по слову learner'а, не по группе: `done` = активные слова курса (`words.is_active = true`), у которых есть
строка `word_repetitions` этого learner'а с `repetitions >= 1` (то же правило, что `stats.words_learned`); `total` = все
активные слова. Неактивные слова не считаются ни в `done`, ни в `total`. Считается живым агрегатным запросом, не кэшируется.

#### `GET /api/twa/courses`
```json
{
  "courses": [
    {
      "slug": "english-a1", "title": "Английский A1", "description": "Первые 500 слов", "level": "A1", "exam_tag": "general",
      "words_count": 500, "is_featured": true, "is_active": true,
      "progress": { "done": 120, "total": 500 }
    }
  ]
}
```
Порядок — `position`, затем `id`. `is_active` — это `learners.active_course_id`. Активный курс learner'а остаётся в списке,
даже если он `unlisted` (выбран по ссылке), пока курс доступен. Список курсов без прогресса кэшируется на 10 минут
(по локали) и сбрасывается при публикации/архивации/правке курса и `CoursePublisher::recount`.

#### `GET /api/twa/courses/{slug}`
```json
{
  "slug": "english-a1", "title": "Английский A1", "description": null, "level": "A1", "exam_tag": "general",
  "words_count": 500, "is_active": true, "progress": { "done": 120, "total": 500 },
  "stages": [
    {
      "number": 1, "title": "Stage 1", "progress": { "done": 40, "total": 100 },
      "lessons": [ { "id": 11, "number": 1, "title": "Greetings", "progress": { "done": 20, "total": 25 } } ]
    }
  ]
}
```
Модули и уроки — в порядке изучения `(stage.number, lesson.number)`; `lessons[].title` может быть `null`.

#### `POST /api/twa/me/course`
Request: `{ "slug": "english-a1" }` (обязателен, строка ≤ 64 → иначе 422). Ставит `learners.active_course_id`
(идемпотентно). Ответ — строка курса в формате элемента `GET /courses` (`is_active = true`, актуальный `progress`).
Недоступный курс — `404 course_not_found`, активный курс не меняется.

### A.6 Общие правила
- Все даты в ISO 8601 UTC.
- Rate limit: 60 запросов/минуту на learner'а (кроме `/tts` — 30/мин, `/promo/redeem` — A.7, `POST /training/sessions` — 10/мин). `TwaAuth` выполняется **до** `throttle:*`
  (`bootstrap/app.php`, `prependToPriorityList`), поэтому ключ лимитера — `learner_id`, а не IP.
- При любой 4xx/5xx (кроме 422 валидации Laravel) ответ — `ApiErrorResource`:
```json
{ "error": { "code": "string", "message": "string" } }
```

### A.7 `POST /api/twa/promo/redeem`
Активация промокода (docs/12 §5.5). Rate limit `twa-promo`: **5/мин на learner'а и 20/мин на IP** (429) — защита от подбора кодов.

**Request:** `{ "code": "welcome-30" }` — `required|string|max:64`; регистр, пробелы и дефисы не важны
(`PromoCodeService::normalize`). Нарушение валидации — 422 в стандартном формате Laravel (`message` + `errors.code`).

**Response 200:** тот же JSON, что у `GET /me` (A.2), с уже новым `entitlement`:
```json
{ "learner": {…}, "groups": […], "stats": {…},
  "entitlement": { "plan": "pro", "source": "subscription", "until": "2026-11-17T12:00:00+00:00", "new_words_per_day": null },
  "paywall": { "mode": "coming_soon", "contact": null } }
```
Нет живой личной подписки → создаётся подписка `provider=promo` на `duration_days`; есть живая личная с конечной датой →
продлевается на месте (`until = max(until, now) + duration_days`). Спонсорский Pro центра не мешает.

**Ошибки** (`ApiErrorResource`: `{"error": {"code", "message"}}`; клиент показывает свой текст по `code`, `message` — английский):

| HTTP | `error.code` | Когда |
|------|--------------|-------|
| 404 | `promo_not_found` | код неизвестен, пуст или деактивирован (не различаем намеренно) |
| 410 | `promo_expired` | прошёл `expires_at` |
| 409 | `promo_exhausted` | исчерпан `max_redemptions` |
| 409 | `promo_already_redeemed` | этот learner уже активировал этот код |
| 409 | `promo_not_applicable` | у learner'а бессрочная личная подписка — код ничего не добавит и не тратится |

Событие `promo.redeemed` пишется сервисом; запись в `audit_logs` не делается (действие самого learner'а).

## Часть B — Telegram Bot

### B.1 Webhook
**URL:** `POST /telegram/webhook/{secret}`
- `{secret}` генерируется при развёртывании и совпадает с `TELEGRAM_WEBHOOK_SECRET`.
- Дополнительно проверяем заголовок `X-Telegram-Bot-Api-Secret-Token` (Telegram сам его шлёт, если указать при `setWebhook`).
- Контроллер кладёт `Update` в очередь `high` и мгновенно отвечает 200.

```php
// app/Http/Controllers/TelegramWebhookController.php (скелет)
public function __invoke(Request $request, string $secret)
{
    abort_unless(hash_equals(config('telegram.webhook_secret'), $secret), 404);
    abort_unless(hash_equals(
        config('telegram.webhook_secret'),
        $request->header('X-Telegram-Bot-Api-Secret-Token', '')
    ), 403);

    HandleTelegramUpdate::dispatch($request->all())->onQueue('high');
    return response()->json(['ok' => true]);
}
```

### B.2 Middleware

1. **GroupLock** — проверяет `chat_id` в белом списке.
2. **TeacherOnly** — применяется к командам `/start_training`, `/start_exam`, `/close_exam`.
3. **IgnorePrivateChats** — для команд, которые имеют смысл только в группе.

### B.3 Команды и хендлеры

| Команда | Контекст | Роль | Что делает |
|---------|----------|------|-----------|
| `/start` | ЛС | любая | Если `user_id` есть в `users.telegram_user_id` → «Привет, учитель X». Иначе — инструкция, как присоединиться к группе. |
| `/start_training [stage] [lesson]` | группа | teacher | Создаёт `training_session`, постит сообщение с WebApp-кнопкой. Номера stage/lesson ищутся **в курсе группы** (`telegram_groups.course_id`, иначе курс `default`); без аргументов — список уроков этого курса. |
| `/start_exam [stage] [lesson] [minutes]` | группа | teacher | Создаёт `exam_session`, планирует закрытие. Номера — в курсе группы, как выше. |
| `/close_exam` | группа | teacher | Досрочно закрывает открытый экзамен. |
| `/stats` | ЛС | teacher | Отчёт по его группам за 7 дней. |
| `/promo КОД` | ЛС | любой learner | Активирует промокод (`PromoCodeService::redeem`, как A.7). `/promo` без кода — подсказка. Learner создаётся/обновляется по самому человеку (`LearnerRegistrar::fromTelegramUser`). Ответ — на языке learner'а: «Pro активен до дд.мм.гггг» (в поясе learner'а) / «без ограничения по сроку», либо текст ошибки по `errorCode` (`lang/{ru,uz}/bot.php`, `bot.promo.errors.*`). Не более 5 попыток в минуту на learner'а (иначе «слишком много попыток»); заблокированному — «доступ ограничен». В **активной группе** бот отвечает «откройте личный чат» и код не обрабатывает (код в группе виден всем), в неактивной — молчит (Group Lock). |
| `/help` | любой | любая | Список доступных команд для роли. |

### B.4 Формат сообщения с WebApp-кнопкой

```php
$telegram->sendWebAppButton(
    chatId: $group->chat_id,
    text: "📚 Тренировка: Урок {$lesson->number}\nНажмите кнопку, чтобы начать.",
    buttonText: '🎯 Открыть тренировку',
    url: "{$baseUrl}/twa/training/{$session->id}",
);
```

URL TWA: `https://lexiflow.example.com/twa/training/123` (для экзамена — `/twa/exam/{id}`).

### B.4a Язык сообщений бота (i18n)

Все тексты бота, напоминаний и лидерборда лежат в `lang/{ru,uz}/*.php` (`bot`, `errors`, `training`, `exam`, `reminders`, `stats`, `twofactor`) и выводятся через `__()` / `trans_choice()` с **явным** параметром `$locale` — глобальная локаль процесса не меняется (воркеры очередей долгоживущие). Поддерживаемые языки и дефолт — `config/localization.php` (`ru`, `uz`; дефолт и fallback `ru`). Выбор языка — `App\Domain\Localization\Services\LocaleResolver`:

| Кому сообщение | Откуда язык |
|----------------|-------------|
| Сообщение в группу (ответы на команды, кнопка WebApp, лидерборд) | `telegram_groups.meta['locale']` (`ru`/`uz`), если не задан или не поддерживается — дефолт (`ru`) |
| Learner'у в ЛС (напоминание о повторении) | `learners.interface_locale` (`ru`/`uz`); если значение не поддерживается — по `learners.language_code`: `uz*` → `uz`, иначе `ru` |
| Учителю в ЛС (`/start` для привязанного учителя, `/stats`) | дефолт (`ru`) |
| Неопознанному пользователю в ЛС (`/start`, `/stats`, `/help`) | `language_code` из апдейта по тому же правилу (`uz*` → `uz`, иначе `ru`) |

Числительные: ru — `trans_choice` с формами «слово | слова | слов» (1 / 2–4 / 5+, с учётом 11–14, 21, 22…); uz — без согласования («5 ta so'z»). HTML-сообщения (лидерборд) по-прежнему экранируют пользовательские данные (`htmlspecialchars`) — переводы содержат только статичную разметку. Язык группы задаётся в Filament (**Telegram Groups → Edit → Bot language**) и сохраняется при повторном добавлении бота в группу. Не переводятся: `message` в JSON-ответах TWA API (клиент локализует по `error.code`), логи, имена команд.

Новая строка бота = ключ в `lang/ru/*.php` **и** `lang/uz/*.php` (тест `LocalizationTest` сверяет наборы ключей и плейсхолдеров и запрещает кириллицу в PHP-коде `app/Domain/Telegram` и `app/Jobs`).

### B.5 События бота (не команды)

- **`my_chat_member`** — бот добавлен/удалён из группы.
  - Added → создаём запись `telegram_groups` со статусом `pending`, уведомляем админов.
  - Removed → `status = 'disabled'`, лочим операции.
- **`message` в группе от `new_chat_members`** — в `new_chat_members` есть `id` бота или новых студентов.
  - Для каждого человека (не бота): `LearnerRegistrar::fromTelegramUser` (upsert `learners` по `telegram_user_id`, `acquisition_source = 'group'` у нового) и `GroupMembershipService::join` (`group_members.status = 'active'`; повторное вступление после выхода возвращает `active`). Событие `group.member_joined {via: group}`.
- **`chat_member`** — изменился статус участника группы (боту нужны права админа, `allowed_updates` уже включает `chat_member`).
  - `member` / `administrator` / `creator` / `restricted(is_member)` → `join` (событие `group.member_joined {via: chat_member}`).
  - `left` → `group_members.status = 'left'`, `kicked` → `'removed'` (+ `left_at`, событие `group.member_left`). Learner и его прогресс остаются; новый learner ради выхода не создаётся.
- **`message` в группе от `left_chat_member`** — служебное сообщение о выходе/исключении; приходит и когда бот **не админ** группы (тогда `chat_member` не приходит). Тот же `ChatMemberHandler`: `from.id` = `left_chat_member.id` → `left`, иначе (убрал другой) → `removed`; по `message.date`; бот и неизвестный learner игнорируются, повтор — no-op.
- **Callback query** — пока не используется (весь UX через WebApp), но хендлер на будущее.

### B.6 Событие `update` от WebApp (опционально)

Если TWA вызывает `Telegram.WebApp.sendData(...)`, то в чат приходит апдейт типа `web_app_data` (только в приватных чатах). В нашем дизайне студент общается с API напрямую, а не через `sendData` — это упрощает масштабирование. Но оставляем возможность добавить лёгкие «отправки» (например, «поделиться результатом экзамена»).

### B.7 Rate limiting отправки сообщений
- Не более 20 сообщений в минуту в одну группу.
- Реализация через Redis-based sliding window в `TelegramGateway::sendMessage()`.
- Если лимит достигнут — кладём в очередь `default` с задержкой.

### B.8 Пример хендлера обработки обновлений

```php
// app/Telegram/Handlers/StartTrainingHandler.php
public function __invoke(Nutgram $bot, ?int $stage = null, ?int $lesson = null): void
{
    $group = $this->groupRepo->findActiveByChatId($bot->chatId());
    if (!$group) return; // GroupLock уже отсёк, но на всякий

    $teacher = $this->userRepo->findByTelegramId($bot->userId());
    if (!$group->isTaughtBy($teacher)) {
        $bot->sendMessage('Только учитель этой группы может запускать тренировку.');
        return;
    }

    // если параметры не переданы — показать выбор
    if ($stage === null || $lesson === null) {
        $this->showLessonPicker($bot, $group);
        return;
    }

    $session = $this->trainingService->open($group, $stage, $lesson, $teacher);

    $bot->sendMessage(
        text: "📚 Тренировка по Уроку {$lesson} запущена!",
        reply_markup: $this->buildWebAppButton($session),
    );
}
```

### B.9 Закрытие экзамена (scheduler)

```php
// app/Console/Commands/CloseExpiredExams.php
public function handle(ExamSessionService $service): int
{
    $expired = ExamSession::query()
        ->where('status', 'open')
        ->where('ends_at', '<=', now())
        ->lockForUpdate()
        ->get();

    foreach ($expired as $session) {
        $service->close($session);
        PostLeaderboardJob::dispatch($session->id)->onQueue('default');
    }

    return self::SUCCESS;
}
```

Регистрируем в `app/Console/Kernel.php`:
```php
$schedule->command('exams:close-expired')->everyMinute()->withoutOverlapping();
```

### B.10 Отправка лидерборда в группу

```php
// app/Jobs/PostLeaderboardJob.php — скелет
public function handle(TelegramGateway $tg): void
{
    $session = ExamSession::with('results.learner', 'group')->findOrFail($this->sessionId);
    $text = $this->formatLeaderboard($session);
    $tg->sendMessage($session->group->chat_id, $text, parseMode: 'HTML');
}

private function formatLeaderboard(ExamSession $session): string
{
    $rows = $session->results->sortBy('rank')->map(function (ExamResult $r) {
        $medal = match ($r->rank) { 1 => '🥇', 2 => '🥈', 3 => '🥉', default => "{$r->rank}." };
        return "{$medal} {$r->learner->first_name} — <b>{$r->total_score}</b>";
    })->implode("\n");

    // Заголовок — из lang/{ru,uz}/exam.php по языку группы (см. B.4a); имена экранируются.
    return __('exam.leaderboard.title', ['lesson' => $session->lesson->number], $locale)."\n\n{$rows}";
}
```

## Часть C — Примеры полного цикла

### C.1 Happy path тренировки (E2E)
1. Teacher в группе: `/start_training 4 8`.
2. Bot → `HandleTelegramUpdate` (queue) → `StartTrainingHandler` → создаёт `training_sessions` id=123.
3. Bot отправляет сообщение с WebApp-кнопкой на URL `.../twa/training/123`.
4. Learner нажимает кнопку → открывается TWA.
5. TWA: `POST /api/twa/auth` с `initData`.
6. TWA: `POST /api/twa/training/sessions/123/start`.
7. Цикл: `GET .../next` → показать карточку → `POST .../review` с `quality`.
8. Teacher в группе: `/close_training` (или тренировка просто продолжается — MVP не требует формального закрытия).

### C.2 Happy path экзамена
1. Teacher: `/start_exam 4 8 10`.
2. Создана `exam_sessions` со `ends_at = now() + 10 min`.
3. Сообщение с WebApp-кнопкой.
4. Learner через TWA: `join`, `question`, `answer` × N.
5. Scheduler через минуту после `ends_at` закрывает сессию, считает `exam_results`, диспатчит `PostLeaderboardJob`.
6. Бот постит лидерборд в группу.
