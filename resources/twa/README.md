# LexiFlow TWA (Telegram Web App)

Vue 3 + Pinia + Vue Router + Tailwind SPA served from `/twa/`.

## Стек

| Пакет | Версия | Зачем |
|-------|--------|-------|
| `vue` | ^3.5 | Composition API + `<script setup>` |
| `vue-router` | ^4.5 | history mode (`/twa/` base) |
| `pinia` | ^2.3 | state (auth, training) |
| `@twa-dev/sdk` | ^7 | обёртка над `window.Telegram.WebApp` |
| `ofetch` | ^1.4 | HTTP-клиент с auto-JSON |
| `tailwindcss` | ^3.4 | UI |
| `vitest` + `@vue/test-utils` | | unit-тесты |

## Команды

```bash
cd resources/twa
npm install
npm run dev       # Vite dev server на :5174
npm run build     # компилирует в ../../public/twa/
npm run test      # vitest run
npm run type-check
```

## Структура

```
src/
├── main.ts             # bootstrap + применение Telegram theme params
├── App.vue             # корень
├── style.css           # Tailwind + Telegram CSS variables
├── router/index.ts     # /, /training/:id, /finished
├── composables/
│   ├── useTelegram.ts  # обёртка над SDK: initData, close, haptic, BackButton
│   ├── useTts.ts       # Web Speech API + fallback на /api/twa/tts
│   └── useTimer.ts     # переиспользуемый countdown (Sprint 8)
├── api/
│   ├── client.ts       # ofetch-обёртка + ApiClientError + auto-retry на 401
│   ├── types.ts
│   └── index.ts        # singleton `api.auth.*`, `api.training.*`, `api.me.get`
├── stores/
│   ├── auth.ts         # JWT + ensure() с авто-обновлением
│   └── training.ts     # sessionId, card, progress, status machine
├── components/
│   └── FlashCard.vue   # карточка + 3 кнопки оценки
└── views/
    ├── LandingView.vue
    ├── TrainingView.vue
    └── FinishedView.vue
```

## Деплой

1. `npm run build` — артефакты в `public/twa/`.
2. Nginx отдаёт с long-cache (см. `docker/nginx/default.conf`).
3. В `.env` backend'а: `TWA_BASE_URL=https://<your-https-host>` (Telegram WebApp требует HTTPS).

## Сценарий работы

1. Учитель в группе шлёт `/start_training 1 1`.
2. Backend создаёт `training_session` и постит сообщение с inline-кнопкой WebApp:
   `https://host/twa/training/{session_id}`.
3. Ученик нажимает → открывается TWA.
4. `TrainingView` → `auth.ensure()` (POST /api/twa/auth) → `training.start()` → цикл `/next`/`/review`.
5. Когда `/next` возвращает `card=null` → редирект на `/finished`.

## Обработка ошибок

`ApiClientError.kind`:

| kind | статус | UX |
|------|--------|----|
| `network` | 0 | «Нет связи», кнопка retry |
| `unauthorized` | 401 | авто-ретрай через `auth.login()` один раз |
| `forbidden` | 403 | «У вас нет доступа к тренировке» |
| `gone` | 410 | «Учитель завершил тренировку» |
| `validation` | 422 | показываем `error.message` |
| `rate_limited` | 429 | предложение подождать |
| `server` | 5xx | «Сервер недоступен, попробуйте позже» |

## Тесты

- `src/components/__tests__/FlashCard.spec.ts` — рендер/события.
- `src/composables/__tests__/useTimer.spec.ts` — fake timers, onExpire invariants.
- E2E (Playwright) — планируется в Sprint 8 (c exam flow).
