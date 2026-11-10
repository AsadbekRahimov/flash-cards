<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Models\TelegramGroup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The answer to a teacher command (/help, /start_training, /start_exam, /close_exam) sent in a group that is not
 * active yet: without it the group stays silent and the teacher does not know why. A group that was switched off
 * (`disabled`) is still silent. The commands are answered at most once an hour per group (a flag in the cache, so a
 * chatty group is not spammed); `/help` is an explicit question and always gets the answer, with the group number.
 */
final class PendingGroupNotice
{
    private const FLAG_TTL_SECONDS = 3600;

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
        private readonly BotSupportContact $support,
    ) {}

    /** Only a registered group that waits for activation is answered; an unknown one (the bot never reported being added) stays silent. */
    public function appliesTo(?TelegramGroup $group): bool
    {
        return $group !== null && $group->status === 'pending';
    }

    public function notify(int $chatId, ?TelegramGroup $group, bool $isHelp): void
    {
        if (! $isHelp && ! Cache::add("tg:pending-group-notice:v1:{$chatId}", true, self::FLAG_TTL_SECONDS)) {
            return;
        }

        $locale = $group !== null ? $this->locales->forGroup($group) : $this->locales->default();
        $text = __($isHelp ? 'bot.group.pending_help' : 'bot.group.pending_command', ['chat_id' => $chatId], $locale)
            .$this->support->line($locale, html: true);

        try {
            $this->telegram->sendMessage($chatId, $text, parseMode: 'HTML');
        } catch (TelegramApiException|TelegramForbiddenException $e) {
            Log::warning('telegram.pending_group_notice_failed', ['chat_id' => $chatId, 'error' => $e->getMessage()]);
        }
    }
}
