# Настройка озвучки: Google Cloud TTS и Cloudflare R2

Пошаговое руководство для владельца. План кода — `docs/26_AUDIO_TTS_PLAN_RU.md`.

> **Важно.** Части A и B (Google Cloud и Cloudflare) можно делать уже сейчас. Часть C описывает настройки приложения, которых в коде **ещё нет**: имена переменных взяты из плана и будут сверены с кодом после реализации.
>
> Названия пунктов меню в консолях Google и Cloudflare меняются; если пункт называется иначе, ищите по смыслу. Цены и квоты проверены по официальным страницам 20.11.2026.

## Что получится

```
правка слова в админке ──► очередь low ──► Google Cloud TTS ──► mp3 ──► бакет R2
ученик открывает карточку ──► https://audio.ваш-домен/audio/<хеш>.mp3 ──► кэш Cloudflare ──► R2
```

Google вызывается один раз на каждый текст. Ученики к Google не обращаются.

---

## Часть A. Google Cloud

### A1. Проект и оплата

1. Откройте `console.cloud.google.com` и войдите рабочим аккаунтом Google (не личным: доступ к проекту потом можно передать).
2. Создайте проект: список проектов вверху → **New project** → имя, например `lexiflow-tts`.
3. **Billing** → привяжите платёжный профиль с картой. Без него API не включится, даже если вы остаётесь в бесплатном объёме.
   - Примет ли Google карту из Узбекистана — не проверено. Если не примет, используйте карту другой страны или партнёра-реселлера Google Cloud.
   - Новым аккаунтам Google даёт пробный кредит (на 20.11.2026 — $300); он не обязателен.

### A2. Включить API

1. **APIs & Services → Library**.
2. Найдите **Cloud Text-to-Speech API** → **Enable**.

### A3. Сервисный аккаунт и ключ

Приложение входит в Google не вашим паролем, а отдельным техническим аккаунтом.

1. **IAM & Admin → Service Accounts → Create service account**.
2. Имя: `lexiflow-tts`. Ролей на проект **не давайте** — для вызова TTS из своего же проекта они, как правило, не нужны. Если первый запрос вернёт `403 PERMISSION_DENIED`, добавьте роль **Service Usage Consumer** (`roles/serviceusage.serviceUsageConsumer`) и больше ничего.
3. Откройте созданный аккаунт → **Keys → Add key → Create new key → JSON**. Скачается файл `lexiflow-tts-….json`.
4. Этот файл — пароль. Правила:
   - не кладите его в репозиторий, в чат, в почту;
   - на сервере храните вне папки проекта, права `600`, владелец — пользователь, под которым работает очередь;
   - при подозрении на утечку: **Keys** → удалить ключ → создать новый.

Если в организации Google включён запрет на ключи сервисных аккаунтов (`iam.disableServiceAccountKeyCreation`), кнопка создания ключа будет недоступна — запрет снимается в **Organization Policies** администратором организации.

### A4. Проверка с вашего компьютера (необязательно)

Нужен установленный `gcloud`.

```bash
gcloud auth activate-service-account --key-file=lexiflow-tts-XXXX.json
```

```bash
curl -s -X POST "https://texttospeech.googleapis.com/v1/text:synthesize" \
  -H "Authorization: Bearer $(gcloud auth print-access-token)" \
  -H "Content-Type: application/json" \
  -d '{"input":{"text":"surname"},"voice":{"languageCode":"en-US","name":"en-US-Neural2-F"},"audioConfig":{"audioEncoding":"MP3"}}' \
  | python3 -c "import sys,json,base64; open('test.mp3','wb').write(base64.b64decode(json.load(sys.stdin)['audioContent']))"
```

Появился `test.mp3` и он звучит — Google настроен.

### A5. Выбор голоса

| Семейство | Цена за 1 млн символов | Бесплатно в месяц | Когда брать |
|---|---|---|---|
| Chirp 3 HD (`en-US-Chirp3-HD-…`) | $30 | 1 млн | самый естественный; проверить на одиночных словах |
| Neural2 (`en-US-Neural2-…`) | $16 | 1 млн | ровный, предсказуемый на коротких словах |
| WaveNet / Standard | $4 | 4 млн | заметно проще по звучанию |

- Весь объём проекта (≈ 308 тыс. символов со словами, диктантами и примерами) меньше бесплатного миллиона у любого семейства.
- Послушать голоса: страница Cloud Text-to-Speech в консоли или демо на `cloud.google.com/text-to-speech`.
- Список имён: `curl -H "Authorization: Bearer $(gcloud auth print-access-token)" "https://texttospeech.googleapis.com/v1/voices?languageCode=en-US"`.
- Американский (`en-US`) или британский (`en-GB`) вариант — решите до массового прогона: транскрипции в курсах американские (`/ˈsɜrneɪm/`).
- Документация Google на 20.11.2026 противоречива насчёт SSML и скорости речи у Chirp 3 HD; приложение их не использует, так что это не мешает.

### A6. Квоты

**APIs & Services → Cloud Text-to-Speech API → Quotas**. По умолчанию: 200 запросов в минуту для Chirp 3, 1 000 для Neural2, до 5 000 байт текста в запросе. Приложение само держит темп ниже квоты; повышать ничего не нужно.

### A7. Защита от неожиданного счёта

1. **Billing → Budgets & alerts → Create budget**.
2. Область: проект `lexiflow-tts`. Сумма: например $5 в месяц.
3. Оповещения на 50 %, 90 %, 100 % — на вашу почту.

Бюджет **только предупреждает**, сам он расходы не останавливает. Останавливает их ограничитель в приложении (`TTS_MONTHLY_CHAR_LIMIT`, часть C) и, в крайнем случае, отключение API или ключа в консоли.

---

## Часть B. Cloudflare R2

Сайт должен быть подключён к Cloudflare (`docs/23_CLOUDFLARE_RU.md`).

### B1. Бакет

1. Cloudflare → **R2 Object Storage → Create bucket**.
2. Имя: `lexiflow-audio`. Расположение: Automatic. Класс хранения: **Standard** (бесплатный объём действует только для него).
3. Это **отдельный** бакет. Бакет резервных копий остаётся закрытым и сюда не подключается.

### B2. Свой домен для бакета

1. Бакет → **Settings → Public access → Custom Domains → Connect Domain**.
2. Домен: `audio.ваш-домен` (поддомен зоны, которая уже в Cloudflare). Cloudflare сам создаст DNS-запись и сертификат.
3. Адрес вида `…r2.dev` **не включайте**: у него нет кэша и есть ограничение скорости, он для разработки.

### B3. Кэш

Файлы никогда не меняются (имя построено от содержимого), поэтому кэш — максимальный.

1. **Caching → Cache Rules → Create rule**.
2. Условие: `Hostname equals audio.ваш-домен`.
3. Действие: **Eligible for cache**, Edge TTL — 1 год, Browser TTL — 1 год.

Попадания в кэш Cloudflare не считаются чтениями R2.

### B4. Ключ доступа для приложения

1. **R2 → Manage R2 API Tokens → Create API token**.
2. Права: **Object Read & Write**, область — **только бакет `lexiflow-audio`**.
3. Сохраните `Access Key ID`, `Secret Access Key` и адрес `https://<account-id>.r2.cloudflarestorage.com`. Секрет показывается один раз.

Не используйте ключ резервных копий: у него доступ к дампам базы.

### B5. Сколько это стоит

| Что | Цена | Бесплатно в месяц | У вас |
|---|---|---|---|
| Хранение | $0,015 за ГБ | 10 ГБ | < 0,3 ГБ |
| Отдача данных | $0 | — | — |
| Чтения (класс B) | $0,36 за 1 млн | 10 млн | ≈ 5 млн при 3 000 учеников без кэша |
| Записи (класс A) | $4,50 за 1 млн | 1 млн | ≈ 12 тыс. разово |

---

## Часть C. Приложение (после реализации docs/26)

### C1. Ключ Google на сервере

```bash
sudo install -m 600 -o www-data -g www-data lexiflow-tts-XXXX.json /etc/lexiflow/google-tts.json
```

Пользователь и путь — по вашему развёртыванию; в Docker файл подключается в контейнеры `app` и `queue-low` только для чтения.

### C2. Переменные `.env` (имена из плана)

```dotenv
TTS_DRIVER=google
GOOGLE_TTS_CREDENTIALS=/etc/lexiflow/google-tts.json
TTS_VOICE=en-US-Neural2-F
TTS_AUDIO_VERSION=1
TTS_MONTHLY_CHAR_LIMIT=900000

TTS_AUDIO_DISK=audio
AUDIO_ACCESS_KEY_ID=...
AUDIO_SECRET_ACCESS_KEY=...
AUDIO_BUCKET=lexiflow-audio
AUDIO_ENDPOINT=https://<account-id>.r2.cloudflarestorage.com
AUDIO_PUBLIC_URL=https://audio.ваш-домен
```

После правки: `php artisan config:cache` и перезапуск обработчиков очереди.

### C3. Первый запуск

1. Контрольный набор — 30 слов и фраз выбранным голосом:

   ```bash
   php artisan audio:sample
   ```

   Послушайте. Не нравится — поменяйте `TTS_VOICE` и повторите.

2. Озвучить всё:

   ```bash
   php artisan audio:pregenerate --all
   ```

   Задания идут в очередь `low`. На 6,5 тыс. слов и диктантов при темпе ниже квоты — порядка часа (оценка).

3. Покрытие:

   ```bash
   php artisan audio:coverage english-a1-a2 --verify
   ```

### C4. Проверка

```bash
curl -sI https://audio.ваш-домен/audio/<имя-файла>.mp3 | grep -i -E "HTTP|cf-cache-status|cache-control|content-type"
```

Ожидается `200`, `content-type: audio/mpeg`, при втором запросе — `cf-cache-status: HIT`.

Затем откройте Mini App на телефоне: слово звучит, в Remote Debugging нет ошибок CSP, в `/admin` курсы ушли из «Требуют внимания».

### C5. Обычная работа

| Что вы делаете | Что происходит |
|---|---|
| Меняете текст слова в `/admin` | старый файл отвязывается, новый создаётся в фоне (обычно в пределах минуты) |
| Добавляете слово или импортируете урок | задания ставятся сами |
| Меняете фразу диктанта | создаётся новая версия задания и её озвучка |
| Меняете пример (после второго выпуска) | озвучка примера создаётся в фоне |
| Хотите другой голос | меняете `TTS_VOICE`, поднимаете `TTS_AUDIO_VERSION`, запускаете `audio:pregenerate --all`; это полный повторный прогон |
| Раз в месяц | `php artisan audio:prune --dry-run`, затем без `--dry-run` — удаляет файлы, на которые ничего не ссылается |

Пока новый файл не готов, ученик слышит голос своего телефона.

### C6. Если что-то не работает

| Признак | Что проверить |
|---|---|
| Задания падают с `403 PERMISSION_DENIED` | API включён (A2)? ключ от нужного проекта? добавьте роль из A3 |
| `401` / `invalid_grant` | часы сервера (расхождение больше пары минут ломает вход), ключ не удалён в консоли |
| `429` | темп выше квоты (A6) — задания повторятся сами |
| Задания «отложены до следующего месяца» | достигнут `TTS_MONTHLY_CHAR_LIMIT`; посмотрите, что съело объём, прежде чем поднимать предел |
| Файл есть в бакете, но в приложении тишина | CSP: домен озвучки в `media-src`; `AUDIO_PUBLIC_URL` совпадает с доменом бакета |
| `cf-cache-status: DYNAMIC` | правило кэша B3 не применилось к этому хосту |
| Очередь стоит | работает ли обработчик `queue-low`; `php artisan queue:failed` |
| У части учеников файл не грузится | доступность домена Cloudflare у оператора; временно `TTS_AUDIO_DISK=public` — файлы с сервера |

### C7. Смена ключей

- Google: создать новый ключ (A3) → заменить файл на сервере → перезапустить очередь → удалить старый ключ.
- R2: создать новый токен (B4) → заменить `AUDIO_*` → `config:cache` → отозвать старый.

## Источники

- Цены Google Cloud Text-to-Speech — https://cloud.google.com/text-to-speech/pricing
- Квоты — https://docs.cloud.google.com/text-to-speech/quotas
- Метод `text:synthesize` — https://docs.cloud.google.com/text-to-speech/docs/reference/rest/v1/text/synthesize
- Вход в API — https://docs.cloud.google.com/text-to-speech/docs/authentication
- Голоса Chirp 3 HD — https://docs.cloud.google.com/text-to-speech/docs/chirp3-hd
- Цены Cloudflare R2 — https://developers.cloudflare.com/r2/pricing/
