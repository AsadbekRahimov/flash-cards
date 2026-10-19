<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Learners\Exceptions\OnboardingException;
use App\Domain\Learners\Services\OnboardingService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Domain\Telegram\Services\OnboardingMessenger;
use App\Domain\Telegram\Support\DmLearnerResolver;

/**
 * Buttons of the onboarding (`onb1:<step>:<value>`) and of `/settings` (`set1:<step>[:<value>]`).
 *
 * `callback_data` is not signed, so everything in it is untrusted: the format is matched
 * strictly, the step must be a known one and the value is checked against the step's whitelist
 * by {@see OnboardingService}. The query is always answered at once, without text; a stale
 * button (old message, double tap) or a tampered one changes nothing and says nothing.
 */
final class OnboardingCallbackHandler implements UpdateHandler
{
    /** `<prefix>:<step>[:<value>]`; the value is a whitelist key or an IANA time zone id. */
    private const FORMAT = '~^(onb1|set1):([a-z]{1,16})(?::([A-Za-z0-9_/+-]{1,40}))?$~';

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly DmLearnerResolver $learners,
        private readonly OnboardingService $onboarding,
        private readonly OnboardingMessenger $messenger,
    ) {}

    /** @param array<string, mixed> $update */
    public function matches(array $update): bool
    {
        $data = (string) ($update['callback_query']['data'] ?? '');

        return str_starts_with($data, OnboardingMessenger::ONBOARDING_PREFIX.':')
            || str_starts_with($data, OnboardingMessenger::SETTINGS_PREFIX.':');
    }

    // Private-chat only; the Group Lock does not apply.
    public function requiresActiveGroup(): bool
    {
        return false;
    }

    /** @param array<string, mixed> $update */
    public function handle(array $update): void
    {
        /** @var array<string, mixed> $cq */
        $cq = $update['callback_query'];

        $callbackQueryId = (string) ($cq['id'] ?? '');
        if ($callbackQueryId !== '') {
            $this->telegram->answerCallbackQuery($callbackQueryId);
        }

        $message = is_array($cq['message'] ?? null) ? $cq['message'] : [];
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $messageId = (int) ($message['message_id'] ?? 0);

        if (($message['chat']['type'] ?? '') !== 'private'
            || $chatId === 0
            || preg_match(self::FORMAT, (string) ($cq['data'] ?? ''), $m) !== 1
            || ! in_array($m[2], OnboardingService::STEPS, true)
        ) {
            return;
        }

        $learner = $this->learners->resolve(is_array($cq['from'] ?? null) ? $cq['from'] : []);
        if ($learner === null || $learner->blocked_at !== null) {
            return;
        }

        $step = $m[2];
        $value = $m[3] ?? null;

        try {
            if ($m[1] === OnboardingMessenger::ONBOARDING_PREFIX) {
                if ($value === null) {
                    return;
                }

                $next = $this->onboarding->apply($learner, $step, $value);
                $this->messenger->clearKeyboard($chatId, $messageId);
                $this->messenger->sendStep($learner, $chatId, $next);

                return;
            }

            // /settings is for learners who have finished the onboarding; before that the steps are the onboarding's.
            if ($learner->onboarding_state !== OnboardingService::DONE) {
                return;
            }

            if ($value === null) {
                $this->messenger->sendSettingStep($learner, $chatId, $step);

                return;
            }

            $this->onboarding->applySetting($learner, $step, $value);
            $this->messenger->clearKeyboard($chatId, $messageId);
            $this->messenger->sendSettingSaved($learner, $chatId);
        } catch (OnboardingException) {
            // Stale or tampered button: already answered, nothing to change or say.
        }
    }
}
