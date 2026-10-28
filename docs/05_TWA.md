# 05. Telegram Web App (TWA) — Спецификация

## 1. Стек и структура

- **Framework:** Vue 3 (Composition API) + `<script setup>`.
- **Build:** Vite 5.
- **State:** Pinia.
- **Routing:** Vue Router (history mode внутри TWA).
- **HTTP:** ofetch или axios.
- **UI:** TailwindCSS 4 (`@tailwindcss/vite`, конфиг в `src/style.css`: `@theme`, токены `--tg-*`) + локальные компоненты (без тяжёлой UI-либы, чтобы размер bundle был маленьким).
- **Telegram SDK:** `@twa-dev/sdk`.
- **Icons:** Heroicons 2.2 (MIT), **вендорные** — пути SVG лежат в `src/components/ui/icons.ts` (50 иконок, outline + solid; лицензия в шапке файла), рисует их `AppIcon`; npm-пакета иконок нет. Файл собирается в отдельный чанк `icons` (`vite.config.ts`), чтобы он кешировался между релизами.
- **Дизайн-система:** токены, CSS-компоненты (`.page`, `.card`, `.btn-*`, `.field`, `.list`, …) и UI-компоненты `components/ui/*` — `docs/14_UX_AUDIT_AND_REDESIGN.md` §4; API компонентов описан в JSDoc каждого файла.

Размер цели: первый бандл ≤ 180 KB gzip.

**Совместимость WebView (Tailwind 4):** CSS использует cascade layers, `@property`, `color-mix()`; официально поддерживаются Safari 16.4+, Chrome 111+, Firefox 128+. В проекте нет `browserslist` (JS-таргет `es2020`), CSS-фолбэков для старых WebView нет: на Android с System WebView < 111 (старые устройства без обновления WebView) стили могут отображаться некорректно/пропасть. Риск принят владельцем; при росте жалоб — вернуться на Tailwind 3.4 или добавить проверку версии WebView.

### Структура

> Сокращённая схема: исходная раскладка MVP плюс то, что добавил редизайн. В дереве больше экранов (все — в таблице §3), сторов (по одному на область: `today`, `courses`, `decks`, `billing`, `settings`, `league`, `friends`, …) и файлов (в `api/` сейчас `client.ts`, `index.ts`, `types.ts`). Актуальное дерево — `resources/twa/src`.

```
resources/twa/
├── index.html
├── vite.config.ts
├── src/
│   ├── main.ts
│   ├── App.vue
│   ├── router/
│   │   └── index.ts
│   ├── stores/
│   │   ├── auth.ts
│   │   ├── training.ts
│   │   └── exam.ts
│   ├── api/
│   │   ├── client.ts        # fetch-обёртка с JWT
│   │   ├── auth.ts
│   │   ├── training.ts
│   │   └── exam.ts
│   ├── i18n/
│   │   ├── index.ts         # t(), tn(), useI18n(), initLocale()
│   │   ├── ru.ts            # словарь-эталон (ключи выводятся из него)
│   │   ├── uz.ts            # узбекский (латиница); обязан иметь те же ключи
│   │   └── errors.ts        # error.code -> локализованный текст
│   ├── composables/
│   │   ├── useTelegram.ts   # обёртка над @twa-dev/sdk
│   │   ├── telegramTheme.ts # themeParams -> CSS-переменные, data-theme, цвет шапки (§8)
│   │   ├── useBackButton.ts # системная «Назад» Telegram: показ/скрытие + переход (§8)
│   │   ├── useToast.ts      # короткие уведомления («Сохранено»), рисует AppToast
│   │   ├── format.ts        # formatDate(iso, style, locale) / formatNumber с кешем Intl
│   │   ├── useTts.ts
│   │   └── useTimer.ts
│   ├── views/
│   │   ├── LandingView.vue
│   │   ├── TodayView.vue
│   │   ├── TrainingView.vue
│   │   ├── ExamView.vue
│   │   ├── ExamResultView.vue
│   │   └── ...              # остальные экраны — таблица маршрутов (§3)
│   └── components/
│       ├── ui/              # общие UI-компоненты (docs/14 §4.4): AppIcon + icons.ts, PageHeader, LoadingState,
│       │                    #   ErrorState, EmptyState, ListGroup/ListRow, SegmentedControl/SegmentedNav,
│       │                    #   ToggleSwitch, TabBar, AppToast, ProBenefits, InlineAlert (ошибка / подтверждение в потоке экрана)
│       ├── FlashCard.vue
│       ├── QuizOption.vue
│       ├── Leaderboard.vue
│       └── ...
└── package.json
```

## 2. Bootstrap

```ts
// src/main.ts
import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import WebApp from '@twa-dev/sdk'

WebApp.ready()
WebApp.expand()

const app = createApp(App)
app.use(createPinia())
app.use(router)
app.mount('#app')
```

> Упрощённый пример: актуальный `main.ts` до монтирования ещё вызывает `initTelegramTheme()` (§8) и `initLocale()` (§8a).

## 3. Маршруты

| Path | View | Вкладка (`meta.tab`) | Описание |
|------|------|------|----------|
| `/` | LandingView | — | Без контекста сессии внутри Telegram сразу переходит (`replace`, с query) на `/today`; вне Telegram показывает подсказку. |
| `/today` | TodayView | `today` | «Главная» (docs/14 §6.1): приветствие с датой и чип Free/Pro (→ `/billing`); баннер «Pro закончился» для Free (`LapsedBanner`); карточка занятия — кольцо цели дня, «N слов к повторению», новые слова на сегодня и **одна** главная кнопка «Начать занятие» / «Продолжить занятие» (`daily_review`; ошибка открытия — `InlineAlert` под кнопкой); серия (streak, заморозки); «Задания центра»; «Мой курс» (прогресс, «Следующий урок», «Учить урок»); строка лиги → `/league`. Пяти кнопок-меню больше нет — навигация во вкладках. Данные — `GET /today` (docs/04 A.2a), сторы `today`, `assignments`. По ссылкам из бота: `?from=reminder` сразу открывает занятие, `?from=assignment&lesson=<id>` — урок задания. |
| `/courses`, `/courses/:slug` | CoursesView, CourseView | `courses` | Каталог курсов («Сделать основным») и экран курса (docs/14 §6.5): прогресс, в основном курсе «Продолжить: модуль M, урок N», в остальных «Сделать основным»; «Пробный тест» (IELTS / SAT, Pro); модули и уроки со статусами. |
| `/decks`, `/decks/:slug` | MyDecksView, DeckEditView | `courses` | Свои колоды и редактор колоды. |
| `/league`, `/friends` | LeagueView, FriendsView | `rating` | Недельная лига; друзья и вызовы. |
| `/profile` | ProfileView | `profile` | Профиль (корень вкладки): имя, тариф Free/Pro, вход в достижения, приглашения, друзей, подписку и настройки, контакт поддержки. Данные — `GET /me` (стор `billing`) и имя из стора `auth`; новых эндпоинтов нет. |
| `/achievements`, `/referrals`, `/billing`, `/settings` | AchievementsView, ReferralsView, BillingView, SettingsView | `profile` | Экраны, в которые ведёт «Профиль». |
| `/finished` | FinishedView | — | «Итог занятия» (§5.3, docs/14 §6.3); query `reviewed`, `next`, `reason`. Данные личной сессии — из `finish`-summary (стор `training.summary`); кнопка «На главную» → `/today`; при `done_reason = new_quota_exhausted` открывает `PaywallSheet` (docs/04 A.3, 1.4b). |
| `/training/:sessionId` | TrainingView | — | Тренировка: карточки и упражнения, действия внизу экрана (§5.2). |
| `/exam/:sessionId` | ExamView | — | Экзамен с таймером (§6.3): групповой и личный «Пробный тест» (`?mode=mock`). |
| `/exam/:sessionId/result` | ExamResultView | — | Итоги: результат и «Топ участников» либо процент пробного теста (§6.4). |

Определяем режим по параметрам query из ссылки, которую бот прислал.

**Нижняя навигация.** `meta.tab` (`today` | `courses` | `rating` | `profile`, тип `TabId` в `router/index.ts`) указывает, к какой вкладке относится экран. `App.vue` показывает `TabBar` только для маршрутов с `meta.tab` и отодвигает под него содержимое; активная вкладка — по `route.meta.tab`, поэтому внутренние экраны (курс, настройки) оставляют свою вкладку подсвеченной. «Погружение» (`/training/*`, `/finished`, `/exam/*`, лендинг) идёт без таб-бара. Состав вкладок — docs/14 §5.1.

## 4. Аутентификация

```ts
// src/composables/useTelegram.ts
import WebApp from '@twa-dev/sdk'

export function useTelegramInitData(): string {
  const data = WebApp.initData
  if (!data) throw new Error('TWA opened outside Telegram')
  return data
}
```

```ts
// src/stores/auth.ts
export const useAuth = defineStore('auth', {
  state: () => ({ token: '' as string, expiresAt: 0, learner: null as Learner | null }),
  actions: {
    async login(context: { type: 'training' | 'exam'; sessionId: number }) {
      const initData = useTelegramInitData()
      const res = await api.auth.authenticate(initData, context)
      this.token = res.token
      this.expiresAt = Date.now() + res.expires_in * 1000
      this.learner = res.learner
    },
    async ensure(context) {
      // One learner for every session: a context change needs no new token.
      if (!this.token || Date.now() > this.expiresAt - 60_000) {
        await this.login(context)
      } else {
        this.context = context
      }
    },
  },
})
```

> Схема, а не копия: в коде стор называется `useAuthStore` (`stores/auth.ts`), `initData` берётся из `useTelegram().initData()`, запрос идёт через `api.auth.authenticate`; перед запросом экран зовёт `auth.ensure()` (контекст сессии — по необходимости).

Перед каждым запросом вызываем `auth.ensure({ type, sessionId })`; если бек вернул 401 — пробуем один раз переавторизоваться (`api/client.ts`). Смена контекста новый токен не требует: learner один на все сессии, доступ к сессии проверяет сервер по членству (403 `not_a_member`). `context` остаётся только для аналитики.

## 5. Режим «Training»

### 5.1 Контракт состояния (Pinia)
```ts
interface TrainingState {
  sessionId: number | null
  lesson: { stage: number; lesson: number; title?: string } | null
  card: Card | null
  progress: { done: number; total: number }
  showTranslation: boolean
  isFinished: boolean
}
```

> Устарело: схема MVP. Актуальное состояние — `stores/training.ts`: `status` (`idle` / `starting` / `loading` / `ready` / `submitting` / `verdict` / `held` / `finished` / `closed` / `error`), `mode`, `card` и `exercise` (по `prompt.type`), `verdict` (оценка набранного ответа), `deferred` (карточка из ответа `/review`, придержанная до «Дальше»: при `verdict` — до вердикта набранного ответа, при `held` — до того, как ученик увидел правильный вариант), `progress`, `lesson`, `summary` (итог для `/finished`).

### 5.2 Экран тренировки

> Макет и состояния — docs/14 §6.2; ниже — что делает код (`TrainingView` и компоненты упражнений).

Иммерсивный экран без таб-бара: шапка, карточка по центру и **действия внизу экрана** — липкий блок `StickyActions` (под большой палец, с отступом под home-индикатор).

- **Шапка:** ✕, полоса прогресса и счётчик «7 / 25»; под ней «Повторение» (ежедневное занятие, `daily_review`) или «Модуль 2 · Урок 4 · Еда и напитки» (урок). ✕ и системная «Назад» — одно действие: в личной сессии (`lesson`, `daily_review`) они ведут на «Главную» (`/today`), в групповой (`group_lesson`, открыта из чата группы) закрывают Mini App (docs/14 §5.3). Уход ничего не теряет: оценка уходит на сервер в тот момент, когда ученик её дал (нажал оценку флеш-карты, выбрал вариант, отправил набранный ответ), а не при показе следующей карточки.
- **Упражнение** выбирает `prompt.type` выданной карточки (docs/13 §2.5); карточка без `prompt` — флеш-карта.
  - **Флеш-карта** (`flash`). Чип типа по `card_kind`: `new` — «Новое слово», `learning` — «Закрепление» (карточка в минутных learning steps, вернётся в этой же сессии), `due` — «Повторение». Круглая кнопка озвучки — `useTts().speak(...)` (§7), слово (36 px, bold), транскрипция. Внизу одна кнопка «Показать перевод»; после неё — перевод с примером, вопрос «Насколько хорошо вы помните слово?» и три кнопки с подсказкой, что будет с карточкой: **«Не помню»** («повторим сегодня»), **«Помню»** («по расписанию»), **«Легко»** («покажем реже»).
    Оценка уходит в `POST /review` как `quality` **2 / 4 / 5** (Again / Good / Easy, docs/06 §2). Значения прежние, изменились подписи: старая «Трудно» на деле означала провал (q = 2) и сбрасывала прогресс по слову.
  - **«4 варианта»** — «Выберите английское слово» (`reverse`: перевод → слово) и «Что вы услышали?» (`audio`: слово на слух → перевод). Четыре варианта-кнопки под карточкой, оценивает клиент. **Оценка уходит в `POST /review` сразу при выборе** (верный — `quality` 4, неверный — 2), один раз на карточку: если ученик после выбора закроет экран (✕, системная «Назад»), ответ уже сохранён. Карточка из ответа `/review` придерживается (стор `training`: статус `held`, поле `deferred`; экран вердикта набранного ответа здесь не показывается), пока ученик не увидел правильный ответ. Верный выбор подсвечивается зелёным, следующая карточка появляется через 0,7 с после выбора (если ответ сервера пришёл позже — сразу по приходу). **Неверный** подсвечивается красным, правильный вариант и пример остаются на экране, а переход — по кнопке **«Дальше»** внизу: она лишь показывает придержанную карточку и ничего не отправляет; нажатая, пока ответ сервера ещё в пути, она ждёт (спиннер) и открывает карточку по его приходу. Ошибка при отправке — обычный экран ошибки с «Повторить».
  - **Написание** (`spelling`) и **пропуск в примере** (`gap_fill`), Pro: поле ввода внизу (крупный шрифт, «Букв: 9»), «Проверить» и «Не знаю» (пустой ответ = промах); ответ оценивает сервер (`POST /review` с `answer`). Затем **вердикт**: иконка в цветном круге, «Верно!» / «Почти!» / «Неверно», «Правильный ответ» крупно, свой ответ зачёркнутым, кнопка «Дальше».
- После оценки флеш-карты, после вердикта набранного ответа или после «Дальше» / паузы в «4 вариантах»: очищаем карточку и показываем следующую из ответа `/review`. Если следующая — `null`, сессия закончена: клиент запрашивает `finish` и заменяет маршрут на `/finished` — один раз, как только статус стора стал `finished`, как бы он до него дошёл (оценка, «Дальше», «Повторить», сессия без карточек с самого начала). Если ученик к этому моменту уже ушёл с экрана (✕, «Назад»), ответ сервера никуда его не переносит. Сервер не вернёт `null`, пока в уроке остаются learning-карточки (learn-ahead, docs/06 §4), поэтому клиенту ждать/опрашивать не нужно.

### 5.3 Итог занятия (`/finished`)

Иммерсивный экран без таб-бара (docs/14 §6.3).

- «Отлично!» в зелёном круге и причина окончания по `done_reason` («Все слова урока пройдены», «На сегодня повторять нечего», …).
- Личная сессия: плитки «Карточек» / «Верно» (процент) / «Новых слов» из `finish`-summary, карточка «Цель дня: 8 из 20» с полосой и серия (в том числе «под угрозой»). Групповая сессия summary не имеет — только «Вы повторили N слов».
- «Следующее повторение — через N дней» (или «Сегодня»).
- Кнопки внизу: **«На главную»** (`btn-primary`, → `/today`), «Узнать о Pro» (`btn-tinted`, только при `new_quota_exhausted`; пейвол в этом случае открывается сам), «Закрыть» (`btn-plain`, `WebApp.close()`). Поля промокода здесь больше нет: оно в «Подписке» и в пейволе.

### 5.4 Edge cases
- Ошибка загрузки (сеть, 5xx) → `ErrorState` с кнопкой «Повторить».
- Сессия закрыта (410 Gone) → экран «Тренировка завершена» с причиной; у личной сессии, открытой этим запуском приложения, есть «Начать заново» (тот же урок или ежедневное занятие), иначе «Закрыть».
- `quality` не отправился → сохраняем в localStorage, пробуем отправить при восстановлении связи. (На MVP можно без оффлайн-очереди — просто показать ошибку.)

## 6. Режим «Exam»

### 6.1 Состояние
```ts
interface ExamState {
  sessionId: number
  endsAt: number           // timestamp ms
  totalQuestions: number
  secondsPerQuestion: number
  currentIndex: number
  currentQuestion: Question | null
  score: number
  perQuestionDeadline: number
  isFinished: boolean
}
```

> Устарело: схема MVP. Актуальное состояние — `stores/exam.ts`: `status` (`idle` / `joining` / `loading` / `answering` / `submitting` / `feedback` / `finished` / `closed` / `error`), `mode` (`group_exam` | `personal_mock`), `mockResult` и итоги (`finalScore`, `finalRank`, `leaderboard`).

### 6.2 Таймер
- Глобальный таймер до `endsAt` (MM:SS).
- Таймер вопроса — `secondsPerQuestion` (по умолчанию 10).
- По истечении времени вопроса — автосабмит «не ответил» (selected_option_index = null, is_correct = false).
- Время показа вопроса фиксирует backend. Повторная загрузка вопроса не перезапускает таймер, а клиентское `time_spent_ms` считается только телеметрией и не влияет на баллы.
- После `ends_at` endpoints `join`, `question` и `answer` немедленно возвращают `410 Gone`, даже если scheduler ещё не перевёл сессию в `closed`.

### 6.3 UI (docs/14 §6.4)

Иммерсивный экран без таб-бара; один и тот же для группового экзамена и личного «Пробного теста» (`?mode=mock`, docs/13 §8.4).

- Шапка: чип таймера до конца экзамена (красный при < 30 с), счётчик «3 / 20», справа чип очков (у пробного теста вместо него «Пробный тест»). Под шапкой — полоса времени на вопрос (красная при ≤ 3 с); у пробного теста её нет, таймер один на весь тест.
- Центр: слово в карточке, под ней 4 варианта перевода (кнопки-варианты).
- Feedback сразу после ответа: зелёная подсветка верного варианта; если ошибся — красная у выбранного и правильный вариант помечен. Затем 800 мс пауза и следующий вопрос.
- Системная «Назад» закрывает Mini App: экзамен не бросают ради вкладки (docs/14 §5.3). Экзамен завершён учителем или время вышло (410) → экран «Экзамен завершён учителем» («Время вышло» у пробного теста, с кнопкой к итогам).
- По окончании: переход (`replace`) на `/exam/:sessionId/result`.

### 6.4 Итоги (`ExamResultView`)
- Флаг, «Итоги экзамена» («Итоги пробного теста») и карточка «Ваш результат»: очки крупно и чип «Место 3 из 14» (пока итоги считаются — «Результаты ещё считаются…»).
- Групповой экзамен: «Топ участников» — сгруппированный список (у первых трёх медали).
- Пробный тест: вместо очков процент, «N из M · P%», «Без ответа: K», процентиль среди результатов по курсу за 90 дней (или «первый результат — сравнить пока не с чем») и число попыток.
- Кнопки внизу: «Закрыть» (`btn-primary`) и, у пробного теста, «К курсам» (`btn-tinted`).

## 7. TTS (useTts)

> Порядок (задача 1.2c): `speak(text, lang, audioUrl)` — если у карточки есть `audio_url`, играет предгенерированный mp3; при `null` или ошибке воспроизведения — Web Speech API, затем `GET /api/twa/tts`. Пример ниже — исходная схема без `audioUrl`.

```ts
// src/composables/useTts.ts
export function useTts() {
  const synth = window.speechSynthesis
  const supported = !!synth
  let voice: SpeechSynthesisVoice | null = null

  function pickEnglishVoice() {
    if (!synth) return null
    const voices = synth.getVoices()
    return voices.find(v => v.lang.startsWith('en')) || voices[0] || null
  }

  async function speak(text: string) {
    if (!supported) return speakFallback(text)
    voice ??= pickEnglishVoice()
    const u = new SpeechSynthesisUtterance(text)
    u.lang = 'en-US'
    u.rate = 0.9
    if (voice) u.voice = voice
    synth.cancel()
    synth.speak(u)
  }

  async function speakFallback(text: string) {
    const url = `/api/twa/tts?word=${encodeURIComponent(text)}`
    const audio = new Audio(url)
    await audio.play()
  }

  return { supported, speak }
}
```

Детали:
- На iOS Safari `synth.getVoices()` возвращает пустой массив при первом вызове — нужно дождаться события `voiceschanged`.
- На Telegram Desktop (Windows/macOS) Web Speech API обычно работает стабильно.

## 8. Интеграция с Telegram UI

- `WebApp.MainButton` — не используем (UX хуже, чем свои кнопки, в рамках карточек).
- **Тема.** `composables/telegramTheme.ts` (`initTelegramTheme()` в `main.ts`) копирует **все** параметры `WebApp.themeParams`, которые нужны дизайну, в CSS-переменные на `<html>`; соответствие «параметр → переменная → фолбэк» — docs/14 §4.1 (14 параметров: `bg_color`, `text_color`, `hint_color`, `link_color`, `button_color`, `button_text_color`, `secondary_bg_color`, `section_bg_color`, `accent_text_color`, `destructive_text_color`, `subtitle_text_color`, `section_header_text_color`, `section_separator_color`, `bottom_bar_bg_color`). Параметр, которого клиент не прислал, **удаляется** (`removeProperty`) — срабатывает фолбэк из `style.css`. Компоненты используют только семантические токены `--app-*` (`page`, `surface`, `fill`, `accent`, …), а не `--tg-*` напрямую:
  ```css
  :root { --tg-bg: #fff; --app-page: var(--tg-secondary-bg); /* … */ }
  ```
- **Смена темы на лету.** Подписка `WebApp.onEvent('themeChanged', …)` применяет тему заново; `document.documentElement.dataset.theme = WebApp.colorScheme` (`light`/`dark`) — для `color-scheme` нативных контролов.
- **Цвет шапки и фона окна.** При `WebApp.isVersionAtLeast('6.1')` вызываются `setHeaderColor('secondary_bg_color')` и `setBackgroundColor('secondary_bg_color')` — шапка Telegram сливается с фоном страницы. Всё в `try/catch`: вне Telegram — no-op.
- **Кнопка «Назад»** (`composables/useBackButton.ts`, правило — docs/14 §5.3): `useBackButton(target)` вызывается в `setup()` экрана; `target` — место назначения (`{ name: 'courses' }`), функция или `null`. Корни вкладок (`today`, `courses`, `decks`, `league`, `friends`, `profile`) — `null`: кнопка скрыта. Внутренние экраны ведут на корень своей вкладки (`course → courses`, `deck → decks`, `settings|billing|achievements|referrals → profile`). Тренировка личной сессии (`lesson`, `daily_review`) возвращает на `/today`, групповая (`group_lesson`) закрывает Mini App; экзамен — закрытие. Текстовых ссылок «← К курсам» нет. Обработчик снимается при уходе с экрана. Все экраны с системной «Назад» используют `useBackButton`; `showBackButton` из `useTelegram` вызывается только внутри него.
- `WebApp.HapticFeedback.impactOccurred('light')` — лёгкий хаптик на нажатие оценок; `selectionChanged()` — при переключении вкладок.

## 8a. Локализация (i18n)

Языки интерфейса — **русский (по умолчанию) и узбекский (латиница)**; изучаемый язык (английский) не переводится. Внешняя библиотека (vue-i18n) не используется — модуль `src/i18n/` (~100 строк).

- **Выбор языка:** `Telegram.WebApp.initDataUnsafe.user.language_code` (через `useTelegram().languageCode()`) → `uz*` → `uz`, всё остальное (и запуск вне Telegram) → `ru`. Выбирается один раз в `main.ts` (`initLocale`), там же выставляется `<html lang>`.
- **Словари:** `ru.ts` — эталон; тип `Translations` выводится из него, поэтому `uz.ts` с пропущенным/лишним ключом не проходит `vue-tsc`. Ключи плоские, `section.name`. Plural-значения — объект форм `{ one, few, many, other }`.
- **API:** `t(key, params?)` — подстановка `{name}`; `tn(key, count, params?)` — форма по `Intl.PluralRules` (ru: one/few/many, uz: one/other; `{count}` подставляется автоматически); `useI18n()` в компонентах.
- **Ошибки API:** клиент не показывает `error.message` сервера (он английский), а локализует по `error.code` (`src/i18n/errors.ts`, список `KNOWN_ERROR_CODES`; остальное — по `kind` ответа: сеть, 403, 410, 429, 5xx). Новый код ошибки в бэкенде = новая запись в `KNOWN_ERROR_CODES` + строки `error.code.<code>` в обоих словарях.
- **Правила:** в `.vue`/`.ts` вне `src/i18n` кириллицы быть не должно (проверяет vitest). Новый текст = ключ в `ru.ts` и `uz.ts` одновременно.

## 9. Производительность

- Экраны подгружаются лениво (`() => import(...)` в `router/index.ts`), в том числе `ExamView` и `ExamResultView`: для тренировок они не нужны.
- Предзагружать следующую карточку параллельно с текущей (prefetch `/next?preload=1`) — опционально для v1.1.
- Images не используем (всё текстом).
- Fonts — системные (`-apple-system, BlinkMacSystemFont, ...`) чтобы не грузить TTF.

## 10. Тестирование TWA

- Unit: Vitest + @vue/test-utils для компонентов FlashCard, QuizOption.
- E2E: Playwright с моком Telegram.WebApp.
- Ручное — на реальных устройствах (минимум iPhone, Android, Desktop).

## 11. Деплой TWA

- `npm run build` в `resources/twa` → `public/twa/`.
- Nginx отдаёт статику с long-cache:
  ```
  location /twa/ {
      try_files $uri $uri/ /twa/index.html;
  }
  location = /twa/index.html {
      add_header Cache-Control "no-cache, no-store, must-revalidate";
  }
  location ^~ /twa/assets/ {
      add_header Cache-Control "public, max-age=31536000, immutable";
  }
  ```
- `index.html` — без кеша (`no-cache`), остальные файлы — с хэшами в именах.
