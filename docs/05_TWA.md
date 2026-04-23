# 05. Telegram Web App (TWA) — Спецификация

## 1. Стек и структура

- **Framework:** Vue 3 (Composition API) + `<script setup>`.
- **Build:** Vite 5.
- **State:** Pinia.
- **Routing:** Vue Router (history mode внутри TWA).
- **HTTP:** ofetch или axios.
- **UI:** TailwindCSS 3 + локальные компоненты (без тяжёлой UI-либы, чтобы размер bundle был маленьким).
- **Telegram SDK:** `@twa-dev/sdk`.
- **Icon set:** `@heroicons/vue`.

Размер цели: первый бандл ≤ 180 KB gzip.

### Структура
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
│   ├── composables/
│   │   ├── useTelegram.ts   # обёртка над @twa-dev/sdk
│   │   ├── useTts.ts
│   │   └── useTimer.ts
│   ├── views/
│   │   ├── LandingView.vue
│   │   ├── TrainingView.vue
│   │   ├── ExamView.vue
│   │   └── ResultView.vue
│   └── components/
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

## 3. Маршруты

| Path | View | Описание |
|------|------|----------|
| `/` | LandingView | Показывается, если режим не задан или ошибка. |
| `/training/:sessionId` | TrainingView | Flashcards. |
| `/exam/:sessionId` | ExamView | Экзамен с таймером. |
| `/exam/:sessionId/result` | ResultView | Итоги + лидерборд. |

Определяем режим по параметрам query из ссылки, которую бот прислал.

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
  state: () => ({ token: '' as string, expiresAt: 0, student: null as Student | null }),
  actions: {
    async login() {
      const initData = useTelegramInitData()
      const res = await api.auth.authenticate(initData)
      this.token = res.token
      this.expiresAt = Date.now() + res.expires_in * 1000
      this.student = res.student
    },
    async ensure() {
      if (!this.token || Date.now() > this.expiresAt - 60_000) {
        await this.login()
      }
    },
  },
})
```

Перед каждым запросом вызываем `auth.ensure()`; если бек вернул 401 — пробуем один раз переавторизоваться.

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

### 5.2 Экран карточки
- Top bar: прогресс «7 / 25», кнопка «Close» (WebApp.close()).
- Центр:
  - Слово (шрифт 36px, bold).
  - Transcription (если есть).
  - Кнопка 🔊 — зовёт `useTts().speak(word)`.
  - Большая кнопка «Показать перевод» (если `showTranslation=false`) — по клику `showTranslation=true`.
  - Когда показан перевод: translation + example.
  - Три кнопки: 😰 Трудно / 🙂 Средне / 😎 Легко.
- При клике на оценку:
  - POST `/review` с соответствующим `quality` (2 / 3 / 5).
  - Локально: `progress.done++`, очищаем карточку, загружаем следующую.
  - Если следующая — `null` → `isFinished = true`.

### 5.3 Экран «всё готово»
- Сообщение «Отлично! Вы повторили N слов. Следующее повторение — через X дней.»
- Кнопка «Закрыть» → `WebApp.close()`.

### 5.4 Edge cases
- Нет интернета → показать retry-кнопку.
- Сессия закрыта учителем → 410 Gone от API → «Учитель завершил тренировку».
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

### 6.2 Таймер
- Глобальный таймер до `endsAt` (MM:SS).
- Таймер вопроса — `secondsPerQuestion` (по умолчанию 10).
- По истечении времени вопроса — автосабмит «не ответил» (selected_option_index = null, is_correct = false).

### 6.3 UI
- Top bar: время до конца экзамена + номер вопроса.
- Центр: слово большим шрифтом, 4 варианта перевода (quiz options).
- Feedback сразу после ответа: зелёная/красная подсветка выбранного варианта + правильный вариант если ошибся. Затем 800 мс пауза и следующий вопрос.
- По окончании: редирект на `ResultView`.

### 6.4 ResultView
- Показывает `student_score / rank / total_participants`.
- Таблица-лидерборд (первые 10).
- Кнопка «Закрыть».

## 7. TTS (useTts)

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
- `WebApp.BackButton` — показываем в `ExamView` и `TrainingView`, по клику → `WebApp.close()` с confirmation, если есть прогресс.
- `WebApp.themeParams` — применяем как CSS variables для светлой/тёмной темы:
  ```css
  :root {
    --tg-bg: var(--tg-theme-bg-color, #fff);
    --tg-text: var(--tg-theme-text-color, #000);
    --tg-button: var(--tg-theme-button-color, #3390ec);
  }
  ```
- `WebApp.HapticFeedback.impactOccurred('light')` — лёгкий хаптик на нажатие оценок.

## 9. Производительность

- Lazy-load `ExamView` и `ResultView` (они не нужны для тренировок).
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
      alias /var/www/html/public/twa/;
      try_files $uri $uri/ /twa/index.html;
      add_header Cache-Control "public, max-age=31536000, immutable";
  }
  ```
- `index.html` — без кеша (`no-cache`), остальные файлы — с хэшами в именах.
