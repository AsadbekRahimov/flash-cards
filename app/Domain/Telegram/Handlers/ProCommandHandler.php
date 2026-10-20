<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Domain\Telegram\Services\ProScreen;
use App\Domain\Telegram\Support\DmLearnerResolver;

/**
 * `/pro` in a DM (docs/13 §5): the learner's plan, the auto-renewal and the ways to pay (or "Pro is
 * coming" while nothing is for sale). Money is the learner's own business, so it is a private-chat
 * command; groups and teachers get no answer.
 */
final class ProCommandHandler implements UpdateHandler
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
        private readonly DmLearnerResolver $learners,
        private readonly ProScreen $screen,
    ) {}

    /** @param array<string, mixed> $update */
    public function matches(array $update): bool
    {
        $message = $update['message'] ?? null;
        if (! is_array($message)) {
            return false;
        }

        return ($message['chat']['type'] ?? '') === 'private'
            && preg_match('~^/pro(?:@\w+)?(\s|$)~', (string) ($message['text'] ?? '')) === 1;
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
            $this->telegram->sendMessage($chatId, __('billing.pro.blocked', [], $this->locales->forLearner($learner)));

            return;
        }

        $screen = $this->screen->build($learner);

        $this->telegram->sendMessage($chatId, $screen['text'], replyMarkup: $screen['markup']);
    }
}
