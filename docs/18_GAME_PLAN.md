# 18. «Караван»: игровой слой, Pro и бесплатная часть — план и задачи

> **Статус:** G1.1–G1.2 сделаны 02.11.2026; G1.3 и решения по G2 приняты владельцем 03.11.2026 (§11). **G2.0 — технический проект домена `Game` — готов 03.11.2026 (§12)**, G2 строится сейчас, до релиза (§11 п. 8); нарезка G2.1–G2.13 и волны — §12.14; открытые вопросы владельцу — §12.16. G3–G4 — после релиза.
> **Основание:** запрос владельца — сделать игру в основном для Pro, а Free дать небольшую её часть.
> **Опирается на:**
> - `15_PRACTICE_GAMES_PLAN.md`, `16_PRACTICE_DESIGN.md` — практика и баттл;
> - `13_PHASE2_DESIGN.md` — лиги, серия, достижения;
> - `06_SPACED_REPETITION.md` — SRS.
>
> **Правила кэша** — `19_REDIS_CACHE_PLAN.md`, **админки** — `20_FILAMENT_PERFORMANCE.md`.

## 1. Что есть сейчас

Игровых частей много:
- четыре режима практики: «Найди пары», «Подбери сочетание», «Собери предложение», «Слушай и пиши»;
- баттл 1 на 1 с другом;
- лиги, челленджи, достижения, серия с заморозкой.

Проблемы:
1. **Части не связаны между собой.** Очки практики и баттла никуда не идут — это сознательное решение (`docs/15` §1). Играть дальше незачем: нет прогресса, наград и цели.
2. **Тариф — «всё или ничего».** `PRACTICE_PLAN` бывает только `all` или `pro`: либо Free получает всю практику, либо ничего. Дать небольшую часть нельзя.
3. **Баттл только с другом,** который онлайн в ту же минуту, — матчей будет мало.
4. **Мало авторского контента:** 90 пилотных заданий (сочетания, предложения, диктант фраз).
5. **Практика ещё не выпущена:** по умолчанию `PRACTICE_ACCESS=off`, этап 8 `docs/15` (публичный выпуск и тарифы) не делался. Это удобный момент выпустить её сразу с правильным делением.

## 2. Игра «Караван» (по-узбекски «Karvon»)

### 2.1. Мир и карта

- **Ученик ведёт караван по Шёлковому пути.**
- **Карта — это его курс:** город — модуль (stage), стоянка — урок.
- **Стоянка открывается, когда урок начат в карточках,** то есть карточки двигают караван. Как и в практике, в игре только слова, уже введённые ученику (`docs/15` §1).
- **Друзья видны на карте:** «Азиз на два города впереди».

### 2.2. Забег

- **Длительность — 2–3 минуты, 8–10 заданий подряд вперемешку:**
  - из самих слов: пары, перевод туда и обратно, на слух, по буквам, пропуск в примере;
  - авторские, где они есть: сочетания, сборка предложения, диктант фраз.
- **Три фляги воды — это жизни.** Ошибка стоит фляги.
- **Бонус** за серию верных ответов.
- **Оценка** — от одной до трёх звёзд.
- **Случайные события:** «песчаная буря» — слово звучит только на слух, «оазис» возвращает флягу, «базар» — подсказка за монеты.
- **Если фляги кончились,** ошибки забега предлагаются в карточках. Учёба не упирается в стену.

### 2.3. Босс города — «Хранитель ворот»

- Собран из самых трудных слов ученика в этом модуле: `lapses` и `is_hard` в `word_repetitions`.
- Нужно набрать 80 % верных ответов.
- Победа открывает город и даёт карточку для шеринга «Я дошёл до Бухары» (`AchievementCardRenderer`).
- Позже босс сможет требовать ответ голосом — связка с «Говорим» (`docs/17`).

### 2.4. Ежедневный караван

Забег на словах, которые сегодня пора повторить. Это игровая разминка; обычное повторение в карточках не ограничено, как и раньше.

### 2.5. Арена базара — PvP без ожидания

- **Дуэль с «призраком»:** это запись забега другого игрока того же уровня на тех же заданиях — его ответы и время.
- **Матч начинается сразу,** ждать соперника онлайн не нужно. Это решает проблему малого числа игроков у молодого продукта.
- **Рейтинг по силе игры (Elo),** а не по числу матчей.
- **Турнир выходного дня** — для Pro.
- **Баттл с живым другом** остаётся как есть (`docs/16`).

### 2.6. Сезоны

- **Сезон длится 6 недель:** новый маршрут-тема («Путешествия», «Еда», «IELTS-темы») и дорожка наград.
- **Опыт сезона идёт от всех занятий:** карточки, практика, забеги, арена, позже — разговоры.
- **Две дорожки:** бесплатная и Pro.
- **Награды Pro-дорожки выдаются задним числом:** при покупке Pro ученик сразу получает всё, что уже заработал.

### 2.7. Монеты и косметика

- **Монеты зарабатываются только игрой и учёбой.** Купить их за деньги нельзя.
- **На монеты** — косметика (верблюды, ковры, шатры, рамки профиля с узорами сюзане) и бустеры только для одиночной игры (подсказка, фляга, замедление времени — в G2 без таймера его место занимает «второй шанс», §12.15 п. 1).
- **Косметика видна** друзьям и соперникам.

### 2.8. Центры: караван класса

Учитель запускает общий караван группы: забеги учеников двигают его вместе, босс собран из самых трудных слов класса, итоги недели уходят в чат группы (как лидерборд экзамена). Это довод для тарифа центра.

## 3. Что бесплатно, а что в Pro

| | Free | Pro |
|---|---|---|
| Ежедневный караван | раз в день | раз в день |
| Забеги по карте | 1 билет в день, плюс билеты за серию и за приглашённых друзей | без лимита |
| Боссы | только первого города | все |
| Бустеры | 1 в неделю | 5 в неделю |
| Арена | 3 дуэли в день | без лимита, плюс турнир выходного дня |
| Баттл с другом | принять — всегда, вызвать — 1 в день | без лимита |
| Практика (4 режима, отдельно от карты) | 2 сессии в день | без лимита |
| Сезон | бесплатная дорожка | плюс Pro-дорожка |
| Косметика | базовая и за достижения | плюс сезонная и эксклюзивная |

- **Остаются бесплатными, как сейчас:** карточки, повторения, курсы, лиги, челленджи, достижения.
- **Все лимиты — в конфиге через env.** Новые фичи entitlement: `game.unlimited_runs`, `game.all_bosses`, `game.arena_unlimited`, `game.tournament`, `season.pro_track`, `practice.unlimited`.
- **`PRACTICE_PLAN` превращается из «всё или ничего» в лимиты.** Сделано в G1.1 (backend, 2026-11-02): при `PRACTICE_PLAN=all` Free получает `PRACTICE_FREE_SESSIONS_PER_DAY` (2) и `PRACTICE_FREE_BATTLES_PER_DAY` (1) в локальный день; `PRACTICE_PLAN=pro` сохранён как более жёсткий режим (Free — 403 `practice_pro_required`). Что считается, окно, атомарность и форма ответа — `docs/16` §3.2–§3.3, `docs/04` A.2, A.16, A.17.
- **Обещание Pro о практике — одно правило на сервере** (`ProPromises`; лендинг, счёт Stars и TWA читают его, не пересчитывая). Строка «Практика [и баттлы] без дневного лимита» говорится читающему, только пока она для него правда:
  1. практика читающему не открыта → строки нет. Публичный текст (лендинг, счёт Stars) читает «для всех»: открыта при `PRACTICE_ACCESS=all`; ученику TWA `/me` отдаёт слово `practice.pro_promise` (`none` / `solo` / `both`), открыта — когда вход в практику виден: `all`, участник пилота, Free при `PRACTICE_PLAN=pro` с чипом Pro;
  2. `PRACTICE_PLAN=pro` (Free практики не имеет вовсе, Pro даёт её целиком) → баттлы включены (`PRACTICE_BATTLE`) — «практика и баттлы», выключены — «практика»;
  3. иначе строка есть, пока у Free есть лимит, который снимает Pro: баттлы включены и у Free есть лимит баттлов — «практика и баттлы»; есть лимит сессий — «практика»; иначе строки нет. «Лимит есть»: пусто — нет, `0` — есть.

## 4. Правила честности

- **Pro покупает больше игры и красивое оформление, но не очки в соревнованиях.** В лиге игра даёт очки по правилам карточек и с дневным потолком; рейтинг арены от числа матчей не растёт. Иначе Free уйдут из лиг, и платным пользователям будет не с кем соревноваться.
- **Принять вызов друга можно всегда:** Pro-пользователь приводит в игру своих Free-друзей.
- **Никаких лутбоксов, платной случайности и покупки монет за деньги:** среди игроков есть школьники.
- **Ошибка в учёбе не наказывается.** Фляги — напряжение внутри забега, а не стена перед карточками.

## 5. Учёба и SRS

- **Правило: игра может только приблизить повторение слова, но никогда не отдалить его.**
  - Ошибка в забеге → слово раньше придёт в карточки.
  - Верный ответ в игре интервал не увеличивает: выбор из вариантов и пары — слабая проверка памяти.
  - Точные правила для SM-2 и FSRS — задача architect (G2.0).
- **Контрольная метрика:** у тех, кто играет, не должны падать точность и выполнение повторений в карточках. Если падают — игра съедает учёбу, лимиты пересматриваются.

## 6. Архитектура

### 6.1. Что переиспользуем

- **Движок практики:** снимок заданий на сервере, непрозрачные id, грейдеры четырёх режимов, идемпотентность по `request_id`, версии заданий, аудио.
- **Баттл:** серверные часы раундов, идея одинакового набора для обоих игроков.
- **Вовлечение:** лиги, достижения, `AchievementCardRenderer`, рефералка (билеты за друзей).
- **Тарифы и аналитика:** `EntitlementResolver`, пейвол, флаги пилота по образцу `PRACTICE_ACCESS`, A/B-группы по образцу FSRS, `ProductEventRecorder`.

### 6.2. Что новое — домен `app/Domain/Game` (черновик; окончательный проект — §12)

| Часть | Что делает |
|---|---|
| Прогресс по карте | Открытые стоянки и звёзды по курсу и уроку, побеждённые боссы |
| Смешанный забег | Сейчас одна сессия практики — один режим; забегу нужен состав из нескольких типов заданий в одном снимке |
| Фляги, звёзды, события | Правила забега на сервере |
| Боссы | Подбор самых трудных слов по `lapses` и `is_hard` с фиксацией набора в снимке |
| Кошелёк монет | Журнал начислений и списаний (только добавление записей) с ключом идемпотентности, по образцу платежей |
| Косметика | Каталог предметов, владение, выбранные предметы профиля |
| Арена | Запись забегов-«призраков», подбор по рейтингу и уровню, рейтинг Elo, турнир |
| Сезон | Опыт, уровни, две дорожки, выдача задним числом |

- **Таблицы-кандидаты:** `game_progress`, `game_runs`, `coin_ledger`, `cosmetic_items`, `learner_cosmetics`, `arena_ghosts`, `arena_ratings`, `seasons`, `season_progress`.
- **Требования:** миграции с `down()`, API через Resource-классы, логика в сервисах.
- **TWA:** экраны «Карта», «Забег», «Босс», «Арена», «Сезон», «Караван» (косметика). Возможности Telegram — полноэкранный режим Mini App, ярлык на главном экране, публикация победы в Stories; сверить с Bot API.

## 7. Безопасность и защита от накруток

| Угроза | Мера |
|---|---|
| Подделка счёта, звёзд или монет клиентом | Всё считает сервер, как уже в практике и баттле; клиент только показывает |
| Двойное начисление монет | У каждого начисления свой ключ (unique), повтор запроса не начисляет повторно; баланс не уходит в минус: строка блокируется на время списания (`FOR UPDATE`) |
| Утечка ответов через «призрака» | Запись соперника раскрывает ответы только после раунда; правильные ответы не уходят клиенту до проверки |
| Бот на арене | Нечеловечески быстрые ответы → матч не идёт в рейтинг |
| Договорные матчи между своими аккаунтами | Рейтинг растёт только за разных соперников, с лимитом на одного соперника в день |
| Накрутка опыта сезона | Дневной потолок на каждый источник |

## 8. Контент

- **Задания из самих слов** (пары, перевод, на слух, по буквам, пропуск в примере) авторов не требуют — их хватит на всю карту.
- **Авторских заданий пока 90.** Их нужно наращивать: черновик пишет ИИ, проверяет человек (`docs/11` §9), загрузка через существующий импорт и редактор практики.

## 9. Задачи

Нумерация — G (game). Исполнители — по `CLAUDE.md`.

| # | Задача | Исполнитель | Готово, когда |
|---|---|---|---|
| **G1** | **Free и Pro для готовой практики и баттла** — быстрые деньги на уже готовом коде | | |
| G1.1 | Лимиты вместо `PRACTICE_PLAN`: Free — 2 сессии практики в день, баттл: принять — всегда, вызвать — 1 в день; Pro — без лимита. Фичи entitlement, счётчик по локальному дню ученика (как квота новых слов) | architect | **Сделано (backend, 2026-11-02):** `practice.free_limits` (`PRACTICE_FREE_SESSIONS_PER_DAY`, `PRACTICE_FREE_BATTLES_PER_DAY`), фича `practice.unlimited`, `PracticeLimits` (подсчёт под `FOR NO KEY UPDATE` строки ученика; вызов на баттл, который не начался не по вине вызвавшего, возвращается), 403 `practice_limit_reached`, событие `practice.limit_reached`, `limits` в `/me`, хабе и 201; `PracticeLimitsTest`, `PracticeLimitsConcurrencyTest`; `docs/16` §3, `docs/04` A.2/A.16/A.17 |
| G1.2 | TWA: остаток на сегодня, чип Pro, пейвол с правильной причиной | coder | **Сделано (TWA, 2026-11-02):** строка остатка Free в хабе и в настройке баттла («Сегодня бесплатно: осталось 1 из 2»); с нулём входы остаются на местах, нажатие открывает пейвол `practice_limit` (лид — по виду и по режиму листа: покупка или «Pro скоро»; вид «только Pro» при лимите 0 — без строки остатка); 403 `practice_limit_reached` в любом месте создания открывает тот же пейвол; ноль баттлов подтверждается чтением `/me`; счётчики обновляются в полночь ученика; пункт Pro «Практика и баттлы без дневного лимита» (или «Практика без дневного лимита», или никакого) — по слову сервера `practice.pro_promise` из `/me`, правило одно с лендингом и счётом Stars (§3); `docs/05` §5a.6; vue-tsc, vitest, сборка зелёные |
| G1.3 | Публичный выпуск практики по `docs/15` этап 8. **Решение владельца 03.11.2026:** на релизе практика и баттлы включены всем (`PRACTICE_ACCESS=all`) с лимитами G1.1; A/B-группа — когда появится аудитория, отдельной задачей | координатор | `PRACTICE_ACCESS=all` в прод-значениях релиза (`PROGRESS.md`, план релиза) |
| **G2** | **Караван** — подробная нарезка, зависимости и волны: **§12.14** | | |
| G2.0 | Технический проект домена `Game`: границы, схема, смешанный снимок, правило SRS §5, кошелёк, порядок блокировок, лига и серия, Free/Pro, API, TWA, админка, караван класса, кэш | architect | ✅ **03.11.2026 — §12**; нарезка G2.1–G2.13 уточнена (§12.14), три вопроса владельцу (§12.16) |
| G2.1 | Схема (5 миграций), модели, `config/game.php`, фичи `game.*`, `GameAccess` + `/me.game`, имена городов | coder | `DatabaseSchemaTest`, `GameAccessTest`, `TwaContractTest` зелёные |
| G2.1m | Карта: открытость стоянок из `word_repetitions`, звёзды, боссы, друзья | coder | Стоянка открывается ровно когда урок начат; бюджет ≤ 10 |
| G2.2a | Ядро забега: снимок, состав, счёт, фляги, события, `RunService`, `GameSrsAdjuster`, PG-гонки | architect | Снимок фиксирует задания; повтор запроса не меняет результат; правило §5 закреплено тестом на SM-2 и FSRS |
| G2.2b | Задания из слов (перевод туда/обратно, на слух, по буквам, пропуск) | coder | Все виды проходят; запретные ключи не утекают |
| G2.2c | API забега: контроллеры, Resources, аудио, контракт, бюджеты | coder | `GameContractTest`, `GameQueryBudgetTest` |
| G2.3 | Ежедневный караван на due-словах | coder | Только due-слова ученика; верные остаются due; один в день |
| G2.4 | Боссы из самых трудных слов, карточка «Я дошёл до …» | coder | Набор фиксируется в снимке; Free — только первый город; карточка один раз |
| G2.5a | Кошелёк монет: леджер, идемпотентность, транзакция завершения забега | architect | PG-тест: параллельные списания не уводят баланс в минус; повтор не начисляет дважды |
| G2.5b | Лавка, бустеры, инвентарь, профиль | coder | Недельный лимит бустеров; кэш каталога с версией |
| G2.6 | Косметика в профиле и у друзей | coder | Предметы видны в профиле и на карте друзей |
| G2.7a–c | Экраны TWA: карта и забег; босс и караван дня; мой караван | coder | 320 px, обе темы, Telegram Back |
| G2.8 | Билеты Free: 1 забег в день, плюс за серию и за друзей (хук рефералки); пейвол `game` | coder | Лимиты из конфига; PG: один билет — один забег |
| G2.9a | Караван класса — ядро (§2.8, §12.11) | architect | Итоги недели уходят в чат ровно один раз (идемпотентно, как лидерборд экзамена); одна активная на группу |
| G2.9b | Караван класса — бот, `/org`, API, TWA | coder | Учитель видит только свои группы |
| G2.10 | Лига и серия: `game_points` с потолком 40, `game_held_at`, размещение из игры | architect | День удержан игрой; Pro не получает очков сверх Free |
| G2.11 | Админка: предметы, забеги, кошелёк с аудитом, караваны, виджет | coder | Бюджеты списков; аудит каждой ручной проводки |
| G2.12 | Контент: авторские задания для карты A1–A2 (черновики Claude → «Проверено» владельцем) | coder, владелец | Пакет импортируется в тесте |
| G2.13 | Ревью G2 и пилот | reviewer, координатор | Блокирующих находок нет |
| **G3** | **Арена** | | |
| G3.1 | Запись «призраков» и подбор соперника по уровню и рейтингу | architect → coder | Призрак не раскрывает ответы до конца раунда |
| G3.2 | Рейтинг Elo и защита от накруток §7 | architect | Тесты на договорные матчи и ботов |
| G3.3 | Турнир выходного дня (Pro) | coder | Итог считается ровно один раз (идемпотентно) |
| **G4** | **Сезоны** | | |
| G4.1 | Опыт сезона со всех источников с потолками; две дорожки; выдача задним числом при покупке Pro | architect → coder | Выдача при покупке идемпотентна (как оплата) |
| G4.2 | Экран сезона и пейвол «уже заработано N наград» | coder | — |
| **G5** | **Ревью и пилот:** ревью каждой волны, A/B, метрики §10 | reviewer, координатор | Блокирующих находок нет |

**Общий DoD:**
- pint, PHPStan level 8, весь Pest зелёные; TWA — vue-tsc, vitest, сборка;
- миграции с `down()`, вывод API — через Resource-классы;
- ru/uz и `I18N_UZ_REVIEW.md`;
- кэш по `docs/19` §3, админка — по `docs/20` §1;
- `PROGRESS.md` и этот документ обновлены.

## 10. Метрики

- **Вовлечение:** доля дневных учеников, которые играют; забегов на игрока в день.
- **Удержание:** D7 и D30 в A/B-группе против контрольной.
- **Деньги:** конверсия из игровых пейволов (`game`, `practice`, `season`) в оплату.
- **Учёба (контрольная метрика):** точность и выполнение повторений в карточках у игроков.

## 11. Решения владельца

**Принято 02.11.2026:** делаем G1. Лимиты Free для практики и баттла — значения из §3 (2 сессии практики в день; баттл: принять — всегда, вызвать — 1 в день), меняются через env. **G1.1 и G1.2 сделаны и приняты 02.11.2026** (ревью и повторное ревью, `PROGRESS.md`); G1.3 — публичный выпуск практики — решён 03.11.2026 (ниже).

**Принято 03.11.2026:**
1. **Сроки.** Следующая цель — релиз. G2 — сейчас, до релиза (решение владельца 03.11.2026, второй опрос; сначала — технический проект G2.0).
2. **G1.3.** На релизе практика и баттлы включены всем (`PRACTICE_ACCESS=all`) с лимитами Free из §3. Учеников ещё нет, поэтому A/B-группа — позже, когда появится аудитория.
3. **Тема** — Шёлковый путь (§2.1).
4. **Лимиты Free** для игры — как в §3: билеты, боссы первого города, бустеры 1 в неделю (Pro — 5), арена 3 дуэли в день; всё через env.
5. **Игра засчитывается в серию и лигу:** забег — занятие дня для серии; очки лиги — по правилам карточек, с дневным потолком (число — architect в G2.0).
6. **Авторские задания** пишет Claude черновиками, «Проверено» в админке ставит владелец или методист (поток `docs/16`: черновик → «Проверено» → опубликовано).
7. **Караван класса для центров** (§2.8) — вместе с G2, задача G2.9.
8. **Пересмотрено в брифе G2.0 (03.11.2026):** G2 строим **сейчас, до релиза**; п. 1 в части «G2 — после релиза» не действует. «Говорим» (`docs/17`) — по-прежнему после релиза.

---

## 12. Технический проект домена `Game` (G2.0, architect, 03.11.2026)

> Статус: **проект, принятый к реализации.** Названия таблиц, колонок, маршрутов, кодов ошибок, ключей конфига и событий — окончательные: исполнители копируют их буквально. Документ обновляется **до** кода каждой задачи (как `docs/16`); при расхождении с кодом и тестами верны они (порядок `docs/00`). Правки других документов, которые делает каждая задача до кода, перечислены в §12.14.

### 12.0 Резюме решений

| Тема | Решение |
|---|---|
| Домен | Один домен `app/Domain/Game` (подпапки `Services/Tasks`, `Services/Runs`, `Services/Economy`, `Services/Class`). Арена (G3) и сезоны (G4) — подпапки того же домена позже; схема им не мешает (§12.2.9) |
| Карта | **Выводится из курса**, своей таблицы «города/стоянки» нет: город = `stages`, стоянка = `lessons` с активными словами. Стоянка **открыта** ⇔ у ученика есть хотя бы одна строка `word_repetitions` по активному слову урока (урок начат в карточках). Имена городов Шёлкового пути — статичный список `lang/{ru,uz}/game.php` по номеру модуля; экран показывает «Самарканд · Модуль 3: Еда» |
| Забег | 8–10 элементов вперемешку: из слов — `translate_fwd`, `translate_back`, `listen`, `spell`, `gap`, один раунд `pairs_round`; авторские — `collocation`, `sentence`, `dictation` через **обработчики практики как есть** (`ModeRegistry`). Полный снимок на сервере (`game_runs.snapshot`), непрозрачные id, события забега (буря, оазис, базар) **зафиксированы в снимке** при создании. Таймера в G2 нет: скорость на счёт не влияет (как в баттле), «замедление времени» из §2.7 заменено бустером «второй шанс» (§12.15) |
| Фляги, звёзды, счёт | 3 фляги; `wrong` и `skipped` стоят флягу, `typo` и подсказка — нет. Третья потеря — `finished/failed`, разбор ошибок и «Повторить в карточках». Звёзды: 0 ошибок — 3, 1 — 2, 2 — 1, провал — 0. Счёт: 10 за верный, +5 за каждый верный с третьего подряд (до +15), `typo` 5, подсказка 0 |
| Босс | 12 самых трудных слов ученика в городе (`is_hard`, `lapses`, промахи игры, EF), без фляг и авторских заданий, победа — ≥ 80 % `correct` (`typo` не считается). Free — боссы первого города (`GAME_FREE_BOSS_STAGES=1`), дальше — 403 `game_pro_required`, пейвол `game` |
| Ежедневный караван | Один забег в локальный день на due-словах (`DueRepetitionCriteria`), только задания из слов; `daily_key` unique как факт БД |
| SRS (§5) | Ошибка (`wrong`, `skipped`, ответ с подсказкой) → одиночный условный `UPDATE word_repetitions SET next_review_at = now, is_hard = true WHERE next_review_at > now` **после commit** ответа + upsert `game_word_misses`. Ничего другого не пишется: ни `state`, `repetitions`, `interval_days`, `easiness_factor`, `lapses`, `stability`, `difficulty`, `last_reviewed_at`, ни `training_reviews`. Верный ответ не пишет ничего. Одно правило для SM-2 и FSRS |
| Кошелёк | `game_wallets.balance` (CHECK ≥ 0) под `FOR UPDATE` + append-only `game_coin_ledger` с unique `idempotency_key`; инвариант `SUM(ledger) = balance` закреплён тестом. Монеты — одинаковые ставки Free и Pro; награда за стоянку — только за первое прохождение и за новые звёзды |
| Серия и лига | Новые колонки `learner_daily_stats.game_runs_count`, `game_points`, `game_held_at`; пишутся в транзакции завершения забега. День **удержан** серией, если `goal_met_at` **или** `game_held_at` **или** `frozen_at`. Очки лиги: верный ответ 2, ошибка 1 (как карточка: `review` 1 + `correct` 1), **потолок 40 очков в день** = два безошибочных забега — Free достигает его ежедневно (караван + бесплатный забег), поэтому Pro очков в лиге не докупает (§12.6) |
| Билеты Free | 1 бесплатный забег по карте в локальный день (`GAME_FREE_RUNS_PER_DAY`) + баланс билетов `game_profiles.tickets` с леджером `game_ticket_ledger`: +1 за каждые 7 дней серии, +3 пригласившему за активированного друга (хук `ReferralService::reward`), потолок 10. Решающая проверка — под замком строки ученика, как `PracticeLimits` |
| Караван класса | `class_caravans` (одна активная на группу — `active_group_id` unique) + вклад участников `class_caravan_members`; запуск учителем командой `/start_caravan` в группе (как `/start_exam`) или из `/org`; итоги недели — `PostClassCaravanSummaryJob` с маркером `summary_posted_at` и `Cache::lock`, как лидерборд экзамена |
| Доступ | `config/game.php`: `GAME_ACCESS=off\|pilot\|all` (пилот — та же `learners.practice_pilot_at`), фичи entitlement `game.unlimited_runs`, `game.all_bosses`, `game.boosters_plus`; все числа — через env |
| Карточка шеринга | Победа над боссом — `AchievementCardRenderer::renderSpec(CardSpec)` (обобщение существующего рендера) с именем города; ЛС через тот же путь, что `SendAchievementJob` (`notifications_log`, один раз) |

Отвергнутые варианты — в конце соответствующих разделов, одной строкой.

### 12.1 Границы и раскладка

```
app/Domain/Game/
├── Enums/        RunKind (stop | boss | daily | class_boss), RunStatus (active | finished), RunFinishReason (completed | failed | expired | abandoned),
│                 TaskKind (translate_fwd | translate_back | listen | spell | gap | pairs_round | collocation | sentence | dictation),
│                 RunEvent (sandstorm | oasis | bazaar), LedgerKind (…§12.2.5), TicketSource (streak | referral | admin | spend), ItemKind (cosmetic | booster),
│                 ItemSlot (camel | carpet | tent | frame), BoosterEffect (hint | extra_flask | second_chance), ItemAvailability (base | achievement | seasonal | exclusive),
│                 ClassCaravanStatus (active | finished | canceled)
├── DTO/          GameAccessState, RunSnapshot, RunState, RunPool (+ RunPoolWord, AuthoredCandidate), RunLimits, RunComposition, ScoringRules, ScoreOutcome, RunView,
│                 RunAnswerOutcome, HintOutcome, TaskGrade, MapView, CityView, StopView, WalletView, ShopView, ProfileView, GameLimitState, ClassCaravanView;
│                 элемент забега — `Practice\DTO\SnapshotElement` (общий с практикой: обработчики практики читают его как есть; отдельного `RunElement` нет — G2.2a);
│                 CardSpec — в Engagement/DTO (общий с достижениями)
├── Exceptions/   GameException (errorCode + httpStatus() + details), ClassCaravanException
├── Support/      RunComposer (состав забега из пулов, детерминирован от seed), RunScoring (счёт, фляги, звёзды, комбо — чистые функции),
│                 BossWordPicker (запрос «самые трудные»), CityNames (номер модуля → ключ имени города), GameWeek (понедельник локальной недели ученика)
└── Services/
    ├── GameAccess                 флаги, пилот, блок `game` в /me (по образцу PracticeAccess)
    ├── GameLimits                 лимиты Free: бесплатный забег дня, билеты, бустеры в неделю; вежливая проверка и claim под замком ученика (образец PracticeLimits)
    ├── MapService                 карта курса: города, стоянки, открытость, звёзды, боссы, друзья на карте
    ├── Runs/RunService            start / find / view / answer / hint / booster / abandon / closeExpired
    ├── Runs/RunSnapshotBuilder    снимок забега: пулы (слова, авторские задания), RunComposer, события, размер ≤ 40 КБ
    ├── Runs/RunFinisher           транзакция завершения: награды, прогресс карты, день ученика (лига/серия), вклад в караван класса; хуки после commit
    ├── Runs/RunEventRecorder      события забега (`game.run_finished`, `game.coins_earned`) — общие для RunService и RunFinisher (G2.5a)
    ├── Runs/DailyCaravanService   due-слова, daily_key
    ├── Runs/BossService           доступность босса, набор слов, победа, карточка шеринга
    ├── Runs/GameSrsAdjuster       правило §5: условный UPDATE word_repetitions + upsert game_word_misses (после commit)
    ├── Tasks/TaskHandler (interface), TaskRegistry, WordTaskHandler (translate_fwd/back, listen, spell, gap), PracticeTaskAdapter (pairs_round, collocation, sentence, dictation → PracticeModeHandler)
    ├── Economy/WalletService      credit / debit (идемпотентно), balance, ledger
    ├── Economy/ShopService        каталог, покупка, инвентарь, экипировка
    ├── Economy/TicketService      grant (streak / referral / admin) / spend, потолок
    ├── Class/ClassCaravanService  start / contribute / boss / finish / cancel
    └── Class/ClassCaravanSummary  текст итогов недели (ru/uz) для PostClassCaravanSummaryJob
app/Jobs/PostClassCaravanSummaryJob, app/Jobs/SendGameCardJob
app/Console/Commands/CloseGameRuns (game:close-expired), CloseClassCaravans (game:class-caravans-close), VerifyGameWallets (game:wallets-verify)
app/Models/GameRun, GameRunAttempt, GameStopProgress, GameCityProgress, GameWordMiss, GameWallet, GameCoinLedgerEntry, GameItem, GameInventoryItem, GameProfile,
           GameTicketLedgerEntry, GameBoosterUse, ClassCaravan, ClassCaravanMember
app/Http/Controllers/Api/Twa/GameMapController, GameRunController, GameWalletController, GameShopController, GameProfileController, ClassCaravanController
app/Http/Resources/Twa/GameMapResource, GameRunResource, GameAnswerResource, GameWalletResource, GameShopResource, GameProfileResource, ClassCaravanResource
app/Filament/Resources/GameItemResource, GameRunResource (read-only), ClassCaravanResource (read-only); LearnerResource → RelationManager «Караван» с действиями начисления
app/Domain/Telegram/Handlers/StartCaravanHandler (/start_caravan, /cancel_caravan), app/Domain/Telegram/Services/ClassCaravanMessenger
```

**Что переиспользуем (точки входа в другие домены — и только они):**

| Нужно | Откуда | Как |
|---|---|---|
| Введённые слова, авторские задания, пригодность | `Practice\Services\PracticeMaterialService::introducedWords / eligibleItems / lesson / course` | те же пулы, что у практики; `MaterialScope` курса/урока плюс новый `MaterialScope::forStage(Course, Stage)` (урок `null`, фильтр по `stages.id`; небольшое расширение Practice в G2.2a) |
| Пары, сочетание, сборка, диктант фраз | `Practice\Services\Modes\ModeRegistry` → `PracticeModeHandler::build / project / grade / reveal / mistakeLabel` | через `PracticeTaskAdapter`; элементы снимка — те же `SnapshotElement`, ключи те же, что в `docs/16` §2.8 |
| Нормализация, перемешивание, id | `Practice\Support\PracticeTextNormalizer`, `TranslationVariants`, `SeededShuffle`, `OpaqueId`; `Learning\Services\SpellingGrader` (`spell`), `GapFillBuilder` (`gap`) | как библиотеки, без записи |
| Аудио слова | `Content\Services\WordAudioService::storedPath / read` | `listen` только для слов с готовым файлом (`words.audio_path`); стрим по непрозрачному id элемента, как `PracticeAudioService::clipFor`; клипы фраз — `PracticeAudioService` |
| Переводы, дерево курса, доступность | `Content\Services\WordTranslationResolver`, `CourseCatalogService::isAvailable / courseTree / title` | |
| Тариф | `Billing\Services\EntitlementResolver::for` (кэш) | фичи `game.*` |
| Серия, лига, достижения | `Learners\Services\StreakService::apply`, `Engagement\Services\LeagueService::place`, `AchievementService::unlock / evaluate` | только **после commit** транзакции завершения (как `DailyStatsService`) |
| Друзья | `Engagement\Services\FriendshipService` (принятые пары) | друзья на карте |
| Рефералы | `Engagement\Services\ReferralService::reward` | хук выдачи билетов (§12.7.3) |
| Карточка шеринга | `Engagement\Services\AchievementCardRenderer` + `AchievementSender` | обобщение `renderSpec(CardSpec, locale)`; существующий `render(Achievement, locale)` остаётся обёрткой |
| Аналитика | `Analytics\Services\ProductEventRecorder` | после commit |
| Группы и учитель | `Learning\Services\TeacherAuthority::canTeach`, `TelegramGroup`, `group_members`, `PanelDataScope` (/org), `LocaleResolver::forGroup` | караван класса |

**Чего игра не делает:** не вызывает `TrainingFlowService`, `ReviewSubmissionService`, `CardPicker`, `DailyStatsService::recordReview`, `SpacedRepetitionEngine`, `Sm2Scheduler`, `FsrsScheduler`; не пишет `training_sessions`, `training_reviews`, `practice_*`, `battles`; в `word_repetitions` трогает **только** `next_review_at`, `is_hard`, `updated_at` (§12.4). Инвариант — `GameIsolationTest` (по образцу `PracticeIsolationTest`: контрольные суммы таблиц до и после полного прогона, список таблиц с записью).

Отвергнуто: отдельные домены `Economy` / `ClassGame` — кошелёк и караван класса существуют только ради игры и делят с ней транзакцию завершения забега; выносить их — лишние границы без выигрыша.

### 12.2 Схема БД

Стиль — `docs/03` §2 и `docs/16` §2: `id bigint PK`, `created_at`/`updated_at` (если не сказано иное), FK с явным `ON DELETE`, перечисления — `varchar` + CHECK только на PostgreSQL (`if (DB::getDriverName() === 'pgsql')`), инварианты «один активный» — nullable unique-колонки (работают и на SQLite), `uuid` — `$table->uuid()`, `jsonb` — `$table->jsonb()`.

#### 12.2.1 `game_runs` — забег

| Колонка | Тип | Описание |
|---|---|---|
| id | bigint PK | |
| learner_id | bigint FK → learners, ON DELETE CASCADE | |
| active_learner_id | bigint nullable **unique** | `= learner_id`, пока `status = active`; **один активный забег на ученика** как факт БД |
| kind | varchar(12) | `stop` / `boss` / `daily` / `class_boss` |
| course_id | bigint FK → courses, ON DELETE CASCADE | |
| stage_id | bigint nullable FK → stages, ON DELETE SET NULL | город (`stop`, `boss`); `NULL` у `daily` |
| lesson_id | bigint nullable FK → lessons, ON DELETE SET NULL | стоянка (`stop`); у `boss`/`daily` — `NULL` |
| class_caravan_id | bigint nullable FK → class_caravans, ON DELETE SET NULL | забег шёл во время активного каравана группы ученика (любой `kind`); у `class_boss` обязателен |
| daily_key | varchar(32) nullable **unique** | `"{learner_id}:{local_date}"` у `kind = daily` — **один ежедневный караван в локальный день** как факт БД; `NULL` у остальных |
| class_boss_key | varchar(40) nullable **unique** | `"{class_caravan_id}:{learner_id}"` у `kind = class_boss` — одна попытка босса класса на ученика и караван |
| local_date | date NOT NULL | локальный день ученика в момент старта (`LearnerClock::localDate`); им же считаются лимиты дня и строка `learner_daily_stats` |
| status | varchar(10) default 'active' | `active` / `finished` |
| finish_reason | varchar(12) nullable | `completed` (все элементы отвечены) / `failed` (фляги кончились) / `expired` (TTL) / `abandoned` (ученик вышел) |
| free_slot | varchar(8) nullable | чем оплачен забег `stop`/`boss` у Free: `daily` (бесплатный забег дня) / `ticket`; `NULL` у Pro, у `daily`-каравана и `class_boss` |
| snapshot | jsonb NOT NULL | выдача (§12.3); серверный, наружу — только через `project()` |
| state | jsonb NOT NULL default '{}' | прогресс по элементам; меняется только под `FOR UPDATE` |
| total | smallint | число элементов |
| answered, correct, typos, wrong, hints | smallint default 0 | `answered = correct + typos + wrong + hints`; `correct` — верные **без** подсказки; `hints` — ответы после подсказки (базар или бустер), верные и нет; `wrong` включает `skipped` |
| flasks | smallint | текущее число фляг (старт — `run.flasks`, бустер «фляга» +1) |
| flasks_max | smallint | 0 у боссов |
| combo_best | smallint default 0 | лучшая серия верных |
| score | int default 0 | |
| stars | smallint nullable | 0..3 после завершения |
| coins_awarded | int default 0 | монет начислено за забег (0 до завершения) |
| league_points | smallint default 0 | очков лиги зачтено этим забегом **после потолка** (аналитика честности) |
| srs_pulled | smallint default 0 | слов, которым приблизили повторение |
| locale | varchar(5) | |
| expires_at | timestamp NOT NULL | `started_at + run.ttl_minutes` (30) |
| started_at, finished_at | timestamp | |

**Индексы:** `active_learner_id` unique; `daily_key` unique; `class_boss_key` unique; `(learner_id, status, started_at)`; `(learner_id, kind, local_date)` — лимит дня и «сыграно сегодня»; `(status, expires_at)` — планировщик; `(class_caravan_id, status)`; `(course_id, kind, finished_at)` — отчёты.
**PG CHECK:** `game_runs_kind_check`, `game_runs_status_check`, `game_runs_active_shape_check ((status = 'active') = (active_learner_id IS NOT NULL))`, `game_runs_active_owner_check (active_learner_id IS NULL OR active_learner_id = learner_id)`, `game_runs_daily_shape_check ((kind = 'daily') = (daily_key IS NOT NULL))`, `game_runs_class_boss_shape_check ((kind = 'class_boss') = (class_boss_key IS NOT NULL))`, `game_runs_flasks_check (flasks BETWEEN 0 AND flasks_max)`.

**Состояния:** `active → finished/completed` (принят последний элемент) · `active → finished/failed` (третья потеря фляги — забег закрывается тем же ответом) · `active → finished/expired` (`expires_at <= now`: лениво при любом обращении или `game:close-expired` каждые 15 мин) · `active → finished/abandoned` (`POST …/abandon`). Награды, прогресс карты и день ученика пишутся **только** при `completed` и `failed` (§12.5.3); `expired`/`abandoned` ничего не дают (приближения SRS по уже данным ответам остаются — это честные сигналы). Старт при активном забеге → 409 `game_run_active {run_id, kind, progress}`; старт **той же** стоянки (тот же `kind`, урок, город, ключи) возвращает идущий забег как есть — вторая вкладка и двойное нажатие получают тот же `id` (G2.2a, `GameRunConcurrencyTest`); с `replace_active: true` старый закрывается `abandoned` в той же транзакции («Ещё раз» на той же стоянке — новый забег).

#### 12.2.2 `game_run_attempts` — журнал принятых запросов

Копия `practice_attempts` (`docs/16` §2.6) для забега: `run_id FK → game_runs CASCADE`, `learner_id FK CASCADE`, `request_id uuid`, `element_id varchar(16)`, `kind varchar(8)` — `answer` / `pair` / `skip` / `hint` / `booster`, `payload jsonb`, `verdict varchar(8) nullable` — `correct` / `typo` / `wrong` / `skipped`; `NULL` у `hint`/`booster`, `is_first boolean`, `result jsonb` (тело ответа без `run` для побайтово одинакового повтора), `time_spent_ms int nullable`, `accepted_at timestamp`, `created_at` (без `updated_at`). **Индексы:** unique `(run_id, request_id)`; `(run_id, element_id)`; `(learner_id, accepted_at)`. Один `request_id` — один `kind` (иначе 409 `request_id_reused`, как в практике).

#### 12.2.3 Прогресс по карте

`game_stop_progress` — стоянка (урок):

| Колонка | Тип | Описание |
|---|---|---|
| id | bigint PK | |
| learner_id | bigint FK → learners, ON DELETE CASCADE | |
| lesson_id | bigint FK → lessons, ON DELETE CASCADE | |
| course_id | bigint FK → courses, ON DELETE CASCADE | денормализация для карты и друзей (один запрос по курсу) |
| stage_id | bigint FK → stages, ON DELETE CASCADE | |
| stars | smallint default 0 | лучший результат 0..3 |
| best_score | int default 0 | |
| runs_count | smallint default 0 | завершённых (`completed`/`failed`) забегов |
| first_cleared_at | timestamp nullable | первый забег с ≥ 1 звездой |
| last_run_at | timestamp | |

**Индексы:** unique `(learner_id, lesson_id)`; `(learner_id, course_id)`; `(course_id, stage_id, learner_id)` — друзья на карте. Строка появляется при первом **завершённом** забеге стоянки; «открыта» стоянка или нет — **не хранится**, выводится из `word_repetitions` одним запросом на карту: `SELECT words.lesson_id, COUNT(*) FROM word_repetitions JOIN words … JOIN lessons … JOIN stages … WHERE learner_id = ? AND stages.course_id = ? AND words.is_active GROUP BY words.lesson_id` (тот же запрос, что `CourseCatalogService::lessonsDone`, без условия `repetitions >= 1`).

`game_city_progress` — город (модуль):

| Колонка | Тип | Описание |
|---|---|---|
| id | bigint PK | |
| learner_id | bigint FK → learners, ON DELETE CASCADE | |
| stage_id | bigint FK → stages, ON DELETE CASCADE | |
| course_id | bigint FK → courses, ON DELETE CASCADE | |
| boss_attempts | smallint default 0 | |
| boss_defeated_at | timestamp nullable | первая победа; «город открыт» |
| boss_best_accuracy | smallint default 0 | 0..100 |
| boss_wins | smallint default 0 | |
| shared_at | timestamp nullable | ученик открыл «Поделиться» карточкой города |

**Индексы:** unique `(learner_id, stage_id)`; `(learner_id, course_id)`.

#### 12.2.4 `game_word_misses` — промахи игры по слову (для §5 и боссов)

| Колонка | Тип | Описание |
|---|---|---|
| id | bigint PK | |
| learner_id | bigint FK → learners, ON DELETE CASCADE | |
| word_id | bigint FK → words, ON DELETE CASCADE | |
| misses | int default 0 | ошибок в игре (`wrong`/`skipped`/с подсказкой), всего |
| last_missed_at | timestamp | |
| last_pulled_at | timestamp nullable | когда последний раз игра реально приблизила `next_review_at` |

**Индексы:** unique `(learner_id, word_id)`. Пишет только `GameSrsAdjuster` (`upsert()` билдера по `(learner_id, word_id)`, без raw). Отвергнуто: класть счётчик в `word_repetitions.meta` — обновление JSON различается по драйверам и не индексируется под подбор босса.

#### 12.2.5 Кошелёк монет

`game_wallets`:

| Колонка | Тип | Описание |
|---|---|---|
| id | bigint PK | |
| learner_id | bigint FK → learners, ON DELETE CASCADE, **unique** | |
| balance | int NOT NULL default 0 | **PG CHECK `game_wallets_balance_check (balance >= 0)`** — страховка; основной механизм — `FOR UPDATE` (§12.5) |
| earned_total | bigint default 0 | всего начислено (профиль, достижения) |
| spent_total | bigint default 0 | |

Строка создаётся лениво при первом обращении (`insertOrIgnore` + перечитывание под замком).

`game_coin_ledger` — только добавление:

| Колонка | Тип | Описание |
|---|---|---|
| id | bigint PK | |
| learner_id | bigint FK → learners, ON DELETE CASCADE | |
| amount | int NOT NULL | `> 0` начисление, `< 0` списание; **PG CHECK `amount <> 0`** |
| kind | varchar(24) | `run_reward` / `boss_reward` / `daily_reward` / `class_reward` / `bazaar_hint` / `item_purchase` / `admin_grant` / `admin_revoke` / `refund` (G3/G4 добавят свои миграцией CHECK) |
| idempotency_key | varchar(96) NOT NULL **unique** | `run:{run_id}:reward`, `run:{run_id}:hint:{element_id}`, `shop:{learner_id}:{request_id}`, `admin:{audit_log_id}`, `class:{caravan_id}:{learner_id}` — вторая запись с тем же ключом невозможна |
| ref_type, ref_id | varchar(24) nullable, bigint nullable | ссылка на забег / предмет / аудит |
| balance_after | int NOT NULL | баланс после проводки (сверка) |
| meta | jsonb default '{}' | `{stars, run_kind, item_key, reason, by_user_id}` |
| created_at | timestamp | без `updated_at` |

**Индексы:** `idempotency_key` unique; `(learner_id, created_at)`; `(kind, created_at)` — отчёты. **Инвариант:** `SUM(amount) WHERE learner_id = ? = game_wallets.balance` — проверяется тестами после каждого сценария и командой `game:wallets-verify` (отчёт в лог, без правок).

#### 12.2.6 Предметы, инвентарь, профиль, билеты, бустеры

`game_items` — каталог (пишет админка):

| Колонка | Тип | Описание |
|---|---|---|
| id | bigint PK | |
| key | varchar(48) **unique** | `camel_bactrian`, `carpet_bukhara`, `booster_hint` … (`/\A[a-z0-9][a-z0-9_]{0,47}\z/`) |
| kind | varchar(10) | `cosmetic` / `booster` |
| slot | varchar(10) nullable | `camel` / `carpet` / `tent` / `frame` — только у косметики |
| effect | varchar(16) nullable | бустер: `hint` / `extra_flask` / `second_chance` |
| price_coins | int NOT NULL default 0 | 0 — не продаётся (выдаётся за достижение или по умолчанию) |
| availability | varchar(12) | `base` / `achievement` / `seasonal` / `exclusive` — `exclusive` и `seasonal` требуют фичи `game.all_bosses` (Pro) **в момент покупки**; купленное остаётся после конца Pro |
| achievement_key | varchar(32) nullable | предмет выдаётся при открытии достижения (`AchievementKey`) |
| season_id | bigint nullable | **задел G4**, без FK до появления `seasons` |
| status | varchar(10) default 'draft' | `draft` / `published` / `archived` |
| sort | smallint default 0 | |
| title | jsonb | `{ru, uz}` |
| description | jsonb nullable | `{ru, uz}` |
| asset | varchar(64) nullable | имя ассета TWA из белого списка (`resources/twa/src/assets/game/*`), не URL |

**Индексы:** `key` unique; `(kind, status, sort)`. **PG CHECK:** `game_items_kind_shape_check (((kind = 'cosmetic') = (slot IS NOT NULL)) AND ((kind = 'booster') = (effect IS NOT NULL)))`.

`game_inventory` — владение: `learner_id FK CASCADE`, `item_id FK → game_items CASCADE`, `qty int default 1` (бустеры расходуются; косметика всегда 1), `acquired_at`, `source varchar(12)` — `purchase` / `achievement` / `admin` / `default`; unique `(learner_id, item_id)`; CHECK `qty >= 0`.

`game_profiles` — игровой профиль (одна строка на ученика, создаётся лениво):

| Колонка | Тип | Описание |
|---|---|---|
| id | bigint PK | |
| learner_id | bigint FK → learners, ON DELETE CASCADE, **unique** | |
| equipped | jsonb default '{}' | `{camel: item_id, carpet: item_id, tent: …, frame: …}` — только предметы из инвентаря (проверка сервиса) |
| tickets | smallint NOT NULL default 0 | баланс билетов; CHECK `tickets >= 0`; меняется только под замком `learners` (§12.5) |
| tickets_earned_total | int default 0 | |
| stops_cleared, cities_cleared | smallint default 0 | денормализация для профиля и друзей; пересчитываются в транзакции завершения |
| last_run_at | timestamp nullable | |

`game_ticket_ledger` — только добавление: `learner_id FK CASCADE`, `delta smallint` (`+n`/`-1`), `source varchar(12)` — `streak` / `referral` / `admin` / `spend` (бесплатный забег дня в леджере не отражается: он считается по `game_runs`), `idempotency_key varchar(96) unique` (`streak:{learner_id}:{local_date}`, `referral:{referral_id}`, `spend:run:{run_id}`, `admin:{audit_log_id}`), `run_id nullable FK → game_runs SET NULL`, `created_at`. Индексы: ключ unique; `(learner_id, created_at)`.

`game_booster_uses` — расход бустеров под недельный лимит: `learner_id FK CASCADE`, `run_id FK → game_runs CASCADE`, `item_id FK → game_items`, `element_id varchar(16) nullable`, `used_at`, `week_start date` (понедельник локальной недели ученика, `GameWeek`). Индексы: `(learner_id, week_start)`; unique `(run_id, item_id, element_id)` — один бустер одного вида на элемент (`extra_flask` и `second_chance` — `element_id = NULL`, один на забег).

#### 12.2.7 `learner_daily_stats` — правка на месте (новая forward-миграция)

| Колонка | Тип | Описание |
|---|---|---|
| game_runs_count | smallint default 0 | завершённых (`completed`/`failed`) забегов за локальный день |
| game_points | smallint default 0 | очки лиги от игры **после потолка** `game.league.daily_cap` (40); пишутся в транзакции завершения под `FOR UPDATE` строки дня |
| game_held_at | timestamp nullable | первый завершённый забег дня — день **удержан** серией (§12.6); ставится один раз, пока `NULL` |

`down()` — drop трёх колонок. Формула лиги (`LeagueService::openStandings`) получает `+ s.game_points` (вес 1, без конфига: потолок уже применён при записи); ключ кэша `league:standings:v2:` (формат значения тот же, версия поднята, потому что меняется смысл очков). `StreakService::heldRowsBetween` и `current()` считают день удержанным ещё и по `game_held_at`; удержанный игрой день **считается** в серию (`+1`), как день с целью, — он не «мост».

#### 12.2.8 Караван класса (G2.9)

`class_caravans`:

| Колонка | Тип | Описание |
|---|---|---|
| id | bigint PK | |
| telegram_group_id | bigint FK → telegram_groups, ON DELETE CASCADE | |
| organization_id | bigint nullable FK → organizations, ON DELETE SET NULL | центр группы на момент старта |
| course_id | bigint FK → courses, ON DELETE CASCADE | курс группы (`telegram_groups.course_id`, иначе `default`) |
| started_by_user_id | bigint nullable FK → users, ON DELETE SET NULL | учитель |
| active_group_id | bigint nullable **unique** | `= telegram_group_id`, пока `status = active` — **один активный караван на группу** |
| status | varchar(10) | `active` / `finished` / `canceled` |
| starts_at, ends_at | timestamp NOT NULL | `ends_at = starts_at + class.days (7) суток` |
| target_stars | int NOT NULL | цель каравана: `max(class.min_target (12), members_at_start × class.stars_per_member (6))` |
| stars_total, runs_total | int default 0 | сумма вкладов (денормализация; источник — `class_caravan_members`) |
| boss_snapshot_words | jsonb nullable | id слов босса класса (12 самых трудных по группе), фиксируются **при старте** |
| boss_unlocked_at | timestamp nullable | `stars_total >= target_stars` |
| boss_defeated_at | timestamp nullable | побед ≥ `ceil(участников с ≥ 1 забегом / 2)` |
| summary_posted_at | timestamp nullable | итоги недели ушли в чат — маркер «ровно один раз» |
| finished_at | timestamp nullable | |
| meta | jsonb default '{}' | `{members_at_start, canceled_reason, summary_skipped}` |

**Индексы:** `active_group_id` unique; `(status, ends_at)` — планировщик; `(telegram_group_id, starts_at)`. **PG CHECK:** `class_caravans_active_shape_check ((status = 'active') = (active_group_id IS NOT NULL))`.

`class_caravan_members` — вклад: `class_caravan_id FK CASCADE`, `learner_id FK CASCADE`, `runs smallint default 0`, `stars smallint default 0`, `best_score int default 0`, `boss_won_at timestamp nullable`, `last_run_at`; unique `(class_caravan_id, learner_id)`; индекс `(class_caravan_id, stars, runs)` — таблица вклада. Строка создаётся при первом завершённом забеге участника в окне каравана.

#### 12.2.9 Задел под G3 (арена) и G4 (сезоны)

- «Призрак» для арены — это `game_runs.snapshot` + `game_run_attempts` (`accepted_at`, `time_spent_ms`, вердикты) чужого забега: добавлять ничего не нужно, кроме таблиц `arena_*` со ссылкой `ghost_run_id FK → game_runs`. Поэтому **снимки и попытки завершённых забегов не чистятся** (ретенция — вопрос G3).
- `game_runs.kind` и `game_coin_ledger.kind` — открытые перечисления (CHECK расширяется миграцией G3/G4).
- `game_items.season_id`, `availability = seasonal` — колонки есть, FK и логика — G4.
- Опыт сезона — отдельная таблица `season_progress` поверх тех же `learner_daily_stats.game_*` и событий; в G2 не проектируется.
- **Не делается в G2:** Elo, рейтинги, таблицы сезонов, «выдача задним числом».

#### 12.2.10 Миграции (по порядку; все с `down()`, проверяются `DatabaseSchemaTest`)

| # | Файл | Что |
|---|---|---|
| 1 | `2026_11_05_100001_create_game_runs_tables` | `game_runs` (без FK на `class_caravans` — добавляется в №5), `game_run_attempts`; PG CHECK |
| 2 | `2026_11_05_100002_create_game_progress_tables` | `game_stop_progress`, `game_city_progress`, `game_word_misses` |
| 3 | `2026_11_05_100003_create_game_economy_tables` | `game_wallets`, `game_coin_ledger`, `game_items`, `game_inventory`, `game_profiles`, `game_ticket_ledger`, `game_booster_uses`; PG CHECK |
| 4 | `2026_11_05_100004_add_game_columns_to_learner_daily_stats_table` | три колонки §12.2.7 |
| 5 | `2026_11_05_100005_create_class_caravans_tables` | `class_caravans`, `class_caravan_members`; FK `game_runs.class_caravan_id` (на SQLite — пересборкой таблицы, как `2026_10_30_100010`) |

Порядок таблиц в `down()` — обратный; `DatabaseSchemaTest` считает `--step` от имени файла. `down()` №5 перед удалением таблиц снимает FK и обнуляет `game_runs.class_caravan_id` (иначе повторный `up()` встретит id без строки).

### 12.3 Снимок забега

#### 12.3.1 Состав

Параметры — `config/game.php` `run.*` (§12.7.1): `size_min` 8, `size_max` 10, `min_elements` 6, `authored_max` 3, `pairs_rounds_max` 1, `pairs_per_round` 4.

**Пулы** (`RunSnapshotBuilder`, до транзакции, без блокировок):

1. **Слова** — `PracticeMaterialService::introducedWords(scope, learner, locales)` для стоянки (урок). Если их меньше `min_elements` — пул расширяется до **города** (`MaterialScope::forStage`: все введённые слова модуля; в снимок пишется `scope_widened: true`, экран говорит «слова этого города»). Меньше `min_elements` и так — 422 `game_not_enough_material {available, required, reason: no_words}`.
2. **Авторские задания** — `eligibleItems(scope, type, learner)` по трём типам для урока, затем модуля; берутся не больше `authored_max`, не больше одного `dictation`; все целевые слова введены ученику (гарантия `eligibleItems`); диктант — только с `audio_status = ready` и непустым файлом (проверка до транзакции, как `ReplaceableElements::playableSpares`).
3. **Дистракторы** для `translate_*` и `listen` — один запрос на забег: до 40 активных слов того же курса и до 40 слов опубликованных публичных курсов платформы (`Course::scopePublicPlatform`, тот же белый список, что у `ExercisePromptBuilder` и экзамена) с переводами на локали снимка.

`RunComposer::compose(RunPool $pool, string $seed, RunLimits $limits): RunComposition {elements: list<SnapshotElement>, events}` — чистая функция, детерминированная от `seed` (`SeededShuffle`; непрозрачные id элементов и вариантов — случайные, как в практике: детерминирован **состав и порядок**, не строки id). Пул (`RunPool`) собирает `RunSnapshotBuilder` до транзакции: слова с их фактами (`RunPoolWord`: путь озвучки — только текущая запись слова, как у диктанта; `GapFill` по `example`; ключи однозначности `TranslationVariants::clashKeys`), готовые авторские элементы (`AuthoredCandidate`: элемент, построенный обработчиком практики через суженный `MaterialScope` с `RetryMaterial(itemIds)`, и его целевые слова), дистракторы. Раунд пар собирает сам композер статикой `PairsMode::roundElement()` (та же раскладка, что у практики); элементы из слов — `WordTaskHandler::build*()` (чистые функции от пула и seed):

1. Размер `N = clamp(слов + авторских, size_min, size_max)`, но не меньше `min_elements`, если материала хватает только на него.
2. Авторские занимают места первыми (до `authored_max`); их целевые слова помечаются «использовано».
3. Если неиспользованных слов с **однозначными** переводами ≥ `pairs_per_round` (проверка — `TranslationVariants`, как `PairsMode`) **и слов достаточно, чтобы размер `N` остался достижим** (раунд складывает 4 слова в один элемент: `неиспользованных − 4 + 1 + авторских ≥ N`; стоянка из 8 слов играет 8 заданий из слов, из 13 — ещё и пары) — один раунд пар из 4 слов; они помечаются.
4. Оставшиеся места — задания из слов, по одному слову на элемент; вид — по кругу `translate_fwd → translate_back → listen → spell → gap`, пропуская невозможные для слова: `listen` без `audio_path`; `gap` без формы слова в `example` (`GapFillBuilder::build() === null`); `translate_*`/`listen` без трёх дистракторов, не конфликтующих с правильным переводом ни в одной локали (`TranslationVariants::conflicts`). Дистракторы выбираются хэшем от `(seed, кандидат)`: сначала слова того же урока, затем курса, затем публичных курсов. Если ни один вид не подходит — слово пропускается. Одинаковые виды подряд избегаются, если есть выбор.
5. Порядок показа — `SeededShuffle` всех элементов; первый элемент — всегда из слов (разогрев), авторские не стоят первыми.
6. **События** (`events.chance` 0.6 — не больше одного на забег; `boss`/`class_boss` — без событий): по seed выбирается `sandstorm` (один элемент вида `translate_fwd`/`translate_back` со словом, у которого есть аудио, **собирается как `listen`**, в элементе `event: sandstorm`), `oasis` (после элемента в середине забега — `+1 фляга`, если `< flasks_max`) или `bazaar` (на элементе в середине можно купить подсказку за `events.bazaar_hint_coins` (5)). Событие записывается в `snapshot.events: [{at, type}]` и показывается клиенту **только когда элемент стал текущим** (`project()` текущего элемента несёт `event`).

**Ежедневный караван** (`kind = daily`): пул — due-слова ученика (`DueRepetitionCriteria::apply` — то же определение, что у напоминаний и `/today`), трудные первыми (`is_hard DESC, next_review_at`), до `size_max`; меньше `min_elements` — добираются введённые слова с самым старым `last_reviewed_at`; введённых меньше `min_elements` — 422 `game_not_enough_material {reason: no_words}`. Только задания из слов, без авторских и без пар.

**Босс** (`kind = boss`, `class_boss`): `boss.size` (12) слов из `BossWordPicker` (§12.3.4), виды `translate_fwd` / `translate_back` / `listen` / `spell` / `gap` по кругу, без пар, авторских и событий; `flasks_max = 0` (фляги не показываются), побеждён при `correct / total ≥ boss.required_accuracy` (0.8).

#### 12.3.2 Форма снимка (`game_runs.snapshot`)

```jsonc
{ "v": 1, "kind": "stop", "locales": ["ru"], "course_slug": "english-a1-a2", "course_title": {"ru": "…"},
  "stage_number": 3, "stage_title": {"ru": "Еда", "uz": "…"}, "city": "samarkand", "lesson_number": 2, "lesson_titles": {"ru": "…"},
  "scope_widened": false, "flasks_max": 3,
  "events": [ {"at": 5, "type": "oasis"} ],
  "elements": [
    { "id": "eK7qM2x9Ab", "kind": "translate_fwd", "word_id": 1007, "prompt": "mother", "audio": true,
      "options": [{"id": "oA…", "text": {"ru": "мама", "uz": "ona"}}, …], "correct_option_id": "oC…", "translation": {"ru": "мама", "uz": "ona"} },
    { "id": "eZ3…", "kind": "translate_back", "word_id": 1012, "prompt": {"ru": "хлеб", "uz": "non"}, "options": [{"id": "o…", "text": "bread"}, …], "correct_option_id": "o…" },
    { "id": "eL1…", "kind": "listen", "word_id": 1003, "audio_disk": "public", "audio_path": "audio/7a….mp3", "options": [{"id": "o…", "text": "water"}, …], "correct_option_id": "o…", "event": "sandstorm" },
    { "id": "eS8…", "kind": "spell", "word_id": 1020, "prompt": {"ru": "яблоко", "uz": "olma"}, "expected": "apple", "answer_length": 5 },
    { "id": "eG2…", "kind": "gap", "word_id": 1031, "masked": "I ____ tea every morning.", "expected": ["drink"], "translation": {"ru": "пить", …} },
    { "id": "eP5…", "kind": "pairs_round", … },                       // как docs/16 §2.8 (PairsMode)
    { "id": "eC4…", "kind": "collocation", "item_id": 12, "version_id": 40, … },   // как docs/16 §2.8 (CollocationMode)
    { "id": "eD9…", "kind": "dictation", "source": "phrase", … }      // как docs/16 §2.8 (DictationMode)
  ] }
```

Элементы практики хранятся **без изменений формы** — их проецируют и проверяют обработчики практики. Ключи, которые **не** уходят клиенту (контрактный тест с запретными ключами `CONTRACT_FORBIDDEN_GAME_KEYS`): `correct_option_id`, `expected`, `pairs`, `correct_ids`, `accepted`, `audio_path`, `word_id`, `version_id`, `item_id`, `events` целиком (кроме `event` текущего элемента). Размер — `RunSnapshot::MAX_BYTES` 40 КБ (`RunSnapshotSizeTest` на худшем валидном составе с запасом ≥ 25 %).

**Ответы и проверка** (`TaskHandler::grade(RunElement, array $answer, RunState): TaskGrade {verdict, reveal, points, srsErrorWordIds}`):

| Вид | Ответ | Вердикт |
|---|---|---|
| `translate_fwd`, `translate_back`, `listen` | `{option_id}` или `{skip: true}` | `correct` / `wrong` / `skipped`; неизвестный id — 422 `unknown_option_id`; `translate_*` / `listen` — 4 варианта (верный + 3 дистрактора, не конфликтующих ни в одной локали) |
| `spell` | `{text}` (≤ 100) или `{skip: true}` | `SpellingGrader::grade(expected, text)`: `quality 5` → `correct`, `4` → `typo`, иначе `wrong`; `typo` не входит в `correct`, но и не стоит фляги |
| `gap` | `{text}` или `{skip: true}` | нормализованный текст ∈ `expected` (формы слова, `GapFillBuilder::forms`) → `correct`; `SpellingGrader` в допуске к любой форме → `typo`; иначе `wrong` |
| `pairs_round`, `collocation`, `sentence`, `dictation` | как в `docs/16` §7.2 | вердикт обработчика практики; `pairs_round` завершается, когда сопоставлены все пары; раунд пар стоит **максимум одну** флягу, если `mismatches > 0`; ошибки для SRS — `wrong_left` |

`skip` («Не знаю») = `skipped`: раскрывает ответ, стоит флягу, ошибка для SRS. Подсказка базара (`POST …/hint`) и бустер «подсказка» убирают два неверных варианта (виды с вариантами: `translate_*`, `listen`, `collocation`) или открывают первые две буквы (`spell`/`gap`); у `pairs_round`, `sentence`, `dictation` подсказки нет (409 `hint_not_available`). Ответ после подсказки помечается `hinted: true` в `state` и считается в `hints` (не в `correct` / `wrong`), каким бы ни был вердикт: верный — не стоит фляги, 0 очков, рвёт комбо, **ошибка для SRS** (ученик не знал); неверный после подсказки — стоит флягу, как любой `wrong`. Уточнения счёта (G2.2a, `RunScoring`): `typo` даёт 5 очков и **рвёт** комбо (это не чистый верный ответ); раунд пар теряет флягу **при первом** несовпадении (не в конце раунда), очки раунда — 10 только без несовпадений; оазис прибавляет флягу **после** потери на своём элементе и не спасает забег, который этой же потерей лишился последней фляги; «второй шанс» прощает первую потерю фляги забега (вердикт и SRS — без изменений). Звёзды: `3 − потерь фляг` (не ниже 0) у `completed`, 0 у `failed`; у босса — 3 при победе, 0 при поражении.

#### 12.3.3 `game_runs.state`

```jsonc
{ "current": 3,
  "elements": { "eK7qM2x9Ab": {"status": "answered", "verdict": "correct", "first": true, "hinted": false, "points": 10, "answered_at": "…"} },
  "pairs": { "eP5…": {"matched": {…}, "mismatches": 1, "wrong_left": ["lC…"]} },
  "combo": 2, "flasks": 2, "oasis_used": false, "boosters": {"extra_flask": true, "second_chance_left": 1},
  "srs_pulled": [1007, 1031] }
```

Приём ответа — транзакция §12.5.2; повтор `request_id` → `result` из `game_run_attempts` + **текущий** `RunView`.

#### 12.3.4 `BossWordPicker`

Слова города для ученика: `word_repetitions JOIN words JOIN lessons` по `stages.id = :stage AND words.is_active AND learner_id = :me`, `LEFT JOIN game_word_misses`, порядок `is_hard DESC, lapses DESC, COALESCE(misses, 0) DESC, easiness_factor ASC, word_repetitions.id`, `LIMIT boss.size`. Введённых слов в городе меньше `boss.min_words` (8) — босс недоступен (409 `boss_not_ready {introduced, required, cleared_stops, required_stops}`). Босс доступен, когда ещё и очищено (≥ 1 звезда) не меньше `boss.min_cleared_stops` (3) стоянок города (или все, если стоянок меньше). Набор фиксируется в снимке — правка слов и прогресс в карточках на идущий бой не влияют. Босс класса — тот же запрос по **всем активным участникам группы** (`group_members.status = active`), агрегат `SUM(is_hard) DESC, SUM(lapses) DESC, SUM(misses) DESC`, по словам курса группы; фиксируется в `class_caravans.boss_snapshot_words` при старте каравана.

Отвергнуто: случайные задания без снимка (повтор запроса менял бы выдачу; ответы утекали бы); таймер на элемент (нужны серверные часы на каждый элемент, как в баттле, — цена не оправдана до арены); пары как несколько элементов (один раунд — один элемент, иначе фляги уходят за раунд пачкой).

### 12.4 Правило SRS (§5): игра только приближает повторение

**Что считается ошибкой для SRS:** `wrong`, `skipped`, верный ответ **после подсказки** (базар или бустер), `wrong_left` раунда пар, ошибка авторского задания — для всех `target_word_ids` его версии. **Не ошибка:** `correct`, `typo`. Бустер «второй шанс» прощает **флягу**, а не знание: прощённый ответ остаётся `wrong` и для SRS.

**Что пишется и когда.** `GameSrsAdjuster::pull(int $learnerId, list<int> $wordIds, CarbonImmutable $now): int` вызывается в `DB::afterCommit` транзакции ответа (не внутри — правило 8 `docs/16` §9 сохраняется: транзакции игры не трогают `word_repetitions`), в `try / report()`:

```sql
UPDATE word_repetitions SET next_review_at = :now, is_hard = true, updated_at = :now
 WHERE learner_id = :me AND word_id IN (:ids) AND next_review_at > :now;
```

(билдер: `whereIn('word_id', $ids)->where('next_review_at', '>', $now)->update([...])`; возвращает число задетых строк), затем `upsert()` в `game_word_misses` по `(learner_id, word_id)` для **всех** `:ids`: `misses = misses + 1`, `last_missed_at = now`, `last_pulled_at = now` у задетых (две пачки `upsert`: задетые — по предварительному `SELECT word_id … WHERE next_review_at > :now` до UPDATE, незадетые — остальные).

**Что это значит для SM-2 (`docs/06` §3) и FSRS (§9):**
- Карточка `review` с `next_review_at` в будущем становится due **сейчас**: придёт в ближайшее повторение карточек (`CardPicker` шаг 1, трудные первыми — `is_hard = true`). Интервал пересчитает **обычная** оценка в карточках: SM-2 — по `interval_days · EF'` от сохранённого `interval_days` (приближение формулу не меняет), FSRS — по фактическому `t` от `last_reviewed_at` (меньше `t` → выше `R` → меньший рост `S`) — оба алгоритма получают честное «повторено раньше срока», и ни один не получает «пропущено».
- Карточки `new` / `learning` / `relearning` в минутных шагах: `next_review_at` уже близко; `UPDATE` задевает их, только если шаг ещё в будущем (становятся due); `learning_step`, `state` не меняются. `served_ahead_*` не трогаются: билет learn-ahead остаётся валидным.
- `is_hard = true` — та же семантика, что `docs/06` §3.5: снимается первым успешным ответом на `review`-карточку в карточках. Следствия приняты: трудные первыми у пикера, бонус ×1.5 в экзамене за это слово (как после провала на тренировке).
- Верный ответ в игре **ничего не пишет**: интервал не растёт (выбор из вариантов — слабая проверка), `last_reviewed_at` не меняется, `training_reviews` не пополняется — метрика «точность и выполнение повторений в карточках» (§5, §10) остаётся чистой от игры.
- Ежедневный караван: due-слова после забега остаются due (верные не отодвигаются) — карточки спросят их снова; это разминка, не замена.

**Идемпотентность:** `UPDATE` условный (`next_review_at > now`) — повтор ничего не меняет; к хуку ведёт только **первый** принятый запрос (повтор `request_id` отвечает из журнала и хук не регистрирует); `misses` растёт один раз на принятую ошибку. **Правило `docs/06` §5.2** (приём оценки) не меняется: приближенная карточка оценивается как обычная due. **Счётчик `game_runs.srs_pulled`** — число реально приближенных слов — известен только после хука, поэтому пишется там же, после commit, одиночным `UPDATE game_runs SET srs_pulled = srs_pulled + n WHERE id = ?` своей строки (ничего не ждёт, правило 17); `state.srs_pulled` хранит id слов-ошибок (для разбора ошибок).

**Правка `docs/06`:** новый §5a «Игра и расписание» — это правило и ссылка сюда (делает G2.2a до кода).

Отвергнуто: писать `training_reviews` с `quality 0..2` за ошибку (это «провал» с `lapses += 1`, сбросом `repetitions`, штрафом EF/`S` — игра бы **отдаляла** повторение по правилам SM-2, нарушая §5); приближать на «следующий локальный день», а не на сейчас (ученик хочет закрыть ошибку сразу после забега — «Повторить в карточках»); засчитывать верный ответ как `quality 4` (увеличивает интервал — запрещено §5).

### 12.5 Конкурентность

#### 12.5.1 Порядок блокировок (дополнение `docs/13` §3 и `docs/16` §9; нумерация продолжает `docs/16` §9)

```
старт забега, Free:   learners (FOR NO KEY UPDATE, своя строка) → game_profiles (UPDATE tickets, своя строка) → game_runs (FOR UPDATE активной / INSERT) → game_ticket_ledger INSERT → product_events
старт забега, Pro:    game_runs (FOR UPDATE активной / INSERT) → product_events
ответ / подсказка:    game_runs (FOR UPDATE, своя строка) → [game_wallets (FOR UPDATE) → game_coin_ledger INSERT — только подсказка базара] → game_run_attempts INSERT → UPDATE game_runs
                      afterCommit: GameSrsAdjuster (одиночный UPDATE word_repetitions + upsert game_word_misses), product_events
бустер в забеге:      game_runs (FOR UPDATE) → game_inventory (UPDATE qty, своя строка) → game_booster_uses INSERT → game_run_attempts INSERT → UPDATE game_runs
завершение (последний ответ / третья фляга), в транзакции ответа (G2.5a, RunFinisher):
                      … → game_stop_progress / game_city_progress (insertOrIgnore + FOR UPDATE, своя строка) → game_wallets (FOR UPDATE) → game_coin_ledger INSERT
                      → game_profiles UPDATE (один условный UPDATE без отдельного замка) → learner_daily_stats (insertOrIgnore + FOR UPDATE строки дня)
                      → class_caravan_members upsert → class_caravans (FOR UPDATE, UPDATE)                                              -- G2.9a
                      afterCommit: StreakService::apply, LeagueService::place, AchievementService::unlock / evaluate, SendGameCardJob, product_events
покупка в лавке:      game_wallets (FOR UPDATE) → game_coin_ledger INSERT → game_inventory upsert → product_events
ручное начисление:    audit_logs INSERT (своя транзакция) → game_wallets (FOR UPDATE) → game_coin_ledger INSERT
билеты за серию/друзей: learners (FOR NO KEY UPDATE) → game_profiles UPDATE → game_ticket_ledger INSERT    (своя короткая транзакция после commit источника)
старт каравана класса: class_caravans INSERT (unique active_group_id; violation → 409);  закрытие: class_caravans (FOR UPDATE) → чтение class_caravan_members → UPDATE
```

17. **Транзакции игры никогда не блокируют** `word_repetitions`, `training_sessions`, `training_reviews`, `practice_*`, `battles`, `courses`, `words`, `friendships`, `league_*`, `referrals`. `word_repetitions` пишется **после commit** одиночным условным UPDATE (держит только свои строки и ничего не ждёт).
18. **Строка ученика `learners`** берётся первой и только Free при старте забега и при выдаче билетов (как `PracticeLimits`, правило 16). Ничто, держащее строки игры, не ждёт `learners` → цикла с `CardPicker`, `LearnerSettingsService`, `PracticeLimits` нет; с ними старт забега Free просто сериализуется. Pro замок не берёт.
19. **`learner_daily_stats` в транзакции завершения** — после всех строк игры. Транзакция оценки держит `training_sessions → word_repetitions → learner_daily_stats` и **никогда** не ждёт строк игры; завершение держит `game_runs → game_wallets → … → learner_daily_stats` и **никогда** не ждёт `training_sessions` / `word_repetitions` → цикла нет; две транзакции встречаются только на строке дня (миллисекунды). Хуки `DailyStatsService` (рефералы, лига, достижения) в завершении **не** вызываются — у `RunFinisher` свои; `LeagueService::place` вызывается после commit, если `game_runs_count` стал 1 и `reviews_count = 0` (первая активность дня — игра).
20. **`game_wallets` всегда после `game_runs`** и никогда наоборот: ничего, держащее кошелёк, не ждёт забег (лавка и админка забегов не трогают). Одна строка кошелька на транзакцию; двух учеников в одной транзакции нет (правило 4 `docs/13` §3; награды каравана класса — по одному ученику, в его собственном завершении). **Строки прогресса (`game_stop_progress` / `game_city_progress`) — до кошелька** (G2.5a): размер награды зависит от прежних звёзд / побед, поэтому строка берётся `FOR UPDATE` первой после забега; её не блокирует никто, кроме завершения (карта и админка только читают), и ничто, держащее кошелёк, её не ждёт — цикла нет. Завершения одного ученика и так последовательны: второй забег не вставляется, пока первый держит `active_learner_id` (unique), — замок прогресса лишь закрепляет это.
21. **Караван класса:** вклад участника пишется в его транзакции завершения (строка `class_caravan_members` своего ученика и одна строка `class_caravans` `FOR UPDATE` — коротко, последней). Закрытие каравана (`game:class-caravans-close`) держит одну строку `class_caravans`; `PostClassCaravanSummaryJob` — ту же строку плюс `Cache::lock("game:class-summary:{id}")` по образцу `PostLeaderboardJob`. Внешний вызов (Telegram) — вне транзакции.
22. **Единственные межученические зависимости** — unique `game_runs.active_learner_id`, `daily_key`, `class_boss_key`, `class_caravans.active_group_id`, `game_coin_ledger.idempotency_key`, `game_ticket_ledger.idempotency_key`: ожидание на индексе — только между транзакциями одного ученика / группы / ключа, каждая держит лишь свои строки → цикл невозможен; проигравшая получает `UniqueConstraintViolationException` после commit победителя и отвечает повтором чтения (старт) или 409.
23. Планировщики: `game:close-expired` — одиночный `UPDATE … WHERE status = 'active' AND expires_at <= now` (+ `active_learner_id = NULL`), просроченный забег наград не получает — завершение не вызывается; `game:class-caravans-close` — по одному каравану за транзакцию, сбой одного `report()`-ится, обход идёт дальше (правило 14).

#### 12.5.2 Транзакция ответа

```
BEGIN
  SELECT * FROM game_runs WHERE id = :id AND learner_id = :me FOR UPDATE          -- нет → 404 game_run_not_found
  status = finished → 410 game_run_closed {finish_reason}
  expires_at <= now → UPDATE finished/expired, active_learner_id = NULL → 410
  SELECT … FROM game_run_attempts WHERE run_id = :id AND request_id = :rid          -- есть, тот же kind → COMMIT, result + текущий RunView; другой kind → 409 request_id_reused
  element ≠ elements[state.current] → 409 element_not_current {current}
  element уже answered → 409 answer_already_accepted {verdict}
  grade = handler->grade(element, answer, state)                                    -- 422 invalid_answer_shape | unknown_element_id | unknown_option_id; 409 pair_already_matched
  RunScoring::apply(state, grade): очки, комбо, фляги (с учётом hinted / second_chance), oasis на этом индексе
  INSERT game_run_attempts (…, result)
  UPDATE game_runs SET state, answered, correct, typos, wrong, hints, flasks, combo_best, score[, current += 1]
  flasks = 0 и flasks_max > 0 → RunFinisher::finish(run, failed);  current = total → RunFinisher::finish(run, completed)   -- в этой же транзакции (§12.5.3)
COMMIT
afterCommit: GameSrsAdjuster::pull(me, grade.srsErrorWordIds), product_events; при завершении — хуки §12.5.3 п. 7
```

Подсказка базара (`POST …/hint`): тот же замок забега; элемент текущий, не отвечен, `events[at = current].type = bazaar` или в инвентаре бустер `hint` (тогда списывается бустер, не монеты); базар — `game_wallets FOR UPDATE`, баланс ≥ цены (иначе 409 `coins_insufficient {balance, price}`), проводка `run:{id}:hint:{element}` (unique — повтор не списывает дважды даже без журнала попыток); `state.elements[e].hinted = true`; `INSERT game_run_attempts kind = hint`; ответ — подсказка в форме вида (`options_removed: [id, id]` / `prefix: "ap"`). Повтор `request_id` → прежняя подсказка.

#### 12.5.3 Транзакция завершения (`RunFinisher`, внутри транзакции ответа)

1. `UPDATE game_runs SET status = finished, finish_reason, finished_at, stars, active_learner_id = NULL, coins_awarded, league_points, srs_pulled`.
2. Награда монетами (`WalletService::credit(me, amount, kind, key: "run:{id}:reward")`; `amount = 0` → проводки нет): `stop` — первое завершение стоянки `economy.stop_first` (10) + `economy.per_new_star` (5) за каждую **новую** звезду сверх прежних; повтор без новых звёзд — `economy.stop_repeat` (2); `daily` — `economy.daily` (10) + 5 за звезду; `boss` — первая победа `economy.boss_first` (50), повторная `economy.boss_repeat` (5), поражение 0; `class_boss` победа — `economy.class_boss` (20). `failed` — только `stop_repeat`, если стоянка уже была очищена, иначе 0.
3. `game_stop_progress` / `game_city_progress` upsert (`stars = max`, `best_score = max`, `runs_count + 1`, `first_cleared_at`, `boss_*`).
4. `game_profiles`: `stops_cleared`, `cities_cleared`, `last_run_at`.
5. `learner_daily_stats` строки `game_runs.local_date`: `insertOrIgnore` + `FOR UPDATE` (как `DailyStatsService::lockedRow`), `game_runs_count + 1`, `game_points = min(cap, game_points + earned)`, где `earned = correct × league.correct (2) + (typos + wrong + hinted) × league.answer (1)`; `game_held_at = now`, если `NULL`. `game_runs.league_points` = фактический прирост.
6. Караван класса: если у ученика есть активный `class_caravans` по его активным группам (`group_members.status = active`, `telegram_groups.status = active`) и `now ∈ [starts_at, ends_at)` — `class_caravan_members` upsert (`runs + 1`, `stars + stars`, `best_score`), `class_caravans FOR UPDATE`: `stars_total`, `runs_total`, `boss_unlocked_at` при достижении цели; `class_boss` победа — `boss_won_at` участника и, если побед ≥ `ceil(участников с забегами / 2)`, `boss_defeated_at`. `game_runs.class_caravan_id` ставится при **старте** забега (чтение без блокировки), в завершении перечитывается `status = active` — караван, закрытый между стартом и финишем, вклад не получает.
7. `afterCommit` (каждый в `try / report()`): `StreakService::apply(learner, local_date)`, если `game_held_at` поставлен этим забегом и `goal_met_at` был `NULL`; `LeagueService::place`, если `game_runs_count` стал 1 и `reviews_count = 0`; `AchievementService::unlock(game_first_boss)` при первой победе над боссом, `evaluate` при удержании дня; `SendGameCardJob` при первой победе над боссом города; события `game.run_finished`, `game.boss_defeated`, `game.coins_earned`.

**Реализация G2.5a (`RunFinisher`, `WalletService::credit`) — уточнения к шагам 1–5:**

- **Порядок внутри транзакции:** строки прогресса берутся `FOR UPDATE` **до** кошелька (правило 20): награда за стоянку считается от прежних звёзд, за босса — от прежней `boss_defeated_at`; решение принимается по замкнутому значению, а не по чтению без замка. `UPDATE game_runs` (шаг 1) — один, **последним**, вместе с полями ответа: `coins_awarded` и `league_points` известны только после шагов 2 и 5, а строка забега и так заперта с начала транзакции ответа. Запросов на завершение стоянки с наградой при существующих строках — 10 (прогресс 1, кошелёк 4, прогресс UPDATE 1, профиль 1, день 2, забег 1); при первом касании каждой таблицы — до 16 (`insertOrIgnore` + перечитывание).
- **Награда (шаг 2), пограничные случаи:** `completed` стоянки **без новых звёзд** — `stop_repeat`, включая завершение с 0 звёзд при бустере «фляга» (четыре фляги, три потери); `failed` стоянки — `stop_repeat`, только если `first_cleared_at` уже стоял, иначе 0; `failed` ежедневного каравана — 0 (награда каравана дня — за завершение); босс — награда только за победу, `kind = boss_reward`; `class_boss` победа — `kind = class_reward`, тот же ключ `run:{id}:reward` (ключ `class:{caravan}:{learner}` остаётся за наградой недели G2.9a). `amount = 0` → `WalletService::credit` ничего не пишет и не берёт замок (возвращает текущий баланс, `applied = false`). `meta` проводки: `{stars, run_kind}`.
- **Прогресс (шаг 3):** `game_stop_progress`: `stars = max`, `best_score = max`, `runs_count + 1`, `first_cleared_at` при первой звезде, `last_run_at`; `game_city_progress` (у `boss`): `boss_attempts + 1`, `boss_wins`, `boss_best_accuracy = max(round(100 × correct / total))`, `boss_defeated_at` один раз. У `daily` и `class_boss` строк прогресса нет.
- **Профиль (шаг 4):** `stops_cleared` / `cities_cleared` не пересчитываются, а **увеличиваются на 1** под замком строки прогресса ровно тогда, когда этот забег поставил `first_cleared_at` / `boss_defeated_at` (эквивалентно пересчёту, на два запроса дешевле); `last_run_at = now`. Строка создаётся лениво (`UPDATE`, при 0 строк — `insertOrIgnore` с готовыми значениями).
- **День (шаг 5):** `earned = correct × league.correct + (typos + wrong + hints) × league.answer`; `game_points = min(cap, game_points + earned)`; `game_runs.league_points` — фактический прирост (0, когда потолок уже достигнут). Строка дня — `game_runs.local_date` (день старта), не день завершения: забег через полночь остаётся в дне, в котором начался.
- **Хуки (шаг 7):** `game.run_finished` для `completed` / `failed` пишет `RunFinisher` (через общий `RunEventRecorder`; `expired` / `abandoned` — по-прежнему `RunService`), `game.coins_earned` — при `applied` проводке. `StreakService::apply` / `LeagueService::place` / `AchievementService` — G2.10; `game_first_boss`, `SendGameCardJob`, `game.boss_defeated` — G2.4; вклад в караван класса — G2.9a. Точки вызова помечены в коде, `RunFinishOutcome` несёт для них `heldDay`, `goalMetBefore`, `firstRunOfDay`, `reviewsCount`, `firstClear`, `bossWon`, `bossFirst`.
- **`game:wallets-verify`** (`VerifyGameWallets`, ежедневно в 04:10, `withoutOverlapping`): один запрос `game_wallets LEFT JOIN (SUM по леджеру)` с условием `balance <> COALESCE(sum, 0)` плюс проводки учеников без строки кошелька; расхождения — в лог (`game.wallet_mismatch`, по одной записи на кошелёк) и итог `game.wallets_verified {wallets, mismatches}`; код выхода 1 при расхождениях, записей нет.

#### 12.5.4 PG-тесты (pcntl, `DatabaseTruncation`, образец `PracticeLimitsConcurrencyTest`; `SET lock_timeout = '8000'` в каждом воркере)

| Файл | Сценарий | Ожидание |
|---|---|---|
| `GameWalletConcurrencyTest` | баланс 10, пять процессов покупают предмет за 4 монеты | ровно 2 покупки, `balance = 2`, `SUM(ledger) = 2`, остальным 409 `coins_insufficient`, ни одного отрицательного `balance_after` |
| | один `idempotency_key` начисления × 8 процессов | одна строка леджера, баланс +amount один раз |
| | списание ∥ начисление одного кошелька | без deadlock, `SUM(ledger) = balance` |
| | два последних ответа одного забега (две вкладки, разные `request_id`) | одна проводка `run:{id}:reward`, один `finished`, `game_runs_count` дня +1, `game_points` один раз |
| `GameRunConcurrencyTest` | два `start` одного ученика | одна активная строка, оба ответа — она же |
| | Free с одним билетом: три `start` стоянок одновременно | один забег, одна проводка `spend:run:*`, `tickets = 0`, остальным 403 `game_limit_reached` |
| | два `start` ежедневного каравана | одна строка `daily_key`, второму — 409 `game_daily_done` |
| | `answer` ∥ `game:close-expired` просроченного | либо 410, либо принято до закрытия; строка консистентна |
| | тот же `request_id` × 2 | одна строка попытки, одинаковые тела |
| | подсказка базара × 2 с разными `request_id` | одно списание (unique `run:{id}:hint:{element}`), второй — повтор подсказки |
| | старт забега Free ∥ открытие практики Free ∥ `CardPicker` того же ученика | без deadlock (общий первый замок `learners`) |
| | завершение забега ∥ `POST /review` того же ученика в тот же локальный день | без deadlock; строка дня содержит и оценку, и забег |
| `ClassCaravanConcurrencyTest` | два запуска каравана одной группы | один `active`, второму 409 `class_caravan_active` |
| | три участника завершают забеги одновременно | `stars_total = Σ`, `runs_total = 3`, три строки участников |
| | `PostClassCaravanSummaryJob` × 3 (два воркера + повтор) | одно сообщение, один `summary_posted_at` |

### 12.6 Лига и серия

**Серия.** Завершённый забег (`completed` или `failed`; `expired`/`abandoned` — нет) ставит `learner_daily_stats.game_held_at` локального дня — день удержан. `StreakService`: день **удержан**, если `goal_met_at IS NOT NULL OR game_held_at IS NOT NULL OR frozen_at IS NOT NULL`; удержанный целью **или игрой** день считается в серию `+1`, замороженный — «мост» без `+1` (как сейчас). Правки: `heldRowsBetween` (условие), `current()` (строка сегодня: `goal_met_at ?? game_held_at`), `project($date, bool $counts)` — параметр переименовывается из `goalMet` в `counts`; удержание игрой пишет `streak.extended` (уже есть), **не** пишет `daily_goal.met` (цель — про карточки), поэтому `AchievementService::evaluate` вызывается из `RunFinisher` явно. Заморозки тратятся по тем же правилам. Экран «Сегодня» показывает «Серия удержана забегом» — новое поле `/today.streak.held_by: goal | game | freeze | null` (`docs/04` A.2a).

**Очки лиги.** Карточка в лиге стоит `review` 1 + `correct` 1 = 2 очка за верную и 1 за неверную оценку, `new_word` 2, цель дня 10 (`config/leagues.php`). Игра — «по правилам карточек»: верный ответ **2**, неверный / опечатка / с подсказкой **1** (`config/game.php` `league.correct`, `league.answer`); новых слов и бонуса цели у игры нет. Забег из 10 элементов — 10–20 очков.

**Потолок — 40 очков в локальный день** (`GAME_LEAGUE_DAILY_CAP=40`). Обоснование:
1. **Честность §4 буквально.** У Free каждый день есть ежедневный караван и один бесплатный забег по карте — два забега без ошибок = ровно 40. Потолок достижим Free **каждый день без билетов и Pro**, поэтому безлимитные забеги Pro не дают в лиге ни одного очка сверх доступного Free: Pro не покупает очки даже косвенно.
2. **Масштаб относительно карточек.** Цель дня 20 карточек ≈ 20 × 2 + 10 = 50 очков; потолок игры (40) меньше одной цели дня — игра дополняет лигу, а не замещает её; у активного ученика (100–150 очков в день карточками) игра — четверть, не половина.
3. **Контрольная метрика §5.** Небольшой потолок не мотивирует заменять повторения забегами; если метрика выполнения повторений просядет — потолок снижается одной переменной env без релиза.

Потолок применяется **при записи** (`game_points = min(cap, …)`), а не при суммировании: формула лиги получает готовую колонку, а `game_runs.league_points` показывает, сколько забег реально принёс. Один потолок для Free и Pro. Арена G3 и турнир очков лиги не дают (решение здесь; §4 это и говорит).

**Размещение в группе:** лиги размещают при первой оценке дня; ученик, который в этот день только играл, размещается из `RunFinisher` (после commit; `LeagueService::place` идемпотентен, opt-out и выключенные лиги проверяет сам).

**Правки документов:** `docs/13` §2.4 (формула `points`), §7.3 (размещение из игры), `docs/03` §2.22 (колонки), `docs/12` §7.4 и `docs/06` §6a (день удержан игрой) — делает G2.10 до кода.

Отвергнуто: засчитывать ответы игры в `reviews_count` / `correct_count` (ломает контрольную метрику §5 и считает забег «повторениями»); ставить `goal_met_at` забегом (цель дня — карточки; `daily_goal.met` слушают рефералы и достижения с другим смыслом); потолок 60 (три забега — Free достигает только с билетом, Pro получал бы до 20 очков в день преимущества).

### 12.7 Free и Pro, билеты, пейвол

#### 12.7.1 `config/game.php` (окончательные ключи; `env()` — у флагов и лимитов)

```php
return [
    'access' => env('GAME_ACCESS', 'off'),                              // off | pilot | all; pilot — learners.practice_pilot_at (общий пилот практики и игры)
    'free_limits' => [                                                  // 0 — только Pro; пусто — без лимита (как practice.free_limits)
        'runs_per_day' => env('GAME_FREE_RUNS_PER_DAY', 1),             // бесплатные забеги по карте (stop + boss) в локальный день; сверх — билеты
        'boss_stages' => env('GAME_FREE_BOSS_STAGES', 1),               // боссы первых N городов курса (по stages.number)
        'boosters_per_week' => env('GAME_FREE_BOOSTERS_PER_WEEK', 1),   // использований бустеров в локальную неделю
    ],
    'pro_limits' => ['boosters_per_week' => env('GAME_PRO_BOOSTERS_PER_WEEK', 5)],
    'tickets' => [
        'per_streak_week' => env('GAME_TICKETS_PER_STREAK_WEEK', 1),    // +1 за каждые 7 дней серии (streak_current % 7 == 0)
        'per_referral' => env('GAME_TICKETS_PER_REFERRAL', 3),          // пригласившему за активированного друга
        'max' => env('GAME_TICKETS_MAX', 10),                           // потолок баланса; сверх — не выдаётся, событие game.ticket_capped
    ],
    'run' => ['size_min' => 8, 'size_max' => 10, 'min_elements' => 6, 'authored_max' => 3, 'pairs_rounds_max' => 1, 'pairs_per_round' => 4,
              'flasks' => 3, 'flasks_max_with_booster' => 4, 'ttl_minutes' => 30, 'distractor_pool' => 40],
    'scoring' => ['correct' => 10, 'typo' => 5, 'combo_from' => 3, 'combo_bonus' => 5, 'combo_bonus_max' => 15],
    'events' => ['chance' => 0.6, 'bazaar_hint_coins' => 5],
    'boss' => ['size' => 12, 'min_words' => 8, 'min_cleared_stops' => 3, 'required_accuracy' => 0.8],
    'economy' => ['stop_first' => 10, 'per_new_star' => 5, 'stop_repeat' => 2, 'daily' => 10, 'daily_per_star' => 5, 'boss_first' => 50, 'boss_repeat' => 5, 'class_boss' => 20],
    'league' => ['correct' => 2, 'answer' => 1, 'daily_cap' => env('GAME_LEAGUE_DAILY_CAP', 40)],
    'class' => ['days' => 7, 'stars_per_member' => 6, 'min_target' => 12, 'centers_only' => (bool) env('GAME_CLASS_CENTERS_ONLY', true)],
    'map' => ['friends_max' => 20],
];
```

Фичи entitlement (`config/billing.php`, `plans.pro.features`): `game.unlimited_runs` (нет лимита забегов, билеты не нужны), `game.all_bosses` (боссы всех городов; она же открывает покупку `exclusive` / `seasonal` косметики), `game.boosters_plus` (`pro_limits.boosters_per_week`). `game.arena_unlimited`, `game.tournament`, `season.pro_track` из §3 заводятся в G3/G4.

#### 12.7.2 Лимиты (`GameLimits`, образец `PracticeLimits`)

| Что | Правило |
|---|---|
| Окно | локальный день `LearnerClock::limitWindowUtc` (тот же, что у практики и квоты слов); неделя бустеров — с понедельника локальной недели ученика (`GameWeek`, как `StreakFreezeService::refill`) |
| Забеги по карте (`stop`, `boss`) | у Free: `COUNT(game_runs WHERE learner_id, kind IN (stop, boss), started_at ∈ окно, NOT (finish_reason IN (abandoned, expired) AND answered = 0))` ≥ `runs_per_day` → нужен билет: `game_profiles.tickets > 0` → `tickets − 1`, проводка `spend:run:{run_id}` в той же транзакции (ключ известен после INSERT забега), `game_runs.free_slot = ticket`; иначе 403 `game_limit_reached {kind: runs, limit, used, tickets: 0, resets_at}`. Брошенный или просроченный без единого ответа забег место **не** занимает (как возврат вызова баттла) |
| Ежедневный караван | лимитов нет (один в день — факт БД, 409 `game_daily_done {resets_at}`); билет не тратит; у Free при `GAME_FREE_RUNS_PER_DAY=0` караван дня остаётся |
| Боссы | Free: `stages.number > boss_stages` → 403 `game_pro_required {kind: boss, stage_number}`; бой с боссом первого города стоит забег дня или билет, как стоянка |
| Бустеры | использование в забеге: `COUNT(game_booster_uses WHERE learner_id, week_start = текущая)` ≥ лимит плана → 409 `booster_limit_reached {limit, used, resets_at}`; покупка бустера в лавке лимитом не ограничена (монеты свои) |
| Pro | фича `game.unlimited_runs` — замок ученика не берётся, билеты не тратятся (баланс сохраняется до конца Pro) |
| Атомарность | вежливая проверка без замка до чтения пулов; решающая — под `FOR NO KEY UPDATE` строки ученика в транзакции старта (§12.5.1); `GameRunConcurrencyTest` |
| Блок `/me.game.limits` | `{runs: {limit, used, remaining, resets_at} \| null, tickets: {balance, max}, boosters: {limit, used, remaining, resets_at}, boss_stages: 1 \| null}`; `null` у Pro там, где лимита нет; весь блок `null`, когда игра закрыта ученику |

#### 12.7.3 Билеты (`TicketService`)

- **За серию:** `StreakService::project` после удачного `+1` регистрирует `afterCommit` → `TicketService::grantForStreak(learner, streakCurrent, localDate)`: `streak_current > 0 && streak_current % 7 == 0` → `+per_streak_week`, ключ `streak:{learner_id}:{local_date}` (повтор дня невозможен). **Всем, не только Free:** у Pro билеты копятся и пригодятся после конца подписки (мотив удержания); потолок `tickets.max`.
- **За друзей:** `ReferralService::reward` после обеих сторон вызывает `TicketService::grantForReferral(referral)` → пригласившему `+per_referral`, ключ `referral:{referral_id}` (идемпотентно — `referrals:recover` и повтор job безопасны); приглашённому билетов нет (у него 7 дней Pro). Флагнутый реферал билетов не даёт, как и награды.
- **Потолок:** `tickets + delta > max` → выдаётся до потолка; срезанное не пишется, событие `game.ticket_capped`.
- **Трата:** только при старте забега по карте сверх бесплатного (§12.7.2). Билеты не сгорают.
- **Админ:** ручная выдача на карточке ученика (`audit_logs` `game.tickets_adjusted`, ключ `admin:{audit_log_id}`).

#### 12.7.4 Пейвол и доступ

- `GameAccess::state(Learner)`: `off` → 403 `game_unavailable`; `pilot` без `practice_pilot_at` → 403 `game_unavailable` (один код — не раскрываем, есть ли пилот); иначе `available`. Режима `plan = pro` у игры **нет**: бесплатная часть — продуктовое решение §3.
- `PaywallSheet reason = 'game'` (новая причина в `PaywallReason`) с `details` отказа: `{kind: runs|boss|boosters, limit, used, tickets, resets_at, stage_number}`; лид по `kind`: «Сегодняшний забег сыгран — ещё один завтра, по билету или в Pro без лимита» / «Хранитель ворот этого города — в Pro» / «Бустеры на этой неделе закончились». Строка Pro «Караван без лимита забегов и все боссы» — через `ProPromises` по тому же правилу, что практика (`/me.game.pro_promise: none | full`): говорится, пока игра открыта читающему и у Free есть лимит, который снимает Pro.
- Событие `game.limit_reached {kind, limit}` при каждом отказе (вежливом и в гонке); `paywall.shown` — не пишется (как у практики).

### 12.8 API и события

Все маршруты — `/api/twa/game/*`, `TwaAuth` + `SetTwaLocale`, группа `throttle:twa-api`; аудио — `twa-practice-audio` (вне `twa-api`); старт — `twa-game-start` 10/мин; ответы — `twa-game-answer` 120/мин (как `twa-practice-answer`). Контроллеры тонкие (валидация → сервис → Resource); ошибки — `ApiErrorResource` с `details`; чужой забег = 404; даты ISO 8601 UTC с мс; `server_now` в ответах забега.

| Маршрут | Resource | Что |
|---|---|---|
| `GET /game/map?course={slug}` | `GameMapResource` | `course {slug, title}`, `cities[] {stage_id, number, city, title, unlocked, stops[] {lesson_id, number, title, unlocked, stars, best_score, runs}, boss {state: locked_by_plan \| not_ready \| ready \| defeated, defeated_at, introduced, required, cleared_stops, required_stops}}`, `daily {available, done_today, due_count, resets_at}`, `me {stops_cleared, cities_cleared, coins, tickets}`, `friends[] {friendship_id, name, city_number, stops_cleared, camel_asset}` (принятые друзья с прогрессом по этому курсу, до `map.friends_max`), `active_run {id, kind, progress} \| null`, `class_caravan {id, stars_total, target_stars, ends_at} \| null`, `limits` |
| `POST /game/runs` (201) | `GameRunResource` | тело `{kind: stop \| boss \| daily \| class_boss, course, lesson_id?, stage_id?, class_caravan_id?, replace_active?: bool}`; ответ — забег с первым элементом + `limits` после старта |
| `GET /game/runs/{id}` | `GameRunResource` | `{id, kind, status, finish_reason, city, stage_number, lesson_number, lesson_title, scope_widened, total, position, flasks, flasks_max, combo, score, current {element (project), event} \| null, boosters {available[] {key, effect, qty}, used[]}, hint {price, available}, summary \| null, server_now}` |
| `POST /game/runs/{id}/answer` | `GameAnswerResource` | `{request_id, element_id, answer, time_spent_ms?}` → `{verdict, points, reveal, flask_lost, combo, event_applied, run}`; при завершении — `run.summary` |
| `POST /game/runs/{id}/hint` | `GameAnswerResource` | `{request_id, element_id}` → `{hint: {options_removed[] \| prefix}, coins: {balance}, run}` |
| `POST /game/runs/{id}/booster` | `GameRunResource` | `{request_id, item_key, element_id?}` |
| `POST /game/runs/{id}/abandon` | `GameRunResource` | `{confirm: true}` → `finished/abandoned` |
| `GET /game/runs/{id}/audio/{element}` | поток `audio/mpeg`, `no-store` | по id элемента снимка; элементы дальше текущего — 404 |
| `GET /game/wallet` | `GameWalletResource` | `{balance, earned_total, spent_total, tickets {balance, max}, ledger[20] {amount, kind, created_at, title}}` |
| `GET /game/shop` | `GameShopResource` | `{items[] {key, kind, slot, effect, price, availability, owned, qty, equipped, locked_reason: pro \| achievement \| null, title, description, asset}, balance}` |
| `POST /game/shop/{key}/buy` | `GameWalletResource` | `{request_id}` → проводка `shop:{learner}:{request_id}` |
| `GET /game/profile` · `POST /game/profile/equip` | `GameProfileResource` | `{equipped {slot: {key, asset}}, stops_cleared, cities_cleared, coins, tickets}`; equip `{slot, item_key \| null}` |
| `GET /game/class` · `GET /game/class/{id}` | `ClassCaravanResource` | караваны моих групп: `{id, group_title, status, starts_at, ends_at, stars_total, target_stars, runs_total, boss {unlocked, defeated, my_attempt: won \| lost \| null}, members[] {name, stars, runs, is_me}, my {stars, runs}}` |
| `GET /me` | `MeResource` | новый блок `game: {available, limits, pro_promise, coins, tickets}` (всегда присутствует; `MePayloadBuilder += GameAccess::describe`; `pro_promise` — `ProPromises::game` / enum `ProGamePromise`; до G2.8 `limits = null`) |
| `GET /today` | | `streak.held_by`; `game {daily_available, active_run}` — вход «Караван» на «Сегодня» (вторичное действие) |

**Коды ошибок** (TWA: `KNOWN_ERROR_CODES` + `error.code.*` ru/uz): 403 `game_unavailable`, `game_limit_reached`, `game_pro_required`; 404 `game_run_not_found`, `course_not_found`, `lesson_not_found`, `stage_not_found`, `item_not_found`, `class_caravan_not_found`; 409 `game_run_active {run_id, kind, progress}`, `game_daily_done {resets_at}`, `boss_not_ready {introduced, required, cleared_stops, required_stops}`, `stop_locked` (урок не начат), `element_not_current {current}`, `answer_already_accepted {verdict}`, `pair_already_matched`, `request_id_reused`, `hint_not_available`, `coins_insufficient {balance, price}`, `booster_limit_reached {limit, used, resets_at}`, `booster_not_owned`, `item_not_purchasable {reason}`, `item_already_owned`, `class_caravan_active`, `class_boss_done`, `class_boss_locked`; 410 `game_run_closed {finish_reason}`; 422 `game_not_enough_material {available, required, reason}`, `invalid_answer_shape`, `unknown_element_id`, `unknown_option_id`, `lesson_not_in_course`, `stage_not_in_course`.

**События `product_events`** (`ProductEventName`, после commit, без PII):

| Имя | props | Когда |
|---|---|---|
| `game.run_started` | `run_id, kind, course_id, stage_id, lesson_id, total, free_slot, scope_widened` | старт |
| `game.run_finished` | `run_id, kind, finish_reason, total, answered, correct, typos, wrong, hints, stars, score, coins, league_points, srs_pulled, duration_ms, event` | завершение любого вида (один раз, кто перевёл в `finished`) |
| `game.boss_defeated` | `run_id, stage_id, accuracy, first` | победа |
| `game.daily_completed` | `run_id, due_count, correct` | ежедневный караван завершён |
| `game.coins_earned` / `game.coins_spent` | `amount, kind, ref_type, ref_id, balance` | проводки (кроме админских — те в `audit_logs` и `game.coins_adjusted`) |
| `game.item_bought` / `game.cosmetic_equipped` | `item_key, kind, price` / `slot, item_key` | лавка, профиль |
| `game.booster_used` | `run_id, effect` | |
| `game.limit_reached` | `kind, limit` | отказ Free |
| `game.ticket_granted` / `game.ticket_spent` / `game.ticket_capped` | `source, delta, balance` / `run_id` / `source, lost` | билеты |
| `game.not_enough_material` | `kind, course_id, lesson_id, available, required, reason` | 422 |
| `game.class_started` / `game.class_finished` / `game.class_summary_posted` | `class_caravan_id, group_id, members, target` / `stars_total, runs_total, boss_defeated` / `class_caravan_id` | караван класса (actor — учитель `user`, группа) |
| `game.coins_adjusted` / `game.tickets_adjusted` | `amount, audit_log_id` | админ (actor — `user`, learner) |

Отдельного события на приближение SRS нет — счётчик `srs_pulled` в `game.run_finished`. Метрики §10 считаются из `game.run_*` и `learner_daily_stats.game_*`.

### 12.9 TWA (контракт и поведение, без вёрстки)

Маршруты (`router/index.ts`): `/game` (карта; `?course=`, иначе активный курс), `/game/runs/:id` (забег любого вида), `/game/boss/:stageId` (экран босса → старт), `/game/daily` (экран каравана дня → старт или итог), `/game/caravan` («мой караван»: кошелёк, лавка, косметика), `/game/class/:id`. Сторы: `game` (карта, `/me.game`, лимиты), `gameRun` (активный забег; поколения ответов как `practice`, `pendingRequestId` сохраняется при сетевой ошибке и повторяется), `gameShop`. Composables — `useBackButton`, `useServerClock`, `usePracticeAudio` (клипы по blob — как диктант). Тексты — `game.*` ru/uz, `I18N_UZ_REVIEW.md`. Входы видны только при `me.game.available` (пока `/me` не пришёл — входов нет).

| Экран | Состояния | Поведение |
|---|---|---|
| **Карта** `/game` | `loading` · `ready` · `empty` (курс без начатых уроков: «Начните урок в карточках — откроется первая стоянка», кнопка «Учить») · `unavailable` · `active_run` (баннер «Продолжить забег») | Вертикальная дорога городов по порядку модулей; город — имя Шёлкового пути + название модуля; стоянки — точки со звёздами (0–3), закрытая — серая с подписью «начните урок»; нажатие открытой → лист «Стоянка N: 8–10 заданий, 3 фляги; сегодня бесплатно: 1 из 1 · билеты: 2» → `POST /game/runs`; 403 `game_limit_reached` → `PaywallSheet reason="game"`; друзья — метка у города (до 20); босс — иконка у города: `ready` — кнопка, `not_ready` — подпись «ещё N стоянок», `locked_by_plan` — чип Pro → пейвол; «Караван дня» — карточка сверху (`done_today` → «Сыграно, завтра снова»); 320 px: один столбец, звёзды 12 px, цели ≥ 44 px; `useBackButton` → «Сегодня» |
| **Забег** `/game/runs/:id` | `loading` · `playing` (подвиды по `current.element.kind`) · `event` (баннер «Песчаная буря» / «Оазис» / «Базар» ~1,5 с, затем задание) · `reveal` (вердикт + разбор, «Дальше») · `summary` · `closed` (410) · `error` | Шапка: позиция «3 / 10», фляги (3 иконки; у босса — полоса точности), счёт, комбо-метка с третьего; компоненты видов: `ChoiceTask` (4 варианта ≥ 44 px; `listen` — «Слушать» и повтор), `SpellTask` (поле без автокоррекции, «Не знаю»), `GapTask` (пример с пропуском, поле), `PairsBoard` / `CollocationChoice` / `SentenceBuilder` / `DictationInput` — **переиспользуются из практики** через адаптер props; базар — «Подсказка за 5» (баланс рядом; `coins_insufficient` → тост); бустеры — ряд иконок с остатком; выход — X → «Выйти? Забег не засчитается» → `abandon`; Telegram Back = X; устаревшие ответы отбрасываются поколением; `element_not_current` → перечитать `GET`; потеря сети после ответа — повтор того же `request_id` |
| **Итог забега** (состояние `summary`) | `completed` (звёзды, счёт, монеты «+15», очки лиги «+18», «Серия удержана», новые звёзды) · `failed` («Фляги кончились», ошибки списком, **«Повторить в карточках»** → `/training` `daily_review` — слова уже due · «Ещё раз» (если есть забег дня / билет, иначе пейвол) · «На карту») | `summary {stars, score, coins, league_points, srs_pulled, mistakes[] {label, your_answer, verdict}, streak_held, new_stars, boss {accuracy, won, first} \| null}` |
| **Босс** `/game/boss/:stageId` | `intro` (город, «Хранитель ворот», 12 слов, «нужно 80 %», остаток забегов / билеты) · `locked_by_plan` (пейвол) · `not_ready` · `won` (карточка «Я дошёл до Самарканда», «Поделиться» → `shareUrl(share_link, share_text)`, отметка `shared`) · `lost` («Точность 67 % — вернитесь после повторения») | Старт → `POST /game/runs {kind: boss}` → `/game/runs/:id`; результат — `summary.boss` |
| **Караван дня** `/game/daily` | `ready` («К повторению 14 слов — разминка на 10», кнопка) · `done` (итог дня, «обычное повторение — в карточках») · `empty` («Пока нечего повторять») | `POST /game/runs {kind: daily}`; `game_daily_done` → `done` |
| **Мой караван** `/game/caravan` | `wallet` (баланс, билеты, последние проводки) · `shop` (вкладки «Верблюды / Ковры / Шатры / Рамки / Бустеры»; карточка предмета: цена, «Куплено», «Надеть», чип Pro у `exclusive`) · `confirm` (лист «Купить за 40?») | Покупка → `POST /game/shop/{key}/buy {request_id}`; `coins_insufficient` → «монеты — за забеги и боссов»; экипировка оптимистична с откатом по ошибке; ассеты с прозрачным фоном для обеих тем |
| **Караван класса** `/game/class/:id` | `active` (полоса `stars_total / target_stars`, вклад участников, мой вклад, босс класса: `locked` / `ready` / `done`) · `finished` (итоги) | Вход — карточка на карте и на «Сегодня», пока караван активен |

Общее: `KNOWN_ERROR_CODES` += коды §12.8; `PaywallReason` += `'game'`; vitest: `stores/__tests__/game.spec.ts`, `gameRun.spec.ts` (поколения, повтор `request_id`, `element_not_current`, 410, события), компоненты видов, `designSystem.spec.ts` (≥ 44 px), i18n-страж; e2e `resources/twa/scripts/e2e/game.mjs` (карта → забег всех видов → итог; босс; караван дня; 320 px и тёмная тема). Возможности Telegram из §6.2 (полноэкранный режим, Stories) — не в G2: сверка с Bot API — отдельная задача.

### 12.10 Админка (`/admin`, правила `docs/20` §1; каждая новая страница списка — с тестом бюджета запросов)

| Что | Где | Правила |
|---|---|---|
| Города и стоянки | **нет** сущности: карта выводится из курса; имена городов — `lang/{ru,uz}/game.php` (`game.cities.1 … 24`, дальше «Город N») | владелец правит lang-файл релизом |
| Предметы (косметика, бустеры) | `GameItemResource`: список (kind, slot, price, availability, status, sort), форма (key — только при создании; title/description ru/uz; asset из белого списка; achievement_key — выбор из `AchievementKey`), действия «Опубликовать» / «В архив» | только `admin` (`GameItemPolicy`); список ≤ 6 запросов (`FilamentQueryBudget`) |
| Забеги | `GameRunResource` read-only: фильтры по виду, статусу, курсу, ученику, датам; просмотр: состав (виды), ответы из `game_run_attempts` (без снимка целиком), награды, `srs_pulled` | только `/admin`; список ≤ 8 запросов |
| Кошелёк и билеты ученика | `LearnerResource` → RelationManager «Караван»: баланс, билеты, последние 50 проводок; действия **«Начислить монеты» / «Списать монеты» / «Выдать билеты»** — форма `{amount, reason ≤ 200}` → запись `audit_logs` (`game.coins_adjusted` / `game.tickets_adjusted`) **первой** транзакцией, затем проводка с ключом `admin:{audit_log_id}`; `requiresConfirmation()` | только `admin`; списание не уводит баланс в минус (409 → уведомление) |
| Караваны классов | `ClassCaravanResource` read-only: группа, центр, статус, прогресс, босс, `summary_posted_at`; действие «Отменить» (admin) | в `/org` — страница «Караваны» по `PanelDataScope` (группы учителя): запуск / отмена / просмотр (G2.9b) |
| Виджет | `GameActivityWidget` на дашборде `/admin`: забегов за 7 дней, игравших учеников, доля игравших среди активных (`WidgetCache::remember`, `admin:widget:game_activity:v1:<зритель>`, 300 с, `flexible`) | опрос выключен (`$pollingInterval = null`) |

### 12.11 Караван класса (G2.9)

- **Кто и как запускает.** Учитель — командой `/start_caravan` в активной группе (права — `TeacherAuthority::canTeach`, статус группы — как `/start_exam`), либо из `/org` → «Караваны» → «Запустить» (учитель центра видит только свои группы: `PanelDataScope`). При `class.centers_only = true` (по умолчанию) группа должна принадлежать центру (`telegram_groups.organization_id`) с живой подпиской (`SubscriptionLiveness` по подписке организации) — иначе бот отвечает «Караван класса — для центров» (`game.class.centers_only`). Открытый вопрос владельцу — §12.16 В3.
- **Модель.** §12.2.8. При старте: курс группы, `members_at_start` = активные участники (`group_members.status = active`), `target_stars = max(min_target, members × stars_per_member)`, `boss_snapshot_words` — 12 самых трудных слов класса (§12.3.4), `ends_at = now + 7 суток`. В чат — «Караван класса отправился! Цель — N звёзд за неделю» с web_app-кнопкой `/twa/game/class/{id}` (`ClassCaravanMessenger`, через глобальный лимит исходящих, как `BattleMessenger`).
- **Движение.** Любой завершённый забег участника (стоянка, босс, караван дня) в окне каравана добавляет его звёзды и один забег в `class_caravan_members` и `class_caravans` (§12.5.3 п. 6). Босс класса открывается при `stars_total ≥ target_stars`; каждый участник бьётся **один раз** (`class_boss_key`), бой — забег `kind = class_boss` без фляг на `boss_snapshot_words`, победа ≥ 80 %, `economy.class_boss` монет; караван «победил босса», когда побед ≥ `ceil(участников с забегами / 2)`.
- **Итоги недели — ровно один раз.** `game:class-caravans-close` (каждые 15 мин) берёт караваны `status = active AND ends_at <= now` по одному под `FOR UPDATE`: `status = finished`, `finished_at`, `active_group_id = NULL`, после commit — `PostClassCaravanSummaryJob(id)` (`ThrottleTelegramOutbound`, `tries = 0`, `maxExceptions = 3`, `retryUntil` +6 ч). Job: перечитать караван, `summary_posted_at !== null` → выход; `Cache::lock("game:class-summary:{id}", 120)->block(10)`: перечитать под замком, отправить текст (топ-5 вкладов, `stars_total / target`, забегов, босс побеждён / нет; язык группы — `LocaleResolver::forGroup`), `UPDATE summary_posted_at`, событие `game.class_summary_posted`; ошибка Telegram → лог `game.class_summary_failed`, исключение, повтор. Повторная постановка (два планировщика, ретрай) — одно сообщение (`ClassCaravanConcurrencyTest`). Группа без бота или неактивная → итоги не отправляются, маркер ставится с `meta.summary_skipped = group_inactive`.
- **Отмена.** Учитель (`/cancel_caravan` или `/org`) или админ: `status = canceled`, `active_group_id = NULL`, итоги не отправляются; забеги участников остаются их личными.
- **Права.** Ученик видит караваны только **своих** активных групп (`GET /game/class`); чужой id — 404. Учитель в `/org` — только группы из `teacher_groups` своего центра; `ClassCaravanPolicy` (`/org`: `viewAny / create / cancel` по `PanelDataScope`; `/admin`: admin).
- **Бот:** `docs/04` B.3 += `/start_caravan`, `/cancel_caravan` (группа, teacher), меню `all_chat_administrators` += обе; B.15 — тексты сообщений каравана.

### 12.12 Кэш (`docs/19` §3; все ключи — через `PureCache::store()`, кроме помеченных)

| Ключ | Что | TTL | Инвалидация | Хранилище |
|---|---|---|---|---|
| `game:friends:v1:{learner}` | друзья на карте: массив `{friendship_id, learner_id, course_id, city_number, stops_cleared, camel_asset}` по всем курсам принятых друзей (карта фильтрует свой курс) | 300 с | TTL (устаревание до 5 мин у чужого прогресса допустимо); сброс при изменении дружбы — `FriendshipService` после commit (`forget` обеих сторон) | `lru` |
| `game:shop:v1:{locale}:n{версия}`; версия `game:shop:ver` | опубликованный каталог предметов (массивы) | 1800 с | счётчик версии: `GameItemObserver` после commit любого сохранения / удаления предмета | значение `lru`, версия `redis` |
| `game:class:v1:{caravan}:standings` | вклад участников каравана (массивы) | 60 с | TTL (как `league:standings`); у `finished` читается из БД и не кэшируется | `lru` |
| `league:standings:v2:{group}` | версия формата поднимается (очки включают игру) | как было | как было | `lru` |
| `admin:widget:game_activity:v1:<зритель>` | виджет | 300 с, `flexible` | TTL | `lru` |

**Не кэшируется** (правило 9): доступ, лимиты и билеты, баланс монет, активный забег, прогресс карты самого ученика (один запрос по своим строкам), набор босса, due-слова каравана дня. Имена городов — lang, не кэш. Каждому ключу — тест «записал → старое не отдаётся» (`tests/Feature/Cache/GameCacheTest`); `docs/19` §2.2 дополняется строками.

### 12.13 Тесты (сводно; PG-гонки — §12.5.4)

| Файл | Сценарии |
|---|---|
| `tests/Unit/Game/RunComposerTest` | детерминизм от seed, размеры 6..10, пропуск невозможных видов, авторские ≤ 3 и не первыми, один раунд пар, события (буря только при аудио), `scope_widened` |
| `tests/Unit/Game/RunScoringTest` | очки, комбо с третьего, `typo`, подсказка (0 очков, без фляги), `skipped`, третья фляга → `failed`, оазис, `second_chance`, звёзды по ошибкам, босс без фляг и порог 80 % |
| `tests/Unit/Game/WordTaskHandlerTest` | вердикты пяти видов, формы `gap`, неизвестный вариант, проекция без запретных ключей |
| `tests/Feature/Game/GameAccessTest` | `off` / `pilot` / `all`, блок `/me.game`, `pro_promise` |
| `tests/Feature/Game/GameLimitsTest` | бесплатный забег дня, билет, 403 с `details`, брошенный без ответов не считается, Pro без лимита, боссы по `boss_stages`, бустеры в неделю, окно после смены пояса |
| `tests/Feature/Game/GameRunTest` | старт стоянки / босса / каравана дня, `stop_locked`, 409 активного и `replace_active`, восстановление (та же раскладка), 410, повтор `request_id` байт-в-байт, `element_not_current`, подсказка базара и `coins_insufficient`, бустеры, `abandon`, итог и `summary.mistakes` |
| `tests/Feature/Game/GameSrsRuleTest` | **правило §5**: после ошибки `next_review_at = now`, `is_hard = true`, остальные колонки байт-в-байт прежние (ученики SM-2 и FSRS; карточки `new` / `learning` / `review` / `relearning`); верный ответ не меняет строку; `typo` не меняет; подсказка меняет; повтор `request_id` не удваивает `misses`; обычная оценка после приближения считается по `docs/06` (эталонные числа); `training_reviews` не пополняется |
| `tests/Feature/Game/GameIsolationTest` | полный прогон всех видов забегов: контрольные суммы `training_sessions`, `training_reviews`, `practice_*`, `battles`, `assignment_results`, `challenges`, `referrals`, `league_members` равны; в `word_repetitions` менялись только `next_review_at`, `is_hard`, `updated_at` и только у слов-ошибок; `learner_daily_stats` — только `game_*`; события только из §12.8 |
| `tests/Feature/Game/GameLeagueStreakTest` | потолок 40 (третий забег даёт 0), `game_held_at` один раз, серия `+1` без цели, заморозка и игра, размещение в лиге игрой, `league:standings:v2` с очками игры, `/today.streak.held_by` |
| `tests/Feature/Game/GameWalletTest` | начисления по видам (первое / повтор / звёзды), `SUM(ledger) = balance`, покупка, экипировка только своего, `exclusive` только Pro, `admin_grant` с аудитом |
| `tests/Feature/Game/GameTicketsTest` | +1 на 7-й день серии (один раз), +3 за реферал (повтор `reward` — один раз), потолок и `ticket_capped`, трата при старте |
| `tests/Feature/Game/BossTest` | подбор 12 самых трудных (порядок), `boss_not_ready`, победа / поражение, награда первая / повторная, достижение и карточка один раз |
| `tests/Feature/Game/DailyCaravanTest` | только due-слова, добор, `game_daily_done`, верные остаются due |
| `tests/Feature/Game/GameContractTest` | `assertExactJsonStructure` всех ответов §12.8, запретные ключи, `details` где описан |
| `tests/Feature/Game/GameQueryBudgetTest` | бюджеты: карта ≤ 10, старт ≤ 14, показ ≤ 3, ответ ≤ 7 (последний, с завершением, ≤ 14), лавка ≤ 4, кошелёк ≤ 3, `/me` +1 |
| `tests/Feature/Game/ClassCaravanTest` | старт (права, центр, одна активная), вклад, босс класса (один раз, порог побед), закрытие и итоги один раз, отмена, видимость чужих, `/org` scope |
| `tests/Feature/Game/GameAdminTest` | Filament: предметы, забеги, кошелёк и действия с аудитом, teacher → 403; бюджеты списков |
| `tests/Feature/Cache/GameCacheTest` | ключи §12.12 |
| `tests/Feature/Game/RunSnapshotSizeTest` | 40 КБ на худшем составе, запас ≥ 25 % |
| `DatabaseSchemaTest` | rollback пяти миграций |

### 12.14 Нарезка задач и волны

Таблица §9 заменена этой. Каждая задача — размер «одна задача coder» (сложные — architect); **документы правятся до кода** (столбец «Docs»).

| # | Задача | Исполнитель | Зависит от | Docs до кода | Готово, когда |
|---|---|---|---|---|---|
| G2.0 | Технический проект (этот раздел) | architect | — | — | ✅ 03.11.2026 |
| G2.1 | **Схема и каркас:** пять миграций §12.2.10, модели, enum'ы, `config/game.php`, фичи `game.*` в `billing.php`, `GameAccess` + блок `/me.game`, `GameException`, `CityNames` + `lang` `game.cities.*` ru/uz, каркас `GameIsolationTest` | coder | G2.0 | `docs/03` §2.48–§2.61, `docs/04` A.2 | `DatabaseSchemaTest`, `GameAccessTest`, `TwaContractTest` (`/me.game`) зелёные |
| G2.2a | **Ядро забега (architect):** `RunSnapshotBuilder` (стоянка), `RunComposer`, `RunScoring`, `TaskHandler` / `TaskRegistry`, `PracticeTaskAdapter`, **`WordTaskHandler`** (сборка пяти видов из пула, `grade` / `project` / `reveal` / `hint`; перенесено сюда из G2.2b: полные прогоны `GameIsolationTest` и гонки без него невозможны), `MaterialScope::forStage`, `RunService` (start стоянки / find / view / answer / hint / abandon / closeExpired — без наград; `open()` для забегов других видов), `GameSrsAdjuster`, `WalletService::debit` (списание за подсказку базара; остальной кошелёк — G2.5a); `RunComposerTest`, `RunScoringTest`, `GameRunServiceTest`, `GameSrsRuleTest`, `GameRunConcurrencyTest` (старт, ответы, `request_id`, подсказка, close-expired), прогоны в `GameIsolationTest` | architect | G2.1 | `docs/06` §5a, `docs/13` §3 (правила 17–23), `docs/16` §9 ссылка | PG-гонки зелёные; правило §5 закреплено тестом на SM-2 и FSRS |
| G2.2b | **Задания из слов — обвязка:** `WordTaskHandlerTest` (вердикты пяти видов, формы `gap`, неизвестный вариант, проекция без запретных ключей), тексты `mistakeLabel` / `reveal` для итога, `audio_url` в проекции `listen` (маршрут аудио G2.2c), i18n подписей | coder | G2.2a | — | все виды проходят в `GameRunTest`; запретные ключи не утекают |
| G2.2c | **API забега:** `GameRunController`, Requests, `GameRunResource` / `GameAnswerResource`, аудио, лимитеры, маршруты; `GameContractTest`, `GameQueryBudgetTest` (забег) | coder | G2.2a, G2.2b | `docs/04` A.18 | контракт и бюджеты зелёные |
| G2.5a | **Кошелёк (architect):** `WalletService` (`credit` — новое; `debit` из G2.2a — проверить и расширить; идемпотентность, `balance_after`), `game:wallets-verify`, `RunFinisher` (транзакция завершения §12.5.3 без каравана класса; заменяет `RunService::finish()` из G2.2a в той же точке транзакции ответа), `GameWalletConcurrencyTest`, `GameWalletTest` (инвариант). Уточнения реализации — §12.5.3 «Реализация G2.5a» (прогресс до кошелька, профиль инкрементом, награды в пограничных случаях, `RunEventRecorder`) | architect | G2.2a | `docs/13` §3 | PG: параллельные списания не уводят в минус; повтор ключа не начисляет дважды; два последних ответа — одна награда |
| G2.10 | **Лига и серия (architect):** правки `StreakService` (`counts`, `game_held_at`), `LeagueService` (`+ game_points`, `v2`), размещение из игры, хук `TicketService::grantForStreak`, `/today.streak.held_by`; `GameLeagueStreakTest` | architect | G2.5a | `docs/13` §2.4, §7.3; `docs/03` §2.22; `docs/12` §7.4; `docs/06` §6a; `docs/19` §2.2 | потолок 40; день удержан игрой; все существующие тесты серии и лиг зелёные |
| G2.1m | **Карта:** `MapService` (открытость из `word_repetitions`, звёзды, боссы, друзья, кэш `game:friends`), `GameMapController` / `GameMapResource`; стоянка открывается ровно когда урок начат | coder | G2.1 | `docs/04` A.18, `docs/19` §2.2 | `GameMapTest`, бюджет ≤ 10, `GameCacheTest` (friends) |
| G2.3 | **Ежедневный караван:** `DailyCaravanService` (due-слова, добор, `daily_key`), `/today.game`; `DailyCaravanTest` | coder | G2.2c, G2.5a | `docs/04` A.2a | только due-слова; верные остаются due; один в день |
| G2.4 | **Боссы:** `BossWordPicker`, `BossService` (готовность, `boss_stages`, награда, `game_city_progress`), достижение `game_first_boss`, `CardSpec` + `AchievementCardRenderer::renderSpec`, `SendGameCardJob`; `BossTest` | coder | G2.2c, G2.5a | `docs/13` §7.4 | набор в снимке; Free — первый город; карточка один раз |
| G2.5b | **Лавка, бустеры, инвентарь, профиль:** `ShopService`, `GameItemObserver` + кэш каталога, бустеры в забеге (`RunService::booster`, `game_booster_uses`, недельный лимит), экипировка, `GameShopController` / `GameProfileController` / `GameWalletController` + Resources, сидер базовых предметов (3 верблюда, 2 ковра, 2 шатра, 2 рамки, 3 бустера) | coder | G2.5a | `docs/04` A.18, `docs/19` §2.2 | `GameWalletTest` (лавка), `GameCacheTest` (shop), бюджеты |
| G2.6 | **Косметика в профиле и у друзей:** экипированный верблюд на карте друзей, в `/friends` и `/profile`; ассеты TWA (обе темы) | coder | G2.5b, G2.1m, G2.7a | `docs/05` §5b | предметы видны в профиле и на карте друзей |
| G2.8 | **Билеты и пейвол:** `GameLimits` (claim под замком ученика, бустеры), `TicketService` (хук в `ReferralService::reward`, потолок, админ), `PaywallReason 'game'`, `ProPromises` для игры; `GameLimitsTest`, `GameTicketsTest`, гонки билетов в `GameRunConcurrencyTest` | coder (гонки — по образцу G2.2a) | G2.2c, G2.5a | `docs/18` §3, `docs/04` A.2, `docs/13` §7.1 | лимиты из конфига; пейвол `game`; PG: один билет — один забег |
| G2.7a | **TWA: карта и забег** (`/game`, `/game/runs/:id`, сторы `game`, `gameRun`, компоненты видов, адаптеры к компонентам практики, итог, пейвол `game`) | coder | G2.2c, G2.1m, G2.8 | `docs/05` §5b | vue-tsc, vitest, сборка; 320 px, обе темы, Telegram Back |
| G2.7b | **TWA: босс и караван дня** (`/game/boss/:stageId`, `/game/daily`, карточка шеринга, входы на `/today`) | coder | G2.7a, G2.3, G2.4 | `docs/05` §5b | то же |
| G2.7c | **TWA: мой караван** (`/game/caravan`: кошелёк, лавка, бустеры, экипировка) | coder | G2.7a, G2.5b | `docs/05` §5b | то же |
| G2.9a | **Караван класса — ядро (architect):** `ClassCaravanService` (start / contribute / boss / finish / cancel), вклад в `RunFinisher`, `BossWordPicker` по группе, `PostClassCaravanSummaryJob`, `game:class-caravans-close`; `ClassCaravanConcurrencyTest`, `ClassCaravanTest` (сервисы) | architect | G2.5a, G2.4 | `docs/03`, `docs/13` §3 | итоги уходят ровно один раз; одна активная на группу |
| G2.9b | **Караван класса — обвязка:** `/start_caravan`, `/cancel_caravan`, `ClassCaravanMessenger`, меню команд, `/org` страница «Караваны» (`PanelDataScope`, бюджет), `ClassCaravanController` / `Resource`, TWA `/game/class/:id` | coder | G2.9a | `docs/04` B.3, B.15, A.18; `docs/05` §5b; `TEACHER_GUIDE`, `ADMIN_GUIDE` | учитель видит только свои группы; `/org` тест бюджета |
| G2.11 | **Админка:** `GameItemResource`, `GameRunResource`, RelationManager кошелька с действиями и аудитом, `ClassCaravanResource`, `GameActivityWidget`; `GameAdminTest` | coder | G2.5b, G2.9a | `ADMIN_GUIDE`, `docs/20` | бюджеты списков; аудит каждой ручной проводки |
| G2.12 | **Контент:** авторские задания для карты A1–A2 — по 1–2 на урок (сочетание / сборка / диктант фразы), черновики Claude → `practice:import` без `--publish`, владелец ставит «Проверено» / «Опубликовать»; `docs/CONTENT_A1_A2.md` | coder (черновики), владелец (проверка) | G2.2c | `docs/CONTENT_A1_A2.md` | пакет импортируется в тесте; количество по урокам в документе |
| G2.13 | **Ревью G2 и пилот:** ревью всего diff, исправления, повторное ревью, `GAME_ACCESS=pilot` → `all`, метрики §10 в виджете, `PROGRESS.md` | reviewer, coder, координатор | всё | `docs/18` статус | блокирующих нет; pint, PHPStan 8, Pest (SQLite + PG), vitest, vue-tsc зелёные |

**Волны (что параллельно):**

1. **Основание:** G2.1 (coder). Затем параллельно: G2.2a (architect) ∥ G2.1m (coder).
2. **Забег и деньги:** G2.2b (coder) ∥ G2.5a (architect); затем G2.2c (coder) ∥ G2.10 (architect).
3. **Виды игры:** G2.3 ∥ G2.4 ∥ G2.5b ∥ G2.8 — четыре coder-задачи, независимые между собой.
4. **TWA и класс:** G2.7a (coder) ∥ G2.9a (architect); затем G2.7b ∥ G2.7c ∥ G2.9b ∥ G2.6 ∥ G2.11 ∥ G2.12.
5. **Ревью:** G2.13.

Критический путь: G2.1 → G2.2a → G2.2b → G2.2c → G2.7a → G2.7b. Ориентир для одного исполнителя, знакомого с проектом: 18–24 рабочих дня без контента и ревью (оценка, не обещание).

### 12.15 Решения, принятые в этом проекте (без вопроса владельцу)

1. **Таймера в забеге нет;** «замедление времени» (§2.7) заменено бустером «второй шанс» (первая ошибка забега не стоит фляги). Таймер появится с ареной (G3), где он сравнивает двух игроков.
2. **Карта выводится из курса**, своих сущностей «город / стоянка» нет; имена городов — статичный список по номеру модуля (`lang`), курс любой тематики ложится на Шёлковый путь.
3. **Стоянка открыта** ⇔ есть `word_repetitions` по активному слову урока (урок начат). **Босс доступен** при ≥ 8 введённых слов города и ≥ 3 очищенных стоянок (или всех, если их меньше).
4. **Ошибка для SRS** — `wrong`, `skipped`, ответ после подсказки; `typo` — нет. Приближение — до **сейчас**, с `is_hard = true`. Авторское задание с ошибкой приближает все его целевые слова.
5. **Раунд пар стоит максимум одну флягу** за раунд с ошибками.
6. **Монеты:** ставки одинаковые для Free и Pro; награда за стоянку — за первое прохождение и новые звёзды, повтор — 2 монеты; босс — 50 за первую победу, 5 за повторные; покупка монет невозможна (нет ни маршрута, ни вида проводки `purchase`).
7. **Косметика `exclusive` / `seasonal`** требует Pro **при покупке** и остаётся после его конца; `achievement`-предметы выдаются при открытии достижения, их нельзя купить.
8. **Билеты не сгорают,** потолок 10, копятся и у Pro (пригодятся после подписки); за серию — каждые 7 дней, за друга — 3 пригласившему.
9. **День, удержанный игрой, считается в серию как день с целью** (`+1`), не как заморозка; событие `daily_goal.met` игрой не пишется.
10. **Потолок лиги — 40 очков в день,** один для всех (§12.6).
11. **Пилот игры** использует флаг пилота практики `learners.practice_pilot_at` (одна аудитория пилота).
12. **Снимки и попытки завершённых забегов не удаляются** (задел под «призраков» G3); ретенция — решение G3.
13. **Караван класса** — по умолчанию только центры с живой подпиской (`GAME_CLASS_CENTERS_ONLY=true`); длительность 7 суток; цель `6 звёзд × участников`, не меньше 12; босс класса — один бой на участника, караван побеждает при половине побед.
14. **«Повторить в карточках»** на итоге ведёт в обычное `daily_review` (слова уже due по §12.4), отдельного режима «ошибки забега» нет.

### 12.16 Открытые вопросы владельцу

**Решено владельцем 03.11.2026 (опрос): все три — по рекомендации.** В1 — любой завершённый забег (`completed` / `failed`) удерживает день серии; В2 — потолок лиги 40 очков в день (`GAME_LEAGUE_DAILY_CAP`); В3 — караван класса только центрам с живой подпиской (`GAME_CLASS_CENTERS_ONLY=true`). Задачи G2.10 и G2.9a не заблокированы.

| # | Вопрос | Рекомендация (по умолчанию) | Блокирует |
|---|---|---|---|
| В1 | **Серия:** забег любого вида — даже проваленный (`failed`) — удерживает серию дня наравне с целью по карточкам? Или только `completed`, либо только вместе с минимумом карточек? | Любой **завершённый** (`completed` / `failed`) забег удерживает день и считается `+1` — буквально «забег — занятие дня» (§11 п. 5) | G2.10 |
| В2 | **Потолок лиги 40 очков в день** (два безошибочных забега; Free достигает ежедневно без билетов) — принять? | 40, одна env-переменная `GAME_LEAGUE_DAILY_CAP` | G2.10 |
| В3 | **Караван класса — только центрам с подпиской** (`telegram_groups.organization_id` + живая подписка) или всем учителям с группами? | Только центрам (§2.8: «довод для тарифа центра»); переключатель `GAME_CLASS_CENTERS_ONLY` | G2.9a |

Остальное решено в §12.15 и меняется env-переменными §12.7.1 без релиза.
