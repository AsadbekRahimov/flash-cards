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
| 10 | [B2C Expansion](./10_B2C_EXPANSION.md) | Идеи расширения до B2C SaaS |
| 11 | [System Analysis & SaaS Strategy](./11_SYSTEM_ANALYSIS_AND_SAAS_STRATEGY.md) | Аудит системы и стратегия B2C-first SaaS: learner-first модель, тарифы для студентов и центров, roadmap |
| 12 | [Phase 1 Design](./12_PHASE1_DESIGN.md) | Проект Фазы 1: схема learners/курсов/подписок, миграция `students` → `learners`, домены и контракты сервисов, entitlement и Free-лимит, онбординг, безопасность, нарезка на задачи |
| 13 | [Phase 2 Design](./13_PHASE2_DESIGN.md) | Проект Фазы 2: оплата (Telegram Stars, Payme, Click, `payment_orders`/`payments`, идемпотентность, порядок блокировок), тарифы и места центров, кабинет `/org`, задания и отчёты, рефералка/лиги/streak freeze, упражнения, FSRS, симулятор; нарезка 2A/2B/2C/2D/2R и вопросы владельцу |
| 14 | [UX Audit & Redesign](./14_UX_AUDIT_AND_REDESIGN.md) | UX-аудит 2026-10-28 и редизайн: принципы, дизайн-система TWA (токены темы Telegram, компоненты, контраст), навигация из 4 разделов и «Назад», спецификации экранов, словарь терминов ru/uz, бот и админка на русском |
| 16 | [Practice Design](./16_PRACTICE_DESIGN.md) | Технический проект «Практика (4 режима) + Баттл 1 на 1» (по плану `15_PRACTICE_GAMES_PLAN.md`): домен `Practice`, контент с версиями и JSON-импортом, снимки сессий и непрозрачные id, проверка ответов, машина состояний баттла, порядок блокировок, аудио, API, TWA, флаги/пилот, тесты, нарезка задач 18.x |
| —  | [Руководство ученика](./USER_GUIDE.md) | Для учеников: как заниматься, оценки, курсы и колоды, рейтинг, профиль, Free/Pro, FAQ |
| —  | [Руководство учителя](./TEACHER_GUIDE.md) | Для учителей: команды бота в группе, тренировка и экзамен, результаты, кабинет центра `/org` |
| —  | [Admin Guide](./ADMIN_GUIDE.md) | Для администраторов платформы: панель `/admin`, контент и импорт, оплата, настройка бота |
| —  | [Uzbek strings review](./I18N_UZ_REVIEW.md) | Все узбекские строки бота и TWA рядом с русскими — для проверки носителем |
| —  | [Аудит 2026-10-22](./AUDIT_2026-10-22_RU.md) | Независимый аудит кода, безопасности и логики; статус исправлений — в PROGRESS.md |
| —  | [VPS deploy (RU)](./VPS_DEPLOY_GUIDE_RU.md) | Запуск на VPS с нуля: PHP, PostgreSQL, Redis, nginx, supervisor, cron |
| —  | [Local Dev (Docker)](./LOCAL_DEV_DOCKER.md) | Локальный запуск через Docker для macOS / Windows, ngrok для Telegram, Claude Code в Docker |

## ⚖️ Порядок актуальности документов

Документы писались в несколько фаз, и ранние формулировки местами расходятся с кодом. При противоречии верен источник выше по списку:

1. **Код и тесты** + текущий контракт: `03_DATABASE`, `04_API_AND_BOT`, `05_TWA`, `07_SECURITY`, `DEVOPS_GUIDE`, `VPS_DEPLOY_GUIDE_RU`; для интерфейса (TWA, тексты бота и админки, термины) — `14_UX_AUDIT_AND_REDESIGN`.
2. **Принятые решения фаз**: `13_PHASE2_DESIGN` (разделы «Реализация …»), затем `12_PHASE1_DESIGN`, затем `11_SYSTEM_ANALYSIS_AND_SAAS_STRATEGY`.
3. **Исторические документы MVP**: `01_PRD`, `02_ARCHITECTURE` (кроме ADR-8/ADR-11), `08_ROADMAP`, `09_AI_AGENTS_GUIDE`. В них остались `students` (заменено на learner-first: `learners` + `group_members`), PHP 8.2 (требуется **8.3**), `nutgram` (используется **`irazasyed/telegram-bot-sdk`** через адаптер `TelegramClient`), MySQL как вариант (поддерживается только **PostgreSQL 16**).

Статусы в `PROGRESS.md`: «✅ код» = реализовано и автотесты зелёные; ручная приёмка на staging и разрешение на production отмечаются отдельно (см. `STAGING_PHASE2_CHECKLIST.md`). Аудит от 2026-10-22 и статус исправлений — `AUDIT_2026-10-22_RU.md` и `PROGRESS.md`.

## 🎯 Краткое описание

**LexiFlow Pro** — это связка из трёх компонентов:

1. **Admin Panel (Filament 5)** — учителя и админы управляют контентом (импорт уроков через JSON), видят аналитику, привязывают Telegram-группы.
2. **Telegram Bot** — работает только в зарегистрированных группах; учитель запускает режимы тренировки и экзамена.
3. **Telegram Web App (TWA)** — там студенты видят карточки, проходят экзамены, слушают TTS.

Под капотом — Laravel 12, алгоритм интервальных повторений SM-2, очереди на Redis, шедулер для персональных уведомлений.

## 🛠️ Технический стек (сводка)

- **Backend:** PHP 8.3, Laravel 12
- **Admin:** Filament 5
- **Database:** PostgreSQL 16
- **Cache/Queue:** Redis 7
- **Bot:** Telegram Bot API (SDK: `irazasyed/telegram-bot-sdk` за адаптером `TelegramClient`)
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
