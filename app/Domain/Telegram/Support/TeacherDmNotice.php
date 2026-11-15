<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Support;

use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;

/**
 * A linked teacher is not a learner ({@see DmLearnerResolver::resolve()} returns null for them), so a learner command
 * (`/today`, `/settings`, `/invite`, `/pro`) has nothing to show them. Instead of silence they are told which commands are theirs.
 * Free text is NOT answered (LearnerDmHandler): that would be spam.
 */
final class TeacherDmNotice
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
    ) {}

    /** @param array<string, mixed> $message a private-chat message whose sender the resolver did not turn into a learner */
    public function send(int $chatId, array $message): void
    {
        $from = is_array($message['from'] ?? null) ? $message['from'] : [];

        // The resolver also returns null for a sender without an id: nobody to answer.
        if ((int) ($from['id'] ?? 0) <= 0) {
            return;
        }

        $languageCode = $from['language_code'] ?? null;

        $this->telegram->sendMessage($chatId, __('bot.teacher.learner_command', [], $this->locales->fromLanguageCode(is_string($languageCode) ? $languageCode : null)));
    }
}
