#!/bin/sh
# Telegram side of a deploy: the command menu and the Mini App button (`telegram:setup-bot`) and the webhook
# (`telegram:set-webhook`). Run INSIDE an application container (deploy.sh: `compose run --rm app sh docker/telegram-setup.sh`;
# Coolify: the end of the lf-migrate command), where the variables of .env are in the environment.
#
# Idempotent (both commands set the same state again) and never fatal: it always exits 0. A missing variable skips the step
# with a WARNING that names it; an error of the Telegram API (down, 429, a wrong token) is a WARNING too, because a deploy
# that has migrated the database must not stop at the bot's menu - the commands print the reason, run them by hand then.

set -u

if [ -z "${TELEGRAM_BOT_TOKEN:-}" ]; then
    echo "WARNING: TELEGRAM_BOT_TOKEN is not set: the bot menu, the Mini App button and the webhook were NOT set up (telegram:setup-bot, telegram:set-webhook)." >&2
    exit 0
fi

echo "==> Telegram: command menu and Mini App button"
php artisan telegram:setup-bot --no-interaction \
    || echo "WARNING: telegram:setup-bot failed (see above); run it by hand: php artisan telegram:setup-bot" >&2

missing=''
for name in TELEGRAM_WEBHOOK_URL TELEGRAM_URL_SECRET TELEGRAM_HEADER_SECRET; do
    eval "value=\${$name:-}"
    [ -n "$value" ] || missing="$missing $name"
done
if [ -n "$missing" ]; then
    echo "WARNING: not set:$missing - the Telegram webhook was NOT registered (telegram:set-webhook)." >&2
    exit 0
fi

echo "==> Telegram: webhook"
php artisan telegram:set-webhook --no-interaction \
    || echo "WARNING: telegram:set-webhook failed (see above); run it by hand: php artisan telegram:set-webhook" >&2

exit 0
