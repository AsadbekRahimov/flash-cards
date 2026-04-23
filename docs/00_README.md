# LexiFlow Pro — Документация проекта

> Система обучения английскому языку для языковых центров с интеграцией в Telegram.

## 📚 Навигация по документам

| № | Документ | Что внутри |
|---|----------|------------|
| 01 | [PRD](./01_PRD.md) | Полный Product Requirements Document: видение, пользователи, user stories, функциональные и нефункциональные требования |
| 02 | [Architecture](./02_ARCHITECTURE.md) | Архитектура системы, компоненты, стек, диаграммы потоков данных |
| 03 | [Database](./03_DATABASE.md) | ERD, схема БД, описание таблиц, индексы, миграции |
| 04 | [API & Bot](./04_API_AND_BOT.md) | REST API для TWA, команды бота, webhook, схемы запросов/ответов |
| 05 | [TWA Spec](./05_TWA.md) | Спецификация Telegram Web App: экраны, состояния, TTS |
| 06 | [Spaced Repetition](./06_SPACED_REPETITION.md) | Алгоритм SM-2, примеры кода, интеграция с БД и планировщиком |
| 07 | [Security](./07_SECURITY.md) | Модель угроз, чек-лист безопасности, конкретные меры защиты |
| 08 | [Roadmap](./08_ROADMAP.md) | Пошаговый план разработки по спринтам с зависимостями |
| 09 | [AI Agents Guide](./09_AI_AGENTS_GUIDE.md) | Как эффективно работать с AI-агентами на этом проекте |

## 🎯 Краткое описание

**LexiFlow Pro** — это связка из трёх компонентов:

1. **Admin Panel (Filament 5)** — учителя и админы управляют контентом (импорт уроков через JSON), видят аналитику, привязывают Telegram-группы.
2. **Telegram Bot** — работает только в зарегистрированных группах; учитель запускает режимы тренировки и экзамена.
3. **Telegram Web App (TWA)** — там студенты видят карточки, проходят экзамены, слушают TTS.

Под капотом — Laravel 12, алгоритм интервальных повторений SM-2, очереди на Redis, шедулер для персональных уведомлений.

## 🛠️ Технический стек (сводка)

- **Backend:** PHP 8.2+, Laravel 12
- **Admin:** Filament 5
- **Database:** PostgreSQL 16 (или MySQL 8.0)
- **Cache/Queue:** Redis 7
- **Bot:** Telegram Bot API (SDK: `irazasyed/telegram-bot-sdk` или `nutgram/nutgram`)
- **TWA Frontend:** Vue 3 + Vite + TailwindCSS (внутри Laravel через Inertia или как SPA)
- **TTS:** Web Speech API (primary), edge-tts как fallback через Python микросервис
- **Deploy:** Docker Compose, Nginx, Supervisor для воркеров

## 🚀 С чего начать разработку

1. Прочти `01_PRD.md` — поймёшь, что строим.
2. Прочти `02_ARCHITECTURE.md` — поймёшь, как строим.
3. Открой `08_ROADMAP.md` — там разбиение на спринты.
4. Первый спринт начинай по `09_AI_AGENTS_GUIDE.md` — там готовые промпты для агентов по каждому модулю.

## 📁 Рекомендуемая структура репозитория

```
lexiflow-pro/
├── docs/                     # эти документы
├── app/                      # Laravel app
│   ├── Filament/             # Filament-ресурсы и страницы
│   ├── Telegram/             # всё, что связано с ботом
│   │   ├── Commands/
│   │   ├── Handlers/
│   │   └── Middleware/
│   ├── Services/             # бизнес-логика (SpacedRepetition, Import, TTS)
│   ├── Models/
│   └── Http/
│       ├── Controllers/Api/  # API для TWA
│       └── Middleware/
├── resources/
│   └── twa/                  # исходники Vue-приложения для TWA
├── routes/
│   ├── api.php               # TWA API
│   ├── web.php               # Filament routes
│   └── telegram.php          # bot webhook
├── database/
│   ├── migrations/
│   ├── seeders/
│   └── factories/
├── tests/
├── docker/
└── docker-compose.yml
```

## ✅ Definition of Done для проекта в целом

- Все функциональные модули PRD реализованы и покрыты тестами (unit + feature).
- Security-чек-лист из `07_SECURITY.md` пройден полностью.
- Проект разворачивается одной командой (`docker compose up -d`).
- Webhook Telegram защищён secret token.
- TWA валидирует `initData` через HMAC.
- Есть seeded demo-данные для локальной разработки.
- README основного репо содержит инструкцию по локальному запуску.

---

**Автор документации:** подготовлено для старта разработки с AI-агентами.
**Версия:** 1.0
**Дата:** 2026-04-19
