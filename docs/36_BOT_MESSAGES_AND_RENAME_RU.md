# Сообщения бота: «поделиться» с кнопкой, карточка достижения, кнопки, пробный Pro, название Muhrla

Статус: **проект, код не написан** (25.11.2026). Автор — architect, по запросу владельца от 25.11.2026.
Продукт в бета-режиме, боевых учеников нет. Документ встраивается в выпуски [docs/31](31_COMPETITION_PLAN_RU.md) §2 (см. §6) и опирается на [docs/34](34_TARIFF_AND_BOT_HYGIENE_RU.md): пробный Pro — `BILLING_TRIAL_DAYS` (станет 14), награда за друзей — `REFERRALS_MILESTONE_DAYS` (7 дней за 3-го и 6-го засчитанного друга), цепочка предложений после пробного периода — задачи P0–P8 docs/34; блокировки бота и 429 — задачи B0–B9 docs/34. Здесь их не повторяем.

| № | Тема | Главное по коду | Что делаем |
|---|---|---|---|
| 1 | «Поделиться» с кнопкой | Все 6 мест «поделиться» (4 в Mini App, 2 в боте) открывают `t.me/share/url`: ученик отправляет **обычный текст со ссылкой**, кнопку к нему добавить нельзя | Сообщение «через бота»: Mini App — подготовленное сообщение (`savePreparedInlineMessage` + `WebApp.shareMessage`), бот — inline-режим. Кнопка «🚀 Начать изучение» ведёт на ту же ссылку `?start=ref_<код>`. Старые клиенты — нынешняя ссылка |
| 2 | Картинка достижения | GD + Noto Sans, 1080×1080 PNG, градиент по «семье», бренд — строка `achievements.card.brand` в lang. Файлы на диске `public`, но nginx наружу отдаёт только `/storage/audio/` | Новый макет в цветах Mini App, шрифт заголовка Source Serif 4 (как в макете), бренд из конфига, JPEG и публичный адрес (нужен для «поделиться»), снимок-тест |
| 3 | Кнопки в сообщениях | ~20 видов сообщений ученику; кнопки есть примерно у половины. Адрес Mini App собирается в **19 местах** вручную (`config('twa.base_url')`). Принятый вызов друга — без кнопки, отдельного экрана вызова нет | Единый построитель кнопок и экранов, правило «одна главная кнопка», перечень в §3а |
| 4 | Сообщение о пробном Pro | **Уже есть** (`TrialWelcomeMessenger`, `billing.trial.welcome`): одна строка без списка плюсов и без кнопки. Ученик, пришедший только в Mini App, его не получает | Расширить текст (срок, дата, 3–4 плюса из `ProPromises`), кнопка там, где нет соседней, отдельный текст награды за друзей |
| 5 | Название Muhrla | «LexiFlow» видят ученики в ~175 строках lang, 14 строках TWA, на сайте, в панелях, на кнопке меню бота и на карточке. Остальное (≈1 100 вхождений) — код, инфраструктура, docs, тесты | Вопрос владельцу (§5г). Рекомендация: сейчас переименовать всё видимое ученику, бренд — одним ключом `app.brand`; `APP_NAME` и технические имена не трогать |

---

## 1. Тема 1 — «поделиться» с кнопкой

### 1а. Как сейчас по коду

| Где | Что делится | Ссылка | Текст | Как открывается |
|---|---|---|---|---|
| Mini App, `AchievementsView.vue` → `share()` | достижение | `share_link` из `GET /achievements` = `ReferralService::inviteLink()` → `https://t.me/<bot>?start=ref_<код>` | `share_text` из `AchievementsResource` (`achievements.share_text`) | `useTelegram().shareUrl()` → `openTelegramLink('https://t.me/share/url?url=…&text=…')`; потом `POST /achievements/{key}/shared` |
| Mini App, `ReferralsView.vue` → `share()` | приглашение (реферал) | `data.link` (тот же `ref_`) | TWA i18n `referrals.share_text` (числа пробного срока нет) | `shareUrl()` |
| Mini App, `FriendsView.vue` → `share()` | «давай дружить» | `friends.invite_link` → `?start=f_<код>` | TWA i18n `friends.invite.share_text` | `shareUrl()` |
| Mini App, `GameRunView.vue` → `onShare()` (`CityCard.vue`) | карточка города «Каравана» после первой победы над боссом | `summary.boss.share.link` (`GameCardSender::share()` → `ref_`) | `game.card.share_text` | `shareUrl()`, потом `POST /game/cities/{stage}/shared` |
| Бот, `AchievementSender` | достижение: фото + подпись + URL-кнопка «Поделиться» | `ref_` | `achievements.share_text` | кнопка-ссылка на `t.me/share/url` (`AchievementSender::shareUrl()`) |
| Бот, `GameCardSender` | карточка города: фото + URL-кнопка | `ref_` | `game.card.share_text` | то же |
| Бот, `/invite` (`InviteCommandHandler`) | приглашение | `ref_` | `referrals.invite.share_text` (с числом дней пробного) | кнопка-ссылка на `t.me/share/url` |

Итоги лиги, победа в месяце, вызов другу и баттл сейчас **не делятся** наружу вообще.

Пример владельца — это ровно `t.me/share/url`: Telegram вставляет ссылку первой строкой, дальше текст `achievements.share_text` (uz). Кнопки нет и быть не может: такое сообщение отправляет сам ученик, а не бот.

Что ещё важно:

- `IrazasyedTelegramClient::ALLOWED_UPDATES` = `message, my_chat_member, chat_member, callback_query, pre_checkout_query` — **`inline_query` бот не получает**, inline-режим не используется.
- Переход по `?start=ref_<код>` засчитывает реферала в `StartCommandHandler` (`StartPayload::referralCode()` → `ReferralService`). Mini App `start_param` для учёта рефералов **не читает** (только для перехода на экран — `router/startParam.ts`). Значит кнопка должна вести на `?start=ref_…`, а не на `?startapp=…`.
- Mini App подключает `@twa-dev/sdk` **7.10.1**. Его собственная копия `telegram-web-apps.js` (версия 7.10) при импорте **перезаписывает** `window.Telegram.WebApp`, загруженный из `telegram.org/js/telegram-web-app.js` в `index.html`. Метода `shareMessage` (Mini Apps 8.0) в этой копии нет — его нужно добавить (см. §1б, H6).
- Карточки лежат на диске `public` (`storage/app/public/achievements/…png`), но в `docker/nginx/prod.conf` наружу открыт только `location ^~ /storage/audio/` (`storage:link` не делается). Для inline-сообщения с фото Telegram должен **скачать картинку по HTTPS** — нужен публичный адрес.

### 1б. Ограничения Telegram

**Сверить с core.telegram.org/bots/api (разделы `savePreparedInlineMessage`, `InlineQueryResultPhoto`, `answerInlineQuery`, `SwitchInlineQueryChosenChat`) и core.telegram.org/bots/webapps (`shareMessage`) и проверить на живом боте до реализации H3.** Ниже — по памяти.

| Вопрос | Что известно | Что сверить |
|---|---|---|
| Кнопка к `t.me/share/url` | Невозможна: это текст от имени ученика | — |
| Сообщение «через бота» | Только inline: ответ на inline-запрос (`answerInlineQuery`) или подготовленное сообщение (`savePreparedInlineMessage`, Bot API 8.0, ноябрь 2024). Сообщение подписано «via @bot», у него может быть `reply_markup` с inline-кнопками | — |
| Какие кнопки можно | `url`, `callback_data`, `switch_inline_query*`. **`web_app` нельзя** — такие кнопки разрешены только в личном чате с ботом. Поэтому кнопка — URL `https://t.me/<bot>?start=ref_<код>` | Список типов кнопок в `InlineQueryResult*.reply_markup` |
| `savePreparedInlineMessage` | Параметры: `user_id`, `result` (один `InlineQueryResult`), `allow_user_chats`, `allow_bot_chats`, `allow_group_chats`, `allow_channel_chats`. Ответ: `PreparedInlineMessage {id, expiration_date}` | **Срок жизни** (берём из `expiration_date`, не зашиваем); нужен ли включённый inline-режим у BotFather (по памяти — нет явного требования, но включаем всё равно, см. ниже) |
| `WebApp.shareMessage(id, callback)` | Mini Apps 8.0. Открывает выбор чата, `callback(true)` — отправлено. Под капотом — событие `web_app_send_prepared_message {id}`, ответы `prepared_message_sent` / `prepared_message_failed` | Нужен ли «жест пользователя» (вызывать только по нажатию — так и делаем) |
| Фото в inline | `InlineQueryResultPhoto`: `photo_url` — **JPEG**, до 5 МБ, обязательный `thumbnail_url`, `caption` до 1024 символов. Telegram скачивает сам — адрес должен открываться без cookie и без проверки Cloudflare | Ограничение «только JPEG»; доступность с серверов Telegram через Cloudflare (docs/23) |
| Inline-режим | Включается у BotFather (`/setinline`, текст-подсказка). После включения **любой** пользователь может набрать `@bot` в любом чате — бот обязан отвечать на `inline_query` (иначе крутится индикатор) | — |
| Кнопка «поделиться» из сообщения бота | `switch_inline_query_chosen_chat` (Bot API 6.7, 2023): ученик выбирает чат, Telegram вставляет `@bot <запрос>`, бот отвечает результатом, ученик нажимает на него | Поведение на iOS / Android / Desktop / Web; `cache_time = 0`, `is_personal = true` |
| Старые клиенты | `shareMessage` есть с версии Mini Apps 8.0 (клиенты конца 2024). Проверка — `WebApp.isVersionAtLeast('8.0')` (версия берётся из `tgWebAppVersion`, это работает и в копии SDK 7.10) | Доля клиентов < 8.0 у наших учеников (событие в аналитике, H7) |
| Telegram Web / Desktop | Поддержка `shareMessage` в веб-клиентах A/K могла появиться позже | Проверить руками |

### 1в. Решение

**Одно ядро — два способа доставки.**

1. **`App\Domain\Engagement\Services\ShareMessageBuilder`** (ядро) строит содержимое сообщения «через бота» по виду и предмету:

```php
enum ShareKind: string {
    case Achievement = 'achievement';   // фото-карточка достижения, предмет — ключ достижения
    case Invite = 'invite';             // приглашение (реферал), без предмета; фото — общая карточка бренда (A4)
    case FriendInvite = 'friend_invite';// «давай дружить», ссылка f_<код>
    case GameCity = 'game_city';        // карточка города, предмет — stage_id
    case LeagueWeek = 'league_week';    // итог недели (место / ярус), предмет — id строки участника; позже, N-задачи
    case MonthPlace = 'month_place';    // место в рейтинге месяца 1–3, предмет — id месяца; после A5
}

/** @return array<string, mixed> готовый InlineQueryResultPhoto|InlineQueryResultArticle (id, photo_url, thumbnail_url, caption, reply_markup) */
public function build(Learner $learner, ShareKind $kind, ?string $subject, string $locale): array; // ShareException: not_found | unavailable
```

   - Предмет ищется **только среди строк самого ученика**: открытое им достижение (`achievements.learner_id`), достигнутый им город, его строка лиги. Чужой ключ — `not_found`, как будто его нет.
   - Текст — только из `lang` сервера (`share.*`), **никакого текста от клиента**: иначе бот стал бы рассылать произвольный текст от своего имени.
   - Подпись: `share.caption.<kind>` (без ссылки — ссылка в кнопке; имя ученика не пишем). Кнопка одна: `share.button.start` → `https://t.me/<bot>?start=ref_<код>` (для `friend_invite` — `share.button.add_friend` → `?start=f_<код>`).
   - Фото: публичный JPEG-адрес карточки из A2 (`AchievementCardRenderer::publicUrl()`), `thumbnail_url` — тот же адрес. Если адреса нет (диск не публичный, бот без имени) — `InlineQueryResultArticle` с тем же текстом и кнопкой.

2. **Mini App: подготовленное сообщение.**
   - `POST /api/twa/share/prepare` `{kind, subject?}` → `ShareController` → `SharePreparer::prepare($learner, $kind, $subject)` → `TelegramClient::savePreparedInlineMessage(userId, result, allowUser: true, allowBot: false, allowGroup: true, allowChannel: true)` → `ShareMessageResource` `{id, expires_at}`.
   - `user_id` — **только** `telegram_user_id` ученика из JWT. Ученик с `blocked_at` → 403 `share_unavailable`.
   - Кэш Redis (docs/19 §3): ключ `share:prep:v1:{learner}:{kind}:{subject|-}:{locale}:{hash содержимого}`, значение — массив `{id, expires_at}`, TTL = `expiration_date − now − 5 мин`, но не больше 1 ч. Повторное нажатие не дёргает Bot API. Инвалидация не нужна: смена текста или карточки меняет хэш в ключе.
   - Лимит: новый `RateLimiter::for('twa-share')` — 20 в минуту и 100 в сутки на ученика (`AppServiceProvider`, рядом с `twa-friends`). 429 — клиент уходит в запасной путь.
   - Ошибки: 404 `share_subject_not_found`, 403 `share_unavailable` (бот без имени, ученик заблокирован, `share.enabled = false`), 503 `share_failed` (Bot API отказал) — клиент во всех трёх случаях открывает **нынешний** `t.me/share/url`.
   - Клиент: новая функция `useTelegram().shareMessage(id): Promise<'sent' | 'cancelled' | 'unsupported'>` и composable `useShare(kind, subject, fallback: {link, text})`: если `isVersionAtLeast('8.0')` и есть способ вызвать `shareMessage` → prepare → shareMessage; иначе — `shareUrl(link, text)`. Отметка «поделился» (`/achievements/{key}/shared`, `/game/cities/{stage}/shared`) — после `'sent'`, а в запасном пути — как сейчас, при открытии диалога.
   - Поля `share_link` / `share_text` в ресурсах **оставляем**: это запасной путь.

3. **Бот: inline-режим.**
   - Включить inline у BotFather (подсказка: «поделиться достижением»). `inline_query` добавить в `ALLOWED_UPDATES`, перерегистрировать вебхук (`telegram:setup-bot`).
   - `InlineQueryHandler` (`app/Domain/Telegram/Handlers/`): ученик ищется по `from.id`; запрос разбирается строго по шаблону `^(a|c|i|f):([a-z0-9_]{1,40})?$` (`a:<ключ>` — достижение, `c:<stage_id>` — город, `i:` — приглашение, `f:` — дружба). Любой другой запрос, пустой запрос, незарегистрированный человек → один результат «Приглашение» (для незнакомца — без реферального кода, ссылка `t.me/<bot>`). Ответ — `answerInlineQuery(results, cache_time: 0, is_personal: true)`. Ответ всегда быстрый: карточка уже нарисована, иначе отдаём `Article`.
   - Кнопка «Поделиться» в `AchievementSender` и `GameCardSender` меняется с URL `t.me/share/url` на `switch_inline_query_chosen_chat {query: "a:<ключ>", allow_user_chats, allow_group_chats, allow_channel_chats}`; в `/invite` — `{query: "i:"}`. Пока inline-режим выключен флагом `share.inline_enabled = false` — остаются нынешние URL-кнопки.
   - Inline-запрос — вход без подписи initData, но Telegram сам подписывает обновление вебхука секретом (`X-Telegram-Bot-Api-Secret-Token` уже проверяется в `TelegramDispatcher`). `from.id` доверенный. Лимит: 30 inline-запросов в минуту на `from.id` (Redis), сверх — пустой ответ.

4. **Учёт реферала** не меняется: кнопка ведёт на `?start=ref_<код>` → `/start` → `StartCommandHandler` → `ReferralService` (первый контакт побеждает). Для аналитики: продуктовые события `share.prepared {kind, via: mini_app|inline}` и `share.sent {kind}` (из отметки «поделился»); источник реферала по-прежнему `ref` — новый формат `start`-параметра не вводим, чтобы не трогать `StartPayload`.

5. **Тексты кнопок** (`lang/{ru,uz}/share.php`, новый файл; uz — на вычитку в docs/I18N_UZ_REVIEW.md):

| Ключ | ru | uz |
|---|---|---|
| `share.button.start` | 🚀 Начать изучение | 🚀 O'rganishni boshlash |
| `share.button.add_friend` | 🤝 Добавить в друзья | 🤝 Do'st bo'lish |
| `share.caption.achievement` | 🏆 Я получил(а) достижение «:title» в :brand! Учим английский прямо в Telegram. | 🏆 Men :brand'da «:title» yutug'ini oldim! Telegramda ingliz tilini o'rganamiz. |
| `share.caption.invite` | Учу английский в :brand — присоединяйся, первые :trial Pro бесплатно! | :brand'da ingliz tilini o'rganyapman — qo'shiling, birinchi :trial Pro bepul! |
| `share.caption.friend_invite` | Давай дружить в :brand: сравним серии и устроим вызов! | :brand'da do'st bo'laylik: seriyalarni solishtiramiz va musobaqa qilamiz! |
| `share.caption.game_city` | Я дошёл(дошла) до города :city в игре «Караван» в :brand! | (как `game.card.share_text`) |
| `share.inline.invite_title` | Пригласить в :brand | :brand'ga taklif qilish |

`:brand` — из `config('app.brand')` (§5), подставляет `ShareMessageBuilder`.

**Отвергнуто:**
- Оставить `t.me/share/url` и дописать ссылку в текст — так и сейчас; кнопки не будет.
- Кнопка `?startapp=ref_<код>` (сразу Mini App) — реферал не засчитается: Mini App не учитывает `start_param`, а онбординг идёт в боте. Можно позже, отдельной задачей с учётом в `/auth`.
- Только inline (`switchInlineQuery` из Mini App) — ученику нужно ещё раз выбрать результат в списке, и это хуже, чем `shareMessage`. Оставляем для бота, где другого пути нет.
- Пересылка сообщения бота (`forward`) — копирует фото и кнопки, но с плашкой «переслано от бота». Ученик может так делать и сейчас, подпись это уже предлагает.

### 1г. Задачи

| № | Задача | Кто | Зависит от | Definition of Done и ключевые тесты |
|---|---|---|---|---|
| H0 | Docs до кода: docs/04 (маршрут `POST /share/prepare`, коды ошибок, `inline_query`), docs/05 (поделиться в Mini App), docs/13 §7.4 (кнопка в сообщении), docs/07 (inline-вход, лимиты), docs/23 (Cloudflare пропускает `/storage/share-cards/`) | координатор | — | docs согласованы |
| H1 | Проверка на живом стенд-боте (вручную, без кода в репозитории): `savePreparedInlineMessage` с фото по URL и кнопкой `?start=ref_`, `shareMessage` на iOS / Android / Desktop / Web, срок `expiration_date`, нужен ли inline у BotFather, `switch_inline_query_chosen_chat` | владелец / devops + architect | — | Таблица «клиент → работает / нет» в PROGRESS.md; сверены пункты таблицы §1б |
| H2 | `TelegramClient::savePreparedInlineMessage()` и `answerInlineQuery()` (через `$this->api->post()`, как `setChatMenuButton`), фейк в тестах | coder | H0 | Unit: параметры запроса (JSON `result`, флаги чатов), разбор `{id, expiration_date}`, ошибки → `TelegramApiException` без токена |
| H3 | **Ядро** `ShareKind`, `ShareMessageBuilder`, `SharePreparer` (кэш, лимит, события), `ShareException` | **architect** | H2, A2 | Чужое достижение / неоткрытое / чужой город → `not_found`; текст только из lang; кнопка = `ReferralService::inviteLink()` ученика; без фото-адреса → `Article`; кэш: второй вызов без Bot API, другой язык — другой ключ, истёкший — новый вызов; 503 не кэшируется |
| H4 | Маршрут `POST /api/twa/share/prepare`, `SharePrepareRequest`, `ShareController`, `ShareMessageResource`, лимитер `twa-share` | coder | H3 | Feature: 200, 401, 403 (`blocked_at`, бот без имени), 404, 422 (неизвестный `kind`), 429; `user_id` в запросе к Bot API — из JWT |
| H5 | Inline: `inline_query` в `ALLOWED_UPDATES`, `InlineQueryHandler` в диспетчере, лимит 30/мин, флаг `share.inline_enabled`; кнопки `switch_inline_query_chosen_chat` в `AchievementSender`, `GameCardSender`, `/invite` при включённом флаге | coder (ревью architect) | H3 | Разбор запроса (мусор → приглашение без кода для незнакомца); чужой ключ не раскрывается; `cache_time 0`, `is_personal`; флаг выключен → кнопки как сейчас (существующие тесты не меняются) |
| H6 | Mini App: `shareMessage` в `useTelegram` (обновление `@twa-dev/sdk` до 8.x **по согласованию** или вызов события `web_app_send_prepared_message` через `window.Telegram.WebView`), `useShare`, перевод `AchievementsView`, `ReferralsView`, `FriendsView`, `GameRunView` | coder | H4 | vitest: версия 8.0+ → prepare + shareMessage, отметка после `sent`; < 8.0, 403/404/429/503 → `shareUrl` с прежними `link`/`text`; отмена — без отметки |
| H7 | Аналитика: `share.prepared`, `share.sent`, `share.fallback {reason: old_client|error}` | coder | H4, H6 | Тест события; строка в отчёте вовлечённости `/admin` (по желанию) |
| H8 | Включение на проде: inline у BotFather, `telegram:setup-bot`, `share.inline_enabled = true` | владелец / devops | H5, H1 | Ученик из бота делится достижением в группу — под фото кнопка, по ней новичок попадает в бот с `ref_` и засчитывается |

### 1д. Вопросы владельцу

- **В1.1.** Текст кнопки: «🚀 Начать изучение» / «🚀 O'rganishni boshlash»? *Рекомендация:* да; для приглашения в друзья — «🤝 Добавить в друзья».
- **В1.2.** Включаем inline-режим бота (после включения `@имя_бота` в любом чате будет показывать «Пригласить в Muhrla»)? *Рекомендация:* да, это единственный способ дать кнопку «Поделиться» прямо в сообщении бота.

### 1е. Риски

- **Картинка не скачивается Telegram** (Cloudflare, nginx, не JPEG) — сообщение уйдёт без фото или не уйдёт совсем. Защита: `Article` как запасной вариант в H3 и проверка H1.
- **Старый SDK перетирает `window.Telegram.WebApp`.** Без H6 `shareMessage` не будет никогда, даже в новых клиентах.
- **Спам от имени бота.** Закрыт тем, что текст только серверный, предмет только свой, лимиты на prepare и inline.
- **Срок подготовленного сообщения.** Если он короткий (минуты), кэш H3 должен его учитывать — TTL берём из ответа, а не из конфига.

---

## 2. Тема 2 — картинка достижения

### 2а. Как сейчас по коду

- `AchievementCardRenderer` (`app/Domain/Engagement/Services/`): **GD + FreeType**, 1080×1080 **PNG**. Вертикальный градиент по «семье» (`PALETTES`: streak — оранжево-бордовый, words — сине-фиолетовый, league, friend, session, game), белый круг-«медаль» с эмодзи/текстом `badge`, заголовок 66 pt, описание 40 pt, внизу `achievements.card.brand` («LexiFlow») и `achievements.card.tagline`. Перенос строк — жадный по ширине.
- Шрифты: `resources/fonts/NotoSans-Regular.ttf` и `-Bold.ttf` (SIL OFL 1.1, `OFL.txt` рядом), пути в `config/achievements.php`. Покрывают латиницу с узбекскими буквами и кириллицу.
- Кэш: файл `achievements/<ключ>-<язык>-<хэш>.png` на диске `public`, хэш от `TEMPLATE_VERSION` ('1'), ключа, языка, значка, текстов, бренда и подписи. Запись через временный файл + переименование. Личных данных на картинке нет.
- Тот же рисовальщик рисует карточку города игры (`renderSpec(CardSpec)`, `GameCardSender`).
- Отправка: `AchievementSender` → `sendPhoto(локальный файл)`; маркер `notifications_log` до отправки.
- Тесты: `tests/Feature/Engagement/AchievementCardRendererTest.php` (размер, разные ru/uz, «не плоский градиент», кэш, новый файл при смене текста, ошибки шрифта, `CardSpec`), `AchievementSenderTest.php`.
- **Цвета карточки не совпадают с новым стилем**: Mini App перешла на синие токены (`resources/twa/src/style.css`: `--app-accent #2854a8`, `--app-primary #234fb1`, `--app-accent-soft #edf3ff`, `--app-accent-line #b9ccef`, `--app-text #1d2130`, `--app-muted #5f6677`), а макет (`docs/design/2026-11-redesign/*.html`) — заголовки **Source Serif 4**, моноширинный IBM Plex Mono, иллюстрации-гравюры синим контуром на мягком синем фоне.
- Шрифты макета в репозитории есть **только в WOFF2** (`resources/twa/src/assets/fonts/`, `resources/fonts/panel/`), лицензия SIL OFL 1.1 (файлы `OFL-*.txt`). GD читает TTF/OTF; WOFF2 — только если FreeType собран с brotli, на это полагаться нельзя.

### 2б. Решение — новый макет карточки

**Размер** остаётся 1080×1080 (так карточка одинаково выглядит в чате, в inline-результате и в превью). **Формат — JPEG** (качество 90): `InlineQueryResultPhoto` требует JPEG, `sendPhoto` его тоже принимает, Telegram всё равно пережимает. Один файл на оба пути.

**Композиция (сверху вниз):**

| Зона | Что | Цвет / шрифт |
|---|---|---|
| Фон | Сплошной `#edf3ff` (`--app-accent-soft`), без градиента | — |
| Карточка | Белая (`#ffffff`) скруглённая панель 960×960, отступ 60, рамка 3 px `#b9ccef` (`--app-accent-line`), радиус 48 | — |
| Шапка (y≈130) | Знак бренда (если задан файл логотипа) + название бренда **из `config('app.brand')`** | Source Serif 4 Semibold 44, `#234fb1` |
| Иллюстрация (центр, 480×420) | Гравюра синим контуром по «семье» достижения: `resources/images/cards/<family>.png` (прозрачный PNG, рисует дизайнер, 6 штук + `invite`, `month`). Нет файла → нынешняя «медаль»: круг `#edf3ff` с рамкой `#b9ccef` и значком `badge` цвета `#2854a8` | контур `#2854a8` |
| Метка | «ДОСТИЖЕНИЕ» / «YUTUQ» (`achievements.card.label`), разрядка | Noto Sans Bold 28, `#5f6677` |
| Заголовок | Название достижения, до 2 строк | Source Serif 4 Bold 68, `#1d2130` |
| Описание | До 3 строк | Noto Sans Regular 38, `#5f6677` |
| Подвал (у нижнего края панели) | Слева подпись `achievements.card.tagline` («Учим английский в Telegram»), справа `t.me/<bot>` (из `telegram.bot_username`; пересланная без кнопки картинка всё равно приведёт к боту) | Noto Sans 28, `#5f6677` / `#2854a8` |

- **Цвета — константы в одном месте** (`CardPalette` в `App\Domain\Engagement\Support`), с комментарием «копия токенов `style.css`». Тест сверяет их с `style.css` (читает файл и ищет `--app-accent: #2854a8` и т. д.), чтобы смена токенов не прошла молча.
- **Шрифты.** Заголовки и бренд — Source Serif 4 (как в макете): нужно положить `SourceSerif4-Semibold.ttf` и `SourceSerif4-Bold.ttf` (Adobe, SIL OFL 1.1, официальный релиз на GitHub, ~0,5 МБ каждый) в `resources/fonts/` с `OFL-SourceSerif4.txt`. Это файл шрифта, а не пакет, но добавление **согласовать с координатором**. Текст — Noto Sans (уже есть). Тест: в обоих шрифтах есть глифы всех символов текстов ru/uz из `lang/*/achievements.php` и `game.php` (включая `ʻ` U+02BB и `’`, если они появятся в узбекских текстах; сравнение с шириной `.notdef`).
- **Бренд — из конфига**, а не строкой в lang: `config('app.brand')` (§5б). `achievements.card.brand` удаляется. Логотип — `config('app.brand_logo')` (путь к PNG, по умолчанию `null` → только текст).
- **Тёмный вариант не нужен.** Картинка в чате не меняется с темой Telegram; светлая карточка на мягком синем фоне хорошо видна и в тёмной теме. Это ещё и вдвое меньше файлов в кэше.
- `TEMPLATE_VERSION` → `'2'` (старые PNG не используются). Старые файлы удаляет команда `achievements:prune-cards` (файлы старше 30 дней, не подходящие под текущую версию), раз в неделю.
- **Публичный адрес.** Карточки переезжают в каталог `share-cards/` на диске `public` (`config('achievements.directory')`). В `docker/nginx/prod.conf` (и `coolify.conf`, `default.conf`) — `location ^~ /storage/share-cards/` по образцу `/storage/audio/`: только чтение, `Cache-Control: public, max-age=31536000, immutable` (хэш в имени). `AchievementCardRenderer::publicUrl(string $path): ?string` — `null`, если диск не `public` или `APP_URL` не https.

**Для каких ещё сообщений нужна картинка:**

| Сообщение | Картинка | Когда |
|---|---|---|
| Достижение (в т. ч. «Победитель лиги» — это достижение `league_top1`) | да, этот макет | A3 |
| Карточка города «Каравана» | да, тот же макет (`CardSpec`, семья `game`) | A3, бесплатно |
| Приглашение (share `invite`) | да, «карточка бренда»: иллюстрация `invite`, заголовок «Учим английский в Telegram», описание «Первые :trial Pro бесплатно» | A4 |
| Победитель месяца, места 1–3 (`MonthWinnerSender`) | да: заголовок «:place место в рейтинге за :month», иллюстрация `month`, без имени ученика (имя не рисуем — картинку пересылают) | A5 |
| Итог недели (повышение яруса) | нет, хватит текста с кнопкой. Первое место уже даёт достижение с карточкой | — |
| Кубок группы (docs/33) | позже, в выпуске 5, тем же `renderSpec` | вне этого документа |

**Как проверять — снимок-тест.** `tests/Feature/Engagement/AchievementCardSnapshotTest.php`: рисует фиксированный `CardSpec` (ru и uz, длинный заголовок на 2 строки), сравнивает с эталоном `tests/fixtures/cards/*.jpg` попиксельно с допуском (средняя разница каналов ≤ 2 %, доля сильно отличающихся пикселей ≤ 1 %): версии GD/FreeType в контейнере и в CI могут чуть по-разному сглаживать. Эталон пересоздаётся `UPDATE_CARD_SNAPSHOTS=1 php artisan test --filter=Snapshot`, и разница обязательно смотрится глазами на ревью. Плюс точечные проверки: пиксель фона = `#edf3ff`, пиксель панели = белый, смена `app.brand` даёт другой файл.

**Отвергнуто:** SVG → растр (нужен Imagick или rsvg — новая зависимость); HTML → картинка через headless-браузер (тяжело и новая зависимость); оставить градиенты (не совпадает с новым стилем, о чём и просит владелец).

### 2в. Задачи

| № | Задача | Кто | Зависит от | Definition of Done и ключевые тесты |
|---|---|---|---|---|
| A0 | Docs: docs/13 §7.4 (новый макет, JPEG, публичный адрес), docs/18 §12.3.4 (карточка города), DEVOPS_GUIDE (nginx `share-cards`) | координатор | M1 | docs согласованы |
| A1 | Иллюстрации-гравюры: 6 семей + `invite` + `month`, прозрачный PNG 960×840 (рисуется в 2×), контур `#2854a8`; шрифты Source Serif 4 TTF с лицензией | дизайнер / владелец; шрифты — coder после согласования | — | Файлы в `resources/images/cards/`, `resources/fonts/`; лицензии рядом |
| A2 | Рисовальщик v2: `CardPalette`, макет §2б, JPEG, каталог `share-cards`, `publicUrl()`, бренд и `t.me/<bot>` из конфига, без иллюстрации — «медаль»; nginx-location; `achievements:prune-cards` | coder (ревью architect) | A0, M2 | Существующие тесты переведены на JPEG; снимок-тест ru/uz; тест глифов; тест сверки цветов со `style.css`; запрос к `/storage/share-cards/…` отдаёт файл, к `/storage/achievements/…` — 404 |
| A3 | Подключение иллюстраций, проверка карточки города и всех ключей достижений на длину (2 строки заголовка, 3 строки описания) | coder | A1, A2 | Тест: все ключи × ru/uz укладываются в строки; снимки обновлены |
| A4 | Карточка бренда для `invite` (`CardSpec`) | coder | A2 | Снимок-тест |
| A5 | Картинка победителя месяца: `MonthWinnerSender` шлёт фото с подписью вместо текста (место, месяц, без имени); кнопка — N-задача | coder | A2 | `MonthWinnerSenderTest`: фото, маркер, 403 как сейчас |

### 2г. Вопросы владельцу

- **В2.1.** Иллюстрации-гравюры для карточек рисует ваш дизайнер (8 штук), а до их появления карточки выходят с «медалью» в новых цветах? *Рекомендация:* да — код не ждёт картинок.
- **В2.2.** На картинке писать адрес бота (`t.me/имя_бота`)? *Рекомендация:* да: картинку часто пересылают без кнопки.

### 2д. Риски

- Снимок-тест «плавает» между окружениями — поэтому допуск, а не точное совпадение; эталон смотреть глазами.
- Публичный каталог: на карточках нет личных данных (правило сохраняется: имя ученика не рисуем никогда), имена файлов — хэш, перечисление каталога выключено (`autoindex off`).

---

## 3. Тема 3 — кнопки в сообщениях бота

### 3а. Как сейчас по коду и что нужно

Обозначения: **web_app** — кнопка открывает Mini App на экране (в личном чате; в группе `sendWebAppButton` сам превращает её в URL `t.me/<bot>?startapp=<base64 пути>`, `MiniAppLinks`); **cb** — `callback_data`; **url** — ссылка.

| Сообщение (ключ lang) | Кто отправляет | Кнопка сейчас | Нужна | Куда |
|---|---|---|---|---|
| Напоминания `reminders.review / streak_at_risk / inactive / inactive_due / new_words` | `ReminderSender` | web_app «Открыть LexiFlow» → `/twa/?from=reminder` | «▶️ Начать занятие» | `/?from=reminder` (как сейчас) |
| `reminders.never_started` | `ReminderSender` | то же | «▶️ Первое занятие» | `/?from=reminder` |
| `reminders.choose_course` | `ReminderSender` | то же | «📚 Выбрать курс» | `/courses?from=reminder` |
| Напоминание-викторина `reminders.quiz.*` | `ReminderQuizMessenger` | cb варианты ответа | оставить | — |
| Итог недели `leagues.result.*` | `LeagueResultSender` | web_app «Открыть лигу» → `/twa/league` | оставить, добавить `?from=league_result` | `/league` |
| Победа в месяце `leagues.month.won / won_plain` | `MonthWinnerSender` | **нет** | «🏆 Рейтинг месяца» | `/league/month?from=month_won` |
| Награда за друзей `referrals.milestone.reached + next` | `ReferralMilestoneSender` | **нет** | «👥 Пригласить ещё» | `/referrals?from=milestone` |
| Достижение `achievements.caption` | `AchievementSender` | url «Поделиться» (`t.me/share/url`) | «Поделиться» → inline (H5) | `switch_inline_query_chosen_chat` |
| Карточка города `game.card.*` | `GameCardSender` | url «Поделиться» | то же (H5) | то же |
| Запрос в друзья `friends.incoming.text` | `FriendsMessenger::notifyFriendRequested` | cb Принять / Отклонить | оставить | — |
| Друг принял запрос `friends.dm.friend_accepted` | `notifyFriendAccepted` | **нет** | «⚔️ Бросить вызов» | `/friends?friend=<friendship_id>` (открывает выбор вызова) |
| Ответ на «Принять» в боте `friends.accepted` (текст «Список друзей — в приложении») | `FriendsMessenger::acceptFriend / sendRequest` | **нет** | «👥 Друзья» | `/friends` |
| Вызов предложен `friends.dm.challenge_proposed` | `notifyChallengeProposed` | cb Принять / Отклонить | оставить | — |
| Ответ принявшему `friends.challenge.accepted` | `FriendsMessenger::acceptChallenge` | **нет** | «🎯 Открыть вызов» | `/friends?challenge=<id>` |
| **Вызов принят** (пример владельца) `friends.dm.challenge_accepted` | `notifyChallengeAccepted` | **нет** | «🎯 Открыть вызов» | `/friends?challenge=<id>` |
| Вызов завершён `friends.dm.challenge_won / draw` | `notifyChallengeFinished` | **нет** | «🎯 Новый вызов» | `/friends?friend=<friendship_id>` |
| Вызов проигран `friends.dm.challenge_lost` («Реванш — в приложении!») | то же | **нет** | «🔁 Реванш» | `/friends?friend=<friendship_id>` |
| Приглашение в баттл `battle.dm.invited` | `BattleMessenger` | web_app «Открыть баттл» → `/twa/battles/{id}` | оставить | — |
| Пробный Pro `billing.trial.welcome` | `TrialWelcomeMessenger` | **нет** | см. тему 4 | `/?from=trial_welcome` |
| Напоминание о продлении / конце пробного `billing.renewal.*` | `RenewalReminderSender` | web_app «Продлить» → `/twa/billing?from=renewal` | оставить (тексты — docs/34 P6) | — |
| Цепочка после пробного | `TrialOfferSender` (docs/34 P5) | проектируется в docs/34 | кнопки периодов + «Не присылать» | docs/34 §2б.4 |
| Личная скидка после центра `billing.offer_dm.*` | `OfferMessenger` | cb покупки (`ProScreen::buyRows`) | оставить | — |
| Оплата прошла `billing.stars.paid*` | `SuccessfulPaymentHandler` | web_app «Открыть LexiFlow» | «▶️ Начать занятие» | `/?from=paid` |
| Возврат Stars `billing.stars.refunded` | `RefundedPaymentHandler` | нет | не нужна (служебное) | — |
| Промокод применён `bot.promo.success_*` | `PromoCommandHandler` | нет | «▶️ Начать занятие» | `/?from=promo` |
| Аккаунт восстановлен `bot.account.restored*` | `StartCommandHandler` | нет | «▶️ Открыть приложение» | `/` |
| Онбординг: конец `onboarding.done.*` | `OnboardingMessenger` | web_app «Начать первую тренировку» | оставить | — |
| `/today` `onboarding.today.*` | `TodayScreen` | web_app | оставить | — |
| Центр: приглашение, места, выход `organizations.*` | `OrganizationMessenger` | cb и web_app | оставить | — |
| Предложение дружбы по ссылке `friends.offer.text` | `FriendsMessenger::offer` | cb | оставить | — |
| `/invite` | `InviteCommandHandler` | url «Поделиться ссылкой» | inline (H5) | `switch_inline_query_chosen_chat {query: "i:"}` |
| Групповые (урок, экзамен, «Караван», итоги, задания) | `GroupSessionAnnouncer`, `ExamLauncher`, `ClassCaravanMessenger`, `PostLeaderboardJob`, `AssignmentService` | url `startapp` | оставить; кубок — docs/33 | — |

Перед N0 coder проверяет перечень поиском `->sendMessage(`, `->sendPhoto(`, `->sendWebAppButton(` по `app/` (сейчас 55 файлов): ни одно сообщение ученику не должно остаться без строки в этой таблице.

**Важно про пример владельца.** «Tolipov musobaqangizni qabul qildi!» — это **вызов другу** (`Challenge`: кто больше повторит или выучит за N дней, uz «musobaqa»), а не **баттл** (`Battle`: живая дуэль, uz «bellashuv»). Кнопка «начать баттл» здесь вводила бы в заблуждение. Вызов начинается **завтра** (`starts_on`), поэтому главная кнопка — «🎯 Открыть вызов»: экран показывает счёт и дни. Отдельного экрана вызова в Mini App нет: вызовы живут на `FriendsView.vue`. Нужен параметр `?challenge=<id>` — прокрутить к карточке вызова и подсветить её. `?friend=<friendship_id>` открывает выбор нового вызова этому другу. Чужой id просто ничего не подсвечивает: данные экран берёт из своего `GET /friends`.

**Как сейчас делаются кнопки.** Разрозненно:
- адрес собирается вручную `rtrim(config('twa.base_url'), '/').'/twa/…'` в 19 местах (18 файлов), константы `TWA_PATH` в четырёх отправителях;
- клавиатура — где через `TelegramClient::sendWebAppButton()` (одна кнопка; в группе сам превращает в `startapp`-ссылку), где массивом `['inline_keyboard' => …]` прямо в коде; `TelegramKeyboardFactory` умеет только одну `web_app` или одну `url` кнопку;
- `sendPhoto` кнопку `web_app` не превращает в ссылку — для групп это не нужно (фото шлются только в личку), но правило нигде не записано;
- `friends.dm.button` («Открыть LexiFlow») есть в lang, но нигде не используется.

### 3б. Решение — единый построитель

**`App\Domain\Telegram\Support\TwaScreen`** (enum) — единственный список экранов, куда ведут сообщения бота:

```php
enum TwaScreen: string {
    case Today = '/';  case Courses = '/courses';  case League = '/league';  case LeagueMonth = '/league/month';
    case Friends = '/friends';  case Battle = '/battles/{id}';  case Referrals = '/referrals';
    case Billing = '/billing';  case Achievements = '/achievements';
}
```

**`App\Domain\Telegram\Services\BotButtons`** (расширяет роль `TelegramKeyboardFactory`, тот остаётся для старых вызовов):

```php
public function twaUrl(TwaScreen $screen, array $params = [], array $query = []): string; // единственное место с config('twa.base_url')
public function open(string $text, TwaScreen $screen, array $params = [], array $query = []): BotButton;   // web_app; в группе — url startapp
public function callback(string $text, string $data): BotButton;          // проверяет ≤ 64 байт
public function url(string $text, string $url): BotButton;
public function shareInline(string $text, string $query): BotButton;      // switch_inline_query_chosen_chat (H5)
/** @param list<list<BotButton>> $rows */
public function keyboard(int|string $chatId, array $rows): array;         // reply_markup; web_app → url startapp вне личного чата
```

- `from=<вид сообщения>` добавляется к каждому `open()` автоматически из аргумента `$query` — так в аналитике видно, какое сообщение привело в приложение (как сейчас `from=reminder`).
- Тексты кнопок — новый файл `lang/{ru,uz}/buttons.php`, общий для всех сообщений (одна формулировка — одно место):

| Ключ | ru | uz |
|---|---|---|
| `buttons.start_lesson` | ▶️ Начать занятие | ▶️ Mashg'ulotni boshlash |
| `buttons.first_lesson` | ▶️ Первое занятие | ▶️ Birinchi mashg'ulot |
| `buttons.choose_course` | 📚 Выбрать курс | 📚 Kursni tanlash |
| `buttons.open_app` | ▶️ Открыть приложение | ▶️ Ilovani ochish |
| `buttons.open_challenge` | 🎯 Открыть вызов | 🎯 Musobaqani ochish |
| `buttons.new_challenge` | 🎯 Новый вызов | 🎯 Yangi musobaqa |
| `buttons.rematch` | 🔁 Реванш | 🔁 Revansh |
| `buttons.challenge_friend` | ⚔️ Бросить вызов | ⚔️ Musobaqaga chaqirish |
| `buttons.friends` | 👥 Друзья | 👥 Do'stlar |
| `buttons.open_league` | 🏅 Открыть лигу | 🏅 Ligani ochish |
| `buttons.month_rating` | 🏆 Рейтинг месяца | 🏆 Oy reytingi |
| `buttons.invite_more` | 👥 Пригласить ещё | 👥 Yana taklif qilish |
| `buttons.share` | 📤 Поделиться | 📤 Ulashish |

  uz — на вычитку (docs/I18N_UZ_REVIEW.md, новый раздел «Кнопки бота»). Существующие ключи кнопок (`reminders.open_button`, `leagues.open_button` и др.) переводятся на `buttons.*` постепенно, в задачах N, где трогается их отправитель.

**Правило (в docs/04, раздел «Бот»):** *у каждого сообщения ученику, после которого логично действие, есть ровно одна главная кнопка; вторая — только если это выбор (Принять / Отклонить) или отказ от рассылки (docs/34 «Не присылать предложения»). Служебные сообщения (возврат, удаление аккаунта, ошибки) — без кнопки.* Проверка — тест-страж: в `app/` нет `config('twa.base_url')` вне `BotButtons`, `MiniAppLinks`, `TelegramSetupBot`, `BotMenuBuilder`.

**Отвергнуто:** кнопка «Открыть LexiFlow» везде (не говорит, что делать); `startapp`-ссылки и в личном чате (лишний переход через «Открыть»: `web_app` в личке открывается сразу); отдельный экран вызова в Mini App (вызов — одна карточка, экрана не стоит).

### 3в. Задачи

| № | Задача | Кто | Зависит от | Definition of Done и ключевые тесты |
|---|---|---|---|---|
| N0 | Docs: docs/04 (правило одной кнопки, таблица §3а, `TwaScreen`), docs/05 (`/friends?challenge=`, `?friend=`), I18N_UZ_REVIEW (кнопки) | координатор | — | docs согласованы |
| N1 | `TwaScreen`, `BotButton`, `BotButtons`, `lang/{ru,uz}/buttons.php` | coder | N0 | Unit: адреса с параметрами и `from`; web_app в личке, url `startapp` в группе; `callback_data` > 64 байт → исключение; без имени бота группа получает обычный адрес (как сейчас) |
| N2 | **Вызовы и друзья**: кнопки в `notifyChallengeAccepted`, `acceptChallenge`, `notifyChallengeFinished` (won/draw/lost), `notifyFriendAccepted`, `acceptFriend` / `sendRequest` | coder | N1 | `FriendsMessengerTest`: у каждого вида — своя кнопка и адрес с id; маркер и 403 как сейчас |
| N3 | Mini App: `FriendsView` — `?challenge=<id>` (прокрутка и подсветка карточки, чужой/неизвестный id — ничего), `?friend=<id>` (открыть выбор вызова, если вызов возможен) | coder | N0 | vitest: подсветка, неизвестный id, вызов уже открыт → показать его |
| N4 | `MonthWinnerSender`, `ReferralMilestoneSender`, `PromoCommandHandler` (успех), `StartCommandHandler` (восстановление), `SuccessfulPaymentHandler` — кнопки из §3а | coder | N1 | Тест на каждого отправителя: кнопка и адрес; для `MonthWinnerSender` — вместе с A5, если A5 готов |
| N5 | `ReminderSender`: текст кнопки по виду напоминания, `choose_course` → `/courses` | coder | N1 | `ReminderSenderTest` по трём видам |
| N6 | Перевод остальных мест с ручного `config('twa.base_url')` на `BotButtons::twaUrl()` + тест-страж | coder | N1 | Страж зелёный; все тесты бота зелёные без правки ожиданий адресов |

### 3г. Вопросы владельцу

- **В3.1.** В примере «musobaqa» — это вызов другу на несколько дней, а не баттл. Кнопка «🎯 Открыть вызов» (uz «Musobaqani ochish»), а «Начать баттл» оставляем только для приглашения в баттл? *Рекомендация:* да.

### 3д. Риски

- Кнопка `web_app` в сообщении, которое ученик **перешлёт** в группу, перестаёт работать — Telegram это допускает. Не наш случай: делиться нужно через H, а не пересылкой.
- Добавление кнопок не увеличивает число сообщений и не задевает лимиты (docs/34 §4б).

---

## 4. Тема 4 — сообщение о пробном Pro

### 4а. Как сейчас по коду

- Выдача: `LearnerRegistrar` пишет `meta.pro_trial_pending` любому созданному ученику; первый **самостоятельный** контакт (`/start` или Mini App) вызывает `ProTrialService::offer()` → `SubscriptionService::grantTrial()` (одна транзакция, книга `pro_trials` по HMAC Telegram id — один пробный на аккаунт навсегда) и пишет одноразовую метку `meta.pro_trial_notice` (дата конца).
- Сообщение **уже есть**: `TrialWelcomeMessenger::announce()` забирает метку (`consumeNotice()` — идемпотентно, второй `/start` молчит), проверяет, что пробный всё ещё действует (`noticeIsCurrent()`), и шлёт `billing.trial.welcome`: «🎁 Вам открыт Pro на :days — бесплатно, до :date. Пригласите :friends — получите ещё :reward: /invite». Срок — `billing.trial.days`, дата — в поясе ученика.
- Когда: в `OnboardingCallbackHandler` **перед** финальным сообщением онбординга (чтобы кнопка «Начать первую тренировку» осталась последней) или на `/start` у того, кто онбординг уже прошёл (`StartCommandHandler`). Тест — `TrialWelcomeBotTest`.
- Чего нет: списка того, что даёт Pro; кнопки на пути `/start`; сообщения ученику, который пришёл **только в Mini App** и в бот не заходил (метка ждёт `/start`; написать ему первым бот не может — Telegram ответит 403 «can't initiate»).
- Награда пригласившему: `ReferralMilestoneSender` шлёт `referrals.milestone.reached` («🎉 3 ваших друга начали заниматься — вам +7 дней Pro (до :date)») + `next`. Кнопки и плюсов нет. По docs/34 §1а: «неделя за реферала» — это `milestone_days` за **3-го и 6-го** засчитанного друга, а не за каждого.

### 4б. Решение

**Текст** (`billing.trial.welcome` переписывается; `welcome_plain` — тот же без строки про друзей):

ru:
```
🎁 Вам открыт Pro на :days — бесплатно, до :date.

Что даёт Pro:
• :benefit1
• :benefit2
• :benefit3
• :benefit4

Пригласите :friends — получите ещё :reward Pro: /invite
```
uz:
```
🎁 Sizga :days Pro bepul ochildi — :date gacha.

Pro nima beradi:
• …

:friends taklif qiling — yana :reward Pro olasiz: /invite
```

- `:days` — `billing.trial.days` (станет 14, docs/34 T2), `:date` — дата конца в поясе ученика. Числа в тексте не зашиваются.
- **Плюсы — не руками.** Первые 4 пункта из того же списка, что строит `ProBenefits` / `ProPromises` для пейвола (docs/34 §2б.4): новые слова без лимита, игры и баттлы без лимита, свои колоды, заморозки серии. Скрытая фича в список не попадает. Серверный источник уже есть — `App\Domain\Billing\Support\ProPromises` (его читают `LandingPricing`, `PracticeAccess`, `GameAccess`, а TWA-компонент `ProBenefits.vue` получает данные с сервера). Добавить метод `ProPromises::top(int $n, string $locale): list<string>` — короткие строки для бота.
- **Кнопка.** Правило одной кнопки (§3б): в онбординге за этим сообщением сразу идёт финальное с кнопкой «Начать первую тренировку» — своей кнопки у приветствия **нет**. На пути `/start` (онбординг пройден) — кнопка `buttons.start_lesson` → `/?from=trial_welcome`. `announce()` получает аргумент `withButton: bool`.
- **Когда — без изменений:** после выбора языка, перед финалом онбординга; не посреди вопросов.
- **Идемпотентность — уже есть** (одноразовая метка в `meta` под блокировкой строки). Новое не нужно; тест на двойной `/start` и на гонку `/start` + Mini App оставить.
- **Ученик только из Mini App.** Сервер не может написать первым. Решение: Mini App один раз показывает лист «Pro на 14 дней — до :date» с теми же плюсами (`ProBenefits`), если `/me.entitlement` говорит «Pro из пробного периода» (поле источника уточнить в W3; при отсутствии — добавить его в ресурс) и в `localStorage` нет `trial_welcome_seen:<ends_at>`. Метку бота Mini App **не забирает** — если ученик потом нажмёт `/start`, бот тоже поздравит; это допустимо (два канала, одно сообщение в каждом).
- **Награда за друзей** — отдельный текст (не путать с пробным): `referrals.milestone.reached` дополняется одной строкой «Pro продлён до :date» (уже есть) и кнопкой `buttons.invite_more` → `/referrals` (N4). Плюсы не повторяем: ученик уже на Pro.
- **Связь с docs/34:** цепочка после окончания пробного (шаги −3, 0, +3, +10, отписка `bill1:offers_off`, «одно сообщение о Pro в сутки») — задачи P5, P6 docs/34. Приветствие в счёт этого лимита **не входит**: оно в день выдачи, а цепочка начинается за 3 дня до конца. Список плюсов — общий источник с P5 (`ProPromises`), чтобы тексты не разошлись.

### 4в. Задачи

| № | Задача | Кто | Зависит от | Definition of Done и ключевые тесты |
|---|---|---|---|---|
| W0 | Docs: docs/13 §7.1 «2C.9» (новый текст, плюсы, кнопка, лист в Mini App) | координатор | docs/34 T1 | docs согласованы |
| W1 | `ProPromises::top()` — короткий список плюсов для бота, общий с docs/34 P5 | coder | W0 | Unit: скрытая фича не попадает; ru/uz; порядок стабилен |
| W2 | `TrialWelcomeMessenger`: новый текст с плюсами, `withButton` на пути `/start` | coder | W1, N1 | `TrialWelcomeBotTest`: 14 дней из конфига, дата в поясе ученика, 4 пункта, в онбординге без кнопки и перед финалом, на `/start` с кнопкой; второй `/start` — тишина; устаревшая метка — тишина |
| W3 | Mini App: разовый лист пробного Pro (`localStorage` по `ends_at`) | coder | выпуск 1 (макет), W1 | vitest: показ один раз на `ends_at`; не показывается платному Pro и Pro от центра |
| W4 | `ReferralMilestoneSender`: кнопка «Пригласить ещё» (входит в N4) | coder | N1 | Тест кнопки |

### 4г. Вопросы владельцу

- **В4.1.** В приветствии 4 пункта «что даёт Pro» и строка про друзей — не слишком длинно? *Рекомендация:* да, 4 пункта; длиннее Telegram покажет «простынёй».

### 4д. Риски

- Если плюсы в TWA и на сервере начнут собираться из разных списков, бот пообещает то, чего нет в Mini App. W1 — один источник.

---

## 5. Тема 5 — название «Muhrla»

### 5а. Где встречается «LexiFlow» (поиск без учёта регистра, 25.11.2026)

| Группа | Вхождений / файлов | Видит ученик? | Что это |
|---|---|---|---|
| `lang/ru`, `lang/uz` | 86 + 87 / 29 | **да** | Бот, напоминания, биллинг (31 в ru), лендинг, юр. тексты, достижения, друзья, рефералы; `achievements.card.brand` |
| TWA `src/i18n/{ru,uz}.ts` | 6 + 7 | **да** | Тексты Mini App |
| TWA `LandingView.vue` (заголовок), `index.html` (`<title>`), `PaywallSheet.vue` (комментарий про «LexiFlow Pro») | 3 | **да** (первые два) | — |
| TWA ключи `localStorage` `lexiflow:*`, `lexiflow.*` (`proReminders.ts`, `TodayView`, `GameMapView`, `useAutoSpeak`, `useSayAloudHint`, `useFeedbackSound`, `assignments.ts`) | 16 | нет | **Не менять**: смена сбросит у учеников скрытые подсказки и настройки звука |
| TWA тесты | 62 / 16 | нет | Меняются вслед за текстами |
| Сайт `resources/views/site/*`, `billing/return.blade.php` | 6 / 4 | **да** | `og:site_name`, логотип-текст, `<title>` |
| Панели Filament: `brandName('LexiFlow Pro')` в `AdminPanelProvider`, `OrgPanelProvider` | 2 | центры — да | — |
| Кнопка меню бота `BotMenuBuilder::MENU_BUTTON_TEXT = 'LexiFlow'` | 1 | **да** | Применяется `telegram:setup-bot` |
| Письма `mail/org/*` (через `config('app.name')`), страницы 2FA | 4 | центры / админы | — |
| `public/.well-known/security.txt`, `public/css/site.css` | 2 | нет / почти нет | — |
| `public/twa/assets/*` (собранная TWA) | ~30 | — | Пересобирается, руками не трогать |
| `app/` прочее: `JwtService` issuer `lexiflow`, `config/twa.php` `TWA_JWT_ISS`, `PracticeImporter::FORMAT = 'lexiflow-practice/1'`, `PanelTheme::CSS_ID`, комментарии | ~15 | нет | **Не менять** |
| `database/data/practice/*.json` | 100 / 100 | нет | Формат `lexiflow-practice/1` — **не менять** |
| `.env.example` | 8 | нет | `APP_NAME`, БД, cookie, кэш, почта, `TWA_JWT_ISS` |
| Docker / nginx (`docker-compose.yml`, `docker/nginx/*.conf`, `postgres/*`) | 112 / 12 | нет | Имена контейнеров, переменные nginx, домены — **не менять** в рамках переименования |
| `tests/` (PHP) | 334 / 99 | нет | Меняются вслед за текстами lang |
| `docs/` | 569 / 52 | нет | История — не менять; менять только USER_GUIDE, TEACHER_GUIDE, лендинговые/пользовательские куски |
| `README.md`, `PROGRESS.md`, `CLAUDE.md` | 14 | нет | Заголовки — по желанию; PROGRESS — история |
| Имя бота `@dev_lexiflow_bot` / прод-бот, домены | — | **да** (в ссылках) | Меняются только у BotFather и в DNS — см. ниже |

**Итого видимое ученику — около 200 строк в ~40 файлах**, всё остальное — техническое.

Особые места:
- **`APP_NAME` менять нельзя без подготовки.** Из него вычисляются `REDIS_PREFIX` (в `.env.example` не задан → `lexiflow-database-`: очереди, блокировки, кэш), имя бэкапов (`config/backup.php` — путь `storage/app/private/<APP_NAME>`), имя отправителя почты. Смена `APP_NAME` на проде без явного `REDIS_PREFIX` потеряет задания в очереди и блокировки. Поэтому бренд — **отдельный ключ**.
- **Имя бота.** Отображаемое имя (`Muhrla`) меняется у BotFather в любой момент без последствий. **Username** (`@…lexiflow_bot`) лучше не менять: все ранее отправленные ссылки `t.me/<старое имя>?start=ref_…` перестанут работать, а старое имя может занять кто-то другой. Если нужен `@muhrla_bot` — это новый бот (новый токен, вебхук, Mini App у BotFather, Stars-платежи привязаны к боту) — отдельный проект.
- **Домен** (nginx, Cloudflare, адреса возврата Payme / Click, `twa.base_url`, URL Mini App у BotFather) — отдельный проект, не часть переименования текстов.
- 2FA: `TwoFactorController` подписывает TOTP через `config('app.name')` — у админов в приложении-аутентификаторе останется старое название, это не ломает вход.

### 5б. Решение — бренд в одном месте

- **Сервер:** `config/app.php` → `'brand' => env('APP_BRAND', 'Muhrla')`, `'brand_pro' => env('APP_BRAND_PRO', 'Muhrla Pro')`, `'brand_logo' => env('APP_BRAND_LOGO')` (путь к PNG или `null`). Читают: карточки (A2), `ShareMessageBuilder` (`:brand`), `BotMenuBuilder` (кнопка меню), Filament `brandName(config('app.brand_pro'))`, сайт (`og:site_name`, логотип, `<title>`), `billing/return`, письма и 2FA-страницы (вместо `config('app.name')`).
- **Тексты lang.** Плейсхолдер `:brand` во всех ~175 строках потребовал бы передавать его в каждый вызов `__()` (сотни мест) — это хуже. Делаем **прямую замену слова в lang** одним коммитом и **тест-страж**: в `lang/` и `resources/twa/src/i18n/` нет слова старого бренда, а бренд в lang совпадает с `config('app.brand')` (тест читает все строки и ищет «LexiFlow»). Следующее переименование = одна замена по `lang/` + TWA i18n + одна строка env; страж покажет пропуски.
- **TWA:** `resources/twa/src/brand.ts` — `export const BRAND = import.meta.env.VITE_BRAND ?? 'Muhrla'`; его читают `LandingView` и i18n-строки там, где бренд стоит в коде, а `<title>` в `index.html` — `%VITE_BRAND%` (подстановка env в HTML у Vite есть из коробки). i18n-строки — прямая замена, как в lang, с тем же стражем в vitest.
- **Не переименовываем:** пространства имён и пакеты, имена таблиц и БД, `APP_NAME`, `REDIS_PREFIX`/`CACHE_PREFIX`/`SESSION_COOKIE`, `TWA_JWT_ISS` (смена разлогинит всех), ключи `localStorage`, формат `lexiflow-practice/1`, `PanelTheme::CSS_ID`, docker/nginx, username бота, домен, исторические docs, PROGRESS.md, аудиты.

### 5в. Задачи

| № | Задача | Кто | Зависит от | Definition of Done и ключевые тесты |
|---|---|---|---|---|
| M0 | Ответ владельца на В5.1; docs: docs/00_README и PRD (название продукта), USER_GUIDE, TEACHER_GUIDE, ADMIN_GUIDE (бренд-ключи, что не менять и почему) | координатор | В5.1 | docs согласованы |
| M1 | `app.brand`, `app.brand_pro`, `app.brand_logo`, `.env.example` (`APP_BRAND`), `VITE_BRAND`, `brand.ts` | coder | M0 | Unit: умолчания; `.env.example` содержит ключи |
| M2 | Код читает бренд из конфига: `BotMenuBuilder`, Filament-провайдеры, сайт, `billing/return`, письма, 2FA, `index.html`, `LandingView` | coder | M1 | Feature: лендинг и панели показывают `config('app.brand')`; `telegram:setup-bot` ставит кнопку меню с брендом |
| M3 | Замена в `lang/{ru,uz}` и TWA i18n (узбекские аффиксы: `LexiFlow'da` → `Muhrla'da`, `LexiFlow'ni` → `Muhrla'ni` — вычитать каждое), тест-страж, правка тестов PHP и vitest | coder | M1, В5.1 | Страж зелёный; весь pest и vitest зелёные; вычитка uz в I18N_UZ_REVIEW |
| M4 | Прод: отображаемое имя бота, описание и короткое описание у BotFather, название Mini App, `telegram:setup-bot`, пересборка TWA | владелец / devops | M2, M3 | В Telegram везде «Muhrla», старые ссылки `?start=ref_` работают |

### 5г. Вопросы владельцу

- **В5.1. Насколько переименовываем сейчас?**
  - (а) только картинки достижений — 1 ключ, но ученик видит «Muhrla» на карточке и «LexiFlow» в тексте под ней;
  - (б) **все видимые ученику тексты** (бот, Mini App, сайт, панели, кнопка меню, отображаемое имя бота) — ~200 строк, 1–2 дня работы coder, без риска для данных;
  - (в) продукт целиком, включая username бота, домен и технические имена — недели работы и риск потери ссылок и платежей.

  *Рекомендация:* **(б)** сейчас (бета — лучший момент, учеников мало), username бота и домен — отдельным решением позже.
- **В5.2.** Тариф называется «Muhrla Pro» (было «LexiFlow Pro»)? *Рекомендация:* да.

### 5д. Риски

- Частичная замена (вариант а) даёт два названия рядом — путает учеников и ревью.
- Узбекские формы с апострофом (`LexiFlow'da`) — простая замена по слову сработает, но окончание может требовать другой гармонии; вычитка обязательна.
- Смена `APP_NAME` «заодно» — потеря очередей и блокировок в Redis (см. §5а).

---

## 6. В какой выпуск docs/31 §2 встаёт каждая тема

| Тема | Задачи | Выпуск | Почему |
|---|---|---|---|
| 5. Бренд-ключи и переименование | M0–M4 | **Сразу, до выпуска 1** (параллельно выпуску 0) | Новый дизайн уже с «Muhrla»; карточка (A2) читает бренд из конфига |
| 4. Приветствие пробного Pro (бот) | W0–W2 | **Сразу, вместе с docs/34 T2** (14 дней) | Новички должны получить новый текст с первого дня нового пробного срока |
| 3. Построитель кнопок и вызовы | N0–N2, N4–N6 | **Сразу, параллельно выпуску 0** | Только бот, не зависит от дизайна; пример владельца закрывает N2 |
| 3. Экран вызова в Mini App | N3 | **Выпуск 1** (`FriendsView` перерисовывается); если раньше — минимально в текущем дизайне | Не рисовать дважды |
| 2. Новая карточка | A0–A4 | **Выпуск 1** | Тот же визуальный стиль, что у Mini App |
| 2. Картинка победителя месяца | A5 | **Выпуск 3** (месяц v2, призы в Free и Pro) | Месяц переделывается там |
| 1. «Поделиться» с кнопкой — сервер и бот | H0–H5, H7, H8 | **Выпуск 1**, после A2 | Нужен публичный JPEG карточки |
| 1. «Поделиться» в Mini App | H6 | **Выпуск 1** | Экраны достижений, друзей и рефералов перерисовываются |
| 4. Лист пробного Pro в Mini App | W3 | **Выпуск 1** | Новый дизайн |
| Итоги лиги / кубка как картинка и share | `ShareKind::LeagueWeek`, кубок | Выпуски 3 и 5 | Вместе с новыми итогами и кубком (docs/33) |

## 7. Риски для ревью (сводно)

- **Telegram:** всё из §1б сверить на живом боте (H1) до H3. Если `shareMessage` окажется недоступен у большой доли учеников, запасной путь (нынешняя ссылка) должен работать без регрессий — тесты H6 это проверяют.
- **SDK Mini App** 7.10 перетирает `window.Telegram.WebApp`: обновление до 8.x — смена версии существующей зависимости, нужна отметка координатора.
- **Новые файлы шрифтов** (Source Serif 4 TTF, OFL) — согласовать; без них карточка рисуется Noto Sans, макет не совпадёт.
- **Публичный каталог карточек** и Cloudflare: Telegram должен скачать JPEG без проверки «вы человек?».
- **Безопасность share:** `user_id` только из JWT / `from.id`, предмет только свой, текст только серверный, лимиты на prepare и inline-запросы.
- **`APP_NAME` не трогать** при переименовании.
