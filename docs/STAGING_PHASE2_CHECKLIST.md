# Чек-лист приёмки Фазы 2 на staging и в песочницах провайдеров (задача 2R.9)

Документ для владельца / DevOps. Это ручная приёмка: автотесты (pint, phpstan, pest) уже зелёные, здесь проверяется то,
что тестами не проверить — реальные Telegram, Payme, Click, реальная инфраструктура. Закрывает также 2A.8 (smoke
с sandbox, вручную владельцем) и 2R.8 / 0.7 (restore, ZAP, k6, Lighthouse).

Источники (если чек-лист и они расходятся — правда в них): `docs/13_PHASE2_DESIGN.md` (§4, §15 и заметки «Реализация …»),
`docs/DEVOPS_GUIDE.md` (§11, §12, §15, §17), `docs/ADMIN_GUIDE.md` (Billing, Backups), `.env.example`, `config/billing.php`,
`config/referrals.php`, `config/leagues.php`, `routes/console.php`.

Пометка **«уточнить»** — в проекте этого нет или это решение владельца; не угадывайте, зафиксируйте ответ и допишите сюда.

Как пользоваться: идти сверху вниз, ставить `[x]`, расхождения записывать в таблицу раздела 9. Не включайте
`BILLING_PAYWALL_MODE=purchase` на production, пока не пройдены разделы 2–5 для включаемого провайдера.

---

## 0. Что нужно до начала

- [ ] Staging на отдельном домене с HTTPS (`APP_URL` = публичный https-origin; от него строятся return-URL платежей и
      ссылки, `docs/DEVOPS_GUIDE.md` §16, §17), **отдельный** staging-бот (BotFather) и отдельная БД PostgreSQL. Данные и ключи
      production на staging не использовать.
- [ ] Доступы: admin-аккаунт с 2FA (`/admin`), кабинет Payme (тестовый, test.paycom.uz), кабинет Click (тестовый сервис),
      реальный Telegram-аккаунт для Stars, второй Telegram-аккаунт (друг / приглашённый / «чужой» плательщик), владелец
      тестового центра (аккаунт `users` с 2FA для `/org`).
- [ ] Бухгалтерия владельца дала: ИКПУ и код упаковки для Payme (`PAYME_FISCAL_*`), реквизиты продавца (`SITE_LEGAL_*`),
      контакт поддержки (`BILLING_SUPPORT_CONTACT`) — иначе пункты про фискализацию и счёт центра проверяются частично.
- [ ] Решения владельца, блокирующие часть пунктов (docs/13 §12): окно возврата Payme (`PAYME_CANCEL_AFTER_PERFORM_DAYS`),
      условия вывода Stars — **уточнить** у владельца.

---

## 1. Подготовка staging

### 1.1 Переменные окружения Фазы 2

Список — всё, что добавлено в `.env.example` после коммита `12a881e` (`git diff 12a881e HEAD -- .env.example`).
«Обяз.» — нужна для проверяемой функции; «Опц.» — есть рабочий дефолт. Секреты — только в `.env` (`chmod 600`/`640`), не в git,
чате и тикетах (`docs/DEVOPS_GUIDE.md` §17.1). После правки `.env`: `php artisan config:cache` и рестарт воркеров
(`supervisorctl restart lexiflow-worker:*` или `docker compose -f docker-compose.prod.yml restart queue-high queue-default scheduler app`).

**Telegram / обучение**

| Переменная | Дефолт | Статус | Откуда значение |
|---|---|---|---|
| `TELEGRAM_OUTBOUND_GLOBAL_PER_SECOND` | `25` | Опц. | Общий потолок фоновых отправок бота в секунду, `0` = выключено; счётчик в Redis-кэше (DEVOPS §11) |
| `FSRS_ROLLOUT_PERCENT` | `0` | Опц. | Доля НОВЫХ learner'ов в FSRS (0..100); для проверки раздела 6.9 поставить, например, `50` |
| `FSRS_DESIRED_RETENTION` | `0.9` | Опц. | Целевое удержание |
| `FSRS_WEIGHTS` | закомментировано | Опц. | 19 параметров FSRS-5 через запятую; пусто = официальные дефолты |
| `MOCK_EXAM_QUESTIONS` / `MOCK_EXAM_SECONDS` | `40` / `1200` | Опц. | Вопросов в пробном тесте / лимит на весь тест, с |
| `MOCK_EXAM_PERCENTILE_DAYS` / `MOCK_EXAM_PERCENTILE_CACHE_TTL` | `90` / `3600` | Опц. | Окно перцентиля, дни / TTL кэша, с |
| `LEARNERS_STREAK_FREEZE_MAX` | `2` | Опц. | Максимум заморозок у Pro; `0` = выключено |

**Оплата — общее**

| Переменная | Дефолт | Статус | Откуда значение |
|---|---|---|---|
| `BILLING_PAYWALL_MODE` | `coming_soon` | Обяз. для оплаты | `purchase` включает покупки; при пустом каталоге API само откатывается на `coming_soon` |
| `BILLING_SUPPORT_CONTACT` | пусто | Обяз. для `/paysupport` | Владелец: e-mail, `@handle` или `https://`-URL |
| `BILLING_PRICE_PRO_MONTH_XTR` / `_YEAR_XTR` | `150` / `1300` | Опц. | Целые Stars; для проверки Stars на staging можно `1` (DEVOPS §17.3) |
| `BILLING_PRICE_PRO_MONTH_UZS` / `_YEAR_UZS` | `2900000` / `24900000` | Опц. | Тийины (сум × 100); `0` = не продаётся |
| `BILLING_PRICE_TUTOR_MONTH_UZS` | `12900000` | Опц. | Тийины |
| `BILLING_PRICE_CENTER_S_MONTH_UZS` | `34900000` | Опц. | Тийины |
| `BILLING_PRICE_CENTER_M_MONTH_UZS` | `84900000` | Опц. | Тийины |
| `BILLING_ORG_YEAR_DISCOUNT_PERCENT` | `17` | Опц. | Скидка года центра против 12 месяцев, 0..99 |
| `BILLING_PRICE_{TUTOR,CENTER_S,CENTER_M}_YEAR_UZS` | пусто | Опц. | Явная цена года (тийины); если задана — приоритетнее скидки |
| `BILLING_ORDER_TTL_MINUTES` | `1440` | Опц. | Жизнь неоплаченного заказа |
| `BILLING_POST_ORG_DISCOUNT_PCT` | `30` | Опц. | Скидка оффера «потерял место в центре» |
| `BILLING_INVOICE_REQUEST_EMAIL` | пусто | Обяз. для кнопки «Запросить счёт» (или `SITE_LEGAL_EMAIL`) | Почта, куда уходит запрос; обе пусты = кнопки нет |
| `SITE_LEGAL_NAME`, `_INN`, `_ADDRESS`, `_BANK`, `_BANK_ACCOUNT`, `_MFO`, `_PHONE`, `_EMAIL` | пусто | Обяз. для печатного счёта центра | Бухгалтер владельца; пустые поля в счёте не печатаются |

**Провайдеры**

| Переменная | Дефолт | Статус | Откуда значение |
|---|---|---|---|
| `BILLING_STARS_ENABLED` | `false` | Обяз. для Stars | `true` на staging-боте |
| `BILLING_STARS_MAX_SUBSCRIPTION_PRICE` | `10000` | Опц. | Потолок цены Stars-подписки (подтвердить в §2 п. 2.8) |
| `BILLING_STARS_SUBSCRIBE_WINDOW_DAYS` | `7` | Опц. | Окно, в котором Stars-подписку можно купить поверх оплаченного периода |
| `PAYME_ENABLED` | `false` | Обяз. для Payme | `true` |
| `PAYME_MERCHANT_ID` | пусто | Обяз. | Тестовый кабинет Payme (пусто = checkout-ссылка не строится, заказ `failed`) |
| `PAYME_KEY` | пусто | Обяз. на production; на staging может быть пуст | Ключ боевого кассы (секрет) |
| `PAYME_TEST_KEY` | пусто | Обяз. для песочницы | Ключ тестового кабинета (секрет); очистить после тестов |
| `PAYME_ALLOW_TEST_KEY` | `false` | Опц. | При `APP_ENV=production` тестовый ключ отвергается (`-32504`), пока не `true`; если staging запущен с `APP_ENV=production` — поставить `true` только на время песочницы |
| `PAYME_CHECKOUT_URL` | `https://checkout.paycom.uz` | Обяз. для песочницы: `https://checkout.test.paycom.uz` | Документация Payme |
| `PAYME_IP_ALLOWLIST` | пусто | Опц. | IP/CIDR Payme из кабинета; пусто = выключено (не-из-списка получит 403) |
| `PAYME_CANCEL_AFTER_PERFORM_DAYS` | пусто | Опц. | Окно возврата после оплаты, дни; пусто = всегда; решение владельца (**уточнить**) |
| `PAYME_FISCAL_IKPU`, `PAYME_FISCAL_PACKAGE_CODE`, `PAYME_FISCAL_VAT_PERCENT` | пусто, пусто, `0` | Обяз. для фискализации | Бухгалтер владельца; `detail` отдаётся только при заданном `PAYME_FISCAL_IKPU` |
| `CLICK_ENABLED` | `false` | Обяз. для Click | `true` |
| `CLICK_MERCHANT_ID`, `CLICK_SERVICE_ID` | пусто | Обяз. | Кабинет Click → Settings → Services (тестовый сервис). `service_id` входит в подпись |
| `CLICK_SECRET_KEY` | пусто | Обяз. (секрет) | Секретный ключ того же сервиса |
| `CLICK_MERCHANT_USER_ID` | пусто | Опц. | Добавляется в ссылку как `merchant_user_id` |
| `CLICK_CHECKOUT_URL` | `https://my.click.uz/services/pay` | Опц. | Для теста — та же страница с тестовым сервисом (DEVOPS §17.1) |
| `CLICK_IP_ALLOWLIST` | пусто | Опц. | Адреса серверов Click (кабинет/документация) |
| `CLICK_TRANSACTION_TIMEOUT_MS` | `1800000` | Опц. | Open Prepare старше — «мёртвый» |

**Рост и удержание**

| Переменная | Дефолт | Статус | Откуда значение |
|---|---|---|---|
| `REFERRALS_REWARD_DAYS` | `7` | Опц. | Дни Pro каждой стороне |
| `REFERRALS_ACTIVATION_DAYS` / `_REVIEWS_PER_DAY` / `_WINDOW_DAYS` | `3` / `10` / `14` | Опц. | Правило активации; для быстрой проверки раздела 6.2 можно временно снизить (например, 1 / 1; значения — предложение, не из docs) и вернуть |
| `REFERRALS_MAX_REWARDS_PER_30D` / `_MAX_REWARDS_LIFETIME` | `10` / `50` | Опц. | Лимиты пригласившего |
| `REFERRALS_INVITEE_REWARDS_PER_30D` | `20` | Опц. | Лимит наград приглашённым одного пригласившего |
| `REFERRALS_FLAG_LINKS_PER_DAY` | `20` | Опц. | Порог флага всплеска |
| `LEAGUES_ENABLED` | `true` | Опц. | Выключатель лиг |
| `LEAGUES_TIMEZONE` | `Asia/Tashkent` | Опц. | Граница недели; закрытие в 00:00 по этому поясу, страховочный запуск в 00:05 |
| `LEAGUES_GROUP_CAPACITY` / `LEAGUES_PROMOTE` / `LEAGUES_DEMOTE` | `30` / `5` / `5` | Опц. | Размер группы, зоны |
| `LEAGUES_POINTS_REVIEW` / `_CORRECT` / `_NEW_WORD` / `_GOAL_MET` | `1` / `1` / `2` / `10` | Опц. | Очки за строку `learner_daily_stats` |
| `LEAGUES_STANDINGS_CACHE_SECONDS` / `LEAGUES_NOTIFY_TTL_HOURS` | `60` / `12` | Опц. | Кэш таблицы / срок актуальности ЛС итогов (единый для всей рассылки, считается от закрытия недели) |
| `LEAGUES_NOTIFY_BATCH_SIZE` / `LEAGUES_NOTIFY_BATCH_SECONDS` | `100` / `30` | Опц. | ЛС итогов идут пачками `SendLeagueResultsJob`: участников в одной пачке / секунд отправки, после которых остаток уходит новым job'ом (держать заметно ниже `--timeout` воркера, 120 с) |

Также влияют: `TELEGRAM_BOT_USERNAME` (без него нет ссылок-приглашений центров, рефералки, друзей, кнопки «Поделиться»),
`ACHIEVEMENTS_ENABLED` (дефолт `true`, `config/achievements.php`),
`ORGANIZATIONS_INVITE_BURST_PER_DAY` (`50`, `config/organizations.php`).

- [ ] Все обязательные переменные для проверяемых провайдеров заданы; `php artisan config:show billing.providers` не показывает пустых
      `merchant_id` / `service_id` / `secret_key` у включённых провайдеров (в выводе секреты — не копировать в чат).
- [ ] `APP_ENV` staging записан: ______ (определяет поведение `PAYME_TEST_KEY`, см. раздел 3.1).
- [ ] Секреты не утекают в логи: `grep -E "PAYME_|CLICK_SECRET|sign_string|Authorization: Basic" storage/logs/laravel.log` — пусто
      (повторить в конце приёмки).

### 1.2 Миграции и кэши

- [ ] `php artisan migrate --force` проходит без ошибок; `php artisan migrate:status` — все `Ran`.
- [ ] Откат миграций Фазы 2 на копии БД (не на staging с данными приёмки): `php artisan migrate:rollback --step=<N>` и снова `migrate`
      — у всех миграций есть `down()`. Ожидание: без ошибок. (Опционально; тесты `DatabaseSchemaTest` это покрывают, **уточнить**, нужен ли ручной прогон.)
- [ ] `php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache`; после — рестарт воркеров.
- [ ] `php artisan telegram:set-webhook` перезапущен (после 2A.3 `allowed_updates` включает `pre_checkout_query`; без него Stars-инвойс
      не оплатить). Проверка: `curl "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/getWebhookInfo"` — в `allowed_updates`:
      `message, my_chat_member, chat_member, callback_query, pre_checkout_query`; `last_error_message` пуст; `url` ведёт на staging.
- [ ] Все апдейты видны боту: бот добавлен администратором в тестовую группу (нужно для событий выхода из группы центров, ADMIN_GUIDE «Organizations»).

### 1.3 Планировщик и очереди

- [ ] Cron `* * * * * … php artisan schedule:run` (bare-метал, DEVOPS §11) или контейнер `scheduler` (`schedule:work`, docker-compose.prod.yml).
- [ ] Воркеры слушают `--queue=high,default,low` (supervisor / сервис `queue`); `php artisan queue:work` не падает.
- [ ] `php artisan schedule:list` показывает все команды из `routes/console.php`:

| Команда | Расписание | Что делает |
|---|---|---|
| `ops:scheduler-heartbeat` (callback) | каждую минуту | метка живости для `/health/ready` |
| `exams:close-expired` | каждую минуту | закрывает просроченные экзамены; брошенные пробные тесты (mock) без лидерборда |
| `training:close-expired` | каждые 15 мин | просроченные личные/групповые сессии |
| `challenges:close-due` | каждые 15 мин | закрытие вызовов друзей, истечение неотвеченных |
| `subscriptions:expire` | ежечасно | переходы подписок по времени |
| `billing:expire-orders` | каждые 15 мин | неоплаченные заказы старше TTL → `expired` |
| `subscriptions:remind-expiring` | ежедневно 06:00 UTC | напоминания о конце периода за 3 и 1 день |
| `organizations:sync-members` | ежечасно | ремонт членства центров |
| `assignments:refresh` | каждые 30 мин | снимки заданий, напоминания в группу за 24 ч, автозакрытие |
| `leagues:close-week` | ежедневно 00:00 и страховочный 00:05 в `LEAGUES_TIMEZONE` (одна запись расписания) | закрытие недели лиг по группам (идемпотентно, возобновляемо), ЛС итогов пачками |
| `referrals:recover` | каждые 30 мин | повтор наград рефералки после потерянного job'а |
| `billing:reconcile-stars` | ежедневно 04:30 UTC | сверка Stars-леджера с Telegram (read-only; при выключенных Stars молчит) |
| `reminders:dispatch` | каждые 15 мин | личные напоминания по локальному времени |
| `model:prune` | ежедневно 03:00 | ретеншн `product_events`, `notifications_log` |
| `backup:clean` / `backup:run` / `backup:monitor` | 01:00 / 01:30 / 02:00 | бэкапы |

- [ ] `curl -s https://<staging>/health/ready` → `200 {"status":"ok",...}` (db, redis, scheduler, queue); heartbeat зеленеет в течение минуты.
- [ ] `failed_jobs` пуст перед началом (`php artisan queue:failed`).

### 1.4 GD / FreeType, шрифты, хранилище карточек достижений

- [ ] В контейнере `app` (и `queue` — карточки рендерит job): `php -r 'var_dump(gd_info()["FreeType Support"]);'` → `bool(true)`.
      Если `false`/ошибка — образ собран без `freetype-dev libpng-dev` + `docker-php-ext-configure gd --with-freetype` (должно быть в `Dockerfile`); пересобрать.
      Проверять и в контейнере `queue`, не только в `app` (а на bare-метале — в PHP CLI воркера).
- [ ] Шрифты на месте: `ls resources/fonts` → `NotoSans-Bold.ttf  NotoSans-Regular.ttf  OFL.txt` (в образ попадают через `COPY . .`;
      `.dockerignore` каталог `resources/fonts` не исключает).
- [ ] Каталог карточек `storage/app/public/achievements/` (диск `public`, `config/achievements.php`) создаётся и **доступен на запись**
      пользователю воркера (в Dockerfile создан только `storage/app/public/audio` — каталог `achievements` создаёт рендерер; проверить, что `storage/app/public` принадлежит `app`).
- [ ] `php artisan storage:link` для достижений **не требуется**: по docs/13 §7.4 карточка отправляется в бот multipart-загрузкой (`sendPhoto`)
      и по URL не раздаётся. Если ваш nginx/volume отдаёт `public`-диск, ничего больше не нужно. (`storage:link` нужен лишь тому,
      кто хочет отдавать `/storage/...` напрямую; для аудио в Docker используется отдельный том `app_audio`, DEVOPS §10.1.)
- [ ] Рендер вручную (проверка без ожидания события, одноразово в tinker): выдать себе достижение через раздел 6.5; файл появился
      как `storage/app/public/achievements/<ключ>-<ru|uz>-<hash>.png`, размер ≈ 40–50 КБ, 1080×1080.
- [ ] Если перед сервером стоит CDN / балансировщик / обратный прокси — настроены trusted proxies в `bootstrap/app.php`, иначе `*_IP_ALLOWLIST` и throttle
      `billing-webhook`/`twa-*` видят адрес прокси (DEVOPS §17.2).

---

## 2. Telegram Stars

Основа: docs/13 §4.1, «Реализация 2A.3», §15 «Сверить перед включением Stars на стенде»; DEVOPS §17.3 п. 3.
Тестовая среда Telegram (отдельный test-DC) в документации не описана — DEVOPS предписывает **реального пользователя на staging-боте с малой ценой**
(`BILLING_PRICE_PRO_MONTH_XTR=1`). Нужна ли тестовая среда Telegram — **уточнить**.

Подготовка:
- [ ] `BILLING_STARS_ENABLED=true`, `BILLING_PRICE_PRO_MONTH_XTR=1`, `BILLING_PRICE_PRO_YEAR_XTR=` малая цена (≥ 1), `BILLING_PAYWALL_MODE=purchase`, `config:cache`, рестарт воркеров.
- [ ] `GET /api/twa/billing/catalog` (с TWA-токеном): `items` и `providers` содержат Stars, и только включённые провайдеры.
- [ ] Бот `/paysupport` отвечает (контакт `BILLING_SUPPORT_CONTACT`, правила возврата, ссылка на `/offer`) — требование Telegram.

Сценарии:
- [ ] **2.1 Инвойс месяц.** `/pro` → кнопка покупки месяца (`pro1:buy:month:telegram_stars`) создаёт заказ только по нажатию.
      Открывается инвойс-ссылка; в нём подписка (30 дней = `subscription_period` 2592000); сумма = цена из конфига; `provider_token` не используется.
- [ ] **2.2 Инвойс год.** Год — разовый инвойс (без `subscription_period`), 365 дней.
- [ ] **2.3 `pre_checkout_query`.** Ответ приходит за ≤ 10 с, синхронно. Негативы: оплатить ссылку **другим** Telegram-аккаунтом — отказ с текстом (`foreign`);
      истёкший/оплаченный заказ, повтор по старой ссылке (`order_expired`/`order_closed`), отключённый Stars (`provider_disabled`). В логе `billing.stars.pre_checkout_refused` с `reason`.
- [ ] **2.4 `successful_payment`.** После оплаты бот отвечает «Pro до …» с web_app-кнопкой «Сегодня»; в **Payments** — строка `succeeded`, `kind=charge`,
      `provider_charge_id` = `telegram_payment_charge_id`; заказ `paid`; подписка `active` (**Subscriptions**), `current_period_ends_at` = `subscription_expiration_date` (месяц).
      Сверить сырой `successful_payment` по полям (см. таблицу «Что сверить по полям Bot API» ниже).
- [ ] **2.5 Идемпотентность.** Повторная доставка того же апдейта не создаёт вторую строку Payments.
- [ ] **2.6 Отмена / возобновление автопродления.** В `/pro` (и в TWA `POST /api/twa/billing/subscription/cancel|resume`) «Отменить автопродление» →
      подписка `canceled` (live до конца периода, без grace), у Telegram вызван `editUserStarSubscription(is_canceled=true)`; «Возобновить» → `active`.
      Пока автопродление работает, в `/pro` нет кнопок покупки (иначе двойное списание). При сбое Bot API подписка НЕ меняется.
- [ ] **2.7 Возврат.** **Payments → Refund** (admin, `note` обязателен) на `succeeded`-платеже: вызван `refundStarPayment`, платёж `refunded`, появилась
      строка `refund`, подписка завершена (если возвращён текущий период), audit `payment.refunded`. Затем в Telegram приходит эхо `refunded_payment` — бот молчит
      (`already_refunded`). Второй клик/второй admin → «already refunded», второго вызова провайдера нет.
- [ ] **2.8 Продление.** Реальное продление через 30 дней на staging не дождаться — **уточнить**, как проверить (при `is_recurring=true` — `kind=renewal`).
      Минимум: проверить разбор по сырому payload'у первого платежа (`is_first_recurring`).
- [ ] **2.9 Сверка.** `php artisan billing:reconcile-stars` → код выхода 0 (`echo $?`); после намеренного пропуска апдейта (напр. отключить webhook на время платежа) →
      код 1 и в логе `billing.reconcile_missing` / `billing.reconcile_missing_refund`.

Что сверить по полям Bot API (§15 п. 1 и блок «Stars на стенде»; при расхождении — править **только** указанное место):

| № | Что проверить | Где в коде при расхождении |
|---|---|---|
| S1 | `successful_payment`: имена `subscription_expiration_date` (unix), `is_recurring`, `is_first_recurring`; `telegram_payment_charge_id` у каждого списания свой, `invoice_payload` одинаков | `TelegramStarsProvider::parseSuccessfulPayment`; фикстуры `tests/Feature/Billing/TelegramStarsProviderTest.php`, `StarsPaymentFlowTest.php` |
| S2 | `refunded_payment`: `telegram_payment_charge_id`, `total_amount`, `currency`, `invoice_payload`; приходит как `message.refunded_payment` и после нашего `refundStarPayment` | `TelegramStarsProvider::parseRefundedPayment` |
| S3 | `subscription_period` допускает только 2592000; потолок цены подписки ≤ 10 000 XTR | `BILLING_STARS_MAX_SUBSCRIPTION_PRICE`, константа периода в `createCheckout` |
| S4 | `editUserStarSubscription`: нужен `telegram_payment_charge_id` первого списания или последнего (мы передаём `subscriptions.meta.stars.charge_id` — первый); принимает ли первый после продления | `TelegramStarsProvider::cancelAutoRenew` |
| S5 | Две подписки подряд (два инвойса `month`) → два charge id; `editUserStarSubscription` по всем id из `meta.stars` не падает на втором | `StarsRenewalStopper` |
| S6 | Останавливает ли `refundStarPayment` подписку сам; если нет — отменяет ли её наш `editUserStarSubscription(false→true)` после возврата, и с каким charge id | `StarsRenewalStopper` |
| S7 | Уведомляет ли Telegram бота об отмене подписки пользователем в настройках Stars (мы ничего не обрабатываем; строка остаётся `active` до период + grace) | если шлёт — нужен новый обработчик (задача архитектору); пока ловит `billing:reconcile-stars` |
| S8 | `getStarTransactions`: поля `source`/`receiver` с `type=user`, `id` = `telegram_payment_charge_id`, `date` unix, страница ≤ 100; порядок записей (новые первыми?) и устойчивость cut-off к одинаковым `date` | `StarsReconciliationService` |
| S9 | `pre_checkout_query` приходит только на первый платёж подписки; что видит пользователь при `ok=false` по старой ссылке (`already_subscribed`); сколько живёт `createInvoiceLink` | — (поведение Telegram; зафиксировать) |
| S10 | Шеринг карточки из TWA через `shareMessage` / `savePreparedInlineMessage` (Bot API 8.0) — не сверялся, не реализован (см. 6.5) | — (идея на будущее) |

---

## 3. Payme (песочница)

Основа: docs/13 §4.2, «Реализация 2A.4», §15 п. 2 и блок «Сверить в песочнице Payme»; DEVOPS §17.1–§17.3.

### 3.1 Подключение

- [ ] Тестовый кассир в test.paycom.uz; `PAYME_MERCHANT_ID` = его id, `PAYME_TEST_KEY` = тестовый ключ,
      `PAYME_CHECKOUT_URL=https://checkout.test.paycom.uz`, `PAYME_ENABLED=true`, `config:cache`.
- [ ] Endpoint в кабинете (Настройки кассы → Endpoint): `https://<staging>/billing/webhook/payme`, логин `Paycom`, пароль = тестовый ключ.
- [ ] Тестовый ключ и `APP_ENV`: если `APP_ENV=production` на staging — нужен `PAYME_ALLOW_TEST_KEY=true`, иначе все вызовы получают `-32504`.
      Проверить оба режима: при `APP_ENV=production` и `PAYME_ALLOW_TEST_KEY=false` тестовый ключ отвергается; **на настоящем production**
      `PAYME_TEST_KEY` пуст и `PAYME_ALLOW_TEST_KEY=false` — чек-пункт финального go-live (раздел 8).
- [ ] Смоук доступности: `curl -s -X POST https://<staging>/billing/webhook/payme -d '{}'` → HTTP 200 и `"code":-32504`
      (404/419/5xx = маршрут, CSRF или nginx настроены неверно).
- [ ] Проверка неверного ключа: `curl -s -u 'Paycom:wrong' -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"CheckTransaction","params":{"id":"x"}}' https://<staging>/billing/webhook/payme` → HTTP 200, `-32504`.
- [ ] Заказ для тестов: `/pro` (бот или TWA) → оплата через Payme; ссылка вида `https://checkout.test.paycom.uz/<base64>`;
      base64 декодируется в `m=<merchant>;ac.order_id=<token>;a=<тийины>;l=<ru|uz>;c=<APP_URL>/billing/return?order=<token>&lang=<l>`.

### 3.2 Шесть методов JSON-RPC и ожидаемые ответы

Сценарии «Merchant API test» запускать из кабинета Payme (DEVOPS §17.3 п. 1). Ожидания по нашей реализации:

| Метод | Сценарий | Ожидаемый ответ |
|---|---|---|
| `CheckPerformTransaction` | верный заказ и сумма | `{allow: true}` (+ `detail` только при `PAYME_FISCAL_IKPU`) |
| | неизвестный `order_id` | `-31050` (`data: order_id`) |
| | заказ не `pending` / истёк | `-31051` |
| | сумма ≠ заказу | `-31001` |
| | у заказа уже открытая транзакция state 1 | `-31052` |
| | заказ центра, тариф изменился | `-31053`; центр не `active` → `-31054` |
| | у ученика живая Stars-подписка с автопродлением | `-31055` |
| `CreateTransaction` | новая | `{create_time, transaction, state: 1}` |
| | повтор с тем же `id` | то же состояние (идемпотентно) |
| | второй `id` на заказ с открытой транзакцией | `-31052` (`data: order_id`) |
| | `time` старше 12 ч (43 200 000 мс) | `-31008`, строка не создаётся |
| `PerformTransaction` | первый раз | `{transaction, perform_time, state: 2}`; в **Payments** `succeeded`, подписка `active` |
| | повтор | тот же `perform_time`, state 2, второго платежа нет |
| | неизвестный `id` | `-31003` |
| | state −1/−2 | `-31008` |
| | открытая просроченная (> 12 ч) | state −1, reason 4, `-31008` |
| `CancelTransaction` | до perform (state 1) | state −1, `cancel_time`; заказ остаётся `pending` (плательщик может повторить оплату) |
| | после perform (state 2) | state −2, `applyRefund` (**Payments**: строка `refund`, подписка завершена, если период текущий) |
| | повтор / другой `reason` после −1/−2 | сохранённое состояние и первый `reason` |
| | вне окна `PAYME_CANCEL_AFTER_PERFORM_DAYS` | `-31007`, состояние не меняется |
| `CheckTransaction` | любой известный `id` | `{create_time, perform_time, cancel_time, transaction, state, reason}`; нулевые `perform_time`/`cancel_time` = 0, `reason` null |
| | неизвестный | `-31003` |
| `GetStatement` | `{from, to}` | транзакции с `create_time` в интервале (включительно): `id, time, amount, account{order_id}, create_time, perform_time, cancel_time, transaction, state, reason, receivers:null` |

Прочие протокольные коды: неверный Basic — HTTP 200 + `-32504`; битый JSON `-32700`; неизвестный метод (в т.ч. `SetFiscalData`, `ChangePassword`) `-32601`;
невалидные params / тело > 64 КБ `-32600`; сбой инфраструктуры — **HTTP 500 + `-32400`**. Все коды диапазона аккаунта `-31050…-31099` отдаются с `message {ru, uz, en}` и `data: order_id`.

- [ ] Все строки таблицы выше пройдены в песочнице Payme (отметить расхождения в разделе 9).
- [ ] **Таймаут 12 ч.** Негативный `CreateTransaction` с `time` старше 43 200 000 мс → `-31008`. `PerformTransaction` после таймаута
      проверяется сценарием кабинета Payme («after timeout»); как ускорить на staging вручную (сдвиг `create_time` в БД) — **уточнить**.
      После таймаута заказ снова доступен новому `CreateTransaction` (старая открытая транзакция закрыта reason 4).
- [ ] **Повторы.** `PerformTransaction` ×2 и `CreateTransaction` ×2 с тем же `id` — одна строка **Payments**, один `perform_time`.
- [ ] **Ответ на `PerformTransaction` по системно отменённой (таймаут) транзакции:** что отвечает Payme на наш `-31008` (§15, ревью 2A.8) — зафиксировать: ______.
- [ ] **HTTP 500 + `-32400`** при сбое инфраструктуры: Payme действительно повторяет вызов? Если нет — отдавать 200 и полагаться на ретраи по `-31008` (§15) — зафиксировать: ______.

### 3.3 Фискализация

- [ ] Без `PAYME_FISCAL_IKPU` `CheckPerformTransaction` отдаёт `{allow: true}` **без** `detail`.
- [ ] С `PAYME_FISCAL_IKPU`, `PAYME_FISCAL_PACKAGE_CODE`, `PAYME_FISCAL_VAT_PERCENT`: `result.detail = {receipt_type: 0, items: [{title, price, count: 1, code, package_code, vat_percent}]}`;
      `title` — `billing.payme.receipt_item` на локали заказа, `price` = `amount` (тийины).
- [ ] Песочница принимает формат `detail`: имена `receipt_type`, `items[].code` (ИКПУ/MXIK), `package_code`, `vat_percent`, `count`, `price`, `title`; обязательность `detail` — **сверить** и записать: ______.
      Расхождение — правка формирования `detail` в `PaymeRpcService::checkPerformTransaction`. ИКПУ и код упаковки — от бухгалтера владельца.

### 3.4 Сквозная оплата и возврат

- [ ] `/pro` → страница Payme (тестовая карта из кабинета) → возврат на `/billing/return?order=<token>` показывает «Оплата получена» (страница без секретов; игнорирует добавленные Payme параметры) —
      проверить, что Payme возвращает на `c` без добавления своих параметров и поддерживает `l`: `ru`/`uz`/`en`.
- [ ] **Payments**: `succeeded`; **Payment orders**: `paid`; **Provider transactions**: state 2; **Subscriptions**: `active`, провайдер `payme`.
- [ ] Отмена платежа из тестового кабинета → появилась строка `refund`, подписка завершена, транзакция state −2.
- [ ] Пока у ученика живая Stars-подписка с автопродлением, заказ Payme/Click отклоняется (`checkout_conflict` в API, `-31055` в Payme).
- [ ] `PAYME_IP_ALLOWLIST`: адреса Payme из кабинета, вызов с адреса вне списка → 403 до аутентификации; (**уточнить** список адресов Payme).
- [ ] В **Provider transactions** сырые данные замаскированы (`[redacted]` для ключей/подписей, `order_id`); в логе нет ключей.

---

## 4. Click (песочница)

Основа: docs/13 §4.3, «Реализация 2A.5», §15 п. 3 и блок «Сверить в песочнице Click»; DEVOPS §17.3 п. 2.

### 4.1 Подключение

- [ ] Тестовый сервис Click: `CLICK_SERVICE_ID`, `CLICK_MERCHANT_ID`, `CLICK_SECRET_KEY` (того же сервиса), `CLICK_ENABLED=true`, `config:cache`.
- [ ] В кабинете: **Prepare URL** `https://<staging>/billing/webhook/click/prepare`, **Complete URL** `https://<staging>/billing/webhook/click/complete`.
- [ ] Смоук: `curl -s -X POST https://<staging>/billing/webhook/click/prepare -d 'action=0'` → HTTP 200, `"error":-8`;
      полностью оформленная форма без/с неверной подписью → `"error":-1`.
- [ ] Ссылка оплаты: `https://my.click.uz/services/pay?service_id=&merchant_id=&amount=<UZS с двумя знаками, напр. 29000.00>&transaction_param=<token>&return_url=<APP_URL>/billing/return?order=<token>&lang=<ru|uz>` (+ `merchant_user_id`, если задан).
      Проверить в песочнице: параметры `service_id`, `merchant_id`, `transaction_param`, `return_url`, `merchant_user_id` приняты; Click возвращает плательщика на `return_url`.

### 4.2 Prepare / Complete, подпись

- [ ] Подпись Prepare: `md5(click_trans_id + service_id + SECRET_KEY + merchant_trans_id + amount + action + sign_time)`.
- [ ] Подпись Complete: то же, но `merchant_prepare_id` между `merchant_trans_id` и `amount` (подтверждена косвенно — **проверить в песочнице**;
      при расхождении правится одна строка в `ClickSignature::digest()` и в `Tests\Support\ClickFixtures::sign`).
- [ ] Тело запроса — form-encoded; `sign_time` формата `YYYY-MM-DD HH:mm:ss`; JSON-тело и query-string → −8.
- [ ] Формат `amount`: `29000.00`, `29000`, `29000.5` принимаются; больше двух знаков — только нули (иначе −8/−2 по `ClickAmount`).
- [ ] Ответ всегда HTTP 200 JSON `{click_trans_id, merchant_trans_id, merchant_prepare_id | merchant_confirm_id, error, error_note}`.

### 4.3 Коды ошибок (проверить каждый; порядок проверок: размер тела → форма → подпись → сумма → заказ)

| Код | Условие | Как воспроизвести |
|---|---|---|
| 0 | успех | нормальная оплата |
| −1 | неверная подпись **или неверный `service_id`** (ответ одинаков намеренно) | испортить `sign_string` / подставить чужой `service_id` |
| −2 | сумма ≠ заказу (Prepare) / ≠ prepared (Complete) | другая `amount` |
| −3 | неизвестный `action` | `action=7` |
| −4 | заказ уже `paid` (Prepare); `error<0` по уже выполненной транзакции | Prepare на оплаченный заказ |
| −5 | `merchant_trans_id` не найден **или заказ другого провайдера** | неизвестный/чужой токен |
| −6 | Complete: транзакция не найдена по `click_trans_id` + `merchant_prepare_id`, или `merchant_trans_id` ≠ токену её заказа | Complete без Prepare / с чужим `merchant_prepare_id` |
| −7 | сбой обновления; **HTTP 500 + −7** при неожиданном исключении (Click должен повторить) | только при реальной аварии |
| −8 | ошибка запроса: нет параметров, JSON-тело, тело > 64 КБ, `click_trans_id` на другом заказе | `action=0` без полей |
| −9 | заказ `expired`/`canceled`/`failed` или `pending` за `expires_at`; транзакция отменена | Prepare на просроченный заказ; Complete с `error<0`; повтор Prepare на отменённую |

- [ ] Все коды выше воспроизведены, `error_note` осмысленный.
- [ ] **Идемпотентность Prepare:** повтор с тем же `click_trans_id` → тот же `merchant_prepare_id`. Два разных `click_trans_id` на один заказ допустимы.
- [ ] **Повторный Complete** выполненной транзакции: мы отвечаем `error: 0` с тем же `merchant_confirm_id` и **не** создаём второй платёж.
      Что ждёт песочница — `0` или `−4`? Записать: ______. Если `−4` — одна строка: `ClickMerchantService::REPEATED_COMPLETE_ERROR = ClickException::ALREADY_PAID`
      (сейчас `ClickException::SUCCESS`), `app/Domain/Billing/Providers/Click/ClickMerchantService.php`.
- [ ] **Complete с `error < 0`** (неудачная/отменённая оплата, например неверный OTP или отмена на странице Click): транзакция state −1 (`cancel_reason = error`),
      ответ −9, **заказ остаётся `pending`** до TTL; повторный Prepare/оплата после неудачи проходит; повтор Complete с `error<0` → −9.
      (DEVOPS §17.3 п. 2 исправлен под docs/13 и код: заказ остаётся `pending` до TTL.)
- [ ] Реально ли Click шлёт Complete с `error<0` при неудачной оплате и укладываются ли его повторы в `CLICK_TRANSACTION_TIMEOUT_MS` (30 мин) — иначе «деньги у Click, Pro не выдан».
- [ ] На наш `error ≠ 0` в ответе Complete (−9/−4 при умершем заказе) Click отменяет списание? (§15; иначе в леджере `failed`-строка, см. DEVOPS §17.5 `billing.click.complete_refused`) — записать: ______.
- [ ] Click повторяет вызов при HTTP 500 + −7? Если нет — отдавать 200 + −7 (§15) — записать: ______.
- [ ] Успешная сквозная оплата: **Payments** `succeeded`, `provider_event_id = <click_trans_id>:complete`, `provider_charge_id = click_trans_id`;
      **Provider transactions** state 2, `merchant_confirm_id = payments.id`; подписка `active`; `/billing/return` — «Оплата получена».
- [ ] Возврат: у Click нет вебхука возврата (подтвердить, что уведомлений нет — §15). Возврат в кабинете Click → **Payments → Refund** с переключателем
      «returned by hand» и `note`: строка `refund`, `provider_event_id = admin-refund:<payment id>`, audit `payment.refunded`. Без переключателя панель отказывает и ничего не меняет.
- [ ] `CLICK_IP_ALLOWLIST`: адреса серверов Click (кабинет/документация) — **уточнить** и внести.

---

## 5. Оплата центров и оферы

Основа: docs/13 §6.5, «Реализация 2B.6», «2B.7», «2B.7a», «2B.8», «2B.9»; ADMIN_GUIDE «Offers», «Organizations».

### 5.1 Оплата тарифа центра (кабинет `/org/<slug>/billing`, только owner)

- [ ] Включён Payme и/или Click, цены заданы (`tutor`, `center_s`, `center_m`; `center_l` онлайн не продаётся — только вручную).
- [ ] Teacher на `/org/<slug>/billing` → 403; чужой slug → 404.
- [ ] **Месяц.** Выбор тарифа и «1 месяц» → «К оплате» = `BILLING_PRICE_<TIER>_MONTH_UZS`; оплата через Payme/Click → подписка организации `active` на 30 дней,
      `seats_limit` = места тарифа, ожидающие участники (FIFO) получили места, Pro у них появился (`/me`, источник «sponsor»).
- [ ] **Год.** «1 год» → цена = месяц × 12 × (1 − `BILLING_ORG_YEAR_DISCOUNT_PERCENT`/100), округление до целых сумов; **скидка по умолчанию 17 %**.
      Пример для проверки: `center_s` по умолчанию 34 900 000 тийин/мес → год = 34 900 000 × 12 × 0,83 = 347 604 000 тийин (3 476 040 сум) — сверить с экраном.
      Строка «Экономия по сравнению с 12 месяцами» показана (только если скидка > 0); счёт печатает «срок: 1 год (365 дн.)». Оплата года продлевает период на 365 дней (поверх живого — от его конца).
- [ ] Явная цена `BILLING_PRICE_<TIER>_YEAR_UZS` приоритетнее скидки; `BILLING_ORG_YEAR_DISCOUNT_PERCENT=0` — год без скидки (проверить и вернуть).
      `center_l` год не продаёт (`tier_not_purchasable`). Stars для центров → `provider_not_supported`.
- [ ] Смена тарифа при живой подписке онлайн запрещена (`tier_change_requires_support`) — только через **Subscriptions → Grant Pro** с `tier`.
- [ ] Сумма открытого заказа не меняется при смене скидки/цены (Payme/Click сверяют сумму заказа).
- [ ] Центр `suspended`: заказ в Payme → `-31054`, в Click → −9; после возврата в `Active` оплата проходит.
- [ ] Возврат оплаты центра убирает ровно оплаченный период (`shortenPeriod`).
- [ ] Напоминание владельцу за 3 и 1 день (`subscriptions:remind-expiring`, 06:00 UTC): письмо (настоящие `MAIL_*`, не `log`) + ЛС при наличии `users.telegram_user_id`.
      Принудительная проверка — **уточнить** (по расписанию; для ускорения можно сдвинуть период в Subscriptions/БД).

### 5.2 Счёт (инвойс) центра

- [ ] `SITE_LEGAL_*` заполнены; `GET /org/<slug>/invoice/<order>` открывается у owner: продавец, плательщик, номер `LF-<id:06>`, тариф/места/срок, сумма в сумах.
      Чужой/learner-заказ → 404; teacher → 403; гость → логин. Заголовки `Cache-Control: no-store, private`, `X-Robots-Tag: noindex`.
- [ ] «Запросить счёт» (при заданном `BILLING_INVOICE_REQUEST_EMAIL` или `SITE_LEGAL_EMAIL`): письмо `OrgInvoiceRequestMail` пришло, `Reply-To` = e-mail владельца; повтор того же выбора в течение 10 минут — `invoice_request_throttled`; audit `organization.invoice_requested`.
      Обе почты пусты — кнопки нет. После ручной оплаты: **Subscriptions → Grant Pro** (организация, `tier`).

### 5.3 Оферы −30 % (мост B2B → B2C)

- [ ] Участник со **занятым** местом уходит из центра (`left`) или удалён (`removed`), личной подписки нет, другого центра с местом нет →
      создаётся одноразовый offer: **−30 %** (`BILLING_POST_ORG_DISCOUNT_PCT`) на первый месяц, действует 14 дней; ЛС бота с кнопками оплаты.
- [ ] Offer не создаётся: ожидающему (`waitlisted`), при личной live-подписке, при Pro из другого центра, при уже открытом offer того же вида (повторный выход/событие).
- [ ] Заказ на месяц применяет скидку на сервере: сумма = `intdiv(list × (100 − pct) + 50, 100)`; оплата **погашает** offer (`redeemed`), истёкший/отменённый заказ его не гасит.
      **Learners → Offers** (admin): состояние `open/redeemed/expired`, центр-источник, заказ; **Annul** → `expired` + audit `offer.annulled`.
- [ ] Два заказа подряд с одним offer: скидка переезжает на новый (`offer_moved`), старый заказ получает `canceled_reason`; в **Payment orders** фильтр **Double discount** пуст.
      При ответе `offer_busy` (409) бот/TWA объясняют без изменения заказов.

---

## 6. Функциональный смоук по волнам

### 6.1 Центры (волна 2B)

- [ ] **Организация** создана в `/admin` (**Billing → Organizations**), группа привязана (**Link group**) либо владельцем в `/org`
      (проверка `getChatMember`: владелец — creator/administrator чата, иначе `not_chat_admin`). Бот — администратор группы.
- [ ] **Кабинет `/org`:** вход owner без 2FA → редирект на setup; owner с 2FA и teacher входят. Пользователь центра A на URL центра B → 404. Суперадмин без строки `organization_users` → 403.
      Бот `/org` (ЛС, у персонала привязан `telegram_user_id`) отвечает местами и активностью, дает URL кабинета.
- [ ] **Teachers:** приглашение по e-mail (письмо со ссылкой), смена роли, привязка групп; нельзя удалить последнего owner (`last_owner`).
- [ ] **Инвайты:** Invites → создать код (нужна `TELEGRAM_BOT_USERNAME`); ссылка `t.me/<бот>?start=…` из второго аккаунта: бот предлагает вступить, после «Вступить» — Pro и ЛС «Pro активен»; без свободных мест → `waitlisted` и ЛС «мест нет»;
      освобождение места → автоматическая посадка из очереди (FIFO) и ЛС «освободилось место». Повторное вступление не растит `uses_count`. 31-й по инвайту Tutor → `waitlisted`, Pro не выдан.
- [ ] **Выход:** `/org`-участник покидает центр (TWA `GET/DELETE /me/organizations`; «Покинуть» только `invite`/`admin`, участнику `group` — `membership_via_group` 409); Pro снимается.
- [ ] **Задания:** Assignments → создать (группа центра, урок курса группы, срок в будущем, порог): сообщение в группу на языке группы; блок «Задания центра» на «Сегодня» у ученика;
      выполнение → результат в списке «X из Y» (после `assignments:refresh` или открытия отчёта); напоминание в группу за 24 ч до срока (`remind_before_hours`); автозакрытие через 14 дней после срока / кнопка «Закрыть». Teacher видит задания только своих групп.
- [ ] **Отчёты:** Reports → фильтры (группа, 7/30/свой период); CSV скачивается (UTF-8 с BOM, разделитель `;`, без Telegram ID); числа совпадают с `learner_daily_stats`; ученики других центров не попадают.
- [ ] **Исполнение расписания:** `php artisan organizations:sync-members` и `assignments:refresh` отрабатывают без ошибок.

### 6.2 Рефералы

- [ ] Бот `/invite` и TWA «Пригласить друга»: ссылка `t.me/<бот>?start=ref_<код>`. Из НОВОГО аккаунта: реферал `pending`. Существующий learner по ссылке → `rejected {existing_learner}`; своя ссылка → `self`; неизвестный код → только событие `referral.rejected {unknown_code}`.
- [ ] Активация: приглашённый учится 3 локальных дня × ≥ 10 оценок в окне 14 дней (для ускорения временно снизить `REFERRALS_ACTIVATION_*`) → `activated` → **обоим +7 дней Pro** (`provider=reward`), события `referral.activated/rewarded`.
- [ ] Лимиты: у пригласившего > `REFERRALS_MAX_REWARDS_PER_30D`/`_LIFETIME` — сторона пригласившего пропущена (`referrer_capped`), приглашённый награду получает; > `REFERRALS_INVITEE_REWARDS_PER_30D` — сторона приглашённого пропущена (`invitee_capped`).
- [ ] Флаг всплеска: ≥ `REFERRALS_FLAG_LINKS_PER_DAY` привязок за 24 ч → следующая флагнута (`referrals.link_burst` в логе), награда ждёт; в Filament (learner → Referrals) «Clear flag» / «Reject».
- [ ] `php artisan referrals:recover` отрабатывает; награда не удваивается.

### 6.3 Заморозки (streak freeze)

- [ ] Free: «заморозки — в Pro», заморозок нет. Pro: +1 заморозка в локальный понедельник до `LEARNERS_STREAK_FREEZE_MAX` (2).
- [ ] Пропуск N дней подряд при ≥ N заморозок: тратится N (по одной на день), серия сохраняется, событие `streak.frozen` на каждую дату. Заморозок меньше N: серия сгорает (`streak_current = 0`), ни одна заморозка не тратится.
      Пополнение в понедельник сгоревшую серию не восстанавливает. `/today.streak` содержит `{freezes, frozen_yesterday}`.
      Сценарий на staging — сдвиг дат в БД/часового пояса learner'а (**уточнить**, какой способ принят).

### 6.4 Лиги

- [ ] Первая оценка локального дня размещает learner'а в группу текущей недели (≤ `LEAGUES_GROUP_CAPACITY`); `GET /api/twa/league`, экран `/league`, строка на «Сегодня». Opt-out в TWA `/settings` («Лиги»).
- [ ] Очки = Σ по `learner_daily_stats` недели (`LEAGUES_POINTS_*`); таблица кэшируется на `LEAGUES_STANDINGS_CACHE_SECONDS`.
- [ ] **Закрытие недели:** `php artisan leagues:close-week` (в обычном режиме — каждую ночь 00:00 по `LEAGUES_TIMEZONE` и страховочный запуск 00:05 той же записью расписания; реально закрывает неделю в ночь на понедельник).
      Все незакрытые прошедшие недели закрываются; повторный запуск ничего не меняет и не шлёт ЛС повторно. Результаты: повышение/понижение по зонам (`LEAGUES_PROMOTE`/`_DEMOTE`), tier следующей недели, ЛС итогов с кнопкой «Открыть лигу» (один на learner'а и неделю), события `league.week_closed`.
      Для проверки без ожидания понедельника — **уточнить** (сдвиг `league_weeks` в БД на staging).
- [ ] Достижение `league_top1` выдано победителю группы с ≥ 1 очком.

### 6.5 Друзья и вызовы

- [ ] Ссылка `f_<referral_code>` из второго аккаунта: бот спрашивает «Отправить X запрос в друзья?», по «Отправить» владелец получает ЛС с кнопками; принятие шлёт отправителю «принял приглашение».
      Новый learner с `f_` получает предложение после онбординга («Добавить в друзья»).
- [ ] Экран «Друзья» (TWA `/friends`): список с серией и выученными словами; удаление из друзей закрывает активные вызовы.
- [ ] Вызов (7 дней по умолчанию): предложение → принятие → старт со следующего локального дня; `challenges:close-due` закрывает по `learner_daily_stats` (оба пояса), ничья → `winner = NULL`, итоговые ЛС обоим по разу. Непринятый > 72 ч → `expired`.
- [ ] Один открытый вызов на пару; лимит друзей `friends.max` (100) — проверка по коду, не вручную.

### 6.6 Достижения (карточка, `sendPhoto`, шеринг)

- [ ] Первая оценённая карточка → `first_session`; цель дня → проверка серий `streak_7/30/100` (по `streak_best`) и слов `words_100/500/1000`; принятая дружба → `first_friend`; первое место лиги → `league_top1`. Один ключ — один раз (повторная оценка ничего не шлёт).
- [ ] Бот присылает **фото-карточку** (`sendPhoto`, 1080×1080 PNG) с подписью на языке learner'а и кнопкой «Поделиться» (URL `https://t.me/share/url?url=<инвайт>&text=<текст>`);
      без `TELEGRAM_BOT_USERNAME` кнопки нет. Кириллица и узбекская латиница (`oʻ gʻ`) на карточке отрисованы правильно, без «квадратиков» (проверить на `ru` и `uz`).
- [ ] TWA `/achievements`: список полученных и прогресс; «Поделиться» открывает диалог (`shareUrl`) и отправляет отметку `shared` (`POST /api/twa/achievements/{key}/shared`).
- [ ] Если рендер не удался (нет GD/шрифта) — job повторяется, отметка в `notifications_log` не пишется; смотреть `failed_jobs` (см. 1.4).
- [ ] Не делалось (не проверять как дефект): `course_completed:*`, превью карточки в TWA, отправка самой картинки из TWA через `shareMessage`/`savePreparedInlineMessage` (S10).

### 6.7 Свои колоды

- [ ] Free: 1 колода × 50 слов; Pro: 20 × 2000 (config). Сверх лимита — 422 (код лимита), не 403.
- [ ] Ввод списком / CSV в TWA (`MyDecksView`, `DeckEditView`), очистка ввода на сервере; колода выбирается как `active_course`, новые слова `daily_review` берутся из неё, дневная квота Free применяется.
- [ ] Удаление колоды: слова `is_active=false`, курс `archived`.

### 6.8 Упражнения

- [ ] Reverse и audio — для Free; spelling и gap-fill — только Pro (Free при прямом запросе получает 422 `exercise_pro_required`).
- [ ] Настройки `exercises {reverse, audio, spelling, gap_fill}` в `PATCH /me/settings` (строгие булевы) отключают типы.
- [ ] Spelling/gap-fill: `GET /next` не отдаёт слово/пример/транскрипцию/аудио; ответ уходит в `POST /review` как `answer`, правильный — в `graded.expected`; `answer` для flash-карточки → 422 `answer_not_accepted`; `quality` для серверных упражнений → `quality_not_accepted`.
- [ ] Карточки с `audio_url` воспроизводят аудио (APP_URL, `/storage/audio/…` отдаёт nginx).
- [ ] Reverse/audio без достаточного числа дистракторов показываются как flash (не ошибка).

### 6.9 FSRS-rollout и A/B

- [ ] `FSRS_ROLLOUT_PERCENT=50` (или иной), `config:cache`: **новые** learner'ы распределяются по бакетам (`learners.scheduler` = `sm2`/`fsrs`); существующие не двигаются. Сервер стартует без ошибок валидации FSRS-параметров (fail-fast при старте).
- [ ] Оценка карточек у learner'а `fsrs`: `word_repetitions.stability/difficulty/scheduler/scheduled_days` заполняются; `training_reviews.scheduler` = `fsrs`; SM-2 поля (EF) не меняются.
- [ ] Filament: **Learners → SRS algorithm** (admin) переключает learner'а, audit `learner.scheduler_changed {from, to, initialized_cards}`; виджет сравнения `SchedulerComparisonWidget` (admin) показывает метрики за 28 дней.
- [ ] Откат: `FSRS_ROLLOUT_PERCENT=0`, при необходимости массовое `scheduler = sm2`; вернуть значение по умолчанию после приёмки.

### 6.10 Симулятор IELTS / SAT vocab (Pro)

- [ ] Курс с `exam_tag ∈ {ielts, sat}`: на странице курса кнопка «Пробный тест». Free → 403 `exam_simulator_pro_required` и шторка paywall `exam_simulator`.
- [ ] Pro: вопросов `min(MOCK_EXAM_QUESTIONS, активных слов)`, минимум 4; один таймер на весь тест (`MOCK_EXAM_SECONDS`); после `ends_at` `answer`/`question` → 410.
- [ ] Не более одного открытого mock на learner'а (параллельный `open` возвращает ту же сессию); результат `GET /result` с процентом и перцентилем (одинокая попытка по курсу = 100); событие `exam.mock_finished` ровно один раз.
- [ ] Брошенный mock закрывается `exams:close-expired`; чужой mock → 403 `forbidden`; групповой экзамен работает как раньше.

---

## 7. Нефункциональные проверки (0.7 / 2R.8)

Скриптов k6, конфигураций ZAP/Lighthouse в репозитории **нет** (проверено по `docs/`, `Makefile`, `.github/workflows/ci.yml`): сценарии нужно написать — **уточнить**,
кто пишет и где хранятся. Команды ниже — стандартные для этих инструментов, не зафиксированные в проекте.

### 7.1 Restore-тест бэкапа (docs/ADMIN_GUIDE «Backups», NFR-08)

- [ ] `php artisan backup:run` (или `make backup`; для только БД — `--only-db`) завершается без ошибок; `backup:monitor` не шлёт писем; архив шифрован `BACKUP_ARCHIVE_PASSWORD`
      (хранить в отдельном секретнице), лежит в `storage/app/private/<APP_NAME>/` либо на S3 при `BACKUP_DISKS=local,s3`.
- [ ] Распаковать ZIP (пароль) на **отдельной** машине/БД, импортировать SQL: `psql -U lexiflow -d lexiflow_restore < dump.sql`.
- [ ] Сверка: `php artisan migrate:status` на восстановленной БД — все `Ran`; число строк ключевых таблиц (`learners`, `payments`, `subscriptions`, `payment_orders`, `provider_transactions`, `organizations`, `referrals`, таблицы лиг) совпадает с источником на момент бэкапа.
      Приложение, направленное на восстановленную БД, проходит `/health/ready` и логин в `/admin`.
- [ ] Бэкап включает таблицы Фазы 2 (платежи, организации, рефералы, лиги, достижения, друзья). Время восстановления: ______ (RTO — **уточнить** целевое значение).
- [ ] Ретеншн: `backup:clean` оставляет 14 дней (NFR-08).

### 7.2 OWASP ZAP baseline (docs/07 §14, docs/10 пен-тест чек-лист)

- [ ] Baseline-скан staging, например `docker run --rm -t ghcr.io/zaproxy/zaproxy:stable zap-baseline.py -t https://<staging>/ -r zap.html` (без аутентификации; для публичных страниц, `/admin/login`, `/org/login`, `/billing/*`, `/twa/`).
      Критерий: нет High; Medium разобраны (критерий допуска — **уточнить**).
- [ ] Ручные пункты пен-теста Фазы 2 (docs/13 §10): вебхук Payme с чужим ключом → `-32504`; `PerformTransaction` дважды → один платёж; Click с неверной подписью → `-1`;
      чужой `GET /billing/orders/{token}` → 404; пользователь организации A на URL организации B → 404; `answer` для flash-карточки → 422; 31-й по инвайту Tutor → `waitlisted`.
- [ ] Подмена `initData` → 401; токен заблокированного learner'а → 401 `learner_blocked` (docs/07 §14).
- [ ] Заголовки: CSP/HSTS на `/admin/login`; страницы `/`, `/offer`, `/privacy`, `/billing/return` без `Set-Cookie`; `/billing/return` — `Cache-Control: no-store`, `noindex`.
- [ ] Сжатие (BREACH): gzip у статики TWA есть, у `/admin/login` и `/2fa/challenge` — нет (DEVOPS §13 п. 8–9).
- [ ] `composer audit` и `cd resources/twa && npm audit` — без critical/high.

### 7.3 Нагрузка k6

Фокус — горячие эндпоинты (docs/11 O4); целевые пороги (p95, RPS, ошибки) в проекте не зафиксированы — **уточнить** у владельца. NFR-03: 1250 активных пользователей; NFR-01: webhook Telegram < 300 мс.
Учесть лимитеры: `twa-review` — 60 запросов в минуту на ключ (learner), поэтому нужно много виртуальных learner'ов с собственными JWT; `billing-webhook` — 120 в минуту на IP (нагрузку с одного IP упрёт в 429);
`twa-session-create`, `twa-auth`, `twa-api` — см. `AppServiceProvider`.

- [ ] Сценарий «оценка карточки»: `POST /api/twa/training/sessions` → `/start` → цикл `GET /next` + `POST /review` (`quality`) на тестовых learner'ах.
- [ ] Сценарий «Сегодня»: `GET /api/twa/today` (включает лигу, задания, заморозки).
- [ ] Сценарий «вебхук Telegram»: `POST /telegram/webhook/{secret}` с заголовком `X-Telegram-Bot-Api-Secret-Token` и фикстурами апдейтов; тяжёлое уходит в очередь, ответ быстрый.
- [ ] Сценарий «Payme/Click RPC»: `POST /billing/webhook/payme` (Basic, фикстуры из `tests/Fixtures/billing/payme/*.json`: Check→Create→Perform) и `POST /billing/webhook/click/prepare|complete`
      (фикстуры `tests/Fixtures/billing/click/*.json`, подпись считается скриптом). Нагрузку гнать по **песочничным** ключам; повторы `Perform` не должны давать второй платёж. Дополнительно `GET /api/twa/billing/catalog` (2R.8).
- [ ] Во время теста: `/health/ready` остаётся 200; backlog очередей `high`/`default` ниже `READINESS_QUEUE_MAX_SIZE` (1000) и рассасывается; `failed_jobs` не растёт; нет 5xx на `/billing/webhook/*`.
- [ ] Глобальный лимит исходящих Telegram: массовый прогон `reminders:dispatch` (много learner'ов) — отправки идут не быстрее `TELEGRAM_OUTBOUND_GLOBAL_PER_SECOND` (25/с),
      job'ы без слота «откладываются» на 1–5 с, ЛС не теряются (2R.3); `reminders:dispatch` печатает `claimed / queued / skipped stale / repaired / backfilled`, клеймит не более `send_rate × 840` за запуск.
      Redis-кэш должен быть общий для всех воркеров (ключ `telegram:outbound:global:{sec}`).

### 7.4 Lighthouse для TWA

- [ ] Lighthouse по `https://<staging>/twa/` (Chrome DevTools / `lighthouse` CLI, мобильный профиль): **Performance ≥ 90** (README, docs/08 Sprint 9). Дополнительно FCP < 1.5 с, LCP < 2.5 с на 4G (NFR-02).
- [ ] Статика TWA отдаётся gzip (`curl -sI -H 'Accept-Encoding: gzip' https://<staging>/twa/assets/<файл>.js | grep -i content-encoding`). Brotli/CDN не сделаны — не дефект.
- [ ] Новые экраны Фазы 2 открываются в Telegram Desktop и на телефоне без ошибок консоли: «Сегодня», `/league`, `/friends`, `/achievements`, рефералы, «Мои колоды», `ExamView` (mock).

### 7.5 Алерты и мониторинг

- [ ] Sentry/GlitchTip (`SENTRY_LARAVEL_DSN`): тестовое исключение попадает в проект; в событиях нет секретов и PII (`SENTRY_SEND_DEFAULT_PII=false`).
- [ ] Внешний uptime-монитор на `/health/ready` (алерт при 2 подряд не-200); алерты на рост `failed_jobs`, `backup:monitor`, срок сертификата < 14 дней, диск > 80 % (DEVOPS §15.3).
- [ ] Лог-сигналы биллинга (DEVOPS §17.5) известны дежурному и на них есть алерт/grep: `billing.amount_mismatch`, `billing.payment_orphan`, `billing.reconcile_missing*`, `billing.reconcile_truncated`, `billing.reconcile_empty`,
      `billing.admin_refund_unrecorded`, `billing.payme.auth_failed`, `billing.payme.rpc_failed`, `billing.click.sign_failed`, `billing.click.complete_refused`, `billing.click.webhook_failed`, `billing.promo_rejected_burst`,
      `organizations.join_burst`, `referrals.link_burst`.
- [ ] Админ-виджеты на дашборде: «Выручка 30 дней» (по валютам), «Ошибки вебхуков 24 ч» (после тестов — исследовать каждую `failed`-строку), «Double Stars subscriptions», плитки лиг/мест.
      Teacher получает 403 на `/admin/payments`.

---

## 8. Переход на production после приёмки (go-live)

- [ ] Боевые ключи/merchant/service id; `PAYME_TEST_KEY` **пуст**, `PAYME_ALLOW_TEST_KEY=false`; `PAYME_CHECKOUT_URL=https://checkout.paycom.uz`; боевая цена `BILLING_PRICE_*`.
- [ ] Адреса провайдеров в `PAYME_IP_ALLOWLIST` / `CLICK_IP_ALLOWLIST` (после проверки real client IP за прокси); trusted proxies настроены.
- [ ] Включать по одному провайдеру: `*_ENABLED=true`, `BILLING_PAYWALL_MODE=purchase`, `config:cache`, рестарт воркеров. После первых платежей — grep `billing.` в логах (нет `amount_mismatch`, `payment_orphan`, `admin_refund_unrecorded`) и grep секретов (пусто).
- [ ] Политика возврата Payme (`PAYME_CANCEL_AFTER_PERFORM_DAYS`) и условия вывода Stars утверждены владельцем.
- [ ] `FSRS_ROLLOUT_PERCENT`, `REFERRALS_*`, `LEAGUES_*` возвращены к целевым значениям (после ускоренных проверок).
- [ ] Юридические тексты `/offer`, `/privacy` заменены на реальные, `security.txt` с реальным контактом (DEVOPS §16) — вне Фазы 2, но блокирует публичный запуск оплаты.

---

## 9. Критерии приёмки и что править при расхождении с песочницей

### 9.1 Критерии

Фаза 2 принята на staging, когда:
- [ ] Разделы 1–6 пройдены без блокирующих расхождений; для каждого провайдера выполнена минимум одна сквозная оплата и один возврат (Stars, Payme, Click).
- [ ] Каждое расхождение с провайдером либо исправлено (таблица 9.2) и прогнан затронутый набор тестов + полный `./vendor/bin/pest`, `pint`, `phpstan` (level 8), либо принято владельцем как допущение с записью в docs/13.
- [ ] Раздел 7: restore-тест выполнен и описан (время, итоги), ZAP — нет High, k6 — пороги достигнуты (после того как они определены), Lighthouse Performance ≥ 90.
- [ ] `failed_jobs` пуст, «Ошибки вебхуков 24 ч» объяснены, в логах нет секретов, `billing:reconcile-stars` → код 0.
- [ ] Найденные расхождения записаны: PROGRESS.md (координатор) и «Сверить …» в docs/13 §15 переведены в «подтверждено» (или исправлены).

Блокирующие: потеря/дубль денег или Pro (двойной платёж, оплата без Pro, Pro без оплаты), утечка секрета, межтенантный доступ в `/org`, неработающий вебхук провайдера.

### 9.2 Таблица «если песочница ждёт иначе — править здесь»

| Расхождение | Правка (одна строка/место) | Файл |
|---|---|---|
| Payme ждёт `-31008`, а не `-31052`, для «заказ уже занят» | Маппинг `ORDER_BUSY` в `createTransaction` и `checkPerformTransaction` | `app/Domain/Billing/Providers/Payme/PaymeRpcService.php` (константы кодов — `PaymeRpcException.php`) |
| Формат checkout-ссылки Payme (`m;ac.order_id;a;l;c`), поддерживаемые `l` | `PaymeProvider::checkoutParams` | `app/Domain/Billing/Providers/PaymeProvider.php` |
| Формат/обязательность `detail` (фискализация) | формирование `detail` в `CheckPerformTransaction` | `app/Domain/Billing/Providers/Payme/PaymeRpcService.php`; `PAYME_FISCAL_*` |
| `GetStatement`: набор полей, фильтр по `time` | `getStatement` | `PaymeRpcService.php` |
| `CheckTransaction` просроченной открытой транзакции должна давать −1 reason 4 | добавить вызов таймаута в `checkTransaction` | `PaymeRpcService.php` |
| Повторный `CancelTransaction` с другим `reason` | логика идемпотентности cancel | `PaymeRpcService.php` |
| Payme не повторяет вызов при HTTP 500 + `-32400` | отдавать 200 и полагаться на ретраи по `-31008` | `app/Http/Controllers/Billing/PaymeWebhookController.php` |
| Click ждёт `−4` на повторный Complete | `REPEATED_COMPLETE_ERROR = ClickException::ALREADY_PAID` | `app/Domain/Billing/Providers/Click/ClickMerchantService.php` |
| Формула подписи Complete | `ClickSignature::digest()` и `Tests\Support\ClickFixtures::sign` | `app/Domain/Billing/Providers/Click/ClickSignature.php`, `tests/Support/ClickFixtures.php` |
| Click не повторяет вызов при HTTP 500 + −7 | отдавать 200 + −7 | `app/Http/Controllers/Billing/ClickWebhookController.php` |
| Параметры checkout-URL Click / формат `amount` | `ClickProvider::createCheckout`, `ClickAmount` | `app/Domain/Billing/Providers/ClickProvider.php`, `Click/ClickAmount.php` |
| Click при `error<0` ведёт себя иначе (не шлёт / шлёт позже таймаута) | `CLICK_TRANSACTION_TIMEOUT_MS`; логика `error<0` в `complete()` | `config/billing.php`, `ClickMerchantService.php` |
| Имена полей `successful_payment` / `refunded_payment` | `parseSuccessfulPayment` / `parseRefundedPayment` (+ фикстуры тестов) | `app/Domain/Billing/Providers/TelegramStarsProvider.php` |
| `editUserStarSubscription` требует charge id последнего списания / не останавливает подписку после возврата | `cancelAutoRenew`; `StarsRenewalStopper` | `app/Domain/Billing/Providers/TelegramStarsProvider.php`, `app/Domain/Billing/Services/StarsRenewalStopper.php` |
| Telegram присылает событие об отмене подписки из настроек Stars | новый обработчик (отдельная задача architect) | — |
| Потолок цены Stars-подписки другой | `BILLING_STARS_MAX_SUBSCRIPTION_PRICE` | `.env` / `config/billing.php` |
| `getStarTransactions`: порядок/страница/поля | `StarsReconciliationService` | `app/Domain/Billing/Providers/StarsReconciliationService.php` |
| Лиги: нужен более быстрый способ проверки закрытия | — (только `leagues:close-week`, идемпотентна) | `routes/console.php`, `app/Console/Commands/CloseLeagueWeek.php` |
| `CDN`/балансировщик: allowlist и throttle видят IP прокси | trusted proxies | `bootstrap/app.php` |

Любое расхождение, не покрытое таблицей, или требующее смены схемы / блокировок / идемпотентности — не править «на глаз»: вернуть координатору для architect
(деньги, конкурентность — CLAUDE.md, «Сложное — к Fable»).

### 9.3 Журнал расхождений (заполняется при приёмке)

| № | Раздел | Ожидали | Увидели | Решение / ссылка на задачу |
|---|---|---|---|---|
| 1 | | | | |
