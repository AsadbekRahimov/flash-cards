<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
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

        // The URL secret authenticates Telegram's calls and the deploy log is kept (Coolify): never print it.
        $shownUrl = "{$baseUrl}/telegram/webhook/***";

        try {
            $ok = $telegram->setWebhook($fullUrl, $headerSecret);
        } catch (TelegramApiException $e) {
            $this->error('Failed to set webhook: '.str_replace([$urlSecret, $headerSecret], '***', $e->getMessage()));

            return self::FAILURE;
        }

        if ($ok) {
            $this->info('Webhook set: '.$shownUrl);

            return self::SUCCESS;
        }

        $this->error("Failed to set webhook {$shownUrl}.");

        return self::FAILURE;
    }
}
