<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Learners\DTO\StartPayload;
use App\Domain\Learners\Services\LearnerRegistrar;
use App\Domain\Learners\Services\OnboardingService;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Domain\Telegram\Services\OnboardingMessenger;
use App\Domain\Telegram\Services\TodayScreen;
use App\Domain\Telegram\Support\DmLearnerResolver;
use App\Models\User;

/**
 * `/start [payload]` in a DM (docs/12 §7.1). A linked teacher gets the greeting as before; anyone
 * else is a learner: the deep-link payload is attributed (first touch wins), then the onboarding
 * starts — or, for a learner who has finished it, the "Today" screen is shown.
 */
final class StartCommandHandler implements UpdateHandler
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
        private readonly DmLearnerResolver $learners,
        private readonly LearnerRegistrar $registrar,
        private readonly OnboardingMessenger $messenger,
        private readonly TodayScreen $today,
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

        return $chatType === 'private' && preg_match('~^/start(?:@\w+)?(\s|$)~', $text) === 1;
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
        /** @var array<string, mixed> $from */
        $from = is_array($message['from'] ?? null) ? $message['from'] : [];
        $tgUserId = isset($from['id']) ? (int) $from['id'] : 0;
        $chatId = (int) $message['chat']['id'];

        if ($tgUserId === 0) {
            return;
        }

        $user = User::where('telegram_user_id', $tgUserId)->first();

        if ($user !== null) {
            $user->update(['last_login_at' => now()]);

            $this->telegram->sendMessage(
                $chatId,
                __('bot.start.greeting', ['name' => $user->name], $this->locales->forTeacher()),
            );

            return;
        }

        $learner = $this->learners->resolve($from);
        if ($learner === null) {
            return;
        }

        // A blocked learner is told so and nothing else is recorded about them.
        if ($learner->blocked_at !== null) {
            $this->telegram->sendMessage($chatId, __('onboarding.blocked', [], $this->locales->forLearner($learner)));

            return;
        }

        $payload = StartPayload::parse($this->payloadFrom((string) ($message['text'] ?? '')));
        $this->registrar->attributeAcquisition($learner, $payload);

        if ($learner->onboarding_state === OnboardingService::DONE) {
            $this->today->send($learner, $chatId);
            // A `c_<slug>` link of a finished learner does not change the active course; it opens the course.
            $this->messenger->sendCourseLink($learner, $chatId, $payload);

            return;
        }

        $this->messenger->resume($learner, $chatId, $payload);
    }

    /** The first word after `/start` (Telegram sends the deep-link parameter as `/start <parameter>`). */
    private function payloadFrom(string $text): ?string
    {
        return preg_match('~^/start(?:@\w+)?\s+(\S+)~', $text, $m) === 1 ? $m[1] : null;
    }
}
