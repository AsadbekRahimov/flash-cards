<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Learners\Exceptions\OnboardingException;
use App\Domain\Learners\Exceptions\TimezoneChangeTooSoonException;
use App\Domain\Learners\Services\OnboardingService;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Domain\Telegram\Services\OnboardingMessenger;
use App\Domain\Telegram\Support\CallbackAcknowledger;
use App\Domain\Telegram\Support\DmLearnerResolver;
use App\Models\Learner;

/**
 * Buttons of the onboarding (`onb1:<step>:<value>`) and of `/settings` (`set1:<step>[:<value>]`).
 *
 * `callback_data` is not signed, so everything in it is untrusted: the format is matched
 * strictly, the step must be a known one and the value is checked against the step's whitelist
 * by {@see OnboardingService}. The query is always answered at once, without text (and a refused
 * answer does not stop the work); a stale button (old message, double tap) or a tampered one
 * changes nothing and says nothing.
 *
 * One exception: a stale `onb1` button whose step is the one just BEFORE the learner's current
 * step is the retry of an update whose answer was saved but whose next message did not reach
 * Telegram (HandleTelegramUpdate retries the whole update). The current step is sent again;
 * a double tap may thus produce a duplicate question, which is harmless.
 *
 * Messages go to `from.id`, and a button of a message in any other chat (a group, a channel) is
 * ignored: the onboarding is a private conversation.
 */
final class OnboardingCallbackHandler implements UpdateHandler
{
    /** `<prefix>:<step>[:<value>]`; the value is a whitelist key or an IANA time zone id. */
    private const FORMAT = '~^(onb1|set1):([a-z]{1,16})(?::([A-Za-z0-9_/+-]{1,40}))?$~';

    public function __construct(
        private readonly CallbackAcknowledger $acknowledger,
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

        $this->acknowledger->acknowledge($update);

        $fromId = (int) ($cq['from']['id'] ?? 0);
        $message = is_array($cq['message'] ?? null) ? $cq['message'] : [];
        $messageId = (int) ($message['message_id'] ?? 0);

        if (($message['chat']['type'] ?? '') !== 'private'
            || $fromId <= 0
            || (int) ($message['chat']['id'] ?? 0) !== $fromId
            || preg_match(self::FORMAT, (string) ($cq['data'] ?? ''), $m) !== 1
            || ! in_array($m[2], OnboardingService::STEPS, true)
        ) {
            return;
        }

        $chatId = $fromId;

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
        } catch (OnboardingException $e) {
            // Stale or tampered button: already answered, nothing to change or say —
            // except the retry of a half-delivered answer, see the class comment.
            if ($e->errorCode === OnboardingException::STALE_STEP && $m[1] === OnboardingMessenger::ONBOARDING_PREFIX) {
                $this->repeatLostStep($learner, $step, $chatId);
            }
        } catch (TimezoneChangeTooSoonException $e) {
            // A legitimate tap the rules refuse: the learner is told when the zone can change again.
            $this->messenger->sendTimezoneChangeTooSoon($learner, $chatId, $e->availableAt);
        }
    }

    private function repeatLostStep(Learner $learner, string $answeredStep, int $chatId): void
    {
        $learner->refresh();

        $following = OnboardingService::STEPS[(int) array_search($answeredStep, OnboardingService::STEPS, true) + 1] ?? OnboardingService::DONE;

        if ($learner->onboarding_state === $following) {
            $this->messenger->sendStep($learner, $chatId, $this->onboarding->current($learner));
        }
    }
}
