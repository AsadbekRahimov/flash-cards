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
| Практика (18.x, docs/16 §12) | Утечка правильных ответов в JSON/сети | Ученик читает ответ до проверки и «проходит» режим без знаний | Ожидаемые ответы, `pairs`, `correct_ids`, `accepted`, `expected`, `audio_path`, `word_id`/`version_id` живут **только** в серверном `snapshot` (jsonb); Resource — белый список через `PracticeModeHandler::project` плюс повторная очистка `PracticeSessionResource::NEVER_SHOWN` на любой глубине; разбор (`reveal`) приходит только **после** принятого ответа; контрактные тесты с запретными ключами (`CONTRACT_FORBIDDEN_PRACTICE_KEYS`) и проверкой, что текст чужих/следующих элементов не просачивается |
| Практика | Вывод ответа из идентификаторов и порядка | Правильный порядок/пара «читаются» по id | id элементов, фрагментов, вариантов и карточек пар — случайные 10 символов (`OpaqueId`), порядок показа — HMAC-перестановка от случайного seed'а, который **не хранится**; индексов, `word_id`, готовой таблицы пар в ответах нет; слово-вариант показывается строкой |
| Практика | IDOR: чужая сессия | Чтение/запись чужих ответов, чужого аудио | Все выборки с `learner_id = me` (владелец), `learner_id` не берётся из тела; чужой и несуществующий id дают один и тот же **404** `practice_session_not_found` (в отличие от 403 у тренировок: существование сессии не раскрывается); то же для аудио и `retry_of` |
| Практика | Подмена элемента / варианта / фрагмента / двойной ответ | Засчитать ответ чужого элемента, дубль фрагмента, повторную попытку | Все id проверяются по снимку (422 `unknown_element_id`); перестановка обязана содержать **все** фрагменты по разу (`invalid_answer_shape`); ответ на не текущий элемент — 409; `request_id` (uuid, обязателен) + unique `(session_id, request_id)` — повтор возвращает **первый** результат; состояние меняется только под `FOR UPDATE` строки сессии; время клиента (`time_spent_ms`) — телеметрия |
| Практика | Аудио диктанта — расшифровка из имени файла / прямой ссылки | Ответ диктанта в открытом виде | Озвучка фраз — на приватном диске `practice_audio` (`storage/app/practice-audio`), **nginx его не раздаёт**; имя `{sha1(version_id\|текст\|голос\|версия)}.mp3` не содержит текста; клип отдаёт только `GET …/audio/{element}` по непрозрачному id элемента, владельцу, с `Cache-Control: private, no-store` и `X-Content-Type-Options: nosniff`; Web Speech для диктанта запрещён (нужен текст на клиенте) |
| Практика | Накрутка запросов (перебор ответов, открытие сессий) | Нагрузка на БД, подбор | Лимитеры `twa-practice-open` 10/мин, `twa-practice-answer` 90/мин, `twa-practice-audio` 60/мин (вне `twa-api`) + общий `twa-api` 60/мин, ключ — `learner_id`; одна активная сессия на ученика — факт БД (unique `active_learner_id`); размеры входа ограничены (`answer.text` ≤ 200 символов, `order` ≤ 8 id по ≤ 16 символов, тело ответа ≤ 4 КБ, `request_id` — строго uuid) |
| Практика / админка | XSS и мусор из импортируемого контента | Угон админской сессии, разметка в заданиях | Строки заданий — untrusted: Filament экранирует, TWA выводит через `{{ }}` без `v-html`; при сохранении версии `strip_tags` + удаление управляющих символов (`PracticeContentValidator`); импорт ≤ 2 МБ и ≤ 300 заданий, формат `lexiflow-practice/1`, «всё или ничего», неизвестное поле — ошибка; публикует только админ (`PracticeItemPolicy`), «опубликовать сразу» — отдельное подтверждение при импорте |
| Практика | Практика меняет обучение | Накрутка серии, лиги, SRS | Практика не вызывает `TrainingFlowService`, `ReviewSubmissionService`, `CardPicker`, `DailyStatsService`, `StreakService`, `LeagueService`, `AchievementService`, `ChallengeService` и не пишет их таблицы; инвариант закреплён `PracticeIsolationTest` (контрольные суммы до/после полного прогона 4 режимов и баттла обоих игроков); из общих таблиц практика пишет только `product_events`, баттл — ещё маркер ЛС-приглашения в `notifications_log` (`type = battle_dm`). Наград за матчи и реванши нет, поэтому сговор двух аккаунтов ничего не накручивает |
| Баттл (18.6 / 18.6b, docs/16 §8, §12) | Утечка ответа соперника, очков раунда и правильного ответа до разбора | Игрок подсматривает чужой ответ или проходит раунд без знаний | Правильные ответы, `pairs`, `accepted`, `expected`, пути клипов живут только в `battles.snapshot`; `BattleRoundResource` — белый список через `project()` плюс очистка `NEVER_SHOWN` на любой глубине; пока раунд не разрешён, опрос и `BattleResource` несут лишь флаги `my_answered` / `opponent_answered`, `round.reveal = null`, а счёт растёт **при разрешении** раунда (в транзакции второго ответа или по дедлайну + грейс) — по счёту чужой вердикт не угадать; разбор (`reveal.correct`, ответ и очки соперника) приходит только после разрешения. `BattleContractTest` проверяет по всем статусам и режимам: запретные ключи снимка и тексты ответов, пояснений, переводов, путей клипов не встречаются ни в одном ответе до разбора |
| Баттл | IDOR: чужой матч | Чтение чужого раунда и счёта, действия в чужом матче, проверка, существует ли id | Каждая выборка сверяет участника (`Battle::involves`); чужой и несуществующий `{id}` дают один и тот же **404** `battle_not_found` — с одинаковым телом на всех маршрутах, включая аудио (`BattleHttpRulesTest`), без записи присутствия и без изменений матча; `learner_id` не берётся из тела; `battle_busy.details.battle_id` называет только матчи самого спрашивающего; `rematch_of` чужого матча — 404; `{id}` длиннее 18 цифр — 404 роутера, а не 500 |
| Баттл | Аудио будущих раундов, прямые ссылки на файлы | Диктант «решается» заранее, текст читается из имени файла | Клип отдаёт только `GET …/battles/{id}/audio/{element}` участнику по непрозрачному id элемента: раунд элемента ≤ `current_round` либо статус `room` / `countdown` (предзагрузка пяти клипов для «Готов» — звук текст не раскрывает); клип будущего раунда, чужой матч, неизвестный элемент и элемент без записи — один и тот же 404; `Cache-Control: private, no-store`, `nosniff`; диск `practice_audio` и имена файлов — как у соло (nginx не раздаёт, имя не содержит текста); 503 пути не раскрывает и матч не трогает |
| Баттл | Подделка `request_id` / `element_id` / номера раунда | Двойной зачёт, ответ на закрытый или чужой раунд, аннулирование матча чужим отчётом | `request_id` — строго uuid, хранится в нижнем регистре, unique `(battle_id, learner_id, request_id)` и `(battle_id, round, learner_id)`, поиск повтора — в пределах `learner_id = me`; повтор возвращает **первый** результат (и после конца раунда или матча), новый `request_id` на тот же раунд — 409 `answer_already_accepted`; `round` обязан быть текущим (`round_not_open`, `round_closed`); id вариантов, фрагментов и карточек — по снимку (422 `unknown_element_id`), перестановка — все фрагменты по разу, пары — биекция (`invalid_answer_shape`); `audio-failed` берёт только элемент этого матча с записью и **сам проверяет файл** — отчёт клиента матч не аннулирует. uuid v5 у `timeout`-строк предсказуем, но ключ повтора действует лишь в пределах `(battle, learner)` — чужой ответ он затронуть не может |
| Баттл | Гонки: двойной тап, два приглашения одному адресату, `answer` ∥ дворник, `surrender` ∥ `answer` | Два живых матча, двойной зачёт раунда, двойная финализация | Состояние меняется только под `FOR UPDATE` строки `battles`; «один живой матч на ученика» — unique `battle_participants.active_learner_id` (нарушение → 409 `battle_busy`, проверок-в-PHP как источника истины нет); переходы по времени — одна идемпотентная функция `BattleClock::advance` под той же блокировкой (её вызывает опрос, действие или дворник — кто первый), событие пишет та транзакция, что сменила статус; транзакции баттла не блокируют `learners`, `friendships`, `courses` (порядок — docs/16 §9). `BattleConcurrencyTest`: 7 сценариев на PostgreSQL, без блокировки падают 5 из 7, два остальных держит unique |
| Баттл | Клиентское время, «затягивание» раунда, исчезновение | Ответ после дедлайна, остановка таймера, зависший матч | Дедлайны, `accepted_at` и грейс (`deadline_grace_ms` = 1500 мс) — серверные, клиентский таймер — отображение; раунд без ответа закрывается по `deadline + грейс` любым обращением участника и дворником (`timeout`, 0 очков); тишина игрока дольше `presence_grace_seconds` (30 с) — техническое поражение (оба пропали — `closed / both_absent`); присутствие игрока пишут только его собственные запросы (исключение — момент принятия, он ставит его обоим), чужой запрос присутствия не пишет; сдача требует `{confirm: true}` |
| Баттл | Спам приглашениями и ЛС другу, перебор | Нагрузка на БД и Telegram, раздражение друга | Только принявший дружбу, не сам себе, друг не заблокирован и имеет доступ к практике; один живой матч, встречное приглашение отклоняется (409 `battle_busy`); `twa-battle-invite` 5/мин и `twa-practice-open` 10/мин считают и отказанные попытки; срок приглашения 5 минут; ЛС — одно на приглашение (маркер `notifications_log`), для отклонённого, отозванного, принятого и просроченного приглашения оно не уходит, 403 Telegram → `dm_blocked_at`; опрос — свой бакет `twa-battle-poll` 90/мин вне `twa-api`, ответ ≤ 600 байт, без блокировок, пока ничего не назрело |
| Рефералка (2C.1) | Фарм наград: самоприглашение, старые аккаунты, массовые «друзья» | Бесплатный Pro | Награда только после активации (3 дня × 10 оценок за 14 дней, по серверным `learner_daily_stats`), только для learner'ов, созданных этим `/start`, коды `referral_code` непредсказуемы (8 символов, не id), лимиты 10/30 дней и 50 всего на пригласившего, ≥ 20 привязок за сутки → `flagged_at` и ручной ревью (audit) — docs/13 §7.1, §10 |
| Инфра | Слабые секреты в env | Компромет | `.env` не в git, секреты через CI/CD secret store, ротация `APP_KEY`, `TELEGRAM_WEBHOOK_SECRET` раз в 6 мес |
| Инфра | HTTP вместо HTTPS | MITM на TWA/webhook | Force HTTPS, HSTS (6 мес), Telegram webhooks **требуют** HTTPS |
| Зависимости | Уязвимости в composer / npm | RCE / supply chain | `composer audit` и `npm audit` в CI, Renovate/Dependabot, lockfiles в git |

## 2. Telegram Webhook — безопасность

### 2.1 Регистрация webhook
```bash
curl -X POST "https://api.telegram.org/bot${TOKEN}/setWebhook" \
  -d "url=https://lexiflow.example.com/telegram/webhook/${SECRET_IN_URL}" \
  -d "secret_token=${SECRET_HEADER}" \
  -d "allowed_updates=[\"message\",\"my_chat_member\",\"chat_member\",\"callback_query\",\"pre_checkout_query\"]"
```

Используем **два разных секрета**: один в URL (не увидят проксирующие логи), другой в заголовке — Telegram подставляет его сам.

`pre_checkout_query` (оплата Telegram Stars, docs/13 §4.1) проходит те же две проверки секретов и отвечается синхронно в запросе вебхука (`PreCheckoutResponder` → `StarsPreCheckoutGuard`): одно чтение заказа по `invoice_payload`, сравнение `from.id` с владельцем заказа, суммы и валюты — с `payment_orders` (сумма от клиента не принимается); чужому плательщику ничего о заказе не сообщается. Деньги зачитываются только по `message.successful_payment` того же вебхука через `PaymentApplier` (идемпотентность по `telegram_payment_charge_id`, docs/13 §10). `invoice_payload` (токен заказа) маскируется в логе апдейтов (`UpdateSanitizer`) и в админке (`PayloadMasker`).

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

### 2a. Вебхуки платёжных провайдеров (docs/13 §4, §10)

Маршруты `/billing/webhook/*` — без сессии/cookies/CSRF (как `/telegram/webhook`), `throttle:billing-webhook` 120/мин per IP, тело ≤ 64 КБ, необязательный allowlist IP (`BillingIpAllowlist:<provider>`, адреса — из кабинета провайдера; чужой IP → 403 до аутентификации).

- **Payme (2A.4)** — `POST /billing/webhook/payme`: `Authorization: Basic base64("Paycom:" + ключ)`, пароль сравнивается `hash_equals` с `PAYME_KEY` и `PAYME_TEST_KEY` (оба сравнения выполняются всегда; пустой ключ не совпадает ни с чем). Неверно → **HTTP 200 + JSON-RPC `-32504`**, тело запроса при этом не разбирается. Сумма берётся из `payment_orders` (несовпадение → `-31001`, ничего не пишется); транзакция идентифицируется `params.id` Payme, заказ — `account.order_id` = непредсказуемый `token`. Идемпотентность: `(provider, provider_transaction_id)` unique + `payments (provider, provider_event_id)` (`<id>:perform`, `<id>:cancel`) + блокировка строки заказа — повтор любого метода возвращает первый ответ, параллельная доставка сериализуется (`BillingConcurrencyTest`). Сбой инфраструктуры → HTTP 500 (`-32400`) без частичной записи.
- Страница `GET /billing/return?order=<token>` показывает только статус заказа (без сумм, имён, подписки), `no-store`, `noindex`, 30/мин per IP: токен — capability плательщика, по нему нельзя ничего изменить.
- **Click (2A.5)** — `POST /billing/webhook/click/prepare|complete` (form-encoded): `sign_string = md5(click_trans_id + service_id + CLICK_SECRET_KEY + merchant_trans_id [+ merchant_prepare_id] + amount + action + sign_time)`; digest считается с нашими `CLICK_SERVICE_ID`/`CLICK_SECRET_KEY` и сравнивается `hash_equals` (как и `service_id`; пустой секрет не совпадает ни с чем). Неверно → **HTTP 200 + `error: -1`**, в БД ничего не пишется; `sign_string` и секрет не попадают ни в `provider_transactions.raw`, ни в логи (`SecretRedactor`). Сумма — строка UZS, переводится в тийины строковой арифметикой и сверяется с `payment_orders.amount` (≠ → `-2`); заказ — по непредсказуемому `merchant_trans_id` = `token`; чужой провайдер/нет заказа → `-5` без деталей. Идемпотентность: `(click, click_trans_id)` unique + `payments (click, <click_trans_id>:complete)` + блокировка строки заказа — повторный Complete отвечает как первый, параллельные сериализуются (`BillingConcurrencyTest`). Сбой инфраструктуры → HTTP 500 (`-7`) без частичной записи. См. docs/13 §4.3, docs/04 D.2.

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
| `PracticeSession` (сольная практика, docs/16 §12) | view / answer / replace / audio / `retry_of` | Только владелец (`learner_id == sub`); чужая или несуществующая сессия — **404** `practice_session_not_found` (не 403); доступ к практике проверяется раньше всего (`PracticeAccess`: 403 `practice_unavailable` / `practice_pro_required`) |
| `Battle` (баттл 1 на 1, docs/16 §12) | view / poll / round / accept / decline / cancel / ready / answer / surrender / audio-failed / audio | Только участники (`challenger_id`, `opponent_id`; `Battle::involves`): чужой и несуществующий матч — **404** `battle_not_found` (не 403); `accept` и `decline` — только приглашённый (`opponent_id`; иначе 403 `battle_not_recipient`), `cancel` приглашения — только инициатор (403 `battle_not_challenger`), комнату и отсчёт покидает любой из двух. Доступ к практике проверяется раньше всего (`PracticeAccess`: 403 `practice_unavailable` / `practice_pro_required`); приглашать можно только принявшего друга, не заблокированного и с доступом к практике |
| `PracticeItem` в Filament (задания практики, 18.7a) | всё | Только активный `admin` (`PracticeItemPolicy`: `viewAny/create/update/review/publish/archive/restore`; `delete` — никому, задание уходит в архив); учитель — 403, у кабинета `/org` такого ресурса нет |
| Пилот практики на `Learner` | включить / исключить | Только `admin` (действие на строке и странице ученика); каждое реальное изменение — `audit_logs` `learner.practice_pilot_changed {from, to}` |
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
| `POST /api/twa/practice/sessions` | 10/min per learner (`throttle:twa-practice-open`) + `twa-api` | Открытие сессии = снимок + запросы материала |
| `POST /api/twa/practice/sessions/{id}/answer\|replace` | 90/min per learner (`throttle:twa-practice-answer`) + `twa-api` 60/min | Быстрый ученик сопоставляет ~40 пар в минуту |
| `GET /api/twa/practice/sessions/{id}/audio/{element}` и `GET /api/twa/practice/battles/{id}/audio/{element}` | 60/min per learner (`throttle:twa-practice-audio`), **вне** `twa-api` | Записи грузятся пачкой (пять клипов при входе в комнату диктанта); отдельный бакет, чтобы предзагрузка не съедала общий бюджет. Бакет общий для соло и баттла |
| `POST /api/twa/practice/battles` | 5/min per learner (`throttle:twa-battle-invite`) + `twa-practice-open` 10/min + `twa-api` | Приглашение = снимок на 5 раундов, три строки, ЛС другу; отказанные попытки (409, 422) тоже считаются |
| `POST /api/twa/practice/battles/{id}/accept\|decline\|cancel\|ready\|surrender\|answer\|audio-failed` | 60/min per learner (`throttle:twa-battle-action`) + `twa-api` 60/min | Один бакет на все семь действий; чтения матча (`GET /battles`, `/battles/{id}`, `…/round`, `options`) — только `twa-api` |
| `GET /api/twa/practice/battles/{id}/poll` | 90/min per learner (`throttle:twa-battle-poll`), **вне** `twa-api` | Опрос раз в 2 с — 30 запросов в минуту (две вкладки — 60). Внутри `twa-api` он съел бы общий бюджет (60) и душил раунды и ответы, поэтому свой бакет; лимит всё же есть: цикл в клиенте или скрипт упрутся в 90/мин, а не в БД. Опрос — это ещё и heartbeat игрока: 429 на нём не должен выбить игрока из матча — поэтому он не делит бакет с остальным API |
| Остальные `/api/twa/*` | 60/min per learner | ключ rate limiter'а — `learner_id` из JWT |
| Filament login | 5/15min per IP + per email | Против brute-force |
| Webhook Telegram | не ограничиваем (но валидация по secret обязательна) | |
| `POST /billing/webhook/*` (Payme, Click) | 120/min per IP (`throttle:billing-webhook`) + allowlist IP по желанию | Провайдер повторяет вызов при не-200; тело ≤ 64 КБ |
| `GET /billing/return` | 30/min per IP (`throttle:billing-return`) | Публичная страница статуса заказа по токену |

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
- **Панель `/org` (кабинет центра):** тот же кастомный TOTP-поток (`/2fa/*`, маршруты под `auth:web,org`), обязателен для владельцев центров (`RequireOrgTwoFactor`), для учителей центра — опционально. Сессия у guard'ов общая, поэтому все отметки хранятся по guard'у (`2fa.{web|org}.passed_at|user_id|pending_secret|show_recovery_codes`) и привязаны к user_id — панель принимает только отметку своего пользователя в своём guard'е; контроллер выбирает guard явно (`?guard=web|org`) и берёт пользователя из него. Лимиты: 5 неверных попыток/мин на аккаунт+guard и 20/мин на IP (429 + `Retry-After`, `Log::warning`), успех сбрасывает счётчик аккаунта; повтор уже принятого TOTP-шага отклоняется (`verifyKeyNewer`, кэш 3 мин). Сброс пароля / приглашение персонала — broker `org` (токен 24 ч, одноразовый, хранится хэшем).
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
- **Баттл:** о другом игроке в API уходит только имя (`opponent.first_name`, `from.first_name`) и только принявшего друга — ни id, ни username, ни прогресса, ни присутствия сверх `opponent_present`. В ЛС-приглашении — имя пригласившего и название курса и урока; в логах — `learner_id` и причина отказа Telegram (`telegram.battle_dm_forbidden`), без chat id и текстов;
  в `product_events` (`battle.*`) — id матча, формат, причина конца, число раундов и длительность. Матчи хранятся вместе с ответами игроков (`battle_answers.payload`); при окончательном удалении learner'а его матчи уходят каскадом (`ON DELETE CASCADE`) — и из истории соперника.

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
  с 2A.4 — также литералы `PAYME_KEY` / `PAYME_TEST_KEY` / `CLICK_SECRET_KEY`, `Basic <base64>` (заголовок Payme) и ключ/пара `sign_string` (Click);
  `provider_transactions.raw` хранится без заголовков (`{method, params}`), в админке `PayloadMasker` маскирует `order_id`, `*key*`, `sign*`;
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
- [ ] Payme: вебхук без/с чужим Basic — HTTP 200 + `-32504`; `PerformTransaction` дважды и параллельно — один `payments`; `CreateTransaction` с другой суммой — `-31001`; просроченная транзакция — `-31008`, state −1 reason 4; IP вне `PAYME_IP_ALLOWLIST` (если задан) — 403; `grep -E "Basic |PAYME_" storage/logs/laravel.log` пуст.
- [ ] Click: Prepare/Complete с неверной `sign_string` или чужим `service_id` — HTTP 200 + `-1`, в БД ничего; Complete дважды и параллельно — один `payments`; Prepare с другой суммой — `-2`; Complete без Prepare — `-6`; IP вне `CLICK_IP_ALLOWLIST` (если задан) — 403; `grep -E "CLICK_SECRET|sign_string=" storage/logs/laravel.log` пуст.
- [ ] Чужой `GET /billing/return?order=<случайный токен>` — нейтральная страница без данных заказа.
- [ ] Практика (соло, docs/16 §12): ответ `GET /practice/sessions/{id}` и `POST …/answer` не содержит `pairs` / `accepted` / `expected` / `correct_ids` / `audio_path` (любой глубины) и текста следующих элементов; чужой `GET /practice/sessions/{id}` и чужое `…/audio/{element}` — **404**;
      `order` без одного фрагмента — 422 `invalid_answer_shape`; неизвестный `element_id` / `option_id` — 422 `unknown_element_id`; повтор `request_id` с другим телом — ответ **первого** запроса; два ответа на один элемент — один засчитан, второй 409 `answer_already_accepted`;
      `GET /storage/practice-audio/…` и любой прямой URL файла — 404 (nginx не раздаёт каталог); аудио без JWT — 401; практика при `PRACTICE_ACCESS=off` — 403 `practice_unavailable` на каждом эндпоинте, а Free-ученик при `PRACTICE_PLAN=pro` — 403 `practice_pro_required`;
      учитель в `/admin/practice-items` — 403. Баттл — следующие два пункта.
- [ ] Баттл (docs/16 §12; `BattleContractTest`, `BattleHttpRulesTest`, `BattleRateLimitTest`, `BattleAudioTest`):
      чужой `GET /practice/battles/{id}`, `…/poll`, `…/round`, `…/audio/{element}` и любое действие над чужим матчем (`accept`, `ready`, `answer`, `surrender`, …) — один и тот же **404** `battle_not_found`, тело совпадает с телом несуществующего id, в матче ничего не меняется (даже `last_seen_at`); `{id}` из 20 цифр — 404 роутера, не 500;
      до разбора раунда ни `poll`, ни `GET /battles/{id}`, ни `GET …/round` не содержат ответа, вердикта и очков раунда соперника, правильного ответа и ключей `pairs` / `accepted` / `expected` / `correct_ids` / `audio_path` (любой глубины); `reveal` — `null`, `scores` не двигаются, пока раунд не разрешён;
      клип раунда, который не начался, — 404 (кроме `room` / `countdown` диктанта), без JWT — 401; `answer` после `round_deadline_at` + 1,5 с — 409 `round_closed`, в отсчёте — 409 `round_not_open`; второй ответ на раунд с новым `request_id` — 409 `answer_already_accepted`; повтор `request_id` с другим телом — **первый** результат (и после закрытия раунда);
      `order` без одного фрагмента, пары не биекцией, `request_id` не uuid, `surrender` без `confirm` — 422; `audio-failed` с чужим или неизвестным `element_id` — 422, и он не аннулирует матч, пока файл на месте;
      второй `accept` другого приглашения при занятом матче — 409 `battle_busy`; приглашение самому себе, не другу, в ожидающую дружбу, заблокированному, колоду как курс — отказ (404 / 403); шестой запрос приглашения за минуту (отказанные считаются) — 429; 91-й `poll` за минуту — 429, остальной API жив;
      `PRACTICE_BATTLE=false` — новое приглашение и `accept` дают 409 `battle_disabled`, идущий матч доигрывается; `PRACTICE_ACCESS=off` — 403 на каждом маршруте, а дворник `practice:battles-sweep` за минуту переводит живые матчи в `closed / voided`;
      ЛС-приглашение уходит один раз и только адресату открытого приглашения (после `decline`, `cancel`, принятия и истечения — не уходит); кнопка ведёт на `{twa.base_url}/twa/battles/{id}`.
- [ ] Баттл: логи и `product_events` (`battle.*`, `practice.audio_failed`) содержат только числа, id и коды (`end_reason`, `result`, `rounds_played`, `duration_ms`), без имён, ответов и текстов; `telegram.battle_dm_forbidden` — `learner_id` и причина без chat id; снимок матча не попадает ни в лог, ни в ответ API.
- [ ] Практика: логи и `product_events` содержат только числа, id и коды причин (`practice.audio_failed` — `version_id`, `reason`; `practice.collocation_content_unplayable` — `item_id`, `version_id`; события — docs/04 A.16), без текстов заданий и ответов учеников; снимок сессии не попадает ни в лог, ни в ответ API.

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
