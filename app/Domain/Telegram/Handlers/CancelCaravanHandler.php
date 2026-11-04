<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Game\Exceptions\GameException;
use App\Domain\Game\Services\Class\ClassCaravanService;
use App\Domain\Game\Services\GameAccess;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Domain\Telegram\Services\ClassCaravanMessenger;
use App\Models\TelegramGroup;
use App\Models\User;

/**
 * Handles `/cancel_caravan` in a group (docs/18 §12.11): the teacher of the group (or an admin) cancels the caravan that is on its way.
 * No summary follows a cancellation (decided in G2.9a); the runs of the members stay their own. Same silence as {@see StartCaravanHandler}
 * while the game is closed.
 */
final class CancelCaravanHandler implements UpdateHandler
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly ClassCaravanService $caravans,
        private readonly ClassCaravanMessenger $messenger,
        private readonly LocaleResolver $locales,
        private readonly GameAccess $access,
    ) {}

    /** @param array<string, mixed> $update */
    public function matches(array $update): bool
    {
        $message = $update['message'] ?? null;
        if (! is_array($message)) {
            return false;
        }

        $chatType = (string) ($message['chat']['type'] ?? '');
        $text = (string) ($message['text'] ?? '');

        return in_array($chatType, ['group', 'supergroup'], true)
            && preg_match('~^/cancel_caravan(?:@\w+)?(\s|$)~', $text) === 1;
    }

    public function requiresActiveGroup(): bool
    {
        return true;
    }

    /** @param array<string, mixed> $update */
    public function handle(array $update): void
    {
        if ($this->access->isOff()) {
            return;
        }

        /** @var array<string, mixed> $message */
        $message = $update['message'];
        $chatId = (int) $message['chat']['id'];
        $fromId = (int) ($message['from']['id'] ?? 0);

        if ($fromId === 0) {
            return;
        }

        /** @var TelegramGroup|null $group */
        $group = TelegramGroup::query()->where('chat_id', $chatId)->first();
        if ($group === null) {
            return;
        }

        /** @var User|null $teacher */
        $teacher = User::query()->where('telegram_user_id', $fromId)->first();
        if ($teacher === null) {
            $this->telegram->sendMessage($chatId, __('errors.teacher_not_linked', [], $this->locales->forGroup($group)));

            return;
        }

        $caravan = $this->caravans->activeOfGroup($group);
        if ($caravan === null) {
            $this->messenger->noActive($group);

            return;
        }

        try {
            $this->caravans->cancel($caravan, $teacher);
        } catch (GameException $e) {
            $this->messenger->refuse($e, $group);

            return;
        }

        $this->messenger->announceCanceled($group);
    }
}
