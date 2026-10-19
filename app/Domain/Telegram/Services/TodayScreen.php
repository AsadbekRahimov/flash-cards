<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Learning\Services\LearnerStatsService;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Models\Learner;

/**
 * The bot's minimal "Today" (`/today`, and `/start` of a learner who has finished the onboarding):
 * how many cards are due and a button into the TWA. The full screen — goal, streak, course
 * progress — is the TWA's `GET /api/twa/today` (docs/12 §7.3); this one has no dependency on it.
 */
final class TodayScreen
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
        private readonly LearnerStatsService $stats,
    ) {}

    public function send(Learner $learner, int|string $chatId): void
    {
        $locale = $this->locales->forLearner($learner);
        $due = $this->stats->forLearner($learner)->wordsDueToday;

        $this->telegram->sendWebAppButton(
            chatId: $chatId,
            text: $due > 0
                ? trans_choice('onboarding.today.due', $due, ['count' => $due], $locale)
                : __('onboarding.today.empty', [], $locale),
            buttonText: __('onboarding.today.button', [], $locale),
            url: rtrim((string) config('twa.base_url'), '/').'/twa/?from=today',
        );
    }
}
