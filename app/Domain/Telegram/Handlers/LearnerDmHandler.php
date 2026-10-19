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
 * Catch-all for any other message a non-teacher writes to the bot in a DM (docs/12 §3.6): the
 * learner is registered / revived and marked reachable, then — during the onboarding — the
 * current step is shown again, afterwards a short menu. Keep it LAST in the dispatcher list so
 * the commands get their turn first. A linked teacher's stray messages stay unanswered, as before.
 */
final class LearnerDmHandler implements UpdateHandler
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
            && is_array($message['from'] ?? null)
            && (int) ($message['from']['id'] ?? 0) > 0
            && empty($message['from']['is_bot']);
    }

    // Private-chat only; the Group Lock does not apply.
    public function requiresActiveGroup(): bool
    {
        return false;
    }

    /** @param array<string, mixed> $update */
    public function handle(array $update): void
    {
        /** @var array<string, mixed> $message */
        $message = $update['message'];
        /** @var array<string, mixed> $from */
        $from = $message['from'];
        $chatId = (int) $message['chat']['id'];

        $learner = $this->learners->resolve($from);
        if ($learner === null || $learner->blocked_at !== null) {
            return;
        }

        if ($learner->onboarding_state !== OnboardingService::DONE) {
            $this->messenger->resume($learner, $chatId, StartPayload::none());

            return;
        }

        $locale = $this->locales->forLearner($learner);
        $this->telegram->sendWebAppButton(
            chatId: $chatId,
            text: __('onboarding.menu.text', [], $locale),
            buttonText: __('onboarding.today.button', [], $locale),
            url: rtrim((string) config('twa.base_url'), '/').'/twa/?from=menu',
        );
    }
}
