<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Game\Exceptions\GameException;
use App\Domain\Game\Services\Class\ClassCaravanService;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Models\ClassCaravan;
use App\Models\TelegramGroup;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * What the bot says in the chat of a group about its class caravan (docs/18 §12.11, docs/04 B.15): "the caravan has set off" with a
 * button that opens `/twa/game/class/{id}` (a direct link in a group, {@see MiniAppLinks}), the refusals of `/start_caravan` by their
 * reason, the cancellation. Used by the commands of the bot and by the `/org` page "Caravans", so both say the same. Plain text in the
 * language of the group (`lang/{ru,uz}/game.php`, `game.class.*`). The summary of the week is not here: `PostClassCaravanSummaryJob`.
 *
 * The announcement goes through {@see GroupSessionAnnouncer}: if Telegram definitely refused it nobody in the class can see the caravan,
 * so it is withdrawn (canceled) and the teacher is told, and a retry of the command works instead of answering "already in the way".
 */
final class ClassCaravanMessenger
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
        private readonly GroupSessionAnnouncer $announcer,
        private readonly ClassCaravanService $caravans,
    ) {}

    /**
     * "The caravan has set off" + the button of the Mini App; withdraws the caravan when the chat definitely did not get it.
     *
     * @return bool true when the message was sent (or may have been)
     */
    public function announceStarted(ClassCaravan $caravan, TelegramGroup $group, User $by): bool
    {
        $locale = $this->locales->forGroup($group);
        $base = rtrim((string) config('twa.base_url'), '/');

        return $this->announcer->announce(
            chatId: $group->chat_id,
            text: __('game.class.started', ['target' => $caravan->target_stars, 'until' => $this->until($caravan->ends_at)], $locale),
            buttonText: __('game.class.open_button', [], $locale),
            url: "{$base}/twa/game/class/{$caravan->id}",
            failureText: __('game.class.not_delivered', [], $locale),
            cancel: fn () => $this->caravans->cancel($caravan, $by),
        );
    }

    /** "The caravan is canceled" (no summary follows). A chat that cannot be written to is only logged: the cancellation stands. */
    public function announceCanceled(TelegramGroup $group): void
    {
        $this->send($group, __('game.class.canceled', [], $this->locales->forGroup($group)));
    }

    public function noActive(TelegramGroup $group): void
    {
        $this->send($group, __('game.class.no_active', [], $this->locales->forGroup($group)));
    }

    /** The answer of a refused `/start_caravan` or `/cancel_caravan` in the chat. */
    public function refuse(GameException $e, TelegramGroup $group): void
    {
        $this->send($group, $this->refusalText($e, $group));
    }

    /** The text of a refusal by its reason (403) or "already in the way, until ..." (409); the `/org` page shows it as a notification. */
    public function refusalText(GameException $e, TelegramGroup $group): string
    {
        $locale = $this->locales->forGroup($group);

        return match ($e->errorCode) {
            GameException::CLASS_CARAVAN_FORBIDDEN => match ($e->details['reason'] ?? null) {
                GameException::REASON_GROUP_INACTIVE => __('game.class.group_inactive', [], $locale),
                GameException::REASON_CENTERS_ONLY => __('game.class.centers_only', [], $locale),
                default => __('game.class.not_teacher', [], $locale),
            },
            GameException::CLASS_CARAVAN_ACTIVE => $this->alreadyActive($group, $locale),
            default => __('errors.exam.failed', [], $locale),
        };
    }

    private function alreadyActive(TelegramGroup $group, string $locale): string
    {
        $active = $this->caravans->activeOfGroup($group);

        return __('game.class.already_active', ['until' => $active === null ? '—' : $this->until($active->ends_at)], $locale);
    }

    private function send(TelegramGroup $group, string $text): void
    {
        try {
            $this->telegram->sendMessage($group->chat_id, $text);
        } catch (TelegramApiException|TelegramForbiddenException $e) {
            Log::warning('telegram.class_caravan_message_failed', ['chat_id' => $group->chat_id, 'error' => $e->getMessage()]);
        }
    }

    /** "05.11 18:30" in the default timezone of learners (a group has none). */
    private function until(CarbonInterface $endsAt): string
    {
        return $endsAt->setTimezone((string) config('learners.default_timezone', 'UTC'))->format('d.m H:i');
    }
}
