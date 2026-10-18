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

1. **Due**: `next_review_at <= now` в рамках урока, `ORDER BY is_hard DESC, next_review_at, id`.
   Сюда попадают и review-карточки, и learning-карточки, у которых минутный шаг уже истёк.
2. **New**: слово урока без строки в `word_repetitions` (создаётся строка `state = new`,
   `next_review_at = now`) — под `lockForUpdate` в транзакции.
3. **Learn-ahead**: если due и new нет — ближайшая `learning`/`relearning` карточка с
   `next_review_at <= now + learn_ahead_minutes`. Она выдаётся **досрочно**, и в строке
   ставится билет: `served_ahead_at = now`, `served_ahead_token = random(32 hex)`. Токен уходит
   клиенту как `card.serve_token`. Если билет уже выдан и не погашен (карточку досрочно получили
   два «таба»), он **переиспользуется**, а не перевыпускается — погасить его сможет только один ответ.
   Выдача — условный `UPDATE ... WHERE served_ahead_token IS NULL`, проигравший гонку берёт токен победителя.
4. Иначе `null` — урок на сегодня закончен.

`card_kind` в ответе API: `new` (state new) · `learning` (learning/relearning) · `due` (review).
`card.serve_token` — `null` для due/new карточек и для learning-карточек, чей шаг уже истёк (они due).

Таким образом сессия не завершается, пока в уроке есть карточки в минутных шагах
(при `learn_ahead_minutes >= max(шаг)`). Если learn-ahead выключен (`0`), сессия завершится,
а карточка вернётся, когда наступит её срок.

## 5. Приём оценки (`ReviewSubmissionService::submit`)

### 5.1 Порядок проверок
1. Сессия `open` (под `lockForUpdate`) — иначе 410 `session_closed`.
2. Строка `word_repetitions` существует — иначе 409 `card_not_started`.
3. Слово принадлежит уроку сессии — иначе 422 `wrong_lesson`.
4. Карточку **можно оценить** (§5.2) — иначе 409 `card_not_due`.
5. `SpacedRepetitionEngine::applyReview`, запись в `training_reviews`.

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
`App\Domain\Learning\Support\DueRepetitionCriteria::apply()`. Его используют **три** потребителя,
и только его: `ReviewReminderService` (порог и число в сообщении), `ReviewReminderLinkBuilder`
(урок для deep-link) и `LearnerStatsService` → `words_due_today` в `GET /api/twa/me`, чтобы число
на экране совпадало с текстом напоминания. Due считается карточка, если:

- `state = review` и `next_review_at <= now` — выпущенная карточка, срок настал; **или**
- `state in (learning, relearning)` и `next_review_at <= now - abandoned_after_hours` (по умолчанию
  12 ч, env `LEARNING_ABANDONED_AFTER_HOURS`) — **брошенная** карточка: минутный шаг истёк давно,
  студент ушёл из сессии. Без этого правила провал в `relearning` посреди сессии (и legacy-строки
  после backfill, см. ниже) навсегда выпадал бы из напоминаний.

Не считаются: свежие минутные шаги (минуты–часы после тренировки — сессия, возможно, ещё идёт) и
строки в `new` (карточка показана, но ни разу не оценена — «повторять» нечего). Порог по умолчанию —
5 due-слов, не чаще раза в 24 часа.

Расписание: `$schedule->command('repetitions:notify')->dailyAt('18:00');`

**Legacy backfill.** Миграция `add_learning_state_to_word_repetitions` переводит строки
`repetitions = 0 AND last_quality IS NOT NULL` (проваленные в старом SM-2, у них `next_review_at`
был «завтра») в `relearning`. По правилу выше они снова попадают в напоминания через
`abandoned_after_hours` после своего `next_review_at` — при ежедневной рассылке в 18:00 это тот же
день, что и раньше, либо на день позже; слова не теряются.

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
