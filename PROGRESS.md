# Progress Log

## Sprint 1 — Foundation ✅
## Sprint 2 — Database & Models ✅
## Sprint 3 — Filament Admin ✅ (2FA отложен до Sprint 9)
## Sprint 4 — Import & Content ✅

## Sprint 5 — Bot Basics ✅ (2026-04-23)

- [x] `config/telegram.php` — token / 2 секрета / webhook URL / queue.
- [x] `.env.example` обновлён: `TELEGRAM_URL_SECRET`, `TELEGRAM_HEADER_SECRET`, `TELEGRAM_WEBHOOK_URL`, `TELEGRAM_QUEUE`.
- [x] `POST /telegram/webhook/{secret}` → `WebhookController` с двойной проверкой (URL + header) через `hash_equals`; CSRF exemption в `bootstrap/app.php`.
- [x] `HandleTelegramUpdate` job → очередь `high`, retry 3/backoff 5. PII маскируется перед логированием (`UpdateSanitizer`).
- [x] `TelegramDispatcher` + интерфейс `UpdateHandler`, 4 handler'а:
  - `MyChatMemberHandler` — создаёт `TelegramGroup` (pending); kick/left → status `disabled`.
  - `NewMembersHandler` — upsert `students` (боты игнорируются).
  - `StartCommandHandler` — ЛС, привязка учителя по `telegram_user_id`, `last_login_at = now()`.
  - `HelpCommandHandler` — справка в private/group.
- [x] `TelegramApi` — обёртка над `irazasyed/telegram-bot-sdk` (sendMessage/setWebhook/deleteWebhook).
- [x] `artisan telegram:set-webhook [--delete]` — регистрация/удаление webhook.
- [x] Логи `Log::channel('daily')` — `telegram.update` / `telegram.handler_failed` (PII masked).

### Тесты
- `TelegramWebhookTest` (4): wrong URL secret, missing header, wrong header, happy path с `Bus::fake`.
- `TelegramHandlersTest` (6): pending-group, kick→disabled, upsert students (skip bot), /help, /start bind, /start unknown.
- `UpdateSanitizerTest` (1): PII masking.

**DoD verify (staging / ngrok):**
```
# в .env
TELEGRAM_BOT_TOKEN=...
TELEGRAM_URL_SECRET=$(openssl rand -hex 24)
TELEGRAM_HEADER_SECRET=$(openssl rand -hex 24)
TELEGRAM_WEBHOOK_URL=https://<ngrok>.ngrok-free.app
# затем
docker compose exec app php artisan telegram:set-webhook
# добавить бота в тестовую группу → TelegramGroup status='pending'
# /admin → активировать группу
# /help в группе отвечает
# /start в ЛС от учителя с прописанным telegram_user_id → «Привет, {name}»
```
