<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Telegram\Contracts\TelegramClient;
use Illuminate\Console\Command;

class TelegramSetWebhook extends Command
{
    protected $signature = 'telegram:set-webhook {--delete : Remove the webhook instead of setting it}';

    protected $description = 'Register or remove the Telegram webhook URL';

    public function handle(TelegramClient $telegram): int
    {
        if ($this->option('delete')) {
            $ok = $telegram->deleteWebhook();
            $this->info($ok ? 'Webhook removed.' : 'Failed to remove webhook.');

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        $baseUrl = rtrim((string) config('telegram.webhook_url'), '/');
        $urlSecret = (string) config('telegram.url_secret');
        $headerSecret = (string) config('telegram.header_secret');

        if ($baseUrl === '' || $urlSecret === '' || $headerSecret === '') {
            $this->error('TELEGRAM_WEBHOOK_URL / URL_SECRET / HEADER_SECRET must be set in .env.');

            return self::FAILURE;
        }

        $fullUrl = "{$baseUrl}/telegram/webhook/{$urlSecret}";

        $ok = $telegram->setWebhook($fullUrl, $headerSecret);

        if ($ok) {
            $this->info('Webhook set: '.$fullUrl);

            return self::SUCCESS;
        }

        $this->error('Failed to set webhook.');

        return self::FAILURE;
    }
}
