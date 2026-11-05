# Cloudflare перед nginx (R13, решение владельца 05.11.2026)

Цель: разгрузить канал сервера. Статика Mini App (`/twa/assets/*`) и аудио слов (`/storage/audio/*`) отдаются с CDN,
сервер получает только API, вебхуки и админку. Побочный выигрыш: WAF и защита от DDoS перед сервером.

Что делает код (уже в репозитории): nginx умеет восстанавливать адрес ученика из `CF-Connecting-IP` (только от диапазонов
Cloudflare) и по желанию закрывать порт 443 для всех, кроме Cloudflare. Всё выключено по умолчанию. Подробности —
`docs/DEVOPS_GUIDE.md` §0 «Cloudflare перед nginx», `docs/07_SECURITY.md` «Доверенные прокси». Остальное — настройки в
панели Cloudflare (они кодом не управляются), их описывает этот документ.

Порядок включения важен: сначала сертификат и `.env`, потом оранжевое облако, потом origin lock.

## 1. DNS

1. Добавьте домен в Cloudflare, на регистраторе смените NS на выданные Cloudflare.
2. DNS → записи `A` (IPv4 сервера) и `AAAA` (если есть IPv6) для `<домен>` (и `www`, если используется).
3. **Первый выпуск сертификата Let's Encrypt делайте с серым облаком** (DNS only): `deploy.sh` / certbot получает
   сертификат по HTTP-01 напрямую на сервер, без неожиданностей.
4. Когда `https://<домен>` открывается с валидным сертификатом — включите оранжевое облако (Proxied) для этих записей.
5. Отдельную запись `origin.<домен>` с **серым** облаком на тот же IP оставьте для нагрузочного теста (§8) и аварийного
   доступа мимо Cloudflare. Сертификат на этот поддомен не выписан, поэтому `curl` к нему — с `--resolve` или `-k` (для теста),
   см. §8.

## 2. SSL/TLS

- Режим шифрования: **Full (strict)**. Не `Flexible` (петля редиректов: Cloudflare ходит на origin по HTTP, а nginx
  отвечает 301 на HTTPS) и не просто `Full` (не проверяет сертификат).
- Сертификат Let's Encrypt на сервере остаётся: он валиден для Full (strict).
- Продление (certbot, HTTP-01, порт 80) за оранжевым облаком работает: запрос Let's Encrypt приходит на Cloudflare, тот
  проксирует `/.well-known/acme-challenge/` на nginx:80, где этот путь отдаётся из `certbot_www` без редиректа. Что стоит
  знать честно:
  - «Always Use HTTPS» включать можно: по документации Cloudflare запросы на `/.well-known/acme-challenge/` из этого
    редиректа исключены. Но это поведение на стороне Cloudflare — после включения проверьте продление:
    `docker compose -f docker-compose.prod.yml --env-file .env exec certbot certbot renew --dry-run`. Если dry-run падает —
    выключите «Always Use HTTPS» (редирект на HTTPS делает и сам nginx на порту 80) или временно переведите запись на серое
    облако на время продления.
  - Не включайте правила Cloudflare (WAF, Bot Fight, Page Rules), которые трогают `/.well-known/acme-challenge/`.
- Минимальная версия TLS: 1.2 (как на origin). HSTS на стороне Cloudflare **не включать**: HSTS выдаёт nginx
  (`max-age=15552000`), два источника дадут дубли в ZAP (10035).
- Альтернатива certbot — **Cloudflare Origin CA** (сертификат до 15 лет, доверяется только Cloudflare). Коротко: в панели
  SSL/TLS → Origin Server → Create Certificate; положить `fullchain.pem`/`privkey.pem` на сервер, примонтировать в сервис
  `nginx` вместо `letsencrypt` (пути `ssl_certificate*` в `docker/nginx/prod.conf`), убрать сервис `certbot`. Минусы: сертификат
  не доверяется браузером при прямом обращении (`origin.<домен>` и аварийный доступ мимо Cloudflare не откроются без `-k`),
  отключение Cloudflare потребует вернуть Let's Encrypt. Пока не реализовано; текущая схема этого не требует.

## 3. Cache Rules (Caching → Cache Rules)

Реальные пути ассетов: Mini App собирается Vite в `public/twa` с `base: '/twa/'`, файлы `assets/[name]-[hash].*`
(`resources/twa/vite.config.ts`), то есть `/twa/assets/*`. Корневой `vite.config.js` (`public/build`) в шаблонах сайта не
используется (`@vite` нет), правило для `/build/*` не нужно. Статика Filament (`/css/filament`, `/js/filament`,
`/fonts/filament`) без хэша в имени: оставьте по умолчанию (Cloudflare кэширует по расширению, ключ включает `?v=`).

Порядок правил — сверху вниз, первое совпавшее с «остановить» не нужно: условия не пересекаются.

| # | Условие (URI Path) | Действие |
|---|---|---|
| 1 | начинается с `/api/` **или** `/telegram/` **или** `/billing/` **или** `/admin` **или** `/org` **или** `/livewire` **или** `/2fa/` **или** `/health/` **или** равен `/up` | Cache eligibility: **Bypass cache** |
| 2 | начинается с `/twa/assets/` | Eligible for cache; Edge TTL: **Use cache-control header if present** (у нас `public, max-age=31536000, immutable`), если нет — 1 год; Browser TTL: **Respect origin** |
| 3 | начинается с `/storage/audio/` | Eligible for cache; Edge TTL: **Use cache-control header if present** (30 дней, `immutable`), иначе 30 дней; Browser TTL: **Respect origin** |

`/twa/index.html` отдаётся с `no-cache, no-store` — Cloudflare его не кэширует и не должен (иначе после релиза ученики
получат старые хэши ассетов). Отдельного правила не нужно. Не ставьте «Cache everything» на `/twa/*` целиком.

Настройки Caching → Configuration: **Browser Cache TTL — Respect Existing Headers**; Caching Level — Standard; «Always
Online» выключить; «Development Mode» только на время отладки.

Ограничение: Cloudflare не кэширует файлы больше лимита плана (512 МБ на Free) — наши файлы на порядки меньше.

## 4. Speed

Безопасно включить: **Brotli**, **HTTP/2**, **HTTP/3 (QUIC)**, Early Hints (по желанию). 0-RTT лучше оставить выключенным: он допускает повтор запроса,
а API содержит POST.

**Выключить** (Speed → Optimization и Rules):
- **Rocket Loader** — переписывает загрузку скриптов, ломает SPA на Vue и порядок `telegram-web-app.js`.
- **Auto Minify** (JS/CSS/HTML) — файлы уже минифицированы Vite; правка HTML ломает SRI/CSP и хэши.
- **Mirage**, **Polish** (для API и аудио не нужны; Polish перепишет картинки, если они есть).
- **Email Obfuscation**, **Server-side Excludes**, **Automatic Platform Optimization** — вставляют скрипты/правят HTML.

Причина — CSP Mini App: `script-src 'self' https://telegram.org` (`docker/nginx/prod.conf`, `/twa/`). Любой скрипт, который
Cloudflare добавит в страницу (`cdn-cgi/scripts/…`, Rocket Loader, обфускация e-mail), браузер заблокирует, а ученик получит
сломанный экран. Не включайте также «Cloudflare Web Analytics» с автоматической вставкой (JS beacon) — то же самое.

## 5. Security

- **WAF → Managed rules** (Cloudflare Managed Ruleset, OWASP) — включить. Первые дни смотрите Security → Events: ложные
  срабатывания на JSON-тела API (импорт слов, ответы практики) гасятся исключением конкретного правила для пути.
- **Отключить челленджи для машинных клиентов.** WebView Telegram и серверы Telegram, Payme, Click не проходят
  JS-челленджи и капчу. WAF → Custom rules, правило **Skip** (для Bot Fight Mode / Browser Integrity Check / Managed Challenge /
  Super Bot Fight / Rate limiting rules — по необходимости) с условием URI Path:
  начинается с `/api/` **или** `/telegram/webhook/` **или** `/billing/webhook/`.
  - **Bot Fight Mode** на Free плане отключить нельзя по путям: если ученики или вебхуки получают 403/challenge — выключите
    его целиком (Security → Bots), а защиту `/api/` оставьте приложению (rate limits, HMAC initData).
  - **Browser Integrity Check** — выключить (Security → Settings) или Skip по путям выше: вебхуки Telegram приходят без
    типичного User-Agent браузера.
  - Уровень безопасности (Security Level) — Medium/Low; High и «I'm Under Attack» дают челлендж вебхукам — включать только
    при атаке и **вместе с Skip-правилом** выше.
- **Rate Limiting Rules** Cloudflare — внешний слой, не замена лимитов приложения (`docs/12_PHASE1_DESIGN.md` §8: `/auth`
  60/мин на IP + 10/мин на `telegram_user_id`). Пример: URI Path = `/api/twa/auth`, счётчик по IP, 60 запросов за 1 минуту →
  Block на 1 минуту. Значение не ниже лимита приложения, чтобы за одним CGNAT-адресом не получить блок раньше, чем
  приложение вернёт аккуратный 429. Для остального `/api/` внешний лимит не ставьте: лимиты там per learner.
- **Origin lock** — §7: без него WAF обходится запросом прямо на IP сервера.
- Telegram присылает вебхуки на 443 — Cloudflare проксирует этот порт. Адрес Telegram в `TelegramIpAllowlist` будет виден
  как `CF-Connecting-IP` (при `CLOUDFLARE_PROXY=true`). Лимит тела запроса Cloudflare (100 МБ на Free) выше нашего
  `client_max_body_size 25m`; таймаут ответа origin на Free — 100 с (в nginx `proxy_read_timeout 60s`).

## 6. Включение в `.env` и запуск

```bash
# .env на сервере
CLOUDFLARE_PROXY=true     # адрес ученика из CF-Connecting-IP (только от диапазонов Cloudflare)
CLOUDFLARE_ONLY=false     # origin lock — позже, после проверки (§7)

C="docker compose -f docker-compose.prod.yml --env-file .env"
$C up -d nginx            # контейнер пересоздаётся (меняется environment); образ пересобирать не нужно
$C logs nginx | head      # «cannot download Cloudflare IP lists» — значит, взят запасной список из репозитория (это нормально)
$C exec nginx nginx -t
$C exec nginx cat /etc/nginx/conf.d/00-cloudflare-real-ip.conf | head -12
```

Порядок: сначала `CLOUDFLARE_PROXY=true` (до или одновременно с оранжевым облаком), потом облако. Если облако включено, а
`CLOUDFLARE_PROXY=false`, все запросы приходят «с адресов Cloudflare»: лимиты per IP схлопнутся, а allowlist Telegram и
платёжных провайдеров вернёт 403.

## 7. Origin lock (`CLOUDFLARE_ONLY=true`)

Включать после проверок из §9, когда уверены, что весь трафик идёт через Cloudflare.

```bash
# .env: CLOUDFLARE_ONLY=true
$C up -d nginx
curl -sk --resolve <домен>:443:<IP сервера> https://<домен>/up -o /dev/null -w '%{http_code}\n'   # 403 — мимо Cloudflare не пускает
curl -s https://<домен>/up -o /dev/null -w '%{http_code}\n'                                      # 200 — через Cloudflare
```

Что блокируется: порт 443 для любого TCP-адреса не из диапазонов Cloudflare. Что НЕ блокируется: порт 80 (нужен certbot и
редиректу). `deploy.sh` ходит на домен по DNS, то есть через Cloudflare, поэтому его smoke проходит. Проверка из самого
сервера по `localhost` или IP получит 403 — используйте домен.

Как это устроено и почему именно так: `geo $realip_remote_addr`. Модуль realip подменяет `$remote_addr` на адрес из
`CF-Connecting-IP` ещё на стадии чтения запроса; к моменту, когда `geo` вычисляется, `$remote_addr` — уже посетитель, и
проверка «это Cloudflare?» по нему бессмысленна. `$realip_remote_addr` хранит адрес TCP-соединения до подмены. Если когда-нибудь
придётся откатить прод на конфиг без realip, `CLOUDFLARE_ONLY` отключайте первым.

Диапазоны Cloudflare меняются редко. nginx обновляет их сам раз в сутки; если в сети сервера закрыт доступ к
`www.cloudflare.com`, используется закоммиченный `docker/nginx/cloudflare-ips.txt` — сверяйте его с
`https://www.cloudflare.com/ips-v4` и `ips-v6` раз в полгода (иначе при `CLOUDFLARE_ONLY=true` новые диапазоны получат 403).

## 8. Нагрузочный тест (k6, `docs/22_DEPLOY_R13_RU.md` §5) — мимо Cloudflare

Через Cloudflare тест бессмыслен: 1 250 «учеников» с одного адреса получат rate limiting/челленджи Cloudflare, а замер
покажет задержку Cloudflare, а не сервера. Варианты:

1. Запись в `/etc/hosts` машины-генератора: `<IP сервера> <домен>`, и на время теста `CLOUDFLARE_ONLY=false`
   (иначе 403). Сертификат Let's Encrypt валиден для домена, `BASE_URL=https://<домен>` работает без `-k`.
2. Либо непроксированный поддомен `origin.<домен>` (серое облако): но на него нет сертификата, и приложение отвечает на
   `Host`, не равный домену, так что вариант 1 проще.

В обоих случаях адрес генератора не из диапазонов Cloudflare, поэтому `CF-Connecting-IP` от него игнорируется и лимиты считаются по
реальному адресу генератора.
После теста: `CLOUDFLARE_ONLY=true`, убрать строку из `/etc/hosts`.

## 9. Проверка

```bash
# 1. Статика кэшируется (второй запрос — HIT; первый может быть MISS)
curl -sI https://<домен>/twa/assets/<файл из index.html> | grep -iE 'cf-cache-status|cache-control|age'
# 2. index.html и API не кэшируются
curl -sI https://<домен>/twa/index.html | grep -iE 'cf-cache-status|cache-control'      # DYNAMIC / BYPASS, no-store
curl -sI https://<домен>/api/twa/me | grep -i cf-cache-status                           # DYNAMIC или BYPASS
# 3. Аудио
curl -sI https://<домен>/storage/audio/<файл>.mp3 | grep -iE 'cf-cache-status|cache-control'
# 4. Реальные адреса в логах nginx (не 104.x / 172.64–71.x / 162.158.x)
$C logs --tail 20 nginx
# 5. Приложение
curl -s -o /dev/null -w '%{http_code}\n' https://<домен>/health/ready     # 200
curl -s https://api.telegram.org/bot$TELEGRAM_BOT_TOKEN/getWebhookInfo | jq '.result | {url, last_error_date, last_error_message, pending_update_count}'   # без ошибок
# 6. Откройте Mini App в Telegram: нет ошибок CSP в Remote Debugging, ученик авторизуется (`/api/twa/auth`)
```

Если в логах nginx видны адреса Cloudflare — `CLOUDFLARE_PROXY` не `true` в контейнере (`$C exec nginx env | grep CLOUDFLARE`)
или запрос пришёл с адреса вне списка (список устарел). Если лимиты срабатывают у всех сразу (429) — то же самое.

## 10. Что Cloudflare НЕ разгружает и оценка экономии

Не разгружает: `/api/*` — каждый запрос ученика идёт на origin (JSON персональный и не кэшируется); TTS `/api/twa/tts` — он
под `Authorization` (Cloudflare такие ответы по умолчанию не кэширует, и это правильно: файл персонален по правам доступа);
админки, вебхуки, `/health/*`. Нагрузка на CPU сервера от Cloudflare не уменьшается — это зона `docs/22` (ёмкость k6).

Разгружает канал: сборка Mini App — порядок единиц МБ (с gzip/brotli) на ученика при первом открытии, дальше 0: файлы
`immutable` на год и лежат в кэше браузера, а между учениками и сервером стоит кэш Cloudflare (в origin идёт по одному запросу
на файл на каждый узел Cloudflare). Аудио слов (30 дней `immutable`, имя = sha1 содержимого) — основной по объёму трафик
статики; после первого прогрева узла отдаётся без участия сервера. Точные цифры: Cloudflare → Analytics → Traffic → Cached
vs Uncached bandwidth через неделю после включения.
