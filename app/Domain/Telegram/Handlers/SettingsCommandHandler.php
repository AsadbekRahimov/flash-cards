<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Learners\DTO\StartPayload;
use App\Domain\Learners\Services\OnboardingService;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Domain\Telegram\Services\OnboardingMessenger;
use App\Domain\Telegram\Support\DmLearnerResolver;

/**
 * `/settings` in a DM — a menu that re-runs one onboarding step at a time (language, goal, level,
 * time zone, reminder). Someone who has not finished the onboarding is taken back to it instead.
 */
final class SettingsCommandHandler implements UpdateHandler
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
        private readonly DmLearnerResolver $learners,
        private readonly OnboardingMessenger $messenger,
    ) {}

    /** @param array<string, mixed> $update */
    public function matches(array $update): bool
    {
        $message = $update['message'] ?? null;
        if (! is_array($message)) {
            return false;
        }

        return ($message['chat']['type'] ?? '') === 'private'
            && preg_match('~^/settings(?:@\w+)?(\s|$)~', (string) ($message['text'] ?? '')) === 1;
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

        if ($learner->onboarding_state !== OnboardingService::DONE) {
            $this->messenger->resume($learner, $chatId, StartPayload::none());

            return;
        }

        $this->messenger->sendSettingsMenu($learner, $chatId);
    }
}
