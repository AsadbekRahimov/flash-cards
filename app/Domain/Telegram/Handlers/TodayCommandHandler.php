<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Domain\Telegram\Services\TodayScreen;
use App\Domain\Telegram\Support\DmLearnerResolver;

/**
 * `/today` in a DM — the minimal "Today" screen: cards due and a button into the TWA
 * (docs/12 §3.6). The full screen is the TWA's. Not answered in groups.
 */
final class TodayCommandHandler implements UpdateHandler
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
        private readonly DmLearnerResolver $learners,
        private readonly TodayScreen $today,
    ) {}

    /** @param array<string, mixed> $update */
    public function matches(array $update): bool
    {
        $message = $update['message'] ?? null;
        if (! is_array($message)) {
            return false;
        }

        return ($message['chat']['type'] ?? '') === 'private'
            && preg_match('~^/today(?:@\w+)?(\s|$)~', (string) ($message['text'] ?? '')) === 1;
    }

    // Private-chat only command; the Group Lock does not apply.
    public function requiresActiveGroup(): bool
    {
        return false;
    }

    /** @param array<string, mixed> $update */
    public function handle(array $update): void
    {
        /** @var array<string, mixed> $message */
        $message = $update['message'];
        $chatId = (int) $message['chat']['id'];

        $learner = $this->learners->resolve(is_array($message['from'] ?? null) ? $message['from'] : []);
        if ($learner === null) {
            return;
        }

        if ($learner->blocked_at !== null) {
            $this->telegram->sendMessage($chatId, __('onboarding.blocked', [], $this->locales->forLearner($learner)));

            return;
        }

        $this->today->send($learner, $chatId);
    }
}
