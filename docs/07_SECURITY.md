# 07. Security — LexiFlow Pro

> Этот документ — и модель угроз, и чек-лист. Каждый пункт чек-листа **обязан** быть закрыт до продакшена.

## 1. Модель угроз (STRIDE-based, сокращённо)

| Поверхность | Угроза | Последствие | Меры |
|-------------|--------|-------------|------|
| Telegram webhook | Злоумышленник шлёт поддельные апдейты | Создание «экзаменов», спам, доступ к чужим группам | `{secret}` в URL + заголовок `X-Telegram-Bot-Api-Secret-Token` + hash_equals |
| TWA API | Подмена learner'а через поддельный `initData` | Доступ к чужим данным, накрутка рейтинга | HMAC-SHA256 валидация `initData` по boot-key, срок 24 ч, short-lived JWT |
| TWA API | IDOR (доступ к чужим сессиям) | Чтение/запись чужих ответов | Policy-checks: `sub` (learner_id) из JWT должен быть владельцем личной сессии или активным участником группы сессии (§5) |
| Admin panel | Brute-force пароля | Захват админки | Rate-limit login, mandatory 2FA для admin (через `filament/2fa` или `pragmarx/google2fa-laravel`) |
| Admin panel | XSS через импорт JSON | Угон админской сессии | Whitelist html/escape во всех полях, CSP заголовок |
| Admin panel | Privilege escalation через form-tampering | Teacher → Admin | Policy-based авторизация в Filament, не полагаться на hide-in-UI |
| Admin panel | CSRF | Принуждение админа выполнить действие | Laravel CSRF middleware (по умолчанию on) |
| Импорт JSON | JSON injection / large file DoS | Падение воркера, OOM | Лимит размера 2 MB, валидация схемы, парсинг в стриме при необходимости |
| Импорт JSON | XXE | N/A — JSON, не XML | — |
| БД | SQL injection | Полный компромат | Только Eloquent / Query Builder, запрет `DB::raw()` без bindings |
| БД | Данные learner'ов в clear text в логах | Утечка PII | Монитор логов: не писать full update из Telegram, отрезать `from.first_name`/`username` до `***` |
| Очереди | Unsafe unserialize в job payload | RCE | Не кладём в job полные модели; только IDs + примитивы |
| Инфра | Слабые секреты в env | Компромет | `.env` не в git, секреты через CI/CD secret store, ротация `APP_KEY`, `TELEGRAM_WEBHOOK_SECRET` раз в 6 мес |
| Инфра | HTTP вместо HTTPS | MITM на TWA/webhook | Force HTTPS, HSTS (6 мес), Telegram webhooks **требуют** HTTPS |
| Зависимости | Уязвимости в composer / npm | RCE / supply chain | `composer audit` и `npm audit` в CI, Renovate/Dependabot, lockfiles в git |

## 2. Telegram Webhook — безопасность

### 2.1 Регистрация webhook
```bash
curl -X POST "https://api.telegram.org/bot${TOKEN}/setWebhook" \
  -d "url=https://lexiflow.example.com/telegram/webhook/${SECRET_IN_URL}" \
  -d "secret_token=${SECRET_HEADER}" \
  -d "allowed_updates=[\"message\",\"my_chat_member\",\"chat_member\",\"callback_query\"]"
```

Используем **два разных секрета**: один в URL (не увидят проксирующие логи), другой в заголовке — Telegram подставляет его сам.

### 2.2 Проверка на входе
```php
public function handle(Request $r, string $secret): Response
{
    abort_unless(
        hash_equals(config('telegram.url_secret'), $secret),
        404
    );

    abort_unless(
        hash_equals(
            config('telegram.header_secret'),
            $r->header('X-Telegram-Bot-Api-Secret-Token', '')
        ),
        403
    );

    // ограничиваем по IP диапазонам Telegram (опционально, жёсткое правило)
    // Актуальные диапазоны: 149.154.160.0/20 и 91.108.4.0/22
    if (!$this->isTelegramIp($r->ip())) {
        abort(403);
    }

    HandleTelegramUpdate::dispatch($r->all())->onQueue('high');
    return response()->json(['ok' => true]);
}
```

## 3. TWA initData — HMAC валидация

Согласно [Telegram Web Apps Docs](https://core.telegram.org/bots/webapps#validating-data-received-via-the-web-app):

Алгоритм:
1. Парсим `init_data` как query string в массив пар.
2. Извлекаем поле `hash`, остальные поля сортируем по ключу и склеиваем в `dataCheckString` через `\n`.
3. `secretKey = HMAC_SHA256("WebAppData", bot_token)`.
4. `calc = HMAC_SHA256(secretKey, dataCheckString)`.
5. `hex(calc) === hash` → валидно.
6. Проверяем `auth_date` — не старше 86400 секунд и не более чем на 30 секунд в будущем.

```php
<?php
namespace App\Support\Telegram;

final class InitDataValidator
{
    public function __construct(private string $botToken) {}

    public function validate(string $initData, int $maxAgeSeconds = 86400): array
    {
        parse_str($initData, $data);
        if (empty($data['hash']) || empty($data['auth_date'])) {
            throw new \DomainException('missing_fields');
        }

        $hash = $data['hash'];
        unset($data['hash']);

        ksort($data);
        $lines = [];
        foreach ($data as $k => $v) {
            $lines[] = "{$k}={$v}";
        }
        $dataCheckString = implode("\n", $lines);

        $secretKey = hash_hmac('sha256', $this->botToken, 'WebAppData', true);
        $calc = hash_hmac('sha256', $dataCheckString, $secretKey);

        if (!hash_equals($calc, $hash)) {
            throw new \DomainException('invalid_hash');
        }

        $age = time() - (int) $data['auth_date'];
        if ($age > $maxAgeSeconds) {
            throw new \DomainException('expired');
        }

        // user — JSON-string в data['user']
        $user = json_decode($data['user'] ?? '[]', true);
        if (empty($user['id'])) {
            throw new \DomainException('no_user');
        }

        return $user; // id, first_name, username, language_code, ...
    }
}
```

## 4. JWT для TWA

- Алгоритм: `HS256`.
- Payload (формат **v2**): `{ iss: 'lexiflow', sub: learner_id, v: 2, iat, exp }`. Группы в токене нет: learner один на все свои группы, доступ к сессии проверяется политиками по членству на каждом запросе (§5).
- `verify()` требует `v === 2` и `iss === 'lexiflow'`; токен без `v` (старый формат: `sub` = `students.id`, есть `gid`) или с другой версией → 401 `invalid_token`. Без версии `students.id` № 42 читался бы как `learners.id` № 42 — чужой аккаунт. Клиент по 401 сам вызывает `/auth` заново (`api/client.ts`), поэтому переход прозрачен. Претензия `v` остаётся для будущей смены формата.
- Срок: 15 минут.
- Отзыв: `learners.blocked_at` проверяется middleware `TwaAuth` на каждом запросе (401 `learner_blocked`, в т.ч. на `/auth`); удалённый (soft-delete) learner → 401 `learner_not_found`. `jti`-denylist не вводим (docs/12 §8).
- Refresh — повторный `/auth` с тем же `initData` (валиден 24 часа).
- Библиотека: `firebase/php-jwt` или `lcobucci/jwt`.

## 5. Авторизация (policies)

Gate/Policy-правила на бэке:

| Ресурс | Действие | Правило |
|--------|----------|---------|
| `TrainingSession` (групповая, `mode = group_lesson`) | view/participate | Только **активный участник активной группы** сессии (`group_members.status = 'active'` и `telegram_groups.status = 'active'`); participate — ещё и `status='open'` |
| `TrainingSession` (личная, `mode <> group_lesson`) | view/participate | Только владелец (`learner_id == sub`); членства в группах не влияют |
| `ExamSession` | view/join | Активный участник активной группы экзамена; join/answer — ещё `status='open'` и `ends_at > now()` (410 после конца); результат — только после закрытия |
| Любая сессия | — | Заблокированный learner (`blocked_at`) не проходит ни одну проверку; `learner_id` никогда не берётся из тела запроса |
| `Learner` в Filament | view | `admin` — любой; учитель — только learner'ы с **активным** членством в его группах (`PanelDataScope::learners()`); имя/username и метрики по его группам, без Telegram ID, настроек и личных сессий |
| `TrainingReview` в Filament | view | учитель — только отзывы **групповых** сессий его групп (личные занятия ученика скрыты) |
| `ExamSession` | close досрочно | Только `started_by_user_id == current teacher` |
| `Lesson` в Filament | edit | Пользователь с ролью `admin` |
| `AnalyticsDashboard` | view | `admin` или учитель (видит только свои группы) |
| `User` CRUD в Filament | всё | `admin` |
| Кабинет центра `/org` (2B.3) | всё | Отдельная панель, guard `org`, tenancy по `Organization` (slug): пользователь без строки `organization_users` в этой организации → **404** на любой URL tenant'а (`User::canAccessTenant`); модели ресурсов (`TelegramGroup`, `OrganizationMember`, `OrganizationInvite`, `OrganizationUser`) получают global scope `organization_id = tenant` на время запроса; политики `App\Policies\Org\*` подставляются `OrgPolicyResolver` **только** в этой панели (в `/admin` — прежние); чужая запись по прямому URL → 404, действие над ней → `ActionNotResolvableException` |
| `/org`: роль `owner` | всё | Ученики (add/remove через `OrganizationMembershipService`), группы (link только после `getChatMember(chat, owner) ∈ {creator, administrator}`, unlink), инвайты, персонал (`OrganizationStaffService`: invite/role/groups/remove, последний owner неснимаем), настройки, Billing. **2FA обязательна** (`RequireOrgTwoFactor`: нет TOTP → redirect на setup; метка сессии должна принадлежать этому пользователю — `2fa.user_id`) |
| `/org`: роль `teacher` | чтение | Только группы из `teacher_groups` и активные ученики этих групп (`OrgTenantScope`), Dashboard без мест/подписки, Assignments/Reports; Invites/Teachers/Billing → 403, Settings → 404. 2FA по желанию (включённая — проверяется) |
| `/org`: Telegram-аккаунт персонала | self-service | `users.telegram_user_id` меняет только сам пользователь в `/org/profile`; владелец видит лишь «привязан / нет» (одна учётка может быть персоналом нескольких центров) |

Реализация (`app/Policies/TrainingSessionPolicy.php`, `ExamSessionPolicy.php`; правило членства — `GroupMembershipService`):
```php
public function denial(Learner $learner, TrainingSession $s): ?string   // null = доступ разрешён
{
    if ($learner->blocked_at !== null) return 'forbidden';

    if ($s->mode !== 'group_lesson') {
        return (int) $s->learner_id === (int) $learner->id ? null : 'forbidden';   // личная
    }

    return $this->memberships->denialReason($learner, $s->group);   // null | 'not_a_member' | 'group_inactive'
}
```
Контроллеры отвечают 403 с этим кодом: `not_a_member` (нет активного членства, вышел/исключён/забанен — чужие группы не раскрываются),
`group_inactive` (участник группы, которая ещё/уже не активна), `forbidden` (чужая личная сессия); закрытая сессия — отдельно 410 `session_closed`.

## 6. Rate limits

| Endpoint | Limit | Комментарий |
|----------|-------|-------------|
| `POST /api/twa/auth` | 60/min per IP (`throttle:twa-auth`) + 10/min per `telegram_user_id` (`AuthController`, `RateLimiter` `twa-auth-user\|<id>`) | IP-лимит широкий — мобильные операторы (CGNAT) прячут целые классы за одним адресом. Персональный лимит считается **после** проверки HMAC initData: подделанный `init_data` с чужим `id` не расходует бюджет жертвы. Превышение → 429 `rate_limited` + `Retry-After`, learner не создаётся и не обновляется |
| `POST /api/twa/training/*/review` | 60/min per learner | Нормальный темп — не больше |
| `POST /api/twa/exam/*/answer` | 30/min per learner | |
| `GET /api/twa/tts` | 30/min per learner | |
| `POST /api/twa/promo/redeem` | 5/min per learner + 60/min per IP | Подбор промокодов; бот `/promo` — **отдельный** лимит 5/min на learner'а (`RateLimiter` `bot-promo:<learner_id>`), счётчики бота и TWA не складываются. По IP — мягко: мобильные операторы (CGNAT) сажают десятки учеников за один адрес, лимит ловит только перебор с многих аккаунтов. Каждая ошибка — событие `promo.rejected`; ≥ 5 отказов одного learner'а за 10 мин — `billing.promo_rejected_burst` (warning, docs/12 §8) |
| `POST /api/twa/billing/checkout` | 10/min per learner + 120/min per IP (`throttle:twa-checkout`) | Каждый вызов — строка `payment_orders` и обращение к платёжному провайдеру; сверх этого — `too_many_pending` (3 неоплаченных заказа, 429). Бот: нажатия `/pro` — 10/min на learner'а (`RateLimiter` `bot-pro:<learner_id>`). Сумма заказа всегда серверная (docs/13 §4) |
| Отказы `TwaAuth` (нет/битый/просроченный токен, learner не найден/заблокирован) | 60/min per IP (`TWA_UNAUTHORIZED_PER_MINUTE`) | `TwaAuth` стоит перед throttle, поэтому у отказов свой лимит; выше — 429 `rate_limited`. Успешные запросы не считаются и не блокируются |
| Остальные `/api/twa/*` | 60/min per learner | ключ rate limiter'а — `learner_id` из JWT |
| Filament login | 5/15min per IP + per email | Против brute-force |
| Webhook | не ограничиваем (но валидация по secret обязательна) | |

Реализация через `Route::middleware('throttle:...')`. `TwaAuth` стоит в списке приоритетов middleware **перед** `ThrottleRequests` (`bootstrap/app.php`), иначе лимитеры не видели бы `learner_id` и считали всех по IP.

## 7. Секреты и конфигурация

- `.env` **никогда** не коммитим. В репо — `.env.example` с плейсхолдерами.
- Для продакшена секреты хранятся в:
  - GitHub Actions Secrets / GitLab CI variables (для CI).
  - На сервере — в `.env` с правами `600`, владелец — `www-data`.
  - Или в HashiCorp Vault / AWS Secrets Manager при росте команды.
- Ротация секретов:
  - `APP_KEY` — раз в 6 мес (требует перешифровки `encrypted` колонок, если есть).
  - `TELEGRAM_WEBHOOK_SECRET` — раз в 6 мес (плановый `setWebhook` с новым).
  - `TWA_JWT_SECRET` — раз в 3 мес (invalidates все активные JWT — OK).
- Все секреты минимум 32 байта рандома: `openssl rand -base64 48`.

## 8. Защита админки Filament

- **2FA обязательна для роли admin.** Можно использовать `pxlrbt/filament-google-2fa` или кастомно через `pragmarx/google2fa-laravel`.
- **Панель `/org` (кабинет центра):** тот же кастомный TOTP-поток (`/2fa/*`, маршруты под `auth:web,org`), обязателен для владельцев центров (`RequireOrgTwoFactor`), для учителей центра — опционально. Сессия у guard'ов общая, поэтому метка `2fa.passed_at` дополнена `2fa.user_id` — кабинет принимает только метку своего пользователя. Сброс пароля / приглашение персонала — broker `org` (токен 24 ч, одноразовый, хранится хэшем).
- **IP allowlist** для `/admin` — опционально, если языковой центр работает из офиса. Middleware с list из env.
- **Auditing:** `owen-it/laravel-auditing` на критичные модели (`User`, `TelegramGroup`, `Word`) + своя `audit_logs` для доменных событий.
- **Session security:**
  - `SESSION_SECURE_COOKIE=true`.
  - `SESSION_SAME_SITE=lax` (или `strict` если не будет OAuth).
  - `SESSION_LIFETIME=120` минут.
- **Content Security Policy:**
  ```
  default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'self';
  ```
  Для TWA отдельный less-restrictive CSP с разрешением на Telegram-домены.
- **HTTP security headers** (middleware):
  - `Strict-Transport-Security: max-age=15552000; includeSubDomains`
  - `X-Content-Type-Options: nosniff`
  - `X-Frame-Options: SAMEORIGIN` (для TWA — уточнить, т.к. открывается внутри Telegram WebView)
  - `Referrer-Policy: strict-origin-when-cross-origin`

## 9. Защита импорта JSON

1. **Schema validation.** Jsonschema через `justinrainbow/json-schema` или Laravel Validator с правилами.
2. **Size limit.** `php.ini`: `post_max_size = 4M`, отдельная валидация на 2 MB.
3. **Parse в транзакции.** Откатить при любой ошибке валидации.
4. **XSS-hardening.** Все строки из JSON считаются untrusted; в Filament отображаем через `{{ $value }}` (Blade автоматом escape).
5. **Path traversal.** Не используем поле `word` как часть filesystem path.
6. **Idempotency.** Повторная загрузка того же файла не создаёт дубликатов — upsert по `(lesson_id, word)`.

## 10. PII и приватность

- Храним минимум:
  - `telegram_user_id` (как int, не секретный).
  - `first_name`, `username`, `language_code`.
  - Не храним `phone`, `photo_url`.
- **Кто может «вернуть» learner'а:** `restore()` soft-deleted learner'а и обновление `last_seen_at` — только по его собственным действиям (`/auth` в TWA, `/start` в ЛС); пассивные события группы (`new_chat_members`, `chat_member`) такого learner'а не восстанавливают, членство ему не создают и `last_seen_at` не трогают.
  **Известное ограничение:** soft-deleted learner, вошедший в группу, членства не получает; после собственного `/auth` / `/start` он восстанавливается, но доступ к группе появится только со следующим событием `chat_member` / `new_chat_members`.
- Шифрование в purpose:
  - `users.email` и `users.name` — в clear (нужны для UI).
  - `audit_logs.meta` — может содержать IP учителя/администратора; JSONB — без шифрования, но логи не экспортируем наружу.
- **Удаление learner'а по запросу:** soft-delete (`learners.deleted_at`) → через 30 дней hard-delete; FK-каскады удаляют его прогресс, а `exam_answers.learner_id` / `exam_results.learner_id` / `product_events.learner_id` обнуляются (`SET NULL`). Анонимизация вместо удаления — Фаза 2.
- **Экспорт данных learner'а (GDPR):** endpoint в admin `GET /admin/learners/{id}/export` — JSON дампа его данных. Не в MVP, но заложить.

## 11. SQL Injection — превентивные меры

- ✅ Только Eloquent / Query Builder.
- ❌ `DB::raw("... $variable ...")` без bindings.
- ❌ `orderByRaw`, `selectRaw` с интерполяцией юзер-инпута.
- Правило в PR-ревью: любой `Raw`-вызов требует explicit bindings и комментария, зачем он нужен.

## 12. Защита от abuse со стороны учителя

- Учитель может импортить гигантский JSON — лимит 2 MB защищает.
- Учитель может запустить 100 экзаменов — ставим soft-limit 5 активных экзаменов на группу.
- Учитель может сменить `teacher_groups` на чужую — CRUD Filament это не позволит (policy).

## 13. Log hygiene

- Не логируем: пароли, полные JWT-токены, `initData` целиком (только user_id).
- Маскируем: email до `a***@domain.com`.
- Логи в Sentry — без PII в breadcrumbs.
- **Секреты в сообщениях исключений.** Guzzle/SDK кладут в message полный URL `https://api.telegram.org/bot<TOKEN>/…`.
  Единый редактор `App\Domain\Operations\Support\SecretRedactor` маскирует `bot<id>:<token>`, значения
  `TELEGRAM_BOT_TOKEN` / `TELEGRAM_URL_SECRET` / `TELEGRAM_HEADER_SECRET` / `TWA_JWT_SECRET`, сегмент
  `/telegram/webhook/{secret}`, `Bearer …`, `secret=`/`token=`/`init_data=`. Применяется в трёх местах:
  (1) Monolog-процессор `RedactingLogProcessor` (tap `RedactSecretsInLogs` на каждом канале в `config/logging.php`) —
  `message`, `context`, `extra`, исключения в context заменяются замаскированным текстом без аргументов вызовов;
  (2) границей Telegram-клиента: `IrazasyedTelegramClient` бросает `TelegramApiException` с замаскированным
  сообщением и без `previous` — поэтому токена нет и в `failed_jobs.exception`; (3) Sentry (`SentryScrubber`,
  включая `before_send_log`). Подробности — DEVOPS_GUIDE §15.1a. Новый канал логов обязан получить тот же `tap`.
- Срок хранения production-логов — 30 дней, audit_logs — 2 года.

## 14. Pen-testing checklist (перед релизом)

- [ ] OWASP ZAP baseline scan против staging.
- [ ] Попытка подделать `initData` — 401.
- [ ] Токен старого формата (без `v`, `sub` = `students.id`) — 401 `invalid_token`.
- [ ] Токен заблокированного learner'а (`blocked_at`) — 401 `learner_blocked`.
- [ ] Попытка открыть чужую `exam_session` / `training_session` по id (не участник группы, вышедший, чужая личная сессия) — 403.
- [ ] Учитель не видит learner'а, вышедшего из его группы, и личные сессии учеников.
- [ ] Попытка отправить `review` с `quality=10` — 422.
- [ ] SQL injection в поиске по словам в админке — запросы через bindings.
- [ ] XSS в JSON-импорте (`<script>alert(1)</script>` в `translation`) — экранировано.
- [ ] Rate-limit срабатывает на `/auth`: после 60 запросов с одного IP и после 10 проверенных входов в минуту одного `telegram_user_id`.
- [ ] Webhook без `X-Telegram-Bot-Api-Secret-Token` — 403.
- [ ] Webhook с правильным secret, но из левого IP (опция) — 403.
- [ ] 2FA для админа работает, recovery codes работают, нельзя отключить без пароля.

## 15. Инциденты (playbook)

1. **Утечка `APP_KEY`:**
   - Ротация немедленно, перезашифровать зашифрованные колонки.
   - Инвалидация сессий (`php artisan session:flush`).
   - Рассылка «Войдите заново» админам.
2. **Утечка `TELEGRAM_BOT_TOKEN`:**
   - Revoke токена в BotFather, генерация нового.
   - Обновить webhook.
   - Проверить логи бота за последние 24 ч на аномалии.
3. **Компромат БД:**
   - Отключить публичный доступ.
   - Восстановить из backup (T-1 day).
   - Уведомить админов языкового центра о возможной утечке PII.

## 16. Security build-в-CI

```yaml
# .github/workflows/security.yml (скелет)
jobs:
  security:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.2' }
      - run: composer install
      - run: composer audit
      - uses: actions/setup-node@v4
        with: { node-version: '20' }
      - run: cd resources/twa && npm ci && npm audit --audit-level=high
      - uses: github/codeql-action/init@v3
        with: { languages: 'javascript, php' }
      - uses: github/codeql-action/analyze@v3
```

Дополнительно: `psalm` или `phpstan level 8` + `larastan` как статический анализ (ловит, например, Mass Assignment).

## 17. Финальный чек-лист перед выходом в продакшн

- [ ] HTTPS с валидным сертификатом (Let's Encrypt через caddy/nginx).
- [ ] `APP_DEBUG=false`, `APP_ENV=production`.
- [ ] Все секреты сгенерены заново (не из dev).
- [ ] Webhook secret установлен и валидация работает.
- [ ] `initData` валидация покрыта тестом.
- [ ] 2FA для админов включена.
- [ ] Rate limits настроены.
- [ ] CSP, HSTS, X-Frame headers поставлены.
- [ ] `composer audit` и `npm audit` — clean.
- [ ] Бэкапы БД настроены и проверены restore.
- [ ] Sentry/аналог подключён.
- [ ] Логи без PII.
- [ ] Smoke-test в проде (тренировка + экзамен на тестовой группе).
