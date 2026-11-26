<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Cup\Support\CupAccess;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Support\CupCallback;
use App\Models\Cup;
use App\Models\TelegramGroup;
use Illuminate\Support\Facades\Log;

/**
 * The one message a group gets when the bot is added to it and waits for activation: what to do next, the group number
 * for the center's cabinet and the support contact. A bot that is not an administrator gets the existing warning in the
 * same message ({@see GroupBotAccessChecker}), so the group never sees two messages in a row. Never blocks anything:
 * a failed send is only logged.
 *
 * Where the friends' cup is open to the group (`CUP_ACCESS`, docs/33 §6.1) and the chat has no cup yet, the same message offers it:
 * one more line and the button «Создать кубок друзей» (`cup:new`).
 */
final class GroupWelcomeMessenger
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
        private readonly GroupBotAccessChecker $botAccess,
        private readonly BotSupportContact $support,
        private readonly BotButtons $buttons,
    ) {}

    /**
     * Returns true when the message was sent. A group that already belongs to a center is not welcomed: the link's own
     * confirmation (`/start grp_…` of the cabinet's deep link) is the message, and the welcome would repeat it.
     */
    public function send(TelegramGroup $group, ?int $botId = null): bool
    {
        if ($group->organization_id !== null) {
            return false;
        }

        $locale = $this->locales->forGroup($group);

        $text = __('bot.group.welcome', ['title' => e($group->title)], $locale);
        if ($this->botAccess->botIsAdmin($group, $botId) === false) {
            $text .= "\n\n".e(__('bot.group.bot_not_admin', [], $locale));
        }
        $text .= $this->support->line($locale, html: true);

        $keyboard = null;
        if (CupAccess::allowsGroup($group) && ! Cup::query()->where('active_group_id', $group->id)->exists()) {
            $text .= "\n\n".e(__('cup.welcome.line', [], $locale));
            $keyboard = $this->buttons->single($group->chat_id, $this->buttons->callback(__('buttons.cup_create', [], $locale), CupCallback::create()));
        }

        try {
            $keyboard === null
                ? $this->telegram->sendMessage($group->chat_id, $text, parseMode: 'HTML')
                : $this->telegram->sendMessage($group->chat_id, $text, parseMode: 'HTML', replyMarkup: $keyboard);
        } catch (TelegramApiException|TelegramForbiddenException $e) {
            Log::warning('telegram.group_welcome_failed', ['chat_id' => $group->chat_id, 'error' => $e->getMessage()]);

            return false;
        }

        return true;
    }
}
