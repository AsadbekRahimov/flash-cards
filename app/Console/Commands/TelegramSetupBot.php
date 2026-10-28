<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Services\BotMenuBuilder;
use Closure;
use Illuminate\Console\Command;

/**
 * Sets what the Telegram client shows around the bot (docs/04 B.3): the command list behind "/" for private chats
 * and groups in every interface language, and the permanent "LexiFlow" button that opens the Mini App. Idempotent:
 * run it after `telegram:set-webhook` and again after a deploy that changes the commands or their descriptions.
 */
final class TelegramSetupBot extends Command
{
    protected $signature = 'telegram:setup-bot';

    protected $description = 'Set the bot command menu (private chats and groups, ru/uz) and the Mini App menu button';

    public function handle(TelegramClient $telegram, BotMenuBuilder $menu): int
    {
        $failed = false;

        foreach ($menu->commandSets() as $set) {
            $ok = $this->apply(
                sprintf('Commands %s, %s', $set['scope']['type'], $set['language_code'] === null ? "default ({$set['locale']})" : $set['locale']),
                implode(' ', array_map(fn (array $command): string => '/'.$command['command'], $set['commands'])),
                fn (): bool => $telegram->setMyCommands($set['commands'], $set['scope'], $set['language_code']),
            );
            $failed = ! $ok || $failed;
        }

        $button = $menu->menuButton();

        if ($button === null) {
            // A warning, not an error: a local or staging install without https still gets its command list.
            $this->warn(sprintf(
                'Menu button skipped: Telegram accepts only https Mini App URLs, but TWA_BASE_URL is "%s". Set the public https address and run the command again.',
                (string) config('twa.base_url'),
            ));
        } else {
            $ok = $this->apply(
                'Menu button',
                sprintf('"%s" opens %s', $button['text'], $button['web_app']['url']),
                fn (): bool => $telegram->setChatMenuButton($button),
            );
            $failed = ! $ok || $failed;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Runs one Bot API call and reports it: what was set, or why it was not.
     *
     * @param  Closure(): bool  $call
     */
    private function apply(string $what, string $details, Closure $call): bool
    {
        try {
            $confirmed = $call();
            $error = $confirmed ? null : 'Telegram did not confirm the change.';
        } catch (TelegramApiException $e) {
            $error = $e->getMessage();
        }

        if ($error !== null) {
            $this->error("{$what} not set: {$error}");

            return false;
        }

        $this->info("{$what}: {$details}");

        return true;
    }
}
