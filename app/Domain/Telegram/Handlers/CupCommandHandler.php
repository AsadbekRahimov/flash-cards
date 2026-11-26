<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Cup\Enums\CupMessageKind;
use App\Domain\Cup\Enums\CupStatus;
use App\Domain\Cup\Exceptions\CupException;
use App\Domain\Cup\Services\CupLifecycleService;
use App\Domain\Cup\Services\CupOutbox;
use App\Domain\Cup\Support\CupAccess;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Domain\Telegram\Services\BotButtons;
use App\Domain\Telegram\Services\GroupBotRemoval;
use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use App\Domain\Telegram\Support\TwaScreen;
use App\Models\Cup;
use App\Models\TelegramGroup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * `/cup` in a group (docs/33 §3.2, §6.2, docs/04 B.16) - and `/start cup`, what Telegram posts when the bot is added from the link
 * `?startgroup=cup`: no cup in the chat → a draft and its proposal (posted through the outbox, {@see CupOutbox}); an open cup → a short
 * answer with a button into the Mini App (a draft → its setup). Anyone in the chat may ask; the proposal says who may publish.
 *
 * At most once in {@see COOLDOWN_SECONDS} per chat, otherwise silence (docs/33 §4.7: the chat cannot be flooded with the bot's answers
 * by its own members), on top of the dispatcher's inbound limit. With `CUP_ACCESS=off` the command is not matched at all - the bot
 * treats it as any unknown command; a group outside the pilot, a paused cup or a group the bot does not know get silence.
 * Works in `pending` groups, so `requiresActiveGroup()` is false.
 */
final class CupCommandHandler implements UpdateHandler
{
    public const COOLDOWN_SECONDS = 600;

    private const COMMAND = '~\A/cup(?:@\w+)?(?:\s|\z)~';

    private const START = '~\A/start(?:@\w+)?\s+cup\s*\z~';

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly CupLifecycleService $lifecycle,
        private readonly CupOutbox $outbox,
        private readonly BotButtons $buttons,
        private readonly TelegramMessageRateLimiter $limiter,
        private readonly GroupBotRemoval $removal,
        private readonly LocaleResolver $locales,
    ) {}

    /** @param array<string, mixed> $update */
    public function matches(array $update): bool
    {
        $message = $update['message'] ?? null;
        if (! is_array($message) || (string) config('cup.access', 'off') === 'off') {
            return false;
        }

        $text = (string) ($message['text'] ?? '');

        return in_array((string) ($message['chat']['type'] ?? ''), ['group', 'supergroup'], true)
            && (preg_match(self::COMMAND, $text) === 1 || preg_match(self::START, $text) === 1);
    }

    public function requiresActiveGroup(): bool
    {
        return false;
    }

    /** @param array<string, mixed> $update */
    public function handle(array $update): void
    {
        /** @var array<string, mixed> $message */
        $message = $update['message'];
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $fromId = (int) ($message['from']['id'] ?? 0);
        if ($chatId === 0 || $fromId <= 0) {
            return;
        }

        $group = TelegramGroup::query()->where('chat_id', $chatId)->first();
        if ($group === null || ! CupAccess::allowsGroup($group) || CupAccess::pauseReason($group) !== null) {
            return;
        }

        $key = 'cup-chat:'.$chatId;
        if (RateLimiter::tooManyAttempts($key, 1)) {
            return;
        }
        RateLimiter::hit($key, self::COOLDOWN_SECONDS);

        $threadId = ($message['is_topic_message'] ?? false) === true && is_int($message['message_thread_id'] ?? null) ? $message['message_thread_id'] : null;
        $now = CarbonImmutable::now();

        try {
            $proposal = $this->lifecycle->propose($group, $fromId, $threadId, $now);
        } catch (CupException $e) {
            if ($e->errorCode === CupException::MATERIAL_UNAVAILABLE) {
                $this->reply($group, $threadId, __('cup.command.material', [], $this->locales->forGroup($group)), null);
            }

            return;
        }

        if ($proposal->created) {
            $this->outbox->enqueue($proposal->cup->id, CupOutbox::CARD_KEY, CupMessageKind::Card, $now);

            return;
        }

        $this->exists($group, $proposal->cup, $threadId);
    }

    /**
     * The chat already has a cup: one line and one button to it (the card itself may be far up in the chat). A DRAFT is not open to
     * join - its button is the setup of the Mini App (an administrator publishes from there): the proposal with «Опубликовать» may be
     * gone or may never have arrived (a timeout at its first send leaves the row `unknown`, never sent again, docs/33 §6.4).
     */
    private function exists(TelegramGroup $group, Cup $cup, ?int $threadId): void
    {
        $locale = $cup->locale;
        [$text, $button] = $cup->status === CupStatus::Draft
            ? ['cup.command.draft', $this->buttons->open(__('buttons.cup_setup', [], $locale), TwaScreen::CupSetup, ['code' => $cup->public_code], ['from' => 'cup_command'])]
            : ['cup.command.exists', $this->buttons->open(__('buttons.cup_open', [], $locale), TwaScreen::CupInvite, ['code' => $cup->public_code], ['from' => 'cup_command'])];

        $this->reply($group, $threadId ?? $cup->card_thread_id, __($text, ['group' => $group->title], $locale), $this->buttons->single($group->chat_id, $button));
    }

    /**
     * An answer to the command, at once (it waits for no queue), in the topic it came from; without a slot of the chat - silence.
     *
     * @param  array<string, mixed>|null  $keyboard
     */
    private function reply(TelegramGroup $group, ?int $threadId, string $text, ?array $keyboard): void
    {
        if ($this->limiter->reserve($group->chat_id) > 0) {
            return;
        }

        try {
            $this->telegram->sendGroupMessage($group->chat_id, $text, null, $keyboard, $threadId);
        } catch (TelegramForbiddenException $e) {
            $this->removal->disable($group, $e, 'cup_command');
        } catch (TelegramApiException $e) {
            Log::warning('cup.command_reply_failed', ['telegram_group_id' => $group->id, 'code' => $e->getCode()]);
        }
    }
}
